<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit\Data;

use Pblsh\Tests\FakeWordPress;
use Pblsh\Tests\Unit\TestCase;

use function Pblsh\maybe_upgrade_schema;

use const Pblsh\PBLSH_SCHEMA_VERSION;

/**
 * The one migration from the live schema (1.3.1: release drafts) to the current one
 * (the plugin's current-release pointer), includes/upgrade.php.
 */
final class UpgradeTest extends TestCase {

    public function test_drafts_become_the_pointer_and_every_release_is_published(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $this->create_release($plugin, '1.0.0');
        $this->create_release($plugin, '1.2.0');
        $draft = $this->create_release($plugin, '2.0.0', 'draft');

        $drafts_only = $this->create_plugin('pblsh_plugin', 'plugin-b');
        $this->create_release($drafts_only, '0.9.0', 'draft');

        $empty = $this->create_plugin('pblsh_plugin', 'plugin-c');

        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-d', 'publish', [ 'revision' => 1 ]);
        $this->create_release($marker, '1.0.0');

        maybe_upgrade_schema();

        self::assertSame('1.2.0', get_post_meta($plugin->ID, '_pblsh_current_release', true), 'the highest published version, not the draft above it');
        self::assertSame('publish', get_post($draft->ID)->post_status);
        self::assertSame('', get_post_meta($drafts_only->ID, '_pblsh_current_release', true), 'no published release: no current release');
        self::assertSame('', get_post_meta($empty->ID, '_pblsh_current_release', true));
        self::assertFalse(metadata_exists('post', $marker->ID, '_pblsh_current_release'), 'wporg markers read their pointer from SVN');
        foreach (get_posts([ 'post_type' => 'pblsh_release', 'post_status' => 'any', 'posts_per_page' => -1 ]) as $release) {
            self::assertSame('publish', $release->post_status);
        }

        self::assertSame(PBLSH_SCHEMA_VERSION, get_option('pblsh_schema_version'));
        self::assertFalse(get_option('pblsh_schema_migration_lock'), 'the lock is released');
        $notice = get_option('pblsh_upgrade_notice');
        self::assertSame([ 'release_drafts' ], array_keys($notice['topics']), 'one topic per migration section with something to say');
        self::assertSame([
            [ 'plugin_id' => $plugin->ID, 'plugin' => 'plugin-a', 'version' => '2.0.0' ],
            [ 'plugin_id' => $drafts_only->ID, 'plugin' => 'plugin-b', 'version' => '0.9.0' ],
        ], $notice['topics']['release_drafts']['former_drafts'], 'every former draft, by plugin and version');
        self::assertSame([ [ 'plugin_id' => $drafts_only->ID, 'plugin' => 'plugin-b' ] ], $notice['topics']['release_drafts']['plugins_without_current'], 'only plugins with releases and no current release');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $notice['at']);
    }

    public function test_nothing_to_say_leaves_no_notice(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $this->create_release($plugin, '1.0.0');

        maybe_upgrade_schema();

        self::assertSame('1.0.0', get_post_meta($plugin->ID, '_pblsh_current_release', true));
        self::assertSame(PBLSH_SCHEMA_VERSION, get_option('pblsh_schema_version'));
        self::assertFalse(get_option('pblsh_upgrade_notice'));
    }

    public function test_migration_does_not_run_on_the_current_schema(): void {
        update_option('pblsh_schema_version', PBLSH_SCHEMA_VERSION);
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $draft = $this->create_release($plugin, '1.0.0', 'draft');

        maybe_upgrade_schema();

        self::assertFalse(metadata_exists('post', $plugin->ID, '_pblsh_current_release'));
        self::assertSame('draft', get_post($draft->ID)->post_status);
    }

    public function test_an_interrupted_run_resumes_without_recomputing_the_pointer(): void {
        // A previous run wrote the pointer and died before publishing the drafts.
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $this->create_release($plugin, '1.0.0');
        $draft = $this->create_release($plugin, '2.0.0', 'draft');
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.0.0');

        maybe_upgrade_schema();

        self::assertSame('1.0.0', get_post_meta($plugin->ID, '_pblsh_current_release', true), 'a pointer is never recomputed from already converted drafts');
        self::assertSame('publish', get_post($draft->ID)->post_status);
        self::assertSame([ '2.0.0' ], array_column(get_option('pblsh_upgrade_notice')['topics']['release_drafts']['former_drafts'], 'version'));
    }

    public function test_a_fresh_lock_of_another_request_skips_the_migration(): void {
        add_option('pblsh_schema_migration_lock', time());
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $this->create_release($plugin, '1.0.0');

        maybe_upgrade_schema();

        self::assertFalse(metadata_exists('post', $plugin->ID, '_pblsh_current_release'));
        self::assertFalse(get_option('pblsh_schema_version'));
        self::assertNotFalse(get_option('pblsh_schema_migration_lock'), 'the other request still holds its lock');
    }

    public function test_an_orphaned_lock_is_taken_over(): void {
        add_option('pblsh_schema_migration_lock', time() - 11 * MINUTE_IN_SECONDS);
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $this->create_release($plugin, '1.0.0');

        maybe_upgrade_schema();

        self::assertSame('1.0.0', get_post_meta($plugin->ID, '_pblsh_current_release', true));
        self::assertSame(PBLSH_SCHEMA_VERSION, get_option('pblsh_schema_version'));
        self::assertFalse(get_option('pblsh_schema_migration_lock'));
    }
}
