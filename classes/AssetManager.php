<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The facade over a plugin's assets on both channels. Reading is the same for both: the
 * shipped layer — the self-hosted directory, or the mirror of a wordpress.org plugin's SVN
 * assets/ that WporgAssetSync keeps in step — described by the manifest
 * (read_asset_manifest()). Self-hosted writes go to LocalAssetStore at once; the assets of a
 * wordpress.org plugin are read-only here.
 */
class AssetManager {
    private static ?self $instance = null;
    private ?LocalAssetStore $local = null;

    private function __construct() {}

    public static function init(): self {
        return self::$instance ??= new self();
    }

    private function local(): LocalAssetStore {
        require_once __DIR__ . '/LocalAssetStore.php';
        return $this->local ??= new LocalAssetStore();
    }

    /**
     * The editor's view (REST GET): every fixed slot (entry or null), the screenshots by number,
     * and their captions from the readme of the release sites receive (get_screenshot_captions()).
     * A wordpress.org plugin adds where its mirror stands: the revision of assets/ it holds,
     * when it was last confirmed, and how many files of assets/ are no slot of this tab.
     */
    public function describe(\WP_Post $plugin): array {
        $manifest = read_asset_manifest((int) $plugin->ID);
        $out = [];
        foreach (array_keys(get_asset_slots()) as $slot_id) {
            if ($slot_id !== 'screenshot') {
                $out[$slot_id] = isset($manifest[$slot_id]) ? $this->entry_view($plugin, $slot_id, $manifest[$slot_id], 'shipped') : null;
            }
        }
        $out['screenshots'] = $this->screenshot_views($plugin, $manifest);
        $captions = get_screenshot_captions($plugin);
        $out['screenshot_captions'] = (object) $captions['captions'];
        $out['captions_source'] = $captions['source'];
        $out['wporg'] = null;
        if (is_wporg_plugin($plugin)) {
            $state = get_wporg_assets_state((int) $plugin->ID);
            $out['wporg'] = [
                'revision' => $state['revision'],
                'listed_at' => $state['listed_at'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $state['listed_at']) : null,
                'other_files' => $state['other_files'],
            ];
        }
        return $out;
    }

    /** The screenshot entries of a manifest as views, by number. */
    private function screenshot_views(\WP_Post $plugin, array $manifest): array {
        $views = [];
        foreach ($manifest as $slot_id => $entry) {
            if (preg_match('/^screenshot-(\d+)$/', (string) $slot_id, $m)) {
                $views[(int) $m[1]] = [ ...$this->entry_view($plugin, (string) $slot_id, $entry, 'shipped'), 'screenshot_n' => (int) $m[1] ];
            }
        }
        ksort($views);
        return array_values($views);
    }

    private function entry_view(\WP_Post $plugin, string $slot_id, array $entry, string $layer): array {
        $path = get_plugin_assets_dir($plugin, $layer) . '/' . $entry['filename'];
        $warnings = asset_dimension_warnings($entry, asset_slot_definition($slot_id));
        // Re-validation when reading back from persistence: the manifest names a file the disk may have lost.
        if (!file_exists($path)) {
            $warnings[] = [ 'code' => 'file_missing', 'message' => __('Asset file is registered but missing from disk.', 'peak-publisher') ];
        }
        return [
            ...$entry,
            'url' => get_plugin_assets_url($plugin, $layer) . '/' . rawurlencode($entry['filename']) . '?rev=' . (int) $entry['revision'],
            'warnings' => $warnings,
        ];
    }

    private function shipped_url(\WP_Post $plugin, array $manifest, string $slot_id): ?string {
        $entry = $manifest[$slot_id] ?? null;
        return $entry === null ? null : get_plugin_assets_url($plugin) . '/' . rawurlencode($entry['filename']) . '?rev=' . (int) $entry['revision'];
    }

    /** The list's and the header's icon — always what is shipped: icon.svg, else 128x128, else 256x256, else the generated pattern. */
    public function get_best_icon_url(\WP_Post $plugin): string {
        $manifest = read_asset_manifest((int) $plugin->ID);
        foreach ([ 'icon_svg', 'icon_128', 'icon_256' ] as $slot_id) {
            $url = $this->shipped_url($plugin, $manifest, $slot_id);
            if ($url !== null) {
                return $url;
            }
        }
        return get_geopattern_icon_url($plugin, (string) get_post_meta((int) $plugin->ID, PBLSH_ASSETS_COLOR_META, true));
    }

