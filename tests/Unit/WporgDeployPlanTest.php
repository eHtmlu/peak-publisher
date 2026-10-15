<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\wporg_commit_operations;
use function Pblsh\wporg_reconcile_plan;
use function Pblsh\wporg_tag_copy_candidates;

/**
 * The decisions of a wordpress.org deploy without I/O (includes/wporg_deploy.php): which tree a
 * new tag is copied from, what a base needs against the tree it is compared with, and the
 * order the commit runs its operations in.
 */
final class WporgDeployPlanTest extends TestCase {

    private function local(string ...$entries): array {
        $tree = [];
        foreach ($entries as $entry) {
            [ $rel, $content ] = array_pad(explode('=', $entry, 2), 2, null);
            $tree[$rel] = $content === null
                ? [ 'type' => 'dir', 'path' => '/work/' . $rel, 'size' => null ]
                : [ 'type' => 'file', 'path' => '/work/' . $rel, 'size' => strlen($content), 'hash' => md5($content) ];
        }
        return $tree;
    }

    private function remote(string ...$entries): array {
        $tree = [];
        foreach ($entries as $entry) {
            [ $rel, $content ] = array_pad(explode('=', $entry, 2), 2, null);
            $tree[$rel] = $content === null
                ? [ 'type' => 'dir', 'path' => 'x/' . $rel, 'size' => null, 'md5' => null ]
                : [ 'type' => 'file', 'path' => 'x/' . $rel, 'size' => strlen($content), 'md5' => md5($content) ];
        }
        return $tree;
    }

    // ---- The copy source of a new tag

    public function test_a_new_tag_with_trunk_going_along_prefers_trunk_then_the_predecessor(): void {
        self::assertSame([ 'trunk', 'tags/1.3' ], wporg_tag_copy_candidates('1.4', [ '1.2', '1.3', '2.0' ], true));
    }

    public function test_a_tag_only_release_prefers_its_predecessor_then_trunk(): void {
        self::assertSame([ 'tags/1.3', 'trunk' ], wporg_tag_copy_candidates('1.4', [ '1.2', '1.3', '2.0' ], false));
    }

    public function test_the_predecessor_follows_the_version_line_not_the_spelling(): void {
        self::assertSame([ 'tags/1.10', 'trunk' ], wporg_tag_copy_candidates('1.11', [ '1.9', '1.10', '1.2' ], false));
        self::assertSame([ 'tags/2.0-beta2', 'trunk' ], wporg_tag_copy_candidates('2.0', [ '1.9', '2.0-beta1', '2.0-beta2' ], false), 'a pre-release is closer than the release before it');
    }

    public function test_without_a_tag_below_only_trunk_is_a_candidate(): void {
        self::assertSame([ 'trunk' ], wporg_tag_copy_candidates('0.9', [ '1.0', '1.1' ], false));
        self::assertSame([ 'trunk' ], wporg_tag_copy_candidates('1.0', [], true));
    }

    public function test_tag_names_that_are_no_publishable_version_are_never_a_source(): void {
        self::assertSame([ 'tags/1.2', 'trunk' ], wporg_tag_copy_candidates('1.4', [ '1.2', 'old', 'v1.3', '1.3b' ], false));
    }

    // ---- The reconcile plan of one base

    public function test_a_new_base_without_a_source_is_created_with_its_folders_and_files(): void {
        $plan = wporg_reconcile_plan('tags/1.4', $this->local('css', 'css/a.css=a', 'main.php=m'), []);

        self::assertSame([], $plan['deletes']);
        self::assertSame([ 'tags', 'tags/1.4', 'tags/1.4/css' ], $plan['mkdirs'], 'the base with the folders above it, which may be missing too');
        self::assertSame([], $plan['copies']);
        self::assertSame([ 'tags/1.4/css/a.css', 'tags/1.4/main.php' ], array_column($plan['puts'], 'path'));
        self::assertSame('/work/main.php', $plan['puts'][1]['local_path']);
    }

    public function test_an_existing_base_gets_only_what_differs_compared_by_md5(): void {
        $plan = wporg_reconcile_plan(
            'trunk',
            $this->local('css', 'css/a.css=a2', 'main.php=m', 'new.php=n'),
            $this->remote('css', 'css/a.css=a1', 'main.php=m', 'old', 'old/x.php=x', 'old/y.php=y'),
        );

        self::assertSame([ 'trunk/old' ], $plan['deletes'], 'a folder delete covers what is in it');
        self::assertSame([], $plan['mkdirs'], 'an existing base and its existing folders are no change');
        self::assertSame([ 'trunk/css/a.css', 'trunk/new.php' ], array_column($plan['puts'], 'path'), 'same size, other content: put; same content: nothing');
    }

    public function test_a_type_change_is_a_delete_and_a_create(): void {
        $plan = wporg_reconcile_plan('trunk', $this->local('lib', 'lib/a.php=a'), $this->remote('lib=a file'));

        self::assertSame([ 'trunk/lib' ], $plan['deletes']);
        self::assertSame([ 'trunk/lib' ], $plan['mkdirs']);
        self::assertSame([ 'trunk/lib/a.php' ], array_column($plan['puts'], 'path'));
    }

