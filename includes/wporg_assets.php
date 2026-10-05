<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The wordpress.org side of a marker's assets. The mirror (wporg-plugins/{slug}/mirror/assets/,
 * described by the manifest metas like a self-hosted plugin's directory) holds the slot winners
 * as they are on SVN; the state below anchors it to the revision of assets/ it holds. The
 * working copy holds only pending changes — per slot one entry `put` (a file in pending/assets/
 * under its canonical target name), `copy` (another slot's mirror file: move and swap) or
 * `delete` — recorded when the change is made, with the mirror state it builds on (`base`).
 * Pure functions; WporgAssetSync does the I/O.
 */
const PBLSH_WPORG_ASSETS_META = '_pblsh_wporg_assets';

function wporg_assets_state_defaults(): array {
    return [
        'revision' => null,      // revision of assets/ the mirror holds; 0 = no directory, null = never pulled
        'other_files' => 0,      // files in assets/ that are no slot member (the tab's footer)
        'color_source' => null,  // { filename, revision } the stored banner color was computed from (A10)
        'pending' => [],         // the working copy: slot_id → entry
    ];
}

function get_wporg_assets_state(int $marker_id): array {
    // Re-validation when reading back from persistence: unknown keys go, a foreign shape reads
    // as the default, a pending entry that is not what apply_asset_change() writes is dropped.
    $defaults = wporg_assets_state_defaults();
    $stored = get_post_meta($marker_id, PBLSH_WPORG_ASSETS_META, true);
    $state = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    $pending = is_array($state['pending']) ? $state['pending'] : [];
    $state['pending'] = array_filter($pending, static fn($entry, $slot_id): bool => is_wporg_asset_entry((string) $slot_id, $entry), ARRAY_FILTER_USE_BOTH);
    return $state;
}

/**
 * Whether a stored entry has the shape apply_asset_change() writes for a slot of the tab
 * (docs/data-schema.md): the actor, a base that is null or a mirror state, and per action its
 * content — a put names its file in pending/assets/, a copy its source, a delete has a base.
 */
function is_wporg_asset_entry(string $slot_id, $entry): bool {
    $is_mirror_state = static fn($value): bool => is_array($value) && is_string($value['filename'] ?? null) && is_int($value['revision'] ?? null);
    if (!is_array($entry) || asset_slot_definition($slot_id) === null || !is_int($entry['at'] ?? null) || !is_array($entry['user'] ?? null)) {
        return false;
    }
    $base = $entry['base'] ?? null;
    if ($base !== null && !$is_mirror_state($base)) {
        return false;
    }
    $action = $entry['action'] ?? null;
    if ($action === 'delete') {
        return $base !== null;
    }
    if (!in_array($action, [ 'put', 'copy' ], true) || !is_string($entry['ext'] ?? null) || !is_int($entry['filesize'] ?? null)
        || !array_key_exists('width', $entry) || !array_key_exists('height', $entry)) {
        return false;
    }
    if ($action === 'put') {
        return is_string($entry['file'] ?? null) && $entry['file'] !== '';
    }
    return is_array($entry['from'] ?? null) && is_string($entry['from']['slot'] ?? null) && $is_mirror_state($entry['from']);
}

function update_wporg_assets_state(int $marker_id, array $state): void {
    update_post_meta($marker_id, PBLSH_WPORG_ASSETS_META, array_intersect_key($state, wporg_assets_state_defaults()));
}

/** A mirror entry as a base — the file and revision a slot holds (null = the slot is empty). */
function wporg_asset_base(?array $mirror_entry): ?array {
    return $mirror_entry === null ? null : [ 'filename' => (string) $mirror_entry['filename'], 'revision' => (int) $mirror_entry['revision'] ];
}

/**
 * What the pull changes in the mirror: the slots whose winner on SVN differs in name or
 * revision from the mirror's (fetch), and the slots SVN no longer fills (remove).
 *
 * @return array{fetch: string[], remove: string[]}
 */
function mirror_diff(array $mirror, array $remote_winners): array {
    $fetch = [];
    foreach ($remote_winners as $slot_id => $winner) {
        if (wporg_asset_base($mirror[$slot_id] ?? null) !== wporg_asset_base($winner)) {
            $fetch[] = (string) $slot_id;
        }
    }
    $remove = array_map('strval', array_keys(array_diff_key($mirror, $remote_winners)));
    return [ 'fetch' => $fetch, 'remove' => $remove ];
}


/**
 * What a slot holds once the working copy is applied, as content that could be placed in
 * another slot: its pending put or copy, else a copy of its own mirror file, else null.
 */
function effective_asset_entry(array $pending, array $mirror, string $slot_id): ?array {
    if (isset($pending[$slot_id])) {
        return $pending[$slot_id]['action'] === 'delete' ? null : $pending[$slot_id];
    }
    $file = $mirror[$slot_id] ?? null;
    if ($file === null) {
        return null;
    }
    return [
        'action' => 'copy',
        'from' => [ 'slot' => $slot_id, 'filename' => (string) $file['filename'], 'revision' => (int) $file['revision'] ],
        'ext' => classify_asset_filename((string) $file['filename'])['ext'],
        'filesize' => (int) $file['filesize'],
        'width' => $file['width'],
        'height' => $file['height'],
    ];
}

/** The slots that hold something once the working copy is applied — mirror order, then new ones. */
function effective_asset_slots(array $pending, array $mirror): array {
    $slots = [];
    foreach (array_unique([ ...array_map('strval', array_keys($mirror)), ...array_map('strval', array_keys($pending)) ]) as $slot_id) {
        if (effective_asset_entry($pending, $mirror, $slot_id) !== null) {
            $slots[] = $slot_id;
        }
    }
    return $slots;
}

/**
 * Records one change in the working copy — the place every pending fact is decided. A put
 * replaces the slot's content; a delete empties it (a never committed upload just vanishes);
 * a move fills an empty slot with the source's content and empties the source, onto an
 * occupied slot it swaps the two — decided here from the effective state, never from what
 * the client believed. Content travels as it is: a pending upload moves (its file is renamed),
 * a mirror file is referenced as a copy. An entry that restores the slot's own mirror state is
 * dropped. Every entry carries the target slot's mirror state as `base`.
 *
 * @param array $change { action: put, slot, ext, filesize, width, height, at, user }
 *                      | { action: delete, slot, at, user } | { action: move, from, to, at, user }
 * @return array{pending: array, renames: array<int, array{0:string, 1:string}>, removes: string[], mode: ?string}
 *         renames/removes: file names in pending/assets/ the caller moves or deletes (renames as a set — swaps cross)
 */
function apply_asset_change(array $pending, array $mirror, array $change): array {
    $actor = [ 'at' => (int) $change['at'], 'user' => $change['user'] ];

    $place = static function(?array $content, string $slot_id) use ($mirror, $actor): ?array {
        $base = wporg_asset_base($mirror[$slot_id] ?? null);
        if ($content === null) {
            return $base === null ? null : [ 'action' => 'delete', ...$actor, 'base' => $base ];
        }
        if ($content['action'] === 'copy' && $content['from']['slot'] === $slot_id && $base !== null
            && $content['from']['filename'] === $base['filename'] && $content['from']['revision'] === $base['revision']) {
            return null; // the slot's own mirror state — no change
        }
        $entry = [ ...$content, 'at' => $content['at'] ?? $actor['at'], 'user' => $content['user'] ?? $actor['user'], 'base' => $base ];
        if ($content['action'] === 'put') {
            $entry['file'] = asset_canonical_filename($slot_id, $content['ext']);
        }
        return $entry;
    };
    $set = static function(array &$pending, string $slot_id, ?array $entry): void {
        if ($entry === null) {
            unset($pending[$slot_id]);
        } else {
            $pending[$slot_id] = $entry;
        }
    };

    if ($change['action'] === 'put' || $change['action'] === 'delete') {
        $slot_id = (string) $change['slot'];
        $old = $pending[$slot_id] ?? null;
        $content = $change['action'] === 'put'
            ? [ 'action' => 'put', 'ext' => (string) $change['ext'], 'filesize' => (int) $change['filesize'], 'width' => $change['width'], 'height' => $change['height'], ...$actor ]
            : null;
        $entry = $place($content, $slot_id);
        $removes = $old !== null && $old['action'] === 'put' && ($entry === null || $entry['file'] !== $old['file']) ? [ $old['file'] ] : [];
        $set($pending, $slot_id, $entry);
        return [ 'pending' => $pending, 'renames' => [], 'removes' => $removes, 'mode' => null ];
    }

    // A move: onto an empty slot, or a swap onto an occupied one.
    $from = 'screenshot-' . (int) $change['from'];
    $to = 'screenshot-' . (int) $change['to'];
    $from_content = effective_asset_entry($pending, $mirror, $from);
    $to_content = effective_asset_entry($pending, $mirror, $to);
    $new_to = $place($from_content, $to);
    $new_from = $place($to_content, $from);
    $renames = [];
    foreach ([ [ $from_content, $new_to ], [ $to_content, $new_from ] ] as [ $content, $entry ]) {
        if ($content !== null && $content['action'] === 'put' && $entry !== null && $content['file'] !== $entry['file']) {
            $renames[] = [ $content['file'], $entry['file'] ];
        }
    }
    $set($pending, $to, $new_to);
    $set($pending, $from, $new_from);
    return [ 'pending' => $pending, 'renames' => $renames, 'removes' => [], 'mode' => $to_content === null ? 'move' : 'swap' ];
}

/** Slots whose entry builds on a mirror state the mirror no longer has — changed here and on wordpress.org. */
function asset_conflicts(array $pending, array $mirror): array {
    $conflicts = [];
    foreach ($pending as $slot_id => $entry) {
        if (($entry['base'] ?? null) !== wporg_asset_base($mirror[$slot_id] ?? null)) {
            $conflicts[] = (string) $slot_id;
        }
    }
    return $conflicts;
}

/**
 * Copy entries whose source file the pull is about to replace or remove: they become puts of
 * the old file first, so the entry keeps its meaning ("the picture that was in slot 3").
 */
function copy_sources_to_secure(array $pending, array $remote_winners): array {
    $slots = [];
    foreach ($pending as $slot_id => $entry) {
        $source = [ 'filename' => $entry['from']['filename'] ?? null, 'revision' => $entry['from']['revision'] ?? null ];
        if ($entry['action'] === 'copy' && wporg_asset_base($remote_winners[$entry['from']['slot']] ?? null) !== $source) {
            $slots[] = (string) $slot_id;
        }
    }
    return $slots;
}

/**
 * The one commit of the working copy, built from the fresh listing under the lock: a put
 * writes the slot's canonical file (the PUT replaces it if it exists) and deletes every other
 * member; a copy (move, swap) copies the source from the base revision — the source is fixed,
 * whatever this commit deletes — and needs its target path free, so every member and any file
 * holding the target name goes first (R in the log); a delete removes every member. Every
 * raster file written — put or copy — gets its svn:mime-type set in the same commit: a copy
 * inherits its source's property, a put over an old file keeps that file's, and the plugin
 * handbook asks for the image type. Message and details in the pattern of every Peak
 * Publisher commit.
 *
 * @param array|null $listing WporgOperations::list_assets() — null: assets/ does not exist yet
 */
function build_asset_commit_plan(string $slug, array $pending, ?array $listing, int $base_revision, string $pending_dir): array {
    $entries = $listing['entries'] ?? [];
    $slots = classify_asset_listing($entries)['slots'];
    $names = array_column($entries, 'name');
    $deletes = [];
    $copies = [];
    $puts = [];
    $mime_types = [];
    $done = [ 'updated' => [], 'moved' => [], 'deleted' => [] ];
    $typed = static function(string $target, string $ext) use (&$mime_types): void {
        $type = asset_mime_type($ext);
        if ($type !== null) {
            $mime_types[] = [ 'path' => 'assets/' . $target, 'type' => $type ];
        }
    };

    foreach ($pending as $slot_id => $entry) {
        $members = $slots[$slot_id]['members'] ?? [];
        if ($entry['action'] === 'put') {
            $target = asset_canonical_filename((string) $slot_id, $entry['ext']);
            $puts[] = [ 'path' => 'assets/' . $target, 'local_path' => $pending_dir . '/' . $entry['file'] ];
            $deletes = [ ...$deletes, ...array_filter($members, static fn(string $member): bool => $member !== $target) ];
            $typed($target, $entry['ext']);
            $done['updated'][] = $target;
        } elseif ($entry['action'] === 'copy') {
            $target = asset_canonical_filename((string) $slot_id, $entry['ext']);
            $copies[] = [ 'path' => 'assets/' . $target, 'from_path' => 'assets/' . $entry['from']['filename'], 'from_revision' => $base_revision ];
            $deletes = [ ...$deletes, ...$members, ...(in_array($target, $names, true) ? [ $target ] : []) ];
            $typed($target, $entry['ext']);
            $done['moved'][] = $target;
        } else {
            $deletes = [ ...$deletes, ...$members ];
            $done['deleted'][] = $entry['base']['filename'] ?? (string) $slot_id;
        }
    }

    $parts = array_filter([
        $done['updated'] ? count($done['updated']) . ' updated' : null,
        $done['moved'] ? count($done['moved']) . ' moved' : null,
        $done['deleted'] ? count($done['deleted']) . ' deleted' : null,
    ]);
    return [
        'deletes' => array_values(array_map(static fn(string $name): string => 'assets/' . $name, array_unique($deletes))),
        'mkdirs' => $listing === null ? [ 'assets' ] : [],
        'copies' => $copies,
        'puts' => $puts,
        'mime_types' => $mime_types,
        'message' => sprintf('Update assets of %s (%s) via Peak Publisher', $slug, implode(', ', $parts)),
        'details' => $done,
    ];
}

/** The listing as the commit leaves it — no second read: the revision guard guarantees nothing else happened. */
function asset_listing_after_plan(array $entries, array $plan, int $revision, array $sizes): array {
    $by_name = [];
    foreach ($entries as $entry) {
        $by_name[(string) $entry['name']] = $entry;
    }
    foreach ($plan['deletes'] as $path) {
        unset($by_name[basename($path)]);
    }
    foreach ([ ...$plan['copies'], ...$plan['puts'] ] as $written) {
        $name = basename((string) $written['path']);
        $by_name[$name] = [ 'name' => $name, 'type' => 'file', 'size' => (int) ($sizes[$name] ?? 0), 'revision' => $revision ];
    }
    ksort($by_name, SORT_STRING);
    return array_values($by_name);
}
