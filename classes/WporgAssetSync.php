<?php

namespace Pblsh;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WporgOperations.php';


/**
 * The wordpress.org assets of a marker: keeps the mirror in step with SVN, records changes in
 * the working copy and turns it into one commit. The mirror holds the slot winners under their
 * SVN names; the other files of assets/ are counted, never copied. Every write runs under the
 * plugin's commit lock.
 */
class WporgAssetSync {

    /**
     * Brings the mirror in step with SVN on a read of the marker: pulls when the mirror is
     * behind (needs_pull()), else records that it was just confirmed against SVN (listed_at —
     * the bar's "checked …"). The confirmation is written under the lock like every write of
     * the state, and skipped while a commit or a change holds it: a timestamp can wait.
     */
    public function refresh(\WP_Post $marker, int $assets_revision): void {
        if ($this->needs_pull($marker, $assets_revision)) {
            $this->pull($marker);
            return;
        }
        WporgOperations::try_under_plugin_lock($marker->post_name, 'assets_pull', (int) $marker->ID, static function() use ($marker): bool {
            update_wporg_assets_state((int) $marker->ID, [ ...get_wporg_assets_state((int) $marker->ID), 'listed_at' => time() ]);
            return true;
        });
    }

    /**
     * Whether the mirror needs a pull: it is behind SVN (its anchor is not the revision of
     * assets/, 0 when there is no directory) or behind itself (a file the manifest names is
     * gone from the disk — a move without the uploads, a restore). Asked on every read, so a
     * lost file is back with the next one.
     */
    private function needs_pull(\WP_Post $marker, int $assets_revision): bool {
        if (get_wporg_assets_state((int) $marker->ID)['revision'] !== $assets_revision) {
            return true;
        }
        $dir = get_plugin_assets_dir($marker);
        foreach (read_asset_manifest((int) $marker->ID) as $entry) {
            if (!file_exists($dir . '/' . $entry['filename'])) {
                return true;
            }
        }
        return false;
    }

    /** Pulls under the plugin's lock; false when a commit holds it (the mirror stays as it is). */
    public function pull(\WP_Post $marker): bool {
        raise_wporg_time_limit();
        return WporgOperations::try_under_plugin_lock($marker->post_name, 'assets_pull', (int) $marker->ID, function() use ($marker): bool {
            $this->pull_unlocked($marker);
            return true;
        }) !== false;
    }

