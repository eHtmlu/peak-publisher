<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;

use function Pblsh\content_hash_from_entries;
use function Pblsh\directory_entries;
use function Pblsh\zip_entries;

/**
 * The content hash of a shipped tree (includes/releases.php): one formula over the paths and
 * file contents of a tree, two sources — the workspace directory at analyze, the release ZIP
 * after an edit — that hash alike.
 */
final class ContentHashTest extends TestCase {

    private function tree(): string {
        $root = FakeWordPress::$upload_dir . '/tree';
        mkdir($root . '/css', 0777, true);
        mkdir($root . '/empty', 0777, true);
        file_put_contents($root . '/plugin.php', "<?php\n// x\n");
        file_put_contents($root . '/css/a.css', 'a{}');
        return $root;
    }

    private function zip(string $root, bool $with_dirs): string {
        $path = FakeWordPress::$upload_dir . '/out' . ($with_dirs ? '-dirs' : '') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($with_dirs) {
            $zip->addEmptyDir('slug');
            $zip->addEmptyDir('slug/css');
            $zip->addEmptyDir('slug/empty');
        }
        $zip->addFile($root . '/plugin.php', 'slug/plugin.php');
        $zip->addFile($root . '/css/a.css', 'slug/css/a.css');
        $zip->close();
        return $path;
    }

    public function test_directory_and_zip_with_directory_entries_hash_alike(): void {
        $root = $this->tree();
        self::assertSame(content_hash_from_entries(directory_entries($root)), content_hash_from_entries(zip_entries($this->zip($root, true))));
    }

    public function test_an_old_zip_without_directory_entries_equals_the_tree_without_its_empty_folder(): void {
        $root = $this->tree();
        $hash_old_zip = content_hash_from_entries(zip_entries($this->zip($root, false)));
        rmdir($root . '/empty');
        self::assertSame(content_hash_from_entries(directory_entries($root)), $hash_old_zip);
    }

    public function test_the_hash_is_sha256_and_changes_with_content(): void {
        $root = $this->tree();
        $before = content_hash_from_entries(directory_entries($root));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $before);
        file_put_contents($root . '/css/a.css', 'b{}');
        self::assertNotSame($before, content_hash_from_entries(directory_entries($root)));
    }

    public function test_an_empty_folder_is_content(): void {
        $root = $this->tree();
        $with = content_hash_from_entries(directory_entries($root));
        rmdir($root . '/empty');
        self::assertNotSame($with, content_hash_from_entries(directory_entries($root)));
    }
}
