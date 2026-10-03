<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\apply_asset_change;
use function Pblsh\asset_conflicts;
use function Pblsh\copy_sources_to_secure;
use function Pblsh\effective_asset_slots;

/**
 * The working copy of a wordpress.org plugin's assets (includes/wporg_assets.php): every change
 * is recorded as one entry per slot when it is made; conflicts are entries whose base the
 * mirror no longer has.
 */
final class AssetWorkingCopyTest extends TestCase {

    private const ACTOR = [ 'at' => 100, 'user' => [ 'id' => 1, 'login' => 'admin' ] ];

    private static function mirror(): array {
        return [
            'screenshot-1' => [ 'filename' => 'screenshot-1.jpg', 'revision' => 10, 'resolution' => '1', 'filesize' => 500, 'width' => 800, 'height' => 600 ],
            'screenshot-3' => [ 'filename' => 'screenshot-3.png', 'revision' => 11, 'resolution' => '3', 'filesize' => 700, 'width' => 800, 'height' => 600 ],
            'icon_128' => [ 'filename' => 'Icon-128x128.PNG', 'revision' => 12, 'resolution' => '128x128', 'filesize' => 90, 'width' => 128, 'height' => 128 ],
        ];
    }

    private static function put(string $slot, string $ext): array {
        return [ 'action' => 'put', 'slot' => $slot, 'ext' => $ext, 'filesize' => 300, 'width' => 128, 'height' => 128, ...self::ACTOR ];
    }

    public function test_an_upload_is_a_put_on_its_canonical_name_with_the_mirror_as_base(): void {
        $result = apply_asset_change([], self::mirror(), self::put('icon_128', 'png'));

        self::assertSame('put', $result['pending']['icon_128']['action']);
        self::assertSame('icon-128x128.png', $result['pending']['icon_128']['file'], 'canonical: lowercase, whatever the mirror file is called');
        self::assertSame([ 'filename' => 'Icon-128x128.PNG', 'revision' => 12 ], $result['pending']['icon_128']['base']);

        $again = apply_asset_change($result['pending'], self::mirror(), self::put('icon_128', 'gif'));
        self::assertSame('icon-128x128.gif', $again['pending']['icon_128']['file']);
        self::assertSame([ 'icon-128x128.png' ], $again['removes'], 'the replaced pending file goes');
    }

    public function test_deleting_a_mirror_slot_is_a_delete_and_a_never_committed_upload_just_vanishes(): void {
        $deleted = apply_asset_change([], self::mirror(), [ 'action' => 'delete', 'slot' => 'icon_128', ...self::ACTOR ]);
        self::assertSame([ 'action' => 'delete', 'at' => 100, 'user' => self::ACTOR['user'], 'base' => [ 'filename' => 'Icon-128x128.PNG', 'revision' => 12 ] ], $deleted['pending']['icon_128']);

        $uploaded = apply_asset_change([], self::mirror(), self::put('banner_sd', 'png'));
        $gone = apply_asset_change($uploaded['pending'], self::mirror(), [ 'action' => 'delete', 'slot' => 'banner_sd', ...self::ACTOR ]);
        self::assertSame([], $gone['pending']);
        self::assertSame([ 'banner-772x250.png' ], $gone['removes']);
    }

    public function test_a_swap_copies_both_mirror_files_and_a_move_onto_an_empty_slot_copies_and_deletes(): void {
        $swap = apply_asset_change([], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 1, ...self::ACTOR ]);
        self::assertSame('swap', $swap['mode']);
        self::assertSame([ 'slot' => 'screenshot-3', 'filename' => 'screenshot-3.png', 'revision' => 11 ], $swap['pending']['screenshot-1']['from']);
        self::assertSame('png', $swap['pending']['screenshot-1']['ext']);
        self::assertSame([ 'slot' => 'screenshot-1', 'filename' => 'screenshot-1.jpg', 'revision' => 10 ], $swap['pending']['screenshot-3']['from']);
        self::assertSame([], $swap['renames'], 'copies move no bytes');

        $move = apply_asset_change([], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 5, ...self::ACTOR ]);
        self::assertSame('move', $move['mode']);
        self::assertSame('copy', $move['pending']['screenshot-5']['action']);
        self::assertNull($move['pending']['screenshot-5']['base']);
        self::assertSame('delete', $move['pending']['screenshot-3']['action']);
    }

    public function test_swap_moves_a_pending_upload_along(): void {
        $uploaded = apply_asset_change([], self::mirror(), [ ...self::put('screenshot-3', 'gif'), 'slot' => 'screenshot-3' ]);

        $swap = apply_asset_change($uploaded['pending'], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 1, ...self::ACTOR ]);

        self::assertSame('put', $swap['pending']['screenshot-1']['action'], 'the upload moves, not the mirror state behind it');
        self::assertSame('screenshot-1.gif', $swap['pending']['screenshot-1']['file']);
        self::assertSame([ 'filename' => 'screenshot-1.jpg', 'revision' => 10 ], $swap['pending']['screenshot-1']['base'], 'the base is the target slot\'s mirror state');
        self::assertSame([ [ 'screenshot-3.gif', 'screenshot-1.gif' ] ], $swap['renames']);
        self::assertSame('copy', $swap['pending']['screenshot-3']['action'], 'slot 3 receives slot 1\'s mirror file');
    }

    public function test_swapping_back_leaves_nothing_pending(): void {
        $once = apply_asset_change([], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 1, ...self::ACTOR ]);
        $back = apply_asset_change($once['pending'], self::mirror(), [ 'action' => 'move', 'from' => 1, 'to' => 3, ...self::ACTOR ]);

        self::assertSame([], $back['pending'], 'a copy of a slot\'s own mirror state is no change');
    }

    public function test_conflicts_are_entries_whose_base_the_mirror_no_longer_has(): void {
        $pending = apply_asset_change([], self::mirror(), self::put('icon_128', 'png'))['pending'];
        $pending += apply_asset_change([], self::mirror(), self::put('banner_sd', 'png'))['pending'];
        self::assertSame([], asset_conflicts($pending, self::mirror()));

        $changed = self::mirror();
        $changed['icon_128']['revision'] = 20;
        $changed['banner_sd'] = [ 'filename' => 'banner-772x250.jpg', 'revision' => 21, 'resolution' => '772x250', 'filesize' => 1, 'width' => null, 'height' => null ];
        self::assertSame([ 'icon_128', 'banner_sd' ], asset_conflicts($pending, $changed), 'changed under a pending upload, and appeared under one');
    }

    public function test_copy_sources_the_pull_replaces_are_secured(): void {
        $pending = apply_asset_change([], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 1, ...self::ACTOR ])['pending'];
        $remote = self::mirror();
        $remote['screenshot-3']['revision'] = 30;   // someone replaced screenshot 3 on wordpress.org

        self::assertSame([ 'screenshot-1' ], copy_sources_to_secure($pending, $remote), 'slot 1 copies slot 3\'s old file — it must become a put before the pull replaces it');
        self::assertSame([ 'screenshot-3' ], asset_conflicts($pending, $remote), 'slot 3 itself was changed under its own entry');
    }

    public function test_the_effective_slots_combine_mirror_and_working_copy(): void {
        $pending = apply_asset_change([], self::mirror(), [ 'action' => 'move', 'from' => 3, 'to' => 7, ...self::ACTOR ])['pending'];

        self::assertSame([ 'screenshot-1', 'icon_128', 'screenshot-7' ], effective_asset_slots($pending, self::mirror()));
    }
}
