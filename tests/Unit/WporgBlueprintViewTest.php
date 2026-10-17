<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\blueprint_ignored_reason;
use function Pblsh\blueprint_imported;
use function Pblsh\blueprint_playground_url;
use function Pblsh\live_preview_requirements;
use function Pblsh\wporg_theme_state_from_response;
use function Pblsh\write_release_snapshot;

/**
 * What the Live Preview tab shows beside the fields (includes/wporg_blueprint.php,
 * includes/wporg_api.php): the test link, why wordpress.org ignores a file it has, what the
 * current release requires, and whether a theme exists.
 */
final class WporgBlueprintViewTest extends TestCase {

    public function test_the_file_is_imported_once_the_stamp_names_it_at_or_after_the_mirrors_revision(): void {
        $file = [ 'revision' => 9, 'filesize' => 2, 'contents' => '{}' ];
        $stamp = static fn(array $assets): array => [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => $assets ];

        self::assertFalse(blueprint_imported(null, $file), 'no check yet');
        self::assertFalse(blueprint_imported($stamp([ 'icon.svg' => 12 ]), $file), 'the stamp has no blueprint');
        self::assertFalse(blueprint_imported($stamp([ 'blueprint.json' => 7 ]), $file), 'an older import: the file changed since');
        self::assertTrue(blueprint_imported($stamp([ 'blueprint.json' => 9 ]), $file));
        self::assertTrue(blueprint_imported($stamp([ 'blueprint.json' => 12 ]), $file), 'the import exports the whole plugin at its revision then');
    }

    public function test_the_test_link_carries_the_blueprint_in_the_fragment(): void {
        self::assertSame('https://playground.wordpress.net/#%7B%22landingPage%22%3A%22%5C%2F%22%7D', blueprint_playground_url('{"landingPage":"\/"}'));
    }

    public function test_a_file_wordpress_org_ignores_says_why(): void {
        self::assertSame('too_large', blueprint_ignored_reason([ 'revision' => 1, 'filesize' => 200000, 'contents' => null ]));
        self::assertSame('invalid', blueprint_ignored_reason([ 'revision' => 1, 'filesize' => 3, 'contents' => '{"steps":"x"}' ]));
        self::assertSame('invalid', blueprint_ignored_reason([ 'revision' => 1, 'filesize' => 3, 'contents' => 'nope' ]));
        self::assertNull(blueprint_ignored_reason([ 'revision' => 1, 'filesize' => 2, 'contents' => '{}' ]));
    }

    public function test_the_requirements_are_the_current_releases_requires_plugins(): void {
        $readme = [ 'stable_tag' => '1.0.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];
        $plugin = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 1, 'release_count' => 2, 'trunk_readme' => $readme ]);
        $current = $this->create_release($plugin, '1.0.0');
        write_release_snapshot($current, [ 'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => '1.0.0', 'RequiresPlugins' => 'woocommerce, buddypress' ], 'plugin_info' => [], 'plugin_readme_txt' => [] ]);
        $this->create_release($plugin, '1.1.0');

        self::assertSame([ 'version' => '1.0.0', 'slugs' => [ 'buddypress', 'woocommerce' ] ], live_preview_requirements(get_post($plugin->ID)));

        $trunk = $this->create_plugin('pblsh_wporg_plugin', 'other-plugin', 'publish', [ 'revision' => 1, 'release_count' => 1, 'trunk_readme' => [ ...$readme, 'stable_tag' => 'trunk' ] ]);
        self::assertNull(live_preview_requirements($trunk), 'trunk is distributed: there is no release to read');
    }

    public function test_the_theme_check_reads_the_themes_api(): void {
        self::assertSame('ok', wporg_theme_state_from_response(200, '{"name":"Twenty Twenty-Five","slug":"twentytwentyfive"}'));
        self::assertSame('not_found', wporg_theme_state_from_response(404, '{"error":"Theme not found"}'));
        self::assertNull(wporg_theme_state_from_response(200, 'garbage'));
        self::assertNull(wporg_theme_state_from_response(500, ''));
    }
}
