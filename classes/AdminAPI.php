<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The REST API of the admin app (namespace pblsh-admin/v1, every route requires
 * manage_options): plugins and releases, the current release, the assets tab, the upload
 * workflow, the settings, and wordpress.org accounts, import and refresh. It is the boundary
 * of the admin side: it validates the request and hands the work to the function modules,
 * UploadWorkflow, AssetManager and WporgOperations, each loaded where it is needed. Errors
 * are answered through rest_error_response(), code, message and status travelling together;
 * the upload routes answer in UploadWorkflow's own format. Loaded only for REST requests
 * whose URI contains pblsh-admin.
 */
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

        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/current-release', [
            'methods' => 'POST',
            'callback' => [$this, 'set_current_release'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/wporg-download-url', [
            'methods' => 'GET',
            'callback' => [$this, 'get_wporg_download_url'],
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

        register_rest_route(self::NAMESPACE, '/releases/(?P<id>\d+)/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_release'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/upgrade-notice', [
            'methods' => 'DELETE',
            'callback' => [$this, 'dismiss_upgrade_notice'],
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

        register_rest_route(self::NAMESPACE, '/admin/wporg/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_wporg_rest'],
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
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets/commit', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_commit_assets'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets/discard', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_discard_assets'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route(self::NAMESPACE, '/plugins/(?P<id>\d+)/assets/resolve', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_resolve_asset'],
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

        if (is_wporg_plugin($post)) {
            // No check against wordpress.org here: the list's directory refresh keeps the cache
            // fresh within minutes, and every write reads fresh under the lock. Only the mirror's
            // own disk is checked — a file lost there is fetched again.
            try {
                require_once PBLSH_PLUGIN_DIR . 'classes/WporgAssetSync.php';
                (new WporgAssetSync())->recover($post);
            } catch (\Throwable $e) {
                wporg_log_cache_error($post, 'assets', $e);
            }
            // A forecast still shown is computed anew on load, foreign commits may have moved it —
            // not on the reload right after the own commit that computed it.
            if (wporg_import_forecast_due($id, time())) {
                refresh_wporg_import_forecast($post);
            }
        }

        $releases_by_parent = fetch_releases_grouped_by_parent([(int) $post->ID]);
        return $this->serialize_plugin_post($post, true, $releases_by_parent[(int) $post->ID] ?? []);
    }

    /**
     * Serialize a plugin post for admin REST responses. `version` is the current release —
     * the one sites receive — `latest_version` the highest; both null when absent.
     * `released_at` is when the current release was published (its post date: the upload,
     * or the first creation of its wordpress.org tag), null without one.
     * `installations` is the channel's figure with its state (self-hosted the exact
     * 24-hour count or 'disabled', wordpress.org the cached public figure), `downloads` the
     * download figures of both channels in one shape — the total and the last 7 and 30 days:
     * self-hosted counted here, wordpress.org the directory's total with the windows of the
     * history fetched from the stats API —, `wporg_stats` the wordpress.org dashboard
     * figures, `wporg_check` and `wporg_token` the state of the copy against wordpress.org
     * (all three null self-hosted).
     *
     * @param \WP_Post[] $releases The plugin's release posts.
     */
    private function serialize_plugin_post(\WP_Post $post, bool $detail, array $releases = []): array {
        $hosting_type = get_plugin_hosting_type($post);
        $is_self_hosted = $hosting_type === 'self_hosted';
        $current = resolve_current_release($post, $releases);
        $wporg_directory = $is_self_hosted ? null : serialize_wporg_directory((int) $post->ID);

        $out = [
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'hosting_type' => $hosting_type,
            'icon_url' => $this->assets()->get_best_icon_url($post),
            'version' => $current['release'] instanceof \WP_Post ? (string) $current['release']->post_title : null,
            'latest_version' => $current['latest'] instanceof \WP_Post ? (string) $current['latest']->post_title : null,
            'current_release_state' => $current['state'],
            // The raw pointer (wporg: the sanitized Stable tag, also 'trunk' or ''; self-hosted:
            // the meta value) — shown as the mechanics line and sent back as the expected
            // value of a flip.
            'pointer' => $current['pointer'],
            'released_at' => $current['release'] instanceof \WP_Post ? $this->release_published_at($current['release']) : null,
            // The distribution switch: self-hosted the post status, wordpress.org the
            // directory's verdict — 'closed' there means nothing is distributed.
            'status' => !$is_self_hosted && $wporg_directory['wporg_stats']['closed'] !== null ? 'closed' : $post->post_status,
            'count_of_releases' => count($releases),
            'installations' => $is_self_hosted
                ? serialize_self_hosted_installations((int) $post->ID)
                : $wporg_directory['installations'],
            'downloads' => $is_self_hosted
                ? summarize_plugin_downloads(get_plugin_downloads((int) $post->ID))
                : $wporg_directory['downloads'],
            'wporg_stats' => $is_self_hosted ? null : $wporg_directory['wporg_stats'],
            // When the plugin was last brought in step with wordpress.org, and what its copy
            // here was made from — sent back with the next refresh (wporg_sync_token()).
            'wporg_check' => $is_self_hosted ? null : $wporg_directory['wporg_check'],
            'wporg_token' => $is_self_hosted ? null : wporg_sync_token($post),
            // Asset changes not on wordpress.org yet — deleting the plugin here would lose them.
            'assets_pending' => $is_self_hosted ? null : count(get_wporg_assets_state((int) $post->ID)['pending']),
        ];

        if ($detail) {
            // The one gate for every wporg write action in the editor: the account the
            // operations would run with, and whether one is usable at all (a stored
            // account whose password decrypts; write access itself is decided by
            // wordpress.org at commit time).
            $username = $is_self_hosted ? null : select_wporg_account_username(
                wporg_string_from_value(get_post_meta((int) $post->ID, '_pblsh_wporg_account_username', true)) ?: null
            );
            $out['wporg_account'] = $is_self_hosted ? null : [
                'username' => $username,
                'can_write' => $username !== null,
            ];
            // When the plugin page shows the last commit, while the forecast lasts (includes/wporg_import_timing.php).
            $out['wporg_import'] = $is_self_hosted ? null : serialize_wporg_import((int) $post->ID, time());
        }

        return $out;
    }

    /**
     * When a release was published — its post date (docs/data-schema.md: the upload, or the
     * first creation of its wordpress.org tag) — as an ISO 8601 UTC moment.
     */
    private function release_published_at(\WP_Post $release): string {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($release->post_date_gmt . ' UTC'));
    }

    /**
     * The releases of a plugin, as stored. No check against wordpress.org of its own: the
     * client requests the list only right after the plugin detail (get_plugin()), which has
     * just refreshed the marker cache — a second check would cost wordpress.org a request
     * for nothing.
     */
    public function get_plugin_releases(\WP_REST_Request $request): array|\WP_Error {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!is_plugin_post($post)) {
            return [];
        }
        $is_wporg = is_wporg_plugin($post);

        $releases_query = new \WP_Query([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_parent' => $post->ID,
        ]);
        $current_release = resolve_current_release($post, $releases_query->posts)['release'];

        $releases = [];
        foreach ($releases_query->posts as $release) {
            $rel_data = json_decode((string) $release->post_content, true) ?? [];
            $normalized = (string) ($rel_data['plugin_info']['normalized_version'] ?? '');
            $version = (string) (($release->post_title ?? '') !== '' ? $release->post_title : ($rel_data['plugin_data']['Version'] ?? ''));
            $releases[] = [
                'id' => $release->ID,
                'version' => $version,
                'is_current' => $current_release instanceof \WP_Post && (int) $current_release->ID === (int) $release->ID,
                'released_at' => $this->release_published_at($release),
                'download_url' => $is_wporg ? '' : rest_url(self::NAMESPACE . '/releases/' . $release->ID . '/download'),
                // wordpress.org has no per-release figures — the column is not rendered there.
                'installations_count' => $is_wporg ? null : ($normalized !== '' ? get_plugin_installations_count_by_version((int) $post->ID, $normalized) : 0),
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
    public function delete_release(\WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $release = get_post($id);
        if (!$release || $release->post_type !== 'pblsh_release') {
            return [ 'status' => 'error', 'message' => 'Release not found.' ];
        }
        $parent = get_post((int) $release->post_parent);
        if (is_wporg_plugin($parent)) {
            return $this->delete_wporg_release_tag($release, $parent);
        }

        // The last gate before the irreversible delete: the current release cannot go — the
        // plugin would silently stop delivering. Make another release current first; the
        // last release leaves only together with the plugin.
        $current_release = is_plugin_post($parent) ? get_current_release($parent) : null;
        if ($current_release instanceof \WP_Post && (int) $current_release->ID === (int) $release->ID) {
            return $this->rest_error_response($this->make_rest_error(
                'current_release_protected',
                __('This is the current release — make another release current before deleting it.', 'peak-publisher'),
                409
            ));
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
     * The wordpress.org account a write of this plugin commits as: the marker's assigned
     * account when it is usable, else the first usable one (select_wporg_account_username()),
     * never written back to the marker. No probe beforehand — the commit itself is the
     * verdict (commit_files() records it, begin_commit() reports rejected credentials), and a
     * probe cost four requests on every click.
     *
     * @return string|\WP_REST_Response the username, or the error response (wporg_no_credentials)
     */
    private function resolve_wporg_write_account(\WP_Post $marker) {
        $preferred_username = wporg_string_from_value(get_post_meta((int) $marker->ID, '_pblsh_wporg_account_username', true));
        $username = select_wporg_account_username($preferred_username !== '' ? $preferred_username : null);
        if ($username === null) {
            return $this->rest_error_response($this->make_rest_error('wporg_no_credentials', __('No usable wordpress.org account — connect one under Settings › wordpress.org.', 'peak-publisher'), 400));
        }
        return $username;
    }

    private function delete_wporg_release_tag(\WP_Post $release, \WP_Post $parent) {
        $version = (string) ($release->post_title ?? '');
        if ($version === '') {
            $content = wporg_decode_json_object((string) $release->post_content);
            $version = (string) ($content['plugin_data']['Version'] ?? '');
        }
        $version = trim($version);
        if ($version === '') {
            return $this->rest_error_response($this->make_rest_error(
                'invalid_version',
                __('Missing plugin version.', 'peak-publisher'),
                400
            ));
        }

        $username = $this->resolve_wporg_write_account($parent);
        if ($username instanceof \WP_REST_Response) {
            return $username;
        }

        require_once __DIR__ . '/WporgOperations.php';
        try {
            $delete_result = WporgOperations::delete_tag($parent, $version, $username);
        } catch (WporgSvnException $e) {
            // The pipeline's errors travel as they are: code, message and status from the throw site.
            return $this->rest_error_response($this->make_rest_error($e->get_error_code(), $e->getMessage(), $e->get_http_status()));
        } catch (\Throwable $e) {
            return $this->rest_error_response($this->make_rest_error('wporg_tag_delete_failed', __('Could not delete the wordpress.org SVN tag.', 'peak-publisher'), 502));
        }

        wp_delete_post((int) $release->ID, true);
        mark_wporg_plugin_cache_stale((int) $parent->ID);

        return [
            'status' => 'ok',
            'revision' => $delete_result['revision'],
            'committed' => !empty($delete_result['committed']),
        ];
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
     * Makes a release the plugin's current release — the one sites receive — on both channels:
     * self-hosted writes the pointer meta, wordpress.org commits the Stable tag line of trunk's
     * readme. expected_pointer is the pointer the editor showed (null when it could not read
     * it), so a flip by someone else in the meantime is refused, never overwritten.
     */
    public function set_current_release(\WP_REST_Request $request) {
        $plugin = get_post((int) $request->get_param('id'));
        if (!is_plugin_post($plugin)) {
            return $this->rest_error_response($this->make_rest_error('plugin_not_found', __('Plugin not found.', 'peak-publisher'), 404));
        }
        $params = $request->get_json_params();
        $version = trim((string) ($params['version'] ?? ''));
        if ($version === '') {
            return $this->rest_error_response($this->make_rest_error('invalid_version', __('Missing plugin version.', 'peak-publisher'), 400, 'version'));
        }
        $expected_pointer = isset($params['expected_pointer']) && is_string($params['expected_pointer']) ? $params['expected_pointer'] : null;

        if (!is_wporg_plugin($plugin)) {
            $result = flip_current_release_pointer($plugin, $version, (string) $expected_pointer);
            if (is_wp_error($result)) {
                return $this->rest_error_response($result);
            }
            return [ 'status' => 'ok', 'from' => $result['from'], 'to' => $result['to'], 'revision' => null ];
        }

        $username = $this->resolve_wporg_write_account($plugin);
        if ($username instanceof \WP_REST_Response) {
            return $username;
        }
        require_once __DIR__ . '/WporgOperations.php';
        try {
            $result = WporgOperations::set_stable_tag($plugin, $version, $expected_pointer, $username);
        } catch (WporgSvnException $e) {
            return $this->rest_error_response($this->make_rest_error($e->get_error_code(), $e->getMessage(), $e->get_http_status()));
        } catch (\Throwable $e) {
            return $this->rest_error_response($this->make_rest_error('wporg_stable_tag_failed', __('Could not change the current release on wordpress.org.', 'peak-publisher'), 502));
        }

        // The flip changed only trunk's readme: when nothing else in the plugin changed since
        // the cached revision, the cache stays fresh with the written pointer; otherwise (or
        // when that cannot be verified) it goes stale and serves the pointer until the refresh.
        // The title follows the pointer either way.
        $known = [ 'trunk_readme' => $result['trunk_readme'] ];
        try {
            $only_trunk = WporgOperations::plugin_changed_only_in((string) $plugin->post_name, [ 'trunk' ], (int) $result['base_revision']);
        } catch (\Throwable $e) {
            $only_trunk = false;
        }
        if ($only_trunk) {
            advance_wporg_plugin_cache((int) $plugin->ID, (int) $result['base_revision'], (int) $result['revision'], $known);
        } else {
            mark_wporg_plugin_cache_stale((int) $plugin->ID, $known);
        }
        refresh_plugin_title_from_reference((int) $plugin->ID);

        return [ 'status' => 'ok', 'from' => $result['from'], 'to' => $version, 'revision' => $result['revision'] ];
    }

    /**
     * Brings the client's wordpress.org plugins up to date, in two forms. Without `plugin_id`
     * the automatic look: the stamp check, whose listings bring every plugin's figures along
     * (refresh_wporg_directory(); the server alone decides when it is due), and the SVN refresh
     * of every marker whose stamp moved — tags, trunk readme, assets mirror. With `plugin_id`
     * the editor's Refresh: that plugin's figures and its SVN refresh whatever the directory
     * says, which lags SVN by wordpress.org's import. A marker's check completes with its SVN
     * refresh; one that failed is recorded on the marker and shows through `wporg_check`, never
     * as an error of this request — and what it changed before it failed is told all the same.
     *
     * Answers what the client then holds: `stats` — figures and check of every marker in
     * scope —, `plugins` — the list row of every marker that moved underneath the client's
     * copy, whoever moved it: the client sends the tokens of its rows as `known`
     * (wporg_sync_token()) —, `changes` — what this request's SVN refreshes changed in what
     * the editor shows —, `next_check_in`, the seconds until the stamp check is due again, and
     * `list_outdated`: the client's list names a marker that is gone or misses one, removed or
     * added elsewhere — no row can say that, the client reloads its list.
     */
    public function refresh_wporg_rest(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : [];
        $plugin_id = isset($params['plugin_id']) ? (int) $params['plugin_id'] : null;
        if ($plugin_id !== null && !is_wporg_plugin(get_post($plugin_id))) {
            return $this->rest_error_response($this->make_rest_error('plugin_not_found', __('Plugin not found.', 'peak-publisher'), 404));
        }

        // The checks the directory left open — a stamp that moved; the editor's Refresh whatever
        // the directory answered, or failed to — complete with the plugin's SVN refresh.
        $open = refresh_wporg_directory($plugin_id === null ? null : [ $plugin_id ], $plugin_id !== null);
        $changes = [];
        foreach ($plugin_id === null ? $open : [ $plugin_id => $open[$plugin_id] ?? null ] as $id => $stamp) {
            $refresh = refresh_wporg_plugin_cache(get_post($id));
            if ($refresh['failure'] === null) {
                confirm_wporg_directory_check($id, $stamp);
            } else {
                record_wporg_directory_check_error($id, $refresh['failure']);
            }
            if ($refresh['changes'] !== []) {
                $changes[$id] = $refresh['changes'];
            }
        }

        $markers = $plugin_id === null
            ? get_posts([ 'post_type' => 'pblsh_wporg_plugin', 'post_status' => 'any', 'posts_per_page' => -1 ])
            : [ get_post($plugin_id) ];
        // The download history rides along with the look, once a day per plugin.
        refresh_wporg_download_stats($markers, time());
        $known = is_array($params['known'] ?? null) ? $params['known'] : [];
        $stats = [];
        $outdated = [];
        foreach ($markers as $marker) {
            $stats[$marker->ID] = serialize_wporg_directory((int) $marker->ID);
            if (isset($known[$marker->ID]) && (string) $known[$marker->ID] !== wporg_sync_token($marker)) {
                $outdated[] = $marker;
            }
        }
        $releases_by_parent = fetch_releases_grouped_by_parent(array_map('intval', wp_list_pluck($outdated, 'ID')));
        $plugins = [];
        foreach ($outdated as $marker) {
            $plugins[$marker->ID] = $this->serialize_plugin_post($marker, false, $releases_by_parent[(int) $marker->ID] ?? []);
        }
        // The automatic look names every wordpress.org plugin the client holds: the two sets
        // are compared as they are.
        $known_ids = array_map('intval', array_keys($known));
        $marker_ids = array_map('intval', wp_list_pluck($markers, 'ID'));
        $list_outdated = $plugin_id === null && (array_diff($known_ids, $marker_ids) !== [] || array_diff($marker_ids, $known_ids) !== []);
        return [
            'status' => 'ok',
            // Objects even when empty, so the client can always iterate their keys.
            'stats' => (object) $stats,
            'plugins' => (object) $plugins,
            'changes' => (object) $changes,
            'next_check_in' => wporg_directory_next_check_in(time()),
            'list_outdated' => $list_outdated,
        ];
    }

    public function get_wporg_download_url(\WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post instanceof \WP_Post || !is_wporg_plugin($post)) {
            return $this->rest_error_response($this->make_rest_error(
                'unsupported_hosting_type',
                __('wordpress.org download URLs are only available for wordpress.org plugins.', 'peak-publisher'),
                404
            ));
        }

        $version = trim((string) $request->get_param('version'));
        if ($version === '') {
            return $this->rest_error_response($this->make_rest_error(
                'invalid_version',
                __('Missing plugin version.', 'peak-publisher'),
                400,
                'version'
            ));
        }

        $release = wporg_find_release_post_by_version((int) $post->ID, $version);
        if (!$release instanceof \WP_Post) {
            return $this->rest_error_response($this->make_rest_error(
                'release_not_found',
                __('Release not found.', 'peak-publisher'),
                404
            ));
        }

        $url = sprintf(
            'https://downloads.wordpress.org/plugin/%s.%s.zip',
            rawurlencode((string) $post->post_name),
            rawurlencode($version)
        );
        $response = wp_remote_request($url, [
            'method' => 'HEAD',
            'timeout' => 15,
            'redirection' => 3,
            'user-agent' => wporg_user_agent(),
        ]);

        if (is_wp_error($response)) {
            return $this->rest_error_response($this->make_rest_error(
                'wporg_download_check_failed',
                __('Could not verify the wordpress.org download URL.', 'peak-publisher'),
                503
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 404) {
            return $this->rest_error_response($this->make_rest_error(
                'wporg_download_not_available',
                __('Release not yet available on wordpress.org. Releases typically appear a few minutes after publishing.', 'peak-publisher'),
                404
            ));
        }
        if ($status < 200 || $status >= 400) {
            return $this->rest_error_response($this->make_rest_error(
                'wporg_download_check_failed',
                __('Could not verify the wordpress.org download URL.', 'peak-publisher'),
                502
            ));
        }

        return [
            'status' => 'ok',
            'url' => $url,
        ];
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
        $assets_dir = get_plugin_assets_dir($plugin);
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

        // The assets mirror and the working copy live together under wporg-plugins/{slug}/.
        $plugin_dir = dirname(get_plugin_assets_dir($plugin), 2);
        if (is_dir($plugin_dir)) {
            get_wp_filesystem()->delete(trailingslashit($plugin_dir), true);
        }
        remove_empty_folders(peak_publisher_upload_basedir());

        wp_delete_post((int) $plugin->ID, true);

        return [
            'status' => 'ok',
            'removed_local_mirror' => true,
            'deleted_releases' => count($release_ids),
        ];
    }

    /**
     * Dismisses the one-time notice about the schema upgrade (includes/upgrade.php).
     */
    public function dismiss_upgrade_notice(): array {
        delete_option('pblsh_upgrade_notice');
        return [ 'status' => 'ok' ];
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
     * The editor's view of a plugin's assets.
     */
    public function handle_get_assets(\WP_REST_Request $request) {
        $post = $this->asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        return $this->assets()->describe($post);
    }

    /** Uploads a file into a slot: multipart with file, slot and screenshot_n (optional). */
    public function handle_upload_asset(\WP_REST_Request $request) {
        $post = $this->asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the facade.
        if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->rest_error_response($this->make_rest_error('asset_no_file', sprintf(__('No file uploaded (error code %d).', 'peak-publisher'), (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE)), 400));
        }
        $screenshot_n = $request->get_param('screenshot_n');
        $result = $this->assets()->upload(
            $post,
            sanitize_key((string) ($request->get_param('slot') ?? '')),
            $screenshot_n === null || $screenshot_n === '' ? null : (int) $screenshot_n,
            $_FILES['file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        );
        return $this->asset_write_response($result);
    }

    /** Deletes a slot: JSON { slot, screenshot_n? }. */
    public function handle_delete_asset(\WP_REST_Request $request) {
        $post = $this->asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        $params = $request->get_json_params();
        $result = $this->assets()->delete(
            $post,
            sanitize_key((string) ($params['slot'] ?? '')),
            isset($params['screenshot_n']) ? (int) $params['screenshot_n'] : null
        );
        return $this->asset_write_response($result);
    }

    /** Moves or swaps screenshots: JSON { from, to }; the server decides which and answers `mode`. */
    public function handle_move_asset(\WP_REST_Request $request) {
        $post = $this->asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        $params = $request->get_json_params();
        $result = $this->assets()->move($post, (int) ($params['from'] ?? 0), (int) ($params['to'] ?? 0));
        return $this->asset_write_response($result);
    }

    /**
     * Commits the working copy of a wordpress.org plugin's assets as one SVN commit. A conflict
     * answers 409 with the view after the commit's fresh pull: the boxes show what to decide.
     */
    public function handle_commit_assets(\WP_REST_Request $request) {
        $post = $this->wporg_asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        $username = $this->resolve_wporg_write_account($post);
        if ($username instanceof \WP_REST_Response) {
            return $username;
        }
        $result = $this->assets()->commit($post, $username);
        if (is_wp_error($result) && $result->get_error_code() === 'wporg_assets_conflict') {
            $result = $this->make_rest_error($result->get_error_code(), $result->get_error_message(), 409, null, [ 'assets' => $this->assets()->describe($post) ]);
        }
        return $this->asset_write_response($result);
    }

    /** Drops every pending asset change of a wordpress.org plugin. */
    public function handle_discard_assets(\WP_REST_Request $request) {
        $post = $this->wporg_asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        return $this->asset_write_response($this->assets()->discard($post));
    }

    /** Decides a conflict or takes back one pending change: JSON { slot, keep: mine|theirs }. */
    public function handle_resolve_asset(\WP_REST_Request $request) {
        $post = $this->wporg_asset_plugin($request);
        if ($post instanceof \WP_REST_Response) {
            return $post;
        }
        $params = $request->get_json_params();
        $keep = (string) ($params['keep'] ?? '');
        if (!in_array($keep, [ 'mine', 'theirs' ], true)) {
            return $this->rest_error_response($this->make_rest_error('invalid_request', __('Choose which version to keep: mine or theirs.', 'peak-publisher'), 400, 'keep'));
        }
        return $this->asset_write_response($this->assets()->resolve($post, sanitize_key((string) ($params['slot'] ?? '')), $keep));
    }

    /** The plugin of an asset request — both channels. */
    private function asset_plugin(\WP_REST_Request $request): \WP_Post|\WP_REST_Response {
        $post = get_post((int) $request->get_param('id'));
        if (!is_plugin_post($post)) {
            return $this->rest_error_response($this->make_rest_error('plugin_not_found', __('Plugin not found.', 'peak-publisher'), 404));
        }
        return $post;
    }

    /** The plugin of a working-copy request — only wordpress.org plugins have one. */
    private function wporg_asset_plugin(\WP_REST_Request $request): \WP_Post|\WP_REST_Response {
        $post = $this->asset_plugin($request);
        if ($post instanceof \WP_Post && !is_wporg_plugin($post)) {
            return $this->rest_error_response($this->make_rest_error('unsupported_hosting_type', __('Only wordpress.org plugins commit their assets.', 'peak-publisher'), 400));
        }
        return $post;
    }

    /** Every write answers the fresh manifest, or the error as it is. */
    private function asset_write_response(array|\WP_Error $result) {
        if (is_wp_error($result)) {
            return $this->rest_error_response($result);
        }
        return [ 'status' => 'ok', ...$result ];
    }

    public function get_peak_publisher_settings_rest(): array {
        return get_peak_publisher_settings_for_api();
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
        return get_peak_publisher_settings_for_api();
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

        // A masked password means the user is testing the stored credential — the
        // probe's verdict is then recorded on the stored account.
        $using_stored_credentials = false;
        if ($password === WPORG_PASSWORD_MASKED) {
            $using_stored_credentials = true;
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
            // Keep testing aligned with saving: credentials are only accepted when they
            // can be stored securely. A test must not create the key file, though — that
            // is the first save's job.
            $storage_error = get_credential_storage_error();
            if ($storage_error !== null) {
                return $this->rest_error_response($storage_error);
            }
        }

        // Load the wordpress.org plugin SVN client only for the endpoint that needs it.
        require_once __DIR__ . '/WporgPluginSvnClient.php';
        try {
            // An MKACTIVITY probe verifies Basic Auth without checking plugin permissions.
            $client = new WporgPluginSvnClient($username, $password);
            $client->test_credentials();
            if ($using_stored_credentials) {
                // Explicit probe: always refresh the stamp — the badge jump is the feedback.
                record_wporg_credentials_verdict($username, true, true);
            }
            return [ 'status' => 'ok' ];
        } catch (WporgSvnException $e) {
            if ($using_stored_credentials && $e->get_error_code() === 'invalid_credentials') {
                record_wporg_credentials_verdict($username, false);
            }
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

        if (!in_array($username, get_usable_wporg_account_usernames(), true)) {
            return $this->rest_error_response($this->make_rest_error(
                'account_not_configured',
                __('Account not configured.', 'peak-publisher'),
                400,
                'username'
            ));
        }

        try {
            $plugins = wporg_api_query_plugins_by_author($username);
        } catch (\Throwable $e) {
            // Expected failures arrive as WporgSvnException from the API module; anything
            // else is an unexpected failure of the same remote operation.
            $error = $e instanceof WporgSvnException ? $e : wporg_api_unavailable();
            return $this->rest_error_response($this->make_rest_error(
                $error->get_error_code(),
                $error->getMessage(),
                $error->get_http_status()
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

        // For imported plugins the local mirror is the authority on the release
        // count — delivered here, so the client needs no directory lookup for them.
        $release_counts_by_plugin_id = [];
        if (!empty($already_imported_by_slug)) {
            foreach (fetch_releases_grouped_by_parent(array_values($already_imported_by_slug)) as $plugin_id => $releases) {
                $release_counts_by_plugin_id[(int) $plugin_id] = count($releases);
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
                'icon' => isset($plugin['icon']) && is_string($plugin['icon']) ? $plugin['icon'] : null,
                // wordpress.org's rounded bucket, as the plugin list will show it.
                'active_installs' => is_int($plugin['active_installs'] ?? null) ? $plugin['active_installs'] : null,
                'already_imported' => isset($already_imported_by_slug[$slug]),
                'existing_plugin_id' => $already_imported_by_slug[$slug] ?? null,
                'count_of_releases' => isset($already_imported_by_slug[$slug])
                    ? ($release_counts_by_plugin_id[$already_imported_by_slug[$slug]] ?? 0)
                    : null,
                'directory_hint' => null,
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

        $slug = normalize_plugin_slug($params['slug'] ?? null, 'slug');
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

        // Already imported: the local mirror is the authority on the release count.
        $local_release_count = null;
        if ($existing_post instanceof \WP_Post) {
            $releases_by_parent = fetch_releases_grouped_by_parent([ (int) $existing_post->ID ]);
            $local_release_count = count($releases_by_parent[(int) $existing_post->ID] ?? []);
        }

        require_once __DIR__ . '/WporgPluginSvnClient.php';
        $client = new WporgPluginSvnClient($username, $credentials['password']);
        $access = $client->check_repo_access($slug);
        $access_status = (string) ($access['status'] ?? 'error');

        // The directory hint is the remaining upfront ownership signal (heuristic,
        // warn-only); write access itself is only decided at MERGE time.
        $directory_hint = null;
        if ($access_status === 'ok') {
            require_once __DIR__ . '/WporgOperations.php';
            $directory_hint = WporgOperations::directory_hint($slug, $username);
        }

        return [
            'status' => 'ok',
            'plugin' => [
                'slug' => $slug,
                'name' => null,
                'already_imported' => $existing_post instanceof \WP_Post,
                'existing_plugin_id' => $existing_post instanceof \WP_Post ? (int) $existing_post->ID : null,
                'count_of_releases' => $local_release_count,
                'access_status' => $access_status,
                'directory_hint' => $directory_hint,
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
            $existing = get_plugin_post_by_slug('pblsh_wporg_plugin', $slug);
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
                $access = $client->check_repo_access($slug);
            } catch (\Throwable $e) {
                $skipped[] = $this->wporg_import_skip($slug, 'access_check_failed');
                continue;
            }

            // Importing needs no ownership — it only mirrors public SVN data (pending
            // ownership transfers, agency handovers). The UI shows the contributor
            // hint beforehand, and SVN enforces write access at deploy time.
            $access_status = (string) ($access['status'] ?? 'error');
            $access_message = isset($access['message']) && is_string($access['message']) ? $access['message'] : null;
            // The access check's MKACTIVITY judges the stored credentials — record it.
            if ($access_status === 'ok') {
                record_wporg_credentials_verdict($username, true);
            } elseif ($access_status === 'credentials_rejected') {
                record_wporg_credentials_verdict($username, false);
            }
            if ($access_status === 'not_found') {
                $skipped[] = $this->wporg_import_skip($slug, 'not_found', $access_message);
                continue;
            }
            if ($access_status !== 'ok') {
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

        // The directory data of the new markers — figures and stamp — in one batched request, so
        // their rows arrive complete instead of waiting for the list's next look. A failure is
        // recorded on the marker like any other and retried by the automatic path. Their
        // download history starts here too: the stats API's full window, once.
        if ($imported !== []) {
            refresh_wporg_directory(array_column($imported, 'id'));
            refresh_wporg_download_stats(array_map('get_post', array_column($imported, 'id')), time());
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
            $slug = normalize_plugin_slug($raw_slug, 'slugs.' . $index);
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

    /** @param array $payload Further facts the client renders with the error (an asset view). */
    private function make_rest_error(string $code, string $message, int $status, ?string $field = null, array $payload = []): \WP_Error {
        $data = [ 'status' => $status ];
        if ($field !== null && $field !== '') {
            $data['field'] = $field;
        }
        if ($payload !== []) {
            $data['payload'] = $payload;
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
        if (is_array($data) && is_array($data['payload'] ?? null)) {
            $payload += $data['payload'];
        }

        return new \WP_REST_Response($payload, $status);
    }
}

AdminAPI::init();
