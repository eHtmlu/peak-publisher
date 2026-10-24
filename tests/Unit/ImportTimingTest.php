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
 * after the last trunk or assets commit, every commit before the import ran folded into it —,
 * what the import must leave in the directory stamp, and when the stored forecast ends: by
 * time, or once the stamp shows what it expects.
 */
final class ImportTimingTest extends TestCase {

    private const T = 1_800_000_000;   // 2027-01-15 08:00 UTC, on the minute
    private const STAMP = [ 'version' => '1.2.0', 'last_updated' => '2027-01-15 8:00am GMT', 'assets' => [] ];
    private const WAITING = [ 'import_waiting' => true, 'current_version' => '1.2.0' ];
    private const LANDED = [ 'import_waiting' => false, 'current_version' => '1.2.0' ];

    // trunk_touched defaults to what the tags say: trunk itself, unless the commit is an assets commit (the watcher
    // lists assets/ under trunk).
    private static function commit(int $time, array $tags = [ 'trunk' ], array $deleted = [], bool $readme = false, bool $assets = false, bool $flip = false, ?bool $trunk = null): array {
        return [ 'revision' => $time, 'time' => $time, 'tags_touched' => $tags, 'tags_deleted' => $deleted, 'readme_touched' => $readme, 'assets_touched' => $assets, 'trunk_touched' => $trunk ?? (in_array('trunk', $tags, true) && !$assets), 'flip' => $flip ];
    }

    public function test_an_assets_commit_waits_fifteen_minutes(): void {
        self::assertSame([ 'expected_at' => self::T + 900 + 30, 'reason' => 'assets', 'assets' => true, 'stable_touched_at' => null ], predict_wporg_import([ self::commit(self::T, assets: true) ], self::T, '1.2.0'));
    }

