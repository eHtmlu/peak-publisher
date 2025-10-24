<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class UploadWorkflow {
    /**
     * Absolute path to the current upload temp directory (pblsh/tmp/<upload_id>/).
     * This is set per request in process().
     */
    private string $tmp_root = '';

    /**
     * Upload directory array.
     * This is set per request in init_tmp_root().
     */
    private array $tmp_upload_dir = [];

    /**
     * Start time of the request.
     * This is set in the constructor.
     */
    private float $time_start = 0;

    /**
     * Constructor.
     * Sets the start time of the request.
     */
    function __construct() {
        $this->time_start = microtime(true);
    }

    /**
     * Alters WP upload_dir to point sideloads into our temp folder.
     *
     * @param array $uploads Original upload dir array from WP.
     * @return array Modified array pointing to $this->tmp_root . 'file'.
     */
    public function filter_upload_dir($uploads): array {
        $upload_dir = $this->tmp_upload_dir;
        return [
            'path' => $upload_dir['path'] . '/file',
            'url' => $upload_dir['url'] . '/file',
            'subdir' => '',
            'basedir' => $upload_dir['basedir'] . '/file',
            'baseurl' => $upload_dir['baseurl'] . '/file',
            'error' => $uploads['error'],
        ];
    }

    /**
     * Finalizes a previously validated upload: creates the plugin and release posts,
     * moves the ZIP to a permanent storage location, links entities and returns IDs.
     *
     * Expects 'upload_id' referencing cache.json and ZIP in pblsh/tmp/<upload_id>/.
     *
     * @param \WP_REST_Request $request REST request containing 'upload_id'.
     * @return array { status, plugin_id, release_id } or error structure.
     */
    public function finalize(\WP_REST_Request $request): array {
        $upload_id = sanitize_text_field((string) ($request->get_param('upload_id') ?? '')); 
        if ($upload_id === '') {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'missing_upload_id', 'message' => 'Missing upload_id.' ] ] ];
        }
        $this->init_tmp_root($upload_id);
        $cache_file = $this->tmp_root . 'cache.json';
        if (!file_exists($cache_file)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'upload_not_found', 'message' => 'Upload not found.' ] ] ];
        }
        $cache = json_decode(file_get_contents($cache_file), true);
        $zip_path = (string) ($cache['zip_path'] ?? '');
        $data = (array) ($cache['data'] ?? []);
        if (!file_exists($zip_path)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'zip_missing', 'message' => 'ZIP file is missing.' ] ] ];
        }

        // Check if the plugin and version are valid
        if (!$data['plugin_ok'] || !$data['version_ok']) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'plugin_or_version_invalid', 'message' => 'Plugin or version is invalid.' ] ] ];
        }

        // Check if the plugin slug is valid
        $plugin_slug = $data['plugin_info']['plugin_slug'];
        $plugin_slug_sanitized = sanitize_title($plugin_slug);
        if ($plugin_slug !== $plugin_slug_sanitized) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'plugin_slug_mismatch', 'message' => 'Plugin slug mismatch.' ] ] ];
        }

        // Check if the plugin slug is unique if it's a new plugin
        if (empty($data['existing_plugin'])) {
            $plugin_slug_unique = wp_unique_post_slug($plugin_slug_sanitized, 0, 'publish', 'pblsh_plugin', 0);
            if ($plugin_slug_sanitized !== $plugin_slug_unique) {
                return [ 'status' => 'error', 'errors' => [ [ 'code' => 'plugin_slug_mismatch', 'message' => 'Plugin slug mismatch.' ] ] ];
            }
        }

        // Check if the release slug is valid
        $release_slug = $data['plugin_info']['release_slug'];
        $release_slug_sanitized = sanitize_title($release_slug);
        if ($release_slug !== $release_slug_sanitized) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'release_slug_mismatch', 'message' => 'Release slug mismatch.' ] ] ];
        }

        // Check if the release slug is unique if it's a new release
        if (empty($data['related_releases']['existing'])) {
            $release_slug_unique = wp_unique_post_slug($release_slug_sanitized, 0, 'publish', 'pblsh_release', 0);
            if ($release_slug_sanitized !== $release_slug_unique) {
                return [ 'status' => 'error', 'errors' => [ [ 'code' => 'release_slug_mismatch', 'message' => 'Release slug mismatch.' ] ] ];
            }
        }

        // Delete the existing release file if it exists
        $existing_release_id = $data['related_releases']['existing']['id'] ?? 0;
        if ($existing_release_id) {
            $zip_to_replace_relative_path = (string) (get_post_meta($existing_release_id, '_pblsh_zip_path', true) ?? '');
            $zip_to_replace_full_path = trailingslashit(publisher_upload_basedir()) . $zip_to_replace_relative_path;
            if ($zip_to_replace_relative_path && file_exists($zip_to_replace_full_path)) {
                get_wp_filesystem()->delete($zip_to_replace_full_path);
            }
        }

        // Move the ZIP to the target directory
        $target_dir = trailingslashit(publisher_upload_basedir()) . 'plugins/' . $plugin_slug . '/' . sanitize_title($data['plugin_info']['normalized_version']) . '/';
        wp_mkdir_p($target_dir);
        $target_zip = $target_dir . basename($zip_path);
        if (!get_wp_filesystem()->move($zip_path, $target_zip)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'move_zip_failed', 'message' => 'Failed to move ZIP to target directory.' ] ] ];
        }

        // Create pblsh_plugin post
        $plugin_post_id = $data['existing_plugin'] ?? 0;
        $plugin_post_data = [
            'post_type' => 'pblsh_plugin',
            'post_status' => 'publish',
            'post_title' => $data['plugin_data']['Name'] ?? '',
            'post_name' => $plugin_slug,
        ];
        if ($plugin_post_id > 0) {
            if (empty($data['related_releases']['latest']) || version_compare($data['related_releases']['latest']['version'], $data['plugin_data']['Version'], '<=')) {
                $plugin_post_data['ID'] = $plugin_post_id;
                $plugin_post_id = wp_update_post($plugin_post_data);
            }
        } else {
            $plugin_post_id = wp_insert_post($plugin_post_data);
        }
        if (is_wp_error($plugin_post_id)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'create_plugin_failed', 'message' => $plugin_post_id->get_error_message() ] ] ];
        }
        if (!$plugin_post_id) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'create_plugin_failed', 'message' => 'Failed to create plugin post.' ] ] ];
        }
    

        // Create pblsh_release post (child of plugin)
        $release_meta = [
            '_pblsh_zip_path' => $this->rel_path($target_zip, publisher_upload_basedir()),
            '_pblsh_directory_content_hash' => $data['plugin_info']['content_hash'] ?? '',
        ];
        $release_post_data = [
            'ID' => $existing_release_id,
            'post_type' => 'pblsh_release',
            'post_status' => 'publish',
            'post_title' => $data['plugin_data']['Version'] ?? '',
            'post_name' => $release_slug,
            'post_parent' => (int) $plugin_post_id,
            'post_content' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'meta_input' => $release_meta,
        ];
        if ($existing_release_id > 0) {
            $release_post_data['ID'] = $existing_release_id;
            $release_post_id = wp_update_post($release_post_data);
        } else {
            $release_post_id = wp_insert_post($release_post_data);
        }
        if (is_wp_error($release_post_id)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'create_release_failed', 'message' => $release_post_id->get_error_message() ] ] ];
        }
        if (!$release_post_id) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'create_release_failed', 'message' => 'Failed to create release post.' ] ] ];
        }

        /* if (empty($data['existing_plugin'])) {
            update_post_meta($plugin_post_id, '_pblsh_latest_release_id', (int) $release_post_id);
        } */

        // Optional: cleanup temp folder later
        $this->delete_directory_recursively_with_race_protection($this->tmp_root);

        return [
            'status' => 'ok',
            'plugin_id' => (int) $plugin_post_id,
            'release_id' => (int) $release_post_id,
            'info' => [
                'existing_release_id' => $existing_release_id,
                'zip_to_replace_relative_path' => $zip_to_replace_relative_path ?? null,
                'zip_to_replace_full_path' => $zip_to_replace_full_path ?? null,
                'file_exists' => isset($zip_to_replace_full_path) ? file_exists($zip_to_replace_full_path) : null,
                'plugin_post_id' => $plugin_post_id ?? null,
            ],
            'plugin' => [
                'post_type' => 'pblsh_plugin',
                'post_status' => 'publish',
                'post_title' => $data['plugin_data']['Name'] ?? '',
                'post_name' => $plugin_slug,
            ],
        ];
    }

    /**
     * Validates an uploaded ZIP (sideloaded via /admin/upload):
     * - Creates temp working directory
     * - Stores uploaded ZIP into temp
     * - Unzips to data/ and detects plugin root
     * - Extracts plugin headers, checks Update URI and searches bootstrap code
     * - Optionally normalizes the zip filename to the main plugin file name
     * - Caches results in cache.json
     *
     * @param \WP_REST_Request $request REST request with uploaded file under key 'file'.
     * @return array Validation result { status, errors, data }.
     */
    public function process(\WP_REST_Request $request): array {
        $phase = sanitize_text_field((string) ($request->get_param('phase') ?? 'upload_prepare'));
        $settings = get_publisher_settings();

        if ($phase === 'upload_prepare') {
            $files = $request->get_file_params();
            if (empty($files['file']) || !is_array($files['file'])) {
                return [
                    'status' => 'error',
                    'errors' => [ [ 'code' => 'no_file', 'message' => 'No file uploaded.' ] ],
                ];
            }

            $built_in_browser = in_array($request->get_param('built_in_browser'), ['jszip']) ? $request->get_param('built_in_browser') : false;

            ensure_upload_dir_is_ready_and_secured();

            $upload_id = $this->init_tmp_root();
            wp_mkdir_p($this->tmp_root . 'file/');

            get_wp_filesystem();
            $overrides = [
                'test_form' => false,
                'mimes' => [ 'zip' => 'application/zip' ],
            ];
            add_filter('upload_dir', [$this, 'filter_upload_dir']);
            $uploaded = wp_handle_sideload($files['file'], $overrides);
            remove_filter('upload_dir', [$this, 'filter_upload_dir']);
            if (isset($uploaded['error'])) {
                return [
                    'status' => 'error',
                    'errors' => [ [ 'code' => 'upload_failed', 'message' => (string) $uploaded['error'] ] ],
                ];
            }

            $zip_path = $uploaded['file'];

            $cache = [
                'zip_path' => $zip_path,
                'data' => [
                    'phases' => [],
                    'original_zip' => [
                        'name' => $files['file']['name'],
                        'size' => (int) $files['file']['size'],
                        'mime_type' => (string) $files['file']['type'],
                        'built_in_browser' => $built_in_browser,
                    ],
                ],
            ];
            $cache['data']['phases'][$phase] = $this->get_time_log();
            @file_put_contents($this->tmp_root . '/cache.json', json_encode($cache, JSON_PRETTY_PRINT));
            return [ 'status' => 'ok', 'next' => 'unpack', 'upload_id' => $upload_id ];
        }

        $upload_id = sanitize_text_field((string) ($request->get_param('upload_id') ?? ''));
        if ($upload_id === '') {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'missing_upload_id', 'message' => 'Missing upload_id.' ] ] ];
        }
        $this->init_tmp_root($upload_id);
        $cache_file = $this->tmp_root . '/cache.json';
        if (!file_exists($cache_file)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'upload_not_found', 'message' => 'Upload not found.' ] ] ];
        }
        $cache = json_decode(file_get_contents($cache_file), true);
        $zip_path = (string) ($cache['zip_path'] ?? '');
        if ($zip_path === '' || !file_exists($zip_path)) {
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'zip_missing', 'message' => 'ZIP file is missing.' ] ] ];
        }

        if ($phase === 'unpack') {
            $working_dir = $this->tmp_root . 'data/';
            wp_mkdir_p($working_dir);
            get_wp_filesystem();
            $unzipped = unzip_file($zip_path, $working_dir);
            if (is_wp_error($unzipped)) {
                return [ 'status' => 'error', 'errors' => [ [ 'code' => 'unzip_failed', 'message' => $unzipped->get_error_message() ] ], 'upload_id' => $upload_id ];
            }
            $cache['data']['phases'][$phase] = $this->get_time_log();
            @file_put_contents($this->tmp_root . '/cache.json', json_encode($cache, JSON_PRETTY_PRINT));
            return [ 'status' => 'ok', 'next' => 'analyze', 'upload_id' => $upload_id ];
        }

        if ($phase === 'analyze') {
            $working_dir = $this->tmp_root . 'data/';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            // Detect root directory and main plugin file
            $root = $this->detect_root_dir($working_dir);
            $main_file = $this->find_main_plugin_file($root);

            // Measure size before cleanup
            $size_before_cleanup = $this->get_path_size($root);
            $entry_count_before_cleanup = $this->get_path_entry_count($root);

            // Add top-level folder if needed
            $has_top_level_folder = $this->has_top_level_folder($working_dir);
            $fixed_top_level_folder = !$has_top_level_folder && $main_file ? $this->add_top_level_folder($working_dir, $main_file) : false;
            if ($fixed_top_level_folder) {
                // The top-level folder was added, so we need to re-detect the root and main file
                $root = $this->detect_root_dir($working_dir);
                $main_file = $this->find_main_plugin_file($root);
            }

            // Find workspace artifacts
            $found_workspace_artifacts = $this->find_workspace_artifacts($root);
            if (!empty($settings['auto_remove_workspace_artifacts'])) {
                // Delete workspace artifacts if needed
                $found_workspace_artifacts = $this->delete_workspace_artifacts($root, $found_workspace_artifacts);
            }

            // Measure size after cleanup
            $size_after_cleanup = $this->get_path_size($root);
            $entry_count_after_cleanup = $this->get_path_entry_count($root);

            // If Publisher created the zip file in the browser itself, rename the zip file to the main file name of the plugin so that it has a meaningful name.
            $built_in_browser = (string) ($cache['data']['original_zip']['built_in_browser'] ?? '');
            if ($built_in_browser && $main_file && !$has_top_level_folder) {
                $new_zip_path = dirname($zip_path) . '/' . preg_replace('/\.php$/i', '', basename($main_file)) . '.zip';
                if ($new_zip_path !== $zip_path) {
                    get_wp_filesystem()->move($zip_path, $new_zip_path);
                    $zip_path = $new_zip_path;
                }
            }

            // Determine plugin folder name and basename
            $plugin_folder_name = $has_top_level_folder || $fixed_top_level_folder ? basename($root) : basename($zip_path, '.zip');
            $plugin_basename = $plugin_folder_name . '/' . basename($main_file);
            $plugin_slug = sanitize_title($plugin_folder_name);

            // Get plugin data
            $plugin_data = $main_file ? get_plugin_data($main_file, false, false) : [];

            // Determine if the plugin is valid
            $plugin_ok = $main_file && !empty($plugin_data['Name']);
            $version_ok = $plugin_ok && !empty($plugin_data['Version']);

            /* if (!$update_uri) {
                // Check if there is a plugin with same slug on wordpress.org
                wp_remote_get('https://api.wordpress.org/plugins/info/1.0/' . $plugin_slug . '.json');
                if ($response['response']['code'] === 200) {
                    $response_data = json_decode($response['body'], true);
                    if ($response_data['update_uri']) {
                        $update_uri = $response_data['update_uri'];
                    }
                }
            } */

            // Search for bootstrap code
            $bootstrap = $this->search_bootstrap_code($root);

            // Check if the plugin already exists
            $existing_plugin = get_posts([
                'post_type' => 'pblsh_plugin',
                'post_status' => 'any',
                'posts_per_page' => 1,
                'name' => $plugin_slug,
            ])[0] ?? null;

            // Find related releases
            $related_releases = !empty($existing_plugin->ID) && !empty($plugin_data['Version']) ? $this->find_related_releases($existing_plugin->ID, $plugin_data['Version']) : false;

            // Prepare data for the result
            $data = [
                ...$cache['data'],
                'existing_plugin' => $existing_plugin->ID ?? false,
                'result_zip' => [
                    'name' => basename($zip_path),
                    'size' => filesize($zip_path),
                    'mime_type' => mime_content_type($zip_path),
                    'rebuilt_on_server' => false,
                ],
                'plugin_ok' => $plugin_ok,
                'version_ok' => $version_ok,
                'related_releases' => $related_releases,
                'plugin_info' => [
                    'normalized_version' => normalize_version_number($plugin_data['Version'] ?? ''),
                    'release_slug' => get_release_slug($plugin_slug, $plugin_data['Version'] ?? ''),
                    'main_file' => $main_file ? $this->rel_path($main_file, $root) : false,
                    'bootstrap_file' => $bootstrap['file'] ? $this->rel_path($bootstrap['file'], $root) : false,
                    'plugin_basename' => $plugin_basename,
                    'plugin_slug' => $plugin_slug,
                    'content_hash' => $this->get_directory_content_hash($root),
                ],
                'cleanup_info' => [
                    'has_top_level_folder' => $has_top_level_folder,
                    'fixed_top_level_folder' => $fixed_top_level_folder,
                    'found_workspace_artifacts' => $found_workspace_artifacts,
                    'size_before_cleanup' => (int) $size_before_cleanup,
                    'size_after_cleanup' => (int) $size_after_cleanup,
                    'entry_count_before_cleanup' => (int) $entry_count_before_cleanup,
                    'entry_count_after_cleanup' => (int) $entry_count_after_cleanup,
                    'settings_on_upload' => array_intersect_key($settings, array_flip([
                        'auto_add_top_level_folder',
                        'auto_remove_workspace_artifacts',
                        'wordspace_artifacts_to_remove'
                    ])),
                ],
                'plugin_data' => $plugin_data,
            ];

            // Determine if the ZIP needs to be rebuilt
            $any_deleted = false;
            foreach ($found_workspace_artifacts as $entry) { if (!empty($entry['deleted'])) { $any_deleted = true; break; } }
            $rebuild_zip_needed = (bool) ($fixed_top_level_folder || $any_deleted);

            // Update cache
            $cache['zip_path'] = $zip_path;
            $cache['data'] = $data;
            $cache['data']['phases'][$phase] = $this->get_time_log();
            @file_put_contents($this->tmp_root . '/cache.json', json_encode($cache, JSON_PRETTY_PRINT));

            // Return result
            return [
                'status' => 'ok',
                'next' => $plugin_ok && $rebuild_zip_needed ? 'rebuild_zip' : 'result',
                'upload_id' => $upload_id,
            ];
        }

        if ($phase === 'rebuild_zip') {
            $working_dir = $this->tmp_root . 'data/';
            $rebuilt = $this->build_zip($working_dir, $zip_path);
            $this->delete_directory_recursively_with_race_protection($working_dir);

            if ($rebuilt) {
                $cache['data']['result_zip'] = [
                    'name' => basename($zip_path),
                    'size' => filesize($zip_path),
                    'mime_type' => mime_content_type($zip_path),
                    'rebuilt_on_server' => $rebuilt,
                ];
                $cache['data']['phases'][$phase] = $this->get_time_log();
                @file_put_contents($this->tmp_root . '/cache.json', json_encode($cache, JSON_PRETTY_PRINT));
                return [ 'status' => 'ok', 'next' => 'result', 'upload_id' => $upload_id ];
            }
            return [ 'status' => 'error', 'errors' => [ [ 'code' => 'rebuild_failed', 'message' => 'Failed to rebuild ZIP.' ] ], 'next' => 'result', 'upload_id' => $upload_id ];
        }

        if ($phase === 'result') {
            return [
                'status' => 'ok',
                'upload_id' => $upload_id,
                'data' => $cache['data'] ?? [],
            ];
        }

        return [ 'status' => 'error', 'errors' => [ [ 'code' => 'invalid_phase', 'message' => 'Invalid phase.' ] ] ];
    }

    /**
     * Registers the upload directory.
     *
     * @param string $upload_id The upload ID.
     * @return string The upload directory.
     */
    private function init_tmp_root(?string $upload_id = null): string {
        ensure_upload_dir_is_ready_and_secured();
        if ($upload_id === null) {
            $upload_id = gmdate('Ymd-His_') . wp_generate_password(8, false);
        }
        $user_id = get_current_user_id();

        $subdir = '/tmp/' . $upload_id . '_user-' . $user_id . '/';
        $publisher_upload_dir = publisher_upload_dir();
        $this->tmp_upload_dir = [
            'path' => $publisher_upload_dir['path'] . $subdir,
            'url' => $publisher_upload_dir['url'] . $subdir,
            'subdir' => '',
            'basedir' => $publisher_upload_dir['basedir'] . $subdir,
            'baseurl' => $publisher_upload_dir['baseurl'] . $subdir,
            'error' => $publisher_upload_dir['error'],
        ];
        $this->tmp_root = $this->tmp_upload_dir['path'] . '/';
        if (!file_exists($this->tmp_root)) {
            wp_mkdir_p($this->tmp_root);
        }
        return $upload_id;
    }

    /**
     * Finds related releases for a given plugin.
     *
     * @param int $plugin_post_id The ID of the plugin post.
     * @param string $version The version of the plugin.
     * @return array The related releases.
     *   - existing: The existing release (same version).
     *   - previous: The previous release (lower version).
     *   - next: The next release (higher version).
     *   - latest: The latest release (highest version).
     */
    private function find_related_releases(int $plugin_post_id, string $version): array {
        $plugin_releases = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'post_parent' => $plugin_post_id,
            'posts_per_page' => -1,
        ]);

        $version = normalize_version_number($version);

        $existing_release = false;
        $previous_release = false;
        $next_release = false;
        $latest_release = false;
        foreach ($plugin_releases as $plugin_release) {
            $plugin_release_content = json_decode($plugin_release->post_content, true);
            $plugin_release_version = $plugin_release_content['plugin_data']['Version'] ?? '';
            $plugin_release_normalized_version = normalize_version_number($plugin_release_version);

            // Find the existing release
            if ($plugin_release_normalized_version === $version) {
                $existing_release = [
                    'id' => $plugin_release->ID,
                    'version' => $plugin_release_version,
                    'normalized_version' => $plugin_release_normalized_version,
                    'plugin_basename' => $plugin_release_content['plugin_info']['plugin_basename'] ?? '',
                ];
            }
            
            // Find the previous release
            if (version_compare($plugin_release_normalized_version, $version, '<') && ($previous_release === false || version_compare($previous_release['normalized_version'], $plugin_release_normalized_version, '<'))) {
                $previous_release = [
                    'id' => $plugin_release->ID,
                    'version' => $plugin_release_version,
                    'normalized_version' => $plugin_release_normalized_version,
                    'plugin_basename' => $plugin_release_content['plugin_info']['plugin_basename'] ?? '',
                ];
            }

            // Find the next release
            if (version_compare($plugin_release_normalized_version, $version, '>') && ($next_release === false || version_compare($next_release['normalized_version'], $plugin_release_normalized_version, '>'))) {
                $next_release = [
                    'id' => $plugin_release->ID,
                    'version' => $plugin_release_version,
                    'normalized_version' => $plugin_release_normalized_version,
                    'plugin_basename' => $plugin_release_content['plugin_info']['plugin_basename'] ?? '',
                ];
            }
            
            // Find the latest release
            if ($latest_release === false || version_compare($latest_release['normalized_version'], $plugin_release_normalized_version, '<')) {
                $latest_release = [
                    'id' => $plugin_release->ID,
                    'version' => $plugin_release_version,
                    'normalized_version' => $plugin_release_normalized_version,
                    'plugin_basename' => $plugin_release_content['plugin_info']['plugin_basename'] ?? '',
                ];
            }
        }
        return [
            'existing' => $existing_release,
            'previous' => $previous_release,
            'next' => $next_release,
            'latest' => $latest_release,
        ];
    }

    /**
     * Finds unnecessary files and directories within the plugin root based on settings patterns.
     * Always runs, regardless of whether auto-deletion is enabled.
     * Adds size information and initializes a 'deleted' flag to false.
     *
     * @param string $root Plugin root directory.
     * @return array List of matches with shape: [ ['path' => 'relative/path', 'type' => 'dir'|'file', 'bytes' => int, 'deleted' => bool], ... ]
     */
    private function find_workspace_artifacts(string $root): array {
        $settings = get_publisher_settings();
        $patterns = array_values(array_filter(array_map('strval', (array) ($settings['wordspace_artifacts_to_remove'] ?? []))));
        if (empty($patterns)) {
            return [];
        }

        $nameMatches = function(string $name) use ($patterns): bool {
            foreach ($patterns as $pat) {
                if ($pat !== '' && fnmatch($pat, $name)) {
                    return true;
                }
            }
            return false;
        };

        $toDeleteDirs = [];
        $toDeleteFiles = [];

        $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($dirIt, function($current) use (&$toDeleteDirs, &$toDeleteFiles, $nameMatches) {
            $path = $current->getPathname();
            $basename = $current->getFilename();
            foreach ($toDeleteDirs as $delDir) {
                if (str_starts_with($path, $delDir)) { return false; }
            }
            if ($current->isDir()) {
                if ($nameMatches($basename)) {
                    $toDeleteDirs[] = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    return false;
                }
                return true;
            }
            if ($nameMatches($basename)) {
                $toDeleteFiles[] = $path;
            }
            return true;
        });
        $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $_) {}

        if (empty($toDeleteDirs) && empty($toDeleteFiles)) {
            return [];
        }

        usort($toDeleteDirs, fn($a, $b) => strlen($b) <=> strlen($a));

        $found = [];
        foreach (array_unique($toDeleteDirs) as $dirAbs) {
            $found[] = [
                'path' => $this->rel_path($dirAbs, $root),
                'type' => 'dir',
                'bytes' => $this->get_path_size($dirAbs),
                'count' => $this->get_path_entry_count($dirAbs),
                'deleted' => false,
            ];
        }
        foreach (array_unique($toDeleteFiles) as $fileAbs) {
            $covered = false;
            foreach ($toDeleteDirs as $dirAbs) { if (str_starts_with($fileAbs, $dirAbs)) { $covered = true; break; } }
            if ($covered) { continue; }
            $found[] = [
                'path' => $this->rel_path($fileAbs, $root),
                'type' => 'file',
                'bytes' => is_file($fileAbs) ? (@filesize($fileAbs) ?: 0) : 0,
                'count' => 1,
                'deleted' => false,
            ];
        }

        return $found;
    }

    /**
     * Deletes the provided unnecessary files and directories relative to the plugin root.
     * Only call this when auto-deletion is enabled.
     *
     * @param string $root Plugin root directory.
     * @param array $found List from find_workspace_artifacts().
     * @return array Updated list, each entry with 'deleted' toggled to true when removed
     */
    private function delete_workspace_artifacts(string $root, array $found): array {
        if (empty($found)) {
            return [];
        }

        $fs = get_wp_filesystem();
        $dirAbsList = [];
        $fileAbsList = [];
        foreach ($found as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $rel = (string) ($entry['path'] ?? '');
            if ($rel === '' || ($type !== 'dir' && $type !== 'file')) { continue; }
            if ($type === 'dir') {
                $dirAbsList[] = rtrim($root, '/\\') . '/' . rtrim($rel, '/\\') . '/';
            } else {
                $fileAbsList[] = rtrim($root, '/\\') . '/' . ltrim($rel, '/\\');
            }
        }

        usort($dirAbsList, fn($a, $b) => strlen($b) <=> strlen($a));

        $deletedMap = [];
        foreach (array_unique($dirAbsList) as $dirAbs) {
            if ($fs->exists($dirAbs)) {
                if ($fs->delete($dirAbs, true)) {
                    $deletedMap[$this->rel_path($dirAbs, $root)] = true;
                }
            }
        }
        foreach (array_unique($fileAbsList) as $fileAbs) {
            $covered = false;
            foreach ($dirAbsList as $dirAbs) { if (str_starts_with($fileAbs, $dirAbs)) { $covered = true; break; } }
            if ($covered) { continue; }
            if ($fs->exists($fileAbs)) {
                if ($fs->delete($fileAbs, false)) {
                    $deletedMap[$this->rel_path($fileAbs, $root)] = true;
                }
            }
        }

        foreach ($found as $idx => $entry) {
            $rel = (string) ($entry['path'] ?? '');
            $found[$idx]['deleted'] = isset($deletedMap[$rel]) ? true : false;
        }

        return $found;
    }

    /**
     * Calculates the byte size of a path (file or directory, recursively).
     *
     * @param string $path Absolute path to the file or directory.
     * @return int Byte size of the path.
     */
    private function get_path_size(string $path): int {
        if (is_file($path)) {
            return @filesize($path) ?: 0;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        $dirIt = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($dirIt, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            if ($entry->isFile()) {
                $size += (@filesize($entry->getPathname()) ?: 0);
            }
        }
        return $size;
    }

    /**
     * Calculates the number of entries represented by a path.
     * Files count as 1. Directories count as 1 (the directory itself) plus all contained files and directories.
     *
     * @param string $path Absolute path to the file or directory.
     * @return int Count of entries.
     */
    private function get_path_entry_count(string $path): int {
        if (is_file($path)) {
            return 1;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $count = 1; // the directory itself
        $dirIt = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($dirIt, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $count += 1; // count both files and directories
        }
        return $count;
    }

    /**
     * Calculates the content hash of a path.
     *
     * @param string $path Absolute path to the file or directory.
     * @return string Content hash of the path.
     */
    private function get_directory_content_hash(string $path): string {
        // iterate over all files and directories and calculate the hash of each relative path and for files calculate also the content hash (md5_file()). At the end, calculate the hash of all hashes and sort them.
        $hashes = [];
        $dirIt = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($dirIt, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $entry_path = $entry->getPathname();
            $relative_path = $this->rel_path($entry_path, $path);
            $hashes[] = md5($relative_path);
            if (is_file($entry_path)) {
                $hashes[] = md5_file($entry_path);
            }
        }
        sort($hashes);
        return md5(implode('', $hashes));
    }

    /**
     * Checks if the working directory has a top-level folder.
     *
     * @param string $working_dir Absolute path to the working directory.
     * @return bool True if there is exactly one top-level folder, false otherwise.
     */
    private function has_top_level_folder(string $working_dir): bool {
        $entries = glob(trailingslashit($working_dir) . '*');
        $dirs = array_values(array_filter($entries, 'is_dir'));
        $files = array_values(array_filter($entries, 'is_file'));
        return count($dirs) === 1 && count($files) === 0;
    }

    /**
     * Fixes the top-level folder by renaming the working directory to a temporary location and creating a new one with the main file name.
     *
     * @param string $working_dir Absolute path to the working directory.
     * @param string $main_file Absolute path to the main plugin file.
     * @return bool True if the top-level folder was fixed, false otherwise.
     */
    private function add_top_level_folder(string $working_dir, string $main_file): bool {
        $settings = get_publisher_settings();
        if (empty($settings['auto_add_top_level_folder'])) {
            return false;
        }
        if (!$main_file) {
            return false;
        }
        if ($this->has_top_level_folder($working_dir)) {
            return false;
        }
        $tmp_root = $this->tmp_root . 'data_old/';
        get_wp_filesystem()->move($working_dir, $tmp_root);
        wp_mkdir_p($working_dir);
        $new_root = trailingslashit($working_dir . preg_replace('/\.php$/i', '', basename($main_file)));
        get_wp_filesystem()->move($tmp_root, $new_root);
        return true;
    }

    /**
     * Detects the plugin root directory inside the unzipped data.
     * If there is exactly one top-level directory, returns that; otherwise the working dir.
     *
     * @param string $working_dir Absolute path to the unzipped data directory.
     * @return string Absolute path (with trailing slash) to the detected root.
     */
    private function detect_root_dir(string $working_dir): string {
        $entries = glob(trailingslashit($working_dir) . '*');
        $dirs = array_values(array_filter($entries, 'is_dir'));
        $files = array_values(array_filter($entries, 'is_file'));
        if (count($dirs) === 1 && count($files) === 0) {
            return trailingslashit($dirs[0]);
        }
        return trailingslashit($working_dir);
    }

    /**
     * Finds the main plugin PHP file (with valid headers) within a shallow search.
     *
     * @param string $root Absolute path to plugin root.
     * @return string|null Absolute path to main file or null if not found.
     */
    private function find_main_plugin_file(string $root): ?string {
        $candidates = $this->list_php_files($root, 0);
        foreach ($candidates as $file) {
            $data = get_plugin_data($file, false, false);
            if (!empty($data['Name'])) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Lists PHP files up to a maximum directory depth.
     *
     * @param string $dir Start directory.
     * @param int $max_depth Maximum depth (0 = only dir itself).
     * @return array Absolute file paths.
     */
    private function list_php_files(string $dir, int $max_depth = 2): array {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $depth = $it->getDepth();
            if ($depth > $max_depth) continue;
            if (is_file($file) && substr($file->getFilename(), -4) === '.php') {
                $files[] = (string) $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * Searches for expected bootstrap/update-related code patterns in PHP files.
     * Returns ['found'=>bool, 'file'=>string].
     *
     * @param string $root Plugin root directory.
     * @return array Array with 'found' boolean and 'file' string.
     */
    private function search_bootstrap_code(string $root): array {
        $files = $this->list_php_files($root, 5);
        $bootstrap_code = get_bootstrap_code();
        $minified = preg_replace('/\s+/', '', preg_replace('/\/\*.*?\*\//s', '', $bootstrap_code));
        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) continue;
            $minified_contents = preg_replace('/\s+/', '', $contents);
            if (strpos($minified_contents, $minified) !== false) return ['found' => true, 'file' => $file];
        }
        return ['found' => false, 'file' => ''];
    }

    /**
     * Converts an absolute file path into a path relative to a given root.
     *
     * @param string $file Absolute file path.
     * @param string $root Root directory to relativize against.
     * @return string Relative path or original if outside root.
     */
    private function rel_path(string $file, string $root): string {
        $root = rtrim($root, '/\\') . '/';
        if (strpos($file, $root) === 0) {
            return substr($file, strlen($root));
        }
        return $file;
    }

    /**
     * Discards a pending upload by removing its temp directory recursively.
     *
     * @param \WP_REST_Request $request Contains 'upload_id'.
     * @return array { status: 'ok'|'error', message? }
     */
    public function discard_upload(\WP_REST_Request $request): array {
        $upload_id = sanitize_text_field((string) ($request->get_param('upload_id') ?? ''));
        if ($upload_id === '') {
            return [ 'status' => 'error', 'message' => 'Missing upload_id.' ];
        }
        $this->init_tmp_root($upload_id);
        if (!is_dir($this->tmp_root)) {
            return [ 'status' => 'ok' ];
        }
        $this->delete_directory_recursively_with_race_protection($this->tmp_root);
        return [ 'status' => 'ok' ];
    }

    /**
     * Creates a new zip file with the plugin root directory.
     *
     * @param string $root Plugin root directory.
     * @param string $zip_path Original zip file path.
     * @return string Absolute path to the new zip file.
     */
    private function build_zip(string $root, string $zip_path): string {
        // Prepare destination directory and target path
        $zip_new_dir = $this->tmp_root . 'file_new/';
        wp_mkdir_p($zip_new_dir);
        $zip_new_path = $zip_new_dir . basename($zip_path);

        // Build file list once (used by ZipArchive and PclZip paths)
        $files = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        $created = false;
        $generated_with = '';

        // Primary: use ZipArchive if available
        if (class_exists('\\ZipArchive')) {
            $zip = new \ZipArchive();
            $openResult = $zip->open($zip_new_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($openResult === true) {
                foreach ($files as $abs) {
                    $local = substr($abs, strlen($root));
                    $zip->addFile($abs, $local);
                }
                $zip->close();
                $created = file_exists($zip_new_path);
                $generated_with = 'ziparchive';
            }
        }

        // Fallback: use WordPress bundled PclZip if ZipArchive is unavailable or failed
        if (!$created) {
            if (!class_exists('\\PclZip')) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            if (class_exists('\\PclZip')) {
                $pcl = new \PclZip($zip_new_path);
                // Remove the absolute $root prefix so entries are stored relative to it
                $res = $pcl->create($files, PCLZIP_OPT_REMOVE_PATH, rtrim($root, '/\\') . '/');
                $created = ($res !== 0);
                $generated_with = 'pclzip';
            }
        }

        // Abort gracefully if archive creation failed in both strategies
        if (!$created) {
            $this->delete_directory_recursively_with_race_protection($zip_new_dir);
            return false;
        }

        // Replace original archive directory with the newly created one
        $this->delete_directory_recursively_with_race_protection(dirname($zip_path));
        return get_wp_filesystem()->move($zip_new_dir, dirname($zip_path)) ? $generated_with : false;
    }

    /**
     * Deletes a directory recursively with race protection.
     *
     * @param string $path Absolute path to the directory to delete.
     * @return void
     */
    private function delete_directory_recursively_with_race_protection(string $path): void {
        // Rename before deletion to prevent race conditions
        $new_path = trailingslashit(dirname($path)) . basename($path) . '_deleted-' . time() . '-' . wp_generate_password(10, false) . '/';
        if (get_wp_filesystem()->move($path, $new_path)) {
            get_wp_filesystem()->delete($new_path, true);
        }
    }

    /**
     * Gets the time log.
     *
     * @return array The time log.
     */
    private function get_time_log(): array {
        $time_end = microtime(true);
        return [
            'time_start' => $this->time_start,
            'time_end' => $time_end,
            'duration' => $time_end - $this->time_start,
        ];
    }
}



