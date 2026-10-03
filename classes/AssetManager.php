<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The facade over a plugin's assets on both channels. Reading is the same for both: the
 * shipped layer — the self-hosted directory, or the mirror of a wordpress.org plugin's SVN
 * assets/ that WporgAssetSync keeps in step — described by the manifest
 * (read_asset_manifest()). Writing goes to the channel's store: self-hosted at once
 * (LocalAssetStore), wordpress.org into the working copy and from there into one commit
 * (WporgAssetSync). The list, the editor header and the public API read the shipped layer
 * only; the assets tab (describe()) shows the working copy over it.
 */
class AssetManager {
    private static ?self $instance = null;
    private ?LocalAssetStore $local = null;
    private ?WporgAssetSync $wporg = null;

    private function __construct() {}

    public static function init(): self {
        return self::$instance ??= new self();
    }

    private function local(): LocalAssetStore {
        require_once __DIR__ . '/LocalAssetStore.php';
        return $this->local ??= new LocalAssetStore();
    }

    private function wporg(): WporgAssetSync {
        require_once __DIR__ . '/WporgAssetSync.php';
        return $this->wporg ??= new WporgAssetSync();
    }

    /**
     * The editor's view (REST GET): every fixed slot (entry or null) and the screenshots by
     * number as they will be — a wordpress.org plugin's working copy applied over its mirror —
     * and the captions from the readme of the release sites receive (get_screenshot_captions()).
     * A wordpress.org plugin adds where its mirror stands and, per slot, what the working copy
     * changes there and what wordpress.org has (wporg_view()).
     */
    public function describe(\WP_Post $plugin): array {
        $id = (int) $plugin->ID;
        $manifest = read_asset_manifest($id);
        $state = is_wporg_plugin($plugin) ? get_wporg_assets_state($id) : null;
        $pending = $state['pending'] ?? [];
        $out = [];
        foreach (array_keys(get_asset_slots()) as $slot_id) {
            if ($slot_id !== 'screenshot') {
                $out[$slot_id] = $this->slot_view($plugin, $slot_id, $pending[$slot_id] ?? null, $manifest);
            }
        }
        $out['screenshots'] = $this->screenshot_views($plugin, $pending, $manifest);
        $captions = get_screenshot_captions($plugin);
        $out['screenshot_captions'] = (object) $captions['captions'];
        $out['captions_source'] = $captions['source'];
        $out['wporg'] = $state === null ? null : $this->wporg_view($plugin, $state, $manifest);
        return $out;
    }

    /** The screenshots as views, by number. */
    private function screenshot_views(\WP_Post $plugin, array $pending, array $manifest): array {
        $views = [];
        foreach (array_unique([ ...array_keys($manifest), ...array_keys($pending) ]) as $slot_id) {
            if (preg_match('/^screenshot-(\d+)$/', (string) $slot_id, $m)) {
                $view = $this->slot_view($plugin, (string) $slot_id, $pending[$slot_id] ?? null, $manifest);
                if ($view !== null) {
                    $views[(int) $m[1]] = [ ...$view, 'screenshot_n' => (int) $m[1] ];
                }
            }
        }
        ksort($views);
        return array_values($views);
    }

    /**
     * A slot as the tab shows it: the shipped file, or with a pending entry what the entry
     * brings — the uploaded file of a put, the mirror file of a copy, nothing for a delete. A
     * pending file has no revision yet: it is not on wordpress.org.
     */
    private function slot_view(\WP_Post $plugin, string $slot_id, ?array $entry, array $manifest): ?array {
        if ($entry === null) {
            return isset($manifest[$slot_id]) ? $this->entry_view($plugin, $slot_id, $manifest[$slot_id], 'shipped') : null;
        }
        if ($entry['action'] === 'delete') {
            return null;
        }
        $name = asset_canonical_filename($slot_id, $entry['ext']);
        $view = [ 'filename' => $name, 'revision' => null, 'resolution' => classify_asset_filename($name)['resolution'], 'filesize' => (int) $entry['filesize'], 'width' => $entry['width'], 'height' => $entry['height'] ];
        [ $layer, $file, $rev ] = $entry['action'] === 'put'
            ? [ 'pending', $entry['file'], (int) $entry['at'] ]
            : [ 'shipped', $entry['from']['filename'], (int) $entry['from']['revision'] ];
        return [
            ...$view,
            'url' => get_plugin_assets_url($plugin, $layer) . '/' . rawurlencode($file) . '?rev=' . $rev,
            'warnings' => asset_dimension_warnings($view, asset_slot_definition($slot_id)),
        ];
    }

