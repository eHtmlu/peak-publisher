<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\carry_tested_up_to;
use function Pblsh\plan_tested_up_to_edit;
use function Pblsh\tested_up_to_line_changes;
use function Pblsh\effective_tested_up_to;
use function Pblsh\is_valid_tested_up_to;
use function Pblsh\read_plugin_headers;
use function Pblsh\sanitize_tested_up_to;
use function Pblsh\served_tested_up_to;
use function Pblsh\tested_up_to_upload_facts;
use function Pblsh\wordpress_stable_branch;
use function Pblsh\write_release_snapshot;

/**
 * The Tested up to vocabulary (includes/readme.php): the value as wordpress.org's parser
 * reads it, the effective value of a release (a well-formed plugin header value overrides the readme's), its state
 * against the stable branch WordPress knows, and the carry-over at upload.
 */
final class TestedUpToTest extends TestCase {

    #[DataProvider('raw_values')]
    public function test_sanitizing_follows_the_directory_parser(string $raw, string $expected): void {
        self::assertSame($expected, sanitize_tested_up_to($raw));
    }

    public static function raw_values(): array {
        return [
            'plain' => [ '7.1', '7.1' ],
            'patch' => [ '7.1.3', '7.1.3' ],
            'WordPress prefix' => [ 'WordPress 7.1', '7.1' ],
            'WP prefix and spaces' => [ ' WP 7.1 ', '7.1' ],
            'suffix stripped' => [ '7.2-RC1', '7.2' ],
            'not a version' => [ 'latest', '' ],
            'single number' => [ '7', '' ],
            'empty' => [ '', '' ],
        ];
    }

    public function test_the_header_wins_when_well_formed(): void {
        self::assertSame([ 'value' => '7.1', 'source' => 'header', 'header' => '7.1', 'readme' => '7.2' ], effective_tested_up_to([ 'TestedUpTo' => '7.1' ], [ 'tested' => '7.2' ]));
        self::assertSame([ 'value' => '7.2', 'source' => 'readme', 'header' => '', 'readme' => '7.2' ], effective_tested_up_to([ 'TestedUpTo' => '7' ], [ 'tested' => '7.2' ]), 'a malformed header is ignored, like the directory does');
        self::assertSame([ 'value' => '7.2', 'source' => 'readme', 'header' => '', 'readme' => '7.2' ], effective_tested_up_to([], [ 'tested' => '7.2' ]));
        self::assertSame([ 'value' => '', 'source' => null, 'header' => '', 'readme' => '' ], effective_tested_up_to(null, null));
    }

    public function test_the_accepted_format_is_major_minor(): void {
        self::assertTrue(is_valid_tested_up_to('7.2'));
        self::assertTrue(is_valid_tested_up_to('10.0'));
        self::assertFalse(is_valid_tested_up_to('7.2.1'));
        self::assertFalse(is_valid_tested_up_to('7'));
        self::assertFalse(is_valid_tested_up_to(''));
    }

    public function test_read_plugin_headers_adds_the_tested_header(): void {
        $file = tempnam(sys_get_temp_dir(), 'pblsh');
        file_put_contents($file, "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.0\n * Tested up to: 7.1\n */\n");
        $headers = read_plugin_headers($file);
        unlink($file);
        self::assertSame('My Plugin', $headers['Name']);
        self::assertSame('7.1', $headers['TestedUpTo']);
    }

