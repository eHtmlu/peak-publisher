<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\finalize_status_view;
use function Pblsh\read_finalize_status;
use function Pblsh\record_finalize_status;

/**
 * The status of an upload's finalize (includes/uploads.php): its progress while it runs and
 * its response once it ended, recorded in the upload's working directory, so a client whose
 * request lost its answer (a gateway timeout) can read back how far the publish got.
 */
final class FinalizeStatusTest extends TestCase {

    private function tmp_root(): string {
        $root = FakeWordPress::$upload_dir . '/tmp/20261015-120000_abcdefgh_user-1/';
        mkdir($root, 0777, true);
        return $root;
    }

    private function running(int $alive_at, string $phase = 'send', int $done = 3, int $total = 10): array {
        return [ 'started_at' => 1000, 'alive_at' => $alive_at, 'phase' => $phase, 'done' => $done, 'total' => $total, 'response' => null ];
    }

    public function test_without_a_status_the_finalize_has_not_started(): void {
        self::assertSame([ 'state' => 'not_started' ], finalize_status_view(null, 2000));
    }

    public function test_a_running_finalize_reports_its_phase_and_progress(): void {
        self::assertSame(
            [ 'state' => 'running', 'phase' => 'send', 'done' => 3, 'total' => 10 ],
            finalize_status_view($this->running(1950), 2000)
        );
    }

    public function test_a_finalize_silent_for_more_than_two_minutes_is_lost(): void {
        self::assertSame('running', finalize_status_view($this->running(1000), 1120)['state'], 'two minutes of silence are still alive');
        self::assertSame([ 'state' => 'lost' ], finalize_status_view($this->running(1000), 1121));
    }

    public function test_a_finished_finalize_reports_its_response_however_old(): void {
        $response = [ 'status' => 'ok', 'plugin_id' => 7, 'release_id' => 8 ];
        $status = [ ...$this->running(1000), 'phase' => 'save', 'response' => $response ];

        self::assertSame([ 'state' => 'finished', 'response' => $response ], finalize_status_view($status, 99999));
    }

    public function test_the_recorded_status_reads_back(): void {
        $root = $this->tmp_root();
        $status = $this->running(1950);

        record_finalize_status($root, $status);

        self::assertSame($status, read_finalize_status($root));
    }

    public function test_a_directory_without_a_status_reads_as_none(): void {
        self::assertNull(read_finalize_status($this->tmp_root()));
    }

    public function test_an_unreadable_status_reads_as_none(): void {
        $root = $this->tmp_root();
        file_put_contents($root . 'finalize.json', '{"started_at": 10');

        self::assertNull(read_finalize_status($root));
    }

    public function test_an_ok_response_is_final(): void {
        $root = $this->tmp_root();
        $done = [ ...$this->running(1950), 'response' => [ 'status' => 'ok', 'plugin_id' => 7 ] ];
        record_finalize_status($root, $done);

        // A second attempt that ran beside the first (the user published again after the
        // first went silent) must not hide that the first one succeeded.
        record_finalize_status($root, $this->running(2010, 'prepare', 0, 0));
        record_finalize_status($root, [ ...$this->running(2020), 'response' => [ 'status' => 'error', 'code' => 'deploy_in_progress' ] ]);

        self::assertSame($done, read_finalize_status($root));
    }

    public function test_an_error_response_gives_way_to_a_new_attempt(): void {
        $root = $this->tmp_root();
        record_finalize_status($root, [ ...$this->running(1950), 'response' => [ 'status' => 'error', 'code' => 'current_release_changed' ] ]);

        $retry = $this->running(2010, 'prepare', 0, 0);
        record_finalize_status($root, $retry);

        self::assertSame($retry, read_finalize_status($root));
    }
}
