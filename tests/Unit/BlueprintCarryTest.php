<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\apply_blueprint_carry;
use function Pblsh\asset_conflicts;
use function Pblsh\blueprint_dependency_carry;
use function Pblsh\blueprint_missing_dependencies;
use function Pblsh\live_preview_missing;
use function Pblsh\wporg_assets_state_defaults;
use function Pblsh\wporg_mirror_slots;

/**
 * The Live Preview follows the current release (includes/wporg_blueprint.php,
 * includes/wporg_assets.php): a deploy or flip that makes a release current adds the plugins
 * it requires to the blueprint on wordpress.org in the same commit, and the working copy keeps
 * its own change on top of that.
 */
final class BlueprintCarryTest extends TestCase {

    private const ACTOR = [ 'at' => 100, 'user' => [ 'id' => 1, 'login' => 'admin' ] ];

    private static function blueprint(string ...$plugins): string {
        $steps = array_map(static fn(string $slug): array => [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => $slug ], 'options' => [ 'activate' => true ] ], [ ...$plugins, 'my-plugin' ]);
        return (string) json_encode([ 'landingPage' => '/', 'steps' => $steps ]);
    }

    private static function state(?string $mirror, ?array $entry = null): array {
        $state = wporg_assets_state_defaults();
        $state['revision'] = 50;
        if ($mirror !== null) {
            $state['blueprints'] = [ 'revision' => 40, 'blueprint_files' => 1, 'other_files' => 0, 'file' => [ 'revision' => 40, 'filesize' => strlen($mirror), 'contents' => $mirror ] ];
        }
        if ($entry !== null) {
            $state['pending']['blueprint'] = [ ...$entry, 'base' => [ 'filename' => 'blueprint.json', 'revision' => 40 ], ...self::ACTOR ];
        }
        return $state;
    }

    public function test_the_carry_adds_what_is_missing_and_nothing_else(): void {
        self::assertNull(blueprint_dependency_carry(self::blueprint('woocommerce'), 'my-plugin', [ 'woocommerce' ]), 'nothing missing, nothing to write');
        self::assertNull(blueprint_dependency_carry('{"steps":"x"}', 'my-plugin', [ 'woocommerce' ]), 'a blueprint wordpress.org ignores is left alone');

        $carry = blueprint_dependency_carry(self::blueprint('query-monitor'), 'my-plugin', [ 'buddypress', 'woocommerce' ]);
        self::assertSame([ 'buddypress', 'woocommerce' ], $carry['added']);
        self::assertSame([], blueprint_missing_dependencies($carry['contents'], 'my-plugin', [ 'buddypress', 'woocommerce', 'query-monitor' ]));
        self::assertStringEndsWith("}\n", $carry['contents'], 'written as Peak Publisher writes a blueprint');
    }

    public function test_after_the_carry_the_mirror_is_the_committed_file_and_an_open_put_follows_without_a_conflict(): void {
        $carry = blueprint_dependency_carry(self::blueprint(), 'my-plugin', [ 'woocommerce' ]);
        $mine = (string) json_encode([ 'landingPage' => '/wp-admin/', 'steps' => [ [ 'step' => 'login' ] ] ]);

        $state = apply_blueprint_carry(self::state(self::blueprint(), [ 'action' => 'put', 'contents' => $mine ]), 'my-plugin', $carry, 61);

        self::assertSame([ 'revision' => 61, 'filesize' => strlen($carry['contents']), 'contents' => $carry['contents'] ], $state['blueprints']['file']);
        self::assertSame(61, $state['blueprints']['revision']);
        self::assertSame([ 'filename' => 'blueprint.json', 'revision' => 61 ], $state['pending']['blueprint']['base'], 'rebased: the own change stays the own change');
        self::assertSame([], asset_conflicts($state['pending'], wporg_mirror_slots([], $state)));
        self::assertSame([], blueprint_missing_dependencies($state['pending']['blueprint']['contents'], 'my-plugin', [ 'woocommerce' ]), 'the open put got the same dependency');

        $twice = apply_blueprint_carry($state, 'my-plugin', $carry, 62);
        self::assertSame($state['pending']['blueprint']['contents'], $twice['pending']['blueprint']['contents'], 'added once');
    }

    public function test_an_open_delete_stays_a_delete_on_the_new_file_and_an_equal_put_goes(): void {
        $carry = blueprint_dependency_carry(self::blueprint(), 'my-plugin', [ 'woocommerce' ]);

        $deleted = apply_blueprint_carry(self::state(self::blueprint(), [ 'action' => 'delete' ]), 'my-plugin', $carry, 61);
        self::assertSame('delete', $deleted['pending']['blueprint']['action']);
        self::assertSame([ 'filename' => 'blueprint.json', 'revision' => 61 ], $deleted['pending']['blueprint']['base']);

        $equal = apply_blueprint_carry(self::state(self::blueprint(), [ 'action' => 'put', 'contents' => $carry['contents'] ]), 'my-plugin', $carry, 61);
        self::assertArrayNotHasKey('blueprint', $equal['pending'], 'the open change is what wordpress.org has now');
    }

    public function test_a_conflict_that_was_there_before_the_carry_stays_one(): void {
        $teammate = self::blueprint('query-monitor');   // committed elsewhere: the mirror moved to 45
        $state = self::state($teammate, [ 'action' => 'put', 'contents' => self::blueprint() ]);
        $state['blueprints']['revision'] = 45;
        $state['blueprints']['file']['revision'] = 45;
        self::assertSame([ 'blueprint' ], asset_conflicts($state['pending'], wporg_mirror_slots([], $state)), 'the entry built on 40');

        $after = apply_blueprint_carry($state, 'my-plugin', blueprint_dependency_carry($teammate, 'my-plugin', [ 'woocommerce' ]), 61);
        self::assertSame([ 'blueprint' ], asset_conflicts($after['pending'], wporg_mirror_slots([], $after)), 'decided by the user, not by the carry');
    }

    public function test_a_carry_that_read_a_change_the_mirror_never_saw_keeps_the_conflict_and_relists(): void {
        $state = self::state(self::blueprint(), [ 'action' => 'put', 'contents' => self::blueprint('buddypress') ]);
        $unseen = self::blueprint('query-monitor');   // committed elsewhere, not pulled yet

        $after = apply_blueprint_carry($state, 'my-plugin', blueprint_dependency_carry($unseen, 'my-plugin', [ 'woocommerce' ]), 61);

        self::assertSame([ 'blueprint' ], asset_conflicts($after['pending'], wporg_mirror_slots([], $after)), 'the user never saw that change');
        self::assertNull($after['blueprints']['revision'], 'the next pull lists assets/blueprints/ again');
        self::assertSame(61, $after['blueprints']['file']['revision'], 'the file is what the commit left');
    }

    public function test_what_is_missing_is_read_from_the_mirror(): void {
        self::assertSame([ 'woocommerce' ], live_preview_missing(self::state(self::blueprint()), 'my-plugin', [ 'woocommerce' ]));
        self::assertSame([ 'woocommerce' ], live_preview_missing(self::state(self::blueprint(), [ 'action' => 'put', 'contents' => self::blueprint('woocommerce') ]), 'my-plugin', [ 'woocommerce' ]), 'what visitors get is the committed file');
        self::assertSame([], live_preview_missing(self::state(null), 'my-plugin', [ 'woocommerce' ]), 'no blueprint, no preview to fail');
    }
}
