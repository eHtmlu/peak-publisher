<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\check_svg_file;
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

    /**
     * The one SVG rule of both channels (check_svg_file()): the files are served from this
     * site's origin, so active content is refused — the upload says so, the mirror keeps the
     * file out.
     */
    public function test_an_svg_with_active_content_is_refused(): void {
        $svg = static fn(string $body, string $root_attributes = ''): string => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1 1"' . $root_attributes . '>' . $body . '</svg>';

        $active = [
            'script element' => $svg('<script>alert(1)</script>'),
            'script element in another namespace' => $svg('<h:script xmlns:h="http://www.w3.org/1999/xhtml">alert(1)</h:script>'),
            'foreignObject' => $svg('<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'),
            'event handler' => $svg('<rect/>', ' onload="alert(1)"'),
            'event handler on a child' => $svg('<rect onclick="alert(1)"/>'),
            'javascript href' => $svg('<a href="javascript:alert(1)"><text>x</text></a>'),
            'javascript xlink:href' => $svg('<a xlink:href=" JavaScript:alert(1)"><text>x</text></a>'),
            'data href that is no raster image' => $svg('<use href="data:image/svg+xml,%3Csvg%3E"/>'),
            'relative href' => $svg('<use href="other.svg#id"/>'),
            'href animated to javascript' => $svg('<a><animate attributeName="href" values="#a;javascript:alert(1)"/><text>x</text></a>'),
            'href set to javascript' => $svg('<a><set attributeName="xlink:href" to="javascript:alert(1)"/><text>x</text></a>'),
            'xslt stylesheet' => '<?xml-stylesheet type="text/xsl" href="https://example.test/x.xsl"?>' . $svg('<rect/>'),
            'entity in the internal subset' => '<!DOCTYPE svg [<!ENTITY x "&#60;script&#62;alert(1)&#60;/script&#62;">]>' . $svg('&x;'),
        ];
        foreach ($active as $case => $content) {
            $error = check_svg_file($this->file($content));
            self::assertSame('asset_svg_active', $error === null ? null : $error->get_error_code(), $case);
            self::assertSame(400, $error->get_error_data()['status'], $case);
        }

        $passive = [
            'plain' => $svg('<rect width="1" height="1"/>'),
            'fragment references' => $svg('<defs><rect id="a"/></defs><use href="#a"/><use xlink:href="#a"/>'),
            'http link and image' => $svg('<a href="https://example.test/"><image href="HTTPS://example.test/i.png" width="1" height="1"/></a>'),
            'embedded raster image' => $svg('<image xlink:href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>'),
            'style with external css' => $svg('<style>@import url(https://example.test/f.css);</style><rect/>'),
            'public doctype without entities' => '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $svg('<rect/>'),
            'href animated between fragments' => $svg('<use><animate attributeName="href" from="#a" to="#b"/></use>'),
        ];
        foreach ($passive as $case => $content) {
            self::assertNull(check_svg_file($this->file($content)), $case);
        }

        self::assertSame('asset_invalid_type', check_svg_file($this->file('<html></html>'))->get_error_code(), 'well-formed, but no SVG document');
        self::assertSame('asset_invalid_type', check_svg_file($this->file('<svg'))->get_error_code(), 'not well-formed');
        self::assertSame('asset_invalid_type', check_svg_file($this->file(''))->get_error_code(), 'empty');

        $error = validate_asset_upload($this->file($active['script element']), 'icon.svg', get_asset_slots()['icon_svg']);
        self::assertSame([ 'asset_svg_active', 400 ], [ $error->get_error_code(), $error->get_error_data()['status'] ], 'the upload passes the rule\'s error through');
        self::assertSame('The SVG contains active content (script): a browser that opens the file on this site would run it.', $error->get_error_message());
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
