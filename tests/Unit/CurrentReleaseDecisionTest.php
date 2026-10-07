<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\decide_current_release;
use function Pblsh\is_pre_release_version;

/**
 * The upload's default decision "does this release become the current release?" —
 * decide_current_release() in includes/functions.php, one relation per upload.
 */
final class CurrentReleaseDecisionTest extends TestCase {

    #[DataProvider('versions')]
    public function test_pre_release_is_the_publishable_suffix(string $version, bool $pre_release): void {
        self::assertSame($pre_release, is_pre_release_version($version));
    }

    public static function versions(): array {
        return [
            [ '1.2.0', false ],
            [ '1.2.0-beta1', true ],
            [ '2.0-RC.2', true ],
            [ '1.0.0-alpha', true ],
            [ '1.0.1a', false ],
        ];
    }

    #[DataProvider('decisions')]
    public function test_decision_tree(array $current, bool $has_releases, string $version, bool $is_wporg, ?string $deploy_mode, array $expected): void {
        $decision = decide_current_release(self::current($current), $has_releases, $version, $is_wporg, $deploy_mode);
        self::assertSame($expected, [ $decision['relation'], $decision['default_make_current'], $decision['choice'] ]);
        self::assertSame(is_pre_release_version($version), $decision['pre_release']);
    }

    public static function decisions(): array {
        $none = [ 'none', '' ];
        $trunk = [ 'trunk', 'trunk' ];
        $missing = [ 'tag_missing', '1.2.4' ];
        $unknown = [ 'unknown', null ];
        $current = [ 'current', '1.2.2' ];
        // [relation, default_make_current, choice]
        return [
            // 1: no releases yet — wporg forced, self-hosted a choice, a pre-release stays out by default
            'first wporg' => [ $none, false, '1.0.0', true, 'trunk_and_tag', [ 'first', true, false ] ],
            'first wporg pre-release still forced' => [ $unknown, false, '1.0.0-beta1', true, 'trunk_and_tag', [ 'first', true, false ] ],
            'first self-hosted' => [ $none, false, '1.0.0', false, null, [ 'first', true, true ] ],
            'first self-hosted pre-release' => [ $none, false, '1.0.0-beta1', false, null, [ 'first', false, true ] ],
            // 2: pointer unreadable (wporg)
            'unknown' => [ $unknown, true, '1.2.3', true, 'tag_only', [ 'unknown', true, true ] ],
            'unknown pre-release' => [ $unknown, true, '2.0.0-rc1', true, 'trunk_and_tag', [ 'unknown', false, true ] ],
            // 3: the pointer already names this version
            'repairs pointer' => [ $missing, true, '1.2.4', true, 'trunk_and_tag', [ 'repairs_pointer', true, false ] ],
            'repairs pointer compares normalized' => [ [ 'tag_missing', '1.2.4-beta1' ], true, '1.2.4-BETA.1', true, 'tag_only', [ 'repairs_pointer', true, false ] ],
            // 4: no usable pointer
            'no_current wporg trunk_and_tag forced' => [ $trunk, true, '1.2.3', true, 'trunk_and_tag', [ 'no_current', true, false ] ],
            'no_current wporg tag_only is a choice' => [ $trunk, true, '1.2.3', true, 'tag_only', [ 'no_current', true, true ] ],
            'no_current wporg tag_only pre-release' => [ $missing, true, '1.2.3-beta1', true, 'tag_only', [ 'no_current', false, true ] ],
            'no_current wporg none trunk_and_tag forced' => [ $none, true, '1.2.3', true, 'trunk_and_tag', [ 'no_current', true, false ] ],
            'no_current wporg none tag_only is a choice too: wordpress.org serves trunk, which stays' => [ $none, true, '1.2.3', true, 'tag_only', [ 'no_current', true, true ] ],
            'no_current self-hosted' => [ $none, true, '1.2.3', false, null, [ 'no_current', true, true ] ],
            'no_current self-hosted pre-release' => [ $none, true, '1.2.3-beta1', false, null, [ 'no_current', false, true ] ],
            // 5–7: relation to the current version
            'equal' => [ $current, true, '1.2.2', false, null, [ 'equal', true, false ] ],
            'equal compares normalized' => [ [ 'current', '1.2.2-beta1' ], true, '1.2.2-BETA.1', true, 'tag_only', [ 'equal', true, false ] ],
            'a fourth component counts as higher, as WordPress offers it' => [ $current, true, '1.2.2.0', true, 'tag_only', [ 'higher', true, true ] ],
            'higher' => [ $current, true, '1.2.3', false, null, [ 'higher', true, true ] ],
            'higher pre-release' => [ $current, true, '2.0.0-beta1', true, 'trunk_and_tag', [ 'higher', false, true ] ],
            'lower' => [ $current, true, '1.2.1', true, 'tag_only', [ 'lower', false, true ] ],
        ];
    }

    /**
     * A resolve_current_release() result from [state, pointer]; the release only matters
     * for 'current', where it names the pointer's version.
     */
    private static function current(array $state_and_pointer): array {
        [ $state, $pointer ] = $state_and_pointer;
        $release = $state === 'current' ? new \WP_Post([ 'post_type' => 'pblsh_release', 'post_title' => $pointer ]) : null;
        return [ 'state' => $state, 'pointer' => $pointer, 'release' => $release, 'latest' => $release, 'reference' => $release ];
    }
}
