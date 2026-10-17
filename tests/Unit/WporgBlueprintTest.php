<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\classify_blueprint_listing;
use function Pblsh\decode_blueprint;
use function Pblsh\encode_blueprint;
use function Pblsh\is_blueprint_filename;
use function Pblsh\validate_blueprint_contents;

use const Pblsh\PBLSH_BLUEPRINT_MAX_BYTES;

/**
 * The blueprint rules of wordpress.org (includes/wporg_blueprint.php): which files of
 * assets/blueprints/ its import reads, what Peak Publisher accepts before it stores a
 * blueprint, and the encoding that keeps a blueprint as it was meant.
 */
final class WporgBlueprintTest extends TestCase {

    #[DataProvider('filenames')]
    public function test_the_filename_rule_is_wordpress_orgs(string $name, bool $expected): void {
        self::assertSame($expected, is_blueprint_filename($name));
    }

    public static function filenames(): array {
        return [
            'the preview' => [ 'blueprint.json', true ],
            'hyphen' => [ 'blueprint-x.json', true ],
            'underscore' => [ 'blueprint_x.json', true ],
            'glued' => [ 'blueprintx.json', true ],
            'capital' => [ 'Blueprint.json', false ],
            'capital extension' => [ 'blueprint.JSON', false ],
            'a dot inside' => [ 'blueprint.dev.json', false ],
            'not ASCII' => [ 'blueprint-ä.json', false ],
            'other file' => [ 'readme.txt', false ],
        ];
    }

    public function test_the_listing_names_the_preview_and_counts_the_rest(): void {
        $listing = classify_blueprint_listing([
            [ 'name' => 'blueprint-alt.json', 'type' => 'file', 'size' => 20, 'revision' => 7 ],
            [ 'name' => 'blueprint.json', 'type' => 'file', 'size' => 300, 'revision' => 9 ],
            [ 'name' => 'old', 'type' => 'dir', 'size' => null, 'revision' => 3 ],
            [ 'name' => 'readme.txt', 'type' => 'file', 'size' => 10, 'revision' => 3 ],
        ]);

        self::assertSame([ 'name' => 'blueprint.json', 'size' => 300, 'revision' => 9 ], $listing['blueprint']);
        self::assertSame(2, $listing['blueprint_files'], 'blueprint.json counts among the files wordpress.org imports');
        self::assertSame(2, $listing['other_files']);
        self::assertSame([ 'blueprint' => null, 'blueprint_files' => 0, 'other_files' => 0 ], classify_blueprint_listing([]));
    }

    public function test_a_blueprint_up_to_the_limit_is_accepted(): void {
        $padded = static fn(int $bytes): string => '{"x":"' . str_repeat('a', $bytes - 8) . '"}';
        self::assertSame(PBLSH_BLUEPRINT_MAX_BYTES, strlen($padded(PBLSH_BLUEPRINT_MAX_BYTES)));
        self::assertTrue(validate_blueprint_contents($padded(PBLSH_BLUEPRINT_MAX_BYTES)));

        $over = $padded(PBLSH_BLUEPRINT_MAX_BYTES + 1);
        $error = validate_blueprint_contents($over);
        self::assertSame('blueprint_too_large', $error->get_error_code());
        self::assertSame(413, $error->get_error_data()['status']);
        self::assertStringContainsString('100 KB', $error->get_error_message());
    }

    #[DataProvider('refused')]
    public function test_what_wordpress_org_ignores_or_chokes_on_is_refused(string $contents, string $code): void {
        $error = validate_blueprint_contents($contents);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame($code, $error->get_error_code());
        self::assertSame(400, $error->get_error_data()['status']);
    }

    public static function refused(): array {
        return [
            'not JSON' => [ '{"a":', 'blueprint_invalid_json' ],
            'empty' => [ '', 'blueprint_invalid_json' ],
            'a list' => [ '[1]', 'blueprint_not_object' ],
            'a string' => [ '"x"', 'blueprint_not_object' ],
            'a number' => [ '1', 'blueprint_not_object' ],
            'steps a string' => [ '{"steps":"abc"}', 'blueprint_invalid_steps' ],
            'a step a string' => [ '{"steps":["abc"]}', 'blueprint_invalid_steps' ],
            'a step a number' => [ '{"steps":[1]}', 'blueprint_invalid_steps' ],
            'steps an object' => [ '{"steps":{"a":{}}}', 'blueprint_invalid_steps' ],
            'steps null' => [ '{"steps":null}', 'blueprint_invalid_steps' ],
            'options a string' => [ '{"steps":[{"step":"installPlugin","options":"x"}]}', 'blueprint_invalid_steps' ],
        ];
    }

    public function test_falsy_steps_and_empty_objects_pass(): void {
        self::assertTrue(validate_blueprint_contents('{"steps":[null,false,{}]}'));
        self::assertTrue(validate_blueprint_contents('{}'));
        self::assertTrue(validate_blueprint_contents("{\n  \"landingPage\": \"/\"\n}\n"));
    }

    public function test_encoding_keeps_objects_numbers_slashes_and_unicode(): void {
        $encoded = encode_blueprint(decode_blueprint('{"a":{},"b":[],"c":1.0,"d":"/x/ü","e":{"f":[{"g":{}}]}}'));

        self::assertStringContainsString('"a": {}', $encoded);
        self::assertStringContainsString('"b": []', $encoded);
        self::assertStringContainsString('"c": 1.0', $encoded);
        self::assertStringContainsString('"d": "/x/ü"', $encoded);
        self::assertStringContainsString('"g": {}', $encoded);
        self::assertStringStartsWith("{\n    \"a\"", $encoded, 'pretty printed, four spaces');
        self::assertStringEndsWith("}\n", $encoded);
    }

    public function test_only_a_json_object_decodes(): void {
        self::assertInstanceOf(\stdClass::class, decode_blueprint('{}'));
        self::assertNull(decode_blueprint('[]'));
        self::assertNull(decode_blueprint('nope'));
    }
}
