<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Schema upgrades — moves stored data from the live schema (the released plugin, see
 * docs/data-schema.md) to the current one. Exactly one migration exists, from live to
 * current; when the current schema changes again before the next release, that one
 * migration is rewritten, never a second one chained.
 *
 * The option pblsh_schema_version (autoloaded) records the schema of the stored data;
 * a site without the option is on the live schema (1.3.1).
 */
const PBLSH_SCHEMA_VERSION = 1;


/**
 * Runs on every request — the public API of a freshly updated site must serve the
 * migrated data at once, not only after the first admin visit. One autoloaded lookup
 * once the migration ran.
 *
 * Hooked to init after the post types are registered (includes/init.php, priority 10):
 * the migration updates posts, and wp_insert_post() needs the registered post type and
 * $wp_rewrite, which WordPress creates only after plugins_loaded. init still precedes
 * every REST and admin handler.
 */
function maybe_upgrade_schema(): void {
    if ((int) get_option('pblsh_schema_version', 0) >= PBLSH_SCHEMA_VERSION) {
        return;
    }
    if (!acquire_schema_migration_lock()) {
        // Another request is migrating: this one continues on the unmigrated data (the
        // public API answers 'no current release' for a few seconds, never an error).
        return;
    }

    try {
        upgrade_schema_from_live();
        // The target version is written only after the migration completed.
        update_option('pblsh_schema_version', PBLSH_SCHEMA_VERSION, true);
    } finally {
        delete_option('pblsh_schema_migration_lock');
    }
}
add_action('init', __NAMESPACE__ . '\\maybe_upgrade_schema', 20);


/**
 * add_option() is atomic (UNIQUE key on option_name): exactly one request wins. A lock
 * older than ten minutes is orphaned — the migrating request died — and is taken over.
 */
function acquire_schema_migration_lock(): bool {
    if (add_option('pblsh_schema_migration_lock', time(), '', false)) {
        return true;
    }
    $held_since = (int) get_option('pblsh_schema_migration_lock', 0);
    if ($held_since > 0 && $held_since < time() - 10 * MINUTE_IN_SECONDS) {
        return update_option('pblsh_schema_migration_lock', time(), false);
    }
    return false;
}


/**
 * The one migration: from the live schema (docs/data-schema.md) to the current one.
 *
 * Every section checks the data state it changes — a missing meta, a wrong status —
 * never the schema version. That keeps the function idempotent (an interrupted run
 * resumes safely) and cumulative (a site that skipped releases still arrives at the
 * current schema through this one function). Each section is its own function, named
 * after what it does; its docblock names the schema version that introduced it, and what
 * it does and why. Independent changes of the same schema version are separate sections.
 * A section the operator may need to know about returns the facts the one-time admin notice
 * reports under its topic key, or null when it has nothing to say this time; a section that
 * never concerns the operator returns nothing.
 *
 * Not a section: the installations cache `_pblsh_installations`, which 1.3.1 keyed by the
 * whole user agent and the current code keys by the site. The site key cannot be computed
 * from the old one, only from the site's next request — so the row moves there, on the
 * site's first update check (legacy_installation_key(), record_plugin_installation()); rows no
 * site renews expire within their day.
 */
function upgrade_schema_from_live(): void {
    upgrade_asset_manifest_entries();
    upgrade_self_hosted_release_snapshots();
    upgrade_drop_latest_release_meta();
    upgrade_rename_workspace_artifacts_setting();

    $topics = array_filter([
        'release_drafts' => upgrade_release_drafts_to_pointer(),
        'readme_conversion' => upgrade_drop_readme_conversion_setting(),
        'top_level_folder' => upgrade_drop_top_level_folder_setting(),
    ]);

    if (!empty($topics)) {
        update_option('pblsh_upgrade_notice', [
            'topics' => $topics,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], false);
    }
}