    /**
     * The icons of the public API, like wordpress.org's: SVG first; else 128x128 as icon and
     * 256x256 as icon_2x (the 2x doubles as icon when alone); the generated pattern otherwise.
     *
     * @return array{svg: string|false, icon: string|false, icon_2x: string|false, generated: bool}
     */
    public function get_plugin_icon(\WP_Post $plugin): array {
        $manifest = read_asset_manifest((int) $plugin->ID);
        $svg = $this->shipped_url($plugin, $manifest, 'icon_svg') ?? false;
        $icon_2x = false;
        if ($svg !== false) {
            $icon = $svg;
        } else {
            $icon_2x = $this->shipped_url($plugin, $manifest, 'icon_256') ?? false;
            $icon = $this->shipped_url($plugin, $manifest, 'icon_128') ?? $icon_2x;
        }
        $generated = $icon === false;
        if ($generated) {
            $icon_2x = false;
            $icon = get_geopattern_icon_url($plugin, (string) get_post_meta((int) $plugin->ID, PBLSH_ASSETS_COLOR_META, true));
        }
        return compact('svg', 'icon', 'icon_2x', 'generated');
    }

    /** @return array{banner?: string, banner_2x?: string} */
    public function get_plugin_banner(\WP_Post $plugin): array {
        $manifest = read_asset_manifest((int) $plugin->ID);
        return array_filter([
            'banner_2x' => $this->shipped_url($plugin, $manifest, 'banner_hd'),
            'banner' => $this->shipped_url($plugin, $manifest, 'banner_sd'),
        ]);
    }

    /** The screenshots of plugin_information: shipped, by number, with their captions. */
    public function get_api_screenshots(\WP_Post $plugin, array $readme_screenshots = []): array {
        $result = [];
        foreach (read_asset_manifest((int) $plugin->ID) as $slot_id => $entry) {
            if (preg_match('/^screenshot-(\d+)$/', (string) $slot_id, $m)) {
                $result[(int) $m[1]] = [ 'src' => $this->shipped_url($plugin, [ $slot_id => $entry ], (string) $slot_id), 'caption' => (string) ($readme_screenshots[(int) $m[1]] ?? '') ];
            }
        }
        ksort($result);
        return $result;
    }

    /** @return array{assets: array, warnings: array}|\WP_Error */
    public function upload(\WP_Post $plugin, string $slot, ?int $screenshot_n, array $file_data): array|\WP_Error {
        $slot_id = $slot === 'screenshot' ? 'screenshot-' . ($screenshot_n ?? next_screenshot_number(array_keys(read_asset_manifest((int) $plugin->ID)))) : $slot;
        $definition = asset_slot_definition($slot_id);
        if ($definition === null) {
            return new \WP_Error('asset_unknown_slot', __('Unknown slot.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        $tmp_path = (string) ($file_data['tmp_name'] ?? '');
        if ($tmp_path === '' || !file_exists($tmp_path)) {
            return new \WP_Error('asset_no_file', __('Upload failed: no temporary file received.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        $facts = validate_asset_upload($tmp_path, sanitize_file_name((string) ($file_data['name'] ?? 'upload')), $definition);
        if (is_wp_error($facts)) {
            return $facts;
        }
        $error = $this->local()->put($plugin, $slot_id, asset_canonical_filename($slot_id, $facts['ext']), $tmp_path, $facts);
        return $error ?? [ 'assets' => $this->describe($plugin), 'warnings' => $facts['warnings'] ];
    }

    /** @return array{assets: array}|\WP_Error */
    public function delete(\WP_Post $plugin, string $slot, ?int $screenshot_n): array|\WP_Error {
        $slot_id = $slot === 'screenshot' ? 'screenshot-' . (int) $screenshot_n : $slot;
        if (asset_slot_definition($slot_id) === null) {
            return new \WP_Error('asset_unknown_slot', __('Unknown slot.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        $error = $this->local()->delete($plugin, $slot_id);
        return $error ?? [ 'assets' => $this->describe($plugin) ];
    }

    /** @return array{mode: string, assets: array}|\WP_Error */
    public function move(\WP_Post $plugin, int $from, int $to): array|\WP_Error {
        if ($from < 1 || $to < 1 || $from === $to) {
            return new \WP_Error('asset_unknown_slot', __('Invalid screenshot numbers.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        $mode = $this->local()->move($plugin, $from, $to);
        return is_wp_error($mode) ? $mode : [ 'mode' => $mode, 'assets' => $this->describe($plugin) ];
    }
}
