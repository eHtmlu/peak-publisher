<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use Pblsh\WporgSvnException;

use function Pblsh\confirm_wporg_directory_check;
use function Pblsh\get_wporg_directory;
use function Pblsh\is_wporg_directory_check_due;
use function Pblsh\is_wporg_figures_due;
use function Pblsh\record_wporg_directory_check_error;
use function Pblsh\refresh_wporg_directory;
use function Pblsh\serialize_self_hosted_installations;
use function Pblsh\serialize_wporg_directory;
use function Pblsh\wporg_directory_next_check_in;

use const Pblsh\PBLSH_WPORG_DIRECTORY_CHECKED_OPTION;
use const Pblsh\PBLSH_WPORG_DIRECTORY_META;

/**
 * The directory cache of wordpress.org plugins (includes/wporg_directory.php): the daily
 * figures — when a fetch is due, the claim before the remote call, one batched request, what
 * a success, a miss and a failure leave behind —, the directory stamp that tells the list a
 * plugin changed on wordpress.org, and the check's record: when a plugin was last brought in
 * step, completed only once a moved stamp's SVN refresh did.
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

    // ---- the rule ----

    public function test_the_automatic_path_fetches_once_a_day_after_the_utc_cut_off(): void {
        $noon = 1_800_000_000 - (1_800_000_000 % DAY_IN_SECONDS) + 12 * HOUR_IN_SECONDS;
        self::assertTrue(is_wporg_figures_due($this->stats([]), $noon), 'never fetched');
        self::assertTrue(is_wporg_figures_due($this->stats([ 'fetched_at' => $noon - DAY_IN_SECONDS, 'attempted_at' => $noon - DAY_IN_SECONDS ]), $noon), 'fetched yesterday');
        self::assertFalse(is_wporg_figures_due($this->stats([ 'fetched_at' => $noon - 11 * HOUR_IN_SECONDS, 'attempted_at' => $noon - 11 * HOUR_IN_SECONDS ]), $noon), 'fetched today after 00:00 UTC');
        self::assertFalse(is_wporg_figures_due($this->stats([ 'attempted_at' => $noon - 10 * MINUTE_IN_SECONDS ]), $noon), 'a claim younger than an hour blocks the automatic path');
        self::assertTrue(is_wporg_figures_due($this->stats([ 'attempted_at' => $noon - 2 * HOUR_IN_SECONDS ]), $noon), 'an hour after a dead claim the automatic path retries');
    }

    public function test_a_foreign_meta_shape_reads_as_never(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, 'not an array');
        self::assertSame('never', get_wporg_directory($marker->ID)['state']);

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, [ 'foreign' => 1, 'state' => 'ok', 'active_installs' => 10 ]);
        $stats = get_wporg_directory($marker->ID);
        self::assertSame('ok', $stats['state']);
        self::assertArrayNotHasKey('foreign', $stats);
        self::assertSame(0, $stats['fetched_at']);
    }

    // ---- the refresh ----

    public function test_refresh_claims_then_writes_every_marker_from_one_batched_request(): void {
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

        self::assertCount(1, FakeWordPress::$http_requests, 'one request for every due marker');
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
        self::assertNull($stats_a['last_error']);
        self::assertGreaterThanOrEqual($before, $stats_a['fetched_at']);
        self::assertSame($stats_a['attempted_at'], $stats_a['fetched_at'], 'a success sets fetched_at to the claim time');

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

    public function test_a_marker_fetched_today_is_left_alone(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => time(), 'attempted_at' => time() ]));
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());

        self::assertSame([], refresh_wporg_directory());
        self::assertSame([], FakeWordPress::$http_requests);
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

    public function test_a_look_at_given_markers_neither_consults_nor_claims_the_site_wide_check(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $today = time();
        update_post_meta($a->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $today, 'attempted_at' => $today ]));

        self::assertSame([], refresh_wporg_directory([ $a->ID ]), 'the site-wide check is due, this marker is not');
        self::assertSame([], FakeWordPress::$http_requests);

        $this->answer([ 'plugin-b' => $this->listing([ 'slug' => 'plugin-b' ]) ]);
        refresh_wporg_directory([ $b->ID ]);
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a' ]) ]);
        refresh_wporg_directory([ $a->ID ], true);
        self::assertSame(0, (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0), 'the list still gets its check');
    }

    public function test_refresh_of_a_self_hosted_plugin_does_nothing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');

        self::assertSame([], refresh_wporg_directory([ $plugin->ID ]));
        self::assertSame([], FakeWordPress::$http_requests);
    }

    public function test_a_transport_failure_records_the_error_beside_the_last_good_figures(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $yesterday = time() - DAY_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 5, 'fetched_at' => $yesterday, 'attempted_at' => $yesterday ]));
        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'cURL error 28');

        refresh_wporg_directory();

        $stats = get_wporg_directory($marker->ID);
        self::assertSame('ok', $stats['state'], 'the state survives');
        self::assertSame(1000, $stats['active_installs']);
        self::assertSame(5, $stats['downloaded']);
        self::assertSame($yesterday, $stats['fetched_at']);
        self::assertGreaterThan($yesterday, $stats['attempted_at'], 'the claim was written before the call');
        self::assertSame('wporg_api_unavailable', $stats['last_error']['code']);
        self::assertNotSame('', $stats['last_error']['message']);
        self::assertSame($stats['attempted_at'], $stats['last_error']['at']);
        self::assertSame($stats['last_error'], $stats['check_error'], 'an unreachable directory is the failed check too');
        self::assertSame(0, $stats['checked_at']);
    }

    public function test_a_listing_without_installation_figures_records_its_own_error(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => null ]) ]);

        refresh_wporg_directory();

        $stats = get_wporg_directory($marker->ID);
        self::assertSame('never', $stats['state']);
        self::assertNull($stats['active_installs']);
        self::assertSame('wporg_stats_incomplete', $stats['last_error']['code']);
        self::assertGreaterThan(0, $stats['checked_at'], 'the directory answered: the check itself completed');
        self::assertNull($stats['check_error']);
    }

    public function test_force_fetches_regardless_of_the_cut_off_and_the_last_attempt(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $now = time();
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $now - MINUTE_IN_SECONDS, 'attempted_at' => $now - MINUTE_IN_SECONDS ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 100 ]) ]);

        refresh_wporg_directory([ $marker->ID ], true);
        self::assertSame(100, get_wporg_directory($marker->ID)['active_installs'], 'force right after a success fetches again');

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'attempted_at' => $now - MINUTE_IN_SECONDS, 'last_error' => [ 'code' => 'wporg_api_unavailable', 'message' => 'x', 'at' => $now - MINUTE_IN_SECONDS ] ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 200 ]) ]);
        refresh_wporg_directory([ $marker->ID ], true);
        self::assertSame(200, get_wporg_directory($marker->ID)['active_installs'], 'force right after a failure fetches again');
        self::assertNull(get_wporg_directory($marker->ID)['last_error'], 'the success clears the failure');
        self::assertCount(2, FakeWordPress::$http_requests);
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
            if ($stats['state'] === 'ok' && $stats['last_error'] === null && $stats['check_error'] === null) {
                $states['ok']++;
            } elseif ($stats['state'] === 'never' && ($stats['last_error']['code'] ?? '') === 'wporg_api_unavailable' && ($stats['check_error']['code'] ?? '') === 'wporg_api_unavailable') {
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
            'state' => 'ok', 'active_installs' => 10, 'fetched_at' => time(), 'attempted_at' => time(),
            'stamp' => [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT', 'assets' => [] ], 'checked_at' => $checked,
        ]));
        $listing = $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => '2026-10-04 8:02am GMT', 'active_installs' => 999 ]);
        $moved = [ 'version' => '1.0.0', 'last_updated' => '2026-10-04 8:02am GMT', 'assets' => [] ];

        $this->answer([ 'plugin-a' => $listing ]);
        self::assertSame([ $marker->ID => $moved ], refresh_wporg_directory(), 'a commit to the stable tag moves last_updated alone');
        $stored = get_wporg_directory($marker->ID);
        self::assertSame('2026-10-01 9:15am GMT', $stored['stamp']['last_updated'], 'the old stamp stands until the SVN refresh completed');
        self::assertSame($checked, $stored['checked_at']);
        self::assertSame(10, $stored['active_installs'], 'the figures were not due and stay');

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
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $now, 'attempted_at' => $now, 'stamp' => $stamp ]));
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
            update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => $stamp, 'fetched_at' => time(), 'attempted_at' => time() ]));
        }
        $this->answer([ 'plugin-a' => $this->closed_listing('plugin-a'), 'plugin-b' => [ 'error' => 'Plugin not found.' ] ]);

        self::assertSame([], refresh_wporg_directory());
        self::assertSame($stamp, get_wporg_directory($a->ID)['stamp'], 'a closure keeps the last stamp');
        self::assertGreaterThan(0, get_wporg_directory($a->ID)['checked_at'], 'and is a completed check');
    }

    public function test_force_checks_the_stamp_of_one_marker_regardless_of_the_interval(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => [ 'version' => '1.0.0', 'last_updated' => 'A', 'assets' => [] ], 'fetched_at' => time(), 'attempted_at' => time() ]));
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
            'installations' => [ 'state' => 'never', 'count' => null, 'fetched_at' => null, 'attempted_at' => null, 'last_error' => null ],
            'wporg_stats' => [ 'downloaded' => null, 'rating' => null, 'num_ratings' => null, 'closed' => null, 'fetched_at' => null ],
            'wporg_check' => [ 'checked_at' => null, 'error' => null ],
        ], serialize_wporg_directory($marker->ID));

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([
            'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4,
            'fetched_at' => 1_800_000_000, 'attempted_at' => 1_800_003_600,
            'last_error' => [ 'code' => 'wporg_api_unavailable', 'message' => 'unavailable', 'at' => 1_800_003_600 ],
            'checked_at' => 1_800_000_000, 'check_error' => [ 'code' => 'svn_unreachable', 'message' => 'down', 'at' => 1_800_003_600 ],
        ]));
        $out = serialize_wporg_directory($marker->ID);
        self::assertSame('2027-01-15T08:00:00Z', $out['installations']['fetched_at']);
        self::assertSame('2027-01-15T09:00:00Z', $out['installations']['attempted_at']);
        self::assertSame([ 'code' => 'wporg_api_unavailable', 'message' => 'unavailable', 'at' => '2027-01-15T09:00:00Z' ], $out['installations']['last_error']);
        self::assertSame(1000, $out['installations']['count']);
        self::assertSame(977, $out['wporg_stats']['downloaded']);
        self::assertSame('2027-01-15T08:00:00Z', $out['wporg_stats']['fetched_at']);
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
