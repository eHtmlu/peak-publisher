<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use function Pblsh\parse_readme_txt;

/**
 * parse_readme_txt() (includes/functions.php): its argument is readme content and nothing
 * else. wordpress.org's parser reads whatever a path or URL names — a readme that merely
 * looks like one is parsed as the text it is, never dereferenced.
 */
final class ReadmeParseTest extends TestCase {

    #[DataProvider('contents_that_look_like_a_source')]
    public function test_content_is_never_dereferenced(string $content): void {
        self::assertSame($content, parse_readme_txt($content)['raw_contents']);
    }

    public static function contents_that_look_like_a_source(): array {
        return [
            // This file exists: read as a source, its PHP code would be the content.
            'the path of an existing file' => [ __FILE__ ],
            'a data URI' => [ 'data:text/plain,=== From The URI ===' ],
            // Nothing listens on the discard port: a regression fails on the refused
            // connection and never reaches the network.
            'an http URL' => [ 'http://127.0.0.1:9/readme.txt' ],
            'an http URL above further lines' => [ "http://127.0.0.1:9/readme.txt\nStable tag: 1.2.3\n" ],
        ];
    }
}
