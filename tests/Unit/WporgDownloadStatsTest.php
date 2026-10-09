<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use Pblsh\WporgSvnException;

use function Pblsh\get_plugin_downloads;
use function Pblsh\get_wporg_directory;
use function Pblsh\is_wporg_download_stats_due;
use function Pblsh\refresh_wporg_download_stats;
use function Pblsh\serialize_wporg_directory;
use function Pblsh\wporg_api_download_stats;
use function Pblsh\wporg_directory_merge_downloads;

use const Pblsh\PBLSH_DOWNLOADS_META;
use const Pblsh\PBLSH_WPORG_DIRECTORY_META;
use const Pblsh\PBLSH_WPORG_STATS_URL;

/**
 * The download history of a wordpress.org plugin (includes/wporg_directory.php,
 * wporg_api_download_stats()): the stats API's complete days, fetched once per marker and UTC
 * day under a claim written before the request, merged into the history kept here — the API's
 * day wins, the days past its window stay —, and the figures the directory view serves.
 */
final class WporgDownloadStatsTest extends TestCase {

    /** Scripts the next stats answer: HTTP 200 with the given day map. */
    private function answer(array $days): void {
        FakeWordPress::$http_responses[] = [ 'code' => 200, 'body' => (string) json_encode((object) $days) ];
    }

    private function request_query(int $index = 0): array {
        $query = [];
        parse_str((string) parse_url(FakeWordPress::$http_requests[$index]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_the_fetch_is_due_once_per_utc_day(): void {
        $noon = gmmktime(12, 0, 0, 2026, 10, 9);
        self::assertTrue(is_wporg_download_stats_due(0, $noon), 'never fetched');
        self::assertTrue(is_wporg_download_stats_due(gmmktime(23, 59, 59, 2026, 10, 8), $noon), 'fetched yesterday, a minute before midnight');
        self::assertFalse(is_wporg_download_stats_due(gmmktime(0, 0, 1, 2026, 10, 9), $noon), 'fetched today, a second after midnight');
        self::assertFalse(is_wporg_download_stats_due($noon, $noon));
    }

    public function test_the_api_days_win_in_their_window_and_the_days_beyond_it_stay(): void {
        $stored = [ '2024-01-01' => 5, '2026-10-01' => 3, '2026-10-02' => 7 ];
        $api = [ '2026-10-02' => 9, '2026-10-03' => 0, '2026-10-04' => 2, '2026-10-01' => 0 ];

        self::assertSame(
            [ '2024-01-01' => 5, '2026-10-02' => 9, '2026-10-04' => 2 ],
            wporg_directory_merge_downloads($stored, $api),
            'a day the API now counts as none is dropped, a day it no longer serves is kept'
        );
    }

    public function test_the_stats_api_is_asked_for_the_full_window_and_answers_days(): void {
        $this->answer([ '2026-10-07' => '1', '2026-10-08' => '16' ]);

        self::assertSame([ '2026-10-07' => 1, '2026-10-08' => 16 ], wporg_api_download_stats('my-plugin'));

        self::assertStringStartsWith(PBLSH_WPORG_STATS_URL, FakeWordPress::$http_requests[0]['url']);
        self::assertSame([ 'slug' => 'my-plugin', 'limit' => '730' ], $this->request_query());
        self::assertStringStartsWith('PeakPublisher/', FakeWordPress::$http_requests[0]['args']['user-agent']);
    }

    public function test_an_unknown_plugin_answers_no_days_and_a_foreign_shape_is_unavailable(): void {
        $this->answer([]);
        self::assertSame([], wporg_api_download_stats('nobody'));

        FakeWordPress::$http_responses[] = [ 'code' => 200, 'body' => '{"error":"Plugin not found."}' ];
        $this->expectException(WporgSvnException::class);
        $this->expectExceptionMessage('wordpress.org API unavailable');
        wporg_api_download_stats('my-plugin');
    }

    public function test_the_refresh_fetches_the_due_markers_under_a_claim_and_merges_their_history(): void {
        $due = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($due->ID, PBLSH_DOWNLOADS_META, [ '2024-01-01' => 5, '2026-10-07' => 1 ]);
        $fetched_today = $this->create_plugin('pblsh_wporg_plugin', 'plugin-b');
        $now = time();
        update_post_meta($fetched_today->ID, PBLSH_WPORG_DIRECTORY_META, array_merge(get_wporg_directory(0), [ 'downloads_fetched_at' => $now ]));
        $this->answer([ '2026-10-07' => '4', '2026-10-08' => '16' ]);

        refresh_wporg_download_stats([ $due, $fetched_today ], $now);

        self::assertCount(1, FakeWordPress::$http_requests, 'the marker fetched today is left alone');
        self::assertSame('plugin-a', $this->request_query()['slug']);
        self::assertSame([ '2024-01-01' => 5, '2026-10-07' => 4, '2026-10-08' => 16 ], get_plugin_downloads($due->ID));
        self::assertSame($now, get_wporg_directory($due->ID)['downloads_fetched_at'], 'the day is claimed');
        self::assertSame([], get_plugin_downloads($fetched_today->ID));
    }

    public function test_a_failed_fetch_keeps_the_history_and_the_claim_and_leaves_the_check_untouched(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($marker->ID, PBLSH_DOWNLOADS_META, [ '2026-10-07' => 1 ]);
        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'cURL error 28');
        $now = time();

        refresh_wporg_download_stats([ $marker ], $now);

        self::assertSame([ '2026-10-07' => 1 ], get_plugin_downloads($marker->ID));
        $directory = get_wporg_directory($marker->ID);
        self::assertSame($now, $directory['downloads_fetched_at'], 'the claim stands: tomorrow tries again, the window loses nothing');
        self::assertNull($directory['check_error'], 'the directory check is not this fetch\'s');

        refresh_wporg_download_stats([ $marker ], $now + 60);
        self::assertCount(1, FakeWordPress::$http_requests, 'no second attempt on the same day');
    }

    public function test_the_directory_view_serves_the_total_with_the_windows_of_the_history(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'plugin-a');
        update_post_meta($marker->ID, PBLSH_WPORG_DIRECTORY_META, array_merge(get_wporg_directory(0), [ 'state' => 'ok', 'active_installs' => 10, 'downloaded' => 977 ]));
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
        $forty_days_ago = gmdate('Y-m-d', time() - 40 * DAY_IN_SECONDS);
        update_post_meta($marker->ID, PBLSH_DOWNLOADS_META, [ $forty_days_ago => 100, $yesterday => 3 ]);

        self::assertSame([ 'total' => 977, 'last_7_days' => 3, 'last_30_days' => 3 ], serialize_wporg_directory($marker->ID)['downloads'], "the total is the directory's, the windows the history's, today ($today) not in it");
    }
}