    public function test_the_stable_branch_comes_from_cores_version_check(): void {
        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'latest', 'current' => '7.1.3' ] ] ]);
        self::assertSame('7.1', wordpress_stable_branch());
        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'upgrade', 'current' => '9.9' ] ] ]);
        self::assertSame('9.9', wordpress_stable_branch(), 'the dev fake: a bare major.minor');
        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'latest', 'current' => '6.9.10' ] ] ]);
        self::assertSame('7.1', wordpress_stable_branch(), 'the running version is the floor');
        set_site_transient('update_core', false);
        self::assertSame('7.1', wordpress_stable_branch(), 'without a transient the site version decides');
        set_site_transient('update_core', 'garbage');
        self::assertSame('7.1', wordpress_stable_branch());
    }

    public function test_line_changes_set_existing_lines_and_create_only_in_the_readme(): void {
        $readme = "=== X ===\nTested up to: 7.0\n\nShort.\n";
        $bare_readme = "=== X ===\nStable tag: 1.0\n\nShort.\n";
        $main = "<?php\n/*\n * Plugin Name: X\n * Tested up to: 7.0\n */\n";
        $bare_main = "<?php\n/*\n * Plugin Name: X\n */\n";
        self::assertSame([ 'readme', 'main' ], array_keys(tested_up_to_line_changes($readme, $main, '7.2', false)), 'both lines set');
        self::assertSame([], tested_up_to_line_changes($bare_readme, $bare_main, '7.2', false), 'no line, nothing created');
        self::assertSame([ 'readme' ], array_keys(tested_up_to_line_changes($bare_readme, $bare_main, '7.2', true)), 'created in the readme only');
        self::assertStringContainsString('Tested up to: 7.2', tested_up_to_line_changes($bare_readme, null, '7.2', true)['readme']);
        self::assertSame([], tested_up_to_line_changes(null, $bare_main, '7.2', true), 'a plugin file line is never planted');
        self::assertSame([ 'main' ], array_keys(tested_up_to_line_changes($bare_readme, $main, '7.2', true)), 'a readme without the line stays so when the header carries the value');
    }

    public function test_the_edit_plan_refuses_a_stale_or_same_value_and_a_release_without_a_place(): void {
        $readme = "=== X ===\nTested up to: 7.0\n\nShort.\n";
        $effective = effective_tested_up_to([], [ 'tested' => '7.0' ]);
        self::assertSame('tested_up_to_changed', plan_tested_up_to_edit($readme, null, $effective, '7.2', '6.9')->get_error_code());
        self::assertSame('invalid_tested_up_to_target', plan_tested_up_to_edit($readme, null, $effective, '7.0', '7.0')->get_error_code());
        self::assertSame('tested_up_to_nowhere', plan_tested_up_to_edit(null, "<?php\n/* Plugin Name: X */\n", effective_tested_up_to([], null), '7.2', '')->get_error_code());
        self::assertSame([ 'readme' ], array_keys(plan_tested_up_to_edit($readme, null, $effective, '7.2', '7.0')));
    }

    public function test_carry_raises_every_line_to_the_highest_value(): void {
        $upload = static fn(string $header, string $readme): array => effective_tested_up_to([ 'TestedUpTo' => $header ], [ 'tested' => $readme ]);
        self::assertSame([ 'value' => '7.2', 'from' => 'predecessor', 'normalized' => false ], carry_tested_up_to($upload('', '7.1'), '7.2'));
        self::assertSame([ 'value' => '7.1', 'from' => null, 'normalized' => false ], carry_tested_up_to($upload('7.0', '7.1'), null), 'the readme says more than the header: the upload\'s own value');
        self::assertSame([ 'value' => '7.1', 'from' => null, 'normalized' => false ], carry_tested_up_to($upload('7.1', '7.0'), '7.1'), 'a predecessor saying the same adds nothing');
        self::assertSame([ 'value' => '7.2', 'from' => 'predecessor', 'normalized' => false ], carry_tested_up_to($upload('7.0', '7.1'), '7.2'), 'the predecessor replaces the upload\'s own highest');
        self::assertNull(carry_tested_up_to($upload('7.2', '7.2'), '7.2'), 'every line says it');
        self::assertNull(carry_tested_up_to($upload('', '7.3'), '7.2'), 'never lowered');
        self::assertNull(carry_tested_up_to($upload('', ''), '7.2'), 'a missing value is its own signal');
        self::assertNull(carry_tested_up_to($upload('', '7.1'), ''), 'nothing to carry');
        self::assertNull(carry_tested_up_to($upload('', '7.1'), null), 'no predecessor');
    }

    public function test_carry_trims_the_value_to_major_minor(): void {
        $upload = static fn(string $header, string $readme): array => effective_tested_up_to([ 'TestedUpTo' => $header ], [ 'tested' => $readme ]);
        self::assertSame([ 'value' => '7.1', 'from' => null, 'normalized' => true ], carry_tested_up_to($upload('7.1.3', '7.1'), null), 'only the form changes');
        self::assertSame([ 'value' => '7.1', 'from' => null, 'normalized' => true ], carry_tested_up_to($upload('7.1.3', '7.1.3'), '7.1'));
        self::assertSame([ 'value' => '7.1', 'from' => null, 'normalized' => true ], carry_tested_up_to($upload('7.0', '7.1.2'), null));
        self::assertSame([ 'value' => '7.1', 'from' => 'predecessor', 'normalized' => true ], carry_tested_up_to($upload('7.0', ''), '7.1.3'), 'an older release may still carry a patch digit');
        self::assertNull(carry_tested_up_to($upload('', '7.1'), '7.1.3'), 'a predecessor with a patch digit says the same');
    }

    public function test_the_served_value_is_the_highest_known_release_of_its_branch(): void {
        set_site_transient('update_core', (object) [ 'updates' => [ (object) [ 'response' => 'upgrade', 'current' => '7.1.3' ], (object) [ 'response' => 'autoupdate', 'current' => '7.0.7' ] ] ]);
        self::assertSame('7.1.3', served_tested_up_to('7.1', '7.1.2'), 'the release core reports beats the site');
        self::assertSame('7.1.4', served_tested_up_to('7.1', '7.1.4'), 'a site ahead of the known releases');
        self::assertSame('7.1.3', served_tested_up_to('7.1', '7.0.7'), 'a site in another branch: the branch decides');
        self::assertSame('7.1.3', served_tested_up_to('7.1', '7.2.1'));
        self::assertSame('7.0.7', served_tested_up_to('7.0', '6.9.3'), 'the running branch\'s offer counts too');
        self::assertSame('6.9.3', served_tested_up_to('6.9', '6.9.3'), 'a branch core reports nothing about: the site');
        self::assertSame('6.8', served_tested_up_to('6.8', '7.1.2'), 'nothing known of the branch: the value as it is');
        self::assertSame('7.1.5', served_tested_up_to('7.1.5', '7.1.2'), 'never below the value itself');
        self::assertSame('', served_tested_up_to('', '7.1.2'));
    }

    private function release_info(\WP_Post $release): array {
        return [ 'id' => $release->ID, 'version' => $release->post_title, 'normalized_version' => $release->post_title, 'plugin_basename' => '' ];
    }

    private function snapshot_with(string $version, string $readme_tested, string $header_tested = ''): array {
        return [
            'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => $version, 'TestedUpTo' => $header_tested ],
            'plugin_info' => [ 'normalized_version' => $version ],
            'plugin_readme_txt' => [ 'found' => true, 'file_name' => 'readme.txt', 'content' => [ 'tested' => $readme_tested ] ],
        ];
    }

    public function test_upload_facts_name_the_predecessor_and_the_carry(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $previous = $this->create_release($plugin, '1.4');
        write_release_snapshot($previous, $this->snapshot_with('1.4', '7.2'));
        $related = [ 'existing' => false, 'previous' => $this->release_info($previous), 'next' => false, 'latest' => $this->release_info($previous) ];

        $facts = tested_up_to_upload_facts([ 'TestedUpTo' => '' ], [ 'tested' => '7.1' ], $related, null);

        self::assertSame('7.1', $facts['upload']['value']);
        self::assertSame([ 'version' => '1.4', 'value' => '7.2' ], $facts['predecessor']);
        self::assertSame([ 'value' => '7.2', 'from' => 'predecessor', 'normalized' => false ], $facts['carry']);
        self::assertNull($facts['current']);
    }

    public function test_upload_facts_without_releases(): void {
        $facts = tested_up_to_upload_facts([ 'TestedUpTo' => '7.1' ], null, false, null);
        self::assertSame([ 'value' => '7.1', 'source' => 'header', 'header' => '7.1', 'readme' => '' ], $facts['upload']);
        self::assertNull($facts['predecessor']);
        self::assertNull($facts['current']);
        self::assertNull($facts['carry']);
    }

    public function test_carry_prefers_existing_over_previous(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $existing = $this->create_release($plugin, '1.5');
        write_release_snapshot($existing, $this->snapshot_with('1.5', '7.3'));
        $previous = $this->create_release($plugin, '1.4');
        write_release_snapshot($previous, $this->snapshot_with('1.4', '7.2'));
        $related = [ 'existing' => $this->release_info(get_post($existing->ID)), 'previous' => $this->release_info(get_post($previous->ID)), 'next' => false, 'latest' => $this->release_info(get_post($existing->ID)) ];

        $facts = tested_up_to_upload_facts([], [ 'tested' => '7.1' ], $related, null);

        self::assertSame([ 'version' => '1.5', 'value' => '7.3' ], $facts['predecessor']);
        self::assertSame([ 'value' => '7.3', 'from' => 'predecessor', 'normalized' => false ], $facts['carry']);
    }

    public function test_the_current_release_is_named_with_its_effective_value(): void {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $current = $this->create_release($plugin, '1.4');
        write_release_snapshot($current, $this->snapshot_with('1.4', '7.1', '7.2'));

        $facts = tested_up_to_upload_facts([], [ 'tested' => '7.0' ], false, get_post($current->ID));

        self::assertSame([ 'version' => '1.4', 'value' => '7.2' ], $facts['current'], 'the effective value there too: the header value overrides the readme\'s');
        self::assertNull($facts['carry'], 'the current release is never the carry source');
    }
}
