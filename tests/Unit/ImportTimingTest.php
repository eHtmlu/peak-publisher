<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\predict_wporg_import;
use function Pblsh\resolve_current_release;
use function Pblsh\serialize_wporg_import;
use function Pblsh\wporg_commits_from_log;
use function Pblsh\wporg_import_confirmed;
use function Pblsh\wporg_import_facts;
use function Pblsh\wporg_import_forecast_due;

use const Pblsh\PBLSH_WPORG_DIRECTORY_META;
use const Pblsh\PBLSH_WPORG_IMPORT_META;

/**
 * When the plugin page shows a commit (includes/wporg_import_timing.php): wordpress.org's import
 * queue played over the plugin's recent commits — +5 s for tags and Stable tag flips, +15 min
 * after the last trunk or assets commit, every commit before the import ran folded into it —
 * and when the stored forecast ends: by time, or once the directory stamp confirms the import.
 */
final class ImportTimingTest extends TestCase {

    private const T = 1_800_000_000;
    private const STAMP = [ 'version' => '1.2.0', 'last_updated' => 'A', 'assets' => [] ];
    private const WAITING = [ 'import_waiting' => true, 'current_version' => '1.2.0' ];
    private const LANDED = [ 'import_waiting' => false, 'current_version' => '1.2.0' ];

    // trunk_touched defaults to what the tags say: trunk itself, unless the commit is an assets commit (the watcher
    // lists assets/ under trunk).
    private static function commit(int $time, array $tags = [ 'trunk' ], array $deleted = [], bool $readme = false, bool $assets = false, bool $flip = false, ?bool $trunk = null): array {
        return [ 'revision' => $time, 'time' => $time, 'tags_touched' => $tags, 'tags_deleted' => $deleted, 'readme_touched' => $readme, 'assets_touched' => $assets, 'trunk_touched' => $trunk ?? (in_array('trunk', $tags, true) && !$assets), 'flip' => $flip ];
    }

    public function test_an_assets_commit_waits_fifteen_minutes(): void {
        self::assertSame([ 'expected_at' => self::T + 900 + 30, 'reason' => 'assets' ], predict_wporg_import([ self::commit(self::T, assets: true) ], self::T));
    }

