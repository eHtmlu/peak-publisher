<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\asset_listing_after_plan;
use function Pblsh\build_asset_commit_plan;

/**
 * The one commit of a working copy (includes/wporg_assets.php): puts, copies from the base
 * revision, deletes of every slot member — its message, and the listing it leaves behind.
 */
final class AssetCommitPlanTest extends TestCase {

    private static function listing(string ...$names): array {
        return [ 'revision' => 50, 'entries' => array_map(static fn(string $name): array => [ 'name' => $name, 'type' => 'file', 'size' => str_contains($name, 'big') ? 5 * 1048576 : 100, 'revision' => 40 ], $names) ];
    }

    private static function copy(string $from_slot, string $from_file, string $ext, ?array $base = null): array {
        return [ 'action' => 'copy', 'from' => [ 'slot' => $from_slot, 'filename' => $from_file, 'revision' => 40 ], 'ext' => $ext, 'filesize' => 100, 'width' => null, 'height' => null, 'base' => $base ];
    }

    public function test_put_deletes_every_other_member_but_not_its_target(): void {
        $plan = build_asset_commit_plan('my-plugin', [ 'icon_128' => [ 'action' => 'put', 'file' => 'icon-128x128.png', 'ext' => 'png', 'base' => null ] ],
            self::listing('Icon-128x128.PNG', 'icon-128x128-de_DE.png', 'icon-128x128.jpeg', 'icon-128x128.png'), 60, '/tmp/pending');

        self::assertSame([ [ 'path' => 'assets/icon-128x128.png', 'local_path' => '/tmp/pending/icon-128x128.png' ] ], $plan['puts']);
        self::assertSame([ 'assets/Icon-128x128.PNG', 'assets/icon-128x128.jpeg' ], $plan['deletes'], 'the PUT replaces its own target; the localized variant is no member');
        self::assertSame([], $plan['copies']);
        self::assertSame([], $plan['mkdirs']);
        self::assertSame([ [ 'path' => 'assets/icon-128x128.png', 'type' => 'image/png' ] ], $plan['mime_types'], 'the type the handbook asks for, from the detected content');
        self::assertSame('Add icon to my-plugin via Peak Publisher', $plan['message'], 'no base: the slot was empty');

        $svg = build_asset_commit_plan('my-plugin', [ 'icon_svg' => [ 'action' => 'put', 'file' => 'icon.svg', 'ext' => 'svg', 'base' => null ] ], self::listing(), 60, '/tmp/pending');
        self::assertSame([], $svg['mime_types'], 'an SVG stays without a property: it is text to SVN, and the origin types .svg by extension');

        $jpeg = build_asset_commit_plan('my-plugin', [ 'screenshot-1' => self::copy('screenshot-3', 'screenshot-3.jpeg', 'jpeg') ], self::listing('screenshot-3.jpeg'), 60, '/tmp/pending');
        self::assertSame([ [ 'path' => 'assets/screenshot-1.jpg', 'type' => 'image/jpeg' ] ], $jpeg['mime_types'], 'a copy carries the extension as the filename rule read it');
    }

