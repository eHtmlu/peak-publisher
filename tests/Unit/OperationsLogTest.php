<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\operation_revisions;
use function Pblsh\record_operation;

use const Pblsh\PBLSH_OPERATIONS_META;

/**
 * The operations log of a plugin, both channels (includes/operations_log.php): every write
 * Peak Publisher made to the published state, newest first, with the WordPress actor the
 * public SVN log lacks; wporg entries with account and revision, self-hosted ones without.
 */
final class OperationsLogTest extends TestCase {

    public function test_entries_are_prepended_with_actor_account_and_revision(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');

        record_operation($marker->ID, 'deploy', 'wporguser', 3670300, [ 'version' => '1.2.3' ]);
        record_operation($marker->ID, 'stable_tag', 'wporguser', 3670301, [ 'from' => '1.2.2', 'to' => '1.2.3' ]);

        $log = get_post_meta($marker->ID, PBLSH_OPERATIONS_META, true);
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
        update_post_meta($marker->ID, PBLSH_OPERATIONS_META, 'garbage');

        record_operation($marker->ID, 'delete_tag', 'wporguser', 5, [ 'version' => '1.0.0' ]);

        self::assertCount(1, get_post_meta($marker->ID, PBLSH_OPERATIONS_META, true));
    }

    public function test_the_revisions_of_one_operation_are_read_newest_first(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        record_operation($marker->ID, 'stable_tag', 'wporguser', 5, []);
        record_operation($marker->ID, 'deploy', 'wporguser', 6, []);
        record_operation($marker->ID, 'stable_tag', 'wporguser', 7, []);

        self::assertSame([ 7, 5 ], operation_revisions($marker->ID, 'stable_tag'));
        self::assertSame([], operation_revisions($marker->ID, 'assets'));
        self::assertSame([], operation_revisions($this->create_plugin('pblsh_wporg_plugin', 'other')->ID, 'stable_tag'));
    }

    public function test_a_self_hosted_plugin_keeps_its_own_log_without_account_or_revision(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');

        record_operation($plugin->ID, 'tested_up_to', null, null, [ 'version' => '1.4', 'from' => '7.1', 'to' => '7.2' ]);

        $log = get_post_meta($plugin->ID, PBLSH_OPERATIONS_META, true);
        self::assertCount(1, $log);
        self::assertSame('tested_up_to', $log[0]['operation']);
        self::assertNull($log[0]['username']);
        self::assertNull($log[0]['revision']);
        self::assertSame([], operation_revisions($plugin->ID, 'tested_up_to'), 'no revision, nothing for the forecast');
    }
}
