<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\confirm_wporg_directory_check;
use function Pblsh\get_wporg_assets_state;
use function Pblsh\mark_wporg_plugin_cache_stale;
use function Pblsh\record_wporg_live_preview_check;
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

    public function test_the_token_moves_with_everything_a_clients_copy_is_made_from(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 7, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        $id = $marker->ID;
        $token = static fn(): string => wporg_sync_token(get_post($id));
        $tokens = [ 'as imported' => $token() ];
        self::assertSame($tokens['as imported'], $token(), 'stable while nothing moved');
        self::assertStringStartsWith('7:-:', $tokens['as imported'], 'the cached revision, the mirror never pulled');

        update_wporg_assets_state($id, [ ...wporg_assets_state_defaults(), 'revision' => 5 ]);
        $tokens['the mirror pulled'] = $token();
        self::assertStringStartsWith('7:5:', $tokens['the mirror pulled']);
        update_wporg_assets_state($id, [ ...wporg_assets_state_defaults(), 'revision' => 0 ]);
        $tokens['no assets directory, a state of its own'] = $token();

        $entry = [ 'action' => 'put', 'file' => 'icon-128x128.png', 'base' => null, 'ext' => 'png', 'filesize' => 300, 'width' => 128, 'height' => 128, 'at' => 100, 'user' => [ 'id' => 1, 'login' => 'admin' ] ];
        update_wporg_assets_state($id, [ ...get_wporg_assets_state($id), 'pending' => [ 'icon_128' => $entry ] ]);
        $tokens['a change of the working copy — another tab, another user'] = $token();

        $stamp = [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [ 'icon.svg' => 12 ] ];
        confirm_wporg_directory_check($id, $stamp);
        $tokens['the first directory check'] = $token();
        confirm_wporg_directory_check($id, [ ...$stamp, 'assets' => [ 'icon.svg' => 13 ] ]);
        $tokens["wordpress.org's import of an own commit: the stamp alone"] = $token();

        record_wporg_live_preview_check($id, true, 1000);
        $tokens['a check of the public preview'] = $token();

        mark_wporg_plugin_cache_stale($id);
        $tokens['a commit of our own'] = $token();
        self::assertStringStartsWith('-:0:', $tokens['a commit of our own']);

        self::assertCount(count($tokens), array_unique($tokens), 'every move leaves a token of its own: ' . implode(' · ', array_keys($tokens)));
    }
}
