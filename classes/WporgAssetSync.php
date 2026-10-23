<?php

namespace Pblsh;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages reach the REST response as JSON and the log; nothing here renders HTML.

require_once __DIR__ . '/WporgOperations.php';


/**
 * The wordpress.org assets of a marker: keeps the mirror in step with SVN, records changes in
 * the working copy and turns it into one commit. The mirror holds the slot winners under their
 * SVN names and the Live Preview's blueprint.json in the state; the other files of assets/
 * and assets/blueprints/ are counted, never copied. Every write runs under the plugin's commit
 * lock.
 */
class WporgAssetSync {

    /**
     * Brings the mirror in step with SVN when the marker is refreshed (the directory said the
     * plugin changed, the editor's Refresh, an upload is prepared): pulls when the mirror is
     * behind SVN or lost a file. Answers what pull_unlocked() does about the mirror: whether
     * the pull changed it, and whether it arrived completely — one that did not is caught up
     * by the next refresh. The caller holds the lock (refresh_wporg_plugin_cache()).
     *
     * @return array{changed: bool, complete: bool}
     */
    public function refresh_unlocked(\WP_Post $marker, int $assets_revision): array {
        if (!$this->is_behind_svn($marker, $assets_revision) && !$this->has_lost_files($marker)) {
            return [ 'changed' => false, 'complete' => true ];
        }
        raise_wporg_time_limit();
        $pulled = $this->pull_unlocked($marker);
        return [ 'changed' => $pulled['changed'], 'complete' => $pulled['complete'] ];
    }

    /**
     * Fetches a mirror file the disk lost (a move without the uploads, a restore) — what
     * opening a plugin checks, locally; the one reason a read asks SVN at all.
     */
    public function recover(\WP_Post $marker): void {
        if ($this->has_lost_files($marker)) {
            $this->pull($marker);
        }
    }

    /** The anchor is not the revision of assets/ (0 when there is no directory). */
    private function is_behind_svn(\WP_Post $marker, int $assets_revision): bool {
        return get_wporg_assets_state((int) $marker->ID)['revision'] !== $assets_revision;
    }

