<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The decisions of a wordpress.org deploy, without I/O: which tree a new tag is copied from,
 * what a base needs against the tree it is compared with, the order a commit runs its
 * operations in, and the messages the commits carry — every write's, in one form
 * (wporg_commit_message()). WporgOperations reads the trees and runs the commit (commit_files()).
 */


/**
 * The trees a new tag may be copied from in its commit, best first: trunk when it goes along
 * (usually the previous release, and read anyway), else the predecessor in the version line —
 * the highest tag below the new version — and then the other one. Only a tag named like a
 * publishable version is a source. The caller takes the first that exists and is not empty;
 * with none, the tag is uploaded in full.
 *
 * @param string[] $tags The names of the existing tags.
 * @return string[] 'trunk' and 'tags/{name}', relative to the plugin root.
 */
function wporg_tag_copy_candidates(string $version, array $tags, bool $touch_trunk): array {
    $target = normalize_version_number($version);
    $predecessor = null;
    foreach ($tags as $tag) {
        $tag = (string) $tag;
        if (!is_publishable_version($tag)) {
            continue;
        }
        $normalized = normalize_version_number($tag);
        if (version_compare($normalized, $target, '<') && ($predecessor === null || version_compare($normalized, normalize_version_number($predecessor), '>'))) {
            $predecessor = $tag;
        }
    }
    $tag_source = $predecessor !== null ? [ 'tags/' . $predecessor ] : [];
    return $touch_trunk ? [ 'trunk', ...$tag_source ] : [ ...$tag_source, 'trunk' ];
}


/**
 * The plan that makes $base hold $local_tree, against what the repository holds there: the
 * base's own tree, or — with $copy — the tree of the source the base is copied from in the
 * same commit. Files compare by MD5 (the listing carries it; nothing is downloaded); paths
 * only the repository has are deleted (a folder's delete covers what is in it), new folders
 * created, new and changed files put. A missing base that is no copy is created with the
 * folders above it, which may be missing too (tags/ for a new tag); a copy brings the base
 * itself, so only the folders above it are listed. An existing base is no change, so a plan
 * without changes stays empty.
 *
 * @param array<string, array{type:string, path:string, hash?:string}> $local_tree  Relative
 *        path => entry; path is the local file, hash its MD5.
 * @param array<string, array{type:string, md5?:?string}> $remote_tree Relative path => entry.
 * @param array{from_path:string, from_revision:int}|null $copy
 * @return array{deletes:string[], mkdirs:string[], copies:array, puts:array{path:string, local_path:string}[]}
 */