    /**
     * A wordpress.org plugin's mirror and working copy for the tab: where the mirror stands (the
     * revision of assets/ it holds, when it was last confirmed, the files of assets/ that are no
     * slot here) and, for every slot with a mirror file or a pending entry, the entry's action,
     * whether it conflicts, and wordpress.org's file (the mirror entry with its URL — the
     * conflict box and the state view show it).
     */
    private function wporg_view(\WP_Post $plugin, array $state, array $manifest): array {
        $pending = $state['pending'];
        $conflicts = asset_conflicts($pending, $manifest);
        $slots = [];
        foreach (array_unique([ ...array_keys($manifest), ...array_keys($pending) ]) as $slot_id) {
            $slots[$slot_id] = [
                'pending' => $pending[$slot_id]['action'] ?? null,
                'conflict' => in_array((string) $slot_id, $conflicts, true),
                'on_wporg' => isset($manifest[$slot_id])
                    ? [ ...$manifest[$slot_id], 'url' => $this->shipped_url($plugin, $manifest, (string) $slot_id) ]
                    : null,
            ];
        }
        return [
            'revision' => $state['revision'],
            'listed_at' => $state['listed_at'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $state['listed_at']) : null,
            'other_files' => $state['other_files'],
            'pending_count' => count($pending),
            'conflict_count' => count($conflicts),
            'slots' => (object) $slots,
        ];
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

    /**
     * A file into a slot, validated the same on both channels: self-hosted at once, wordpress.org
     * into the working copy. A new screenshot without a number takes the next free position.
     *
     * @return array{assets: array, warnings: array}|\WP_Error
     */
    public function upload(\WP_Post $plugin, string $slot, ?int $screenshot_n, array $file_data): array|\WP_Error {
        $id = (int) $plugin->ID;
        $taken = is_wporg_plugin($plugin)
            ? effective_asset_slots(get_wporg_assets_state($id)['pending'], read_asset_manifest($id))
            : array_keys(read_asset_manifest($id));
        $slot_id = $slot === 'screenshot' ? 'screenshot-' . ($screenshot_n ?? next_screenshot_number($taken)) : $slot;
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
        $result = is_wporg_plugin($plugin)
            ? $this->wporg()->change($plugin, [ 'action' => 'put', 'slot' => $slot_id, 'ext' => $facts['ext'], 'filesize' => $facts['filesize'], 'width' => $facts['width'], 'height' => $facts['height'] ], $tmp_path)
            : $this->local()->put($plugin, $slot_id, asset_canonical_filename($slot_id, $facts['ext']), $tmp_path, $facts);
        return is_wp_error($result) ? $result : [ 'assets' => $this->describe($plugin), 'warnings' => $facts['warnings'] ];
    }

    /** @return array{assets: array}|\WP_Error */
    public function delete(\WP_Post $plugin, string $slot, ?int $screenshot_n): array|\WP_Error {
        $slot_id = $slot === 'screenshot' ? 'screenshot-' . (int) $screenshot_n : $slot;
        if (asset_slot_definition($slot_id) === null) {
            return new \WP_Error('asset_unknown_slot', __('Unknown slot.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        $result = is_wporg_plugin($plugin)
            ? $this->wporg()->change($plugin, [ 'action' => 'delete', 'slot' => $slot_id ])
            : $this->local()->delete($plugin, $slot_id);
        return is_wp_error($result) ? $result : [ 'assets' => $this->describe($plugin) ];
    }

    /**
     * A screenshot to another position; onto an occupied one the two swap — the store decides.
     *
     * @return array{mode: string, assets: array}|\WP_Error
     */
    public function move(\WP_Post $plugin, int $from, int $to): array|\WP_Error {
        if ($from < 1 || $to < 1 || $from === $to) {
            return new \WP_Error('asset_unknown_slot', __('Invalid screenshot numbers.', 'peak-publisher'), [ 'status' => 400 ]);
        }
        if (is_wporg_plugin($plugin)) {
            $change = $this->wporg()->change($plugin, [ 'action' => 'move', 'from' => $from, 'to' => $to ]);
            $mode = is_wp_error($change) ? $change : $change['mode'];
        } else {
            $mode = $this->local()->move($plugin, $from, $to);
        }
        return is_wp_error($mode) ? $mode : [ 'mode' => $mode, 'assets' => $this->describe($plugin) ];
    }

    /**
     * The working copy of a wordpress.org plugin as one commit with the account the caller
     * resolved.
     *
     * @return array{revision: ?int, committed: bool, assets: array}|\WP_Error
     */
    public function commit(\WP_Post $plugin, string $username): array|\WP_Error {
        $result = $this->wporg()->commit($plugin, $username);
        return is_wp_error($result) ? $result : [ ...$result, 'assets' => $this->describe($plugin) ];
    }

    /** @return array{assets: array}|\WP_Error */
    public function discard(\WP_Post $plugin): array|\WP_Error {
        $error = $this->wporg()->discard($plugin);
        return $error ?? [ 'assets' => $this->describe($plugin) ];
    }

    /** @return array{assets: array}|\WP_Error */
    public function resolve(\WP_Post $plugin, string $slot_id, string $keep): array|\WP_Error {
        $error = $this->wporg()->resolve($plugin, $slot_id, $keep);
        return $error ?? [ 'assets' => $this->describe($plugin) ];
    }
}
