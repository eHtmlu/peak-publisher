<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\mark_wporg_plugin_cache_stale;
use function Pblsh\update_wporg_assets_state;
use function Pblsh\wporg_assets_state_defaults;
use function Pblsh\wporg_refresh_changes;
use function Pblsh\wporg_sync_token;

/**
 * What a marker's SVN refresh reports (includes/wporg_cache.php): the changes behind the
 * editor's "changed on wordpress.org" notice — nothing for a commit of Peak Publisher's own —
 * and the token a client's copy of the marker was made from.
 */
final class WporgRefreshTest extends TestCase {

    private const READ = [ 'stable_tag' => '1.0.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];

    public function test_a_refresh_that_found_what_the_cache_held_changed_nothing(): void {
        $summary = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 3, 'release_count' => 3 ];

        self::assertSame([], wporg_refresh_changes($summary, self::READ, self::READ, false), 'an own deploy: tags and readme were written through');
        self::assertSame([], wporg_refresh_changes([], self::READ, self::READ, false), 'the revision did not move');
    }

    public function test_releases_added_removed_and_tagged_anew_are_counted(): void {
        $summary = [ 'created' => 2, 'updated' => 1, 'deleted' => 1, 'unchanged' => 4, 'release_count' => 7 ];

        self::assertSame(
            [ 'releases_added' => 2, 'releases_removed' => 1, 'releases_updated' => 1 ],
            wporg_refresh_changes($summary, self::READ, self::READ, false)
        );
    }

    public function test_a_moved_stable_tag_is_a_changed_current_release_when_both_readings_are_known(): void {
        $flipped = [ ...self::READ, 'stable_tag' => '1.1.0' ];

        self::assertSame([ 'current_release' => true ], wporg_refresh_changes([], self::READ, $flipped, false));
        self::assertSame([], wporg_refresh_changes([], null, $flipped, false), 'no reading before: nothing to compare');
        self::assertSame([], wporg_refresh_changes([], self::READ, null, false), 'the readme could not be read');
    }

    public function test_a_pull_that_changed_the_mirror_is_reported(): void {
        self::assertSame([ 'assets' => true ], wporg_refresh_changes([], self::READ, self::READ, true));
    }

    public function test_the_token_moves_with_the_cached_revision_and_the_mirror(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 7, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        self::assertSame('7:-', wporg_sync_token($marker), 'the mirror was never pulled');

        update_wporg_assets_state($marker->ID, [ ...wporg_assets_state_defaults(), 'revision' => 5 ]);
        self::assertSame('7:5', wporg_sync_token($marker));

        update_wporg_assets_state($marker->ID, [ ...wporg_assets_state_defaults(), 'revision' => 0 ]);
        self::assertSame('7:0', wporg_sync_token($marker), 'no assets directory is a state of its own');

        mark_wporg_plugin_cache_stale($marker->ID);
        self::assertSame('-:0', wporg_sync_token(get_post($marker->ID)), 'a commit of our own moves the token for every other client');
    }
}