/**
 * Schema version 1 (ships with 2.0.0): release drafts become the
 * plugin's current-release pointer.
 *
 * Why: sites received the highest *published* release, and drafting a release was the way
 * to roll back or to hold a release back. The pointer `_pblsh_current_release` expresses
 * that decision directly; a release status no longer exists.
 *
 * What, per self-hosted plugin, in this order — the pointer is computed from the status
 * before the status disappears: (a) no meta yet → the pointer is the highest version among
 * the releases with status publish ('' when there is none); (b) every release with another
 * status becomes publish. (b) only ever follows (a) for the same plugin, so a repeated run
 * never computes a pointer from already converted drafts. wporg markers need nothing —
 * their pointer is read from SVN.
 *
 * @return array|null Every former draft by plugin and version (each is now downloadable by
 *         version — the operator decides whether that is fine or the release goes) and the
 *         plugins left without a current release; null when there is nothing to say.
 */
function upgrade_release_drafts_to_pointer(): ?array {
    $former_drafts = [];
    $plugins_without_current = [];

    $plugins = get_posts([
        'post_type' => 'pblsh_plugin',
        'post_status' => 'any',
        'posts_per_page' => -1,
    ]);
    foreach ($plugins as $plugin) {
        $releases = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'post_parent' => (int) $plugin->ID,
            'posts_per_page' => -1,
        ]);

        if (!metadata_exists('post', (int) $plugin->ID, '_pblsh_current_release')) {
            $pointer = '';
            $pointer_normalized = '';
            foreach ($releases as $release) {
                if ($release->post_status !== 'publish') {
                    continue;
                }
                $version = (string) $release->post_title;
                $normalized = normalize_version_number($version);
                if ($normalized === '') {
                    continue;
                }
                if ($pointer_normalized === '' || version_compare($normalized, $pointer_normalized, '>')) {
                    $pointer = $version;
                    $pointer_normalized = $normalized;
                }
            }
            update_post_meta((int) $plugin->ID, '_pblsh_current_release', $pointer);
        }

        foreach ($releases as $release) {
            if ($release->post_status === 'publish') {
                continue;
            }
            // The release's content is its snapshot: the status update saves it again.
            write_data_post([
                'ID' => (int) $release->ID,
                'post_status' => 'publish',
                // Keeps the release date: without it WordPress would assign the publish moment.
                'edit_date' => true,
            ]);
            $former_drafts[] = [
                'plugin_id' => (int) $plugin->ID,
                'plugin' => (string) $plugin->post_name,
                'version' => (string) $release->post_title,
            ];
        }

        if (!empty($releases) && (string) get_post_meta((int) $plugin->ID, '_pblsh_current_release', true) === '') {
            $plugins_without_current[] = [
                'plugin_id' => (int) $plugin->ID,
                'plugin' => (string) $plugin->post_name,
            ];
        }
    }

    if (empty($former_drafts) && empty($plugins_without_current)) {
        return null;
    }

    // Listed by plugin, then by version — the order the notice shows.
    usort($former_drafts, static fn(array $a, array $b): int => [$a['plugin'], normalize_version_number($a['version'])] <=> [$b['plugin'], normalize_version_number($b['version'])]);
    usort($plugins_without_current, static fn(array $a, array $b): int => $a['plugin'] <=> $b['plugin']);

    return [
        'former_drafts' => $former_drafts,
        'plugins_without_current' => $plugins_without_current,
    ];
}


/**
 * Schema version 1 (ships with 2.0.0): the setting
 * `readme_txt_convert_to_utf8_without_bom` is gone — every uploaded readme is stored as
 * UTF-8 without a BOM.
 *
 * Why: Peak Publisher writes the readme anyway (its Stable tag line names the release's
 * version), a readme that is not UTF-8 cannot be stored as release data, and wordpress.org
 * requires UTF-8 — keeping the file as it was never was a useful choice.
 *
 * What: the key is removed from the stored option. An operator who had switched the
 * conversion off learns about the change through the notice.
 *
 * @return array|null `{ was_disabled: true }` when the conversion was switched off; null otherwise.
 */
function upgrade_drop_readme_conversion_setting(): ?array {
    $settings = get_option('pblsh_settings');
    if (!is_array($settings) || !array_key_exists('readme_txt_convert_to_utf8_without_bom', $settings)) {
        return null;
    }
    $was_disabled = empty($settings['readme_txt_convert_to_utf8_without_bom']);
    unset($settings['readme_txt_convert_to_utf8_without_bom']);
    update_option('pblsh_settings', $settings, false);
    return $was_disabled ? [ 'was_disabled' => true ] : null;
}


