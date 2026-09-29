<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\parse_readme_txt;
use function Pblsh\set_readme_stable_tag;

/**
 * set_readme_stable_tag() (includes/functions.php): the one place a Stable tag is written
 * into a readme — the header block as wordpress.org's parser reads it, line endings kept,
 * everything below untouched.
 */
final class ReadmeStableTagTest extends TestCase {

    #[DataProvider('readmes')]
    public function test_the_header_block_gets_the_value(string $readme, string $expected): void {
        self::assertSame($expected, set_readme_stable_tag($readme, '1.2.3'));
    }

    public static function readmes(): array {
        return [
            'replaces the value and keeps the key spelling' => [
                "=== My Plugin ===\nContributors: me\nStable Tag: 1.1.0\nLicense: GPLv2\n\nShort description.\n",
                "=== My Plugin ===\nContributors: me\nStable Tag: 1.2.3\nLicense: GPLv2\n\nShort description.\n",
            ],
            'a quoted trunk value is replaced too' => [
                "=== My Plugin ===\nStable tag: \"trunk\"\n",
                "=== My Plugin ===\nStable tag: 1.2.3\n",
            ],
            'a bullet before the key, like the parser tolerates' => [
                "=== My Plugin ===\n* Stable tag: 1.0\n",
                "=== My Plugin ===\n* Stable tag: 1.2.3\n",
            ],
            'inserted after the last header line when missing' => [
                "=== My Plugin ===\nContributors: me\nTags: a, b\n\nShort description.\n",
                "=== My Plugin ===\nContributors: me\nTags: a, b\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'a known header after a blank line still belongs to the block' => [
                "=== My Plugin ===\nContributors: me\n\nTags: a\n\nShort description.\n",
                "=== My Plugin ===\nContributors: me\n\nTags: a\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'an unknown key after a blank line ends the header block' => [
                "=== My Plugin ===\nTags: a\n\nNote: this is the description.\n",
                "=== My Plugin ===\nTags: a\nStable tag: 1.2.3\n\nNote: this is the description.\n",
            ],
            'a stable tag line below the header block stays' => [
                "=== My Plugin ===\nStable tag: 1.0\n\nThe Stable tag: header is explained here.\n",
                "=== My Plugin ===\nStable tag: 1.2.3\n\nThe Stable tag: header is explained here.\n",
            ],
            'no title line' => [
                "Contributors: me\nStable tag: trunk\n",
                "Contributors: me\nStable tag: 1.2.3\n",
            ],
            'a first line with an unknown key is the title' => [
                "Note: this is the title\nContributors: me\n",
                "Note: this is the title\nContributors: me\nStable tag: 1.2.3\n",
            ],
            'a github-style underline belongs to the title' => [
                "My Plugin\n=========\nContributors: me\n\nShort description.\n",
                "My Plugin\n=========\nContributors: me\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'a github-style underline without headers' => [
                "My Plugin\n---------\n\nShort description.\n",
                "My Plugin\n---------\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'the placeholder title is followed by the real name' => [
                "=== Plugin Name ===\nMy Real Name\n\nShort description.\n",
                "=== Plugin Name ===\nMy Real Name\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'whitespace-only lines before the first header are skipped like the parser does' => [
                "=== My Plugin ===\n   \nContributors: me\n\nShort description.\n",
                "=== My Plugin ===\n   \nContributors: me\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'a whitespace-only line inside the block ends it' => [
                "=== My Plugin ===\nContributors: me\n   \nTags: a\n",
                "=== My Plugin ===\nContributors: me\nStable tag: 1.2.3\n   \nTags: a\n",
            ],
            'every stable tag line of the block is set — the parser keeps the last one' => [
                "=== My Plugin ===\nStable tag: 1.0\nTags: a\nStable tag: 1.1\n\nShort description.\n",
                "=== My Plugin ===\nStable tag: 1.2.3\nTags: a\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'a utf-8 bom stays in front and does not hide a first-line header' => [
                "\xEF\xBB\xBFContributors: me\nStable tag: 1.0\n",
                "\xEF\xBB\xBFContributors: me\nStable tag: 1.2.3\n",
            ],
            'a utf-8 bom with no stable tag' => [
                "\xEF\xBB\xBFContributors: me\n",
                "\xEF\xBB\xBFContributors: me\nStable tag: 1.2.3\n",
            ],
            'title only' => [
                "=== My Plugin ===\n\nShort description.\n",
                "=== My Plugin ===\nStable tag: 1.2.3\n\nShort description.\n",
            ],
            'appended to a file without trailing newline' => [
                "=== My Plugin ===\nTags: a",
                "=== My Plugin ===\nTags: a\nStable tag: 1.2.3",
            ],
            'an empty file becomes the header' => [
                '',
                "Stable tag: 1.2.3\n",
            ],
        ];
    }

    /**
     * The proof that counts: wordpress.org's own parser (the bundled copy) reads the value
     * back from every result.
     */
    #[DataProvider('readmes')]
    public function test_the_wordpress_org_parser_reads_the_value_back(string $readme, string $expected): void {
        self::assertSame('1.2.3', parse_readme_txt(set_readme_stable_tag($readme, '1.2.3'))['stable_tag']);
    }

