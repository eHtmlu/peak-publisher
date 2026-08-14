<?php

namespace Pblsh;

defined('ABSPATH') || exit;


const PBLSH_WPORG_IMPORT_CHUNK_SIZE = 5;


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
    raise_wporg_time_limit();

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
        $result = wporg_upsert_release_post_from_wporg_tag($plugin_post, $version, $tag, $tag_data, $existing);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        if ($existing instanceof \WP_Post) {
            $summary['updated']++;
        } else {
            $summary['created']++;
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


function fetch_wporg_import_cache_bundle(string $wporg_slug) {
    raise_wporg_time_limit();

    $wporg_slug = normalize_wporg_slug($wporg_slug);
    if (is_wp_error($wporg_slug)) {
        return $wporg_slug;
    }

    require_once PBLSH_PLUGIN_DIR . 'classes/SvnDeployWorkflow.php';

    try {
        $root_revision = SvnDeployWorkflow::get_plugin_revision($wporg_slug);
        if ($root_revision === null) {
            return new \WP_Error(
                'not_found',
                __('Plugin not found on wordpress.org SVN.', 'peak-publisher'),
                [ 'status' => 404 ]
            );
        }

        $tags = SvnDeployWorkflow::list_tags($wporg_slug);
        $versions = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $version = (string) ($tag['version'] ?? '');
            if ($version !== '') {
                $versions[] = $version;
            }
        }
        $tag_data_by_version = SvnDeployWorkflow::fetch_tags_data_batch($wporg_slug, $versions);
    } catch (\Throwable $e) {
        return new \WP_Error(
            'access_check_failed',
            __('Could not read wordpress.org SVN data for this plugin.', 'peak-publisher'),
            [ 'status' => 502 ]
        );
    }

    $releases = [];
    foreach ($tags as $tag) {
        if (!is_array($tag)) {
            continue;
        }

        $version = (string) ($tag['version'] ?? '');
        if ($version === '') {
            continue;
        }

        $tag_data = $tag_data_by_version[$version] ?? [];
        $releases[] = [
            'version' => $version,
            'tag_revision' => (int) ($tag['revision'] ?? 0),
            'date' => (string) ($tag['date'] ?? ''),
            'plugin_data' => $tag_data['plugin_data'] ?? null,
            'plugin_info' => $tag_data['plugin_info'] ?? null,
            'plugin_readme_txt' => $tag_data['plugin_readme_txt'] ?? default_plugin_readme_txt_data(),
        ];
    }

    usort($releases, static function(array $a, array $b): int {
        return version_compare((string) ($b['version'] ?? ''), (string) ($a['version'] ?? ''));
    });

    $reference_version = '';
    $reference_name = null;
    if (!empty($releases)) {
        $reference = $releases[0];
        $reference_version = (string) ($reference['version'] ?? '');
        $name = (string) ($reference['plugin_data']['Name'] ?? '');
        $reference_name = $name !== '' ? $name : null;
    }

    return [
        'root_revision' => (int) $root_revision,
        'release_count' => count($releases),
        'reference_version' => $reference_version,
        'reference_name' => $reference_name,
        'releases' => $releases,
    ];
}


