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
     * changes there, what wordpress.org has and what the plugin page shows (wporg_view()), and
     * its Live Preview (blueprint_view()) — both read against the directory cache, the one read.
     */
    public function describe(\WP_Post $plugin): array {
        $id = (int) $plugin->ID;
        $manifest = read_asset_manifest($id);
        $state = is_wporg_plugin($plugin) ? get_wporg_assets_state($id) : null;
        $directory = $state === null ? null : get_wporg_directory($id);
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
        $out['wporg'] = $state === null ? null : $this->wporg_view($plugin, $state, $manifest, $directory);
        $out['blueprint'] = $state === null ? null : $this->blueprint_view($plugin, $state, $directory);
        return $out;
    }

    /**
     * The Live Preview as the tab shows it, the working copy applied: the blueprint in effect
     * (contents null: none, or wordpress.org's is over the limit) with the hash a JSON edit
     * builds on, the fields (null when there is none to write safely — blueprint_fields_of()), the warnings, what the current
     * release requires and which of it is not installed before the plugin, the directory's
     * listings of the plugins named (wporg_plugin_listings(); the plugin's own from its marker
     * and the directory check), the theme the preview starts with (theme_view()), the test link (the
     * blueprint as the plugin page will serve it) or why there is none, wordpress.org's file —
     * why it ignores it, its fields and what it lacks; whether its import has read it is the
     * slot's fact (wporg_view()) —, the counts of assets/blueprints/, and the last check of the
     * public preview.
     */
    private function blueprint_view(\WP_Post $plugin, array $state, array $directory): array {
        $own = (string) $plugin->post_name;
        $requirements = live_preview_requirements($plugin);
        $required = $requirements['slugs'] ?? [];
        $contents = effective_blueprint_contents($state);
        $served = $contents === null ? null : normalize_blueprint_like_wporg($contents, $own);
        $file = $state['blueprints']['file'];
        $blocked = match (true) {
            $contents === null => null,
            $served === null => 'invalid',
            in_array($directory['state'], [ 'closed', 'not_found' ], true) => 'not_downloadable',
            default => null,
        };
        $fields = blueprint_fields_of($contents, $own, $required);
        $missing = $contents === null ? [] : blueprint_missing_dependencies($contents, $own, $required);
        $file_fields = $file === null ? null : blueprint_fields_of($file['contents'], $own, $required);
        $file_missing = $file === null ? [] : live_preview_missing($state, $own, $required);
        // The plugins the section names, by slug — what the directory lists for them; the plugin
        // itself from its marker and the directory check (its state: ok, closed, not_found,
        // never), no request.
        $named = [ ...array_column($fields['plugins'] ?? [], 'slug'), ...array_column($file_fields['plugins'] ?? [], 'slug'), ...$required, ...$missing, ...$file_missing ];
        $listings = [
            ...wporg_plugin_listings(array_diff($named, [ $own ])),
            $own => [ 'name' => (string) $plugin->post_title, 'icon' => $this->get_best_icon_url($plugin), 'state' => (string) $directory['state'] ],
        ];
        return [
            'contents' => $contents,
            'hash' => md5($contents ?? ''),
            'fields' => $fields,
            'theme' => $this->theme_view($fields),
            'warnings' => blueprint_warnings($contents, $state['blueprints']['blueprint_files']),
            'required' => $requirements,
            'missing' => $missing,
            'listings' => $listings,
            'test' => [ 'url' => $served === null || $blocked !== null ? null : blueprint_playground_url(encode_blueprint_like_wporg($served)), 'blocked' => $blocked ],
            // wordpress.org's file as the tab shows it alone: why its import ignores it, its
            // fields and what it lacks.
            'on_wporg' => $file === null ? null : [
                ...$file,
                'ignored' => blueprint_ignored_reason($file),
                'problem' => $this->blueprint_problem($file),
                'fields' => $file_fields,
                'theme' => $this->theme_view($file_fields),
                'missing' => $file_missing,
            ],
            'blueprint_files' => $state['blueprints']['blueprint_files'],
            'other_files' => $state['blueprints']['other_files'],
            'preview' => get_wporg_live_preview_check((int) $plugin->ID),
        ];
    }

    /** What makes wordpress.org's blueprint.json unreadable to its import, in the words the editor's save would use (validate_blueprint_contents()); null when it reads it or did not get it (too large). */
    private function blueprint_problem(array $file): ?string {
        $valid = $file['contents'] === null ? true : validate_blueprint_contents($file['contents']);
        return is_wp_error($valid) ? $valid->get_error_message() : null;
    }

    /**
     * A change of a wordpress.org plugin's Live Preview: the request's values checked at the
     * boundary (the kind, the landing page's form, the theme's existence on wordpress.org), then
     * decided under the lock from the blueprint in effect then (decide_blueprint_change(), through
     * WporgAssetSync::change_blueprint()).
     *
     * @param array{change?:string, value?:string, contents?:string, expected?:string} $params
     * @return array{assets: array}|\WP_Error
     */
    public function change_blueprint(\WP_Post $plugin, array $params): array|\WP_Error {
        $kind = (string) ($params['change'] ?? '');
        $value = trim((string) ($params['value'] ?? ''));
        $own = (string) $plugin->post_name;
        $error = static fn(string $code, string $message, int $status): \WP_Error => new \WP_Error($code, $message, [ 'status' => $status ]);

        if (!in_array($kind, [ 'create', 'contents', 'landing_page', 'theme', 'plugin_add', 'plugin_remove', 'redirect_skip', 'delete' ], true)) {
            return $error('invalid_request', __('Unknown change of the Live Preview.', 'peak-publisher'), 400);
        }
        if ($kind === 'landing_page' && !str_starts_with($value, '/')) {
            return $error('blueprint_invalid_landing_page', __('The landing page is a path on the site and starts with /, for example /wp-admin/admin.php?page=my-plugin.', 'peak-publisher'), 400);
        }
        if ($kind === 'theme' && $value !== '') {
            if (!is_wordpress_org_slug($value)) {
                return $error('blueprint_theme_not_found', __('A theme slug is written in lowercase letters, digits and hyphens, as in its address on wordpress.org/themes/.', 'peak-publisher'), 404);
            }
            try {
                $exists = wporg_theme_exists($value);
            } catch (WporgSvnException $e) {
                return $e->to_wp_error();
            }
            if (!$exists) {
                return $error('blueprint_theme_not_found', sprintf(__('wordpress.org has no theme with the slug %s.', 'peak-publisher'), $value), 404);
            }
        }

        if ($kind === 'plugin_add') {
            if (!is_wordpress_org_slug($value)) {
                return $error('blueprint_plugin_not_found', __('A plugin slug is written in lowercase letters, digits and hyphens, as in its address on wordpress.org/plugins/.', 'peak-publisher'), 404);
            }
            try {
                $state = (string) (wporg_api_plugin_information([ $value ], wporg_api_fields([]))[$value]['state'] ?? 'not_found');
            } catch (WporgSvnException $e) {
                return $e->to_wp_error();
            }
            if ($state !== 'ok') {
                return $error('blueprint_plugin_not_found', $state === 'closed'
                    ? sprintf(__('wordpress.org has closed the plugin %s — Playground could not install it.', 'peak-publisher'), $value)
                    : sprintf(__('wordpress.org has no plugin with the slug %s.', 'peak-publisher'), $value), 404);
            }
        }

        $required = live_preview_requirements($plugin)['slugs'] ?? [];
        $result = $this->wporg()->change_blueprint($plugin, static fn(array $state): array|\WP_Error|null => decide_blueprint_change($kind, effective_blueprint_contents($state), [ ...$params, 'value' => $value ], $own, $required));
        return is_wp_error($result) ? $result : [ 'assets' => $this->describe($plugin) ];
    }

    /**
     * The theme the preview starts with, for its row: the blueprint's wordpress.org theme, or
     * Playground's default (wp_default_theme()) when the fields set none — with the directory's
     * listing (wporg_theme_listings()). Null without readable fields; the slug null for a theme
     * from elsewhere, or while the default is unknown.
     *
     * @return array{slug:?string, default:bool, listing:?array}|null
     */
    private function theme_view(?array $fields): ?array {
        if ($fields === null) {
            return null;
        }
        $default = $fields['theme']['slug'] === null && $fields['theme']['editable'];
        $slug = $default ? wp_default_theme() : $fields['theme']['slug'];
        return [ 'slug' => $slug, 'default' => $default, 'listing' => $slug === null ? null : (wporg_theme_listings([ $slug ])[$slug] ?? null) ];
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
     * revision of assets/ it holds — null before the first pull —, the files of assets/ that are
     * no slot here), whether the plugin page is behind the mirror (`import_waiting`,
     * assets_import_waiting()) and, for every slot with a mirror file, a pending entry or a file
     * the plugin page shows, the entry's action, whether it conflicts, wordpress.org's file (the
     * mirror entry with its URL — the conflict box and the state view show it — and whether the
     * import has read it, asset_imported()), the file the page shows (`shown`, assets_shown())
     * and whether the page passes the slot over for another file (`shadowed`,
     * assets_shadowed()): the slot's band is decided from these facts alone.
     */
    private function wporg_view(\WP_Post $plugin, array $state, array $manifest, array $directory): array {
        $pending = $state['pending'];
        $mirror = wporg_mirror_slots($manifest, $state);
        $conflicts = asset_conflicts($pending, $mirror);
        $stamp = $directory['stamp'];
        $shown = assets_shown($stamp);
        $shadowed = assets_shadowed($shown);
        $slots = [];
        foreach (array_unique([ ...array_keys($mirror), ...array_keys($pending), ...array_keys($shown) ]) as $slot_id) {
            $on_wporg = null;
            if ($slot_id === 'blueprint' && isset($mirror['blueprint'])) {
                $on_wporg = [ ...$mirror['blueprint'], 'filesize' => (int) $state['blueprints']['file']['filesize'] ];
            } elseif (isset($manifest[$slot_id])) {
                $on_wporg = [ ...$manifest[$slot_id], 'url' => $this->shipped_url($plugin, $manifest, (string) $slot_id) ];
            }
            $slots[$slot_id] = [
                'pending' => $pending[$slot_id]['action'] ?? null,
                'conflict' => in_array((string) $slot_id, $conflicts, true),
                'on_wporg' => $on_wporg === null ? null : [ ...$on_wporg, 'imported' => asset_imported($stamp, $on_wporg) ],
                'shown' => $shown[$slot_id] ?? null,
                'shadowed' => in_array((string) $slot_id, $shadowed, true),
            ];
        }
        return [
            'revision' => $state['revision'],
            'other_files' => $state['other_files'],
            'pending_count' => count($pending),
            'conflict_count' => count($conflicts),
            'import_waiting' => assets_import_waiting($stamp, $mirror),
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
