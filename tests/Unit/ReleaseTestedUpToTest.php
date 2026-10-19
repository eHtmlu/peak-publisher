<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\content_hash_from_entries;
use function Pblsh\peak_publisher_upload_basedir;
use function Pblsh\read_release_zip_entry;
use function Pblsh\release_snapshot;
use function Pblsh\release_snapshot_from_zip;
use function Pblsh\release_zip_path;
use function Pblsh\set_release_tested_up_to;
use function Pblsh\zip_entries;

use const Pblsh\PBLSH_OPERATIONS_META;

/**
 * Setting Tested up to of a self-hosted release (includes/releases.php): both lines of the
 * ZIP, the snapshot, the content hash and the operations log follow; the expectation guard
 * and the input gates refuse before anything is written.
 */
final class ReleaseTestedUpToTest extends TestCase {

    private function self_hosted_release(string $readme_tested = '7.1', ?string $header_tested = '7.1', bool $with_readme = true): \WP_Post {
        $plugin = $this->create_plugin('pblsh_plugin', 'my-plugin');
        $zip = peak_publisher_upload_basedir() . '/plugins/my-plugin/releases/my-plugin.1.4.zip';
        mkdir(dirname($zip), 0777, true);
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addEmptyDir('my-plugin');
        $archive->addFromString('my-plugin/my-plugin.php', "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.4\n" . ($header_tested === null ? '' : " * Tested up to: $header_tested\n") . " */\n");
        if ($with_readme) {
            $archive->addFromString('my-plugin/readme.txt', "=== My Plugin ===\n" . ($readme_tested === '' ? '' : "Tested up to: $readme_tested\n") . "Stable tag: 1.4\n\nText.\n");
        }
        $archive->close();
        $id = wp_insert_post([ 'post_type' => 'pblsh_release', 'post_status' => 'publish', 'post_title' => '1.4', 'post_parent' => $plugin->ID, 'post_content' => json_encode(release_snapshot_from_zip($zip)) ]);
        update_post_meta($id, '_pblsh_zip_path', 'plugins/my-plugin/releases/my-plugin.1.4.zip');
        update_post_meta($id, '_pblsh_directory_content_hash', content_hash_from_entries(zip_entries($zip)));
        return get_post($id);
    }

