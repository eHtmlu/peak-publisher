<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\release_snapshot_from_upload_state;
use function Pblsh\release_snapshot_from_zip;
use function Pblsh\replace_release_zip_entries;

/**
 * A self-hosted release ZIP as the source of its snapshot and the one artifact an edit
 * changes (includes/releases.php): entries replaced in place, everything else byte for byte.
 */
final class ReleaseZipTest extends TestCase {

    private function release_zip(): string {
        $path = FakeWordPress::$upload_dir . '/my-plugin.1.4.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addEmptyDir('my-plugin');
        $zip->addEmptyDir('my-plugin/css');
        $zip->addFromString('my-plugin/my-plugin.php', "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.4\n * Tested up to: 7.1\n */\n");
        $zip->addFromString('my-plugin/readme.txt', "=== My Plugin ===\nTested up to: 7.1\nStable tag: 1.4\n\nText.\n");
        $zip->addFromString('my-plugin/css/a.css', 'a{}');
        $zip->close();
        return $path;
    }

    public function test_the_snapshot_is_derived_from_the_zip(): void {
        $snapshot = release_snapshot_from_zip($this->release_zip());

        self::assertSame([ 'plugin_data', 'plugin_info', 'plugin_readme_txt' ], array_keys($snapshot));
        self::assertSame('My Plugin', $snapshot['plugin_data']['Name']);
        self::assertSame('7.1', $snapshot['plugin_data']['TestedUpTo']);
        self::assertSame('my-plugin.php', $snapshot['plugin_info']['main_file']);
        self::assertSame('my-plugin/my-plugin.php', $snapshot['plugin_info']['plugin_basename']);
        self::assertSame('my-plugin', $snapshot['plugin_info']['plugin_slug']);
        self::assertSame('1.4', $snapshot['plugin_info']['normalized_version']);
        self::assertSame('7.1', $snapshot['plugin_readme_txt']['content']['tested']);
        self::assertSame('readme.txt', $snapshot['plugin_readme_txt']['file_name']);
        self::assertTrue($snapshot['plugin_readme_txt']['found']);
    }

    public function test_the_snapshot_from_the_upload_state_has_the_same_shape(): void {
        $data = [
            'plugin_ok' => true,
            'plugin_data' => [ 'Name' => 'My Plugin', 'Version' => '1.4', 'TestedUpTo' => '7.1' ],
            'plugin_info' => [ 'normalized_version' => '1.4', 'main_file' => 'my-plugin.php', 'bootstrap_file' => false, 'slug_candidates' => [], 'content_hash' => 'abc', 'plugin_slug' => 'my-plugin', 'plugin_basename' => 'my-plugin/my-plugin.php', 'release_slug' => 'my-plugin_1-4' ],
            'plugin_readme_txt' => [ 'found' => true, 'file_name' => 'readme.txt', 'content' => [ 'tested' => '7.1' ] ],
            'cleanup_info' => [],
        ];
        $snapshot = release_snapshot_from_upload_state($data);
        self::assertSame([ 'plugin_data', 'plugin_info', 'plugin_readme_txt' ], array_keys($snapshot));
        self::assertSame($data['plugin_data'], $snapshot['plugin_data']);
        self::assertSame([ 'normalized_version' => '1.4', 'release_slug' => 'my-plugin_1-4', 'main_file' => 'my-plugin.php', 'plugin_basename' => 'my-plugin/my-plugin.php', 'plugin_slug' => 'my-plugin' ], $snapshot['plugin_info'], 'the release facts of plugin_info, not the upload facts');
        self::assertSame($data['plugin_readme_txt'], $snapshot['plugin_readme_txt']);
    }

    public function test_a_zip_without_a_main_file_has_no_snapshot(): void {
        $path = FakeWordPress::$upload_dir . '/no-plugin.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('no-plugin/readme.txt', "=== X ===\n");
        $zip->close();
        self::assertNull(release_snapshot_from_zip($path));
    }

    public function test_entries_are_replaced_and_everything_else_stays_byte_identical(): void {
        $path = $this->release_zip();
        $before = hash_file('sha256', $path);

        $result = replace_release_zip_entries($path, [ 'my-plugin/readme.txt' => "=== My Plugin ===\nTested up to: 7.2\nStable tag: 1.4\n\nText.\n" ]);

        self::assertTrue($result);
        self::assertNotSame($before, hash_file('sha256', $path));
        $zip = new \ZipArchive();
        $zip->open($path);
        self::assertSame("=== My Plugin ===\nTested up to: 7.2\nStable tag: 1.4\n\nText.\n", $zip->getFromName('my-plugin/readme.txt'));
        self::assertSame('a{}', $zip->getFromName('my-plugin/css/a.css'));
        self::assertNotFalse($zip->locateName('my-plugin/css/'), 'directory entries survive');
        self::assertSame(5, $zip->numFiles);
        self::assertFileDoesNotExist($path . '.pblsh-edit', 'the working copy is gone');
    }
}