    /** A file the manifest names is gone from the disk. */
    private function has_lost_files(\WP_Post $marker): bool {
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
     * mirror file is gone, the SVN history still has it. assets/blueprints/ is read when its
     * revision moved (pull_blueprints()). The manifest records every slot that arrived; the
     * anchor (revision) moves only when all did, so a failed download is retried by the next
     * pull. The caller holds the lock.
     *
     * @return array{listing: ?array, complete: bool, changed: bool} listing = list_assets()
     *         as read; changed = wordpress.org's winners or blueprint differ from the mirror's
     *         (a file fetched again because the disk lost it is no change)
     */
    public function pull_unlocked(\WP_Post $marker, ?WporgPluginSvnClient $client = null): array {
        $listing = WporgOperations::list_assets($marker->post_name, $client);
        $classified = classify_asset_listing($listing['entries'] ?? []);
        $remote = array_map(static fn(array $slot): array => $slot['winner'], $classified['slots']);
        $manifest = read_asset_manifest((int) $marker->ID);
        $stored = get_wporg_assets_state((int) $marker->ID);
        $rejected = rejected_assets_kept($stored['rejected'], $remote);
        $diff = mirror_diff($manifest, $remote, $rejected);
        $blueprints = $this->pull_blueprints($marker, $listing, $stored['blueprints'], $client);
        $changed = $diff['fetch'] !== [] || $diff['remove'] !== [] || $blueprints['changed'];

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
            return [ 'listing' => $listing, 'complete' => false, 'changed' => $changed ];
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
                if ($e instanceof WporgSvnException && in_array($e->get_error_code(), [ 'asset_svg_active', 'asset_svg_unchecked' ], true)) {
                    // The mirror refuses the file (check_svg_file()): the slot's old file goes —
                    // SVN no longer has it —, the refusal is kept with the winner, so the pull
                    // stops asking for this revision and the tab can say what stands there.
                    if (isset($manifest[$slot_id])) {
                        $this->unlink($dir, $manifest[$slot_id]['filename']);
                        unset($manifest[$slot_id]);
                    }
                    $rejected[$slot_id] = [ ...$winner, 'code' => $e->get_error_code() ];
                    continue;
                }
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
            if (!rename($temp, $dir . '/' . $winner['filename'])) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- within the plugin's own uploads directory, atomic on the same file system
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
        $state['rejected'] = $rejected;
        // The blueprints mirror has its own anchor (its directory's revision): what arrived stays.
        if ($blueprints['complete']) {
            $state['blueprints'] = $blueprints['mirror'];
        }
        if (!$this->refresh_banner_color($marker, $manifest, $classified['color_source'], $state['color_source'], $client)) {
            $complete = false;
        }
        if (!$complete || !$blueprints['complete']) {
            update_wporg_assets_state((int) $marker->ID, $state);
            return [ 'listing' => $listing, 'complete' => false, 'changed' => $changed ];
        }
        update_wporg_assets_state((int) $marker->ID, [
            ...$state,
            'revision' => $listing === null ? 0 : (int) $listing['revision'],
            'other_files' => $classified['other_files'],
            'color_source' => $classified['color_source'],
        ]);
        return [ 'listing' => $listing, 'complete' => true, 'changed' => $changed ];
    }

    /**
     * The Live Preview's part of a pull: assets/blueprints/ is listed only when its revision in
     * the assets listing moved, and blueprint.json read only when its own revision did — over
     * wordpress.org's limit it is not read at all (the import ignores it). No directory, no
     * blueprint. A failed read keeps the mirror as it was (complete false, logged).
     *
     * @return array{mirror: array, complete: bool, changed: bool}
     */
    private function pull_blueprints(\WP_Post $marker, ?array $listing, array $mirror, ?WporgPluginSvnClient $client): array {
        $directory = null;
        foreach ($listing['entries'] ?? [] as $entry) {
            if ($entry['name'] === 'blueprints' && $entry['type'] === 'dir') {
                $directory = $entry;
            }
        }
        if ($directory === null) {
            return [ 'mirror' => wporg_blueprints_mirror_defaults(), 'complete' => true, 'changed' => $mirror['file'] !== null ];
        }
        if ($mirror['revision'] === (int) $directory['revision']) {
            return [ 'mirror' => $mirror, 'complete' => true, 'changed' => false ];
        }
        try {
            $classified = classify_blueprint_listing(WporgOperations::list_blueprints($marker->post_name, $client)['entries'] ?? []);
            $entry = $classified['blueprint'];
            $file = $mirror['file'];
            if ($entry === null) {
                $file = null;
            } elseif ($file === null || $file['revision'] !== $entry['revision']) {
                $contents = null;
                if ($entry['size'] <= PBLSH_BLUEPRINT_MAX_BYTES) {
                    $contents = WporgOperations::read_blueprint($marker->post_name, $client);
                    if ($contents === null) {
                        // Listed a moment ago, gone when read: the mirror stays, the next pull lists anew.
                        wporg_log_cache_error($marker, 'assets blueprints', new WporgSvnException('not_found', 'blueprint.json vanished between listing and reading it', 404));
                        return [ 'mirror' => $mirror, 'complete' => false, 'changed' => false ];
                    }
                }
                $file = [ 'revision' => $entry['revision'], 'filesize' => $entry['size'], 'contents' => $contents ];
            }
        } catch (\Throwable $e) {
            wporg_log_cache_error($marker, 'assets blueprints', $e);
            return [ 'mirror' => $mirror, 'complete' => false, 'changed' => false ];
        }
        return [
            'mirror' => [ 'revision' => (int) $directory['revision'], 'blueprint_files' => $classified['blueprint_files'], 'other_files' => $classified['other_files'], 'file' => $file ],
            'complete' => true,
            'changed' => ($mirror['file']['revision'] ?? null) !== ($file['revision'] ?? null),
        ];
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
            if ($state['revision'] === null) {
                return $this->not_synced();
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
                $moved = is_uploaded_file((string) $local_path) ? @move_uploaded_file((string) $local_path, $target) : false; // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- the file passed validate_asset_upload(), the target is the canonical name in the plugin's own directory
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

    /**
     * Records a change of the Live Preview in the working copy (apply_blueprint_change()).
     * $decide gets the state under the lock and answers the change — built from the blueprint
     * in effect at that moment, so two writers never lose each other's change —, a WP_Error,
     * or null when there is nothing to change.
     *
     * @param callable $decide fn(array $state): array{action:string, contents?:string}|\WP_Error|null
     * @return array{}|\WP_Error
     */
    public function change_blueprint(\WP_Post $marker, callable $decide): array|\WP_Error {
        return $this->locked($marker, function() use ($marker, $decide): array|\WP_Error {
            $id = (int) $marker->ID;
            $state = get_wporg_assets_state($id);
            if ($state['revision'] === null) {
                return $this->not_synced();
            }
            $change = $decide($state);
            if (is_wp_error($change)) {
                return $change;
            }
            if ($change !== null) {
                $user = wp_get_current_user();
                $pending = apply_blueprint_change($state['pending'], $state, [ ...$change, 'at' => time(), 'user' => [ 'id' => (int) $user->ID, 'login' => (string) $user->user_login ] ]);
                update_wporg_assets_state($id, [ ...$state, 'pending' => $pending ]);
            }
            return [];
        });
    }

    /**
     * The working copy builds on the mirror: before the first pull every entry would record an
     * empty base, and that pull would then report a conflict on each of them.
     */
    private function not_synced(): \WP_Error {
        return new \WP_Error('wporg_assets_not_synced', __('The assets have not been read from wordpress.org yet, so they cannot be changed. Reload to try again.', 'peak-publisher'), [ 'status' => 409 ]);
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
            $base = wporg_asset_base(wporg_mirror_slots(read_asset_manifest($id), $state)[$slot_id] ?? null);
            if ($keep === 'theirs' || ($entry['action'] === 'delete' && $base === null)) {
                if ($entry['action'] === 'put' && isset($entry['file'])) {
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
                    if (asset_conflicts($state['pending'], wporg_mirror_slots(read_asset_manifest($id), $state)) !== []) {
                        throw new WporgSvnException('wporg_assets_conflict', __('Some of these assets were changed on wordpress.org as well. Decide for each marked asset, then commit again.', 'peak-publisher'), 409);
                    }
                    // A deliberate last gate before the irreversible commit, beside the validation of
                    // every change (decide_blueprint_change()): a blueprint wordpress.org's import
                    // throws on fails that import for the whole commit, the images included.
                    $blueprint = $state['pending']['blueprint'] ?? null;
                    $valid = $blueprint !== null && $blueprint['action'] === 'put' ? validate_blueprint_contents($blueprint['contents']) : true;
                    if (is_wp_error($valid)) {
                        throw WporgSvnException::from_wp_error($valid);
                    }
                    // The Live Preview's put commits from a temp file with its contents; the plan's cleanup deletes it.
                    $plan = build_asset_commit_plan($marker->post_name, $state['pending'], $pulled['listing'], $base_revision, get_plugin_assets_dir($marker, 'pending'), $blueprint !== null && $blueprint['action'] === 'put' ? WporgOperations::file_variant($blueprint['contents'])['path'] : null);
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
        // The Live Preview's entry carries its contents; the image entries their files.
        $blueprint_entry = $outcome['pending']['blueprint'] ?? null;
        $image_entries = array_diff_key($outcome['pending'], [ 'blueprint' => true ]);
        try {
            $manifest = read_asset_manifest($id);
            $staged = [];
            foreach ($image_entries as $slot_id => $entry) {
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
            foreach (array_keys($image_entries) as $slot_id) {
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
        $blueprints = blueprints_mirror_after_commit($state['blueprints'], $blueprint_entry, $revision);
        update_wporg_assets_state($id, $complete ? [
            ...$state,
            'revision' => $revision,
            'other_files' => $classified['other_files'],
            'color_source' => $classified['color_source'],
            'blueprints' => $blueprints,
            'pending' => [],
        ] : [ ...$state, 'blueprints' => $blueprints, 'pending' => [] ]);
    }

    /** Runs a change of the working copy under the commit lock: a pull, a refresh or another change is waited for, a commit — or one of those outlasting the wait — refuses it (deploy_in_progress, wporg_plugin_busy). */
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
