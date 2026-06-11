<?php

namespace Pblsh;

defined('ABSPATH') || exit;


function get_wporg_plugin_data($plugin_post_or_id): array {
    $plugin_post = $plugin_post_or_id instanceof \WP_Post ? $plugin_post_or_id : get_post((int) $plugin_post_or_id);
    if (!$plugin_post instanceof \WP_Post || !is_wporg_plugin($plugin_post)) {
        return [];
    }

    require_once PBLSH_PLUGIN_DIR . 'classes/SvnDeployWorkflow.php';

    $cached = wporg_decode_json_object((string) $plugin_post->post_content);
    try {
        $current_revision = SvnDeployWorkflow::get_plugin_revision($plugin_post->post_name);
    } catch (\Throwable $e) {
        wporg_log_cache_error($plugin_post, 'root_revision', $e);
        if (!wporg_has_cached_revision($cached)) {
            throw new \RuntimeException(__('Could not refresh wordpress.org SVN cache.', 'peak-publisher'), 0, $e);
        }
        return $cached;
    }

    if ($current_revision === null) {
        if (!wporg_has_cached_revision($cached)) {
            throw new \RuntimeException(__('wordpress.org SVN plugin was not found.', 'peak-publisher'));
        }
        return $cached;
    }

    if ((int) ($cached['revision'] ?? 0) === (int) $current_revision && array_key_exists('release_count', $cached)) {
        return $cached;
    }

    try {
        $sync_summary = sync_wporg_release_posts($plugin_post, $current_revision);
    } catch (\Throwable $e) {
        wporg_log_cache_error($plugin_post, 'sync_releases', $e);
        if (!wporg_has_cached_revision($cached)) {
            throw new \RuntimeException(__('Could not refresh wordpress.org release cache.', 'peak-publisher'), 0, $e);
        }
        return $cached;
    }

    $next_cache = [
        'revision' => (int) $current_revision,
        'release_count' => (int) ($sync_summary['release_count'] ?? 0),
        'fetched_at' => time(),
    ];

    wp_update_post([
        'ID' => (int) $plugin_post->ID,
        'post_content' => wp_slash(wp_json_encode($next_cache)),
    ]);

    return $next_cache;
}


function sync_wporg_release_posts($plugin_post_or_id, ?int $root_revision = null): array {
    $plugin_post = $plugin_post_or_id instanceof \WP_Post ? $plugin_post_or_id : get_post((int) $plugin_post_or_id);
    if (!$plugin_post instanceof \WP_Post || !is_wporg_plugin($plugin_post)) {
        return [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'unchanged' => 0,
        ];
    }

    require_once PBLSH_PLUGIN_DIR . 'classes/SvnDeployWorkflow.php';

    $summary = [
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'unchanged' => 0,
    ];
    $tags = SvnDeployWorkflow::list_tags($plugin_post->post_name);
    $tags_by_version = [];
    foreach ($tags as $tag) {
        $version = (string) ($tag['version'] ?? '');
        if ($version !== '') {
            $tags_by_version[$version] = $tag;
        }
    }

    $existing_posts = get_posts([
        'post_type' => 'pblsh_release',
        'post_status' => 'any',
        'post_parent' => (int) $plugin_post->ID,
        'posts_per_page' => -1,
    ]);
    $existing_by_version = [];
    foreach ($existing_posts as $release_post) {
        if (!$release_post instanceof \WP_Post) {
            continue;
        }
        $version = (string) ($release_post->post_title ?? '');
        if ($version !== '' && !isset($existing_by_version[$version])) {
            $existing_by_version[$version] = $release_post;
        }
    }

    foreach ($tags_by_version as $version => $tag) {
        $existing = $existing_by_version[$version] ?? null;
        $current_tag_revision = (int) ($tag['revision'] ?? 0);
        $existing_content = $existing instanceof \WP_Post ? wporg_decode_json_object((string) $existing->post_content) : [];
        $existing_tag_revision = (int) ($existing_content['tag_revision'] ?? 0);

        if ($existing instanceof \WP_Post && $current_tag_revision > 0 && $existing_tag_revision === $current_tag_revision) {
            $summary['unchanged']++;
            continue;
        }

        $tag_data = SvnDeployWorkflow::fetch_tag_data($plugin_post->post_name, $version);
        $content = [
            'tag_revision' => $current_tag_revision,
            'plugin_data' => $tag_data['plugin_data'] ?? null,
            'plugin_readme_txt' => $tag_data['plugin_readme_txt'] ?? [
                'found' => false,
                'file_name' => '',
                'content' => [],
            ],
        ];
        [$post_date, $post_date_gmt] = wporg_svn_date_to_post_dates((string) ($tag['date'] ?? ''));

        $post_data = [
            'post_type' => 'pblsh_release',
            'post_status' => 'publish',
            'post_parent' => (int) $plugin_post->ID,
            'post_title' => $version,
            'post_name' => get_release_slug($plugin_post->post_name, $version),
            'post_content' => wp_slash(wp_json_encode($content)),
            'post_date' => $post_date,
            'post_date_gmt' => $post_date_gmt,
        ];

        if ($existing instanceof \WP_Post) {
            $post_data['ID'] = (int) $existing->ID;
            $post_data['edit_date'] = true;
            $result = wp_update_post($post_data, true);
            $summary['updated']++;
        } else {
            $result = wp_insert_post($post_data, true);
            $summary['created']++;
        }

        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
    }

    foreach ($existing_by_version as $version => $release_post) {
        if (!isset($tags_by_version[$version])) {
            wp_delete_post((int) $release_post->ID, true);
            $summary['deleted']++;
        }
    }

    wporg_refresh_plugin_title_from_reference_release((int) $plugin_post->ID);

    return [
        ...$summary,
        'root_revision' => $root_revision,
        'release_count' => count($tags_by_version),
    ];
}


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


