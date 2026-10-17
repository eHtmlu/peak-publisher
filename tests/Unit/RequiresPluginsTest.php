<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\is_wordpress_org_slug;
use function Pblsh\read_plugin_headers;
use function Pblsh\requires_plugins_entries;
use function Pblsh\requires_plugins_slugs;
use function Pblsh\wporg_requires_plugins_resolution;

/**
 * The Requires Plugins header (includes/readme.php): read on every WordPress version, its
 * entries as written, its slugs cleaned the way core's plugin dependencies clean them, in the
 * header's order — and the entries resolved as wordpress.org's import resolves them
 * (includes/wporg_api.php).
 */
final class RequiresPluginsTest extends TestCase {

    public function test_the_entries_are_what_the_header_says(): void {
        self::assertSame([ 'woocommerce', 'Akismet', 'buddypress', 'my_plugin' ], requires_plugins_entries(' woocommerce, Akismet ,buddypress,woocommerce,,my_plugin'), 'trimmed, each once, in the header\'s order, a spelling that is no slug kept');
        self::assertSame([], requires_plugins_entries(''));
    }

    public function test_the_slugs_are_cleaned_like_core_does_in_the_headers_order(): void {
        self::assertSame([ 'woocommerce', 'buddypress' ], requires_plugins_slugs(' woocommerce, Akismet ,buddypress,woocommerce,,my_plugin'), 'trimmed, wordpress.org slugs only, each once, in the header\'s order');
        self::assertSame([], requires_plugins_slugs(''));
        self::assertSame([ 'c1', 'a-b' ], requires_plugins_slugs('c1,a-b,-x,y-,a--b'));
        self::assertSame([ 'advanced-custom-fields', 'acf-extended' ], requires_plugins_slugs('advanced-custom-fields, acf-extended'), 'a dependency that requires the other keeps its place after it');
    }

    public function test_the_entries_resolve_as_wordpress_orgs_import_resolves_them(): void {
        $states = [
            'woocommerce' => [ 'state' => 'ok', 'data' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce' ] ],
            'gone' => [ 'state' => 'not_found', 'data' => [] ],
            'shut' => [ 'state' => 'closed', 'data' => [ 'name' => 'Shut Plugin', 'closed' => [ 'date' => null, 'text' => '', 'reason' => null ] ] ],
            'old-name' => [ 'state' => 'ok', 'data' => [ 'name' => 'Renamed', 'slug' => 'new-name' ] ],
        ];
        self::assertSame([
            [ 'entry' => 'woocommerce', 'state' => 'ok', 'name' => 'WooCommerce' ],
            [ 'entry' => 'My_Plugin', 'state' => 'invalid', 'name' => null ],
            [ 'entry' => 'gone', 'state' => 'not_found', 'name' => null ],
            [ 'entry' => 'shut', 'state' => 'closed', 'name' => 'Shut Plugin' ],
            [ 'entry' => 'old-name', 'state' => 'renamed', 'name' => 'Renamed', 'slug_now' => 'new-name' ],
            [ 'entry' => 'unasked', 'state' => 'unchecked', 'name' => null ],
        ], wporg_requires_plugins_resolution([ 'woocommerce', 'My_Plugin', 'gone', 'shut', 'old-name', 'unasked' ], $states), 'published by exactly its slug is ok; no slug, missing, closed, known under another slug fail the import; unasked stays open');
        self::assertSame([], wporg_requires_plugins_resolution([], []));
    }

    public function test_a_wordpress_org_slug_is_lowercase_words_joined_by_single_hyphens(): void {
        self::assertTrue(is_wordpress_org_slug('woocommerce'));
        self::assertTrue(is_wordpress_org_slug('wp-2fa'));
        self::assertFalse(is_wordpress_org_slug('WooCommerce'));
        self::assertFalse(is_wordpress_org_slug('my_plugin'));
        self::assertFalse(is_wordpress_org_slug('a--b'));
        self::assertFalse(is_wordpress_org_slug(''));
    }

    public function test_read_plugin_headers_always_carries_requires_plugins(): void {
        $file = tempnam(sys_get_temp_dir(), 'pblsh');
        file_put_contents($file, "<?php\n/**\n * Plugin Name: My Plugin\n * Requires Plugins: woocommerce, buddypress\n */\n");
        $with = read_plugin_headers($file);
        file_put_contents($file, "<?php\n/**\n * Plugin Name: My Plugin\n */\n");
        $without = read_plugin_headers($file);
        unlink($file);

        self::assertSame('woocommerce, buddypress', $with['RequiresPlugins']);
        self::assertSame('', $without['RequiresPlugins']);
    }
}
