<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\get_screenshot_captions;

/**
 * Where the screenshot captions come from (includes/assets.php): the readme the plugin page
 * shows — the current release's, wordpress.org's trunk readme when the pointer names no tag,
 * the latest release's as a fallback when there is no current one.
 */
final class AssetCaptionsTest extends TestCase {

    private function create_release_with_captions(\WP_Post $plugin, string $version, array $captions): \WP_Post {
        $release = $this->create_release($plugin, $version);
        wp_update_post([ 'ID' => $release->ID, 'post_content' => (string) json_encode([
            'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => $version ],
            'plugin_readme_txt' => [ 'found' => true, 'file_name' => 'readme.txt', 'content' => [ 'screenshots' => $captions ] ],
        ]) ]);
        return get_post($release->ID);
    }

    public function test_the_current_release_provides_the_captions(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release_with_captions($plugin, '1.0.0', [ '1' => 'Old' ]);
        $this->create_release_with_captions($plugin, '1.1.0', [ '1' => 'The dashboard', '2' => '<em>Settings</em>' ]);
        $this->create_release_with_captions($plugin, '2.0.0-beta1', [ '1' => 'Beta' ]);
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.1.0');

        $result = get_screenshot_captions($plugin);

        self::assertSame([ 1 => 'The dashboard', 2 => '<em>Settings</em>' ], $result['captions']);
        self::assertSame([ 'state' => 'current', 'version' => '1.1.0', 'fallback' => false ], $result['source']);
    }

    public function test_without_a_current_release_the_latest_is_the_fallback(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release_with_captions($plugin, '1.0.0', [ '1' => 'Old' ]);
        $this->create_release_with_captions($plugin, '1.2.0', [ '1' => 'Latest' ]);
        update_post_meta($plugin->ID, '_pblsh_current_release', '9.9.9');

        $result = get_screenshot_captions($plugin);

        self::assertSame([ 1 => 'Latest' ], $result['captions']);
        self::assertSame([ 'state' => 'tag_missing', 'version' => '1.2.0', 'fallback' => true ], $result['source']);
        self::assertSame([ 'captions' => [], 'source' => [ 'state' => 'none', 'version' => null, 'fallback' => true ] ], get_screenshot_captions($this->create_plugin('pblsh_plugin', 'empty')));
    }

    public function test_wporg_states_without_a_current_release_read_the_trunk_readme(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 1, 'trunk_readme' => [ 'stable_tag' => '1.5.0', 'file_name' => 'readme.txt', 'screenshots' => [ '1' => 'From trunk' ] ] ]);
        $this->create_release_with_captions($marker, '1.4.0', [ '1' => 'From the tag' ]);

        $result = get_screenshot_captions($marker);

        self::assertSame([ 1 => 'From trunk' ], $result['captions']);
        self::assertSame([ 'state' => 'tag_missing', 'version' => null, 'fallback' => false ], $result['source']);
    }

    public function test_an_unreadable_trunk_readme_falls_back_to_the_latest_tag(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin', 'publish', [ 'revision' => 1, 'trunk_readme' => null ]);
        $this->create_release_with_captions($marker, '1.4.0', [ '1' => 'From the tag' ]);

        $result = get_screenshot_captions($marker);

        self::assertSame([ 1 => 'From the tag' ], $result['captions']);
        self::assertSame([ 'state' => 'unknown', 'version' => '1.4.0', 'fallback' => true ], $result['source']);
    }
}
