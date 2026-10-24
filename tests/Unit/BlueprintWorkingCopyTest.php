<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\apply_blueprint_change;
use function Pblsh\asset_conflicts;
use function Pblsh\blueprint_mirror_base;
use function Pblsh\blueprints_mirror_after_commit;
use function Pblsh\effective_blueprint_contents;
use function Pblsh\get_wporg_assets_state;
use function Pblsh\update_wporg_assets_state;
use function Pblsh\wporg_assets_state_defaults;
use function Pblsh\wporg_mirror_slots;

/**
 * The Live Preview in the working copy (includes/wporg_assets.php): blueprint.json is one more
 * entry beside the images — the same base, the same conflict rule, the same commit — with its
 * contents in the entry instead of a file.
 */
final class BlueprintWorkingCopyTest extends TestCase {

    private const ACTOR = [ 'at' => 100, 'user' => [ 'id' => 1, 'login' => 'admin' ] ];

    private static function state(?string $contents, int $revision = 40): array {
        $state = wporg_assets_state_defaults();
        $state['revision'] = 50;
        if ($contents !== null) {
            $state['blueprints'] = [ 'revision' => $revision, 'blueprint_files' => 1, 'other_files' => 0, 'file' => [ 'revision' => $revision, 'filesize' => strlen($contents), 'contents' => $contents ] ];
        }
        return $state;
    }

    public function test_a_put_builds_on_the_mirror_and_the_contents_are_the_effective_ones(): void {
        $state = self::state('{"a":1}');
        $pending = apply_blueprint_change([], $state, [ 'action' => 'put', 'contents' => '{"a":2}', ...self::ACTOR ]);

        self::assertSame([ 'action' => 'put', 'contents' => '{"a":2}', 'base' => [ 'filename' => 'blueprint.json', 'revision' => 40 ], ...self::ACTOR ], $pending['blueprint']);
        self::assertSame('{"a":2}', effective_blueprint_contents([ ...$state, 'pending' => $pending ]));
        self::assertSame('{"a":1}', effective_blueprint_contents($state));

        $new = apply_blueprint_change([], self::state(null), [ 'action' => 'put', 'contents' => '{}', ...self::ACTOR ]);
        self::assertNull($new['blueprint']['base'], 'nothing on wordpress.org yet');
    }

    public function test_contents_equal_to_the_mirror_leave_no_entry(): void {
        $state = self::state('{"a":1}');
        $pending = apply_blueprint_change([], $state, [ 'action' => 'put', 'contents' => '{"a":2}', ...self::ACTOR ]);
        self::assertSame([], apply_blueprint_change($pending, $state, [ 'action' => 'put', 'contents' => '{"a":1}', ...self::ACTOR ]));
    }

    public function test_an_entry_keeps_its_base_so_a_conflict_stays_until_it_is_decided(): void {
        $pending = apply_blueprint_change([], self::state('{"a":1}', 40), [ 'action' => 'put', 'contents' => '{"a":2}', ...self::ACTOR ]);
        $moved = self::state('{"a":9}', 45);   // a pull brought a commit from elsewhere

        $again = apply_blueprint_change($pending, $moved, [ 'action' => 'put', 'contents' => '{"a":3}', ...self::ACTOR ]);
        self::assertSame([ 'filename' => 'blueprint.json', 'revision' => 40 ], $again['blueprint']['base']);
        self::assertSame([ 'blueprint' ], asset_conflicts($again, wporg_mirror_slots([], $moved)));
        self::assertSame([], asset_conflicts($pending, wporg_mirror_slots([], self::state('{"a":1}', 40))));
    }

    public function test_a_delete_needs_a_file_on_wordpress_org(): void {
        $deleted = apply_blueprint_change([], self::state('{}'), [ 'action' => 'delete', ...self::ACTOR ]);
        self::assertSame([ 'action' => 'delete', 'base' => [ 'filename' => 'blueprint.json', 'revision' => 40 ], ...self::ACTOR ], $deleted['blueprint']);
        self::assertNull(effective_blueprint_contents([ ...self::state('{}'), 'pending' => $deleted ]));

        $uploaded = apply_blueprint_change([], self::state(null), [ 'action' => 'put', 'contents' => '{}', ...self::ACTOR ]);
        self::assertSame([], apply_blueprint_change($uploaded, self::state(null), [ 'action' => 'delete', ...self::ACTOR ]), 'a never committed blueprint just vanishes');
    }

    public function test_the_mirror_slots_add_the_blueprint_to_the_manifest(): void {
        $manifest = [ 'icon_128' => [ 'filename' => 'icon-128x128.png', 'revision' => 3 ] ];
        self::assertSame([ ...$manifest, 'blueprint' => [ 'filename' => 'blueprint.json', 'revision' => 40 ] ], wporg_mirror_slots($manifest, self::state('{}')));
        self::assertSame($manifest, wporg_mirror_slots($manifest, self::state(null)));
        self::assertNull(blueprint_mirror_base(self::state(null)));
    }

    public function test_a_blueprint_keeps_its_backslashes_through_the_stored_state(): void {
        $marker = $this->create_plugin('pblsh_wporg_plugin', 'my-plugin');
        $contents = '{"landingPage":"\/wp-admin\/","steps":[{"step":"runPHP","code":"<?php echo \"a\\\\b\";"}]}';
        $state = self::state($contents);
        $state['pending'] = apply_blueprint_change([], $state, [ 'action' => 'put', 'contents' => $contents . "\n", ...self::ACTOR ]);

        update_wporg_assets_state($marker->ID, $state);

        $read = get_wporg_assets_state($marker->ID);
        self::assertSame($contents, $read['blueprints']['file']['contents'], 'WordPress unslashes meta on the way in');
        self::assertSame($contents . "\n", $read['pending']['blueprint']['contents']);
    }

    public function test_the_mirror_follows_the_commit_without_a_read(): void {
        $none = self::state(null)['blueprints'];
        self::assertSame([ 'revision' => 61, 'blueprint_files' => 1, 'other_files' => 0, 'file' => [ 'revision' => 61, 'filesize' => 2, 'contents' => '{}' ] ],
            blueprints_mirror_after_commit($none, [ 'action' => 'put', 'contents' => '{}' ], 61));

        $one = self::state('{"a":1}')['blueprints'];
        self::assertSame(1, blueprints_mirror_after_commit($one, [ 'action' => 'put', 'contents' => '{}' ], 61)['blueprint_files'], 'replaced, not added');
        self::assertSame([ 'revision' => 61, 'blueprint_files' => 0, 'other_files' => 0, 'file' => null ], blueprints_mirror_after_commit($one, [ 'action' => 'delete' ], 61));
        self::assertSame($one, blueprints_mirror_after_commit($one, null, 61), 'no entry, nothing changed there');
    }
}
