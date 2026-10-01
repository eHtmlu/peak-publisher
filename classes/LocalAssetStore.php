<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The self-hosted store: the files under plugins/{slug}/assets/ are what sites get, the
 * manifest metas describe them. Every write takes effect at once. An entry's revision is the
 * time of its last change — the URL's cache-buster. Slot members are read from the directory
 * itself (classify_asset_listing()), so a .jpg beside a new .png or an uppercase name left by
 * an earlier version goes with the replace.
 */
class LocalAssetStore {

    /**
     * Writes the upload as the slot's canonical file and removes every other member. The
     * upload lands under a temp name first and the members go before it takes its name: on a
     * case-insensitive filesystem `Icon-128x128.PNG` and `icon-128x128.png` are one file, and
     * deleting the "other" member afterwards would delete the upload itself.
     */
    public function put(\WP_Post $plugin, string $slot_id, string $filename, string $local_path, array $facts): ?\WP_Error {
        ensure_plugin_assets_dir($plugin);
        $dir = get_plugin_assets_dir($plugin);

        $temp = $dir . '/.upload-' . wp_generate_password(8, false);
        $moved = is_uploaded_file($local_path) ? @move_uploaded_file($local_path, $temp) : false;
        if (!$moved && !get_wp_filesystem()->move($local_path, $temp, true)) {
            return new \WP_Error('asset_write_failed', __('Failed to save the uploaded file. Please check server permissions.', 'peak-publisher'), [ 'status' => 500 ]);
        }
        foreach ($this->members($dir, $slot_id) as $member) {
            $this->unlink($dir, $member);
        }
        if (!get_wp_filesystem()->move($temp, $dir . '/' . $filename, true)) {
            return new \WP_Error('asset_write_failed', __('Failed to save the uploaded file. Please check server permissions.', 'peak-publisher'), [ 'status' => 500 ]);
        }
        $this->write_slots($plugin, [ $slot_id => [
            'filename' => $filename,
            'revision' => time(),
            'resolution' => classify_asset_filename($filename)['resolution'],
            'filesize' => $facts['filesize'],
            'width' => $facts['width'],
            'height' => $facts['height'],
        ] ]);
        return null;
    }

    public function delete(\WP_Post $plugin, string $slot_id): ?\WP_Error {
        $dir = get_plugin_assets_dir($plugin);
        foreach ($this->members($dir, $slot_id) as $member) {
            $this->unlink($dir, $member);
        }
        $this->write_slots($plugin, [ $slot_id => null ]);
        return null;
    }

    /**
     * Moves screenshot $from to $to, or swaps the two when $to is occupied — decided here from
     * the manifest, never from what the client believed. A swap renames through a temp name;
     * both entries get a new revision, so no browser keeps the old picture.
     *
     * @return string|\WP_Error 'move' | 'swap'
     */
    public function move(\WP_Post $plugin, int $from, int $to): string|\WP_Error {
        $manifest = read_asset_manifest((int) $plugin->ID);
        $source = $manifest['screenshot-' . $from] ?? null;
        if ($source === null) {
            return new \WP_Error('asset_not_found', __('Source screenshot not found.', 'peak-publisher'), [ 'status' => 404 ]);
        }
        $target = $manifest['screenshot-' . $to] ?? null;
        $dir = get_plugin_assets_dir($plugin);
        $fs = get_wp_filesystem();
        $now = time();
        $source_name = asset_canonical_filename('screenshot-' . $to, classify_asset_filename($source['filename'])['ext']);

        // Variants beside the winners (an earlier .jpg next to the .png) go — the slots change hands.
        foreach ([ 'screenshot-' . $from => $source['filename'], 'screenshot-' . $to => $target['filename'] ?? null ] as $slot_id => $winner) {
            foreach ($this->members($dir, $slot_id) as $member) {
                if ($member !== $winner) {
                    $this->unlink($dir, $member);
                }
            }
        }

        if ($target === null) {
            if (!$fs->move($dir . '/' . $source['filename'], $dir . '/' . $source_name, true)) {
                return $this->move_failed();
            }
            $this->write_slots($plugin, [
                'screenshot-' . $from => null,
                'screenshot-' . $to => [ ...$source, 'filename' => $source_name, 'revision' => $now, 'resolution' => (string) $to ],
            ]);
            return 'move';
        }

        $target_name = asset_canonical_filename('screenshot-' . $from, classify_asset_filename($target['filename'])['ext']);
        $temp = $dir . '/.swap-' . wp_generate_password(8, false) . '-' . $source['filename'];
        if (!$fs->move($dir . '/' . $source['filename'], $temp, true)
            || !$fs->move($dir . '/' . $target['filename'], $dir . '/' . $target_name, true)
            || !$fs->move($temp, $dir . '/' . $source_name, true)) {
            return $this->move_failed();
        }
        $this->write_slots($plugin, [
            'screenshot-' . $from => [ ...$target, 'filename' => $target_name, 'revision' => $now, 'resolution' => (string) $from ],
            'screenshot-' . $to => [ ...$source, 'filename' => $source_name, 'revision' => $now, 'resolution' => (string) $to ],
        ]);
        return 'swap';
    }

    /** The slot's member files as they lie in the directory. */
    private function members(string $dir, string $slot_id): array {
        return classify_asset_listing(list_asset_directory($dir))['slots'][$slot_id]['members'] ?? [];
    }

    /** Replaces entries of the manifest (null removes); a banner change recomputes the generated icon's color. */
    private function write_slots(\WP_Post $plugin, array $changes): void {
        $manifest = read_asset_manifest((int) $plugin->ID);
        foreach ($changes as $slot_id => $entry) {
            if ($entry === null) {
                unset($manifest[$slot_id]);
            } else {
                $manifest[$slot_id] = $entry;
            }
        }
        write_asset_manifest((int) $plugin->ID, $manifest);
        foreach (array_keys($changes) as $slot_id) {
            if (asset_slot_definition($slot_id)['type'] === 'banner') {
                $this->refresh_banner_color($plugin);
                break;
            }
        }
    }

    /** A10 over the directory: the first valid banner file by name. */
    private function refresh_banner_color(\WP_Post $plugin): void {
        $dir = get_plugin_assets_dir($plugin);
        $source = select_banner_color_source(list_asset_directory($dir));
        $color = $source === null ? false : get_image_average_color($dir . '/' . $source['filename']);
        if (is_string($color) && $color !== '') {
            update_post_meta((int) $plugin->ID, PBLSH_ASSETS_COLOR_META, $color);
        } else {
            delete_post_meta((int) $plugin->ID, PBLSH_ASSETS_COLOR_META);
        }
    }

    private function unlink(string $dir, string $filename): void {
        if (file_exists($dir . '/' . $filename)) {
            get_wp_filesystem()->delete($dir . '/' . $filename, false);
        }
    }

    private function move_failed(): \WP_Error {
        return new \WP_Error('asset_write_failed', __('Failed to move the screenshot file.', 'peak-publisher'), [ 'status' => 500 ]);
    }
}
