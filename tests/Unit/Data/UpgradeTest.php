<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit\Data;

use Pblsh\Tests\FakeWordPress;
use Pblsh\Tests\Unit\TestCase;

use function Pblsh\maybe_upgrade_schema;
use function Pblsh\peak_publisher_upload_basedir;

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

    public function test_the_readme_conversion_setting_is_dropped_and_reported_when_it_was_off(): void {
        update_option('pblsh_settings', [ 'standalone_mode' => false, 'readme_txt_convert_to_utf8_without_bom' => false ]);

        maybe_upgrade_schema();

        self::assertSame([ 'standalone_mode' => false ], get_option('pblsh_settings'));
        self::assertSame([ 'readme_conversion' => [ 'was_disabled' => true ] ], get_option('pblsh_upgrade_notice')['topics']);
    }

    public function test_a_readme_conversion_setting_that_was_on_is_dropped_silently(): void {
        update_option('pblsh_settings', [ 'readme_txt_convert_to_utf8_without_bom' => true ]);

        maybe_upgrade_schema();

        self::assertSame([], get_option('pblsh_settings'));
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

    public function test_asset_manifest_entries_get_their_figures_and_lose_local(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        $dir = FakeWordPress::$upload_dir . '/pblsh-peak-publisher/plugins/plugin-a/assets';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/icon-128x128.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        file_put_contents($dir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        update_post_meta($plugin->ID, 'assets_icons', [
            'icon-128x128.png' => [ 'filename' => 'icon-128x128.png', 'revision' => 1700000000, 'resolution' => '128x128', 'local' => '' ],
            'icon.svg' => [ 'filename' => 'icon.svg', 'revision' => 1700000001, 'resolution' => false, 'local' => false ],
        ]);
        update_post_meta($plugin->ID, 'assets_screenshots', [
            'screenshot-1.png' => [ 'filename' => 'screenshot-1.png', 'revision' => 1700000002, 'resolution' => '1', 'local' => '' ],
        ]);
        $migrated = [ 'banner-772x250.png' => [ 'filename' => 'banner-772x250.png', 'revision' => 1700000003, 'resolution' => '772x250', 'filesize' => 5, 'width' => 772, 'height' => 250 ] ];
        update_post_meta($plugin->ID, 'assets_banners', $migrated);

        maybe_upgrade_schema();

        self::assertSame(
            [ 'filename' => 'icon-128x128.png', 'revision' => 1700000000, 'resolution' => '128x128', 'filesize' => 70, 'width' => 1, 'height' => 1 ],
            get_post_meta($plugin->ID, 'assets_icons', true)['icon-128x128.png']
        );
        self::assertSame(
            [ 'filename' => 'icon.svg', 'revision' => 1700000001, 'resolution' => false, 'filesize' => 41, 'width' => null, 'height' => null ],
            get_post_meta($plugin->ID, 'assets_icons', true)['icon.svg']
        );
        self::assertSame(
            [ 'filename' => 'screenshot-1.png', 'revision' => 1700000002, 'resolution' => '1', 'filesize' => 0, 'width' => null, 'height' => null ],
            get_post_meta($plugin->ID, 'assets_screenshots', true)['screenshot-1.png'],
            'a missing file: size 0, no measurements — the editor warns about it when reading'
        );
        self::assertSame($migrated, get_post_meta($plugin->ID, 'assets_banners', true), 'an entry with figures is left alone');
        self::assertArrayNotHasKey('asset_manifest', get_option('pblsh_upgrade_notice')['topics'] ?? [], 'nothing for the operator to decide');
    }

    public function test_self_hosted_release_content_becomes_a_snapshot_and_the_upload_state_moves_to_meta(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $zip = peak_publisher_upload_basedir() . '/plugins/my-plugin/releases/my-plugin.1.4.zip';
        mkdir(dirname($zip), 0777, true);
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFromString('my-plugin/my-plugin.php', "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.4\n * Tested up to: 7.1\n */\n");
        $archive->addFromString('my-plugin/readme.txt', "=== My Plugin ===\nStable tag: 1.4\n");
        $archive->close();
        $upload_state = [
            'plugin_ok' => true,
            'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => '1.4' ],
            'plugin_info' => [ 'normalized_version' => '1.4', 'main_file' => 'my-plugin.php', 'plugin_basename' => 'my-plugin/my-plugin.php', 'content_hash' => md5('x') ],
            'plugin_readme_txt' => [ 'found' => true, 'file_name' => 'readme.txt', 'content' => [] ],
            'cleanup_info' => [ 'size_after_cleanup' => 1 ],
        ];
        $release_id = wp_insert_post([ 'post_type' => 'pblsh_release', 'post_status' => 'publish', 'post_title' => '1.4', 'post_parent' => $plugin->ID, 'post_content' => json_encode($upload_state), 'post_modified' => '2026-02-22 05:10:00', 'post_modified_gmt' => '2026-02-22 04:10:00' ]);
        update_post_meta($release_id, '_pblsh_zip_path', 'plugins/my-plugin/releases/my-plugin.1.4.zip');
        update_post_meta($release_id, '_pblsh_directory_content_hash', md5('x'));

        maybe_upgrade_schema();

        $content = json_decode(get_post($release_id)->post_content, true);
        self::assertSame([ 'plugin_data', 'plugin_info', 'plugin_readme_txt' ], array_keys($content));
        self::assertSame('7.1', $content['plugin_data']['TestedUpTo'], 'read from the ZIP');
        self::assertSame('1.4', $content['plugin_info']['normalized_version']);
        self::assertSame($upload_state, json_decode(get_post_meta($release_id, '_pblsh_upload_state', true), true), 'the upload state, verbatim');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', get_post_meta($release_id, '_pblsh_directory_content_hash', true), 'recomputed from the ZIP');
        self::assertSame([ '2026-02-22 05:10:00', '2026-02-22 04:10:00' ], [ get_post($release_id)->post_modified, get_post($release_id)->post_modified_gmt ], 'the release was not changed, only stored in another form: sites keep seeing its last update');

        $content_before = get_post($release_id)->post_content;
        delete_option('pblsh_schema_version');
        maybe_upgrade_schema();
        self::assertSame($content_before, get_post($release_id)->post_content, 'a second run finds nothing to do');
    }

    public function test_a_release_without_its_zip_keeps_the_three_keys_of_the_old_content(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $release_id = wp_insert_post([ 'post_type' => 'pblsh_release', 'post_status' => 'publish', 'post_title' => '1.4', 'post_parent' => $plugin->ID, 'post_content' => json_encode([
            'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => '1.4' ],
            'plugin_info' => [ 'normalized_version' => '1.4' ],
            'plugin_readme_txt' => [ 'found' => false, 'file_name' => '', 'content' => [] ],
            'cleanup_info' => [],
        ]) ]);
        update_post_meta($release_id, '_pblsh_zip_path', 'plugins/my-plugin/releases/gone.zip');

        maybe_upgrade_schema();

        $content = json_decode(get_post($release_id)->post_content, true);
        self::assertSame([ 'plugin_data', 'plugin_info', 'plugin_readme_txt' ], array_keys($content));
        self::assertSame('My Plugin', $content['plugin_data']['Name']);
        self::assertSame('', get_post_meta($release_id, '_pblsh_directory_content_hash', true));
        self::assertTrue(metadata_exists('post', $release_id, '_pblsh_upload_state'));
    }

    public function test_wporg_releases_and_migrated_self_hosted_releases_are_left_alone(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'wporg-plugin', 'publish', [ 'revision' => 1 ]);
        $wporg_release = $this->create_release($marker, '1.0.0');
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $migrated = $this->create_release($plugin, '1.0.0');

        maybe_upgrade_schema();

        self::assertFalse(metadata_exists('post', $wporg_release->ID, '_pblsh_upload_state'), 'a wporg tag snapshot was never an upload state');
        self::assertFalse(metadata_exists('post', $migrated->ID, '_pblsh_upload_state'), 'a snapshot-shaped content has nothing to move');
    }
}