function persist_wporg_import_cache_bundle(string $wporg_slug, string $username, array $bundle) {
    $wporg_slug = normalize_wporg_slug($wporg_slug);
    if (is_wp_error($wporg_slug)) {
        return $wporg_slug;
    }

    $username = normalize_wporg_username($username, 'username');
    if (is_wp_error($username)) {
        return $username;
    }

    $existing = get_plugin_post_by_slug('pblsh_wporg_plugin', $wporg_slug);
    if ($existing instanceof \WP_Post) {
        return new \WP_Error(
            'already_imported',
            __('Plugin already imported.', 'peak-publisher'),
            [
                'status' => 409,
                'existing_plugin_id' => (int) $existing->ID,
            ]
        );
    }

    $created_marker_id = 0;
    $created_release_ids = [];

    try {
        $post_content = wp_json_encode([
            'revision' => (int) ($bundle['root_revision'] ?? 0),
            'release_count' => (int) ($bundle['release_count'] ?? 0),
            'fetched_at' => time(),
        ]);
        if (!is_string($post_content)) {
            throw new \RuntimeException('wporg_import_cache_encode_failed');
        }

        $marker_id = wp_insert_post([
            'post_type' => 'pblsh_wporg_plugin',
            'post_status' => 'publish',
            'post_name' => $wporg_slug,
            'post_title' => (string) (($bundle['reference_name'] ?? '') ?: $wporg_slug),
            'post_content' => wp_slash($post_content),
        ], true);
        if (is_wp_error($marker_id)) {
            throw new \RuntimeException($marker_id->get_error_message());
        }
        $created_marker_id = (int) $marker_id;

        $marker = get_post($created_marker_id);
        if (!$marker instanceof \WP_Post || $marker->post_name !== $wporg_slug) {
            if ($created_marker_id > 0) {
                wp_delete_post($created_marker_id, true);
                $created_marker_id = 0;
            }

            $existing_after_race = get_plugin_post_by_slug('pblsh_wporg_plugin', $wporg_slug);
            if ($existing_after_race instanceof \WP_Post) {
                return new \WP_Error(
                    'already_imported',
                    __('Plugin already imported.', 'peak-publisher'),
                    [
                        'status' => 409,
                        'existing_plugin_id' => (int) $existing_after_race->ID,
                    ]
                );
            }

            throw new \RuntimeException('wporg_import_slug_race');
        }

        update_post_meta($created_marker_id, '_pblsh_wporg_account_username', $username);

        foreach (($bundle['releases'] ?? []) as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = (string) ($release['version'] ?? '');
            if ($version === '') {
                continue;
            }

            $content = wp_json_encode([
                'tag_revision' => (int) ($release['tag_revision'] ?? 0),
                'plugin_data' => $release['plugin_data'] ?? null,
                'plugin_info' => $release['plugin_info'] ?? null,
                'plugin_readme_txt' => $release['plugin_readme_txt'] ?? default_plugin_readme_txt_data(),
            ]);
            if (!is_string($content)) {
                throw new \RuntimeException('wporg_import_release_encode_failed');
            }

            [$post_date, $post_date_gmt] = wporg_svn_date_to_post_dates((string) ($release['date'] ?? ''));
            $release_id = wp_insert_post([
                'post_type' => 'pblsh_release',
                'post_status' => 'publish',
                'post_parent' => $created_marker_id,
                'post_title' => $version,
                'post_name' => get_release_slug($wporg_slug, $version),
                'post_content' => wp_slash($content),
                'post_date' => $post_date,
                'post_date_gmt' => $post_date_gmt,
            ], true);
            if (is_wp_error($release_id)) {
                throw new \RuntimeException($release_id->get_error_message());
            }

            $created_release_ids[] = (int) $release_id;
        }

        return $created_marker_id;
    } catch (\Throwable $e) {
        foreach (array_reverse($created_release_ids) as $release_id) {
            wp_delete_post((int) $release_id, true);
        }
        if ($created_marker_id > 0) {
            wp_delete_post($created_marker_id, true);
        }
        wporg_log_import_error($wporg_slug, $e);

        return new \WP_Error(
            'access_check_failed',
            __('Could not persist wordpress.org import data.', 'peak-publisher'),
            [ 'status' => 500 ]
        );
    }
}


