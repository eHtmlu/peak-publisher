<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\get_current_release;
use function Pblsh\refresh_plugin_title_from_reference;
use function Pblsh\resolve_current_release;
use function Pblsh\select_current_release;

/**
 * The one derivation of "current release" (includes/functions.php): the release the
 * plugin's pointer names — the self-hosted meta, the wporg Stable tag of the cached
 * trunk readme — with latest and reference as the fallbacks.
 */
final class CurrentReleaseTest extends TestCase {

    public function test_select_returns_the_entry_the_pointer_names(): void {
        $entries = [ '1.0.0' => 'first', '1.1.0' => 'second' ];
        self::assertSame('second', select_current_release($entries, '1.1.0'));
    }

    #[DataProvider('pointers_without_selection')]
    public function test_select_is_null_for_pointers_that_name_nothing(?string $pointer): void {
        self::assertNull(select_current_release([ '1.0.0' => 'first' ], $pointer));
    }

    public static function pointers_without_selection(): array {
        return [
            'unknown (null)' => [ null ],
            'empty' => [ '' ],
            'trunk' => [ 'trunk' ],
            'no such version' => [ '9.9.9' ],
            'not normalized: the version string must match verbatim' => [ '1.0' ],
        ];
    }

    public function test_self_hosted_current_release_follows_the_meta(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');
        $current = $this->create_release($plugin, '1.1.0');
        $beta = $this->create_release($plugin, '2.0.0-beta1');
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.1.0');

        $resolved = resolve_current_release($plugin);

        self::assertSame('current', $resolved['state']);
        self::assertSame('1.1.0', $resolved['pointer']);
        self::assertSame($current->ID, $resolved['release']->ID);
        self::assertSame($beta->ID, $resolved['latest']->ID, 'latest is the highest version of all releases, the pointer does not limit it');
        self::assertSame($current->ID, $resolved['reference']->ID);
        self::assertSame($current->ID, get_current_release($plugin)->ID);
    }

    public function test_self_hosted_plugin_without_the_meta_has_no_current_release(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');
        $latest = $this->create_release($plugin, '1.1.0');

        $resolved = resolve_current_release($plugin);

        self::assertSame('none', $resolved['state']);
        self::assertSame('', $resolved['pointer']);
        self::assertNull($resolved['release']);
        self::assertSame($latest->ID, $resolved['latest']->ID);
        self::assertSame($latest->ID, $resolved['reference']->ID, 'reference falls back to latest');
    }

    public function test_self_hosted_pointer_to_a_missing_release_is_tag_missing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $latest = $this->create_release($plugin, '1.0.0');
        update_post_meta($plugin->ID, '_pblsh_current_release', '3.0.0');

        $resolved = resolve_current_release($plugin);

        self::assertSame('tag_missing', $resolved['state']);
        self::assertSame('3.0.0', $resolved['pointer']);
        self::assertNull($resolved['release']);
        self::assertSame($latest->ID, $resolved['reference']->ID);
    }

    public function test_plugin_without_releases_resolves_to_nothing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');

        $resolved = resolve_current_release($plugin);

        self::assertSame('none', $resolved['state']);
        self::assertNull($resolved['release']);
        self::assertNull($resolved['latest']);
        self::assertNull($resolved['reference']);
    }

    public function test_preloaded_releases_replace_the_store_lookup(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $only = $this->create_release($plugin, '1.0.0');
        $this->create_release($plugin, '2.0.0');
        update_post_meta($plugin->ID, '_pblsh_current_release', '2.0.0');

        $resolved = resolve_current_release($plugin, [ $only ]);

        self::assertSame('tag_missing', $resolved['state'], 'only the preloaded releases count');
        self::assertSame($only->ID, $resolved['latest']->ID);
    }

    public function test_latest_orders_by_normalized_version_not_by_string(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.9.0');
        $latest = $this->create_release($plugin, '1.10.0');

        self::assertSame($latest->ID, resolve_current_release($plugin)['latest']->ID);
    }

    #[DataProvider('wporg_marker_caches')]
    public function test_wporg_current_release_follows_the_cached_trunk_readme(?array $cache, string $state, ?string $pointer, ?string $current_version): void {
        $plugin = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', $cache);
        $this->create_release($plugin, '1.0.0');
        $latest = $this->create_release($plugin, '1.1.0');

        $resolved = resolve_current_release($plugin);

        self::assertSame($state, $resolved['state']);
        self::assertSame($pointer, $resolved['pointer']);
        self::assertSame($current_version, $resolved['release']?->post_title);
        self::assertSame($latest->ID, $resolved['latest']->ID);
        self::assertSame($current_version ?? '1.1.0', $resolved['reference']->post_title);
    }

    public static function wporg_marker_caches(): array {
        $readme = static fn(string $stable_tag): array => [ 'stable_tag' => $stable_tag, 'file_name' => 'readme.txt', 'screenshots' => [] ];
        return [
            'cache from before the trunk readme was cached' => [ [ 'revision' => 1, 'release_count' => 2 ], 'unknown', null, null ],
            'trunk readme not readable at the last refresh' => [ [ 'revision' => 1, 'release_count' => 2, 'trunk_readme' => null ], 'unknown', null, null ],
            'no readme in trunk' => [ [ 'revision' => 1, 'trunk_readme' => [ 'stable_tag' => '', 'file_name' => null, 'screenshots' => [] ] ], 'none', '', null ],
            'Stable tag: trunk' => [ [ 'revision' => 1, 'trunk_readme' => $readme('trunk') ], 'trunk', 'trunk', null ],
            'Stable tag names an existing tag' => [ [ 'revision' => 1, 'trunk_readme' => $readme('1.0.0') ], 'current', '1.0.0', '1.0.0' ],
            'Stable tag names a tag that does not exist' => [ [ 'revision' => 1, 'trunk_readme' => $readme('1.2.0') ], 'tag_missing', '1.2.0', null ],
        ];
    }

    public function test_plugin_title_follows_the_reference_release(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0', 'publish', 'Old Name');
        $this->create_release($plugin, '1.1.0', 'publish', 'New Name');

        refresh_plugin_title_from_reference($plugin->ID);
        self::assertSame('New Name', get_post($plugin->ID)->post_title, 'without a current release the latest one names the plugin');

        update_post_meta($plugin->ID, '_pblsh_current_release', '1.0.0');
        refresh_plugin_title_from_reference($plugin->ID);
        self::assertSame('Old Name', get_post($plugin->ID)->post_title, 'the current release names the plugin, even below the latest');
    }

    public function test_plugin_title_stays_without_releases(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');

        refresh_plugin_title_from_reference($plugin->ID);

        self::assertSame('My Plugin', get_post($plugin->ID)->post_title);
    }
}
