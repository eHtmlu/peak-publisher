<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\is_wordpress_org_slug;
use function Pblsh\read_plugin_headers;
use function Pblsh\requires_plugins_slugs;

/**
 * The Requires Plugins header (includes/readme.php): read on every WordPress version, its slugs
 * cleaned the way core's plugin dependencies clean them.
 */
final class RequiresPluginsTest extends TestCase {

    public function test_the_slugs_are_cleaned_like_core_does(): void {
        self::assertSame([ 'buddypress', 'woocommerce' ], requires_plugins_slugs(' woocommerce, Akismet ,buddypress,woocommerce,,my_plugin'), 'trimmed, wordpress.org slugs only, unique, sorted');
        self::assertSame([], requires_plugins_slugs(''));
        self::assertSame([ 'a-b', 'c1' ], requires_plugins_slugs('c1,a-b,-x,y-,a--b'));
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