function sync_wporg_deployed_release_post(\WP_Post $plugin_post, string $version) {
    if (!is_wporg_plugin($plugin_post)) {
        return new \WP_Error(
            'invalid_plugin',
            __('Expected a wordpress.org plugin marker.', 'peak-publisher'),
            [ 'status' => 400 ]
        );
    }

    $version = trim($version);
    if ($version === '') {
        return new \WP_Error(
            'invalid_version',
            __('Missing plugin version.', 'peak-publisher'),
            [ 'status' => 400 ]
        );
    }

    require_once PBLSH_PLUGIN_DIR . 'classes/SvnDeployWorkflow.php';

    try {
        // Read the committed tag from SVN
        $tags = SvnDeployWorkflow::list_tags($plugin_post->post_name);
        $tag = null;
        foreach ($tags as $candidate) {
            if ((string) ($candidate['version'] ?? '') === $version) {
                $tag = $candidate;
                break;
            }
        }

        if (!is_array($tag)) {
            return new \WP_Error(
                'wporg_deployed_tag_not_found',
                __('The deployed wordpress.org tag could not be read after commit.', 'peak-publisher'),
                [ 'status' => 502 ]
            );
        }

        // Upsert the local release mirror for that tag
        $tag_data = SvnDeployWorkflow::fetch_tag_data($plugin_post->post_name, $version);
        $existing = wporg_find_release_post_by_version((int) $plugin_post->ID, $version);
        $release_id = wporg_upsert_release_post_from_wporg_tag($plugin_post, $version, $tag, $tag_data, $existing);

        if (is_wp_error($release_id)) {
            return $release_id;
        }

        // Refresh the marker title from the reference release
        wporg_refresh_plugin_title_from_reference_release((int) $plugin_post->ID);

        return (int) $release_id;
    } catch (\Throwable $e) {
        wporg_log_cache_error($plugin_post, 'sync_deployed_release', $e);
        return new \WP_Error(
            'wporg_local_sync_failed_after_commit',
            __('The wordpress.org commit succeeded, but the local release cache could not be updated.', 'peak-publisher'),
            [ 'status' => 500 ]
        );
    }
}


function invalidate_wporg_plugin_cache(int $plugin_id): void {
    $post = get_post($plugin_id);
    if (!$post instanceof \WP_Post || !is_wporg_plugin($post)) {
        return;
    }

    wp_update_post([
        'ID' => $plugin_id,
        'post_content' => wp_slash('{}'),
    ]);
}


function wporg_find_release_post_by_version(int $plugin_id, string $version): ?\WP_Post {
    $releases = get_posts([
        'post_type' => 'pblsh_release',
        'post_status' => 'any',
        'post_parent' => $plugin_id,
        'title' => $version,
        'posts_per_page' => 1,
    ]);

    return !empty($releases) && $releases[0] instanceof \WP_Post ? $releases[0] : null;
}


function wporg_upsert_release_post_from_wporg_tag(
    \WP_Post $plugin_post,
    string $version,
    array $tag,
    array $tag_data,
    ?\WP_Post $existing = null
) {
    // Encode the wporg release snapshot stored in post_content
    $content = wp_json_encode([
        'tag_revision' => (int) ($tag['revision'] ?? 0),
        'plugin_data' => $tag_data['plugin_data'] ?? null,
        'plugin_info' => $tag_data['plugin_info'] ?? null,
        'plugin_readme_txt' => $tag_data['plugin_readme_txt'] ?? default_plugin_readme_txt_data(),
    ]);
    if (!is_string($content)) {
        throw new \RuntimeException('wporg_release_encode_failed');
    }

    // Use the SVN tag date as the release post date
    [$post_date, $post_date_gmt] = wporg_svn_date_to_post_dates((string) ($tag['date'] ?? ''));
    $post_data = [
        'post_type' => 'pblsh_release',
        'post_status' => 'publish',
        'post_parent' => (int) $plugin_post->ID,
        'post_title' => $version,
        'post_name' => get_release_slug($plugin_post->post_name, $version),
        'post_content' => wp_slash($content),
        'post_date' => $post_date,
        'post_date_gmt' => $post_date_gmt,
    ];

    // Insert or update the release post
    if ($existing instanceof \WP_Post) {
        $post_data['ID'] = (int) $existing->ID;
        $post_data['edit_date'] = true;
        $release_id = wp_update_post($post_data, true);
    } else {
        $release_id = wp_insert_post($post_data, true);
    }

    if (is_wp_error($release_id)) {
        return $release_id;
    }
    if (!$release_id) {
        return new \WP_Error(
            'wporg_release_persist_failed',
            __('Could not persist the wordpress.org release locally.', 'peak-publisher'),
            [ 'status' => 500 ]
        );
    }

    return (int) $release_id;
}


function wporg_log_import_error(string $wporg_slug, \Throwable $error): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'Peak Publisher wporg import failed for %s: %s',
            $wporg_slug,
            $error->getMessage()
        ));
    }
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