    public function test_a_remote_file_without_a_checksum_is_put(): void {
        $remote = $this->remote('main.php=m');
        $remote['main.php']['md5'] = null;

        self::assertSame([ 'trunk/main.php' ], array_column(wporg_reconcile_plan('trunk', $this->local('main.php=m'), $remote)['puts'], 'path'));
    }

    public function test_a_new_tag_copied_from_a_source_gets_the_copy_and_what_differs_from_the_source(): void {
        $plan = wporg_reconcile_plan(
            'tags/1.4',
            $this->local('css', 'css/a.css=a', 'js', 'main.php=m2', 'readme.txt=r14'),
            $this->remote('css', 'css/a.css=a', 'main.php=m1', 'readme.txt=r13', 'gone.php=g'),
            [ 'from_path' => 'trunk', 'from_revision' => 100 ],
        );

        self::assertSame([ [ 'path' => 'tags/1.4', 'from_path' => 'trunk', 'from_revision' => 100 ] ], $plan['copies']);
        self::assertSame([ 'tags', 'tags/1.4/js' ], $plan['mkdirs'], 'the copy brings the base; the folders above it may be missing, new folders are created');
        self::assertSame([ 'tags/1.4/gone.php' ], $plan['deletes']);
        self::assertSame([ 'tags/1.4/main.php', 'tags/1.4/readme.txt' ], array_column($plan['puts'], 'path'));
    }

    // ---- The order of a commit's operations

    public function test_operations_run_deletes_deep_first_then_folders_shallow_first_then_files(): void {
        $operations = wporg_commit_operations([
            'deletes' => [ 'trunk/a', 'trunk/a/b/c.php' ],
            'mkdirs' => [ 'tags/1.4/x/y', 'tags', 'tags/1.4' ],
            'copies' => [],
            'puts' => [ [ 'path' => 'trunk/z.php', 'local_path' => '/z' ], [ 'path' => 'trunk/b.php', 'local_path' => '/b' ] ],
            'mime_types' => [ [ 'path' => 'assets/icon.svg', 'type' => 'image/svg+xml' ] ],
        ]);

        self::assertSame([
            [ 'op' => 'delete', 'path' => 'trunk/a/b/c.php' ],
            [ 'op' => 'delete', 'path' => 'trunk/a' ],
            [ 'op' => 'mkdir', 'path' => 'tags' ],
            [ 'op' => 'mkdir', 'path' => 'tags/1.4' ],
            [ 'op' => 'mkdir', 'path' => 'tags/1.4/x/y' ],
            [ 'op' => 'put', 'path' => 'trunk/b.php', 'local_path' => '/b' ],
            [ 'op' => 'put', 'path' => 'trunk/z.php', 'local_path' => '/z' ],
            [ 'op' => 'mime_type', 'path' => 'assets/icon.svg', 'type' => 'image/svg+xml' ],
        ], $operations);
    }

    public function test_what_lies_below_a_copy_runs_after_it(): void {
        $operations = wporg_commit_operations([
            'deletes' => [ 'tags/1.4/gone.php', 'trunk/gone.php' ],
            'mkdirs' => [ 'tags/1.4/js', 'tags' ],
            'copies' => [ [ 'path' => 'tags/1.4', 'from_path' => 'trunk', 'from_revision' => 100 ] ],
            'puts' => [ [ 'path' => 'tags/1.4/main.php', 'local_path' => '/m' ] ],
            'mime_types' => [],
        ]);

        self::assertSame([
            [ 'op' => 'delete', 'path' => 'trunk/gone.php' ],
            [ 'op' => 'mkdir', 'path' => 'tags' ],
            [ 'op' => 'copy', 'path' => 'tags/1.4', 'from_path' => 'trunk', 'from_revision' => 100 ],
            [ 'op' => 'delete', 'path' => 'tags/1.4/gone.php' ],
            [ 'op' => 'mkdir', 'path' => 'tags/1.4/js' ],
            [ 'op' => 'put', 'path' => 'tags/1.4/main.php', 'local_path' => '/m' ],
        ], $operations);
    }

    public function test_a_copy_onto_a_deleted_target_deletes_first(): void {
        // The assets swap: the target is deleted, then the other file copied onto its name.
        $operations = wporg_commit_operations([
            'deletes' => [ 'assets/icon-128x128.png' ],
            'mkdirs' => [],
            'copies' => [ [ 'path' => 'assets/icon-128x128.png', 'from_path' => 'assets/icon-256x256.png', 'from_revision' => 7 ] ],
            'puts' => [],
            'mime_types' => [],
        ]);

        self::assertSame([ 'delete', 'copy' ], array_column($operations, 'op'));
    }

    public function test_an_empty_plan_has_no_operations(): void {
        self::assertSame([], wporg_commit_operations([ 'deletes' => [], 'mkdirs' => [], 'copies' => [], 'puts' => [], 'mime_types' => [] ]));
    }
}
