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
 * The Live Preview is one more entry, `blueprint`: assets/blueprints/blueprint.json, its
 * contents in the entry and its mirror in the state (`blueprints`) instead of files — text
 * the editor needs as text (includes/wporg_blueprint.php). Pure functions; WporgAssetSync does
 * the I/O.
 */
const PBLSH_WPORG_ASSETS_META = '_pblsh_wporg_assets';

function wporg_assets_state_defaults(): array {
    return [
        'revision' => null,      // revision of assets/ the mirror holds; 0 = no directory, null = never pulled
        'other_files' => 0,      // files in assets/ that are no slot member (the tab's footer)
        'color_source' => null,  // { filename, revision } the stored banner color was computed from (A10)
        'blueprints' => wporg_blueprints_mirror_defaults(), // the mirror of assets/blueprints/
        'pending' => [],         // the working copy: slot_id → entry, 'blueprint' for the Live Preview
    ];
}

function wporg_blueprints_mirror_defaults(): array {
    return [
        'revision' => null,      // revision of assets/blueprints/ the mirror holds; null = no such directory
        'blueprint_files' => 0,  // files wordpress.org's import takes there (blueprint.json among them)
        'other_files' => 0,      // the other files there
        'file' => null,          // blueprint.json { revision, filesize, contents } — contents null over the limit
    ];
}

/** Whether a stored blueprints mirror has the shape the pull writes. */
function is_wporg_blueprints_mirror($mirror): bool {
    if (!is_array($mirror) || array_keys($mirror) !== array_keys(wporg_blueprints_mirror_defaults())
        || !($mirror['revision'] === null || is_int($mirror['revision'])) || !is_int($mirror['blueprint_files']) || !is_int($mirror['other_files'])) {
        return false;
    }
    $file = $mirror['file'];
    return $file === null || (is_array($file) && is_int($file['revision'] ?? null) && is_int($file['filesize'] ?? null)
        && array_key_exists('contents', $file) && ($file['contents'] === null || is_string($file['contents'])));
}

function get_wporg_assets_state(int $marker_id): array {
    // Re-validation when reading back from persistence: unknown keys go, a foreign shape reads
    // as the default, a pending entry that is not what apply_asset_change() writes is dropped.
    $defaults = wporg_assets_state_defaults();
    $stored = get_post_meta($marker_id, PBLSH_WPORG_ASSETS_META, true);
    $state = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    if (!is_wporg_blueprints_mirror($state['blueprints'])) {
        $state['blueprints'] = $defaults['blueprints'];
    }
    $pending = is_array($state['pending']) ? $state['pending'] : [];
    $state['pending'] = array_filter($pending, static fn($entry, $slot_id): bool => is_wporg_asset_entry((string) $slot_id, $entry), ARRAY_FILTER_USE_BOTH);
    return $state;
}

/**
 * Whether a stored entry has the shape apply_asset_change() writes for a slot of the tab, or
 * apply_blueprint_change() for the Live Preview (docs/data-schema.md): the actor, a base that
 * is null or a mirror state, and per action its content — a put names its file in
 * pending/assets/ (the blueprint's carries its contents), a copy its source, a delete has a base.
 */
