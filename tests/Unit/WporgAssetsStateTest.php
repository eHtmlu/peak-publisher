<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\get_wporg_assets_state;
use function Pblsh\mirror_diff;
use function Pblsh\update_wporg_assets_state;

use const Pblsh\PBLSH_WPORG_ASSETS_META;

/**
 * The wordpress.org assets state of a marker (includes/wporg_assets.php): the mirror's anchor
 * and what the pull must fetch or remove.
 */
final class WporgAssetsStateTest extends TestCase {

    public function test_the_state_reads_back_with_defaults_and_drops_foreign_keys(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        self::assertSame([ 'revision' => null, 'listed_at' => 0, 'other_files' => 0, 'color_source' => null, 'pending' => [] ], get_wporg_assets_state($marker->ID));

        update_post_meta($marker->ID, PBLSH_WPORG_ASSETS_META, [ 'revision' => 7, 'junk' => 1, 'pending' => 'garbage' ]);
        self::assertSame([ 'revision' => 7, 'listed_at' => 0, 'other_files' => 0, 'color_source' => null, 'pending' => [] ], get_wporg_assets_state($marker->ID));

        update_wporg_assets_state($marker->ID, [ ...get_wporg_assets_state($marker->ID), 'other_files' => 3, 'junk' => 2 ]);
        self::assertSame(3, get_wporg_assets_state($marker->ID)['other_files']);
        self::assertArrayNotHasKey('junk', get_post_meta($marker->ID, PBLSH_WPORG_ASSETS_META, true));
    }

    public function test_the_pull_fetches_changed_winners_and_removes_vanished_slots(): void {
        $mirror = [
            'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 5, 'filesize' => 10 ],
            'banner_sd' => [ 'filename' => 'banner-772x250.png', 'revision' => 5, 'filesize' => 10 ],
            'screenshot-1' => [ 'filename' => 'screenshot-1.png', 'revision' => 5, 'filesize' => 10 ],
        ];
        $remote = [
            'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 5, 'filesize' => 10 ],
            'banner_sd' => [ 'filename' => 'banner-772x250.jpg', 'revision' => 9, 'filesize' => 12 ],
            'screenshot-2' => [ 'filename' => 'screenshot-2.png', 'revision' => 9, 'filesize' => 12 ],
        ];

        self::assertSame([ 'fetch' => [ 'banner_sd', 'screenshot-2' ], 'remove' => [ 'screenshot-1' ] ], mirror_diff($mirror, $remote));
    }

    public function test_a_new_revision_of_the_same_file_is_fetched_again(): void {
        $mirror = [ 'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 5 ] ];
        $remote = [ 'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 6 ] ];

        self::assertSame([ 'fetch' => [ 'icon_128' ], 'remove' => [] ], mirror_diff($mirror, $remote));
    }
}
