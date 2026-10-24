<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\upgrade_drop_latest_release_meta;
use function Pblsh\upgrade_drop_top_level_folder_setting;
use function Pblsh\upgrade_rename_workspace_artifacts_setting;

/**
 * Three sections of the one migration from the live schema (includes/upgrade.php): the leftovers
 * of 1.3.1 that nothing reads any more — a plugin meta and a setting — go, the setting with a
 * word to the operator who had it off, and a misspelled setting key is renamed. Every section
 * is idempotent: a second run finds nothing.
 */
final class SchemaUpgradeSectionsTest extends TestCase {

    public function test_the_latest_release_meta_is_deleted_from_every_plugin(): void {
        $with = $this->create_plugin('pblsh_plugin', 'with-meta');
        $without = $this->create_plugin('pblsh_plugin', 'without-meta', 'draft');
        update_post_meta($with->ID, '_pblsh_latest_release_id', 42);
        update_post_meta($with->ID, '_pblsh_current_release', '1.0.0');

        upgrade_drop_latest_release_meta();

        self::assertFalse(metadata_exists('post', $with->ID, '_pblsh_latest_release_id'));
        self::assertFalse(metadata_exists('post', $without->ID, '_pblsh_latest_release_id'));
        self::assertSame('1.0.0', get_post_meta($with->ID, '_pblsh_current_release', true), 'nothing else moves');

        upgrade_drop_latest_release_meta();
        self::assertFalse(metadata_exists('post', $with->ID, '_pblsh_latest_release_id'), 'a second run finds nothing');
    }

    public function test_the_top_level_folder_setting_goes_and_the_operator_hears_of_it_only_when_it_was_off(): void {
        self::assertNull(upgrade_drop_top_level_folder_setting(), 'no option at all');
        self::assertFalse(get_option('pblsh_settings'), 'and none is created');

        update_option('pblsh_settings', [ 'auto_add_top_level_folder' => true, 'count_plugin_installations' => false ]);
        self::assertNull(upgrade_drop_top_level_folder_setting(), 'it was on: nothing to say');
        self::assertSame([ 'count_plugin_installations' => false ], get_option('pblsh_settings'), 'the key is gone, the rest stays');

        update_option('pblsh_settings', [ 'auto_add_top_level_folder' => false ]);
        self::assertSame([ 'was_disabled' => true ], upgrade_drop_top_level_folder_setting(), 'it was off: the notice says so');
        self::assertSame([], get_option('pblsh_settings'));

        self::assertNull(upgrade_drop_top_level_folder_setting(), 'a second run finds nothing');
    }

    public function test_the_misspelled_artifacts_key_is_renamed_and_its_value_kept(): void {
        upgrade_rename_workspace_artifacts_setting();
        self::assertFalse(get_option('pblsh_settings'), 'no option at all: none is created');

        update_option('pblsh_settings', [ 'wordspace_artifacts_to_remove' => [ '.git', '.DS_Store' ], 'standalone_mode' => true ]);
        upgrade_rename_workspace_artifacts_setting();
        self::assertSame([ 'standalone_mode' => true, 'workspace_artifacts_to_remove' => [ '.git', '.DS_Store' ] ], get_option('pblsh_settings'));

        update_option('pblsh_settings', [ 'wordspace_artifacts_to_remove' => [ 'old' ], 'workspace_artifacts_to_remove' => [ 'new' ] ]);
        upgrade_rename_workspace_artifacts_setting();
        self::assertSame([ 'workspace_artifacts_to_remove' => [ 'new' ] ], get_option('pblsh_settings'), 'an interrupted run left the new key: the old one only goes');

        upgrade_rename_workspace_artifacts_setting();
        self::assertSame([ 'workspace_artifacts_to_remove' => [ 'new' ] ], get_option('pblsh_settings'), 'a second run finds nothing');
    }
}
