<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Bulk-load release posts for multiple plugin parents in a single query.
 *
 * @param int[] $plugin_ids
 * @return array<int, \WP_Post[]>
 */
function fetch_releases_grouped_by_parent(array $plugin_ids): array {
    $plugin_ids = array_values(array_filter(array_map('intval', $plugin_ids)));
    if (empty($plugin_ids)) {
        return [];
    }

    $grouped = array_fill_keys($plugin_ids, []);
    $releases = get_posts([
        'post_type' => 'pblsh_release',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'post_parent__in' => $plugin_ids,
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    foreach ($releases as $release) {
        $parent_id = (int) $release->post_parent;
        if (!array_key_exists($parent_id, $grouped)) {
            $grouped[$parent_id] = [];
        }
        $grouped[$parent_id][] = $release;
    }

    return $grouped;
}
