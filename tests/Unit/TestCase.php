<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base class of the unit tests: a fresh in-memory WordPress per test and the two
 * fixtures every test around plugins needs.
 */
abstract class TestCase extends PHPUnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        FakeWordPress::reset();
    }

    /**
     * @param 'pblsh_plugin'|'pblsh_wporg_plugin' $post_type
     * @param array<string, mixed> $marker_cache The wporg marker cache (post_content), when given.
     */
    protected function create_plugin(string $post_type, string $slug, string $status = 'publish', ?array $marker_cache = null): \WP_Post {
        $id = wp_insert_post([
            'post_type' => $post_type,
            'post_status' => $status,
            'post_title' => ucwords(str_replace('-', ' ', $slug)),
            'post_name' => $slug,
            'post_content' => $marker_cache === null ? '' : (string) json_encode($marker_cache),
        ]);
        return get_post($id);
    }

    /**
     * A release post as finalize and the tag sync store it: the version as title, the
     * plugin name in the content's plugin_data.
     */
    protected function create_release(\WP_Post $plugin, string $version, string $status = 'publish', string $name = 'My Plugin'): \WP_Post {
        $id = wp_insert_post([
            'post_type' => 'pblsh_release',
            'post_status' => $status,
            'post_title' => $version,
            'post_name' => $plugin->post_name . '_' . $version,
            'post_parent' => $plugin->ID,
            'post_content' => (string) json_encode([ 'plugin_data' => [ 'Name' => $name, 'Version' => $version ] ]),
        ]);
        return get_post($id);
    }
}