function wporg_decode_json_object(string $json): array {
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}


function wporg_has_cached_revision(array $cached): bool {
    return (int) ($cached['revision'] ?? 0) > 0;
}


function wporg_log_cache_error(\WP_Post $plugin_post, string $stage, \Throwable $error): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'Peak Publisher wporg cache refresh failed for %s (%d) during %s: %s',
            $plugin_post->post_name,
            (int) $plugin_post->ID,
            $stage,
            $error->getMessage()
        ));
    }
}


function wporg_svn_date_to_post_dates(string $last_modified): array {
    $timestamp = strtotime($last_modified);
    if (!is_int($timestamp) || $timestamp <= 0) {
        $timestamp = time();
    }

    $post_date_gmt = gmdate('Y-m-d H:i:s', $timestamp);
    $post_date = get_date_from_gmt($post_date_gmt);
    return [$post_date, $post_date_gmt];
}


function wporg_refresh_plugin_title_from_reference_release(int $plugin_id): void {
    $reference = wporg_get_reference_release($plugin_id);
    if (!$reference instanceof \WP_Post) {
        return;
    }

    $content = wporg_decode_json_object((string) $reference->post_content);
    $name = (string) ($content['plugin_data']['Name'] ?? '');
    if ($name === '') {
        return;
    }

    $plugin_post = get_post($plugin_id);
    if (!$plugin_post instanceof \WP_Post || $plugin_post->post_title === $name) {
        return;
    }

    wp_update_post([
        'ID' => $plugin_id,
        'post_title' => $name,
    ]);
}


function wporg_get_reference_release(int $plugin_id): ?\WP_Post {
    $releases = get_posts([
        'post_type' => 'pblsh_release',
        'post_status' => 'any',
        'post_parent' => $plugin_id,
        'posts_per_page' => -1,
    ]);
    $reference = null;
    $reference_normalized = '';

    foreach ($releases as $release) {
        if (!$release instanceof \WP_Post) {
            continue;
        }

        $content = wporg_decode_json_object((string) $release->post_content);
        $version = (string) (($release->post_title ?? '') !== '' ? $release->post_title : ($content['plugin_data']['Version'] ?? ''));
        $normalized = normalize_version_number($version);
        if ($normalized === '') {
            continue;
        }

        if ($reference === null || version_compare($normalized, $reference_normalized, '>')) {
            $reference = $release;
            $reference_normalized = $normalized;
        }
    }

    return $reference instanceof \WP_Post ? $reference : null;
}
