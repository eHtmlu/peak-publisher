<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class AdminAPI {
    private static $instance = null;

    const NAMESPACE = 'pblsh-admin/v1';

    private ?AssetManager $asset_manager = null;

    /**
     * Constructor.
     */
    private function __construct() {
        $this->register_routes();
    }

    /**
     * Lazy-load the AssetManager singleton.
     */
    private function assets(): AssetManager {
        if ($this->asset_manager === null) {
            require_once __DIR__ . '/AssetManager.php';
            $this->asset_manager = AssetManager::init();
        }
        return $this->asset_manager;
    }

    /**
     * Initialize the admin API class.
     */
    public static function init(): self {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugins'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/releases', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugin_releases'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        
        
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/releases/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_release'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/releases/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_release'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/releases/(?P<id>\d+)/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_release'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/get-bootstrap-code', [
            'methods' => 'GET',
            'callback' => [$this, 'get_bootstrap_code'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/upload', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_process'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/upload/finalize', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_finalize'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/upload/discard', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_discard'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_peak_publisher_settings_rest'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'save_peak_publisher_settings_rest'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/svn/test-credentials', [
            'methods' => 'POST',
            'callback' => [$this, 'test_svn_credentials'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/wporg/lookup-plugin', [
            'methods' => 'POST',
            'callback' => [$this, 'lookup_wporg_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/wporg/discover-plugins', [
            'methods' => 'POST',
            'callback' => [$this, 'discover_wporg_plugins'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/wporg/import-plugins', [
            'methods' => 'POST',
            'callback' => [$this, 'import_wporg_plugins'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Plugin assets
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_assets'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_upload_asset'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets', [
            'methods' => 'DELETE',
            'callback' => [$this, 'handle_delete_asset'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets/move', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_move_asset'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Check permission.
     */
    public function check_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get plugins.
     */
    public function get_plugins(): array {
        $plugins = get_posts([
            'post_type' => PBLSH_PLUGIN_POST_TYPES,
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);
        $plugin_ids = array_map('intval', wp_list_pluck($plugins, 'ID'));
        $releases_by_parent = fetch_releases_grouped_by_parent($plugin_ids);

        $out = [];
        foreach ($plugins as $plugin_post) {
            $out[] = $this->serialize_plugin_post($plugin_post, false, $releases_by_parent[(int) $plugin_post->ID] ?? []);
        }
        return $out;
    }

    /**
     * Get plugin.
     */
    public function get_plugin(\WP_REST_Request $request): array|\WP_Error {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!is_plugin_post($post)) {
            return [];
        }

        if (is_wporg_plugin($post)) {
            $refresh_error = $this->refresh_wporg_plugin_cache($post);
            if ($refresh_error instanceof \WP_Error) {
                return $refresh_error;
            }
            $post = get_post($id);
            if (!is_plugin_post($post)) {
                return [];
            }
        }

        $releases_by_parent = fetch_releases_grouped_by_parent([(int) $post->ID]);
        return $this->serialize_plugin_post($post, true, $releases_by_parent[(int) $post->ID] ?? []);
    }

    /**
     * Serialize a plugin post for admin REST responses.
     *
     * @param \WP_Post[] $releases
     */
    private function serialize_plugin_post(\WP_Post $post, bool $detail, array $releases = []): array {
        $hosting_type = get_plugin_hosting_type($post);
        [$latest_version, $count_of_releases] = $this->derive_release_info($releases);
        $is_self_hosted = $hosting_type === 'self_hosted';

        return [
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'hosting_type' => $hosting_type,
            'icon_url' => $is_self_hosted ? $this->assets()->get_best_icon_url($post->post_name) : null,
            'version' => $latest_version,
            'status' => $post->post_status,
            'count_of_releases' => $count_of_releases,
            'installations_count' => $is_self_hosted ? get_plugin_installations_count((int) $post->ID) : 0,
        ];
    }

    /**
     * Derive the latest version and release count from release posts.
     *
     * @param \WP_Post[] $releases
     * @return array{0:string,1:int}
     */
    private function derive_release_info(array $releases): array {
        $latest_version = '';
        $latest_normalized = '';

        foreach ($releases as $release) {
            if (!$release instanceof \WP_Post) {
                continue;
            }

            $rel_data = json_decode((string) ($release->post_content ?? ''), true);
            if (!is_array($rel_data)) {
                $rel_data = [];
            }

            $version = (string) (($release->post_title ?? '') !== '' ? $release->post_title : ($rel_data['plugin_data']['Version'] ?? ''));
            $normalized = (string) ($rel_data['plugin_info']['normalized_version'] ?? '');
            if ($normalized === '' && $version !== '') {
                $normalized = normalize_version_number($version);
            }
            if ($normalized === '') {
                continue;
            }

            if ($latest_normalized === '' || version_compare($normalized, $latest_normalized, '>')) {
                $latest_normalized = $normalized;
                $latest_version = $version;
            }
        }

        return [$latest_version, count($releases)];
    }

    /**
     * Get releases list for a plugin.
     */
    public function get_plugin_releases(\WP_REST_Request $request): array|\WP_Error {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!is_plugin_post($post)) {
            return [];
        }
        if (is_wporg_plugin($post)) {
            $refresh_error = $this->refresh_wporg_plugin_cache($post);
            if ($refresh_error instanceof \WP_Error) {
                return $refresh_error;
            }
            $post = get_post($id);
            if (!is_plugin_post($post)) {
                return [];
            }
        }
        $is_wporg = is_wporg_plugin($post);

        $releases_query = new \WP_Query([
            'post_type' => 'pblsh_release',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_parent' => $post->ID,
        ]);

        $releases = [];
        foreach ($releases_query->posts as $release) {
            $rel_data = json_decode((string) $release->post_content, true) ?? [];
            $normalized = (string) ($rel_data['plugin_info']['normalized_version'] ?? '');
            $version = (string) (($release->post_title ?? '') !== '' ? $release->post_title : ($rel_data['plugin_data']['Version'] ?? ''));
            $releases[] = [
                'id' => $release->ID,
                'version' => $version,
                'status' => $release->post_status,
                'date' => $release->post_date,
                'download_url' => $is_wporg ? '' : rest_url(self::NAMESPACE . '/releases/' . $release->ID . '/download'),
                'installations_count' => (!$is_wporg && $normalized !== '') ? get_plugin_installations_count_by_version((int) $post->ID, $normalized) : 0,
            ];
        }

        // order releases by version (descending)
        usort($releases, function($a, $b) {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        return $releases;
    }

    private function refresh_wporg_plugin_cache(\WP_Post $post): ?\WP_Error {
        try {
            get_wporg_plugin_data($post);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'wporg_cache_refresh_failed',
                $e->getMessage() ?: __('Could not refresh wordpress.org SVN cache.', 'peak-publisher'),
                [ 'status' => 502 ]
            );
        }

        return null;
    }

    /**
     * Delete a release.
     */
    public function delete_release(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $release = get_post($id);
        if (!$release || $release->post_type !== 'pblsh_release') {
            return [ 'status' => 'error', 'message' => 'Release not found.' ];
        }
        $parent = get_post((int) $release->post_parent);
        if (is_wporg_plugin($parent)) {
            return [
                'status' => 'error',
                'code' => 'wporg_release_delete_unsupported',
                'message' => __('wporg tag deletion is not available in this slice.', 'peak-publisher'),
            ];
        }

        $zip_rel = (string) get_post_meta($release->ID, '_pblsh_zip_path', true);
        if ($zip_rel !== '') {
            $zip_abs = trailingslashit(peak_publisher_upload_basedir()) . ltrim($zip_rel, '/\\');
            if (file_exists($zip_abs)) {
                if (get_wp_filesystem()) {
                    get_wp_filesystem()->delete($zip_abs, false);
                } else {
                    wp_delete_file($zip_abs);
                }
            }
        }

        // Remove all empty folders from the upload directory
        remove_empty_folders(peak_publisher_upload_basedir());

        wp_delete_post($release->ID, true);
        return [ 'status' => 'ok' ];
    }

    /**
     * Streams a release ZIP through WordPress to bypass web-server access limits.
     */
    public function download_release(\WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $release = get_post($id);
        if (!$release || $release->post_type !== 'pblsh_release') {
            return new \WP_Error('not_found', 'Release not found', ['status' => 404]);
        }
        $parent = get_post((int) $release->post_parent);
        if (is_wporg_plugin($parent)) {
            return new \WP_Error('unsupported_hosting_type', 'Release downloads for wordpress.org plugins are not available here.', ['status' => 404]);
        }

        $zip_rel = (string) get_post_meta($release->ID, '_pblsh_zip_path', true);
        if ($zip_rel === '') {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }
        $zip_abs = trailingslashit(peak_publisher_upload_basedir()) . ltrim($zip_rel, '/\\');
        if (!file_exists($zip_abs) || !is_readable($zip_abs)) {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }

        $wp_filesystem = get_wp_filesystem();
        $data = $wp_filesystem->get_contents($zip_abs);
        if ($data === false) {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }

        $filename = basename($zip_abs);
        nocache_headers();
        $filename = sanitize_file_name($filename);
        header('X-Content-Type-Options: nosniff');
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . (string) filesize($zip_abs));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file output
        echo $data;
        exit;
    }

    

    /**
     * Update plugin.
     */
    public function update_plugin(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return [ 'status' => 'error', 'message' => 'Plugin not found.' ];
        }
        $params = $request->get_json_params();
        $status = isset($params['status']) ? (string) $params['status'] : '';
        if ($status !== 'publish' && $status !== 'draft') {
            return [ 'status' => 'error', 'message' => 'Invalid status.' ];
        }
        $res = wp_update_post([
            'ID' => $post->ID,
            'post_status' => $status,
        ], true);
        if (is_wp_error($res)) {
            return [ 'status' => 'error', 'message' => $res->get_error_message() ];
        }
        return [ 'status' => 'ok', 'id' => $post->ID, 'new_status' => $status ];
    }

    /**
     * Delete plugin.
     */
    public function delete_plugin(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $plugin = get_post($id);
        if (!is_plugin_post($plugin)) {
            return [ 'status' => 'error', 'message' => 'Plugin not found.' ];
        }

        if (is_wporg_plugin($plugin)) {
            return $this->delete_wporg_plugin_mirror($plugin);
        }

        // Delete all releases including their ZIP files
        $releases = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'post_parent' => $plugin->ID,
            'fields' => 'ids',
        ]);

        foreach ($releases as $release_id) {
            $zip_rel = (string) get_post_meta($release_id, '_pblsh_zip_path', true);
            if ($zip_rel !== '') {
                $zip_abs = trailingslashit(peak_publisher_upload_basedir()) . ltrim($zip_rel, '/\\');
                if (file_exists($zip_abs)) {
                    if (get_wp_filesystem()) {
                        get_wp_filesystem()->delete($zip_abs, false);
                    } else {
                        wp_delete_file($zip_abs);
                    }
                }
            }
            wp_delete_post($release_id, true);
        }

        // Delete the plugin's assets directory.
        $assets_dir = get_plugin_assets_basedir($plugin->post_name);
        if (is_dir($assets_dir)) {
            get_wp_filesystem()->delete(trailingslashit($assets_dir), true);
        }

        // Remove all empty folders from the upload directory
        remove_empty_folders(peak_publisher_upload_basedir());

        // Delete the plugin post itself
        wp_delete_post($plugin->ID, true);
        return [ 'status' => 'ok' ];
    }

    private function delete_wporg_plugin_mirror(\WP_Post $plugin): array {
        $release_ids = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'post_parent' => (int) $plugin->ID,
            'fields' => 'ids',
        ]);

        foreach ($release_ids as $release_id) {
            wp_delete_post((int) $release_id, true);
        }

        wp_delete_post((int) $plugin->ID, true);

        return [
            'status' => 'ok',
            'removed_local_mirror' => true,
            'deleted_releases' => count($release_ids),
        ];
    }

    /**
     * Update a release.
     */
    public function update_release(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $release = get_post($id);
        if (!$release || $release->post_type !== 'pblsh_release') {
            return [ 'status' => 'error', 'message' => 'Release not found.' ];
        }
        $parent = get_post((int) $release->post_parent);
        if (is_wporg_plugin($parent)) {
            return [
                'status' => 'error',
                'code' => 'wporg_release_immutable',
                'message' => __('wporg releases are SVN tags and cannot be drafted.', 'peak-publisher'),
            ];
        }
        $params = $request->get_json_params();
        $status = isset($params['status']) ? (string) $params['status'] : '';
        if ($status !== 'publish' && $status !== 'draft') {
            return [ 'status' => 'error', 'message' => 'Invalid status.' ];
        }
        $res = wp_update_post([
            'ID' => $release->ID,
            'post_status' => $status,
        ], true);
        if (is_wp_error($res)) {
            return [ 'status' => 'error', 'message' => $res->get_error_message() ];
        }
        return [ 'status' => 'ok', 'id' => $release->ID, 'new_status' => $status ];
    }

    /**
     * Get code to embed.
     */
    public function get_bootstrap_code(): array {
        return [
            'code' => get_bootstrap_code(),
        ];
    }

    public function upload_process(\WP_REST_Request $request): array {
        require_once __DIR__ . '/UploadWorkflow.php';
        $workflow = new UploadWorkflow();
        return $workflow->process($request);
    }

    public function upload_finalize(\WP_REST_Request $request): array {
        require_once __DIR__ . '/UploadWorkflow.php';
        $workflow = new UploadWorkflow();
        return $workflow->finalize($request);
    }

    public function upload_discard(\WP_REST_Request $request): array {
        require_once __DIR__ . '/UploadWorkflow.php';
        $workflow = new UploadWorkflow();
        return $workflow->discard_upload($request);
    }

    /**
     * Get all assets for a plugin.
     */
    public function handle_get_assets(\WP_REST_Request $request): array {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return ['status' => 'error', 'message' => 'Plugin not found.'];
        }
        $result  = $this->assets()->get_all($post->post_name);
        $result['screenshot_captions'] = $this->get_screenshot_captions($id);
        return $result;
    }

    /**
     * Get screenshot captions from the latest published release's readme.txt.
     *
     * @return object Screenshot captions keyed by number, e.g. {1: "Caption", 2: "Caption"}.
     */
    private function get_screenshot_captions(int $plugin_id): object {
        $latest = get_posts([
            'post_type'      => 'pblsh_release',
            'post_status'    => 'publish',
            'post_parent'    => $plugin_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if (empty($latest)) {
            return (object) [];
        }
        $content = json_decode((string) $latest[0]->post_content, true);
        $screenshots = $content['plugin_readme_txt']['content']['screenshots'] ?? [];
        if (empty($screenshots) || !is_array($screenshots)) {
            return (object) [];
        }
        // Ensure keys are integers and values are strings.
        $captions = [];
        foreach ($screenshots as $n => $caption) {
            $captions[(int) $n] = (string) $caption;
        }
        return (object) $captions;
    }

    /**
     * Upload an asset file to a plugin slot.
     * Expects multipart/form-data with: file (binary), slot (string), screenshot_n (int, optional).
     */
    public function handle_upload_asset(\WP_REST_Request $request): array {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return ['status' => 'error', 'message' => 'Plugin not found.'];
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
        if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err_code = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
            return ['status' => 'error', 'message' => 'No file uploaded (error code ' . $err_code . ').'];
        }

        $slot         = sanitize_key((string) ($request->get_param('slot') ?? ''));
        $screenshot_n_raw = $request->get_param('screenshot_n');
        $screenshot_n = $screenshot_n_raw !== null && $screenshot_n_raw !== '' ? (int) $screenshot_n_raw : null;

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed to AssetManager which validates it.
        $file_data = $_FILES['file'];
        $result = $this->assets()->upload($id, $post->post_name, $slot, $screenshot_n, $file_data);

        // Calculate banner average color for geopattern fallback icons.
        // Based on WordPress.org Plugin Directory.
        if ( in_array( $slot, [ 'banner_sd', 'banner_hd' ], true ) && ( $result['status'] ?? '' ) !== 'error' ) {
            $this->update_banner_color( $id, $post->post_name );
        }

        return $result;
    }

    /**
     * Delete an asset from a plugin slot.
     * Expects JSON body: { slot: string, screenshot_n?: int }.
     */
    public function handle_delete_asset(\WP_REST_Request $request): array {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return ['status' => 'error', 'message' => 'Plugin not found.'];
        }

        $params       = $request->get_json_params();
        $slot         = sanitize_key((string) ($params['slot'] ?? ''));
        $screenshot_n_raw = $params['screenshot_n'] ?? null;
        $screenshot_n = $screenshot_n_raw !== null ? (int) $screenshot_n_raw : null;

        $deleted = $this->assets()->delete($id, $post->post_name, $slot, $screenshot_n);

        // Recalculate banner average color for geopattern fallback icons.
        // Based on WordPress.org Plugin Directory.
        if ( in_array( $slot, [ 'banner_sd', 'banner_hd' ], true ) ) {
            $this->update_banner_color( $id, $post->post_name );
        }

        $assets  = $this->assets()->get_all($post->post_name);
        $assets['screenshot_captions'] = $this->get_screenshot_captions($id);
        return ['status' => 'ok', 'deleted' => $deleted, 'assets' => $assets];
    }

    /**
     * Move a screenshot from one position to another.
     * Expects JSON body: { slot: "screenshot", from: int, to: int }.
     */
    public function handle_move_asset(\WP_REST_Request $request): array {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return ['status' => 'error', 'message' => 'Plugin not found.'];
        }

        $params = $request->get_json_params();
        $from   = isset($params['from']) ? (int) $params['from'] : 0;
        $to     = isset($params['to'])   ? (int) $params['to']   : 0;

        $result = $this->assets()->move_screenshot($id, $post->post_name, $from, $to);
        if ($result['status'] === 'error') {
            return $result;
        }

        $assets = $this->assets()->get_all($post->post_name);
        $assets['screenshot_captions'] = $this->get_screenshot_captions($id);
        return ['status' => 'ok', 'assets' => $assets];
    }

    /**
     * Recalculate and store the banner average color for geopattern fallback icons.
     *
     * Based on WordPress.org Plugin Directory.
     * @see https://github.com/WordPress/wordpress.org — class-tools.php
     */
    private function update_banner_color( int $plugin_id, string $plugin_slug ): void {
        $banner_average_color = '';

        // Find the first available banner file (prefer HD, then SD) via asset meta.
        foreach ( [ 'banner_hd', 'banner_sd' ] as $slot ) {
            $info = $this->assets()->find_file_in_slot( $plugin_slug, $slot );
            if ( $info !== null ) {
                $filepath = trailingslashit( get_plugin_assets_basedir( $plugin_slug ) ) . $info['filename'];
                if ( file_exists( $filepath ) ) {
                    $banner_average_color = get_image_average_color( $filepath );
                    if ( ! is_string( $banner_average_color ) ) {
                        $banner_average_color = '';
                    }
                }
                break;
            }
        }

        if ( $banner_average_color !== '' ) {
            update_post_meta( $plugin_id, 'assets_banners_color', wp_slash( $banner_average_color ) );
        } else {
            delete_post_meta( $plugin_id, 'assets_banners_color' );
        }
    }

    public function get_peak_publisher_settings_rest(): array {
        return get_peak_publisher_settings();
    }

    public function save_peak_publisher_settings_rest(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = []; }
        $result = update_peak_publisher_settings($params);
        if (is_wp_error($result)) {
            return $this->rest_error_response($result);
        }
        if ($result !== true) {
            return $this->rest_error_response(new \WP_Error(
                'settings_save_failed',
                __('Settings could not be saved.', 'peak-publisher'),
                [ 'status' => 500 ]
            ));
        }
        return get_peak_publisher_settings();
    }

    public function test_svn_credentials(\WP_REST_Request $request) {
        // Accept only JSON object bodies; malformed requests fall back to empty input.
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        // Normalize the username exactly like the settings save pipeline does.
        $username = normalize_wporg_username($params['username'] ?? null);
        if (is_wp_error($username)) {
            return $this->rest_error_response($username);
        }

        // Preserve password bytes except for requiring an actual string value.
        $password = wporg_string_from_value($params['password'] ?? '');
        if ($password === '') {
            return $this->rest_error_response($this->make_rest_error(
                'invalid_credentials',
                __('Invalid wordpress.org username or password.', 'peak-publisher'),
                401
            ));
        }

        // A masked password means the user is testing the stored credential.
        if ($password === WPORG_PASSWORD_MASKED) {
            $credentials = get_wporg_credentials($username);
            if (is_wp_error($credentials)) {
                return $this->rest_error_response($credentials);
            }
            // Missing stored credentials should behave like an authentication failure.
            if ($credentials === null) {
                return $this->rest_error_response($this->make_rest_error(
                    'invalid_credentials',
                    __('Invalid wordpress.org username or password.', 'peak-publisher'),
                    401
                ));
            }
            $password = $credentials['password'];
        } else {
            // Keep testing aligned with saving: credentials are only accepted when they can be stored securely.
            $key = get_encryption_key();
            if (is_wp_error($key)) {
                return $this->rest_error_response($key);
            }
        }

        // Load the wordpress.org plugin SVN client only for the endpoint that needs it.
        require_once __DIR__ . '/WporgPluginSvnClient.php';
        try {
            // PROPFIND against the repository root verifies Basic Auth without checking plugin permissions.
            $client = new WporgPluginSvnClient($username, $password);
            $client->test_credentials();
            return [ 'status' => 'ok' ];
        } catch (WporgPluginSvnClientException $e) {
            // Known SVN failures keep their normalized error code and HTTP status.
            return $this->rest_error_response($this->make_rest_error(
                $e->get_error_code(),
                $e->getMessage(),
                $e->get_http_status()
            ));
        } catch (\RuntimeException $e) {
            // Unexpected runtime failures are hidden behind a generic SVN auth error.
            return $this->rest_error_response($this->make_rest_error(
                'svn_auth_check_failed',
                __('wordpress.org SVN returned an unexpected authentication response.', 'peak-publisher'),
                502
            ));
        }
    }

    public function discover_wporg_plugins(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $username = normalize_wporg_username($params['username'] ?? null, 'username');
        if (is_wp_error($username)) {
            return $this->rest_error_response($username);
        }

        if (!$this->wporg_account_is_configured($username)) {
            return $this->rest_error_response($this->make_rest_error(
                'account_not_configured',
                __('Account not configured.', 'peak-publisher'),
                400,
                'username'
            ));
        }

        require_once __DIR__ . '/SvnDeployWorkflow.php';
        try {
            $plugins = SvnDeployWorkflow::discover_plugins_by_author($username);
        } catch (\Throwable $e) {
            return $this->rest_error_response($this->make_rest_error(
                'wporg_api_unavailable',
                __('wordpress.org API unavailable, try again later.', 'peak-publisher'),
                503
            ));
        }

        $slugs = array_values(array_unique(array_filter(array_map(
            static fn($plugin) => is_array($plugin) ? (string) ($plugin['slug'] ?? '') : '',
            $plugins
        ))));
        $already_imported_by_slug = [];
        if (!empty($slugs)) {
            $existing_posts = get_posts([
                'post_type' => 'pblsh_wporg_plugin',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'post_name__in' => $slugs,
            ]);
            foreach ($existing_posts as $existing_post) {
                if ($existing_post instanceof \WP_Post) {
                    $already_imported_by_slug[(string) $existing_post->post_name] = (int) $existing_post->ID;
                }
            }
        }

        $out = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'name' => (string) ($plugin['name'] ?? $slug),
                'already_imported' => isset($already_imported_by_slug[$slug]),
                'existing_plugin_id' => $already_imported_by_slug[$slug] ?? null,
                'has_write_access' => null,
                'access_status' => 'pending',
            ];
        }

        return [
            'status' => 'ok',
            'username' => $username,
            'plugins' => $out,
        ];
    }

    public function lookup_wporg_plugin(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $username = normalize_wporg_username($params['username'] ?? null, 'username');
        if (is_wp_error($username)) {
            return $this->rest_error_response($username);
        }

        $slug = normalize_wporg_slug($params['slug'] ?? null, 'slug');
        if (is_wp_error($slug)) {
            return $this->rest_error_response($slug);
        }

        $credentials = get_wporg_credentials($username);
        if (is_wp_error($credentials)) {
            return $this->rest_error_response($credentials);
        }
        if ($credentials === null) {
            return $this->rest_error_response($this->make_rest_error(
                'account_not_configured',
                __('Account not configured.', 'peak-publisher'),
                400,
                'username'
            ));
        }

        $existing = get_posts([
            'post_type' => 'pblsh_wporg_plugin',
            'post_status' => 'any',
            'name' => $slug,
            'posts_per_page' => 1,
        ]);
        $existing_post = !empty($existing) && $existing[0] instanceof \WP_Post ? $existing[0] : null;

        require_once __DIR__ . '/WporgPluginSvnClient.php';
        $client = new WporgPluginSvnClient($username, $credentials['password']);
        $access = $client->can_write($slug);
        $access_status = (string) ($access['status'] ?? 'error');
        if (!in_array($access_status, ['ok', 'no_write_access', 'not_found', 'error'], true)) {
            $access_status = 'error';
        }

        return [
            'status' => 'ok',
            'plugin' => [
                'slug' => $slug,
                'name' => null,
                'already_imported' => $existing_post instanceof \WP_Post,
                'existing_plugin_id' => $existing_post instanceof \WP_Post ? (int) $existing_post->ID : null,
                'has_write_access' => $access_status === 'ok' && !empty($access['has_write_access']),
                'access_status' => $access_status,
                'message' => isset($access['message']) && is_string($access['message']) ? $access['message'] : null,
            ],
        ];
    }

    public function import_wporg_plugins(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $decoded_body = json_decode((string) $request->get_body());
        if (!$decoded_body instanceof \stdClass || !is_array($params)) {
            return $this->rest_error_response($this->make_rest_error(
                'invalid_request',
                __('Expected a JSON object request body.', 'peak-publisher'),
                400
            ));
        }

        $username = normalize_wporg_username($params['username'] ?? null, 'username');
        if (is_wp_error($username)) {
            return $this->rest_error_response($username);
        }

        $credentials = get_wporg_credentials($username);
        if (is_wp_error($credentials)) {
            return $this->rest_error_response($credentials);
        }
        if ($credentials === null) {
            return $this->rest_error_response($this->make_rest_error(
                'account_not_configured',
                __('Account not configured.', 'peak-publisher'),
                400,
                'username'
            ));
        }

        $slugs = $this->validate_wporg_import_slugs($params);
        if (is_wp_error($slugs)) {
            return $this->rest_error_response($slugs);
        }

        require_once __DIR__ . '/WporgPluginSvnClient.php';
        if (!WporgPluginSvnClient::is_batch_transport_available()) {
            return $this->rest_error_response($this->make_rest_error(
                'wporg_import_transport_unavailable',
                __('wordpress.org import needs curl_multi_exec support.', 'peak-publisher'),
                500
            ));
        }

        $client = new WporgPluginSvnClient($username, $credentials['password']);
        $imported = [];
        $skipped = [];

        foreach ($slugs as $slug) {
            $existing = wporg_get_plugin_marker_by_slug($slug);
            if ($existing instanceof \WP_Post) {
                $skipped[] = $this->wporg_import_skip(
                    $slug,
                    'already_imported',
                    __('Plugin already imported.', 'peak-publisher'),
                    (int) $existing->ID
                );
                continue;
            }

            try {
                $access = $client->can_write($slug);
            } catch (\Throwable $e) {
                $skipped[] = $this->wporg_import_skip($slug, 'access_check_failed');
                continue;
            }

            $access_status = (string) ($access['status'] ?? 'error');
            $access_message = isset($access['message']) && is_string($access['message']) ? $access['message'] : null;
            if ($access_status === 'no_write_access') {
                $skipped[] = $this->wporg_import_skip($slug, 'no_write_access', $access_message);
                continue;
            }
            if ($access_status === 'not_found') {
                $skipped[] = $this->wporg_import_skip($slug, 'not_found', $access_message);
                continue;
            }
            if ($access_status !== 'ok' || empty($access['has_write_access'])) {
                $skipped[] = $this->wporg_import_skip($slug, 'access_check_failed', $access_message);
                continue;
            }

            $bundle = fetch_wporg_import_cache_bundle($slug);
            if (is_wp_error($bundle)) {
                $skipped[] = $this->wporg_import_skip_from_error($slug, $bundle);
                continue;
            }

            $plugin_id = persist_wporg_import_cache_bundle($slug, $username, $bundle);
            if (is_wp_error($plugin_id)) {
                $skipped[] = $this->wporg_import_skip_from_error($slug, $plugin_id);
                continue;
            }

            $imported[] = $this->serialize_imported_wporg_plugin((int) $plugin_id, $slug);
        }

        return [
            'status' => 'ok',
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    private function validate_wporg_import_slugs(array $params) {
        if (!array_key_exists('slugs', $params) || !is_array($params['slugs']) || empty($params['slugs']) || !array_is_list($params['slugs'])) {
            return $this->make_rest_error(
                'invalid_slugs',
                __('Select at least one wordpress.org plugin slug.', 'peak-publisher'),
                400,
                'slugs'
            );
        }

        if (count($params['slugs']) > PBLSH_WPORG_IMPORT_CHUNK_SIZE) {
            return $this->make_rest_error(
                'too_many_slugs',
                sprintf(
                    __('Import at most %d plugins per request.', 'peak-publisher'),
                    PBLSH_WPORG_IMPORT_CHUNK_SIZE
                ),
                400,
                'slugs'
            );
        }

        $slugs = [];
        foreach ($params['slugs'] as $index => $raw_slug) {
            $slug = normalize_wporg_slug($raw_slug, 'slugs.' . $index);
            if (is_wp_error($slug)) {
                return $slug;
            }
            $slugs[] = $slug;
        }

        return array_values(array_unique($slugs));
    }

    private function wporg_import_skip(string $slug, string $reason, ?string $message = null, ?int $existing_plugin_id = null): array {
        $default_messages = [
            'already_imported' => __('Plugin already imported.', 'peak-publisher'),
            'no_write_access' => __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher'),
            'not_found' => __('Plugin not found on wordpress.org SVN.', 'peak-publisher'),
            'access_check_failed' => __('Could not verify wordpress.org SVN access for this plugin.', 'peak-publisher'),
        ];

        return [
            'slug' => $slug,
            'reason' => $reason,
            'message' => $message ?: ($default_messages[$reason] ?? __('Plugin could not be imported.', 'peak-publisher')),
            'existing_plugin_id' => $existing_plugin_id !== null && $existing_plugin_id > 0 ? $existing_plugin_id : null,
        ];
    }

    private function wporg_import_skip_from_error(string $slug, \WP_Error $error): array {
        $reason = $error->get_error_code();
        if (!in_array($reason, ['already_imported', 'not_found'], true)) {
            $reason = 'access_check_failed';
        }

        $data = $error->get_error_data();
        $existing_plugin_id = is_array($data) ? (int) ($data['existing_plugin_id'] ?? 0) : 0;

        return $this->wporg_import_skip(
            $slug,
            $reason,
            $error->get_error_message(),
            $existing_plugin_id > 0 ? $existing_plugin_id : null
        );
    }

    private function serialize_imported_wporg_plugin(int $plugin_id, string $fallback_slug): array {
        $post = get_post($plugin_id);
        if (!$post instanceof \WP_Post) {
            return [
                'slug' => $fallback_slug,
                'id' => $plugin_id,
                'name' => $fallback_slug,
                'version' => '',
                'count_of_releases' => 0,
            ];
        }

        $releases_by_parent = fetch_releases_grouped_by_parent([$plugin_id]);
        $plugin = $this->serialize_plugin_post($post, false, $releases_by_parent[$plugin_id] ?? []);

        return [
            'slug' => (string) ($plugin['slug'] ?? $fallback_slug),
            'id' => (int) ($plugin['id'] ?? $plugin_id),
            'name' => (string) ($plugin['name'] ?? $fallback_slug),
            'version' => (string) ($plugin['version'] ?? ''),
            'count_of_releases' => (int) ($plugin['count_of_releases'] ?? 0),
        ];
    }

    private function wporg_account_is_configured(string $username): bool {
        $settings = get_option('pblsh_settings');
        $accounts = is_array($settings) && is_array($settings['wporg_accounts'] ?? null) ? $settings['wporg_accounts'] : [];

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $stored_username = normalize_wporg_username($account['username'] ?? null);
            if (is_wp_error($stored_username) || $stored_username !== $username) {
                continue;
            }

            if (wporg_is_encrypted_password(wporg_string_from_value($account['password'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function make_rest_error(string $code, string $message, int $status, ?string $field = null): \WP_Error {
        $data = [ 'status' => $status ];
        if ($field !== null && $field !== '') {
            $data['field'] = $field;
        }
        return new \WP_Error($code, $message, $data);
    }

    private function rest_error_response(\WP_Error $error): \WP_REST_Response {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $payload = [
            'status' => 'error',
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ];
        $field = is_array($data) ? (string) ($data['field'] ?? '') : '';
        if ($field !== '') {
            $payload['field'] = $field;
        }

        return new \WP_REST_Response($payload, $status);
    }
}

AdminAPI::init();
