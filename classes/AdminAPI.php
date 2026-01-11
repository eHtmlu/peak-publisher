<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class AdminAPI {
    private static $instance = null;

    const NAMESPACE = 'pblsh-admin/v1';

    /**
     * Constructor.
     */
    private function __construct() {
        $this->register_routes();
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
        $out = [];

        $plugins = get_posts([
            'post_type' => 'pblsh_plugin',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);

        foreach ($plugins as $plugin_post) {
            $releases = get_posts([
                'post_type' => 'pblsh_release',
                'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
                'post_parent' => $plugin_post->ID,
                'posts_per_page' => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            $latest_version = '';
            $latest_normalized = '';
            $count_of_releases = count($releases);

            foreach ($releases as $rel) {
                $rel_data = json_decode((string) ($rel->post_content ?? ''), true);
                $normalized = (string) ($rel_data['plugin_info']['normalized_version'] ?? '');
                $version = (string) ($rel_data['plugin_data']['Version'] ?? ($rel->post_title ?? ''));
                if ($normalized !== '') {
                    if ($latest_normalized === '' || version_compare($normalized, $latest_normalized, '>')) {
                        $latest_normalized = $normalized;
                        $latest_version = $version;
                    }
                }
            }

            if ($latest_version === '' && $count_of_releases > 0) {
                $latest_version = (string) ($releases[0]->post_title ?? '');
            }

            $out[] = [
                'id' => $plugin_post->ID,
                'name' => $plugin_post->post_title,
                'slug' => $plugin_post->post_name,
                'icon' => get_post_meta($plugin_post->ID, 'pblsh_icon', true),
                'version' => $latest_version,
                'status' => $plugin_post->post_status,
                'count_of_releases' => $count_of_releases,
                'installations_count' => get_plugin_installations_count((int) $plugin_post->ID),
            ];
        }
        return $out;
    }

    /**
     * Get plugin.
     */
    public function get_plugin(\WP_REST_Request $request): array {
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
        $latest_version = '';
        $latest_normalized = '';

        foreach ($releases_query->posts as $release) {
            $rel_data = json_decode((string) $release->post_content, true) ?? [];
            $normalized = (string) ($rel_data['plugin_info']['normalized_version'] ?? '');
            $version = (string) ($rel_data['plugin_data']['Version'] ?? ($release->post_title ?? ''));
            $releases[] = [
                'id' => $release->ID,
                'title' => $version,
                'status' => $release->post_status,
                'date' => $release->post_date,
                'download_url' => rest_url(self::NAMESPACE . '/releases/' . $release->ID . '/download'),
                'installations_count' => $normalized !== '' ? get_plugin_installations_count_by_version((int) $post->ID, $normalized) : 0,
            ];
            if ($normalized !== '') {
                if ($latest_normalized === '' || version_compare($normalized, $latest_normalized, '>')) {
                    $latest_normalized = $normalized;
                    $latest_version = $version;
                }
            }
        }

        // order releases by version
        usort($releases, function($a, $b) {
            return version_compare($a['title'], $b['title'], '<');
        });

        if ($latest_version === '' && !empty($releases)) {
            $latest_version = (string) ($releases[0]['title'] ?? '');
        }

        return [
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'icon' => get_post_meta($post->ID, 'pblsh_icon', true),
            'version' => $latest_version,
            'status' => $post->post_status,
            'releases' => $releases,
            'installations_count' => get_plugin_installations_count((int) $post->ID),
        ];
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

    public function get_peak_publisher_settings_rest(): array {
        return get_peak_publisher_settings();
    }

    public function save_peak_publisher_settings_rest(\WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = []; }
        update_peak_publisher_settings($params);
        return get_peak_publisher_settings();
    }
}

AdminAPI::init();