    public function test_swap_with_equal_extensions_replaces_both_paths(): void {
        $plan = build_asset_commit_plan('my-plugin', [
            'screenshot-1' => self::copy('screenshot-3', 'screenshot-3.png', 'png', [ 'filename' => 'screenshot-1.png', 'revision' => 40 ]),
            'screenshot-3' => self::copy('screenshot-1', 'screenshot-1.png', 'png', [ 'filename' => 'screenshot-3.png', 'revision' => 40 ]),
        ], self::listing('screenshot-1.png', 'screenshot-3.png'), 60, '/tmp/pending');

        self::assertSame([
            [ 'path' => 'assets/screenshot-1.png', 'from_path' => 'assets/screenshot-3.png', 'from_revision' => 60 ],
            [ 'path' => 'assets/screenshot-3.png', 'from_path' => 'assets/screenshot-1.png', 'from_revision' => 60 ],
        ], $plan['copies'], 'both from the base revision — no intermediate name');
        self::assertSame([ 'assets/screenshot-1.png', 'assets/screenshot-3.png' ], $plan['deletes'], 'a COPY needs its target gone first (R in the log)');
        self::assertSame([ [ 'path' => 'assets/screenshot-1.png', 'type' => 'image/png' ], [ 'path' => 'assets/screenshot-3.png', 'type' => 'image/png' ] ], $plan['mime_types'], 'a copy inherits the source\'s property — set anew');
        self::assertSame('Update screenshots 1 and 3 of my-plugin via Peak Publisher', $plan['message']);
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
        self::assertSame('Remove banner from my-plugin via Peak Publisher', $plan['message']);

        $first = build_asset_commit_plan('my-plugin', [ 'icon_128' => [ 'action' => 'put', 'file' => 'icon-128x128.png', 'ext' => 'png', 'base' => null ] ], null, 60, '/tmp/pending');
        self::assertSame([ 'assets' ], $first['mkdirs']);

        $empty = build_asset_commit_plan('my-plugin', [], self::listing('icon.svg'), 60, '/tmp/pending');
        self::assertSame([ [], [], [], [] ], [ $empty['deletes'], $empty['copies'], $empty['puts'], $empty['mime_types'] ]);
    }

    public function test_a_blueprint_put_writes_its_file_and_creates_the_directories_it_needs(): void {
        $put = [ 'blueprint' => [ 'action' => 'put', 'contents' => '{}', 'base' => null ] ];

        $plan = build_asset_commit_plan('my-plugin', $put, self::listing('icon.svg'), 60, '/tmp/pending', '/tmp/blueprint-x.json');
        self::assertSame([ [ 'path' => 'assets/blueprints/blueprint.json', 'local_path' => '/tmp/blueprint-x.json' ] ], $plan['puts']);
        self::assertSame([ 'assets/blueprints' ], $plan['mkdirs'], 'blueprints/ is missing from the listing');
        self::assertSame([ '/tmp/blueprint-x.json' ], $plan['cleanup'], 'the temp file goes with the commit');
        self::assertSame([], $plan['mime_types'], 'text to SVN; wordpress.org serves it from its own store');
        self::assertSame([ 'blueprints/blueprint.json' ], $plan['details']['updated']);
        self::assertSame('Add Live Preview blueprint.json to my-plugin via Peak Publisher', $plan['message']);

        $first = build_asset_commit_plan('my-plugin', $put, null, 60, '/tmp/pending', '/tmp/blueprint-x.json');
        self::assertSame([ 'assets', 'assets/blueprints' ], $first['mkdirs']);

        $listing = self::listing('icon.svg');
        $listing['entries'][] = [ 'name' => 'blueprints', 'type' => 'dir', 'size' => null, 'revision' => 40 ];
        $existing = build_asset_commit_plan('my-plugin', [ ...$put, 'icon_128' => [ 'action' => 'put', 'file' => 'icon-128x128.png', 'ext' => 'png', 'base' => null ] ], $listing, 60, '/tmp/pending', '/tmp/blueprint-x.json');
        self::assertSame([], $existing['mkdirs']);
        self::assertSame('Add icon and Live Preview blueprint.json to my-plugin via Peak Publisher', $existing['message'], 'one commit for the images and the blueprint');
    }

    public function test_a_blueprint_delete_removes_the_file_and_leaves_the_directory(): void {
        $plan = build_asset_commit_plan('my-plugin', [ 'blueprint' => [ 'action' => 'delete', 'base' => [ 'filename' => 'blueprint.json', 'revision' => 40 ] ] ], self::listing('icon.svg'), 60, '/tmp/pending');
        self::assertSame([ 'assets/blueprints/blueprint.json' ], $plan['deletes']);
        self::assertSame([], $plan['mkdirs']);
        self::assertSame([ 'blueprints/blueprint.json' ], $plan['details']['deleted']);
        self::assertSame('Remove Live Preview blueprint.json from my-plugin via Peak Publisher', $plan['message']);
    }