/**
 * Schema version 1 (ships with 2.0.0): the setting
 * `auto_add_top_level_folder` ("Add top-level folder if missing") is gone — every release ZIP
 * is built at finalize with the plugin's slug as its top-level folder.
 *
 * Why: WordPress installs the ZIP's root as the plugin folder, and wordpress.org names it by
 * the slug; a ZIP without that folder was never a useful choice, so the folder is no longer
 * an option but the way the release is built.
 *
 * What: the key is removed from the stored option. An operator who had switched the addition
 * off learns about the change through the notice.
 *
 * @return array|null `{ was_disabled: true }` when the addition was switched off; null otherwise.
 */
function upgrade_drop_top_level_folder_setting(): ?array {
    $settings = get_option('pblsh_settings');
    if (!is_array($settings) || !array_key_exists('auto_add_top_level_folder', $settings)) {
        return null;
    }
    $was_disabled = empty($settings['auto_add_top_level_folder']);
    unset($settings['auto_add_top_level_folder']);
    update_option('pblsh_settings', $settings, false);
    return $was_disabled ? [ 'was_disabled' => true ] : null;
}


/**
 * Schema version 1 (ships with 2.0.0): the setting
 * `wordspace_artifacts_to_remove` is spelled `workspace_artifacts_to_remove`.
 *
 * Why: a typo in the stored key since the first release; the name should say what the
 * setting holds.
 *
 * What: the value moves under the new key — where the new key exists already (an interrupted
 * run), the old one is only dropped — and the old key is removed. Nothing for the notice.
 */
function upgrade_rename_workspace_artifacts_setting(): void {
    $settings = get_option('pblsh_settings');
    if (!is_array($settings) || !array_key_exists('wordspace_artifacts_to_remove', $settings)) {
        return;
    }
    if (!array_key_exists('workspace_artifacts_to_remove', $settings)) {
        $settings['workspace_artifacts_to_remove'] = $settings['wordspace_artifacts_to_remove'];
    }
    unset($settings['wordspace_artifacts_to_remove']);
    update_option('pblsh_settings', $settings, false);
}


/**
 * Schema version 1 (ships with 2.0.0): the plugin meta
 * `_pblsh_latest_release_id` is gone.
 *
 * Why: 1.3.1 recorded the newest release's post ID on the plugin with every upload; since the
 * current-release pointer nothing reads it — what sites receive is `_pblsh_current_release`,
 * and the newest release is read from the releases themselves.
 *
 * What: the meta is deleted from every self-hosted plugin. Nothing for the notice.
 */
