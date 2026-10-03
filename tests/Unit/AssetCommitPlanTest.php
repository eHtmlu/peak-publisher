<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\asset_listing_after_plan;
use function Pblsh\build_asset_commit_plan;

/**
 * The one commit of a working copy (includes/wporg_assets.php): puts, copies from the base
 * revision, deletes of every slot member — and the listing it leaves behind.
 */
final class AssetCommitPlanTest extends TestCase {

    private static function listing(string ...$names): array {
        return [ 'revision' => 50, 'entries' => array_map(static fn(string $name): array => [ 'name' => $name, 'type' => 'file', 'size' => str_contains($name, 'big') ? 5 * 1048576 : 100, 'revision' => 40 ], $names) ];
    }

    private static function copy(string $from_slot, string $from_file, string $ext): array {
        return [ 'action' => 'copy', 'from' => [ 'slot' => $from_slot, 'filename' => $from_file, 'revision' => 40 ], 'ext' => $ext, 'filesize' => 100, 'width' => null, 'height' => null, 'base' => null ];
    }

    public function test_put_deletes_every_other_member_but_not_its_target(): void {
        $plan = build_asset_commit_plan('my-plugin', [ 'icon_128' => [ 'action' => 'put', 'file' => 'icon-128x128.png', 'ext' => 'png', 'base' => null ] ],
            self::listing('Icon-128x128.PNG', 'icon-128x128-de_DE.png', 'icon-128x128.jpeg', 'icon-128x128.png'), 60, '/tmp/pending');

        self::assertSame([ [ 'path' => 'assets/icon-128x128.png', 'local_path' => '/tmp/pending/icon-128x128.png' ] ], $plan['puts']);
        self::assertSame([ 'assets/Icon-128x128.PNG', 'assets/icon-128x128.jpeg' ], $plan['deletes'], 'the PUT replaces its own target; the localized variant is no member');
        self::assertSame([], $plan['copies']);
        self::assertSame([], $plan['mkdirs']);
        self::assertSame('Update assets of my-plugin (1 updated) via Peak Publisher', $plan['message']);
    }

    public function test_swap_with_equal_extensions_replaces_both_paths(): void {
        $plan = build_asset_commit_plan('my-plugin', [
            'screenshot-1' => self::copy('screenshot-3', 'screenshot-3.png', 'png'),
            'screenshot-3' => self::copy('screenshot-1', 'screenshot-1.png', 'png'),
        ], self::listing('screenshot-1.png', 'screenshot-3.png'), 60, '/tmp/pending');

        self::assertSame([
            [ 'path' => 'assets/screenshot-1.png', 'from_path' => 'assets/screenshot-3.png', 'from_revision' => 60 ],
            [ 'path' => 'assets/screenshot-3.png', 'from_path' => 'assets/screenshot-1.png', 'from_revision' => 60 ],
        ], $plan['copies'], 'both from the base revision — no intermediate name');
        self::assertSame([ 'assets/screenshot-1.png', 'assets/screenshot-3.png' ], $plan['deletes'], 'a COPY needs its target gone first (R in the log)');
        self::assertSame('Update assets of my-plugin (2 moved) via Peak Publisher', $plan['message']);
    }

    public function test_a_copy_onto_an_ignored_canonical_file_deletes_it_first(): void {
        $plan = build_asset_commit_plan('my-plugin', [ 'screenshot-2' => self::copy('screenshot-1', 'screenshot-1.png', 'png') ],
            self::listing('screenshot-1.png', 'screenshot-2.png-big'), 60, '/tmp/pending');
        self::assertSame([], $plan['deletes'], 'a non-canonical file is not in the way');

        $plan = build_asset_commit_plan('my-plugin', [ 'icon_128' => self::copy('icon_256', 'icon-256x256.png', 'png') ],
            [ 'revision' => 50, 'entries' => [ [ 'name' => 'icon-128x128.png', 'type' => 'file', 'size' => 2 * 1048576, 'revision' => 1 ], [ 'name' => 'icon-256x256.png', 'type' => 'file', 'size' => 9, 'revision' => 1 ] ] ], 60, '/tmp/pending');
        self::assertSame([ 'assets/icon-128x128.png' ], $plan['deletes'], 'the oversize file holds the target path — no member, but in the way of the COPY');
    }

    public function test_delete_removes_every_member_and_the_first_commit_creates_the_directory(): void {
        $plan = build_asset_commit_plan('my-plugin', [ 'banner_hd' => [ 'action' => 'delete', 'base' => [ 'filename' => 'banner-1544x500.png', 'revision' => 40 ] ] ],
            self::listing('banner-1544x500.jpg', 'banner-1544x500.png', 'banner-1544x500-rtl.png'), 60, '/tmp/pending');
        self::assertSame([ 'assets/banner-1544x500.png', 'assets/banner-1544x500.jpg' ], $plan['deletes']);
        self::assertSame('Update assets of my-plugin (1 deleted) via Peak Publisher', $plan['message']);

        $first = build_asset_commit_plan('my-plugin', [ 'icon_128' => [ 'action' => 'put', 'file' => 'icon-128x128.png', 'ext' => 'png', 'base' => null ] ], null, 60, '/tmp/pending');
        self::assertSame([ 'assets' ], $first['mkdirs']);

        $empty = build_asset_commit_plan('my-plugin', [], self::listing('icon.svg'), 60, '/tmp/pending');
        self::assertSame([ [], [], [] ], [ $empty['deletes'], $empty['copies'], $empty['puts'] ]);
    }

    public function test_the_listing_after_the_plan_needs_no_second_read(): void {
        $listing = self::listing('screenshot-1.jpg', 'screenshot-3.png');
        $plan = build_asset_commit_plan('my-plugin', [
            'screenshot-1' => self::copy('screenshot-3', 'screenshot-3.png', 'png'),
            'screenshot-3' => self::copy('screenshot-1', 'screenshot-1.jpg', 'jpg'),
        ], $listing, 60, '/tmp/pending');

        self::assertSame([
            [ 'name' => 'screenshot-1.png', 'type' => 'file', 'size' => 100, 'revision' => 61 ],
            [ 'name' => 'screenshot-3.jpg', 'type' => 'file', 'size' => 100, 'revision' => 61 ],
        ], asset_listing_after_plan($listing['entries'], $plan, 61, [ 'screenshot-1.png' => 100, 'screenshot-3.jpg' => 100 ]));
    }
}
