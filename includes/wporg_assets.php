<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The wordpress.org side of a marker's assets. The mirror (wporg-plugins/{slug}/mirror/assets/,
 * described by the manifest metas like a self-hosted plugin's directory) holds the slot winners
 * as they are on SVN; the state below anchors it to the revision of assets/ it holds. Pure
 * functions; WporgAssetSync does the I/O.
 */
const PBLSH_WPORG_ASSETS_META = '_pblsh_wporg_assets';

function wporg_assets_state_defaults(): array {
    return [
        'revision' => null,      // revision of assets/ the mirror holds; 0 = no directory, null = never pulled
        'listed_at' => 0,        // when the mirror was last confirmed against SVN
        'other_files' => 0,      // files in assets/ that are no slot member (the tab's footer)
        'color_source' => null,  // { filename, revision } the stored banner color was computed from (A10)
        'pending' => [],         // the working copy: slot_id → entry
    ];
}

function get_wporg_assets_state(int $marker_id): array {
    // Re-validation when reading back from persistence: unknown keys go, a foreign shape reads as the default.
    $defaults = wporg_assets_state_defaults();
    $stored = get_post_meta($marker_id, PBLSH_WPORG_ASSETS_META, true);
    $state = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    $state['pending'] = is_array($state['pending']) ? array_filter($state['pending'], 'is_array') : [];
    return $state;
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
