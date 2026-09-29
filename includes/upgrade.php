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
 * A section returns the facts the one-time admin notice reports under its topic key, or
 * null when it has nothing to say.
 */
function upgrade_schema_from_live(): void {
    $topics = array_filter([
        'release_drafts' => upgrade_release_drafts_to_pointer(),
        'readme_conversion' => upgrade_drop_readme_conversion_setting(),
    ]);

    if (!empty($topics)) {
        update_option('pblsh_upgrade_notice', [
            'topics' => $topics,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], false);
    }
}


/**
 * Schema version 1 (ships with the release after 1.3.1): release drafts become the
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
            wp_update_post([
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
 * Schema version 1 (ships with the release after 1.3.1): the setting
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