    public function test_the_listing_after_the_plan_holds_only_the_files_of_assets_itself(): void {
        $listing = self::listing('icon.svg');
        $plan = build_asset_commit_plan('my-plugin', [ 'blueprint' => [ 'action' => 'put', 'contents' => '{}', 'base' => null ] ], $listing, 60, '/tmp/pending', '/tmp/blueprint-x.json');

        self::assertSame([ 'icon.svg' ], array_column(asset_listing_after_plan($listing['entries'], $plan, 61, []), 'name'), 'blueprints/blueprint.json is no file of assets/');
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

    public function test_the_message_says_what_was_done_in_the_words_of_the_log_reader(): void {
        $base = [ 'filename' => 'x.png', 'revision' => 40 ];
        $put = static fn(string $file, ?array $base = null): array => [ 'action' => 'put', 'file' => $file, 'ext' => pathinfo($file, PATHINFO_EXTENSION), 'base' => $base ];
        $delete = [ 'action' => 'delete', 'base' => $base ];
        $message = static fn(array $pending): string => build_asset_commit_plan('my-plugin', $pending, self::listing(), 60, '/tmp/pending', '/tmp/blueprint-x.json')['message'];

        self::assertSame('Update icon of my-plugin via Peak Publisher', $message([ 'icon_256' => $put('icon-256x256.png'), 'icon_128' => $put('icon-128x128.png', $base) ]), 'the icon is one thing, whatever its slots: a new slot beside a replaced one updates it');
        self::assertSame('Update icon of my-plugin via Peak Publisher', $message([ 'icon_128' => self::copy('icon_256', 'icon-256x256.png', 'png'), 'icon_256' => $delete ]), 'a move within the icon too');
        self::assertSame('Add banner to my-plugin via Peak Publisher', $message([ 'banner_sd' => $put('banner-772x250.png'), 'banner_hd' => $put('banner-1544x500.png') ]));
        self::assertSame('Add screenshot 5 to my-plugin via Peak Publisher', $message([ 'screenshot-5' => $put('screenshot-5.png') ]));
        self::assertSame('Remove screenshot 3 from my-plugin via Peak Publisher', $message([ 'screenshot-3' => $delete ]));
        self::assertSame('Update screenshots 1, 2, 3 and 4 of my-plugin via Peak Publisher', $message([ 'screenshot-4' => $put('screenshot-4.png', $base), 'screenshot-1' => $put('screenshot-1.png', $base), 'screenshot-3' => $put('screenshot-3.png', $base), 'screenshot-2' => $put('screenshot-2.png', $base) ]), 'by number, not by the order of the changes');
        self::assertSame('Update icon and Live Preview blueprint.json of my-plugin via Peak Publisher', $message([ 'blueprint' => [ 'action' => 'put', 'contents' => '{}', 'base' => $base ], 'icon_128' => $put('icon-128x128.png', $base) ]), 'in a fixed order: icon, banner, screenshots, Live Preview');
        self::assertSame('Update icon, banner and screenshots 2 and 3 of my-plugin via Peak Publisher', $message([ 'screenshot-3' => $put('screenshot-3.png', $base), 'banner_sd' => $put('banner-772x250.png', $base), 'icon_svg' => $put('icon.svg', $base), 'screenshot-2' => $put('screenshot-2.png', $base) ]));
        self::assertSame('Update assets of my-plugin (icon, screenshot 5 added, screenshots 2 and 3 removed) via Peak Publisher', $message([ 'screenshot-3' => $delete, 'icon_128' => $put('icon-128x128.png', $base), 'screenshot-5' => $put('screenshot-5.png'), 'screenshot-2' => $delete ]), 'mixed intents: each thing with its own word');
    }
}
