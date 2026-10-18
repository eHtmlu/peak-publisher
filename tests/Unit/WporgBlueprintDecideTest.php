<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\blueprint_dependency_carry;
use function Pblsh\blueprint_fields_of;
use function Pblsh\decide_blueprint_change;
use function Pblsh\encode_blueprint;
use function Pblsh\new_blueprint;

/**
 * Every change of the Live Preview decided in one place (includes/wporg_blueprint.php) — and
 * none stores a blueprint wordpress.org would ignore or choke on, whichever way it was made:
 * typed, through a field, created, or carried by a deploy.
 */
final class WporgBlueprintDecideTest extends TestCase {

    private const BROKEN = '{"landingPage":"/","steps":["abc",{"step":"login"}]}';

    /** A valid compact blueprint that pretty printing pushes over wordpress.org's 100 KB. */
    private static function compact_but_long(): string {
        return '{"landingPage":"/","steps":[' . implode(',', array_fill(0, 3000, '{"step":"login"}')) . ']}';
    }

    public function test_a_broken_foreign_file_has_no_fields_and_a_field_change_names_why(): void {
        self::assertNull(blueprint_fields_of(self::BROKEN, 'my-plugin', []), 'the fields would write a file the import throws on');
        self::assertNull(blueprint_fields_of(null, 'my-plugin', []));
        self::assertIsArray(blueprint_fields_of('{"landingPage":"/"}', 'my-plugin', []));

        $error = decide_blueprint_change('landing_page', self::BROKEN, [ 'value' => '/wp-admin/' ], 'my-plugin', []);
        self::assertSame('blueprint_invalid_steps', $error->get_error_code());
    }

    public function test_a_field_change_that_grows_the_file_over_the_limit_is_refused(): void {
        self::assertLessThan(102400, strlen(self::compact_but_long()));
        $error = decide_blueprint_change('landing_page', self::compact_but_long(), [ 'value' => '/wp-admin/' ], 'my-plugin', []);
        self::assertSame('blueprint_too_large', $error->get_error_code());
    }

    public function test_typed_json_builds_on_what_the_editor_showed(): void {
        $effective = '{"landingPage":"/"}';
        self::assertSame([ 'action' => 'put', 'contents' => '{"a":1}' ], decide_blueprint_change('contents', $effective, [ 'contents' => '{"a":1}', 'expected' => md5($effective) ], 'my-plugin', []));
        self::assertSame('blueprint_changed', decide_blueprint_change('contents', $effective, [ 'contents' => '{"a":1}', 'expected' => md5('other') ], 'my-plugin', [])->get_error_code());
        self::assertSame('blueprint_invalid_steps', decide_blueprint_change('contents', $effective, [ 'contents' => '{"steps":"x"}', 'expected' => md5($effective) ], 'my-plugin', [])->get_error_code());
        self::assertSame([ 'action' => 'put', 'contents' => '{}' ], decide_blueprint_change('contents', null, [ 'contents' => '{}', 'expected' => md5('') ], 'my-plugin', []), 'a pasted blueprint where there was none');
    }

    public function test_create_only_where_there_is_none_and_delete_always(): void {
        self::assertSame([ 'action' => 'put', 'contents' => encode_blueprint(new_blueprint('my-plugin', [ 'woocommerce' ])) ], decide_blueprint_change('create', null, [], 'my-plugin', [ 'woocommerce' ]));
        self::assertSame('blueprint_changed', decide_blueprint_change('create', '{}', [], 'my-plugin', [])->get_error_code());
        self::assertSame([ 'action' => 'delete' ], decide_blueprint_change('delete', '{}', [], 'my-plugin', []));
    }

    public function test_dependencies_are_added_only_when_missing(): void {
        self::assertNull(decide_blueprint_change('dependencies', '{"steps":[]}', [], 'my-plugin', []));
        $added = decide_blueprint_change('dependencies', '{"steps":[]}', [], 'my-plugin', [ 'woocommerce' ]);
        self::assertSame('put', $added['action']);
        self::assertStringContainsString('"woocommerce"', $added['contents']);
    }

    public function test_the_redirect_skip_is_added_only_where_the_fields_read_it_as_open(): void {
        $woo = '{"steps":[{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"woocommerce"},"options":{"activate":true}}]}';
        $added = decide_blueprint_change('redirect_skip', $woo, [ 'value' => 'woocommerce' ], 'my-plugin', [ 'woocommerce' ]);
        self::assertSame('put', $added['action']);
        self::assertStringContainsString("delete_transient('_wc_activation_redirect')", $added['contents']);
        self::assertNull(decide_blueprint_change('redirect_skip', $added['contents'], [ 'value' => 'woocommerce' ], 'my-plugin', [ 'woocommerce' ]), 'skipped already');
        self::assertNull(decide_blueprint_change('redirect_skip', $woo, [ 'value' => 'akismet' ], 'my-plugin', []), 'no such plugin in the blueprint, no known redirect');
    }

    public function test_the_carry_leaves_a_file_alone_that_it_would_push_over_the_limit(): void {
        self::assertNull(blueprint_dependency_carry(self::compact_but_long(), 'my-plugin', [ 'woocommerce' ]), 'detection reports the gap instead');
    }
}