function is_wporg_asset_entry(string $slot_id, $entry): bool {
    $is_mirror_state = static fn($value): bool => is_array($value) && is_string($value['filename'] ?? null) && is_int($value['revision'] ?? null);
    if (!is_array($entry) || ($slot_id !== 'blueprint' && asset_slot_definition($slot_id) === null) || !is_int($entry['at'] ?? null) || !is_array($entry['user'] ?? null)) {
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
    // The Live Preview's put carries the blueprint's contents (apply_blueprint_change()).
    if ($slot_id === 'blueprint') {
        return $action === 'put' && is_string($entry['contents'] ?? null);
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
    // Slashed: WordPress unslashes meta on its way in, and a blueprint is JSON full of backslashes.
    update_post_meta($marker_id, PBLSH_WPORG_ASSETS_META, wp_slash(array_intersect_key($state, wporg_assets_state_defaults())));
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

/**
 * Whether wordpress.org's import has read a file the mirror holds: the stamp of the directory
 * check (get_wporg_directory()['stamp']) names the file with a revision at or after the mirror
 * file's — the import exports the whole plugin at its revision then, and the info API shows the
 * file as soon as the import ran, since the import empties its cache. Null before the first
 * check: unknown, not "no". A changed filename is another file: the slot is imported only once
 * the stamp names the new one.
 *
 * @param array{filename:string, revision:int} $file a mirror state (wporg_asset_base(), blueprint_mirror_base())
 */
function asset_imported(?array $stamp, array $file): ?bool {
    if ($stamp === null) {
        return null;
    }
    $revision = $stamp['assets'][(string) $file['filename']] ?? null;
    return is_int($revision) && $revision >= (int) $file['revision'];
}

/**
 * The file the plugin page shows for every slot, slot → filename, read from the stamp's files
 * by the filename rule (classify_asset_filename(), asset_slot_id()); blueprint.json is the Live
 * Preview's, the other blueprint files have no slot. Empty before the first check.
 *
 * @return array<string, string>
 */
function assets_shown(?array $stamp): array {
    $shown = [];
    foreach (array_keys($stamp['assets'] ?? []) as $filename) {
        $filename = (string) $filename;
        if ($filename === PBLSH_BLUEPRINT_FILENAME) {
            $shown['blueprint'] = $filename;
            continue;
        }
        $class = classify_asset_filename($filename);
        $slot_id = $class === null ? null : asset_slot_id($class);
        if ($slot_id !== null) {
            $shown[$slot_id] = $filename;
        }
    }
    return $shown;
}

/**
 * The slots the plugin page passes over while it shows another file: wordpress.org picks the
 * icon by "SVG has priority" (Template::get_plugin_icon()) — while the page shows icon.svg,
 * the raster icons are read by the import but never shown, and the info API never names them,
 * so the stamp cannot say whether they were read. Slot ids, from what the page shows
 * (assets_shown()); nothing for banners, whose SVG wordpress.org never shows.
 *
 * @param array<string, string> $shown
 * @return string[]
 */
function assets_shadowed(array $shown): array {
    if (!isset($shown['icon_svg'])) {
        return [];
    }
    return array_keys(array_filter(get_asset_slots(), static fn(array $slot, string $slot_id): bool => $slot['type'] === 'icon' && $slot_id !== 'icon_svg', ARRAY_FILTER_USE_BOTH));
}

/**
 * Whether the plugin page is behind the mirror, an import still to come: a slot of the mirror
 * the import has not read (asset_imported()), or a slot the page shows a file for that the
 * mirror no longer holds — deleted, the import of the delete waiting. A slot the page passes
 * over (assets_shadowed()) waits for nothing: the page would show no more of it after an
 * import. Unknown is no waiting: false before the first check.
 *
 * @param array<string, array{filename:string, revision:int}> $mirror slot → state, the Live Preview included (wporg_mirror_slots())
 */
function assets_import_waiting(?array $stamp, array $mirror): bool {
    if ($stamp === null) {
        return false;
    }
    $shown = assets_shown($stamp);
    $shadowed = assets_shadowed($shown);
    foreach ($mirror as $slot_id => $file) {
        if (!in_array((string) $slot_id, $shadowed, true) && asset_imported($stamp, $file) !== true) {
            return true;
        }
    }
    return array_diff_key($shown, $mirror) !== [];
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

/** blueprint.json as a mirror state (null: wordpress.org has none) — the base a Live Preview entry builds on. */
function blueprint_mirror_base(array $state): ?array {
    $file = $state['blueprints']['file'];
    return $file === null ? null : [ 'filename' => PBLSH_BLUEPRINT_FILENAME, 'revision' => (int) $file['revision'] ];
}

/** The mirror as slot → state, the Live Preview included — what entries are compared with (asset_conflicts(), resolve). */
function wporg_mirror_slots(array $manifest, array $state): array {
    $blueprint = blueprint_mirror_base($state);
    return $blueprint === null ? $manifest : [ ...$manifest, 'blueprint' => $blueprint ];
}

/** The Live Preview once the working copy is applied: the entry's contents, null after a delete, else the mirror's (null: none, or not read over the limit). */
function effective_blueprint_contents(array $state): ?string {
    $entry = $state['pending']['blueprint'] ?? null;
    if ($entry !== null) {
        return $entry['action'] === 'put' ? $entry['contents'] : null;
    }
    return $state['blueprints']['file']['contents'] ?? null;
}

/**
 * Records a change of the Live Preview in the working copy — the place every blueprint entry
 * is decided. A put whose contents are byte for byte the mirror's leaves no entry; a delete
 * with nothing on wordpress.org leaves none either (a never committed blueprint just vanishes).
 * A new entry builds on the mirror; an entry that is there keeps the base it was built on — a
 * pull may have moved the mirror meanwhile, and that conflict stays one until it is decided.
 *
 * @param array $change { action: put, contents, at, user } | { action: delete, at, user }
 * @return array the new pending
 */
function apply_blueprint_change(array $pending, array $state, array $change): array {
    $file = $state['blueprints']['file'];
    $base = ($pending['blueprint'] ?? null) !== null ? $pending['blueprint']['base'] : blueprint_mirror_base($state);
    $actor = [ 'at' => (int) $change['at'], 'user' => $change['user'] ];
    unset($pending['blueprint']);
    if ($change['action'] === 'put' && ($file === null || $file['contents'] !== $change['contents'])) {
        $pending['blueprint'] = [ 'action' => 'put', 'contents' => (string) $change['contents'], 'base' => $base, ...$actor ];
    } elseif ($change['action'] === 'delete' && $file !== null) {
        $pending['blueprint'] = [ 'action' => 'delete', 'base' => $base, ...$actor ];
    }
    return $pending;
}

/**
 * The blueprints mirror after a commit that carried the Live Preview entry — no second read: the
 * revision guard guarantees nothing else happened. The directory's revision is the commit's.
 */
function blueprints_mirror_after_commit(array $mirror, ?array $entry, int $revision): array {
    if ($entry === null) {
        return $mirror;
    }
    $had = $mirror['file'] !== null;
    if ($entry['action'] === 'put') {
        return [ ...$mirror, 'revision' => $revision, 'blueprint_files' => $mirror['blueprint_files'] + ($had ? 0 : 1),
            'file' => [ 'revision' => $revision, 'filesize' => strlen($entry['contents']), 'contents' => $entry['contents'] ] ];
    }
    return [ ...$mirror, 'revision' => $revision, 'blueprint_files' => max(0, $mirror['blueprint_files'] - ($had ? 1 : 0)), 'file' => null ];
}

/**
 * The state after a deploy or flip carried dependencies into blueprint.json
 * (blueprint_dependency_carry()), in its commit: the mirror is the committed file; an open put
 * gets the same dependencies where it still lacks them. An open entry is rebased onto the
 * committed file only when it built on exactly what the carry read — then the change in between
 * is Peak Publisher's own and no conflict. An entry already in conflict, or one whose mirror had
 * not seen what the carry read (a commit from elsewhere, not pulled yet), keeps its base: that
 * conflict is the user's to decide; and a mirror that missed a commit is listed anew by the next
 * pull (revision null). An open put that now equals the mirror goes.
 *
 * @param array{contents:string, added:string[], read:string} $carry
 */
function apply_blueprint_carry(array $state, string $own_slug, array $carry, int $revision): array {
    $base_before = blueprint_mirror_base($state);
    $mirror_was_current = ($state['blueprints']['file']['contents'] ?? null) === $carry['read'];
    $state['blueprints'] = [ ...$state['blueprints'], 'revision' => $mirror_was_current ? $revision : null, 'blueprint_files' => max(1, $state['blueprints']['blueprint_files']),
        'file' => [ 'revision' => $revision, 'filesize' => strlen($carry['contents']), 'contents' => $carry['contents'] ] ];
    $entry = $state['pending']['blueprint'] ?? null;
    if ($entry === null) {
        return $state;
    }
    if ($entry['action'] === 'put') {
        $own_carry = blueprint_dependency_carry($entry['contents'], $own_slug, $carry['added']);
        $entry['contents'] = $own_carry['contents'] ?? $entry['contents'];
    }
    if ($mirror_was_current && $entry['base'] === $base_before) {
        $entry['base'] = blueprint_mirror_base($state);
    }
    if ($entry['action'] === 'put' && $entry['contents'] === $carry['contents']) {
        unset($state['pending']['blueprint']);
    } else {
        $state['pending']['blueprint'] = $entry;
    }
    return $state;
}

/**
 * The Requires Plugins the committed blueprint does not install before the plugin — what
 * visitors of the plugin page get, so what makes the Live Preview fail there. The working
 * copy's open change does not count: it is not on wordpress.org yet.
 *
 * @param string[] $required
 * @return string[]
 */
function live_preview_missing(array $state, string $own_slug, array $required): array {
    $contents = $state['blueprints']['file']['contents'] ?? null;
    return $contents === null ? [] : blueprint_missing_dependencies($contents, $own_slug, $required);
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
 * handbook asks for the image type. The Live Preview's put writes blueprints/blueprint.json from
 * the temp file the caller wrote with its contents (cleaned up with the commit), creating
 * blueprints/ — and assets/ — when the listing lacks them; its delete removes the file, the
 * directory stays. No mime type: to SVN it is text, and wordpress.org serves it from its own
 * store. The message says what was done (asset_commit_message()); the details list the files
 * by what happened to them — added when the slot held no file before, else updated.
 *
 * @param array|null  $listing        WporgOperations::list_assets() — null: assets/ does not exist yet
 * @param string|null $blueprint_path the temp file holding the contents of a blueprint put
 */
function build_asset_commit_plan(string $slug, array $pending, ?array $listing, int $base_revision, string $pending_dir, ?string $blueprint_path = null): array {
    $entries = $listing['entries'] ?? [];
    $slots = classify_asset_listing($entries)['slots'];
    $names = array_column($entries, 'name');
    $deletes = [];
    $copies = [];
    $puts = [];
    $mime_types = [];
    $mkdirs = $listing === null ? [ 'assets' ] : [];
    $cleanup = [];
    $done = [ 'added' => [], 'updated' => [], 'moved' => [], 'deleted' => [] ];
    $typed = static function(string $target, string $ext) use (&$mime_types): void {
        $type = asset_mime_type($ext);
        if ($type !== null) {
            $mime_types[] = [ 'path' => 'assets/' . $target, 'type' => $type ];
        }
    };

    foreach ($pending as $slot_id => $entry) {
        if ($slot_id === 'blueprint') {
            $file = 'blueprints/' . PBLSH_BLUEPRINT_FILENAME;
            if ($entry['action'] === 'delete') {
                $deletes[] = $file;
                $done['deleted'][] = $file;
                continue;
            }
            if ($blueprint_path === null) {
                throw new \RuntimeException('A blueprint put needs the file holding its contents.');
            }
            $puts[] = [ 'path' => 'assets/' . $file, 'local_path' => $blueprint_path ];
            $cleanup[] = $blueprint_path;
            $has_directory = in_array([ 'blueprints', 'dir' ], array_map(static fn(array $entry): array => [ (string) $entry['name'], (string) ($entry['type'] ?? 'file') ], $entries), true);
            if (!$has_directory) {
                $mkdirs[] = 'assets/blueprints';
            }
            $done[$entry['base'] === null ? 'added' : 'updated'][] = $file;
            continue;
        }
        $members = $slots[$slot_id]['members'] ?? [];
        if ($entry['action'] === 'put') {
            $target = asset_canonical_filename((string) $slot_id, $entry['ext']);
            $puts[] = [ 'path' => 'assets/' . $target, 'local_path' => $pending_dir . '/' . $entry['file'] ];
            $deletes = [ ...$deletes, ...array_filter($members, static fn(string $member): bool => $member !== $target) ];
            $typed($target, $entry['ext']);
            $done[$entry['base'] === null ? 'added' : 'updated'][] = $target;
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

    return [
        'deletes' => array_values(array_map(static fn(string $name): string => 'assets/' . $name, array_unique($deletes))),
        'mkdirs' => $mkdirs,
        'copies' => $copies,
        'puts' => $puts,
        'mime_types' => $mime_types,
        'message' => asset_commit_message($slug, $pending),
        'details' => $done,
        'cleanup' => $cleanup,
    ];
}

/**
 * The commit message of the working copy, in the words of the log reader: one verb when every
 * change means the same — Add, Update or Remove — and the things by their kind: the icon and
 * the banner as one thing each, whatever their slots, screenshots by number, the Live Preview
 * by its file. Mixed intents become "Update assets" with each thing's own word in brackets. A
 * thing is added when no slot of it held a file before, removed when none keeps one, else
 * updated — a move within the icon updates the icon.
 */
function asset_commit_message(string $slug, array $pending): string {
    // Every slot's word, folded into its thing's: icon, banner, screenshot-N, blueprint.
    $words = [];
    foreach ($pending as $slot_id => $entry) {
        $definition = asset_slot_definition((string) $slot_id);
        $thing = $slot_id === 'blueprint' ? 'blueprint' : (isset($definition['number']) ? 'screenshot-' . $definition['number'] : (string) $definition['type']);
        $words[$thing][] = $entry['action'] === 'delete' ? 'removed' : ($entry['base'] === null ? 'added' : 'updated');
    }
    $things = array_map(static fn(array $slot_words): string => count(array_unique($slot_words)) === 1 ? $slot_words[0] : 'updated', $words);

    $join = static fn(array $parts): string => count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . $parts[count($parts) - 1] : implode('', $parts);
    // In a fixed order: icon, banner, the screenshots — those of one word as one thing — the Live Preview.
    $items = [];
    foreach ([ 'icon', 'banner' ] as $thing) {
        if (isset($things[$thing])) {
            $items[] = [ $thing, $things[$thing] ];
        }
    }
    foreach ([ 'updated', 'added', 'removed' ] as $word) {
        $numbers = [];
        foreach ($things as $thing => $thing_word) {
            if ($thing_word === $word && str_starts_with($thing, 'screenshot-')) {
                $numbers[] = (int) substr($thing, strlen('screenshot-'));
            }
        }
        if ($numbers !== []) {
            sort($numbers);
            $items[] = [ (count($numbers) === 1 ? 'screenshot ' : 'screenshots ') . $join(array_map('strval', $numbers)), $word ];
        }
    }
    if (isset($things['blueprint'])) {
        $items[] = [ 'Live Preview ' . PBLSH_BLUEPRINT_FILENAME, $things['blueprint'] ];
    }

    $shared = array_values(array_unique(array_column($items, 1)));
    if (count($shared) === 1) {
        [ $verb, $preposition ] = [ 'added' => [ 'Add', 'to' ], 'updated' => [ 'Update', 'of' ], 'removed' => [ 'Remove', 'from' ] ][$shared[0]];
        return wporg_commit_message(sprintf('%s %s %s %s', $verb, $join(array_column($items, 0)), $preposition, $slug));
    }
    return wporg_commit_message(sprintf('Update assets of %s', $slug), array_map(static fn(array $item): string => $item[1] === 'updated' ? $item[0] : $item[0] . ' ' . $item[1], $items));
}

/**
 * The listing of assets/ as the commit leaves it — no second read: the revision guard guarantees
 * nothing else happened. Only the files of assets/ itself; blueprints/ has its own mirror.
 */
function asset_listing_after_plan(array $entries, array $plan, int $revision, array $sizes): array {
    $in_assets = static fn(string $path): bool => dirname($path) === 'assets';
    $by_name = [];
    foreach ($entries as $entry) {
        $by_name[(string) $entry['name']] = $entry;
    }
    foreach (array_filter($plan['deletes'], $in_assets) as $path) {
        unset($by_name[basename($path)]);
    }
    foreach (array_filter([ ...$plan['copies'], ...$plan['puts'] ], static fn(array $written): bool => $in_assets((string) $written['path'])) as $written) {
        $name = basename((string) $written['path']);
        $by_name[$name] = [ 'name' => $name, 'type' => 'file', 'size' => (int) ($sizes[$name] ?? 0), 'revision' => $revision ];
    }
    ksort($by_name, SORT_STRING);
    return array_values($by_name);
}
