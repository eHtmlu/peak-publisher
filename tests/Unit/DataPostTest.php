<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\write_data_post;
use function Pblsh\write_release_snapshot;

/**
 * Posts whose content is data — release snapshots, the marker cache — are written through
 * write_data_post() (includes/functions.php): their JSON leaves WordPress's save filters as it
 * entered them, for a user without unfiltered_html too (kses, simulated here by strip_tags).
 */
final class DataPostTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        // kses for a user without unfiltered_html: tags are stripped from the content.
        add_filter('content_save_pre', 'strip_tags');
    }

    public function test_a_release_snapshot_keeps_its_html_through_kses(): void {
        $release = get_post(wp_insert_post([ 'post_type' => 'pblsh_release', 'post_title' => '1.4', 'post_content' => '' ]));
        $snapshot = [ 'plugin_data' => [ 'Name' => 'My Plugin' ], 'plugin_info' => [], 'plugin_readme_txt' => [ 'content' => [ 'sections' => [ 'description' => '<p>Does <strong>this</strong>.</p>' ] ] ] ];

        self::assertTrue(write_release_snapshot($release, $snapshot));

        self::assertSame($snapshot, json_decode(get_post($release->ID)->post_content, true));
    }

    public function test_a_new_data_post_is_stored_as_given(): void {
        $json = '{"trunk_readme":{"stable_tag":"1.4","content":"<b>x</b>"}}';

        $id = write_data_post([ 'post_type' => 'pblsh_wporg_plugin', 'post_title' => 'x', 'post_content' => $json ]);

        self::assertSame($json, get_post($id)->post_content);
    }

    public function test_an_update_of_another_field_keeps_the_content(): void {
        $json = '{"sections":"<p>a</p>"}';
        $id = write_data_post([ 'post_type' => 'pblsh_wporg_plugin', 'post_title' => 'x', 'post_content' => $json ]);

        write_data_post([ 'ID' => $id, 'post_title' => 'My Plugin' ]);

        self::assertSame('My Plugin', get_post($id)->post_title);
        self::assertSame($json, get_post($id)->post_content, 'WordPress saves the stored content again on every update');
    }

    public function test_other_posts_still_pass_the_save_filters(): void {
        write_data_post([ 'post_type' => 'pblsh_release', 'post_title' => '1.4', 'post_content' => '<p>x</p>' ]);

        $id = wp_insert_post([ 'post_type' => 'post', 'post_title' => 'y', 'post_content' => '<p>y</p>' ]);

        self::assertSame('y', get_post($id)->post_content);
    }
}