function wporg_reconcile_plan(string $base, array $local_tree, array $remote_tree, ?array $copy = null): array {
    $base = trim($base, '/');
    $join = static fn(string $rel): string => $base . '/' . trim($rel, '/');

    // Paths only the repository has, or of another type — shallow first, so a folder's delete
    // takes what is in it along.
    $gone = [];
    foreach ($remote_tree as $rel => $remote) {
        $local = $local_tree[$rel] ?? null;
        if ($local === null || $local['type'] !== $remote['type']) {
            $gone[] = (string) $rel;
        }
    }
    usort($gone, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
    $deletes = [];
    foreach ($gone as $rel) {
        foreach ($deletes as $deleted) {
            if (str_starts_with($rel, $deleted . '/')) {
                continue 2;
            }
        }
        $deletes[] = $rel;
    }

    $mkdirs = [];
    $puts = [];
    foreach ($local_tree as $rel => $local) {
        $rel = (string) $rel;
        $remote = $remote_tree[$rel] ?? null;
        $remote_type = $remote['type'] ?? null;
        if ($local['type'] === 'dir') {
            if ($remote_type !== 'dir') {
                $mkdirs[] = $join($rel);
            }
        } elseif ($remote_type !== 'file' || ($remote['md5'] ?? null) === null || $remote['md5'] !== ($local['hash'] ?? '')) {
            $puts[] = [ 'path' => $join($rel), 'local_path' => (string) $local['path'] ];
        }
    }

    $base_and_above = wporg_path_with_ancestors($base);
    return [
        'deletes' => array_map($join, $deletes),
        'mkdirs' => [
            ...($copy !== null ? array_slice($base_and_above, 0, -1) : ($remote_tree === [] ? $base_and_above : [])),
            ...$mkdirs,
        ],
        'copies' => $copy !== null ? [ [ 'path' => $base, 'from_path' => $copy['from_path'], 'from_revision' => $copy['from_revision'] ] ] : [],
        'puts' => $puts,
    ];
}


/**
 * The operations of a commit plan in the order they run: deletes deep-first, folders
 * shallow-first, then copies, files and mime types by path. A copy is an operation on its
 * whole subtree: what lies strictly below a copied path — deletes and folders in the copy —
 * runs after it; a delete of the copy's target itself (to replace it) runs before.
 *
 * @param array{deletes:string[], mkdirs:string[], copies:array, puts:array, mime_types:array} $plan
 * @return array<int, array{op:string, path:string}> op delete | mkdir | copy (from_path,
 *         from_revision) | put (local_path) | mime_type (type)
 */
function wporg_commit_operations(array $plan): array {
    $by_path = static fn(array $a, array $b): int => strcmp($a['path'], $b['path']);
    $copies = $plan['copies'];
    usort($copies, $by_path);
    $puts = $plan['puts'];
    usort($puts, $by_path);
    $mime_types = $plan['mime_types'];
    usort($mime_types, $by_path);
    $deletes = $plan['deletes'];
    usort($deletes, static fn(string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/') ?: strcmp($a, $b));
    $mkdirs = $plan['mkdirs'];
    usort($mkdirs, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));

    $in_copy = static function(string $path) use ($copies): bool {
        foreach ($copies as $copy) {
            if (str_starts_with($path, $copy['path'] . '/')) {
                return true;
            }
        }
        return false;
    };
    $paths = static fn(string $op, array $paths): array => array_map(static fn(string $path): array => [ 'op' => $op, 'path' => $path ], array_values($paths));
    $outside = static fn(array $paths): array => array_filter($paths, static fn(string $path): bool => !$in_copy($path));
    $inside = static fn(array $paths): array => array_filter($paths, $in_copy);

    return [
        ...$paths('delete', $outside($deletes)),
        ...$paths('mkdir', $outside($mkdirs)),
        ...array_map(static fn(array $copy): array => [ 'op' => 'copy', ...$copy ], $copies),
        ...$paths('delete', $inside($deletes)),
        ...$paths('mkdir', $inside($mkdirs)),
        ...array_map(static fn(array $put): array => [ 'op' => 'put', ...$put ], $puts),
        ...array_map(static fn(array $typed): array => [ 'op' => 'mime_type', ...$typed ], $mime_types),
    ];
}


/**
 * The message of every commit Peak Publisher makes: the deed, a note in brackets when there is
 * one, and the attribution last — without exception, so whoever reads a log sees the sender at
 * the end of every line. Trac's log overview cuts a message longer than 75 characters from the
 * end: the attribution goes first, then a note, never the deed — which is why the deed comes
 * first and says what was meant, not what the diff shows.
 *
 * @param string[] $notes The notes for the brackets; empty ones vanish.
 */
function wporg_commit_message(string $deed, array $notes = []): string {
    $notes = array_values(array_filter($notes, static fn(?string $note): bool => $note !== null && $note !== ''));
    return $deed . ($notes === [] ? '' : ' (' . implode(', ', $notes) . ')') . ' via Peak Publisher';
}


/**
 * What a deploy's commit means, from what changes in the tag: publish (the tag is new),
 * replace (an existing tag gets other files), readme (only its readme) — trunk and the Live
 * Preview follow in the diff. With the tag unchanged, the rest speaks: trunk (it gets the
 * version), stable_tag (nothing but the pointer moves — the commit is a flip), live_preview
 * (nothing but the carry of the release's Requires Plugins), null (nothing changes;
 * commit_files() commits nothing).
 *
 * @param array      $tag_plan   The plan of tags/{version} (wporg_reconcile_plan()).
 * @param array|null $trunk_plan trunk's plan — the reconcile when trunk goes along, the Stable
 *                               tag line's put when only the pointer moves — or null.
 */
function wporg_deploy_kind(bool $tag_exists, array $tag_plan, ?array $trunk_plan, bool $pointer_changed, bool $live_preview_adjusted): ?string {
    if (!$tag_exists) {
        return 'publish';
    }
    if (!wporg_plan_is_empty($tag_plan)) {
        return wporg_plan_puts_only_a_readme($tag_plan) ? 'readme' : 'replace';
    }
    if ($trunk_plan === null || wporg_plan_is_empty($trunk_plan)) {
        return $live_preview_adjusted ? 'live_preview' : null;
    }
    return $pointer_changed && wporg_plan_puts_only_a_readme($trunk_plan) ? 'stable_tag' : 'trunk';
}

/** A plan without deletes, folders, copies and puts — an existing base as it is. */
function wporg_plan_is_empty(array $plan): bool {
    return $plan['deletes'] === [] && $plan['mkdirs'] === [] && $plan['copies'] === [] && $plan['puts'] === [];
}

/** A plan whose only content change is the put of one readme (wordpress.org's readme names); folders aside. */
function wporg_plan_puts_only_a_readme(array $plan): bool {
    return $plan['deletes'] === [] && $plan['copies'] === [] && count($plan['puts']) === 1
        && find_wporg_readme_file_name([ basename((string) $plan['puts'][0]['path']) ]) !== null;
}

/**
 * The commit message of a deploy, by its kind (wporg_deploy_kind()): the deed, then whether the
 * release went live — the stable tag set, staying, or unchanged when trunk has no readable
 * pointer — and that the Live Preview was adjusted when the commit carried the release's
 * Requires Plugins into it. A flip reads as one (wporg_stable_tag_commit_message()), a carry
 * alone as its own deed. No kind, no message.
 *
 * @param array{pointer_changed:bool, pointer_value:string} $decision The settled pointer decision.
 * @param string|null $stable_tag_before trunk's live Stable tag ('' without one, null unreadable).
 */
function wporg_deploy_commit_message(?string $kind, string $wporg_slug, string $version, array $decision, ?string $stable_tag_before, bool $live_preview_adjusted): string {
    if ($kind === null) {
        return '';
    }
    if ($kind === 'stable_tag') {
        return wporg_stable_tag_commit_message($wporg_slug, $version, $stable_tag_before, $live_preview_adjusted);
    }
    if ($kind === 'live_preview') {
        return wporg_commit_message(sprintf('Adjust Live Preview of %s', $wporg_slug));
    }
    $deed = match ($kind) {
        'publish' => 'Publish version %s of %s',
        'replace' => 'Replace version %s of %s',
        'readme' => 'Update readme of version %s of %s',
        'trunk' => 'Update trunk to version %s of %s',
    };
    $stable_tag = $decision['pointer_changed']
        ? sprintf('stable tag set to %s', $decision['pointer_value'])
        : ($stable_tag_before !== null && $stable_tag_before !== '' ? sprintf('stable tag stays %s', $stable_tag_before) : 'stable tag unchanged');
    return wporg_commit_message(sprintf($deed, $version, $wporg_slug), [ $stable_tag, $live_preview_adjusted ? 'Live Preview adjusted' : null ]);
}

/**
 * The commit message of a Stable tag flip: the pointer before, when trunk had one, and that the
 * Live Preview was adjusted when the commit carried the release's Requires Plugins into it.
 */
function wporg_stable_tag_commit_message(string $wporg_slug, string $version, ?string $from, bool $live_preview_adjusted): string {
    return wporg_commit_message(sprintf('Set stable tag to version %s of %s', $version, $wporg_slug), [
        $from !== null && $from !== '' ? sprintf('was %s', $from) : null,
        $live_preview_adjusted ? 'Live Preview adjusted' : null,
    ]);
}


/** 'tags/1.0' → ['tags', 'tags/1.0']: a path and every folder above it, shallow first. */
function wporg_path_with_ancestors(string $path): array {
    $parts = explode('/', trim($path, '/'));
    return array_map(static fn(int $depth): string => implode('/', array_slice($parts, 0, $depth)), range(1, count($parts)));
}
