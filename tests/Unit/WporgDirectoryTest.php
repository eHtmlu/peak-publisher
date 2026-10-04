<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\get_wporg_directory;
use function Pblsh\is_wporg_directory_check_due;
use function Pblsh\is_wporg_figures_due;
use function Pblsh\refresh_wporg_directory;
use function Pblsh\serialize_self_hosted_installations;
use function Pblsh\serialize_wporg_installations;

use const Pblsh\PBLSH_WPORG_DIRECTORY_CHECKED_OPTION;
use const Pblsh\PBLSH_WPORG_DIRECTORY_META;

/**
 * The directory cache of wordpress.org plugins (includes/wporg_directory.php): the daily
 * figures — when a fetch is due, the claim before the remote call, one batched request, what
 * a success, a miss and a failure leave behind — and the directory stamp that tells the list
 * a plugin changed on wordpress.org.
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

        $touched = refresh_wporg_directory()['figures'];

        self::assertEqualsCanonicalizing([ $a->ID, $b->ID, $c->ID ], $touched);
        self::assertCount(1, FakeWordPress::$http_requests, 'one request for every due marker');
        $query = $this->request_query();
        self::assertEqualsCanonicalizing([ 'plugin-a', 'plugin-b', 'plugin-c' ], explode(',', $query['request']['slugs']));
        self::assertStringContainsString(',downloaded,', ',' . $query['request']['fields'] . ',', 'downloaded must be requested positively');
        self::assertStringContainsString(',-sections,', ',' . $query['request']['fields'] . ',');
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
    }

    public function test_a_marker_fetched_today_is_left_alone(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => time(), 'attempted_at' => time() ]));
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());

        self::assertSame([ 'figures' => [], 'changed' => [] ], refresh_wporg_directory());
        self::assertSame([], FakeWordPress::$http_requests);
    }

    public function test_refresh_of_one_marker_touches_only_that_marker(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $this->answer([ 'plugin-b' => $this->listing([ 'slug' => 'plugin-b' ]) ]);

        self::assertSame([ $b->ID ], refresh_wporg_directory($b->ID)['figures']);
        self::assertSame('plugin-b', $this->request_query()['request']['slugs']);
        self::assertSame('never', get_wporg_directory($a->ID)['state']);
        self::assertSame('ok', get_wporg_directory($b->ID)['state']);
    }

    public function test_refresh_of_a_self_hosted_plugin_does_nothing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');

        self::assertSame([ 'figures' => [], 'changed' => [] ], refresh_wporg_directory($plugin->ID));
        self::assertSame([], FakeWordPress::$http_requests);
    }

    public function test_a_transport_failure_records_the_error_beside_the_last_good_figures(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $yesterday = time() - DAY_IN_SECONDS;
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 5, 'fetched_at' => $yesterday, 'attempted_at' => $yesterday ]));
        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'cURL error 28');

        self::assertSame([ $marker->ID ], refresh_wporg_directory()['figures'], 'a failed attempt counts as touched');

        $stats = get_wporg_directory($marker->ID);
        self::assertSame('ok', $stats['state'], 'the state survives');
        self::assertSame(1000, $stats['active_installs']);
        self::assertSame(5, $stats['downloaded']);
        self::assertSame($yesterday, $stats['fetched_at']);
        self::assertGreaterThan($yesterday, $stats['attempted_at'], 'the claim was written before the call');
        self::assertSame('wporg_api_unavailable', $stats['last_error']['code']);
        self::assertNotSame('', $stats['last_error']['message']);
        self::assertSame($stats['attempted_at'], $stats['last_error']['at']);
    }

    public function test_a_listing_without_installation_figures_records_its_own_error(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => null ]) ]);

        self::assertSame([ $marker->ID ], refresh_wporg_directory()['figures']);

        $stats = get_wporg_directory($marker->ID);
        self::assertSame('never', $stats['state']);
        self::assertNull($stats['active_installs']);
        self::assertSame('wporg_stats_incomplete', $stats['last_error']['code']);
    }

    public function test_force_fetches_regardless_of_the_cut_off_and_the_last_attempt(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $now = time();
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $now - MINUTE_IN_SECONDS, 'attempted_at' => $now - MINUTE_IN_SECONDS ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 100 ]) ]);

        self::assertSame([ $marker->ID ], refresh_wporg_directory($marker->ID, true)['figures'], 'force right after a success fetches again');
        self::assertSame(100, get_wporg_directory($marker->ID)['active_installs']);

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'attempted_at' => $now - MINUTE_IN_SECONDS, 'last_error' => [ 'code' => 'wporg_api_unavailable', 'message' => 'x', 'at' => $now - MINUTE_IN_SECONDS ] ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'active_installs' => 200 ]) ]);
        self::assertSame([ $marker->ID ], refresh_wporg_directory($marker->ID, true)['figures'], 'force right after a failure fetches again');
        self::assertSame(200, get_wporg_directory($marker->ID)['active_installs']);
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

        $touched = refresh_wporg_directory()['figures'];

        self::assertCount(101, $touched);
        self::assertCount(2, FakeWordPress::$http_requests);
        self::assertCount(100, explode(',', $this->request_query(0)['request']['slugs']));
        self::assertCount(1, explode(',', $this->request_query(1)['request']['slugs']));
        $states = [ 'ok' => 0, 'failed' => 0 ];
        foreach ($ids as $id) {
            $stats = get_wporg_directory($id);
            if ($stats['state'] === 'ok' && $stats['last_error'] === null) {
                $states['ok']++;
            } elseif ($stats['state'] === 'never' && ($stats['last_error']['code'] ?? '') === 'wporg_api_unavailable') {
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

        $result = refresh_wporg_directory();

        self::assertSame([ 'figures' => [ $marker->ID ], 'changed' => [] ], $result);
        self::assertSame([ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ], get_wporg_directory($marker->ID)['stamp']);
        self::assertGreaterThanOrEqual($before, (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION), 'the check claims the option');
    }

    public function test_a_moved_stamp_marks_the_marker_changed_although_its_figures_are_not_due(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $now = time();
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([
            'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $now, 'attempted_at' => $now,
            'stamp' => [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ],
        ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.0.0', 'last_updated' => '2026-10-04 8:02am GMT', 'active_installs' => 999 ]) ]);

        $result = refresh_wporg_directory();

        self::assertSame([ 'figures' => [], 'changed' => [ $marker->ID ] ], $result, 'an assets or trunk commit moves last_updated alone');
        $stored = get_wporg_directory($marker->ID);
        self::assertSame('2026-10-04 8:02am GMT', $stored['stamp']['last_updated'], 'the new stamp is the next comparison');
        self::assertSame(10, $stored['active_installs'], 'the figures were not due and stay');
    }

    public function test_an_unchanged_stamp_marks_nothing_and_the_check_waits_five_minutes(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $now = time();
        $stamp = [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ];
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'active_installs' => 10, 'fetched_at' => $now, 'attempted_at' => $now, 'stamp' => $stamp ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', ...$stamp ]) ]);

        self::assertSame([ 'figures' => [], 'changed' => [] ], refresh_wporg_directory());
        self::assertSame([ 'figures' => [], 'changed' => [] ], refresh_wporg_directory(), 'checked a moment ago');
        self::assertCount(1, FakeWordPress::$http_requests);
    }

    public function test_a_closed_or_missing_plugin_never_changes(): void {
        $a = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        $b = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $stamp = [ 'version' => '1.0.0', 'last_updated' => '2026-10-01 9:15am GMT' ];
        foreach ([ $a, $b ] as $marker) {
            update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => $stamp, 'fetched_at' => time(), 'attempted_at' => time() ]));
        }
        $this->answer([ 'plugin-a' => $this->closed_listing('plugin-a'), 'plugin-b' => [ 'error' => 'Plugin not found.' ] ]);

        self::assertSame([ 'figures' => [], 'changed' => [] ], refresh_wporg_directory());
        self::assertSame($stamp, get_wporg_directory($a->ID)['stamp'], 'a closure keeps the last stamp');
    }

    public function test_force_checks_the_stamp_of_one_marker_regardless_of_the_interval(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, time());
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([ 'state' => 'ok', 'stamp' => [ 'version' => '1.0.0', 'last_updated' => 'A' ], 'fetched_at' => time(), 'attempted_at' => time() ]));
        $this->answer([ 'plugin-a' => $this->listing([ 'slug' => 'plugin-a', 'version' => '1.1.0', 'last_updated' => 'B' ]) ]);

        self::assertSame([ 'figures' => [ $marker->ID ], 'changed' => [ $marker->ID ] ], refresh_wporg_directory($marker->ID, true));
    }

    // ---- the REST view ----

    public function test_serialize_wporg_installations_turns_timestamps_into_iso_8601_and_never_into_null(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        self::assertSame([
            'installations' => [ 'state' => 'never', 'count' => null, 'fetched_at' => null, 'attempted_at' => null, 'last_error' => null ],
            'wporg_stats' => [ 'downloaded' => null, 'rating' => null, 'num_ratings' => null, 'closed' => null, 'fetched_at' => null ],
        ], serialize_wporg_installations($marker->ID));

        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, $this->stats([
            'state' => 'ok', 'active_installs' => 1000, 'downloaded' => 977, 'rating' => 100, 'num_ratings' => 4,
            'fetched_at' => 1_800_000_000, 'attempted_at' => 1_800_003_600,
            'last_error' => [ 'code' => 'wporg_api_unavailable', 'message' => 'unavailable', 'at' => 1_800_003_600 ],
        ]));
        $out = serialize_wporg_installations($marker->ID);
        self::assertSame('2027-01-15T08:00:00Z', $out['installations']['fetched_at']);
        self::assertSame('2027-01-15T09:00:00Z', $out['installations']['attempted_at']);
        self::assertSame([ 'code' => 'wporg_api_unavailable', 'message' => 'unavailable', 'at' => '2027-01-15T09:00:00Z' ], $out['installations']['last_error']);
        self::assertSame(1000, $out['installations']['count']);
        self::assertSame(977, $out['wporg_stats']['downloaded']);
        self::assertSame('2027-01-15T08:00:00Z', $out['wporg_stats']['fetched_at']);
    }

    public function test_serialize_self_hosted_installations_reports_the_setting_as_a_state(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'plugin-a');
        update_post_meta($plugin->ID, '_pblsh_installations', [ 'k1' => [ 'last_seen' => time() ], 'k2' => [ 'last_seen' => time() ] ]);

        self::assertSame([ 'state' => 'ok', 'count' => 2 ], serialize_self_hosted_installations($plugin->ID));

        update_option('pblsh_settings', [ 'count_plugin_installations' => false ]);
        self::assertSame([ 'state' => 'disabled', 'count' => null ], serialize_self_hosted_installations($plugin->ID));
    }
}
