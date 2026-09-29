<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\mark_wporg_plugin_cache_stale;
use function Pblsh\resolve_current_release;

/**
 * mark_wporg_plugin_cache_stale() (includes/wporg_cache.php): the revision keys go, every
 * other fact stays, and facts the caller just established on SVN — the trunk readme a deploy
 * or flip wrote — are served until the next refresh.
 */
final class WporgCacheStaleTest extends TestCase {

    private const READ = [ 'stable_tag' => '1.0.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];

    public function test_stale_drops_the_revision_keys_and_keeps_the_rest(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 7, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);

        mark_wporg_plugin_cache_stale($marker->ID);

        self::assertSame([ 'trunk_readme' => self::READ, 'fetched_at' => 123 ], json_decode(get_post($marker->ID)->post_content, true));
    }

    public function test_known_facts_replace_the_cached_ones_until_the_refresh(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 7, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        $this->create_release($marker, '1.0.0');
        $this->create_release($marker, '1.1.0');
        $written = [ 'stable_tag' => '1.1.0', 'file_name' => 'readme.txt', 'screenshots' => [ '1' => 'The dashboard' ] ];

        mark_wporg_plugin_cache_stale($marker->ID, [ 'trunk_readme' => $written ]);

        $cache = json_decode(get_post($marker->ID)->post_content, true);
        self::assertSame($written, $cache['trunk_readme']);
        self::assertArrayNotHasKey('revision', $cache);
        self::assertSame('1.1.0', resolve_current_release(get_post($marker->ID))['pointer'], 'the current release follows the written pointer right away');
    }

    public function test_a_self_hosted_plugin_is_left_alone(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');

        mark_wporg_plugin_cache_stale($plugin->ID, [ 'trunk_readme' => self::READ ]);

        self::assertSame('', get_post($plugin->ID)->post_content);
    }
}
