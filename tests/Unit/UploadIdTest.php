<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\is_upload_id;

/**
 * The upload ID (includes/uploads.php) — the name of an upload's working directory under tmp/,
 * so a request's ID is accepted only in the one format the upload creates.
 */
final class UploadIdTest extends TestCase {

    public function test_an_id_as_the_upload_creates_it_is_accepted(): void {
        self::assertTrue(is_upload_id('20261015-120000_abcDEF12'));
    }

    public function test_anything_that_could_leave_the_tmp_directory_is_rejected(): void {
        self::assertFalse(is_upload_id('../../wp-content/x'));
        self::assertFalse(is_upload_id('20261015-120000_abcdefgh/../..'));
        self::assertFalse(is_upload_id('20261015-120000_abcd/fgh'));
    }

    public function test_other_shapes_are_rejected(): void {
        self::assertFalse(is_upload_id(''));
        self::assertFalse(is_upload_id('20261015-120000_abcdefg'), 'seven random characters');
        self::assertFalse(is_upload_id('20261015-120000_abcdefgh_user-1'), 'the directory name, not the ID');
        self::assertFalse(is_upload_id("20261015-120000_abcdefgh\n"), 'a trailing line break');
    }
}
