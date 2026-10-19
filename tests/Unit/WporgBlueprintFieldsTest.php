<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\add_blueprint_plugins;
use function Pblsh\add_blueprint_redirect_skip;
use function Pblsh\remove_blueprint_plugin;
use function Pblsh\blueprint_fields;
use function Pblsh\blueprint_missing_dependencies;
use function Pblsh\blueprint_redirect_skip_step;
use function Pblsh\blueprint_warnings;
use function Pblsh\decode_blueprint;
use function Pblsh\encode_blueprint;
use function Pblsh\new_blueprint;
use function Pblsh\set_blueprint_landing_page;
use function Pblsh\set_blueprint_theme;

/**
 * The fields of the Live Preview (includes/wporg_blueprint.php): windows onto the JSON — each
 * reads its part and writes only that —, the rule that the preview installs the current
 * release's Requires Plugins before the plugin, and a new blueprint.
 */
final class WporgBlueprintFieldsTest extends TestCase {

    private static function plugin(string $slug, ?bool $activate = true): array {
        return [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => $slug ], ...($activate === null ? [] : [ 'options' => [ 'activate' => $activate ] ]) ];
    }

    private static function skip(string $slug): array {
        return json_decode((string) json_encode(blueprint_redirect_skip_step($slug)), true);
    }

    private static function theme(string $slug): array {
        return [ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'wordpress.org/themes', 'slug' => $slug ], 'options' => [ 'activate' => true ] ];
    }

    private static function blueprint(array $data): \stdClass {
        return decode_blueprint((string) json_encode($data === [] ? new \stdClass() : $data));
    }

    private static function steps(\stdClass $blueprint): array {
        return json_decode(json_encode($blueprint->steps), true);
    }

    public function test_the_fields_read_landing_page_theme_and_plugins(): void {
        $fields = blueprint_fields(self::blueprint([
            'landingPage' => '/wp-admin/admin.php?page=x',
            'login' => true,
            'siteOptions' => [ 'blogname' => 'Demo' ],
            'plugins' => [ 'query-monitor' ],
            'steps' => [ self::plugin('woocommerce'), self::theme('twentytwentyfive'), [ 'step' => 'runPHP', 'code' => '<?php' ], null, self::plugin('my-plugin'), self::plugin('akismet') ],
        ]), 'my-plugin', [ 'woocommerce' ]);

        self::assertSame([ 'value' => '/wp-admin/admin.php?page=x', 'editable' => true ], $fields['landing_page']);
        self::assertSame([ 'slug' => 'twentytwentyfive', 'editable' => true ], $fields['theme']);
        self::assertSame([ [ 'slug' => 'query-monitor', 'required' => false, 'own' => false, 'redirect' => null ], [ 'slug' => 'woocommerce', 'required' => true, 'own' => false, 'redirect' => 'open' ], [ 'slug' => 'my-plugin', 'required' => false, 'own' => true, 'redirect' => null ], [ 'slug' => 'akismet', 'required' => false, 'own' => false, 'redirect' => null ] ], $fields['plugins'], 'the shorthand list first, then the steps in order, the plugin itself at its place; WooCommerce\'s redirect open: no step drops it');
        self::assertSame([ [ 'name' => 'runPHP', 'count' => 1 ], [ 'name' => 'siteOptions', 'count' => 1 ] ], $fields['more'], 'the runPHP step and siteOptions');
    }

    public function test_what_no_window_shows_is_listed_by_name_and_count(): void {
        $more = blueprint_fields(self::blueprint([
            'preferredVersions' => [ 'php' => '8.3' ],
            'steps' => [ [ 'step' => 'runPHP', 'code' => 'a' ], self::plugin('my-plugin'), [ 'step' => 'setSiteOptions', 'options' => [ 'blogname' => 'x' ] ], [ 'step' => 'runPHP', 'code' => 'b' ], [ 'code' => 'no step name' ] ],
        ]), 'my-plugin', [])['more'];

        self::assertSame([ [ 'name' => 'runPHP', 'count' => 2 ], [ 'name' => 'setSiteOptions', 'count' => 1 ], [ 'name' => 'step', 'count' => 1 ], [ 'name' => 'preferredVersions', 'count' => 1 ] ], $more, 'steps first, in the order of their first appearance, then the top-level keys');
        self::assertSame([], blueprint_fields(self::blueprint([ 'landingPage' => '/', 'steps' => [ self::plugin('my-plugin') ] ]), 'my-plugin', [])['more']);
    }

    public function test_a_missing_landing_page_is_playgrounds_default_and_a_foreign_one_is_read_only(): void {
        self::assertSame([ 'value' => null, 'editable' => true ], blueprint_fields(self::blueprint([]), 'my-plugin', [])['landing_page']);
        self::assertSame([ 'value' => null, 'editable' => false ], blueprint_fields(self::blueprint([ 'landingPage' => 5 ]), 'my-plugin', [])['landing_page']);
    }

    public function test_a_theme_the_field_cannot_hold_is_read_only(): void {
        $none = blueprint_fields(self::blueprint([ 'steps' => [] ]), 'my-plugin', []);
        self::assertSame([ 'slug' => null, 'editable' => true ], $none['theme']);
        self::assertSame([ [ 'slug' => 'my-plugin', 'required' => false, 'own' => true, 'redirect' => null ] ], $none['plugins'], 'no step of its own: wordpress.org appends it, so it stands last');

        $two = blueprint_fields(self::blueprint([ 'steps' => [ self::theme('a'), self::theme('b') ] ]), 'my-plugin', []);
        self::assertSame([ 'slug' => 'a', 'editable' => false ], $two['theme']);

        $url = blueprint_fields(self::blueprint([ 'steps' => [ [ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'url', 'url' => 'https://example.com/t.zip' ] ] ] ]), 'my-plugin', []);
        self::assertSame([ 'slug' => null, 'editable' => false ], $url['theme']);

        $download = blueprint_fields(self::blueprint([ 'steps' => [ [ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'url', 'url' => 'https://downloads.wordpress.org/theme/kadence.1.2.zip' ] ] ] ]), 'my-plugin', []);
        self::assertSame([ 'slug' => 'kadence', 'editable' => true ], $download['theme'], 'read as wordpress.org imports it');
    }

    public function test_a_broken_foreign_blueprint_reads_without_throwing(): void {
        $fields = blueprint_fields(decode_blueprint('{"plugins":"x","steps":["abc",5,{"step":"installPlugin","pluginData":"x","options":"y"},{"step":"installTheme","themeData":7}]}'), 'my-plugin', [ 'woocommerce' ]);
        self::assertSame([ [ 'slug' => 'my-plugin', 'required' => false, 'own' => true, 'redirect' => null ] ], $fields['plugins'], 'nothing readable installs a plugin; the plugin itself, appended');
        self::assertSame([ 'slug' => null, 'editable' => false ], $fields['theme']);
        self::assertSame([ [ 'name' => 'installPlugin', 'count' => 1 ] ], $fields['more'], 'the plugin step without a wordpress.org slug');
        self::assertSame([ 'woocommerce' ], blueprint_missing_dependencies('{"plugins":"x","steps":[{"step":"installPlugin","pluginData":"x"}]}', 'my-plugin', [ 'woocommerce' ]));
    }

    public function test_the_landing_page_keeps_its_place_or_comes_first(): void {
        $set = set_blueprint_landing_page(self::blueprint([ 'login' => true, 'landingPage' => '/', 'steps' => [] ]), '/wp-admin/');
        self::assertSame([ 'login', 'landingPage', 'steps' ], array_keys((array) $set));
        self::assertSame('/wp-admin/', $set->landingPage);

        $new = set_blueprint_landing_page(self::blueprint([ 'login' => true ]), '/wp-admin/plugins.php');
        self::assertSame([ 'landingPage', 'login' ], array_keys((array) $new));
    }

    public function test_the_theme_is_replaced_inserted_before_the_plugin_or_removed(): void {
        $own = self::plugin('my-plugin');

        $replaced = set_blueprint_theme(self::blueprint([ 'steps' => [ [ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'wordpress.org/themes', 'slug' => 'a' ], 'options' => [ 'activate' => true, 'importStarterContent' => true ] ], $own ] ]), 'my-plugin', 'b');
        self::assertSame([ [ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'wordpress.org/themes', 'slug' => 'b' ], 'options' => [ 'activate' => true, 'importStarterContent' => true ] ], $own ], self::steps($replaced), 'its options stay');

        $inserted = set_blueprint_theme(self::blueprint([ 'steps' => [ self::plugin('woocommerce'), $own, [ 'step' => 'login' ] ] ]), 'my-plugin', 'b');
        self::assertSame([ self::plugin('woocommerce'), self::theme('b'), $own, [ 'step' => 'login' ] ], self::steps($inserted));

        $appended = set_blueprint_theme(self::blueprint([ 'steps' => [ [ 'step' => 'login' ] ] ]), 'my-plugin', 'b');
        self::assertSame([ [ 'step' => 'login' ], self::theme('b') ], self::steps($appended));

        $created = set_blueprint_theme(self::blueprint([]), 'my-plugin', 'b');
        self::assertSame([ self::theme('b') ], self::steps($created));

        $removed = set_blueprint_theme(self::blueprint([ 'steps' => [ self::theme('a'), $own ] ]), 'my-plugin', '');
        self::assertSame([ $own ], self::steps($removed), 'reindexed');
    }

    public function test_a_dependency_counts_only_when_it_is_activated_before_the_plugin(): void {
        $own = self::plugin('my-plugin');
        $missing = static fn(array $data): array => blueprint_missing_dependencies((string) json_encode($data), 'my-plugin', [ 'buddypress', 'woocommerce' ]);

        self::assertSame([], $missing([ 'steps' => [ self::plugin('woocommerce'), self::plugin('buddypress', null), $own ] ]), 'activate defaults to true');
        self::assertSame([ 'woocommerce' ], $missing([ 'steps' => [ self::plugin('buddypress'), $own, self::plugin('woocommerce') ] ]), 'after the plugin is too late');
        self::assertSame([ 'woocommerce' ], $missing([ 'steps' => [ self::plugin('buddypress'), self::plugin('woocommerce', false), $own ] ]));
        self::assertSame([ 'buddypress', 'woocommerce' ], $missing([ 'steps' => [ [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'woocommerce' ], 'options' => [ 'activate' => null ] ], $own ] ]));
        self::assertSame([ 'buddypress' ], $missing([ 'steps' => [ [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'url', 'url' => 'https://downloads.wordpress.org/plugin/woocommerce.9.0.zip' ] ], $own ] ]), 'a download URL is a slug to wordpress.org');
        self::assertSame([ 'buddypress' ], $missing([ 'steps' => [ [ 'step' => 'installPlugin', 'pluginZipFile' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'woocommerce' ] ], $own ] ]));
        self::assertSame([ 'woocommerce' ], $missing([ 'plugins' => [ 'buddypress' ], 'steps' => [ $own ] ]), 'the shorthand runs before every step');
        self::assertSame([], $missing([ 'steps' => [ self::plugin('woocommerce'), self::plugin('buddypress') ] ]), 'without its own step wordpress.org appends the plugin: every step comes before');
        self::assertSame([], blueprint_missing_dependencies('{"steps":"x"}', 'my-plugin', [ 'woocommerce' ]), 'a blueprint wordpress.org ignores has no preview to fail');
    }

    public function test_dependencies_are_added_before_the_plugin_and_nothing_goes(): void {
        $own = self::plugin('my-plugin');
        $added = add_blueprint_plugins(self::blueprint([ 'steps' => [ self::plugin('query-monitor'), $own, [ 'step' => 'login' ] ] ]), 'my-plugin', [ 'buddypress', 'woocommerce' ]);
        self::assertSame([ self::plugin('query-monitor'), self::plugin('buddypress'), self::plugin('woocommerce'), self::skip('woocommerce'), $own, [ 'step' => 'login' ] ], self::steps($added), 'a known activation redirect dropped right after its install');

        $appended = add_blueprint_plugins(self::blueprint([ 'steps' => [ [ 'step' => 'login' ] ] ]), 'my-plugin', [ 'woocommerce' ]);
        self::assertSame([ [ 'step' => 'login' ], self::plugin('woocommerce'), self::skip('woocommerce') ], self::steps($appended));
    }

    public function test_a_new_blueprint_lands_on_the_plugins_screen_with_the_dependencies_before_the_plugin(): void {
        self::assertSame(
            "{\n    \"landingPage\": \"/wp-admin/plugins.php\",\n    \"login\": true,\n    \"steps\": [\n"
            . "        {\n            \"step\": \"installPlugin\",\n            \"pluginData\": {\n                \"resource\": \"wordpress.org/plugins\",\n                \"slug\": \"woocommerce\"\n            },\n            \"options\": {\n                \"activate\": true\n            }\n        },\n"
            . "        {\n            \"step\": \"runPHP\",\n            \"code\": \"<?php require_once '/wordpress/wp-load.php'; delete_transient('_wc_activation_redirect');\"\n        },\n"
            . "        {\n            \"step\": \"installPlugin\",\n            \"pluginData\": {\n                \"resource\": \"wordpress.org/plugins\",\n                \"slug\": \"my-plugin\"\n            },\n            \"options\": {\n                \"activate\": true\n            }\n        }\n"
            . "    ]\n}\n",
            encode_blueprint(new_blueprint('my-plugin', [ 'woocommerce' ])),
            'WooCommerce\'s activation redirect dropped right after its install, as wordpress.org\'s own blueprints do'
        );
    }

    public function test_a_known_activation_redirect_reads_as_skipped_only_when_a_later_step_drops_it(): void {
        $woo = self::plugin('woocommerce');
        $redirect = static fn(array $data): ?string => blueprint_fields(self::blueprint($data), 'my-plugin', [ 'woocommerce' ])['plugins'][0]['redirect'];
        $official = [ 'step' => 'runPHP', 'code' => "<?php include '/wordpress/wp-load.php'; delete_transient( '_wc_activation_redirect' ); wp_insert_post([ 'post_title' => 'Sample' ]);" ];

        self::assertSame('open', $redirect([ 'steps' => [ $woo, self::plugin('my-plugin') ] ]));
        self::assertSame('skipped', $redirect([ 'steps' => [ $woo, self::skip('woocommerce'), self::plugin('my-plugin') ] ]));
        self::assertSame('skipped', $redirect([ 'steps' => [ $woo, $official ] ]), 'however the step is written');
        self::assertSame('open', $redirect([ 'steps' => [ self::skip('woocommerce'), $woo ] ]), 'dropped before the install, the redirect is set after it');
        self::assertSame('skipped', $redirect([ 'plugins' => [ 'woocommerce' ], 'steps' => [ self::skip('woocommerce') ] ]), 'the shorthand list runs before every step');

        $fields = blueprint_fields(self::blueprint([ 'steps' => [ $woo, self::skip('woocommerce'), $official, self::plugin('my-plugin') ] ]), 'my-plugin', [ 'woocommerce' ]);
        self::assertSame([ [ 'name' => 'runPHP', 'count' => 1 ] ], $fields['more'], 'the step as Peak Publisher writes it is known; a step doing more is listed');
        self::assertNull(blueprint_fields(self::blueprint([ 'steps' => [ self::plugin('akismet') ] ]), 'my-plugin', [])['plugins'][0]['redirect'], 'no known redirect: nothing to say');
    }

    public function test_a_plugin_is_taken_out_with_its_steps_and_its_shorthand_entry(): void {
        $own = self::plugin('my-plugin');
        $official = [ 'step' => 'runPHP', 'code' => "<?php include '/wordpress/wp-load.php'; delete_transient( '_wc_activation_redirect' ); wp_insert_post([]);" ];
        $blueprint = self::blueprint([ 'plugins' => [ 'woocommerce', 'akismet' ], 'steps' => [ self::plugin('woocommerce'), self::skip('woocommerce'), $official, self::plugin('woocommerce', false), $own ] ]);

        $removed = remove_blueprint_plugin($blueprint, 'woocommerce');
        self::assertSame([ $official, $own ], self::steps($removed), 'every install of it and the skip step as Peak Publisher wrote it; a foreign step doing more stays');
        self::assertSame([ 'akismet' ], $removed->plugins);

        $last = remove_blueprint_plugin(self::blueprint([ 'plugins' => [ 'akismet' ], 'steps' => [ $own ] ]), 'akismet');
        self::assertFalse(property_exists($last, 'plugins'), 'an emptied shorthand list goes');
        self::assertSame([ $own ], self::steps($last));
    }

    public function test_the_redirect_skip_is_inserted_right_after_the_install(): void {
        $woo = self::plugin('woocommerce');
        $own = self::plugin('my-plugin');
        self::assertSame([ $woo, self::skip('woocommerce'), $own ], self::steps(add_blueprint_redirect_skip(self::blueprint([ 'steps' => [ $woo, $own ] ]), 'woocommerce')));
        self::assertSame([ self::skip('woocommerce'), $own ], self::steps(add_blueprint_redirect_skip(self::blueprint([ 'plugins' => [ 'woocommerce' ], 'steps' => [ $own ] ]), 'woocommerce')), 'installed by the shorthand list: first of the steps');
    }

    public function test_the_warnings_name_what_wordpress_org_would_break(): void {
        self::assertSame([ 'empty_object' ], array_column(blueprint_warnings('{"steps":[{"step":"setSiteOptions","options":{}}]}', 1), 'code'));
        self::assertSame([], blueprint_warnings('{"a":[]}', 10));
        self::assertSame([ 'too_many_files' ], array_column(blueprint_warnings('{}', 11), 'code'));
        self::assertSame([ 'too_many_files' ], array_column(blueprint_warnings(null, 11), 'code'));
    }
}
