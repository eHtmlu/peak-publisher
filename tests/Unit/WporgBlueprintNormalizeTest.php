<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\encode_blueprint_like_wporg;
use function Pblsh\normalize_blueprint_like_wporg;

/**
 * The blueprint as the plugin page serves it (includes/wporg_blueprint.php): a faithful port of
 * the plugin directory's normalize_blueprint_json() — quirks included, so a test in Playground
 * shows what the plugin page will show.
 */
final class WporgBlueprintNormalizeTest extends TestCase {

    private const OWN_STEP = [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'my-plugin' ], 'options' => [ 'activate' => true ] ];

    public function test_a_missing_self_install_step_is_appended_at_the_end(): void {
        $normalized = normalize_blueprint_like_wporg('{"landingPage":"/","steps":[{"step":"login"}]}', 'my-plugin');
        self::assertSame([ 'landingPage' => '/', 'steps' => [ [ 'step' => 'login' ], self::OWN_STEP ] ], $normalized);

        self::assertSame([ 'steps' => [ self::OWN_STEP ] ], normalize_blueprint_like_wporg('{}', 'my-plugin'), 'steps are created');
        self::assertSame([], normalize_blueprint_like_wporg('{}', 'akismet'), 'akismet is bundled with WordPress');
    }

    public function test_an_existing_self_install_step_is_forced_to_activate(): void {
        $normalized = normalize_blueprint_like_wporg('{"steps":[{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"my-plugin"},"options":{"activate":false}},{"step":"login"}]}', 'my-plugin');
        self::assertSame([ 'steps' => [ self::OWN_STEP, [ 'step' => 'login' ] ] ], $normalized, 'nothing appended');
    }

    public function test_download_urls_and_zip_slugs_become_slug_resources(): void {
        $normalized = normalize_blueprint_like_wporg((string) json_encode([ 'steps' => [
            [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'url', 'url' => 'https://downloads.wordpress.org/plugin/foo.1.2.3.zip' ], 'options' => [ 'activate' => true ] ],
            [ 'step' => 'installTheme', 'themeZipFile' => [ 'resource' => 'url', 'url' => 'https://downloads.wordpress.org/theme/bar.zip?x=1' ] ],
            [ 'step' => 'installPlugin', 'pluginZipFile' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'baz' ] ],
            [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'url', 'url' => 'https://example.com/my-plugin.zip' ] ],
        ] ]), 'my-plugin');

        self::assertSame([ 'step' => 'installPlugin', 'options' => [ 'activate' => true ], 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'foo' ] ], $normalized['steps'][0], 'the version is lost, the key moves to the end — like there');
        self::assertSame([ 'step' => 'installTheme', 'themeData' => [ 'resource' => 'wordpress.org/themes', 'slug' => 'bar' ] ], $normalized['steps'][1]);
        self::assertSame([ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => 'baz' ] ], $normalized['steps'][2]);
        self::assertSame('https://example.com/my-plugin.zip', $normalized['steps'][3]['pluginData']['url'], 'a foreign URL is no self-install step');
        self::assertSame(self::OWN_STEP, $normalized['steps'][4]);
    }

    public function test_falsy_steps_go_and_empty_objects_become_arrays_in_the_encoding(): void {
        $normalized = normalize_blueprint_like_wporg('{"siteOptions":{},"steps":[null,false,{},{"step":"login"}]}', 'my-plugin');
        self::assertSame([ [ 'step' => 'login' ], self::OWN_STEP ], $normalized['steps']);
        self::assertSame(
            '{"siteOptions":[],"steps":[{"step":"login"},{"step":"installPlugin","pluginData":{"resource":"wordpress.org\/plugins","slug":"my-plugin"},"options":{"activate":true}}]}',
            encode_blueprint_like_wporg($normalized),
            'json_encode() without flags: {} turns into [], slashes escaped'
        );
    }

    public function test_what_wordpress_org_ignores_or_chokes_on_has_no_normalized_form(): void {
        self::assertNull(normalize_blueprint_like_wporg('{"steps":"abc"}', 'my-plugin'));
        self::assertNull(normalize_blueprint_like_wporg('{"steps":["abc"]}', 'my-plugin'));
        self::assertNull(normalize_blueprint_like_wporg('not json', 'my-plugin'));
        self::assertNull(normalize_blueprint_like_wporg('{"x":"' . str_repeat('a', 102400) . '"}', 'my-plugin'));
    }
}
