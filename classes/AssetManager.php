<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class AssetManager {
    private static ?self $instance = null;

    private static ?array $slots = null;

    /** @var array<string, int|null> Slug → post ID cache (per-request). */
    private array $slug_to_id_cache = [];

    /** @var array<int, array|null> Plugin ID → cached meta per group (per-request). */
    private array $meta_cache = [];

    private function __construct() {}

    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fixed slot definitions — single source of truth for backend and frontend.
     * Each slot: prefix, allowed extensions, expected dimensions, UI label, UI group.
     * 'screenshot' is special: multiple numbered files can exist.
     */
    public static function get_slots(): array {
        if (self::$slots !== null) {
            return self::$slots;
        }
        self::$slots = [
            'icon_svg'   => ['prefix' => 'icon',             'exts' => ['svg'],               'expectedW' => null, 'expectedH' => null, 'label' => __('SVG', 'peak-publisher'),               'group' => 'icons'],
            'icon_256'   => ['prefix' => 'icon-256x256',     'exts' => ['png', 'jpg', 'gif'], 'expectedW' => 256,  'expectedH' => 256,  'label' => __('256×256 (Retina)', 'peak-publisher'),  'group' => 'icons'],
            'icon_128'   => ['prefix' => 'icon-128x128',     'exts' => ['png', 'jpg', 'gif'], 'expectedW' => 128,  'expectedH' => 128,  'label' => __('128×128', 'peak-publisher'),           'group' => 'icons'],
            'banner_svg' => ['prefix' => 'banner',           'exts' => ['svg'],               'expectedW' => null, 'expectedH' => null, 'label' => __('SVG', 'peak-publisher'),               'group' => 'banners'],
            'banner_hd'  => ['prefix' => 'banner-1544x500',  'exts' => ['png', 'jpg', 'gif'], 'expectedW' => 1544, 'expectedH' => 500,  'label' => __('1544×500 (Retina)', 'peak-publisher'), 'group' => 'banners'],
            'banner_sd'  => ['prefix' => 'banner-772x250',   'exts' => ['png', 'jpg', 'gif'], 'expectedW' => 772,  'expectedH' => 250,  'label' => __('772×250', 'peak-publisher'),           'group' => 'banners'],
            'screenshot' => ['prefix' => 'screenshot',       'exts' => ['png', 'jpg', 'gif'], 'expectedW' => null, 'expectedH' => null, 'label' => __('Screenshot', 'peak-publisher'),        'group' => 'screenshots'],
        ];
        if (!apply_filters('pblsh_enable_banner_svg', false)) {
            unset(self::$slots['banner_svg']);
        }
        return self::$slots;
    }

    // -------------------------------------------------------------------------
    // Asset meta (database-backed manifest)
    //
    // Based on WordPress.org Plugin Directory asset storage:
    // Three separate post-meta keys on pblsh_plugin posts track which asset
    // files exist.  The filesystem remains the storage backend, but the DB is
    // the source of truth for *which* files are present.
    // -------------------------------------------------------------------------

    /**
     * Meta-key mapping: group name → post-meta key.
     *
     * Each meta value is an associative array keyed by filename.  The value
     * for each filename is an array with a single 'resolution' key:
     * - Icons/banners: pixel dimensions, e.g. "256x256" (false for SVG).
     * - Screenshots:   the screenshot *number* as a string, e.g. "3".
     *   (This overloaded use of 'resolution' mirrors the WordPress.org
     *   Plugin Directory convention and is intentional.)
     */
    private const META_KEYS = [
        'icons'       => 'assets_icons',
        'banners'     => 'assets_banners',
        'screenshots' => 'assets_screenshots',
    ];

    /**
     * Resolve a plugin slug to its post ID (cached per request).
     */
    private function resolve_plugin_id(string $plugin_slug): ?int {
        if (array_key_exists($plugin_slug, $this->slug_to_id_cache)) {
            return $this->slug_to_id_cache[$plugin_slug];
        }
        $post = get_page_by_path($plugin_slug, OBJECT, 'pblsh_plugin');
        $id   = $post ? (int) $post->ID : null;
        $this->slug_to_id_cache[$plugin_slug] = $id;
        return $id;
    }

    /**
     * Read one of the three asset-meta arrays from post meta.
     *
     * @param int    $plugin_id Post ID.
     * @param string $group     'icons', 'banners', or 'screenshots'.
     * @return array The stored array, or empty array if not set.
     */
    private function get_asset_meta(int $plugin_id, string $group): array {
        $cache_key = $plugin_id . ':' . $group;
        if (array_key_exists($cache_key, $this->meta_cache)) {
            return $this->meta_cache[$cache_key];
        }
        $meta_key = self::META_KEYS[$group] ?? '';
        if ($meta_key === '') {
            return [];
        }
        $value = get_post_meta($plugin_id, $meta_key, true);
        $result = is_array($value) ? $value : [];
        $this->meta_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * Write one of the three asset-meta arrays to post meta.
     */
    private function save_asset_meta(int $plugin_id, string $group, array $data): void {
        $meta_key = self::META_KEYS[$group] ?? '';
        if ($meta_key === '') {
            return;
        }
        update_post_meta($plugin_id, $meta_key, $data);
        // Invalidate per-request cache.
        $cache_key = $plugin_id . ':' . $group;
        $this->meta_cache[$cache_key] = $data;
    }

    /**
     * Build a meta entry for a newly placed asset file.
     *
     * The entry structure mirrors the WordPress.org Plugin Directory SVN
     * asset metadata (filename, revision, resolution, local).
     *
     * - filename:   canonical filename (redundant with the array key, but
     *               kept for parity with the WordPress.org data model).
     * - revision:   unix timestamp of the upload — used for cache-busting
     *               asset URLs without hitting the filesystem.
     * - resolution: pixel dimensions for icons/banners (e.g. "256x256",
     *               false for SVG), or the screenshot *number* as string
     *               for screenshots (e.g. "3"). This overloaded use mirrors
     *               the WordPress.org Plugin Directory convention.
     * - local:      reserved for future use (empty string or false for SVG).
     */
    private function build_meta_entry(string $filename, string $slot, array $slot_def): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($slot === 'screenshot') {
            preg_match('/^screenshot-(\d+)\./', $filename, $m);
            $resolution = $m[1] ?? '0';
            $local = '';
        } elseif ($ext === 'svg') {
            $resolution = false;
            $local = false;
        } else {
            $resolution = $slot_def['expectedW'] . 'x' . $slot_def['expectedH'];
            $local = '';
        }
        return [
            'filename'   => $filename,
            'revision'   => time(),
            'resolution' => $resolution,
            'local'      => $local,
        ];
    }

    // -------------------------------------------------------------------------
    // Public API — Upload / Delete / Move
    // -------------------------------------------------------------------------

    /**
     * Upload a file to a slot.
     *
     * @param int         $plugin_id   WP post ID of the plugin.
     * @param string      $plugin_slug Plugin slug (post_name).
     * @param string      $slot        One of the SLOTS keys.
     * @param int|null    $screenshot_n Screenshot number (null = append new, int = replace specific).
     * @param array       $file_data   Entry from $_FILES['file'].
     * @return array { status, filename, url, width, height, was_renamed, original_name, screenshot_n, warnings[] }
     */
    public function upload(int $plugin_id, string $plugin_slug, string $slot, ?int $screenshot_n, array $file_data): array {
        if (!isset(self::get_slots()[$slot])) {
            return ['status' => 'error', 'message' => 'Unknown slot.'];
        }
        $slot_def = self::get_slots()[$slot];

        $tmp_path = (string) ($file_data['tmp_name'] ?? '');
        if ($tmp_path === '' || !file_exists($tmp_path)) {
            return ['status' => 'error', 'message' => 'Upload failed: no temporary file received.'];
        }

        $original_name = sanitize_file_name((string) ($file_data['name'] ?? 'upload'));
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        // Validate that the extension is allowed for this slot.
        if (!in_array($ext, $slot_def['exts'], true)) {
            return [
                'status'  => 'error',
                'message' => sprintf(
                    'File type .%s is not allowed for this slot. Allowed types: %s.',
                    $ext,
                    implode(', ', $slot_def['exts'])
                ),
            ];
        }

        // Validate file contents match the claimed type.
        if ($ext === 'svg') {
            if (!$this->is_valid_svg($tmp_path)) {
                return ['status' => 'error', 'message' => 'The file does not appear to be a valid SVG.'];
            }
            $validated_ext = 'svg';
        } else {
            $validated_ext = $this->get_raster_image_ext($tmp_path);
            if ($validated_ext === false) {
                return ['status' => 'error', 'message' => 'The file does not appear to be a valid image (PNG, JPG, or GIF).'];
            }
            if (!in_array($validated_ext, $slot_def['exts'], true)) {
                return [
                    'status'  => 'error',
                    'message' => sprintf(
                        'Detected image type .%s is not allowed for this slot. Allowed types: %s.',
                        $validated_ext,
                        implode(', ', $slot_def['exts'])
                    ),
                ];
            }
        }

        // Ensure the assets directory exists and is protected.
        ensure_upload_dir_is_ready_and_secured();
        ensure_plugin_assets_dir($plugin_slug);
        $assets_dir = get_plugin_assets_basedir($plugin_slug);

        // Determine screenshot number and canonical filename.
        if ($slot === 'screenshot') {
            if ($screenshot_n === null) {
                $screenshot_n = $this->find_next_screenshot_n($plugin_id);
            }
            $this->delete_screenshot_files($plugin_slug, $screenshot_n);
            $canonical_name = 'screenshot-' . $screenshot_n . '.' . $validated_ext;
        } else {
            $this->delete_slot_files($plugin_slug, $slot);
            $canonical_name = $slot_def['prefix'] . '.' . $validated_ext;
        }

        $final_path = trailingslashit($assets_dir) . $canonical_name;

        // Move file to final destination.
        $moved = false;
        if (is_uploaded_file($tmp_path)) {
            $moved = @move_uploaded_file($tmp_path, $final_path);
        }
        if (!$moved) {
            $moved = get_wp_filesystem()->move($tmp_path, $final_path, true);
        }
        if (!$moved) {
            return ['status' => 'error', 'message' => 'Failed to save the uploaded file. Please check server permissions.'];
        }

        // Update asset meta only after the file was placed successfully.
        $new_entry = $this->build_meta_entry($canonical_name, $slot, $slot_def);

        if ($slot === 'screenshot') {
            $meta = $this->get_asset_meta($plugin_id, 'screenshots');
            foreach ($meta as $fname => $entry) {
                if (($entry['resolution'] ?? '') === (string) $screenshot_n) {
                    unset($meta[$fname]);
                }
            }
            $meta[$canonical_name] = $new_entry;
            $this->save_asset_meta($plugin_id, 'screenshots', $meta);
        } else {
            $group = $slot_def['group'];
            $meta  = $this->get_asset_meta($plugin_id, $group);
            foreach ($meta as $fname => $entry) {
                if (str_starts_with($fname, $slot_def['prefix'] . '.')) {
                    unset($meta[$fname]);
                }
            }
            $meta[$canonical_name] = $new_entry;
            $this->save_asset_meta($plugin_id, $group, $meta);
        }

        // Validate dimensions for raster images and generate warnings.
        $warnings = [];
        $width    = null;
        $height   = null;

        if ($validated_ext !== 'svg') {
            $image_size = @getimagesize($final_path);
            if ($image_size !== false) {
                $width  = (int) $image_size[0];
                $height = (int) $image_size[1];
                if ($slot_def['expectedW'] !== null && $slot_def['expectedH'] !== null) {
                    if ($width !== $slot_def['expectedW'] || $height !== $slot_def['expectedH']) {
                        $warnings[] = [
                            'code'    => 'wrong_dimensions',
                            'message' => sprintf(
                                'Expected %d×%d px, but the uploaded file is %d×%d px.',
                                $slot_def['expectedW'], $slot_def['expectedH'],
                                $width, $height
                            ),
                        ];
                    }
                }
            }
        }

        return [
            'status'        => 'ok',
            'filename'      => $canonical_name,
            'url'           => $this->get_public_url($plugin_slug, $canonical_name),
            'width'         => $width,
            'height'        => $height,
            'was_renamed'   => $canonical_name !== $original_name,
            'original_name' => $original_name,
            'screenshot_n'  => $slot === 'screenshot' ? $screenshot_n : null,
            'warnings'      => $warnings,
        ];
    }

    /**
     * Delete a slot's asset file(s).
     *
     * @param int      $plugin_id    WP post ID of the plugin.
     * @param string   $plugin_slug
     * @param string   $slot         SLOT key (e.g. 'icon_128', 'screenshot').
     * @param int|null $screenshot_n Required when $slot === 'screenshot'.
     * @return bool True if at least one file was deleted.
     */
    public function delete(int $plugin_id, string $plugin_slug, string $slot, ?int $screenshot_n): bool {
        $slot_def = self::get_slots()[$slot] ?? null;
        if ($slot_def === null) {
            return false;
        }

        if ($slot === 'screenshot') {
            if ($screenshot_n === null) {
                return false;
            }
            $deleted = $this->delete_screenshot_files($plugin_slug, $screenshot_n);

            // Remove from screenshots meta.
            $meta = $this->get_asset_meta($plugin_id, 'screenshots');
            foreach ($meta as $fname => $entry) {
                if (($entry['resolution'] ?? '') === (string) $screenshot_n) {
                    unset($meta[$fname]);
                }
            }
            $this->save_asset_meta($plugin_id, 'screenshots', $meta);
            return $deleted;
        }

        $deleted = $this->delete_slot_files($plugin_slug, $slot);

        // Remove from icons/banners meta.
        $group = $slot_def['group'];
        $meta  = $this->get_asset_meta($plugin_id, $group);
        foreach ($meta as $fname => $entry) {
            if (str_starts_with($fname, $slot_def['prefix'] . '.')) {
                unset($meta[$fname]);
            }
        }
        $this->save_asset_meta($plugin_id, $group, $meta);

        return $deleted;
    }

    /**
     * Move a screenshot from one position to another.
     *
     * @param int    $plugin_id    WP post ID of the plugin.
     * @param string $plugin_slug
     * @param int    $from_n Source screenshot number (must exist).
     * @param int    $to_n   Target screenshot number.
     * @return array ['status' => 'ok'] or ['status' => 'error', 'message' => '...']
     */
    public function move_screenshot(int $plugin_id, string $plugin_slug, int $from_n, int $to_n): array {
        if ($from_n < 1 || $to_n < 1) {
            return ['status' => 'error', 'message' => 'Invalid screenshot numbers.'];
        }
        if ($from_n === $to_n) {
            return ['status' => 'ok'];
        }

        // Find the source file via asset meta (DB is source of truth).
        $meta     = $this->get_asset_meta($plugin_id, 'screenshots');
        $src_fname = null;
        foreach ($meta as $fname => $entry) {
            if (($entry['resolution'] ?? '') === (string) $from_n) {
                $src_fname = $fname;
                break;
            }
        }
        if ($src_fname === null) {
            return ['status' => 'error', 'message' => 'Source screenshot not found.'];
        }

        $source_ext  = strtolower(pathinfo($src_fname, PATHINFO_EXTENSION));
        $assets_dir  = get_plugin_assets_basedir($plugin_slug);
        $source_path = trailingslashit($assets_dir) . $src_fname;

        // Delete target if it exists (frontend already confirmed replacement).
        $this->delete_screenshot_files($plugin_slug, $to_n);

        // Move the file via WP_Filesystem for virtual FS compatibility.
        $target_path = trailingslashit($assets_dir) . 'screenshot-' . $to_n . '.' . $source_ext;
        if (!get_wp_filesystem()->move($source_path, $target_path, true)) {
            return ['status' => 'error', 'message' => 'Failed to move screenshot file.'];
        }

        // Update screenshots meta: remove source entry + target entries, add new target.
        $new_filename  = 'screenshot-' . $to_n . '.' . $source_ext;
        $slot_def      = self::get_slots()['screenshot'];

        // Remove old source and any existing target entries.
        foreach ($meta as $fname => $entry) {
            $res = $entry['resolution'] ?? '';
            if ($res === (string) $from_n || $res === (string) $to_n) {
                unset($meta[$fname]);
            }
        }
        $meta[$new_filename] = $this->build_meta_entry($new_filename, 'screenshot', $slot_def);
        $this->save_asset_meta($plugin_id, 'screenshots', $meta);

        return ['status' => 'ok'];
    }

    // -------------------------------------------------------------------------
    // Public API — Read
    // -------------------------------------------------------------------------

    /**
     * Get all assets for a plugin, grouped by slot.
     *
     * @return array {
     *   icon_128: asset|null,
     *   icon_256: asset|null,
     *   icon_svg: asset|null,
     *   banner_sd: asset|null,
     *   banner_hd: asset|null,
     *   banner_svg: asset|null,
     *   screenshots: asset[],
     * }
     */
    public function get_all(string $plugin_slug): array {
        $result = [];
        foreach (self::get_slots() as $slot => $slot_def) {
            if ($slot === 'screenshot') {
                continue;
            }
            $result[$slot] = $this->find_file_in_slot($plugin_slug, $slot);
        }
        $result['screenshots'] = $this->get_screenshots($plugin_slug);
        return $result;
    }

    /**
     * Find the existing file for a fixed (non-screenshot) slot.
     * Returns null if the slot is empty.
     */
    public function find_file_in_slot(string $plugin_slug, string $slot): ?array {
        if (!isset(self::get_slots()[$slot]) || $slot === 'screenshot') {
            return null;
        }
        $slot_def  = self::get_slots()[$slot];
        $plugin_id = $this->resolve_plugin_id($plugin_slug);
        if ($plugin_id === null) {
            return null;
        }

        $group = $slot_def['group'];
        $meta  = $this->get_asset_meta($plugin_id, $group);

        // Find the entry matching this slot's prefix.
        $filename = null;
        foreach ($meta as $fname => $entry) {
            if (str_starts_with($fname, $slot_def['prefix'] . '.')) {
                $filename = $fname;
                break;
            }
        }
        if ($filename === null) {
            return null;
        }

        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        $path       = trailingslashit($assets_dir) . $filename;
        $info         = $this->get_file_info($path, $filename, $plugin_slug, $slot_def);
        $info['slot'] = $slot;
        return $info;
    }

    /**
     * Get all numbered screenshot assets, sorted by number.
     *
     * @return array Array of asset info arrays with 'screenshot_n' key.
     */
    public function get_screenshots(string $plugin_slug): array {
        $plugin_id = $this->resolve_plugin_id($plugin_slug);
        if ($plugin_id === null) {
            return [];
        }

        $meta = $this->get_asset_meta($plugin_id, 'screenshots');
        if (empty($meta)) {
            return [];
        }

        $assets_dir  = get_plugin_assets_basedir($plugin_slug);
        $slot_def    = self::get_slots()['screenshot'];
        $screenshots = [];

        foreach ($meta as $fname => $entry) {
            $n = (int) ($entry['resolution'] ?? 0);
            if ($n < 1) {
                continue;
            }
            $path                 = trailingslashit($assets_dir) . $fname;
            $info                 = $this->get_file_info($path, $fname, $plugin_slug, $slot_def);
            $info['slot']         = 'screenshot';
            $info['screenshot_n'] = $n;
            $screenshots[$n]      = $info;
        }

        ksort($screenshots);
        return array_values($screenshots);
    }

    /**
     * Return the public URL for the best available icon (SVG → 256 → 128), or null if none exists.
     */
    public function get_best_icon_url(string $plugin_slug): ?string {
        foreach (['icon_svg', 'icon_256', 'icon_128'] as $slot) {
            $info = $this->find_file_in_slot($plugin_slug, $slot);
            if ($info !== null) {
                return $info['url'];
            }
        }

        // Geopattern fallback when no icon was uploaded.
        $geopattern_url = get_geopattern_icon_url( $plugin_slug );
        return $geopattern_url ?: null;
    }

    /**
     * Get the public direct file URL for an asset.
     */
    public function get_public_url(string $plugin_slug, string $filename): string {
        $upload_dir = peak_publisher_upload_dir();
        $relative   = 'plugins/' . sanitize_file_name($plugin_slug) . '/assets/' . $filename;
        $mtime      = (int) @filemtime($upload_dir['basedir'] . '/' . $relative);
        return $upload_dir['baseurl'] . '/' . $relative . '?t=' . $mtime;
    }

    /**
     * Build the banners array for the public API response (update-check & plugin_information).
     *
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php
     */
    public function get_plugin_banner(string $plugin_slug): array {
        $banners = [];

        $hd_info = $this->find_file_in_slot($plugin_slug, 'banner_hd');
        if ($hd_info) {
            $banners['banner_2x'] = $hd_info['url'];
        }

        $sd_info = $this->find_file_in_slot($plugin_slug, 'banner_sd');
        if ($sd_info) {
            $banners['banner'] = $sd_info['url'];
        }

        return $banners;
    }

    /**
     * Build the icons array for the public API response (update-check & plugin_information).
     *
     * Follows the same logic as WordPress.org Plugin Directory:
     * - SVG has priority — if present, raster icons are ignored.
     * - If no SVG, look for 128x128 (1x) and 256x256 (2x) separately.
     * - If only 2x exists, it doubles as the main icon.
     * - Geopattern fallback when no icon was uploaded.
     *
     * Always returns an array with exactly four keys. Values are either a URL string or false:
     * - 'svg'       — SVG icon URL, or false.
     * - 'icon'      — Primary icon URL (SVG, 1x raster, 2x fallback, or geopattern), or false.
     * - 'icon_2x'   — 2x raster icon URL, or false. Not set when SVG or geopattern is used.
     * - 'generated' — true if the icon is a generated geopattern fallback, false otherwise.
     *
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php
     *
     * @return array{svg: string|false, icon: string|false, icon_2x: string|false, generated: bool}
     */
    public function get_plugin_icon(string $plugin_slug): array {
        $icon      = false;
        $icon_2x   = false;
        $svg       = false;
        $generated = false;

        // Check for SVG first — it takes priority over raster icons.
        $svg_info = $this->find_file_in_slot($plugin_slug, 'icon_svg');
        if ($svg_info) {
            $svg  = $svg_info['url'];
            $icon = $svg_info['url'];
        } else {
            // Look for raster icons.
            $icon_1x_info = $this->find_file_in_slot($plugin_slug, 'icon_128');
            $icon_2x_info = $this->find_file_in_slot($plugin_slug, 'icon_256');

            $icon_2x = $icon_2x_info ? $icon_2x_info['url'] : false;
            $icon    = $icon_1x_info ? $icon_1x_info['url'] : ($icon_2x ?: false);
        }

        // Geopattern fallback when no icon was uploaded.
        // Based on WordPress.org Plugin Directory.
        if (!$icon) {
            $generated = true;
            $icon_2x   = false;
            $icon      = get_geopattern_icon_url($plugin_slug) ?: false;
        }

        return compact('svg', 'icon', 'icon_2x', 'generated');
    }

    /**
     * Build the screenshots array for the plugin_information API response.
     *
     * @return array List of ['src' => url, 'caption' => ''] entries.
     */
    public function get_api_screenshots(string $plugin_slug, array $readme_screenshots = []): array {
        $screenshots = $this->get_screenshots($plugin_slug);
        if (empty($screenshots)) {
            return [];
        }

        $result = [];
        foreach ($screenshots as $shot) {
            $n       = $shot['screenshot_n'];
            $caption = $readme_screenshots[$n] ?? '';
            $result[$n] = [
                'src'     => $shot['url'],
                'caption' => $caption,
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Delete all extension variants for a fixed slot.
     */
    private function delete_slot_files(string $plugin_slug, string $slot): bool {
        if (!isset(self::get_slots()[$slot])) {
            return false;
        }
        $slot_def   = self::get_slots()[$slot];
        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        $fs         = get_wp_filesystem();
        $deleted    = false;

        foreach ($slot_def['exts'] as $ext) {
            $path = trailingslashit($assets_dir) . $slot_def['prefix'] . '.' . $ext;
            if ($fs->exists($path)) {
                $fs->delete($path, false);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /**
     * Delete all extension variants for a numbered screenshot.
     */
    private function delete_screenshot_files(string $plugin_slug, int $screenshot_n): bool {
        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        $fs         = get_wp_filesystem();
        $deleted    = false;

        foreach (self::get_slots()['screenshot']['exts'] as $ext) {
            $path = trailingslashit($assets_dir) . 'screenshot-' . $screenshot_n . '.' . $ext;
            if ($fs->exists($path)) {
                $fs->delete($path, false);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /**
     * Find the next available screenshot number (1-based, no gaps required).
     */
    private function find_next_screenshot_n(int $plugin_id): int {
        $meta = $this->get_asset_meta($plugin_id, 'screenshots');
        if (empty($meta)) {
            return 1;
        }
        $max = 0;
        foreach ($meta as $entry) {
            $n = (int) ($entry['resolution'] ?? 0);
            if ($n > $max) {
                $max = $n;
            }
        }
        return $max + 1;
    }

    /**
     * Build the metadata array for a single asset file.
     */
    private function get_file_info(string $path, string $filename, string $plugin_slug, array $slot_def): array {
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $width    = null;
        $height   = null;
        $filesize = 0;
        $warnings = [];
        $exists   = file_exists($path);

        if ($exists) {
            $filesize = (int) filesize($path);

            if ($ext !== 'svg') {
                $image_size = @getimagesize($path);
                if ($image_size !== false) {
                    $width  = (int) $image_size[0];
                    $height = (int) $image_size[1];
                    if ($slot_def['expectedW'] !== null && $slot_def['expectedH'] !== null) {
                        if ($width !== $slot_def['expectedW'] || $height !== $slot_def['expectedH']) {
                            $warnings[] = [
                                'code'    => 'wrong_dimensions',
                                'message' => sprintf(
                                    "Expected: %d×%d px\nFound: %d×%d px",
                                    $slot_def['expectedW'], $slot_def['expectedH'],
                                    $width, $height
                                ),
                            ];
                        }
                    }
                }
            }
        } else {
            $warnings[] = [
                'code'    => 'file_missing',
                'message' => 'Asset file is registered but missing from disk.',
            ];
        }

        return [
            'filename' => $filename,
            'url'      => $this->get_public_url($plugin_slug, $filename),
            'width'    => $width,
            'height'   => $height,
            'filesize' => $filesize,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate a raster image by reading its actual file headers.
     * Returns the detected extension ('png', 'jpg', 'gif') or false on failure.
     */
    private function get_raster_image_ext(string $path): string|false {
        $image_info = @getimagesize($path);
        if ($image_info === false) {
            return false;
        }
        $type_map = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF  => 'gif',
        ];
        return $type_map[$image_info[2]] ?? false;
    }

    /**
     * Validate that a file is a well-formed SVG document.
     * Uses DOMDocument when available (full XML validation), otherwise
     * falls back to a lightweight string check (root element only).
     *
     * Note: This method validates structure only — it does NOT sanitize embedded scripts,
     * event handlers, or <foreignObject>. This matches the approach used by wordpress.org,
     * which also accepts SVG plugin assets without script sanitization. SVG assets are
     * rendered inside <img> tags (both in the admin UI and in the public API), which
     * prevents script execution by browser design. Direct URL access is possible but
     * limited to admin-uploaded content.
     */
    private function is_valid_svg(string $path): bool {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return false;
        }

        if (class_exists('DOMDocument')) {
            $previous_state = libxml_use_internal_errors(true);
            $doc            = new \DOMDocument();
            $loaded         = $doc->loadXML($content, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_state);

            if (!$loaded) {
                return false;
            }

            $root = $doc->documentElement;
            return $root && strtolower($root->localName) === 'svg';
        }

        // Fallback: strip XML declaration and whitespace, then check for <svg root element.
        $trimmed = preg_replace('/^<\?xml[^?]*\?>\s*/si', '', trim($content));
        return (bool) preg_match('/^<svg[\s>]/i', $trimmed);
    }

    /**
     * Check whether a given filename matches any known asset pattern.
     * Used by the public serve endpoint for path validation.
     */
    public static function is_valid_asset_filename(string $filename): bool {
        // Fixed slot filenames
        foreach (self::get_slots() as $slot => $slot_def) {
            if ($slot === 'screenshot') {
                continue;
            }
            foreach ($slot_def['exts'] as $ext) {
                if ($filename === $slot_def['prefix'] . '.' . $ext) {
                    return true;
                }
            }
        }
        // Screenshot filenames: screenshot-{N}.{ext}
        $exts_pattern = implode('|', self::get_slots()['screenshot']['exts']);
        if (preg_match('/^screenshot-(\d+)\.(' . $exts_pattern . ')$/i', $filename)) {
            return true;
        }
        return false;
    }
}
