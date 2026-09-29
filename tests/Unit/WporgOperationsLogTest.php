<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\record_wporg_operation;

use const Pblsh\PBLSH_WPORG_OPERATIONS_META;

/**
 * The operations log of a wordpress.org marker (includes/wporg_log.php): every SVN write
 * Peak Publisher committed, newest first, with the WordPress actor the public SVN log lacks.
 */
final class WporgOperationsLogTest extends TestCase {

    public function test_entries_are_prepended_with_actor_account_and_revision(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');

        record_wporg_operation($marker->ID, 'deploy', 'wporguser', 3670300, [ 'version' => '1.2.3' ]);
        record_wporg_operation($marker->ID, 'stable_tag', 'wporguser', 3670301, [ 'from' => '1.2.2', 'to' => '1.2.3' ]);

        $log = get_post_meta($marker->ID, PBLSH_WPORG_OPERATIONS_META, true);
        self::assertCount(2, $log);
        self::assertSame('stable_tag', $log[0]['operation'], 'newest first');
        self::assertSame(3670301, $log[0]['revision']);
        self::assertSame([ 'from' => '1.2.2', 'to' => '1.2.3' ], $log[0]['details']);
        self::assertSame([ 'id' => 1, 'login' => 'admin' ], $log[0]['user']);
        self::assertSame('wporguser', $log[0]['username']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $log[0]['at']);
        self::assertSame('deploy', $log[1]['operation']);
    }

    public function test_a_foreign_meta_shape_starts_a_new_log(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        update_post_meta($marker->ID, PBLSH_WPORG_OPERATIONS_META, 'garbage');

        record_wporg_operation($marker->ID, 'delete_tag', 'wporguser', 5, [ 'version' => '1.0.0' ]);

        self::assertCount(1, get_post_meta($marker->ID, PBLSH_WPORG_OPERATIONS_META, true));
    }
}
