<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\asset_imported;
use function Pblsh\assets_import_waiting;
use function Pblsh\assets_shown;

/**
 * What the plugin page shows of a marker's assets (includes/wporg_assets.php), read from the
 * directory stamp against the mirror: whether wordpress.org's import has read a slot's file,
 * which file the page shows for a slot, and whether an import is still waited for.
 */
final class WporgAssetsImportTest extends TestCase {

    private static function stamp(array $assets): array {
        return [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => $assets ];
    }

    public function test_a_file_is_imported_once_the_stamp_names_it_at_or_after_the_mirrors_revision(): void {
        $file = [ 'filename' => 'icon-128x128.png', 'revision' => 9 ];

        self::assertNull(asset_imported(null, $file), 'no check yet: unknown');
        self::assertFalse(asset_imported(self::stamp([ 'icon.svg' => 12 ]), $file), 'the stamp has no such file');
        self::assertFalse(asset_imported(self::stamp([ 'icon-128x128.png' => 7 ]), $file), 'an older import: the file changed since');
        self::assertTrue(asset_imported(self::stamp([ 'icon-128x128.png' => 9 ]), $file));
        self::assertTrue(asset_imported(self::stamp([ 'icon-128x128.png' => 12 ]), $file), 'the import exports the whole plugin at its revision then');
        self::assertFalse(asset_imported(self::stamp([ 'icon-128x128.png' => 9 ]), [ 'filename' => 'icon-128x128.jpg', 'revision' => 9 ]), 'a changed filename is another file');
    }

    public function test_the_blueprint_is_judged_like_every_other_file(): void {
        $file = [ 'filename' => 'blueprint.json', 'revision' => 9 ];

        self::assertFalse(asset_imported(self::stamp([ 'blueprint.json' => 7 ]), $file));
        self::assertTrue(asset_imported(self::stamp([ 'blueprint.json' => 9 ]), $file));
    }

    public function test_the_page_shows_a_file_per_slot_by_the_filename_rule(): void {
        self::assertSame([], assets_shown(null), 'no check yet');

        $shown = assets_shown(self::stamp([
            'banner-1544x500.jpg' => 3, 'banner-772x250.png' => 3, 'blueprint-x.json' => 5, 'blueprint.json' => 5,
            'icon-128x128.png' => 4, 'icon-256x256.png' => 4, 'icon.svg' => 2, 'screenshot-1.png' => 6, 'screenshot-10.gif' => 6,
        ]));

        self::assertSame([
            'banner_hd' => 'banner-1544x500.jpg', 'banner_sd' => 'banner-772x250.png', 'blueprint' => 'blueprint.json',
            'icon_128' => 'icon-128x128.png', 'icon_256' => 'icon-256x256.png', 'icon_svg' => 'icon.svg',
            'screenshot-1' => 'screenshot-1.png', 'screenshot-10' => 'screenshot-10.gif',
        ], $shown, 'the other blueprint files have no slot');
    }

    public function test_nothing_waits_while_the_page_shows_what_the_mirror_holds(): void {
        $mirror = [ 'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 9 ], 'blueprint' => [ 'filename' => 'blueprint.json', 'revision' => 7 ] ];

        self::assertFalse(assets_import_waiting(self::stamp([ 'icon-128x128.png' => 9, 'blueprint.json' => 12 ]), $mirror));
        self::assertFalse(assets_import_waiting(null, $mirror), 'unknown is no waiting');
        self::assertFalse(assets_import_waiting(self::stamp([]), []), 'nothing here, nothing there');
    }

    public function test_a_slot_the_import_has_not_read_waits(): void {
        $mirror = [ 'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 9 ] ];

        self::assertTrue(assets_import_waiting(self::stamp([ 'icon-128x128.png' => 7 ]), $mirror), 'an older revision on the page');
        self::assertTrue(assets_import_waiting(self::stamp([]), $mirror), 'not on the page at all');
        self::assertTrue(assets_import_waiting(self::stamp([ 'icon-128x128.png' => 9 ]), [ 'icon_128' => [ 'filename' => 'icon-128x128.jpg', 'revision' => 9 ] ]), 'the filename changed: the page still shows the old file');
    }

    public function test_a_deleted_slot_the_page_still_shows_waits(): void {
        self::assertTrue(assets_import_waiting(self::stamp([ 'screenshot-3.png' => 7 ]), []));
        self::assertTrue(assets_import_waiting(self::stamp([ 'blueprint.json' => 7 ]), []), 'the Live Preview too');
        self::assertFalse(assets_import_waiting(self::stamp([ 'blueprint-x.json' => 7, 'screenshot-0.png' => 7 ]), []), 'files of no slot are nobody\'s wait');
    }
}
