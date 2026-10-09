<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use Pblsh\WporgSvnException;

use function Pblsh\confirm_wporg_directory_check;
use function Pblsh\get_wporg_directory;
use function Pblsh\is_wporg_directory_check_due;
use function Pblsh\record_wporg_directory_check_error;
use function Pblsh\refresh_wporg_directory;
use function Pblsh\serialize_self_hosted_installations;
use function Pblsh\serialize_wporg_directory;
use function Pblsh\wporg_directory_next_check_in;

use const Pblsh\PBLSH_WPORG_DIRECTORY_CHECKED_OPTION;
use const Pblsh\PBLSH_WPORG_DIRECTORY_META;

/**
 * The directory cache of wordpress.org plugins (includes/wporg_directory.php): who is asked —
 * every marker when the site-wide check is due, given markers always —, the claim before the
 * remote call, one batched request, the figures every listing brings and what a success, a
 * miss and a failure leave behind, the directory stamp that tells the list a plugin changed on
 * wordpress.org, and the check's record: when a plugin was last brought in step, completed only
 * once a moved stamp's SVN refresh did.
 */
final class WporgDirectoryTest extends TestCase {

    private function listing(array $overrides = []): array {
        return array_merge([
            'name' => 'My Plugin', 'slug' => 'my-plugin', 'version' => '1.0.0',
            'active_installs' => 1000, 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4,
        ], $overrides);
    }

    private function closed_listing(string $slug): array {
        return [
            'error' => 'closed', 'name' => 'Old Plugin', 'slug' => $slug, 'closed' => true,
            'description' => 'This plugin has been closed as of March 3, 2026 and is not available for download. This closure is permanent. Reason: Author Request.',
            'closed_date' => '2026-03-03', 'reason' => 'author-request', 'reason_text' => 'Author Request',
        ];
    }

    /** Scripts the next info API answer: HTTP 200 with the given slug → listing map. */
    private function answer(array $by_slug): void {
        FakeWordPress::$http_responses[] = [ 'code' => 200, 'body' => (string) json_encode($by_slug) ];
    }

    private function stats(array $overrides): array {
        return array_merge(get_wporg_directory(0), $overrides);
    }

