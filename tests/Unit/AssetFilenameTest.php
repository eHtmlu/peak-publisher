<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\asset_canonical_filename;
use function Pblsh\asset_slot_definition;
use function Pblsh\asset_slot_id;
use function Pblsh\classify_asset_filename;
use function Pblsh\get_asset_slots;
use function Pblsh\select_shown_asset;

/**
 * wordpress.org's one filename rule (includes/assets.php): which file is which asset, which
 * slot it belongs to, and which of several candidates the plugin page shows.
 */
final class AssetFilenameTest extends TestCase {

    #[DataProvider('filenames')]
    public function test_classification_follows_the_directory_import(string $filename, ?array $expected): void {
        self::assertSame($expected, classify_asset_filename($filename));
    }

    public static function filenames(): array {
        return [
            'icon.svg' => [ 'icon.svg', [ 'type' => 'icon', 'resolution' => false, 'rtl' => false, 'locale' => '', 'ext' => 'svg' ] ],
            'uppercase counts (flag i)' => [ 'Icon-128x128.PNG', [ 'type' => 'icon', 'resolution' => '128x128', 'rtl' => false, 'locale' => '', 'ext' => 'png' ] ],
            'jpeg is an extension of its own' => [ 'screenshot-01.jpeg', [ 'type' => 'screenshot', 'resolution' => '1', 'rtl' => false, 'locale' => '', 'ext' => 'jpeg' ] ],
            'screenshot number is normalized, 0 stays 0' => [ 'screenshot-0.png', [ 'type' => 'screenshot', 'resolution' => '0', 'rtl' => false, 'locale' => '', 'ext' => 'png' ] ],
            'resolution separator becomes x' => [ 'banner-1544X500.jpg', [ 'type' => 'banner', 'resolution' => '1544x500', 'rtl' => false, 'locale' => '', 'ext' => 'jpg' ] ],
            'rtl then locale' => [ 'banner-772x250-rtl-de_DE.png', [ 'type' => 'banner', 'resolution' => '772x250', 'rtl' => true, 'locale' => 'de_DE', 'ext' => 'png' ] ],
            'short locale' => [ 'icon-128x128-de.gif', [ 'type' => 'icon', 'resolution' => '128x128', 'rtl' => false, 'locale' => 'de', 'ext' => 'gif' ] ],
            'variant locale' => [ 'screenshot-3-de_DE_formal.png', [ 'type' => 'screenshot', 'resolution' => '3', 'rtl' => false, 'locale' => 'de_DE_formal', 'ext' => 'png' ] ],
            'svg never carries suffixes' => [ 'icon-128x128.svg', null ],
            'foreign' => [ 'editor-screen-1.png', null ],
            'blueprint' => [ 'blueprint.json', null ],
        ];
    }

    #[DataProvider('slot_ids')]
    public function test_a_file_belongs_to_the_slot_of_its_type_and_resolution(string $filename, ?string $slot_id): void {
        self::assertSame($slot_id, asset_slot_id(classify_asset_filename($filename)));
    }

    public static function slot_ids(): array {
        return [
            [ 'icon-128x128.png', 'icon_128' ],
            [ 'icon-256x256-de_DE.jpg', 'icon_256' ],
            [ 'icon.svg', 'icon_svg' ],
            [ 'banner-772x250.png', 'banner_sd' ],
            [ 'banner-1544x500-rtl.png', 'banner_hd' ],
            [ 'banner.svg', null ],           // the filter pblsh_enable_banner_svg is off
            [ 'icon-128.png', null ],         // a resolution wordpress.org never consumes
            [ 'screenshot-07.png', 'screenshot-7' ],
            [ 'screenshot-0.png', null ],     // listed by wordpress.org, never a caption
            [ 'screenshot.svg', null ],
        ];
    }

    public function test_slot_definitions_carry_the_limits_and_numbered_screenshots(): void {
        $slots = get_asset_slots();
        self::assertSame([ 'icon_svg', 'icon_256', 'icon_128', 'banner_hd', 'banner_sd', 'screenshot' ], array_keys($slots));
        self::assertSame(1048576, $slots['icon_128']['maxBytes']);
        self::assertSame(4194304, $slots['banner_hd']['maxBytes']);
        self::assertSame(10485760, $slots['screenshot']['maxBytes']);
        self::assertSame(3, asset_slot_definition('screenshot-3')['number']);
        self::assertNull(asset_slot_definition('screenshot'), 'a screenshot slot always has a number');
        self::assertNull(asset_slot_definition('screenshot-0'));
        self::assertNull(asset_slot_definition('banner_svg'));
    }

    public function test_canonical_filenames_are_lowercase_with_jpg(): void {
        self::assertSame('icon-128x128.png', asset_canonical_filename('icon_128', 'PNG'));
        self::assertSame('screenshot-3.jpg', asset_canonical_filename('screenshot-3', 'jpeg'));
        self::assertSame('icon.svg', asset_canonical_filename('icon_svg', 'svg'));
    }

    #[DataProvider('candidate_sets')]
    public function test_the_shown_file_is_chosen_like_find_best_asset_for_en_US(array $filenames, string $winner): void {
        $candidates = array_map(static function(string $filename): array {
            $class = classify_asset_filename($filename);
            return [ 'filename' => $filename, 'locale' => $class['locale'], 'rtl' => $class['rtl'] ];
        }, $filenames);
        self::assertSame($winner, select_shown_asset($candidates)['filename']);
    }

    public static function candidate_sets(): array {
        return [
            'extension descending' => [ [ 'banner-772x250.jpg', 'banner-772x250.png' ], 'banner-772x250.png' ],
            'ties keep the listing order' => [ [ 'screenshot-01.png', 'screenshot-1.png' ], 'screenshot-01.png' ],
            'default beats localized' => [ [ 'banner-772x250-de_DE.png', 'banner-772x250.png' ], 'banner-772x250.png' ],
            'en_US beats default' => [ [ 'banner-772x250.png', 'banner-772x250-en_US.png' ], 'banner-772x250-en_US.png' ],
            'a lone localized file is shown to everyone' => [ [ 'banner-772x250-de_DE.png' ], 'banner-772x250-de_DE.png' ],
            'ltr beats rtl' => [ [ 'banner-772x250-rtl.png', 'banner-772x250.png' ], 'banner-772x250.png' ],
            'only localized: extension, then order' => [ [ 'banner-772x250-de_DE.jpg', 'banner-772x250-fr_FR.png' ], 'banner-772x250-fr_FR.png' ],
        ];
    }
}