    public function test_a_trunk_change_after_assets_moves_the_time_and_is_no_longer_about_assets(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, readme: true) ];
        self::assertSame([ 'expected_at' => self::T + 300 + 900 + 30, 'reason' => 'trunk' ], predict_wporg_import($commits, self::T + 300));
    }

    public function test_each_further_trunk_commit_restarts_the_window(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, assets: true), self::commit(self::T + 600, assets: true) ];
        self::assertSame(self::T + 600 + 900 + 30, predict_wporg_import($commits, self::T + 600)['expected_at']);
    }

    public function test_a_flip_pulls_waiting_assets_forward(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, readme: true, flip: true) ];
        self::assertSame([ 'expected_at' => self::T + 300 + 5 + 30, 'reason' => 'flip' ], predict_wporg_import($commits, self::T + 300));
    }

    public function test_a_tag_is_imported_within_seconds(): void {
        self::assertSame([ 'expected_at' => self::T + 5 + 30, 'reason' => 'tag' ], predict_wporg_import([ self::commit(self::T, [ '1.2.0' ], readme: true) ], self::T));
        self::assertSame('tag', predict_wporg_import([ self::commit(self::T, [], [ '1.0.0' ]) ], self::T)['reason'], 'a deleted tag too');
    }

    public function test_commits_before_the_import_ran_are_folded_into_it(): void {
        $commits = [ self::commit(self::T, [ '1.2.0' ]), self::commit(self::T + 3, assets: true) ];
        self::assertSame([ 'expected_at' => self::T + 3 + 5 + 30, 'reason' => 'tag' ], predict_wporg_import($commits, self::T + 3), 'the watcher summarizes both before queue(): the tag decides');

        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 100, [ '1.3.0' ]) ];
        self::assertSame('tag', predict_wporg_import($commits, self::T + 100)['reason'], 'a tag pulls waiting assets forward');
    }

    public function test_a_foreign_readme_commit_is_a_trunk_change(): void {
        self::assertSame('trunk', predict_wporg_import([ self::commit(self::T, readme: true) ], self::T)['reason']);
    }

    public function test_an_import_that_ran_is_forgotten_and_the_forecast_ends_three_minutes_after_it(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 2000, [ '1.3.0' ]) ];
        self::assertSame(self::T + 2000 + 35, predict_wporg_import($commits, self::T + 2000)['expected_at']);

        self::assertNotNull(predict_wporg_import([ self::commit(self::T, assets: true) ], self::T + 930 + 179));
        self::assertNull(predict_wporg_import([ self::commit(self::T, assets: true) ], self::T + 930 + 180));
        self::assertNull(predict_wporg_import([], self::T));
    }

    public function test_the_log_is_read_like_wordpress_orgs_svn_watcher(): void {
        $log = [
            [ 'revision' => 13, 'time' => 130, 'paths' => [ '/my-plugin/trunk/readme.txt' => 'M' ] ],
            [ 'revision' => 10, 'time' => 100, 'paths' => [ '/my-plugin/assets/icon-128x128.png' => 'M' ] ],
            [ 'revision' => 11, 'time' => 110, 'paths' => [ '/my-plugin/tags/1.2.0' => 'A', '/my-plugin/tags/1.2.0/readme.txt' => 'A', '/my-plugin/tags/1.2.0/sub/readme.txt' => 'A', '/other-plugin/trunk/readme.txt' => 'M' ] ],
            [ 'revision' => 12, 'time' => 120, 'paths' => [ '/my-plugin/tags/1.0.0' => 'D', '/my-plugin/tags/1.1.0/old.php' => 'D' ] ],
        ];

        $commits = wporg_commits_from_log($log, 'my-plugin', [ 13 ]);

        self::assertSame([ 10, 11, 12, 13 ], array_column($commits, 'revision'));
        self::assertSame([ 'trunk' ], $commits[0]['tags_touched'], 'assets/ counts as trunk');
        self::assertTrue($commits[0]['assets_touched']);
        self::assertFalse($commits[0]['trunk_touched'], 'trunk itself was not touched');
        self::assertTrue($commits[3]['trunk_touched']);
        self::assertSame([ '1.2.0' ], $commits[1]['tags_touched'], 'another plugin\'s paths in the same commit are not ours');
        self::assertTrue($commits[1]['readme_touched'], 'only the readme at the root of trunk or a tag counts');
        self::assertSame([ '1.0.0' ], $commits[2]['tags_deleted']);
        self::assertSame([ '1.1.0' ], $commits[2]['tags_touched'], 'a deleted file inside a tag touches the tag');
        self::assertTrue($commits[3]['flip']);
        self::assertFalse($commits[1]['flip']);
    }

    public function test_the_stored_forecast_is_served_until_three_minutes_after_it(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T, 'reason' => 'trunk', 'computed_at' => self::T - 900 ]);

        self::assertSame([ 'expected_at' => gmdate('Y-m-d\TH:i:s\Z', self::T), 'reason' => 'trunk' ], serialize_wporg_import($marker->ID, self::T + 179, self::LANDED));
        self::assertNull(serialize_wporg_import($marker->ID, self::T + 180, self::LANDED));

        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, 'garbage');
        self::assertNull(serialize_wporg_import($marker->ID, self::T, self::LANDED), 're-validation when reading back from persistence');
    }

    public function test_a_shown_forecast_is_recomputed_on_load_once_it_is_older_than_the_watchers_interval(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T, self::WAITING), 'nothing stored');

        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T + 600, 'reason' => 'assets', 'computed_at' => self::T ]);
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T + 10, self::WAITING), 'the reload right after the own commit');
        self::assertTrue(wporg_import_forecast_due($marker->ID, self::T + 30, self::WAITING));
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T + 600 + 180, self::WAITING), 'no longer shown');
    }

    public function test_the_stamp_confirms_an_import_of_assets_or_of_the_current_release_but_never_a_trunk_commit(): void {
        $assets = [ 'expected_at' => self::T, 'reason' => 'assets' ];
        self::assertFalse(wporg_import_confirmed($assets, self::STAMP, self::WAITING));
        self::assertTrue(wporg_import_confirmed($assets, self::STAMP, self::LANDED));
        self::assertFalse(wporg_import_confirmed($assets, null, self::LANDED), 'no check yet: nothing confirms');

        foreach ([ 'flip', 'tag' ] as $reason) {
            $stored = [ 'expected_at' => self::T, 'reason' => $reason ];
            self::assertTrue(wporg_import_confirmed($stored, self::STAMP, self::WAITING), 'the directory shows the current release');
            self::assertFalse(wporg_import_confirmed($stored, [ ...self::STAMP, 'version' => '1.1.0' ], self::WAITING));
            self::assertFalse(wporg_import_confirmed($stored, self::STAMP, [ ...self::WAITING, 'current_version' => null ]), 'no current release: nothing to compare with');
            self::assertFalse(wporg_import_confirmed($stored, null, self::WAITING));
        }

        self::assertFalse(wporg_import_confirmed([ 'expected_at' => self::T, 'reason' => 'trunk' ], self::STAMP, self::LANDED), 'a trunk commit leaves no trace in the stamp');
    }

    public function test_a_confirmed_forecast_is_neither_served_nor_recomputed(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T + 600, 'reason' => 'assets', 'computed_at' => self::T ]);
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, [ 'state' => 'ok', 'stamp' => self::STAMP ]);

        self::assertNotNull(serialize_wporg_import($marker->ID, self::T + 60, self::WAITING));
        self::assertTrue(wporg_import_forecast_due($marker->ID, self::T + 60, self::WAITING));

        self::assertNull(serialize_wporg_import($marker->ID, self::T + 60, self::LANDED), 'the import landed early');
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T + 60, self::LANDED));
    }

    public function test_the_facts_are_read_from_the_marker_and_its_current_release(): void {
        $readme = [ 'stable_tag' => '1.2.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 1, 'release_count' => 1, 'trunk_readme' => $readme ]);
        $release = $this->create_release($marker, '1.2.0');

        self::assertSame([ 'import_waiting' => false, 'current_version' => '1.2.0' ], wporg_import_facts($marker, resolve_current_release($marker, [ $release ])));

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, [ 'state' => 'ok', 'stamp' => [ ...self::STAMP, 'assets' => [ 'screenshot-1.png' => 5 ] ] ]);
        self::assertTrue(wporg_import_facts($marker, resolve_current_release($marker, []))['import_waiting'], 'the page shows a file the mirror does not hold');
        self::assertNull(wporg_import_facts($marker, resolve_current_release($marker, []))['current_version'], 'the Stable tag names no release');
    }
}
