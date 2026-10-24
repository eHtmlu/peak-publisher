<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\get_plugin_downloads;
use function Pblsh\record_plugin_download;
use function Pblsh\summarize_plugin_downloads;

use const Pblsh\PBLSH_DOWNLOADS_META;

/**
 * The download history of a self-hosted plugin (includes/functions.php): one count per UTC
 * day, recorded with every delivered release ZIP, re-validated when read back, and the figures
 * the editor and the public API show — the all-time total and the last 7 and 30 days.
 */
final class DownloadsTest extends TestCase {

    public function test_a_delivery_counts_on_the_current_utc_day_and_keeps_the_other_days(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        update_post_meta($plugin->ID, PBLSH_DOWNLOADS_META, [ '2020-01-01' => 3 ]);

        record_plugin_download($plugin->ID);
        record_plugin_download($plugin->ID);

        self::assertSame([ '2020-01-01' => 3, gmdate('Y-m-d') => 2 ], get_plugin_downloads($plugin->ID));
    }

    public function test_the_history_is_empty_without_one_and_a_stray_id_records_nothing(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        record_plugin_download(0);

        self::assertSame([], get_plugin_downloads($plugin->ID));
    }

    public function test_reading_back_keeps_only_days_with_a_positive_count_in_day_order(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        update_post_meta($plugin->ID, PBLSH_DOWNLOADS_META, [
            '2026-03-02' => '4', '2026-03-01' => 1, 'yesterday' => 5, '2026-03-03' => 0, '2026-03-04' => 'many', 7 => 2,
        ]);

        self::assertSame([ '2026-03-01' => 1, '2026-03-02' => 4 ], get_plugin_downloads($plugin->ID));
    }

    public function test_the_figures_sum_the_total_today_and_the_windows_of_7_and_30_complete_days(): void {
        $days = [
            '2026-09-08' => 100, // 31 days before: outside both windows
            '2026-09-09' => 1,   // 30 days before: 30-day window
            '2026-10-01' => 2,   // 8 days before: 30-day window only
            '2026-10-02' => 4,   // 7 days before: both windows
            '2026-10-08' => 16,  // yesterday: both windows
            '2026-10-09' => 8,   // today: its own figure, in no window
            '2026-10-10' => 1000, // tomorrow: counted in the total only
        ];

        self::assertSame(
            [ 'total' => 1131, 'today' => 8, 'last_7_days' => 20, 'last_30_days' => 23 ],
            summarize_plugin_downloads($days, '2026-10-09'),
        );
        self::assertSame([ 'total' => 0, 'today' => 0, 'last_7_days' => 0, 'last_30_days' => 0 ], summarize_plugin_downloads([], '2026-10-09'));
    }
}
