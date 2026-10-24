<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\wporg_commit_message;
use function Pblsh\wporg_commit_operations;
use function Pblsh\wporg_deploy_commit_message;
use function Pblsh\wporg_deploy_kind;
use function Pblsh\wporg_reconcile_plan;
use function Pblsh\wporg_stable_tag_commit_message;
use function Pblsh\wporg_tag_copy_candidates;

/**
 * The decisions of a wordpress.org deploy without I/O (includes/wporg_deploy.php): which tree a
 * new tag is copied from, what a base needs against the tree it is compared with, the order
 * the commit runs its operations in, and the messages the commits carry.
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

    // ---- The commit's message

    public function test_every_message_ends_with_the_attribution_and_keeps_its_notes_in_brackets(): void {
        self::assertSame('Delete version 1.2.0 of my-plugin via Peak Publisher', wporg_commit_message('Delete version 1.2.0 of my-plugin'));
        self::assertSame('Publish version 1.2.0 of my-plugin (stable tag set to 1.2.0, Live Preview adjusted) via Peak Publisher',
            wporg_commit_message('Publish version 1.2.0 of my-plugin', [ 'stable tag set to 1.2.0', null, '', 'Live Preview adjusted' ]), 'empty notes vanish');
    }

    public function test_what_a_deploy_means_follows_what_changes_in_the_tag(): void {
        $plan = static fn(array $puts = [], array $deletes = []): array => [ 'deletes' => $deletes, 'mkdirs' => [], 'copies' => [], 'puts' => array_map(static fn(string $path): array => [ 'path' => $path, 'local_path' => '/work/' . basename($path) ], $puts) ];
        $none = $plan();
        $readme = $plan([ 'tags/1.2.0/readme.txt' ]);
        $files = $plan([ 'tags/1.2.0/readme.txt', 'tags/1.2.0/my-plugin.php' ]);

        self::assertSame('publish', wporg_deploy_kind(false, $files, null, true, false), 'a new tag, whatever else changes');
        self::assertSame('replace', wporg_deploy_kind(true, $files, $none, false, false));
        self::assertSame('replace', wporg_deploy_kind(true, $plan([], [ 'tags/1.2.0/old.php' ]), null, false, false), 'a delete alone replaces too');
        self::assertSame('readme', wporg_deploy_kind(true, $readme, $plan([ 'trunk/readme.txt', 'trunk/my-plugin.php' ]), false, true), 'the tag decides; trunk and the Live Preview follow in the diff');
        self::assertSame('readme', wporg_deploy_kind(true, $plan([ 'tags/1.2.0/README.md' ]), null, false, false), 'wordpress.org\'s readme names');
        self::assertSame('trunk', wporg_deploy_kind(true, $none, $plan([ 'trunk/readme.txt', 'trunk/my-plugin.php' ]), true, false), 'the tag is unchanged, trunk gets the version');
        self::assertSame('trunk', wporg_deploy_kind(true, $none, $plan([ 'trunk/readme.txt' ]), false, false), 'trunk\'s readme differs beyond the pointer');
        self::assertSame('stable_tag', wporg_deploy_kind(true, $none, $plan([ 'trunk/readme.txt' ]), true, false), 'nothing but the pointer: the commit is a flip');
        self::assertSame('stable_tag', wporg_deploy_kind(true, $none, [ ...$plan([ 'trunk/readme.txt' ]), 'mkdirs' => [ 'trunk' ] ], true, false), 'a trunk created for the pointer alone');
        self::assertSame('live_preview', wporg_deploy_kind(true, $none, $none, false, true), 'nothing but the carry');
        self::assertNull(wporg_deploy_kind(true, $none, $none, false, false), 'nothing changes: no commit');
        self::assertNull(wporg_deploy_kind(true, $none, null, false, false));
    }

    public function test_the_deploy_message_names_the_deed_and_whether_the_release_went_live(): void {
        $set = [ 'pointer_changed' => true, 'pointer_value' => '1.2.0' ];
        $stays = [ 'pointer_changed' => false, 'pointer_value' => '1.1.0' ];
        $same = [ 'pointer_changed' => false, 'pointer_value' => '1.2.0' ];

        self::assertSame('Publish version 1.2.0 of my-plugin (stable tag set to 1.2.0) via Peak Publisher', wporg_deploy_commit_message('publish', 'my-plugin', '1.2.0', $set, '1.1.0', false));
        self::assertSame('Publish version 1.2.0 of my-plugin (stable tag stays 1.1.0) via Peak Publisher', wporg_deploy_commit_message('publish', 'my-plugin', '1.2.0', $stays, '1.1.0', false));
        self::assertSame('Publish version 1.2.0 of my-plugin (stable tag unchanged) via Peak Publisher', wporg_deploy_commit_message('publish', 'my-plugin', '1.2.0', $stays, null, false), 'trunk\'s readme could not be read');
        self::assertSame('Publish version 1.2.0 of my-plugin (stable tag unchanged) via Peak Publisher', wporg_deploy_commit_message('publish', 'my-plugin', '1.2.0', $stays, '', false), 'trunk has no pointer');
        self::assertSame('Replace version 1.2.0 of my-plugin (stable tag stays 1.2.0) via Peak Publisher', wporg_deploy_commit_message('replace', 'my-plugin', '1.2.0', $same, '1.2.0', false));
        self::assertSame('Update readme of version 1.2.0 of my-plugin (stable tag stays 1.2.0) via Peak Publisher', wporg_deploy_commit_message('readme', 'my-plugin', '1.2.0', $same, '1.2.0', false));
        self::assertSame('Update trunk to version 1.2.0 of my-plugin (stable tag set to 1.2.0, Live Preview adjusted) via Peak Publisher', wporg_deploy_commit_message('trunk', 'my-plugin', '1.2.0', $set, '1.1.0', true));
        self::assertSame('Set stable tag to version 1.2.0 of my-plugin (was 1.1.0) via Peak Publisher', wporg_deploy_commit_message('stable_tag', 'my-plugin', '1.2.0', $set, '1.1.0', false), 'a flip reads as one');
        self::assertSame('Adjust Live Preview of my-plugin via Peak Publisher', wporg_deploy_commit_message('live_preview', 'my-plugin', '1.2.0', $same, '1.2.0', true));
        self::assertSame('', wporg_deploy_commit_message(null, 'my-plugin', '1.2.0', $same, '1.2.0', false), 'no commit, no message');
    }

    public function test_the_flip_message_names_the_pointer_before_and_the_live_preview_it_adjusted(): void {
        self::assertSame('Set stable tag to version 1.2.0 of my-plugin (was 1.1.0) via Peak Publisher', wporg_stable_tag_commit_message('my-plugin', '1.2.0', '1.1.0', false));
        self::assertSame('Set stable tag to version 1.2.0 of my-plugin (was 1.1.0, Live Preview adjusted) via Peak Publisher', wporg_stable_tag_commit_message('my-plugin', '1.2.0', '1.1.0', true));
        self::assertSame('Set stable tag to version 1.2.0 of my-plugin via Peak Publisher', wporg_stable_tag_commit_message('my-plugin', '1.2.0', '', false), 'trunk had no pointer');
    }
}
