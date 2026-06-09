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
    public function get_plugin(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!is_plugin_post($post)) {
            return [];
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

            $version = (string) ($rel_data['plugin_data']['Version'] ?? ($release->post_title ?? ''));
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
    public function get_plugin_releases(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'pblsh_plugin') {
            return [];
        }

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
            $version = (string) ($rel_data['plugin_data']['Version'] ?? ($release->post_title ?? ''));
            $releases[] = [
                'id' => $release->ID,
                'version' => $version,
                'status' => $release->post_status,
                'date' => $release->post_date,
                'download_url' => rest_url(self::NAMESPACE . '/releases/' . $release->ID . '/download'),
                'installations_count' => $normalized !== '' ? get_plugin_installations_count_by_version((int) $post->ID, $normalized) : 0,
            ];
        }

        // order releases by version (descending)
        usort($releases, function($a, $b) {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        return $releases;
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
        if (!$plugin || $plugin->post_type !== 'pblsh_plugin') {
            return [ 'status' => 'error', 'message' => 'Plugin not found.' ];
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
    /**
     * Update a release.
     */
    public function update_release(\WP_REST_Request $request): array {
        $id = (int) $request->get_param('id');
        $release = get_post($id);
        if (!$release || $release->post_type !== 'pblsh_release') {
            return [ 'status' => 'error', 'message' => 'Release not found.' ];
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