    public function test_a_trunk_change_after_assets_moves_the_time_and_is_no_longer_about_assets(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, readme: true) ];
        self::assertSame([ 'expected_at' => self::T + 300 + 900 + 30, 'reason' => 'trunk', 'assets' => true, 'stable_touched_at' => null ], predict_wporg_import($commits, self::T + 300, '1.2.0'), 'the assets are still expected; trunk beside the stable folder leaves the stamp alone');
    }

    public function test_each_further_trunk_commit_restarts_the_window(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, assets: true), self::commit(self::T + 600, assets: true) ];
        self::assertSame(self::T + 600 + 900 + 30, predict_wporg_import($commits, self::T + 600, '1.2.0')['expected_at']);
    }

    public function test_a_flip_pulls_waiting_assets_forward(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 300, readme: true, flip: true) ];
        self::assertSame([ 'expected_at' => self::T + 300 + 5 + 30, 'reason' => 'flip', 'assets' => true, 'stable_touched_at' => self::T + 300 ], predict_wporg_import($commits, self::T + 300, '1.1.0'), 'a pointer move touches the stable, whatever folder the pointer named before');
    }

    public function test_a_tag_is_imported_within_seconds(): void {
        $edit = [ self::commit(self::T, [ '1.2.0' ], readme: true) ];
        self::assertSame([ 'expected_at' => self::T + 5 + 30, 'reason' => 'tag', 'assets' => false, 'stable_touched_at' => self::T ], predict_wporg_import($edit, self::T, '1.2.0'), 'the current release\'s folder: last_updated will move');
        self::assertNull(predict_wporg_import($edit, self::T, '1.1.0')['stable_touched_at'], 'another tag: the plugin page shows nothing of it');
        self::assertSame('tag', predict_wporg_import([ self::commit(self::T, [], [ '1.0.0' ]) ], self::T, '1.2.0')['reason'], 'a deleted tag too');
    }

    public function test_the_stable_folder_is_trunk_when_the_pointer_says_so(): void {
        self::assertSame(self::T, predict_wporg_import([ self::commit(self::T, readme: true) ], self::T, 'trunk')['stable_touched_at']);
        self::assertNull(predict_wporg_import([ self::commit(self::T, assets: true) ], self::T, 'trunk')['stable_touched_at'], 'assets/ is listed under trunk for the schedule; trunk itself stayed');
        self::assertNull(predict_wporg_import([ self::commit(self::T, readme: true) ], self::T, null)['stable_touched_at'], 'the pointer unknown: only a pointer move is known to touch the stable');
    }

    public function test_commits_before_the_import_ran_are_folded_into_it(): void {
        $commits = [ self::commit(self::T, [ '1.2.0' ]), self::commit(self::T + 3, assets: true) ];
        self::assertSame([ 'expected_at' => self::T + 3 + 5 + 30, 'reason' => 'tag', 'assets' => true, 'stable_touched_at' => self::T ], predict_wporg_import($commits, self::T + 3, '1.2.0'), 'the watcher summarizes both before queue(): the tag decides the time, both are expected');

        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 100, [ '1.3.0' ]) ];
        self::assertSame('tag', predict_wporg_import($commits, self::T + 100, '1.2.0')['reason'], 'a tag pulls waiting assets forward');
    }

    public function test_a_foreign_readme_commit_is_a_trunk_change(): void {
        self::assertSame('trunk', predict_wporg_import([ self::commit(self::T, readme: true) ], self::T, '1.2.0')['reason']);
    }

    public function test_an_import_that_ran_is_forgotten_and_the_forecast_ends_three_minutes_after_it(): void {
        $commits = [ self::commit(self::T, assets: true), self::commit(self::T + 2000, [ '1.3.0' ]) ];
        $forecast = predict_wporg_import($commits, self::T + 2000, '1.2.0');
        self::assertSame(self::T + 2000 + 35, $forecast['expected_at']);
        self::assertFalse($forecast['assets'], 'the assets import ran: nothing of it is expected any more');

        self::assertNotNull(predict_wporg_import([ self::commit(self::T, assets: true) ], self::T + 930 + 179, '1.2.0'));
        self::assertNull(predict_wporg_import([ self::commit(self::T, assets: true) ], self::T + 930 + 180, '1.2.0'));
        self::assertNull(predict_wporg_import([], self::T, '1.2.0'));
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
        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T, 'reason' => 'trunk', 'assets' => false, 'stable_touched_at' => null, 'computed_at' => self::T - 900 ]);

        self::assertSame([ 'expected_at' => gmdate('Y-m-d\TH:i:s\Z', self::T), 'reason' => 'trunk', 'watch' => false ], serialize_wporg_import($marker->ID, self::T + 179, self::LANDED), 'nothing expected of the stamp: nothing to watch');
        self::assertNull(serialize_wporg_import($marker->ID, self::T + 180, self::LANDED));

        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T, 'reason' => 'tag', 'assets' => false, 'stable_touched_at' => self::T - 40, 'computed_at' => self::T - 35 ]);
        self::assertTrue(serialize_wporg_import($marker->ID, self::T, self::WAITING)['watch'], 'the stamp can end it: the client watches');

        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, 'garbage');
        self::assertNull(serialize_wporg_import($marker->ID, self::T, self::LANDED), 're-validation when reading back from persistence');
        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T, 'reason' => 'trunk', 'computed_at' => self::T - 900 ]);
        self::assertNull(serialize_wporg_import($marker->ID, self::T, self::LANDED), 'a forecast of an earlier shape is none');
    }

    public function test_a_shown_forecast_is_recomputed_on_load_once_it_is_older_than_the_watchers_interval(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T, self::WAITING), 'nothing stored');

        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T + 600, 'reason' => 'assets', 'assets' => true, 'stable_touched_at' => null, 'computed_at' => self::T ]);
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T + 10, self::WAITING), 'the reload right after the own commit');
        self::assertTrue(wporg_import_forecast_due($marker->ID, self::T + 30, self::WAITING));
        self::assertFalse(wporg_import_forecast_due($marker->ID, self::T + 600 + 180, self::WAITING), 'no longer shown');
    }

    public function test_the_stamp_confirms_what_the_forecast_expects_of_it(): void {
        $assets = [ 'expected_at' => self::T, 'reason' => 'assets', 'assets' => true, 'stable_touched_at' => null ];
        self::assertFalse(wporg_import_confirmed($assets, self::STAMP, self::WAITING));
        self::assertTrue(wporg_import_confirmed($assets, self::STAMP, self::LANDED));
        self::assertFalse(wporg_import_confirmed($assets, null, self::LANDED), 'no check yet: nothing confirms');

        // The current release's folder touched at T + 40: the stamp's last_updated, to the minute, is not before it.
        $stable = [ 'expected_at' => self::T + 75, 'reason' => 'tag', 'assets' => false, 'stable_touched_at' => self::T + 40 ];
        self::assertTrue(wporg_import_confirmed($stable, self::STAMP, self::WAITING), 'last_updated in the commit\'s minute; assets may wait, none are expected');
        self::assertFalse(wporg_import_confirmed($stable, [ ...self::STAMP, 'last_updated' => '2027-01-15 7:59am GMT' ], self::LANDED), 'last_updated before the commit: the version alone confirms nothing — it was the current one before the edit');
        self::assertFalse(wporg_import_confirmed($stable, [ ...self::STAMP, 'version' => '1.1.0' ], self::LANDED));
        self::assertFalse(wporg_import_confirmed($stable, [ ...self::STAMP, 'last_updated' => 'A' ], self::LANDED), 'a date the directory would not give');
        self::assertFalse(wporg_import_confirmed($stable, self::STAMP, [ ...self::LANDED, 'current_version' => null ]), 'no current release: nothing to compare with');
        self::assertFalse(wporg_import_confirmed($stable, null, self::LANDED));

        $both = [ ...$stable, 'assets' => true ];
        self::assertFalse(wporg_import_confirmed($both, self::STAMP, self::WAITING), 'the assets have not shown yet');
        self::assertTrue(wporg_import_confirmed($both, self::STAMP, self::LANDED));

        self::assertFalse(wporg_import_confirmed([ 'expected_at' => self::T, 'reason' => 'trunk', 'assets' => false, 'stable_touched_at' => null ], self::STAMP, self::LANDED), 'nothing expected of the stamp: the forecast ends by time alone');
    }

    public function test_a_confirmed_forecast_is_neither_served_nor_recomputed(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_IMPORT_META, [ 'expected_at' => self::T + 600, 'reason' => 'assets', 'assets' => true, 'stable_touched_at' => null, 'computed_at' => self::T ]);
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
