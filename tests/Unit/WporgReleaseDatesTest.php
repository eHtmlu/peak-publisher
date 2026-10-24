<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\get_release_slug;
use function Pblsh\wporg_create_release_post;
use function Pblsh\wporg_release_snapshot;
use function Pblsh\wporg_tag_publication_time;
use function Pblsh\wporg_tag_publication_times;
use function Pblsh\wporg_update_release_post;

/**
 * When a wordpress.org release was published (includes/wporg_cache.php): the first creation
 * of its tag, read from the history of tags/ — a version is published once, so a later change
 * inside the tag, a replace, or deleting and creating it anew keeps that date — and the
 * release post that is dated when it is created and keeps its date on every update.
 */
final class WporgReleaseDatesTest extends TestCase {

    /** A log entry as get_log_entries() returns it. */
    private static function commit(int $revision, int $time, array $paths): array {
        return [ 'revision' => $revision, 'time' => $time, 'paths' => $paths ];
    }

    public function test_a_tag_is_published_when_its_directory_was_first_created(): void {
        $log = [
            self::commit(30, 3000, [ '/my-plugin/tags/1.0.0/readme.txt' => 'M', '/my-plugin/trunk/readme.txt' => 'M' ]),
            self::commit(20, 2000, [ '/my-plugin/tags/1.0.0' => 'A', '/my-plugin/tags/1.0.0/readme.txt' => 'R', '/my-plugin/trunk/my-plugin.php' => 'M' ]),
        ];

        self::assertSame([ '1.0.0' => 2000 ], wporg_tag_publication_times($log, 'my-plugin'), 'the later "Tested up to" commit does not move it');
    }

    public function test_replacing_or_creating_a_tag_anew_keeps_its_first_publication(): void {
        $log = [
            self::commit(50, 5000, [ '/my-plugin/tags/2.0.0' => 'R' ]),
            self::commit(45, 4500, [ '/my-plugin/tags/2.0.0' => 'A' ]),
            self::commit(40, 4000, [ '/my-plugin/tags/1.0.0' => 'A' ]),
            self::commit(39, 3900, [ '/my-plugin/tags/1.0.0' => 'D' ]),
            self::commit(10, 1000, [ '/my-plugin/tags/1.0.0' => 'A' ]),
            self::commit(5, 500, [ '/my-plugin/tags/0.9.0' => 'R' ]),
        ];

        self::assertSame(
            [ '2.0.0' => 4500, '1.0.0' => 1000, '0.9.0' => 500 ],
            wporg_tag_publication_times($log, 'my-plugin'),
            'replaced in one commit, deleted and created anew: the first creation counts; a replace that is the oldest is the creation'
        );
    }

    public function test_only_this_plugins_tag_directories_count(): void {
        $log = [ self::commit(10, 1000, [
            '/my-plugin/tags/1.0.0/includes' => 'A',
            '/my-plugin/tags/' => 'A',
            '/my-plugin/trunk' => 'A',
            '/my-plugin-pro/tags/1.0.0' => 'A',
            '/other/tags/1.0.0' => 'A',
        ]) ];

        self::assertSame([], wporg_tag_publication_times($log, 'my-plugin'));
    }

    public function test_a_tag_the_history_does_not_show_is_dated_by_its_last_change(): void {
        $times = [ '1.0.0' => 1000 ];

        self::assertSame(1000, wporg_tag_publication_time($times, [ 'version' => '1.0.0', 'last_modified' => 'Tue, 18 Aug 2026 23:42:13 GMT', 'revision' => 7 ]));
        self::assertSame(
            (int) strtotime('Tue, 18 Aug 2026 23:42:13 GMT'),
            wporg_tag_publication_time($times, [ 'version' => '2.0.0', 'last_modified' => 'Tue, 18 Aug 2026 23:42:13 GMT', 'revision' => 7 ]),
            'arrived inside a copied parent directory'
        );
    }

    public function test_a_release_post_is_dated_when_created_and_keeps_its_date_on_update(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        $first = wporg_release_snapshot([ 'version' => '1.0.0', 'revision' => 20 ], [ 'plugin_data' => [ 'Version' => '1.0.0' ] ]);

        $id = wporg_create_release_post($marker, '1.0.0', $first, 1_775_260_965);
        $post = get_post($id);
        self::assertSame('2026-04-04 00:02:45', $post->post_date_gmt);
        self::assertSame('2026-04-04 00:02:45', $post->post_date, 'the fake site runs on UTC');
        self::assertSame(get_release_slug('wporg', 'my-plugin', '1.0.0'), $post->post_name);
        self::assertSame($first, json_decode($post->post_content, true));

        $corrected = wporg_release_snapshot([ 'version' => '1.0.0', 'revision' => 30 ], [ 'plugin_data' => [ 'Version' => '1.0.0' ] ]);
        self::assertSame($id, wporg_update_release_post($post, $corrected));
        $post = get_post($id);
        self::assertSame(30, json_decode($post->post_content, true)['tag_revision']);
        self::assertSame('2026-04-04 00:02:45', $post->post_date_gmt, 'a changed tag is a correction of the published version');
    }
}
