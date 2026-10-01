<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\get_asset_slots;
use function Pblsh\get_geopattern_icon_url;
use function Pblsh\validate_asset_upload;

/**
 * What an uploaded asset must be before any store touches it (includes/assets.php): the
 * slot's file type by content, not empty, within wordpress.org's byte limit, and measured.
 */
final class AssetUploadValidationTest extends TestCase {

    // A 1×1 PNG; GIF headers are synthesized with any size (getimagesize reads the header only).
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function file(string $contents): string {
        $path = FakeWordPress::$upload_dir . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return $path;
    }

    private static function gif(int $width, int $height, int $padding = 0): string {
        return 'GIF89a' . pack('vv', $width, $height) . "\x00\x00\x00" . str_repeat("\x00", $padding);
    }

    public function test_a_matching_raster_image_is_measured_and_warned_about(): void {
        $facts = validate_asset_upload($this->file(self::gif(128, 128)), 'anything.GIF', get_asset_slots()['icon_128']);
        self::assertSame([ 'ext' => 'gif', 'filesize' => 13, 'width' => 128, 'height' => 128, 'warnings' => [] ], $facts);

        $facts = validate_asset_upload($this->file(base64_decode(self::PNG_1X1)), 'photo.jpeg', get_asset_slots()['icon_128']);
        self::assertSame('png', $facts['ext'], 'the content decides the extension, not the name');
        self::assertSame('wrong_dimensions', $facts['warnings'][0]['code']);
    }

    public function test_the_content_must_match_the_slot(): void {
        $error = validate_asset_upload($this->file('not an image'), 'icon.png', get_asset_slots()['icon_128']);
        self::assertSame([ 'asset_invalid_type', 400 ], [ $error->get_error_code(), $error->get_error_data()['status'] ]);

        $error = validate_asset_upload($this->file(self::gif(128, 128)), 'icon.gif', get_asset_slots()['icon_svg']);
        self::assertSame('asset_invalid_type', $error->get_error_code(), 'gif is no SVG');

        $error = validate_asset_upload($this->file('<html></html>'), 'icon.svg', get_asset_slots()['icon_svg']);
        self::assertSame('asset_invalid_type', $error->get_error_code());

        $facts = validate_asset_upload($this->file('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'), 'icon.svg', get_asset_slots()['icon_svg']);
        self::assertSame([ 'svg', null, null ], [ $facts['ext'], $facts['width'], $facts['height'] ]);
    }

    public function test_a_file_over_the_limit_is_refused_before_anything_is_written(): void {
        $error = validate_asset_upload($this->file(self::gif(128, 128, 1048576)), 'icon.gif', get_asset_slots()['icon_128']);
        self::assertSame([ 'asset_too_large', 413 ], [ $error->get_error_code(), $error->get_error_data()['status'] ]);
        self::assertSame('The file is 1.1 MB — icons may be at most 1 MB (the wordpress.org limit).', $error->get_error_message(), 'just over the limit reads as over it');

        $error = validate_asset_upload($this->file(''), 'icon.gif', get_asset_slots()['icon_128']);
        self::assertSame('asset_empty', $error->get_error_code());
    }

    public function test_the_geopattern_url_names_the_channel_and_the_color(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        self::assertSame('https://example.test/wp-json/pblsh/v1/plugins/geopattern-icon/my-plugin_a1b2c3.svg', get_geopattern_icon_url($plugin, 'a1b2c3'));
        self::assertSame('https://s.w.org/plugins/geopattern-icon/my-plugin.svg', get_geopattern_icon_url($marker, ''));
        self::assertSame('https://s.w.org/plugins/geopattern-icon/my-plugin.svg', get_geopattern_icon_url($marker, 'nope'), 'an invalid color is dropped');
    }
}