    /**
     * Parity with wordpress.org's parser over generated readmes: BOM, line endings, every
     * title form the parser knows (classic, markdown, bare, underlined, the "Plugin Name"
     * placeholder, a title with a colon), blank and whitespace lines, known and unknown
     * headers, duplicate Stable tags, descriptions with colons. After the change the parser
     * reads the value back, and every other field exactly as before — a header hidden or a
     * description shifted would show here.
     */
    public function test_the_wordpress_org_parser_reads_every_other_field_unchanged_across_generated_readmes(): void {
        $titles = [
            'classic' => [ '=== My Plugin ===' ],
            'markdown' => [ '# My Plugin' ],
            'bare' => [ 'My Plugin' ],
            'underlined' => [ 'My Plugin', '=====' ],
            'dashed' => [ 'My Plugin', '-----' ],
            'placeholder' => [ '=== Plugin Name ===', 'Real Name' ],
            'placeholder-long' => [ '=== Plugin Name ===', str_repeat('x', 60) ],
            'colon-title' => [ 'Note: odd title' ],
            'none' => [],
        ];
        $headers = [
            'none' => [],
            'one' => [ 'Contributors: me' ],
            'with-stable' => [ 'Contributors: me', 'Stable tag: 1.0', 'Tags: a' ],
            'stable-first' => [ 'Stable tag: 1.0', 'Tags: a' ],
            'blank-inside' => [ 'Contributors: me', '', 'Tags: a' ],
            'unknown-after-blank' => [ 'Contributors: me', '', 'Note: x', 'Tags: a' ],
            'unknown-inline' => [ 'Contributors: me', 'Note: x', 'Tags: a' ],
            'double-stable' => [ 'Stable tag: 1.0', 'Tags: a', 'Stable tag: 1.1' ],
            'bullet-stable' => [ '* Stable tag: 1.0', 'Tags: a' ],
            'quoted-trunk' => [ 'Stable Tag : "trunk"' ],
            'whitespace-inside' => [ 'Contributors: me', '   ', 'Tags: a' ],
            'stable-after-blank' => [ 'Contributors: me', '', 'Stable tag: 1.0' ],
            'double-space-key' => [ 'Stable  tag: 1.0', 'Tags: a' ],
        ];
        $bodies = [
            'none' => [],
            'plain' => [ '', 'Short description.', '', '== Description ==', 'Stable tag: 9.9 in text', '', '== Changelog ==', '= 1.0 =', '* first' ],
            'colon-desc' => [ 'This plugin does: things.', '', '== Description ==', 'More.' ],
        ];
        // BOM and line ending each vary against the plain form; the remaining dimensions cross fully.
        $framings = [ [ '', "\n" ], [ "\xEF\xBB\xBF", "\n" ], [ '', "\r\n" ] ];
        $fields = [ 'name', 'contributors', 'tags', 'requires', 'tested', 'requires_php', 'donate_link', 'license', 'license_uri', 'short_description', 'sections', 'upgrade_notice', 'faq', 'screenshots', 'warnings' ];

        $cases = 0;
        foreach ($framings as [ $bom, $eol ]) {
            foreach ([ [], [ '   ' ] ] as $leading) {
                foreach ($titles as $title_name => $title) {
                    foreach ([ [], [ '' ], [ '   ' ] ] as $after_title) {
                        foreach ($headers as $headers_name => $header_lines) {
                            foreach ($bodies as $body_name => $body) {
                                foreach ([ true, false ] as $trailing) {
                                    if ($title === [] && $header_lines === [] && $body === []) {
                                        continue; // no content at all: the parser does not even run on the original
                                    }
                                    $readme = $bom . implode($eol, [ ...$leading, ...$title, ...$after_title, ...$header_lines, ...$body ]) . ($trailing ? $eol : '');
                                    $case = sprintf('%s / %s / %s (bom %s, eol %s, leading %s, after title %s, trailing %s)', $title_name, $headers_name, $body_name, $bom === '' ? 'no' : 'yes', json_encode($eol), json_encode($leading), json_encode($after_title), json_encode($trailing));

                                    $before = parse_readme_txt($readme);
                                    $result = set_readme_stable_tag($readme, '1.2.3');
                                    $after = parse_readme_txt($result);

                                    self::assertSame('1.2.3', $after['stable_tag'], $case . "\n" . json_encode($result));
                                    self::assertSame(str_starts_with($readme, "\xEF\xBB\xBF"), str_starts_with($result, "\xEF\xBB\xBF"), $case);
                                    foreach ($fields as $field) {
                                        $expected = $before[$field] ?? null;
                                        $actual = $after[$field] ?? null;
                                        if ($field === 'warnings') {
                                            unset($expected['invalid_stable_tag'], $actual['invalid_stable_tag']);
                                        }
                                        self::assertSame($expected, $actual, $case . ': ' . $field . "\n" . json_encode($result));
                                    }
                                    $cases++;
                                }
                            }
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(10000, $cases);
    }

    public function test_line_endings_survive(): void {
        $readme = "=== My Plugin ===\r\nContributors: me\r\nStable tag: 1.0\r\n\r\nDescription.\r\n";
        self::assertSame("=== My Plugin ===\r\nContributors: me\r\nStable tag: 1.2.3\r\n\r\nDescription.\r\n", set_readme_stable_tag($readme, '1.2.3'));
        self::assertSame("=== My Plugin ===\r\nTags: a\r\nStable tag: 1.2.3\r\n\r\nDescription.\r\n", set_readme_stable_tag("=== My Plugin ===\r\nTags: a\r\n\r\nDescription.\r\n", '1.2.3'), 'the inserted line takes the file\'s line ending');
    }

    public function test_invalid_utf8_is_a_caller_bug(): void {
        $this->expectException(\InvalidArgumentException::class);
        set_readme_stable_tag("=== My Plugin ===\nStable tag: 1.0\n\xC3\x28\n", '1.2.3');
    }
}
