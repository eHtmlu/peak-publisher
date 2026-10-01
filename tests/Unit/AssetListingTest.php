<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\asset_dimension_warnings;
use function Pblsh\classify_asset_listing;
use function Pblsh\get_asset_slots;
use function Pblsh\get_plugin_assets_dir;
use function Pblsh\list_asset_directory;
use function Pblsh\next_screenshot_number;
use function Pblsh\read_asset_manifest;
use function Pblsh\select_banner_color_source;
use function Pblsh\write_asset_manifest;

/**
 * An assets directory as wordpress.org reads it (includes/assets.php): one winner and its
 * members per slot, everything else counted, the banner that colors the generated icon —
 * and the manifest format both channels store.
 */
final class AssetListingTest extends TestCase {

    private static function entry(string $name, int $size = 1000, int $revision = 100, string $type = 'file'): array {
        return [ 'name' => $name, 'type' => $type, 'size' => $type === 'dir' ? null : $size, 'revision' => $revision ];
    }

    public function test_variants_of_one_slot_have_one_winner_and_all_are_members(): void {
        $listing = classify_asset_listing([ self::entry('Icon-128x128.PNG', revision: 3), self::entry('icon-128x128.jpeg'), self::entry('icon-128x128.png') ]);

        self::assertSame([ 'filename' => 'Icon-128x128.PNG', 'revision' => 3, 'resolution' => '128x128', 'filesize' => 1000 ], $listing['slots']['icon_128']['winner'], 'png beats jpeg; equal extensions keep the listing order (uppercase sorts first)');
        self::assertSame([ 'Icon-128x128.PNG', 'icon-128x128.jpeg', 'icon-128x128.png' ], $listing['slots']['icon_128']['members'], 'winner first — every member goes on the next replace or delete');
        self::assertSame(0, $listing['other_files']);
    }

    public function test_localized_rtl_oversize_and_foreign_files_are_counted_not_managed(): void {
        $listing = classify_asset_listing([
            self::entry('banner-1000x300.png'), self::entry('banner-772x250-de_DE.png'), self::entry('banner-772x250-rtl.png'),
            self::entry('banner.svg'), self::entry('blueprints', type: 'dir'), self::entry('editor-screen-1.png'),
            self::entry('icon-128x128.png', 2 * 1048576), self::entry('icon-256x256.png', 0), self::entry('screenshot-0.png'),
        ]);

        self::assertSame([], $listing['slots'], 'banner.svg is no slot while the filter is off; oversize and empty files are invisible to wordpress.org');
        self::assertSame(9, $listing['other_files']);
    }

    public function test_screenshot_numbers_are_normalized(): void {
        $listing = classify_asset_listing([ self::entry('screenshot-01.png'), self::entry('screenshot-1.png'), self::entry('screenshot-3.jpg') ]);

        self::assertSame('screenshot-01.png', $listing['slots']['screenshot-1']['winner']['filename'], 'same extension: listing order');
        self::assertSame('1', $listing['slots']['screenshot-1']['winner']['resolution']);
        self::assertSame([ 'screenshot-01.png', 'screenshot-1.png' ], $listing['slots']['screenshot-1']['members']);
        self::assertSame(4, next_screenshot_number(array_keys($listing['slots'])));
        self::assertSame(1, next_screenshot_number([ 'icon_128' ]));
    }

    public function test_the_banner_color_source_is_the_first_valid_banner_file_by_name(): void {
        $entries = [ self::entry('banner-1544x500.png', 5 * 1048576), self::entry('banner-772x250-de_DE.png', revision: 7), self::entry('banner.svg', revision: 9) ];

        self::assertSame([ 'filename' => 'banner-772x250-de_DE.png', 'revision' => 7 ], select_banner_color_source($entries), 'oversize skipped; a localized banner counts');
        self::assertNull(select_banner_color_source([ self::entry('icon.svg') ]));
        self::assertSame([ 'filename' => 'banner-772x250-de_DE.png', 'revision' => 7 ], classify_asset_listing($entries)['color_source']);
    }

    public function test_the_manifest_round_trips_and_drops_foreign_shapes(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $manifest = [
            'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 5, 'resolution' => '128x128', 'filesize' => 900, 'width' => 128, 'height' => 128 ],
            'screenshot-2' => [ 'filename' => 'screenshot-2.png', 'revision' => 6, 'resolution' => '2', 'filesize' => 800, 'width' => null, 'height' => null ],
        ];

        write_asset_manifest($plugin->ID, $manifest);

        self::assertSame([ 'icon-128x128.png' ], array_keys(get_post_meta($plugin->ID, 'assets_icons', true)), 'wordpress.org\'s metas, keyed by filename');
        self::assertSame([], get_post_meta($plugin->ID, 'assets_banners', true));
        self::assertSame($manifest, read_asset_manifest($plugin->ID));

        update_post_meta($plugin->ID, 'assets_icons', [ 'readme.txt' => [ 'revision' => 1 ], 'icon.svg' => 'garbage', 'banner-772x250.png' => [ 'revision' => 1 ] ]);
        self::assertSame([ 'screenshot-2' ], array_keys(read_asset_manifest($plugin->ID)), 'unknown names, foreign shapes and entries under the wrong type are dropped');
    }

    public function test_a_local_directory_lists_like_svn(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $dir = get_plugin_assets_dir($plugin);
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/icon-128x128.png', 'x');
        file_put_contents($dir . '/Banner-772x250.png', 'xy');
        mkdir($dir . '/sub');

        $entries = list_asset_directory($dir);

        self::assertSame([ 'Banner-772x250.png', 'icon-128x128.png', 'sub' ], array_column($entries, 'name'), 'bytewise by name');
        self::assertSame([ 2, 1, null ], array_column($entries, 'size'));
        self::assertSame(FakeWordPress::$upload_dir . '/pblsh-peak-publisher/plugins/my-plugin/assets', $dir);
        self::assertSame(FakeWordPress::$upload_dir . '/pblsh-peak-publisher/wporg-plugins/my-plugin/mirror/assets', get_plugin_assets_dir($this->create_plugin('pblsh_wporg_plugin', 'my-plugin')), 'a channel tree of its own — the same slug may exist on both channels');
        self::assertSame([], list_asset_directory($dir . '/missing'));
    }

    public function test_dimension_warnings_name_expected_and_found(): void {
        $slot = get_asset_slots()['icon_128'];
        self::assertSame([], asset_dimension_warnings([ 'width' => 128, 'height' => 128 ], $slot));
        self::assertSame([], asset_dimension_warnings([ 'width' => null, 'height' => null ], $slot), 'unmeasured: no warning');
        self::assertSame('wrong_dimensions', asset_dimension_warnings([ 'width' => 200, 'height' => 128 ], $slot)[0]['code']);
        self::assertSame([], asset_dimension_warnings([ 'width' => 640, 'height' => 480 ], get_asset_slots()['screenshot']));
    }
}