function upgrade_drop_latest_release_meta(): void {
    $plugin_ids = get_posts([
        'post_type' => 'pblsh_plugin',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    foreach ($plugin_ids as $plugin_id) {
        delete_post_meta((int) $plugin_id, '_pblsh_latest_release_id');
    }
}


/**
 * Schema version 1 (ships with 2.0.0): a self-hosted release post's content
 * becomes the snapshot {plugin_data, plugin_info, plugin_readme_txt} — regenerable from its
 * ZIP, like a wporg tag's from SVN — and the upload state moves to the meta
 * _pblsh_upload_state.
 *
 * Why: an edit after the upload (Tested up to) changes the release's readme; the upload state
 * must stay the verbatim record of the upload, and one stored state must say what the release
 * is now. The content hash is recomputed with the current formula (content_hash_from_entries():
 * sha256 over the paths and file contents of the ZIP's entries).
 *
 * What, per self-hosted release whose content carries keys beyond the three and whose meta is
 * missing: content → meta; the snapshot from the ZIP (release_snapshot_from_zip()), or — ZIP
 * gone or unreadable without the zip extension — the three keys of the old content; the hash
 * from the ZIP, '' without one. The release itself does not change, only its stored form: the
 * post's modified time stays, so what sites see as "last updated" (post_modified_gmt, the
 * public API) does not move with the migration. A repeated
 * run finds nothing to do. Nothing for the notice. wporg release posts carry tag snapshots
 * already and are not touched.
 */
function upgrade_self_hosted_release_snapshots(): void {
    $plugin_ids = get_posts([
        'post_type' => 'pblsh_plugin',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    foreach ($plugin_ids as $plugin_id) {
        $releases = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'post_parent' => (int) $plugin_id,
            'posts_per_page' => -1,
        ]);
        foreach ($releases as $release) {
            $content = json_decode((string) $release->post_content, true);
            if (!is_array($content) || metadata_exists('post', (int) $release->ID, '_pblsh_upload_state')) {
                continue;
            }
            if (array_diff(array_keys($content), [ 'plugin_data', 'plugin_info', 'plugin_readme_txt' ]) === []) {
                continue;
            }
            update_post_meta((int) $release->ID, '_pblsh_upload_state', wp_slash((string) $release->post_content));
            // Without the zip extension the ZIP stays unread: the old content's three keys and no
            // hash — the migration must never fail on init.
            $zip = release_zip_path($release);
            $zip_readable = $zip !== '' && is_file($zip) && release_zip_support();
            $snapshot = $zip_readable ? release_snapshot_from_zip($zip) : null;
            // A failed write is tolerated here, on init: the content stays the old upload state,
            // which carries the three keys release_snapshot() reads. wp_update_post() stamps
            // every update with the current time; the row keeps the release's own.
            $keep_modified = static fn(array $data): array => [ ...$data, 'post_modified' => $release->post_modified, 'post_modified_gmt' => $release->post_modified_gmt ];
            add_filter('wp_insert_post_data', $keep_modified, PHP_INT_MAX);
            try {
                write_release_snapshot($release, $snapshot ?? release_snapshot($release));
            } finally {
                remove_filter('wp_insert_post_data', $keep_modified, PHP_INT_MAX);
            }
            update_post_meta((int) $release->ID, '_pblsh_directory_content_hash', $zip_readable ? content_hash_from_entries(zip_entries($zip)) : '');
        }
    }
}


/**
 * Schema version 1 (ships with 2.0.0): an assets manifest entry carries the
 * file's size and pixel dimensions and loses the never-read key `local`.
 *
 * Why: the editor reads size and dimensions from the manifest instead of measuring the disk on
 * every read, and the entry is wordpress.org's shape — filename, revision, resolution, size,
 * dimensions — for every plugin.
 *
 * What: every entry of assets_icons / assets_banners / assets_screenshots of a self-hosted
 * plugin without a `filesize` key gets filesize (0 when the file is missing), width and height
 * (null for SVG or when unmeasurable) from the file on disk and loses `local`. A repeated run
 * finds nothing to do. Nothing for the notice — the operator has nothing to decide. The
 * generated icon's color is not recomputed: it is a cache the store refreshes with the next
 * banner change.
 */
function upgrade_asset_manifest_entries(): void {
    $plugins = get_posts([
        'post_type' => 'pblsh_plugin',
        'post_status' => 'any',
        'posts_per_page' => -1,
    ]);
    foreach ($plugins as $plugin) {
        $assets_dir = get_plugin_assets_dir($plugin);
        foreach (PBLSH_ASSET_META_KEYS as $meta_key) {
            $manifest = get_post_meta((int) $plugin->ID, $meta_key, true);
            if (!is_array($manifest)) {
                continue;
            }
            $changed = false;
            foreach ($manifest as $filename => $entry) {
                if (!is_array($entry) || array_key_exists('filesize', $entry)) {
                    continue;
                }
                $path = $assets_dir . '/' . $filename;
                $exists = is_file($path);
                $size = $exists ? @getimagesize($path) : false;
                unset($entry['local']);
                $manifest[$filename] = [
                    ...$entry,
                    'filesize' => $exists ? (int) filesize($path) : 0,
                    'width' => $size === false ? null : (int) $size[0],
                    'height' => $size === false ? null : (int) $size[1],
                ];
                $changed = true;
            }
            if ($changed) {
                update_post_meta((int) $plugin->ID, $meta_key, $manifest);
            }
        }
    }
}


/**
 * Schema version 1 (ships with 2.0.0): the key 1.3.1 gave a site in the installations cache
 * `_pblsh_installations` — the hash of its whole user agent, under the secret salt —, for the one
 * place that still meets such a row: record_plugin_installation() moves it to the site's key on
 * the site's first update check after the update, so nothing counts twice and nothing starts at
 * zero. Goes with this migration.
 */
function legacy_installation_key(string $user_agent): string {
    return substr(preg_replace('/[^a-z0-9]/i', '', base64_encode(hash('sha256', get_secret_salt() . '|' . $user_agent, true))), 0, 14);
}
