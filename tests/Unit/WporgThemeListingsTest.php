<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use Pblsh\WporgSvnException;

use function Pblsh\wp_default_theme;
use function Pblsh\wporg_theme_information;
use function Pblsh\wporg_theme_listings;

use const Pblsh\PBLSH_WP_DEFAULT_THEME_OPTION;
use const Pblsh\PBLSH_WPORG_THEME_LISTINGS_OPTION;

/**
 * What the Live Preview's theme row rests on: the themes API's listing of a theme
 * (includes/wporg_api.php), the site-wide cache of those listings (includes/wporg_directory.php,
 * the mechanics the plugin listings share), and the default theme of the WordPress Playground
 * boots, read from core's own constants once per branch (includes/wporg_blueprint.php).
 */
final class WporgThemeListingsTest extends TestCase {

    private function answer(int $code, string $body): void {
        FakeWordPress::$http_responses[] = [ 'code' => $code, 'body' => $body ];
    }

    public function test_the_themes_api_lists_name_and_screenshot_or_says_not_found(): void {
        $this->answer(200, '{"name":"Twenty Twenty-Five","slug":"twentytwentyfive","screenshot_url":"//ts.w.org/wp-content/themes/twentytwentyfive/screenshot.png?ver=1.5"}');
        self::assertSame([ 'state' => 'ok', 'name' => 'Twenty Twenty-Five', 'screenshot' => 'https://ts.w.org/wp-content/themes/twentytwentyfive/screenshot.png?ver=1.5' ], wporg_theme_information('twentytwentyfive'), 'the screenshot URL made absolute');
        self::assertStringContainsString('request%5Bfields%5D%5Bscreenshot_url%5D=1', FakeWordPress::$http_requests[0]['url'], 'the screenshot asked for');

        $this->answer(404, '{"error":"Theme not found"}');
        self::assertSame([ 'state' => 'not_found', 'name' => null, 'screenshot' => null ], wporg_theme_information('no-such-theme'));

        $this->answer(500, '');
        $this->expectException(WporgSvnException::class);
        wporg_theme_information('twentytwentyfive');
    }

    public function test_theme_listings_are_asked_one_by_one_and_then_served_from_the_cache(): void {
        $this->answer(200, '{"name":"Twenty Twenty-Five","slug":"twentytwentyfive","screenshot_url":"https://ts.w.org/t.png"}');
        $this->answer(404, '{"error":"Theme not found"}');

        $listings = wporg_theme_listings([ 'twentytwentyfive', 'no-such-theme', 'Not A Slug' ]);

        self::assertSame([
            'twentytwentyfive' => [ 'name' => 'Twenty Twenty-Five', 'screenshot' => 'https://ts.w.org/t.png', 'state' => 'ok' ],
            'no-such-theme' => [ 'name' => null, 'screenshot' => null, 'state' => 'not_found' ],
        ], $listings, 'one request per theme; what is no slug is neither asked nor listed');
        self::assertCount(2, FakeWordPress::$http_requests);
        self::assertSame([ 'twentytwentyfive', 'no-such-theme' ], array_keys(get_option(PBLSH_WPORG_THEME_LISTINGS_OPTION)));

        self::assertSame($listings, wporg_theme_listings([ 'twentytwentyfive', 'no-such-theme' ]));
        self::assertCount(2, FakeWordPress::$http_requests, 'served from the cache');
    }

    public function test_the_default_theme_is_read_from_cores_constants_once_per_branch(): void {
        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'latest', 'current' => '7.1.3' ] ] ]);
        $constants = "<?php\n/** The default theme. */\ndefine( 'WP_DEFAULT_THEME', 'twentytwentyfive' );\n";

        $this->answer(200, $constants);
        self::assertSame('twentytwentyfive', wp_default_theme());
        self::assertSame('https://core.svn.wordpress.org/branches/7.1/wp-includes/default-constants.php', FakeWordPress::$http_requests[0]['url'], 'the stable branch\'s own file');
        self::assertSame([ 'branch' => '7.1', 'slug' => 'twentytwentyfive' ], array_intersect_key(get_option(PBLSH_WP_DEFAULT_THEME_OPTION), [ 'branch' => 1, 'slug' => 1 ]));

        self::assertSame('twentytwentyfive', wp_default_theme());
        self::assertCount(1, FakeWordPress::$http_requests, 'kept until the branch moves');

        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'latest', 'current' => '7.2.0' ] ] ]);
        $this->answer(500, '');
        self::assertNull(wp_default_theme(), 'a new branch asks again; without an answer the row names no theme');
        self::assertCount(2, FakeWordPress::$http_requests);
        self::assertSame('7.1', get_option(PBLSH_WP_DEFAULT_THEME_OPTION)['branch'], 'nothing written without an answer');

        $this->answer(200, str_replace('twentytwentyfive', 'twentytwentysix', $constants));
        self::assertSame('twentytwentysix', wp_default_theme());
        self::assertSame('7.2', get_option(PBLSH_WP_DEFAULT_THEME_OPTION)['branch']);

        $this->answer(200, '<?php // nothing defined');
        update_option(PBLSH_WP_DEFAULT_THEME_OPTION, 'garbage', false);
        self::assertNull(wp_default_theme(), 're-validation when reading back: garbage is none; a file without the constant answers nothing');
    }
}
