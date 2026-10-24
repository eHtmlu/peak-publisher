<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\flip_current_release_pointer;

/**
 * The flip — making another release of a self-hosted plugin the current one
 * (includes/functions.php): the second write site of the pointer, guarded by the
 * caller's expectation.
 */
final class CurrentReleaseFlipTest extends TestCase {

    public function test_flip_moves_the_pointer_and_the_title(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0', 'publish', 'Old Name');
        $this->create_release($plugin, '1.1.0', 'publish', 'New Name');
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.0.0');

        $result = flip_current_release_pointer($plugin, '1.1.0', '1.0.0');

        self::assertSame([ 'from' => '1.0.0', 'to' => '1.1.0' ], $result);
        self::assertSame('1.1.0', get_post_meta($plugin->ID, '_pblsh_current_release', true));
        self::assertSame('New Name', get_post($plugin->ID)->post_title, 'the title follows the new current release');
    }

    public function test_flip_from_no_current_release(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');

        $result = flip_current_release_pointer($plugin, '1.0.0', '');

        self::assertSame([ 'from' => '', 'to' => '1.0.0' ], $result);
        self::assertSame('1.0.0', get_post_meta($plugin->ID, '_pblsh_current_release', true));
    }

    public function test_flip_to_a_release_that_does_not_exist_is_refused(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.0.0');

        $result = flip_current_release_pointer($plugin, '9.9.9', '1.0.0');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('current_release_target_missing', $result->get_error_code());
        self::assertSame(404, $result->get_error_data()['status']);
        self::assertSame('1.0.0', get_post_meta($plugin->ID, '_pblsh_current_release', true));
    }

    public function test_flip_to_the_current_release_is_refused(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.0.0');

        $result = flip_current_release_pointer($plugin, '1.0.0', '1.0.0');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('invalid_current_release_target', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status']);
    }

    public function test_flip_on_a_changed_pointer_is_refused(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $this->create_release($plugin, '1.0.0');
        $this->create_release($plugin, '1.1.0');
        $this->create_release($plugin, '1.2.0');
        // Another admin flipped to 1.1.0 after this editor showed 1.0.0.
        update_post_meta($plugin->ID, '_pblsh_current_release', '1.1.0');

        $result = flip_current_release_pointer($plugin, '1.2.0', '1.0.0');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('current_release_changed', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
        self::assertSame('1.1.0', get_post_meta($plugin->ID, '_pblsh_current_release', true), 'the foreign flip stays');
    }
}
