<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class AssetManager {

    private static ?array $slots = null;

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
                $screenshot_n = $this->find_next_screenshot_n($plugin_slug);
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
     * @param string   $plugin_slug
     * @param string   $slot        SLOT key (e.g. 'icon_128', 'screenshot').
     * @param int|null $screenshot_n Required when $slot === 'screenshot'.
     * @return bool True if at least one file was deleted.
     */
    public function delete(string $plugin_slug, string $slot, ?int $screenshot_n): bool {
        if ($slot === 'screenshot') {
            if ($screenshot_n === null) {
                return false;
            }
            return $this->delete_screenshot_files($plugin_slug, $screenshot_n);
        }
        return $this->delete_slot_files($plugin_slug, $slot);
    }

    /**
     * Move a screenshot from one position to another.
     *
     * @param string $plugin_slug
     * @param int    $from_n Source screenshot number (must exist).
     * @param int    $to_n   Target screenshot number.
     * @return array ['status' => 'ok'] or ['status' => 'error', 'message' => '...']
     */
    public function move_screenshot(string $plugin_slug, int $from_n, int $to_n): array {
        if ($from_n < 1 || $to_n < 1) {
            return ['status' => 'error', 'message' => 'Invalid screenshot numbers.'];
        }
        if ($from_n === $to_n) {
            return ['status' => 'ok'];
        }

        $assets_dir = get_plugin_assets_basedir($plugin_slug);

        // Find the source file.
        $source_path = null;
        $source_ext  = null;
        foreach (self::get_slots()['screenshot']['exts'] as $ext) {
            $path = trailingslashit($assets_dir) . 'screenshot-' . $from_n . '.' . $ext;
            if (file_exists($path)) {
                $source_path = $path;
                $source_ext  = $ext;
                break;
            }
        }
        if ($source_path === null) {
            return ['status' => 'error', 'message' => 'Source screenshot not found.'];
        }

        // Delete target if it exists (frontend already confirmed replacement).
        $this->delete_screenshot_files($plugin_slug, $to_n);

        // Move the file — use rename() directly instead of WP_Filesystem::move().
        $target_path = trailingslashit($assets_dir) . 'screenshot-' . $to_n . '.' . $source_ext;
        if (!@rename($source_path, $target_path)) {
            return ['status' => 'error', 'message' => 'Failed to move screenshot file.'];
        }

        return ['status' => 'ok'];
    }

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
        $assets_dir = get_plugin_assets_basedir($plugin_slug);

        foreach ($slot_def['exts'] as $ext) {
            $filename = $slot_def['prefix'] . '.' . $ext;
            $path     = trailingslashit($assets_dir) . $filename;
            if (file_exists($path) && is_readable($path)) {
                $info         = $this->get_file_info($path, $filename, $plugin_slug, $slot_def);
                $info['slot'] = $slot;
                return $info;
            }
        }
        return null;
    }

    /**
     * Get all numbered screenshot assets, sorted by number.
     *
     * @return array Array of asset info arrays with 'screenshot_n' key.
     */
    public function get_screenshots(string $plugin_slug): array {
        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        if (!is_dir($assets_dir)) {
            return [];
        }

        $slot_def    = self::get_slots()['screenshot'];
        $screenshots = [];

        $files = glob(trailingslashit($assets_dir) . 'screenshot-*.*');
        if ($files === false) {
            return [];
        }

        foreach ($files as $path) {
            $filename = basename($path);
            $exts_pattern = implode('|', self::get_slots()['screenshot']['exts']);
            if (!preg_match('/^screenshot-(\d+)\.(' . $exts_pattern . ')$/i', $filename, $m)) {
                continue;
            }
            $n = (int) $m[1];
            if (isset($screenshots[$n])) {
                continue; // Prefer the first match per number.
            }
            $info                = $this->get_file_info($path, $filename, $plugin_slug, $slot_def);
            $info['slot']        = 'screenshot';
            $info['screenshot_n'] = $n;
            $screenshots[$n]     = $info;
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
                return $this->get_public_url($plugin_slug, $info['filename']);
            }
        }
        return null;
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
        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        if (!is_dir($assets_dir)) {
            return [];
        }
        $banners    = [];

        // Banners
        foreach (['banner-1544x500.png', 'banner-1544x500.jpg', 'banner-1544x500.gif', 'banner-772x250.png', 'banner-772x250.jpg', 'banner-772x250.gif'] as $filename) {
            if (!file_exists(trailingslashit($assets_dir) . $filename)) {
                continue;
            }
            $url = $this->get_public_url($plugin_slug, $filename);
            if (str_starts_with($filename, 'banner-1544')) {
                if (!isset($banners['banner_2x'])) {
                    $banners['banner_2x'] = $url;
                }
            } elseif (str_starts_with($filename, 'banner-772')) {
                if (!isset($banners['banner'])) {
                    $banners['banner'] = $url;
                }
            }
        }

        return $banners;
    }

    /**
     * Build the icons array for the public API response (update-check & plugin_information).
     *
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php
     */
    public function get_plugin_icon(string $plugin_slug): array {
        $assets_dir = get_plugin_assets_basedir($plugin_slug);
        if (!is_dir($assets_dir)) {
            return [];
        }
        $icons      = [];

        // Icons
        foreach (['icon.svg', 'icon-256x256.png', 'icon-256x256.jpg', 'icon-256x256.gif', 'icon-128x128.png', 'icon-128x128.jpg', 'icon-128x128.gif'] as $filename) {
            if (!file_exists(trailingslashit($assets_dir) . $filename)) {
                continue;
            }
            $url = $this->get_public_url($plugin_slug, $filename);
            if ($filename === 'icon.svg') {
                $icons['svg'] = $url;
            } elseif (str_starts_with($filename, 'icon-256')) {
                if (!isset($icons['icon_2x'])) {
                    $icons['icon_2x'] = $url;
                }
            } elseif (str_starts_with($filename, 'icon-128')) {
                if (!isset($icons['icon'])) {
                    $icons['icon'] = $url;
                }
            }
        }

        return $icons;
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
            if (file_exists($path)) {
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
            if (file_exists($path)) {
                $fs->delete($path, false);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /**
     * Find the next available screenshot number (1-based, no gaps required).
     */
    private function find_next_screenshot_n(string $plugin_slug): int {
        $screenshots = $this->get_screenshots($plugin_slug);
        if (empty($screenshots)) {
            return 1;
        }
        $max = max(array_column($screenshots, 'screenshot_n'));
        return $max + 1;
    }

    /**
     * Build the metadata array for a single asset file.
     */
    private function get_file_info(string $path, string $filename, string $plugin_slug, array $slot_def): array {
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $width    = null;
        $height   = null;
        $warnings = [];

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

        return [
            'filename' => $filename,
            'url'      => $this->get_public_url($plugin_slug, $filename),
            'width'    => $width,
            'height'   => $height,
            'filesize' => file_exists($path) ? (int) filesize($path) : 0,
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
