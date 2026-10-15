<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The decisions of a wordpress.org deploy, without I/O: which tree a new tag is copied from,
 * what a base needs against the tree it is compared with, and the order a commit runs its
 * operations in. WporgOperations reads the trees and runs the commit (commit_files()).
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


/** 'tags/1.0' → ['tags', 'tags/1.0']: a path and every folder above it, shallow first. */
function wporg_path_with_ancestors(string $path): array {
    $parts = explode('/', trim($path, '/'));
    return array_map(static fn(int $depth): string => implode('/', array_slice($parts, 0, $depth)), range(1, count($parts)));
}