    /**
     * One listing, then only the slots whose winner changed are downloaded into the mirror —
     * and the slots whose file the disk lost (a move without the uploads, a restore): the
     * mirror heals itself —, vanished slots are removed, and the banner color follows its A10
     * source. A copy entry of the working copy whose source this pull replaces becomes a put of
     * the old file first, so it keeps its meaning ("the picture that was in slot 3"); when the
     * mirror file is gone, the SVN history still has it. The manifest records every slot
     * that arrived; the anchor (revision, listed_at) moves only when all did, so a failed
     * download is retried by the next pull. The caller holds the lock.
     *
     * @return array{listing: ?array, complete: bool} listing = list_assets() as read
     */
    public function pull_unlocked(\WP_Post $marker, ?WporgPluginSvnClient $client = null): array {
        $listing = WporgOperations::list_assets($marker->post_name, $client);
        $classified = classify_asset_listing($listing['entries'] ?? []);
        $remote = array_map(static fn(array $slot): array => $slot['winner'], $classified['slots']);
        $manifest = read_asset_manifest((int) $marker->ID);
        $diff = mirror_diff($manifest, $remote);

        ensure_plugin_assets_dir($marker);
        $dir = get_plugin_assets_dir($marker);
        // The diff knows names and revisions, not the disk: a mirror file lost there is fetched
        // again while SVN still fills its slot.
        foreach ($manifest as $slot_id => $entry) {
            if (isset($remote[$slot_id]) && !in_array((string) $slot_id, $diff['fetch'], true) && !file_exists($dir . '/' . $entry['filename'])) {
                $diff['fetch'][] = (string) $slot_id;
            }
        }
        if (!$this->secure_copy_sources($marker, $remote, $client)) {
            return [ 'listing' => $listing, 'complete' => false ];
        }
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
            return [ 'listing' => $listing, 'complete' => false ];
        }
        update_wporg_assets_state((int) $marker->ID, [
            ...$state,
            'revision' => $listing === null ? 0 : (int) $listing['revision'],
            'listed_at' => time(),
            'other_files' => $classified['other_files'],
            'color_source' => $classified['color_source'],
        ]);
        return [ 'listing' => $listing, 'complete' => true ];
    }

    /**
     * Copy entries whose source the pull is about to replace or remove become puts of the
     * source's current mirror file (copy_sources_to_secure()) — or, when the disk lost that
     * file, of the file as the SVN history holds it at the entry's revision. False when a file
     * could not be secured (a transport failure, logged) — then the pull must not touch the
     * mirror, and the next one tries again.
     */
    private function secure_copy_sources(\WP_Post $marker, array $remote, ?WporgPluginSvnClient $client): bool {
        $state = get_wporg_assets_state((int) $marker->ID);
        $secure = copy_sources_to_secure($state['pending'], $remote);
        if ($secure === []) {
            return true;
        }
        ensure_plugin_assets_dir($marker, 'pending');
        $mirror_dir = get_plugin_assets_dir($marker);
        $pending_dir = get_plugin_assets_dir($marker, 'pending');
        foreach ($secure as $slot_id) {
            $entry = $state['pending'][$slot_id];
            $file = asset_canonical_filename($slot_id, $entry['ext']);
            $source = $mirror_dir . '/' . $entry['from']['filename'];
            $failure = null;
            try {
                if (file_exists($source)) {
                    $secured = get_wp_filesystem()->copy($source, $pending_dir . '/' . $file, true);
                } else {
                    WporgOperations::download_asset($marker->post_name, $entry['from']['filename'], $pending_dir . '/' . $file, (int) $entry['filesize'], $client, (int) $entry['from']['revision']);
                    $secured = true;
                }
            } catch (\Throwable $failure) {
                $secured = false;
            }
            if (!$secured) {
                wporg_log_cache_error($marker, 'assets secure ' . $slot_id, $failure ?? new \RuntimeException('The mirror file could not be copied into the working copy.'));
                update_wporg_assets_state((int) $marker->ID, $state);
                return false;
            }
            unset($entry['from']);
            $state['pending'][$slot_id] = [ ...$entry, 'action' => 'put', 'file' => $file ];
        }
        update_wporg_assets_state((int) $marker->ID, $state);
        return true;
    }

    /**
     * Records one change in the working copy (apply_asset_change(), which also decides between
     * move and swap) and does its file work in pending/assets/: the uploaded file of a put under
     * its canonical name, the removed files, the renames of uploads that moved.
     *
     * @return array{mode: ?string}|\WP_Error
     */
    public function change(\WP_Post $marker, array $change, ?string $local_path = null): array|\WP_Error {
        return $this->locked($marker, function() use ($marker, $change, $local_path): array|\WP_Error {
            $id = (int) $marker->ID;
            $state = get_wporg_assets_state($id);
            // The working copy builds on the mirror: before the first pull every entry would
            // record an empty base, and that pull would then report a conflict on each of them.
            if ($state['revision'] === null) {
                return new \WP_Error('wporg_assets_not_synced', __('The assets have not been read from wordpress.org yet, so they cannot be changed. Reload to try again.', 'peak-publisher'), [ 'status' => 409 ]);
            }
            $mirror = read_asset_manifest($id);
            if ($change['action'] === 'move' && effective_asset_entry($state['pending'], $mirror, 'screenshot-' . (int) $change['from']) === null) {
                return new \WP_Error('asset_not_found', __('Source screenshot not found.', 'peak-publisher'), [ 'status' => 404 ]);
            }
            $user = wp_get_current_user();
            $result = apply_asset_change($state['pending'], $mirror, [ ...$change, 'at' => time(), 'user' => [ 'id' => (int) $user->ID, 'login' => (string) $user->user_login ] ]);

            ensure_plugin_assets_dir($marker, 'pending');
            $dir = get_plugin_assets_dir($marker, 'pending');
            $fs = get_wp_filesystem();
            if ($change['action'] === 'put') {
                $target = $dir . '/' . $result['pending'][$change['slot']]['file'];
                $moved = is_uploaded_file((string) $local_path) ? @move_uploaded_file((string) $local_path, $target) : false;
                if (!$moved && !$fs->move((string) $local_path, $target, true)) {
                    return new \WP_Error('asset_write_failed', __('Failed to save the uploaded file. Please check server permissions.', 'peak-publisher'), [ 'status' => 500 ]);
                }
            }
            foreach ($result['removes'] as $file) {
                $this->unlink($dir, $file);
            }
            // The renames are a set — a swap of two uploads crosses — so every file takes a temp name first.
            $temps = [];
            foreach ($result['renames'] as [ $from, $to ]) {
                $temps[$to] = $dir . '/.move-' . wp_generate_password(8, false);
                if (!$fs->move($dir . '/' . $from, $temps[$to], true)) {
                    return $this->move_failed();
                }
            }
            foreach ($temps as $to => $temp) {
                if (!$fs->move($temp, $dir . '/' . $to, true)) {
                    return $this->move_failed();
                }
            }
            update_wporg_assets_state($id, [ ...$state, 'pending' => $result['pending'] ]);
            return [ 'mode' => $result['mode'] ];
        });
    }

    /** Drops every pending change at once — no download: the mirror is the state on wordpress.org. */
    public function discard(\WP_Post $marker): ?\WP_Error {
        $result = $this->locked($marker, function() use ($marker): array {
            get_wp_filesystem()->delete(get_plugin_assets_dir($marker, 'pending'), true);
            update_wporg_assets_state((int) $marker->ID, [ ...get_wporg_assets_state((int) $marker->ID), 'pending' => [] ]);
            return [];
        });
        return is_wp_error($result) ? $result : null;
    }

    /**
     * A conflict decided, or a pending change taken back: theirs drops the entry; mine rebases it
     * on the current mirror state, so it goes out with the next commit. A delete whose slot is
     * empty on wordpress.org by now has nothing left to delete and is dropped either way.
     */
    public function resolve(\WP_Post $marker, string $slot_id, string $keep): ?\WP_Error {
        $result = $this->locked($marker, function() use ($marker, $slot_id, $keep): array|\WP_Error {
            $id = (int) $marker->ID;
            $state = get_wporg_assets_state($id);
            $entry = $state['pending'][$slot_id] ?? null;
            if ($entry === null) {
                return new \WP_Error('asset_not_found', __('There is no pending change for this asset any more.', 'peak-publisher'), [ 'status' => 404 ]);
            }
            $base = wporg_asset_base(read_asset_manifest($id)[$slot_id] ?? null);
            if ($keep === 'theirs' || ($entry['action'] === 'delete' && $base === null)) {
                if ($entry['action'] === 'put') {
                    $this->unlink(get_plugin_assets_dir($marker, 'pending'), $entry['file']);
                }
                unset($state['pending'][$slot_id]);
            } else {
                $state['pending'][$slot_id]['base'] = $base;
            }
            update_wporg_assets_state($id, $state);
            return [];
        });
        return is_wp_error($result) ? $result : null;
    }

    /**
     * The working copy as one commit (commit_files(), operation `assets`). Under the lock the
     * mirror is pulled fresh with the commit's client — an incomplete pull or a conflict stops
     * the commit before anything is written — and the plan is built from that listing. Still
     * under the lock, the mirror is then written forward locally (apply_commit_locally()); the
     * marker cache advances when only assets/ changed since its revision.
     *
     * @return array{revision: ?int, committed: bool}|\WP_Error
     */
    public function commit(\WP_Post $marker, string $username): array|\WP_Error {
        $id = (int) $marker->ID;
        $outcome = [];
        try {
            $result = WporgOperations::commit_files(
                $marker,
                $username,
                'assets',
                function(WporgPluginSvnClient $client, int $base_revision) use ($marker, $id, &$outcome): array {
                    $pulled = $this->pull_unlocked($marker, $client);
                    if (!$pulled['complete']) {
                        throw new WporgSvnException('wporg_assets_pull_incomplete', __('The assets could not be read from wordpress.org completely, so nothing was committed. Try again.', 'peak-publisher'), 502);
                    }
                    $state = get_wporg_assets_state($id);
                    if (asset_conflicts($state['pending'], read_asset_manifest($id)) !== []) {
                        throw new WporgSvnException('wporg_assets_conflict', __('Some of these assets were changed on wordpress.org as well. Decide for each marked asset, then commit again.', 'peak-publisher'), 409);
                    }
                    $plan = build_asset_commit_plan($marker->post_name, $state['pending'], $pulled['listing'], $base_revision, get_plugin_assets_dir($marker, 'pending'));
                    $outcome = [ 'listing' => $pulled['listing'], 'plan' => $plan, 'pending' => $state['pending'] ];
                    return $plan;
                },
                function(int $revision) use ($marker, &$outcome): void {
                    $this->apply_commit_locally($marker, $revision, $outcome);
                }
            );
        } catch (WporgSvnException $e) {
            return $e->to_wp_error();
        }
        if (empty($result['committed'])) {
            return [ 'revision' => null, 'committed' => false ];
        }

        try {
            $only_assets = WporgOperations::plugin_changed_only_in($marker->post_name, [ 'assets' ], (int) $result['base_revision']);
        } catch (\Throwable $e) {
            $only_assets = false;
        }
        if ($only_assets) {
            advance_wporg_plugin_cache($id, (int) $result['base_revision'], (int) $result['revision'], []);
        } else {
            mark_wporg_plugin_cache_stale($id);
        }
        return [ 'revision' => (int) $result['revision'], 'committed' => true ];
    }

    /**
     * The mirror after the commit, written from the working copy without a download: the new
     * slot files take temp names first (a swap crosses; a copy's source is still in place), then
     * the slots' old files go, then the temps take their canonical names. The working copy is
     * emptied — its changes are on wordpress.org now. The anchor moves to the commit's revision
     * only when every file arrived; otherwise the next read pulls what is missing.
     */
    private function apply_commit_locally(\WP_Post $marker, int $revision, array $outcome): void {
        $id = (int) $marker->ID;
        $mirror_dir = get_plugin_assets_dir($marker);
        $pending_dir = get_plugin_assets_dir($marker, 'pending');
        $fs = get_wp_filesystem();
        $complete = true;
        try {
            $manifest = read_asset_manifest($id);
            $staged = [];
            foreach ($outcome['pending'] as $slot_id => $entry) {
                if ($entry['action'] === 'delete') {
                    continue;
                }
                $temp = $mirror_dir . '/.commit-' . wp_generate_password(8, false);
                $ok = $entry['action'] === 'put'
                    ? $fs->move($pending_dir . '/' . $entry['file'], $temp, true)
                    : $fs->copy($mirror_dir . '/' . $entry['from']['filename'], $temp, true);
                if ($ok) {
                    $staged[$slot_id] = [ $temp, $entry ];
                } else {
                    $complete = false;
                }
            }
            foreach (array_keys($outcome['pending']) as $slot_id) {
                if (isset($manifest[$slot_id])) {
                    $this->unlink($mirror_dir, $manifest[$slot_id]['filename']);
                    unset($manifest[$slot_id]);
                }
            }
            $sizes = [];
            foreach ($staged as $slot_id => [ $temp, $entry ]) {
                $name = asset_canonical_filename((string) $slot_id, $entry['ext']);
                if (!$fs->move($temp, $mirror_dir . '/' . $name, true)) {
                    $this->unlink($mirror_dir, basename($temp));
                    $complete = false;
                    continue;
                }
                $sizes[$name] = (int) filesize($mirror_dir . '/' . $name);
                $manifest[$slot_id] = [ 'filename' => $name, 'revision' => $revision, 'resolution' => classify_asset_filename($name)['resolution'], 'filesize' => $sizes[$name], 'width' => $entry['width'], 'height' => $entry['height'] ];
            }
            write_asset_manifest($id, $manifest);

            $classified = classify_asset_listing(asset_listing_after_plan($outcome['listing']['entries'] ?? [], $outcome['plan'], $revision, $sizes));
            $state = get_wporg_assets_state($id);
            if (!$this->refresh_banner_color($marker, $manifest, $classified['color_source'], $state['color_source'], null)) {
                $complete = false;
            }
        } catch (\Throwable $e) {
            wporg_log_cache_error($marker, 'assets after commit', $e);
            $complete = false;
        }

        $fs->delete($pending_dir, true);
        $state = get_wporg_assets_state($id);
        update_wporg_assets_state($id, $complete ? [
            ...$state,
            'revision' => $revision,
            'listed_at' => time(),
            'other_files' => $classified['other_files'],
            'color_source' => $classified['color_source'],
            'pending' => [],
        ] : [ ...$state, 'pending' => [] ]);
    }

    /** Runs a change of the working copy under the commit lock; while a commit, a pull or another change holds it, the change is refused (deploy_in_progress, wporg_plugin_busy). */
    private function locked(\WP_Post $marker, callable $fn): array|\WP_Error {
        try {
            return WporgOperations::under_plugin_lock($marker->post_name, 'assets_change', (int) $marker->ID, $fn);
        } catch (WporgSvnException $e) {
            return $e->to_wp_error();
        }
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

    private function move_failed(): \WP_Error {
        return new \WP_Error('asset_write_failed', __('Failed to move the screenshot file.', 'peak-publisher'), [ 'status' => 500 ]);
    }

    private function unlink(string $dir, string $filename): void {
        if (file_exists($dir . '/' . $filename)) {
            wp_delete_file($dir . '/' . $filename);
        }
    }
}