    public function test_both_lines_are_set_and_snapshot_hash_and_log_follow(): void {
        $release = $this->self_hosted_release();
        $hash_before = get_post_meta($release->ID, '_pblsh_directory_content_hash', true);

        $result = set_release_tested_up_to($release, '7.2', '7.1');

        self::assertSame([ 'version' => '1.4', 'from' => '7.1', 'to' => '7.2', 'revision' => null ], $result);
        $zip = release_zip_path(get_post($release->ID));
        self::assertStringContainsString("Tested up to: 7.2\n", read_release_zip_entry($zip, 'my-plugin/readme.txt'));
        self::assertStringContainsString(" * Tested up to: 7.2\n", read_release_zip_entry($zip, 'my-plugin/my-plugin.php'));
        $snapshot = release_snapshot(get_post($release->ID));
        self::assertSame('7.2', $snapshot['plugin_data']['TestedUpTo']);
        self::assertSame('7.2', $snapshot['plugin_readme_txt']['content']['tested']);
        self::assertNotSame($hash_before, get_post_meta($release->ID, '_pblsh_directory_content_hash', true));
        $log = get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true);
        self::assertSame('tested_up_to', $log[0]['operation']);
        self::assertNull($log[0]['username']);
        self::assertNull($log[0]['revision']);
        self::assertSame('1.4', $log[0]['details']['version']);
        self::assertSame([ 'my-plugin/readme.txt', 'my-plugin/my-plugin.php' ], $log[0]['details']['files']);
        self::assertSame('header', $log[0]['details']['source_before']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $log[0]['details']['zip_sha256_before']);
        self::assertNotSame($log[0]['details']['zip_sha256_before'], $log[0]['details']['zip_sha256_after']);
    }

    public function test_the_readme_line_is_inserted_and_no_header_is_planted(): void {
        $release = $this->self_hosted_release('', null);

        $result = set_release_tested_up_to($release, '7.2', '');

        self::assertSame('7.2', $result['to']);
        self::assertSame('', $result['from']);
        $zip = release_zip_path(get_post($release->ID));
        self::assertStringContainsString("Stable tag: 1.4\nTested up to: 7.2\n", read_release_zip_entry($zip, 'my-plugin/readme.txt'));
        self::assertStringNotContainsString('Tested up to', (string) read_release_zip_entry($zip, 'my-plugin/my-plugin.php'));
        $log = get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true);
        self::assertSame([ 'my-plugin/readme.txt' ], $log[0]['details']['files']);
        self::assertNull($log[0]['details']['source_before']);
    }

    public function test_a_readme_that_already_says_the_value_is_not_rewritten(): void {
        // The header is the effective value (7.1), the readme already says 7.2: only the plugin file changes.
        $release = $this->self_hosted_release('7.2', '7.1');
        $zip = release_zip_path($release);
        $readme_before = read_release_zip_entry($zip, 'my-plugin/readme.txt');

        $result = set_release_tested_up_to($release, '7.2', '7.1');

        self::assertSame('7.2', $result['to']);
        self::assertSame($readme_before, read_release_zip_entry($zip, 'my-plugin/readme.txt'));
        $log = get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true);
        self::assertSame([ 'my-plugin/my-plugin.php' ], $log[0]['details']['files'], 'the log names only what changed');
    }

    public function test_a_readme_without_the_line_stays_so_when_the_header_carries_the_value(): void {
        // The author keeps the value in the plugin header: the edit sets it there and plants no readme line.
        $release = $this->self_hosted_release('', '7.1');
        $zip = release_zip_path($release);
        $readme_before = read_release_zip_entry($zip, 'my-plugin/readme.txt');

        $result = set_release_tested_up_to($release, '7.2', '7.1');

        self::assertSame('7.2', $result['to']);
        self::assertSame($readme_before, read_release_zip_entry($zip, 'my-plugin/readme.txt'));
        self::assertStringContainsString(" * Tested up to: 7.2\n", read_release_zip_entry($zip, 'my-plugin/my-plugin.php'));
        self::assertSame([ 'my-plugin/my-plugin.php' ], get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true)[0]['details']['files']);
    }

    public function test_lowering_is_allowed(): void {
        $release = $this->self_hosted_release();
        $result = set_release_tested_up_to($release, '7.0', '7.1');
        self::assertSame('7.0', $result['to']);
        self::assertSame('7.0', release_snapshot(get_post($release->ID))['plugin_data']['TestedUpTo']);
    }

    public function test_a_stale_expectation_is_refused_and_nothing_is_written(): void {
        $release = $this->self_hosted_release();

        $result = set_release_tested_up_to($release, '7.2', '7.0');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('tested_up_to_changed', $result->get_error_code());
        self::assertStringContainsString("Tested up to: 7.1\n", read_release_zip_entry(release_zip_path($release), 'my-plugin/readme.txt'));
        self::assertSame('', (string) get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true));
    }

    public function test_the_same_value_and_a_bad_format_are_refused(): void {
        $release = $this->self_hosted_release();
        self::assertSame('invalid_tested_up_to_target', set_release_tested_up_to($release, '7.1', '7.1')->get_error_code());
        self::assertSame('invalid_tested_up_to', set_release_tested_up_to($release, '7.2.1', '7.1')->get_error_code());
        self::assertSame('invalid_tested_up_to', set_release_tested_up_to($release, '', '7.1')->get_error_code());
    }

    public function test_without_a_readme_the_plugin_file_header_alone_is_set(): void {
        $release = $this->self_hosted_release('', '7.1', false);

        $result = set_release_tested_up_to($release, '7.2', '7.1');

        self::assertSame('7.2', $result['to']);
        $zip = release_zip_path(get_post($release->ID));
        self::assertStringContainsString(" * Tested up to: 7.2\n", read_release_zip_entry($zip, 'my-plugin/my-plugin.php'));
        self::assertNull(read_release_zip_entry($zip, 'my-plugin/readme.txt'), 'no readme is planted');
        self::assertSame('7.2', release_snapshot(get_post($release->ID))['plugin_data']['TestedUpTo']);
        $log = get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true);
        self::assertSame([ 'my-plugin/my-plugin.php' ], $log[0]['details']['files']);
    }

    public function test_a_release_with_neither_readme_nor_header_line_cannot_be_edited(): void {
        $release = $this->self_hosted_release('', null, false);
        self::assertSame('tested_up_to_nowhere', set_release_tested_up_to($release, '7.2', '')->get_error_code());
        self::assertSame('', (string) get_post_meta($release->post_parent, PBLSH_OPERATIONS_META, true));
    }

    public function test_a_missing_zip_is_reported(): void {
        $release = $this->self_hosted_release();
        unlink(release_zip_path($release));
        self::assertSame('release_zip_missing', set_release_tested_up_to($release, '7.2', '7.1')->get_error_code());
    }
}
