<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\advance_wporg_plugin_cache;
use function Pblsh\mark_wporg_plugin_cache_stale;
use function Pblsh\resolve_current_release;

/**
 * The marker cache after a commit of Peak Publisher's own (includes/wporg_cache.php):
 * mark_wporg_plugin_cache_stale() drops the revision keys and keeps every other fact, serving
 * what the caller just established on SVN until the next refresh; advance_wporg_plugin_cache()
 * keeps a cache that is fresh at the commit's base revision fresh at the new one.
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

    public function test_a_cache_fresh_at_the_base_advances_to_the_commit_revision(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 7, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        $written = [ 'stable_tag' => '1.1.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];

        advance_wporg_plugin_cache($marker->ID, 7, 8, [ 'trunk_readme' => $written ]);

        self::assertSame([ 'revision' => 8, 'release_count' => 2, 'trunk_readme' => $written, 'fetched_at' => 123 ], json_decode(get_post($marker->ID)->post_content, true), 'the caller verified nothing else changed since 7: the next read needs no refresh');
    }

    public function test_a_cache_at_another_revision_than_the_base_goes_stale(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 6, 'release_count' => 2, 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        $written = [ 'stable_tag' => '1.1.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];

        advance_wporg_plugin_cache($marker->ID, 7, 8, [ 'trunk_readme' => $written ]);

        self::assertSame([ 'trunk_readme' => $written, 'fetched_at' => 123 ], json_decode(get_post($marker->ID)->post_content, true), 'the cache predates the base: it was stale already, the written readme is served meanwhile');
    }

    public function test_a_stale_cache_stays_stale_and_serves_the_known_facts(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'trunk_readme' => self::READ, 'fetched_at' => 123 ]);
        $written = [ 'stable_tag' => '1.1.0', 'file_name' => 'readme.txt', 'screenshots' => [] ];

        advance_wporg_plugin_cache($marker->ID, 7, 8, [ 'trunk_readme' => $written ]);

        self::assertSame([ 'trunk_readme' => $written, 'fetched_at' => 123 ], json_decode(get_post($marker->ID)->post_content, true), 'a pending refresh is not cancelled by a commit');
    }

    public function test_advancing_leaves_a_self_hosted_plugin_alone(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');

        advance_wporg_plugin_cache($plugin->ID, 7, 8, [ 'trunk_readme' => self::READ ]);

        self::assertSame('', get_post($plugin->ID)->post_content);
    }
}
