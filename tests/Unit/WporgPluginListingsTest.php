<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\wporg_plugin_listings;

use const Pblsh\PBLSH_WPORG_PLUGIN_LISTINGS_OPTION;

/**
 * The directory listings of the other plugins the Live Preview names (includes/wporg_directory.php):
 * a site-wide cache a day fresh, filled in one request with icons, warn-only — a stale listing
 * serves on without an answer, a listing not renewed for a week goes.
 */
final class WporgPluginListingsTest extends TestCase {

    /** Scripts the next info API answer: HTTP 200 with the given slug → listing map. */
    private function answer(array $by_slug): void {
        FakeWordPress::$http_responses[] = [ 'code' => 200, 'body' => (string) json_encode($by_slug) ];
    }

    /** The query of the recorded request, decoded (request[slugs], request[fields]). */
    private function request_query(int $index = 0): array {
        $query = [];
        parse_str((string) parse_url(FakeWordPress::$http_requests[$index]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_the_listings_are_asked_once_with_icons_and_then_served_from_the_cache(): void {
        $this->answer([
            'woocommerce' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce', 'icons' => [ '1x' => 'https://ps.w.org/woocommerce/assets/icon-128x128.png', '2x' => 'https://ps.w.org/woocommerce/assets/icon-256x256.png' ] ],
            'no-such-plugin' => [ 'error' => 'Plugin not found.' ],
            'old-plugin' => [ 'error' => 'closed', 'name' => 'Old Plugin', 'slug' => 'old-plugin', 'closed' => true, 'description' => 'This plugin has been closed as of March 3, 2026.', 'closed_date' => '2026-03-03', 'reason_text' => 'Author Request' ],
        ]);

        $listings = wporg_plugin_listings([ 'woocommerce', 'no-such-plugin', 'old-plugin', 'woocommerce', 'Not A Slug' ]);

        self::assertSame([
            'woocommerce' => [ 'name' => 'WooCommerce', 'icon' => 'https://ps.w.org/woocommerce/assets/icon-256x256.png', 'state' => 'ok' ],
            'no-such-plugin' => [ 'name' => null, 'icon' => null, 'state' => 'not_found' ],
            'old-plugin' => [ 'name' => 'Old Plugin', 'icon' => null, 'state' => 'closed' ],
        ], $listings, 'the 2x icon preferred; what is no slug is neither asked nor listed');
        self::assertCount(1, FakeWordPress::$http_requests, 'one request for all');
        $query = $this->request_query();
        self::assertSame('woocommerce,no-such-plugin,old-plugin', $query['request']['slugs']);
        self::assertStringContainsString('icons', $query['request']['fields']);
        self::assertStringNotContainsString('-icons', $query['request']['fields'], 'the icons are asked for');

        self::assertSame($listings['woocommerce'], wporg_plugin_listings([ 'woocommerce' ])['woocommerce']);
        self::assertCount(1, FakeWordPress::$http_requests, 'served from the cache, no second request');
        self::assertSame([], wporg_plugin_listings([]), 'nothing asked for nothing');
    }

    public function test_a_stale_listing_is_renewed_serves_on_without_an_answer_and_an_old_one_goes(): void {
        $day = 86400;
        update_option(PBLSH_WPORG_PLUGIN_LISTINGS_OPTION, [
            'woocommerce' => [ 'name' => 'Woo', 'icon' => null, 'state' => 'ok', 'checked_at' => time() - $day - 1 ],
            'ancient' => [ 'name' => 'Ancient', 'icon' => null, 'state' => 'ok', 'checked_at' => time() - 8 * $day ],
        ], false);

        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'cURL error 28');
        self::assertSame([ 'woocommerce' => [ 'name' => 'Woo', 'icon' => null, 'state' => 'ok' ] ], wporg_plugin_listings([ 'woocommerce', 'new-plugin' ]), 'the API down: the stale listing serves on, the unknown stays unknown');
        self::assertCount(1, FakeWordPress::$http_requests, 'both were asked');
        self::assertSame([ 'woocommerce', 'ancient' ], array_keys(get_option(PBLSH_WPORG_PLUGIN_LISTINGS_OPTION)), 'nothing written without an answer');

        $this->answer([ 'woocommerce' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce' ], 'new-plugin' => [ 'name' => 'New Plugin', 'slug' => 'new-plugin' ] ]);
        $listings = wporg_plugin_listings([ 'woocommerce', 'new-plugin' ]);
        self::assertSame('WooCommerce', $listings['woocommerce']['name'], 'renewed');
        self::assertSame('New Plugin', $listings['new-plugin']['name']);
        self::assertSame([ 'woocommerce', 'new-plugin' ], array_keys(get_option(PBLSH_WPORG_PLUGIN_LISTINGS_OPTION)), 'the listing not renewed for a week is gone');
    }

    public function test_a_foreign_shape_in_the_option_is_no_listing(): void {
        update_option(PBLSH_WPORG_PLUGIN_LISTINGS_OPTION, 'garbage', false);
        $this->answer([ 'woocommerce' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce' ] ]);
        self::assertSame('WooCommerce', wporg_plugin_listings([ 'woocommerce' ])['woocommerce']['name'], 'asked anew');

        update_option(PBLSH_WPORG_PLUGIN_LISTINGS_OPTION, [ 'woocommerce' => [ 'name' => 'WooCommerce', 'state' => 'ok' ], 'akismet' => [ 'name' => 7, 'icon' => null, 'state' => 'ok', 'checked_at' => time() ] ], false);
        $this->answer([ 'woocommerce' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce' ], 'akismet' => [ 'name' => 'Akismet', 'slug' => 'akismet' ] ]);
        self::assertSame([ 'woocommerce', 'akismet' ], array_keys(wporg_plugin_listings([ 'woocommerce', 'akismet' ])), 'entries without a time or with a foreign field are asked anew');
        self::assertSame('woocommerce,akismet', $this->request_query(1)['request']['slugs']);
    }
}
