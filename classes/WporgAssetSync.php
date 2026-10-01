<?php

namespace Pblsh;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WporgOperations.php';


/**
 * The wordpress.org assets of a marker: keeps the mirror in step with SVN. The mirror holds the
 * slot winners under their SVN names; the other files of assets/ are counted, never copied.
 */
class WporgAssetSync {

    /** Pulls under the plugin's lock; false when a commit holds it (the mirror stays as it is). */
    public function pull(\WP_Post $marker): bool {
        raise_wporg_time_limit();
        return WporgOperations::with_plugin_lock($marker->post_name, 'assets_pull', (int) $marker->ID, function() use ($marker): bool {
            $this->pull_unlocked($marker);
            return true;
        }) !== false;
    }

    /**
     * One listing, then only the slots whose winner changed are downloaded into the mirror,
     * vanished slots are removed, and the banner color follows its A10 source. The manifest
     * records every slot that arrived; the anchor (revision, listed_at) moves only when all
     * did, so a failed download is retried by the next pull. The caller holds the lock.
     */
    public function pull_unlocked(\WP_Post $marker, ?WporgPluginSvnClient $client = null): void {
        $listing = WporgOperations::list_assets($marker->post_name, $client);
        $classified = classify_asset_listing($listing['entries'] ?? []);
        $remote = array_map(static fn(array $slot): array => $slot['winner'], $classified['slots']);
        $manifest = read_asset_manifest((int) $marker->ID);
        $diff = mirror_diff($manifest, $remote);

        ensure_plugin_assets_dir($marker);
        $dir = get_plugin_assets_dir($marker);
        foreach ($diff['remove'] as $slot_id) {
            $this->unlink($dir, $manifest[$slot_id]['filename']);
            unset($manifest[$slot_id]);
        }

        $complete = true;
        foreach ($diff['fetch'] as $slot_id) {
            $winner = $remote[$slot_id];
            $temp = $dir . '/.pull-' . wp_generate_password(8, false);
            try {
                WporgOperations::download_asset($marker->post_name, $winner['filename'], $temp, $winner['filesize'], $client);
            } catch (\Throwable $e) {
                // The slot keeps its previous mirror state.
                wporg_log_cache_error($marker, 'assets download ' . $winner['filename'], $e);
                $complete = false;
                continue;
            }
            // The old file goes first: on a case-insensitive file system a winner renamed only
            // in case is the same file.
            if (isset($manifest[$slot_id])) {
                $this->unlink($dir, $manifest[$slot_id]['filename']);
            }
            if (!rename($temp, $dir . '/' . $winner['filename'])) {
                $this->unlink($dir, basename($temp));
                unset($manifest[$slot_id]);
                $complete = false;
                continue;
            }
            $size = @getimagesize($dir . '/' . $winner['filename']);
            $manifest[$slot_id] = [ ...$winner, 'width' => $size === false ? null : (int) $size[0], 'height' => $size === false ? null : (int) $size[1] ];
        }
        write_asset_manifest((int) $marker->ID, $manifest);

        $state = get_wporg_assets_state((int) $marker->ID);
        if (!$this->refresh_banner_color($marker, $manifest, $classified['color_source'], $state['color_source'], $client)) {
            $complete = false;
        }
        if (!$complete) {
            return;
        }
        update_wporg_assets_state((int) $marker->ID, [
            ...$state,
            'revision' => $listing === null ? 0 : (int) $listing['revision'],
            'listed_at' => time(),
            'other_files' => $classified['other_files'],
            'color_source' => $classified['color_source'],
        ]);
    }

    /**
     * A10: the generated icon's color from the first valid banner file by name — computed again
     * only when that file or its revision changed, or no color is stored. The source may be a
     * file the mirror does not hold at that revision (a localized banner, a failed download);
     * then it is fetched into a temp file. False when the color could not be brought in step.
     */
    private function refresh_banner_color(\WP_Post $marker, array $manifest, ?array $source, ?array $previous, ?WporgPluginSvnClient $client): bool {
        $id = (int) $marker->ID;
        if ($source === null) {
            delete_post_meta($id, PBLSH_ASSETS_COLOR_META);
            return true;
        }
        if ($source === $previous && (string) get_post_meta($id, PBLSH_ASSETS_COLOR_META, true) !== '') {
            return true;
        }

        $dir = get_plugin_assets_dir($marker);
        $in_mirror = in_array($source, array_map('Pblsh\wporg_asset_base', $manifest), true);
        $file = $dir . '/' . ($in_mirror ? $source['filename'] : '.color-' . wp_generate_password(8, false));
        if (!$in_mirror) {
            try {
                WporgOperations::download_asset($marker->post_name, $source['filename'], $file, PBLSH_ASSET_LIMITS['banner'], $client);
            } catch (\Throwable $e) {
                wporg_log_cache_error($marker, 'assets color ' . $source['filename'], $e);
                return false;
            }
        }
        $color = get_image_average_color($file);
        if (!$in_mirror) {
            $this->unlink($dir, basename($file));
        }

        // A banner the image functions cannot read (an SVG) gives no color: the plain pattern.
        if (is_string($color) && $color !== '') {
            update_post_meta($id, PBLSH_ASSETS_COLOR_META, $color);
        } else {
            delete_post_meta($id, PBLSH_ASSETS_COLOR_META);
        }
        return true;
    }

    private function unlink(string $dir, string $filename): void {
        if (file_exists($dir . '/' . $filename)) {
            wp_delete_file($dir . '/' . $filename);
        }
    }
}