    /** The query of the one recorded request, decoded (request[slugs], request[fields]). */
    private function request_query(int $index = 0): array {
        $query = [];
        parse_str((string) parse_url(FakeWordPress::$http_requests[$index]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    // ---- the stored shape ----

    public function test_a_foreign_meta_shape_reads_as_never(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, 'not an array');
        self::assertSame('never', get_wporg_directory($marker->ID)['state']);

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, [ 'foreign' => 1, 'fetched_at' => 5, 'state' => 'ok', 'active_installs' => 10 ]);
        $stats = get_wporg_directory($marker->ID);
        self::assertSame('ok', $stats['state']);
        self::assertSame(10, $stats['active_installs']);
        self::assertArrayNotHasKey('foreign', $stats);
        self::assertArrayNotHasKey('fetched_at', $stats, 'a key of no longer stored shape is dropped');
        self::assertSame(0, $stats['checked_at']);
    }

    // ---- the refresh ----

    public function test_a_due_check_writes_every_marker_from_one_batched_request(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $c = $this->create_plugin('pblsh_wporg_plugin', 'plugin-c');
        $this->create_plugin('pblsh_plugin', 'self-hosted');
        $this->answer([
            'plugin-a' => $this->listing([ 'slug' => 'plugin-a' ]),
            'plugin-b' => $this->closed_listing('plugin-b'),
            'plugin-c' => [ 'error' => 'Plugin not found.' ],
        ]);
        $before = time();

        self::assertSame([], refresh_wporg_directory(), 'a first look moves no stamp');

        self::assertCount(1, FakeWordPress::$http_requests, 'one request for every marker');
        $query = $this->request_query();
        self::assertEqualsCanonicalizing([ 'plugin-a', 'plugin-b', 'plugin-c' ], explode(',', $query['request']['slugs']));
        self::assertStringContainsString(',downloaded,', ',' . $query['request']['fields'] . ',', 'downloaded must be requested positively');
        self::assertStringContainsString(',-sections,', ',' . $query['request']['fields'] . ',');
        foreach ([ 'last_updated', 'icons', 'banners', 'screenshots' ] as $field) {
            self::assertStringContainsString(',' . $field . ',', ',' . $query['request']['fields'] . ',', "the stamp's $field must be requested positively");
        }
        self::assertStringStartsWith('PeakPublisher/', FakeWordPress::$http_requests[0]['args']['user-agent']);

        $stats_a = get_wporg_directory($a->ID);
        self::assertSame('ok', $stats_a['state']);
        self::assertSame([ 1000, 977, 100, 4 ], [ $stats_a['active_installs'], $stats_a['downloaded'], $stats_a['rating'], $stats_a['num_ratings'] ]);
        self::assertNull($stats_a['closed']);

        $stats_b = get_wporg_directory($b->ID);
        self::assertSame('closed', $stats_b['state']);
        self::assertNull($stats_b['active_installs']);
        self::assertSame('2026-03-03', $stats_b['closed']['date']);
        self::assertStringStartsWith('This plugin has been closed', $stats_b['closed']['text']);

        $stats_c = get_wporg_directory($c->ID);
        self::assertSame('not_found', $stats_c['state']);
        self::assertNull($stats_c['active_installs']);
        self::assertNull($stats_c['closed']);

        foreach ([ $stats_a, $stats_b, $stats_c ] as $stats) {
            self::assertGreaterThanOrEqual($before, $stats['checked_at'], 'every determinate answer completes the check');
            self::assertNull($stats['check_error']);
        }
    }

    public function test_a_check_that_is_not_due_asks_nothing(): void {
        $fetched = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($fetched->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10 ]));
        $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());

        self::assertSame([], refresh_wporg_directory());
        self::assertSame([], FakeWordPress::$http_requests, 'not even a plugin without figures: they come with the next check');
    }

    public function test_every_check_takes_the_figures_it_gets(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 1000, 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4 ]) ]);
        refresh_wporg_directory();

        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 2000, 'downloaded' => 1026, 'rating' => 96, 'num_ratings' => 5 ]) ]);
        refresh_wporg_directory();

        $stats = get_wporg_directory($marker->ID);
        self::assertSame([ 2000, 1026, 96, 5 ], [ $stats['active_installs'], $stats['downloaded'], $stats['rating'], $stats['num_ratings'] ], 'wordpress.org publishes new figures at any hour');
    }

    public function test_refresh_of_one_marker_touches_only_that_marker(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $this->answer([ 'plugin-b' => $this->listing([ 'slug' => 'plugin-b' ]) ]);

        self::assertSame([], refresh_wporg_directory([ $b->ID ]));
        self::assertSame('plugin-b', $this->request_query()['request']['slugs']);
        self::assertSame('never', get_wporg_directory($a->ID)['state']);
        self::assertSame('ok', get_wporg_directory($b->ID)['state']);
    }

    public function test_given_markers_share_one_request_and_leave_the_others_alone(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $c = $this->create_plugin('pblsh_wporg_plugin', 'plugin-c');
        $this->answer([
            'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'last_updated' => '2026-10-01 9:15am GMT' ]),
            'plugin-b' => $this->listing([ 'slug' => 'plugin-b' ]),
        ]);

        self::assertSame([], refresh_wporg_directory([ $a->ID, $b->ID ]), 'the markers an import just created');

        self::assertCount(1, FakeWordPress::$http_requests);
        self::assertEqualsCanonicalizing([ 'plugin-a', 'plugin-b' ], explode(',', $this->request_query()['request']['slugs']));
        self::assertSame('ok', get_wporg_directory($a->ID)['state']);
        self::assertSame('ok', get_wporg_directory($b->ID)['state']);
        self::assertSame('2026-10-01 9:15am GMT', get_wporg_directory($a->ID)['stamp']['last_updated'], 'the first look records the stamp');
        self::assertGreaterThan(0, get_wporg_directory($a->ID)['checked_at'], 'an imported plugin arrives checked');
        self::assertSame('never', get_wporg_directory($c->ID)['state']);
    }

    public function test_given_markers_are_always_asked_and_leave_the_site_wide_check_alone(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        update_post_meta($a->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10 ]));

        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 20 ]) ]);
        self::assertSame([], refresh_wporg_directory([ $a->ID ]));
        self::assertSame(20, get_wporg_directory($a->ID)['active_installs'], 'a plugin with figures is asked all the same');

        $this->answer([ 'plugin-b' => $this->listing([ 'slug' => 'plugin-b' ]) ]);
        refresh_wporg_directory([ $b->ID ], true);
        self::assertCount(2, FakeWordPress::$http_requests);
        self::assertSame(0, (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0), 'the list still gets its check');
    }

    public function test_refresh_of_a_self_hosted_plugin_does_nothing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');

        self::assertSame([], refresh_wporg_directory([ $plugin->ID ]));
        self::assertSame([], FakeWordPress::$http_requests);
    }

    public function test_an_unreachable_directory_is_the_failed_check_and_keeps_the_last_figures(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ];
        $checked = time() - HOUR_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 5, 'stamp' => $stamp, 'checked_at' => $checked ]));
        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'cURL error 28');
        $before = time();

        self::assertSame([], refresh_wporg_directory());

        $stats = get_wporg_directory($marker->ID);
        self::assertSame('ok', $stats['state'], 'the state survives');
        self::assertSame([ 1000, 5 ], [ $stats['active_installs'], $stats['downloaded'] ]);
        self::assertSame('wporg_api_unavailable', $stats['check_error']['code']);
        self::assertNotSame('', $stats['check_error']['message']);
        self::assertGreaterThanOrEqual($before, $stats['check_error']['at']);
        self::assertSame($checked, $stats['checked_at'], 'the last completed check stands');
        self::assertSame($stamp, $stats['stamp']);
    }

    public function test_a_listing_without_installation_figures_is_a_failed_check(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ];
        $checked = time() - HOUR_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'stamp' => $stamp, 'checked_at' => $checked ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => null, 'last_updated' => 'B' ]) ]);

        self::assertSame([], refresh_wporg_directory(), 'its stamp is not compared: the check failed');

        $stats = get_wporg_directory($marker->ID);
        self::assertSame(10, $stats['active_installs'], 'the last figures stand');
        self::assertSame('wporg_stats_incomplete', $stats['check_error']['code']);
        self::assertSame($checked, $stats['checked_at']);
        self::assertSame($stamp, $stats['stamp'], 'the next check tries again');
    }

    public function test_a_failing_chunk_marks_only_its_own_markers(): void {
        $ids = [];
        $listings = [];
        for ($i = 1; $i <= 101; $i++) {
            $slug = sprintf('plugin-%03d', $i);
            $ids[] = $this->create_plugin('pblsh_wporg_plugin', $slug)->ID;
            $listings[$slug] = $this->listing([ 'slug' => $slug ]);
        }
        // The first chunk of 100 is answered (extra slugs in the map are ignored), the
        // second chunk hits a transport failure.
        $this->answer($listings);
        FakeWordPress::$http_responses[] = [ 'code' => 503, 'body' => '' ];

        refresh_wporg_directory();

        self::assertCount(2, FakeWordPress::$http_requests);
        self::assertCount(100, explode(',', $this->request_query(0)['request']['slugs']));
        self::assertCount(1, explode(',', $this->request_query(1)['request']['slugs']));
        $states = [ 'ok' => 0, 'failed' => 0 ];
        foreach ($ids as $id) {
            $stats = get_wporg_directory($id);
            if ($stats['state'] === 'ok' && $stats['check_error'] === null) {
                $states['ok']++;
            } elseif ($stats['state'] === 'never' && ($stats['check_error']['code'] ?? '') === 'wporg_api_unavailable') {
                $states['failed']++;
            }
        }
        self::assertSame([ 'ok' => 100, 'failed' => 1 ], $states);
    }

    // ---- the directory stamp ----

    public function test_the_directory_check_is_due_every_five_minutes_site_wide(): void {
        $now = 1_800_000_000;
        self::assertTrue(is_wporg_directory_check_due(0, $now), 'never checked');
        self::assertFalse(is_wporg_directory_check_due($now - 4 * MINUTE_IN_SECONDS, $now));
        self::assertTrue(is_wporg_directory_check_due($now - 6 * MINUTE_IN_SECONDS, $now));
    }

    public function test_the_first_look_records_the_stamp_without_marking_a_change(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ]) ]);
        $before = time();

        self::assertSame([], refresh_wporg_directory());

        self::assertSame([ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ], get_wporg_directory($marker->ID)['stamp']);
        self::assertGreaterThanOrEqual($before, get_wporg_directory($marker->ID)['checked_at']);
        self::assertGreaterThanOrEqual($before, (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION), 'the check claims the option');
    }

    /** The asset fields as the info API serves them: every URL names the file and the revision it was last changed in. */
    private function directory_assets(int $screenshot_revision): array {
        return [
            'icons' => [ '1x' => 'https://ps.w.org/plugin-a/assets/icon-128x128.png?rev=100', '2x' => 'https://ps.w.org/plugin-a/assets/icon-256x256.png?rev=101' ],
            'banners' => [ 'low' => 'https://ps.w.org/plugin-a/assets/banner-772x250.jpg?rev=102', 'high' => 'https://ps.w.org/plugin-a/assets/banner-1544x500.jpg?rev=103' ],
            'screenshots' => [
                '1' => [ 'src' => 'https://ps.w.org/plugin-a/assets/screenshot-1.png?rev=' . $screenshot_revision, 'caption' => 'The editor' ],
                '2' => [ 'src' => 'https://ps.w.org/plugin-a/assets/screenshot-2.png?rev=104', 'caption' => '' ],
            ],
        ];
    }

    public function test_the_stamp_carries_the_revision_of_every_asset_the_directory_serves(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'last_updated' => 'A', ...$this->directory_assets(105) ]) ]);

        self::assertSame([], refresh_wporg_directory());

        self::assertSame([
            'version' => '1.0.0',
            'last_updated' => 'A',
            'assets' => [
                'banner-1544x500.jpg' => 103, 'banner-772x250.jpg' => 102,
                'icon-128x128.png' => 100, 'icon-256x256.png' => 101,
                'screenshot-1.png' => 105, 'screenshot-2.png' => 104,
            ],
        ], get_wporg_directory($marker->ID)['stamp'], 'filename → revision, in filename order');
    }

    public function test_a_generated_icon_and_empty_asset_lists_leave_no_asset_in_the_stamp(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([
            'slug' => 'plugin-a', 'last_updated' => 'A',
            'icons' => [ 'default' => 'https://s.w.org/plugins/geopattern-icon/plugin-a_ffffff.svg' ], 'banners' => [], 'screenshots' => [],
        ]) ]);

        self::assertSame([], refresh_wporg_directory());

        self::assertSame([ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ], get_wporg_directory($marker->ID)['stamp']);
    }

    public function test_a_moved_asset_revision_opens_the_check_like_a_moved_last_updated(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'last_updated' => 'A', ...$this->directory_assets(105) ]) ]);
        self::assertSame([], refresh_wporg_directory(), 'the first look');
        $before = get_wporg_directory($marker->ID)['stamp'];

        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'last_updated' => 'A', ...$this->directory_assets(106) ]) ]);
        $open = refresh_wporg_directory();

        self::assertSame([ ...$before, 'assets' => [ ...$before['assets'], 'screenshot-1.png' => 106 ] ], $open[$marker->ID] ?? null, 'an assets commit moves a revision alone: version and last_updated stay');
        self::assertSame($before, get_wporg_directory($marker->ID)['stamp'], 'the old stamp stands until the SVN refresh completed');
    }

    public function test_a_stored_stamp_without_the_asset_revisions_reads_as_none(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => [ 'version' => '1.0.0', 'last_updated' => 'A' ] ]));

        self::assertNull(get_wporg_directory($marker->ID)['stamp'], 'a foreign shape is no stamp: the next check is a first look');
    }

    public function test_a_moved_stamp_is_answered_and_stands_until_its_check_is_confirmed(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $checked = time() - HOUR_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([
            'state' => 'ok', 'active_installs' => 10,
            'stamp' => [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ], 'checked_at' => $checked,
        ]));
        $listing = $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => '2026-10-04 8:02am GMT', 'active_installs' => 999 ]);
        $moved = [ 'version' => '1.0.0', 'last_updated' => '2026-10-04 8:02am GMT', 'assets' => [] ];

        $this->answer([ 'plugin-a' => $listing ]);
        self::assertSame([ $marker->ID => $moved ], refresh_wporg_directory(), 'a commit to the stable tag moves last_updated alone');
        $stored = get_wporg_directory($marker->ID);
        self::assertSame('2026-10-01 9:15am GMT', $stored['stamp']['last_updated'], 'the old stamp stands until the SVN refresh completed');
        self::assertSame($checked, $stored['checked_at']);
        self::assertSame(999, $stored['active_installs'], 'the figures come with the listing while the check stays open');

        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
        $this->answer([ 'plugin-a' => $listing ]);
        self::assertSame([ $marker->ID => $moved ], refresh_wporg_directory(), 'a refresh that did not complete is found again by the next check');

        confirm_wporg_directory_check($marker->ID, $moved);
        $stored = get_wporg_directory($marker->ID);
        self::assertSame($moved, $stored['stamp'], 'the new stamp is the next comparison');
        self::assertGreaterThan($checked, $stored['checked_at']);

        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
        $this->answer([ 'plugin-a' => $listing ]);
        self::assertSame([], refresh_wporg_directory());
    }

    public function test_a_failed_svn_refresh_is_the_failed_check_until_the_next_one_completes(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ];
        $checked = time() - HOUR_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => $stamp, 'checked_at' => $checked ]));

        record_wporg_directory_check_error($marker->ID, new WporgSvnException('svn_unreachable', 'SVN did not answer.', 502));

        $stored = get_wporg_directory($marker->ID);
        self::assertSame('svn_unreachable', $stored['check_error']['code']);
        self::assertSame('SVN did not answer.', $stored['check_error']['message']);
        self::assertGreaterThan($checked, $stored['check_error']['at']);
        self::assertSame($checked, $stored['checked_at'], 'the last completed check stands');
        self::assertSame($stamp, $stored['stamp']);

        confirm_wporg_directory_check($marker->ID);
        $stored = get_wporg_directory($marker->ID);
        self::assertNull($stored['check_error']);
        self::assertGreaterThan($checked, $stored['checked_at']);
        self::assertSame($stamp, $stored['stamp'], 'a check that did not ask the directory keeps the stamp');
    }

    public function test_an_unchanged_stamp_marks_nothing_and_the_check_waits_five_minutes(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $now = time();
        $stamp = [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ];
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'stamp' => $stamp ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ]) ]);

        self::assertSame([], refresh_wporg_directory());
        self::assertGreaterThanOrEqual($now, get_wporg_directory($marker->ID)['checked_at'], 'an unchanged stamp completes the check');
        self::assertSame([], refresh_wporg_directory(), 'checked a moment ago');
        self::assertCount(1, FakeWordPress::$http_requests);
    }

    public function test_a_closed_or_missing_plugin_never_changes(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ];
        foreach ([ $a, $b ] as $marker) {
            update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => $stamp ]));
        }
        $this->answer([ 'plugin-a' => $this->closed_listing('plugin-a'), 'plugin-b' => [ 'error' => 'Plugin not found.' ] ]);

        self::assertSame([], refresh_wporg_directory());
        self::assertSame($stamp, get_wporg_directory($a->ID)['stamp'], 'a closure keeps the last stamp');
        self::assertGreaterThan(0, get_wporg_directory($a->ID)['checked_at'], 'and is a completed check');
    }

    public function test_force_checks_the_stamp_of_one_marker_regardless_of_the_interval(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ] ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.1.0', 'last_updated' => 'B' ]) ]);

        self::assertSame([ $marker->ID => [ 'version' => '1.1.0', 'last_updated' => 'B', 'assets' => [] ] ], refresh_wporg_directory([ $marker->ID ], true));
    }

    public function test_force_leaves_every_check_open_for_the_caller_who_reads_svn(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ];
        $checked = time() - HOUR_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => $stamp, 'checked_at' => $checked ]));

        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => 'A' ]) ]);
        self::assertSame([ $marker->ID => $stamp ], refresh_wporg_directory([ $marker->ID ], true), 'an unchanged stamp too');
        self::assertSame($checked, get_wporg_directory($marker->ID)['checked_at'], 'the check is the SVN read\'s to complete');

        $this->answer([ 'plugin-a' => $this->closed_listing('plugin-a') ]);
        self::assertSame([ $marker->ID => null ], refresh_wporg_directory([ $marker->ID ], true), 'a listing without a stamp');
    }

    public function test_a_site_wide_look_without_markers_still_claims_the_check(): void {
        $this->create_plugin('pblsh_plugin', 'self-hosted');

        self::assertSame([], refresh_wporg_directory());

        self::assertSame([], FakeWordPress::$http_requests);
        self::assertGreaterThan(0, wporg_directory_next_check_in(time()), 'a client that still asks is told to wait, not to ask again at once');
    }

    public function test_the_next_check_is_due_five_minutes_after_the_claim(): void {
        $now = 1_800_000_000;
        self::assertSame(0, wporg_directory_next_check_in($now), 'never checked: now');
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, $now - 2 * MINUTE_IN_SECONDS);
        self::assertSame(3 * MINUTE_IN_SECONDS, wporg_directory_next_check_in($now));
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, $now - 6 * MINUTE_IN_SECONDS);
        self::assertSame(0, wporg_directory_next_check_in($now));
    }

    // ---- the REST view ----

    public function test_serialize_wporg_directory_turns_timestamps_into_iso_8601_and_never_into_null(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        self::assertSame([
            'installations' => [ 'state' => 'never', 'count' => null ],
            'downloads' => [ 'total' => null, 'last_7_days' => null, 'last_30_days' => null ],
            'wporg_stats' => [ 'downloaded' => null, 'rating' => null, 'num_ratings' => null, 'closed' => null ],
            'wporg_check' => [ 'checked_at' => null, 'error' => null ],
        ], serialize_wporg_directory($marker->ID), 'installations and downloads in the shape of the self-hosted ones');

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([
            'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4,
            'checked_at' => 1_800_000_000, 'check_error' => [ 'code' => 'svn_unreachable', 'message' => 'down', 'at' => 1_800_003_600 ],
        ]));
        $out = serialize_wporg_directory($marker->ID);
        self::assertSame([ 'state' => 'ok', 'count' => 1000 ], $out['installations']);
        self::assertSame([ 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4, 'closed' => null ], $out['wporg_stats']);
        self::assertSame([ 'total' => 977, 'last_7_days' => null, 'last_30_days' => null ], $out['downloads'], 'the windows wait for the history');
        self::assertSame([ 'checked_at' => '2027-01-15T08:00:00Z', 'error' => [ 'code' => 'svn_unreachable', 'message' => 'down', 'at' => '2027-01-15T09:00:00Z' ] ], $out['wporg_check']);
    }

    public function test_serialize_self_hosted_installations_reports_the_setting_as_a_state(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        update_post_meta($plugin->ID, '_pblsh_installations', [ 'k1' => [ 'last_seen' => time() ], 'k2' => [ 'last_seen' => time() ] ]);

        self::assertSame([ 'state' => 'ok', 'count' => 2 ], serialize_self_hosted_installations($plugin->ID));

        update_option('pblsh_settings', [ 'count_plugin_installations' => false ]);
        self::assertSame([ 'state' => 'disabled', 'count' => null ], serialize_self_hosted_installations($plugin->ID));
    }
}
