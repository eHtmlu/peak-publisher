<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\blueprint_dependency_carry;
use function Pblsh\blueprint_fields_of;
use function Pblsh\blueprint_redirect_skip_step;
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

    public function test_a_plugin_is_added_before_the_plugin_itself_and_taken_out_only_where_the_json_alone_adds_it(): void {
        $own = '{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"my-plugin"},"options":{"activate":true}}';
        $added = decide_blueprint_change('plugin_add', '{"steps":[' . $own . ']}', [ 'value' => 'akismet' ], 'my-plugin', []);
        self::assertSame('put', $added['action']);
        self::assertLessThan(strpos($added['contents'], '"my-plugin"'), strpos($added['contents'], '"akismet"'), 'before the plugin itself');
        self::assertNull(decide_blueprint_change('plugin_add', $added['contents'], [ 'value' => 'akismet' ], 'my-plugin', []), 'there already');
        self::assertNull(decide_blueprint_change('plugin_add', '{"steps":[]}', [ 'value' => 'my-plugin' ], 'my-plugin', []), 'the plugin itself is no addition');

        $removed = decide_blueprint_change('plugin_remove', $added['contents'], [ 'value' => 'akismet' ], 'my-plugin', []);
        self::assertSame('put', $removed['action']);
        self::assertStringNotContainsString('akismet', $removed['contents']);
        self::assertNull(decide_blueprint_change('plugin_remove', $added['contents'], [ 'value' => 'akismet' ], 'my-plugin', [ 'akismet' ]), 'a required plugin is not taken out');
        self::assertNull(decide_blueprint_change('plugin_remove', $added['contents'], [ 'value' => 'my-plugin' ], 'my-plugin', []), 'nor the plugin itself');
    }

    public function test_a_required_plugin_is_moved_before_the_plugin_itself_as_it_stands_where_the_json_installs_it_only_after_or_inactive(): void {
        $own = '{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"my-plugin"},"options":{"activate":true}}';
        $akismet = '{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"akismet"},"options":{"activate":false,"targetFolderName":"akismet-dev"},"progress":{"weight":2}}';
        $steps = static fn(array $result): array => json_decode($result['contents'], true)['steps'];

        $added = decide_blueprint_change('plugin_add', '{"steps":[' . $own . ']}', [ 'value' => 'akismet' ], 'my-plugin', [ 'akismet' ]);
        self::assertSame([ 'akismet', 'my-plugin' ], array_column(array_column($steps($added), 'pluginData'), 'slug'), 'the one the JSON lacks is added before the plugin');
        self::assertNull(decide_blueprint_change('plugin_add', $added['contents'], [ 'value' => 'akismet' ], 'my-plugin', [ 'akismet' ]), 'active before the plugin already');

        $moved = decide_blueprint_change('plugin_add', '{"steps":[' . $own . ',' . $akismet . ']}', [ 'value' => 'akismet' ], 'my-plugin', [ 'akismet' ]);
        self::assertSame([ 'akismet', 'my-plugin' ], array_column(array_column($steps($moved), 'pluginData'), 'slug'), 'moved before the plugin, never installed twice');
        self::assertSame([ 'activate' => true, 'targetFolderName' => 'akismet-dev' ], $steps($moved)[0]['options'], 'activated, the rest of its options kept');
        self::assertSame([ 'weight' => 2 ], $steps($moved)[0]['progress'], 'and whatever else the step carries');

        $woo = '{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"woocommerce"}}';
        $skip = json_encode(blueprint_redirect_skip_step('woocommerce'));
        $setup = '{"step":"runPHP","code":"<?php echo 1;"}';
        $with_skip = decide_blueprint_change('plugin_add', '{"steps":[' . $own . ',' . $setup . ',' . $woo . ',' . $skip . ']}', [ 'value' => 'woocommerce' ], 'my-plugin', [ 'woocommerce' ]);
        self::assertSame([ 'woocommerce', 'runPHP', 'my-plugin', 'runPHP' ], array_map(static fn(array $step): string => $step['pluginData']['slug'] ?? $step['step'], $steps($with_skip)), 'its redirect skip travels along, the other steps stay');
        self::assertStringContainsString('_wc_activation_redirect', $steps($with_skip)[1]['code']);

        self::assertNull(decide_blueprint_change('plugin_add', '{"steps":[' . $own . ',' . $akismet . ']}', [ 'value' => 'akismet' ], 'my-plugin', []), 'not required: there already, wherever it is');
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
