<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use function Pblsh\readme_oembed_whitelist;

/**
 * readme_oembed_whitelist() (includes/functions.php): the oEmbed providers a readme may embed
 * from — wordpress.org's whitelist, which a site changes through pblsh_readme_oembed_providers.
 */
final class ReadmeOembedWhitelistTest extends TestCase {

    private const YOUTUBE = '#https?://((m|www)\.)?youtube\.com/watch.*#i';
    private const VIMEO = '#https?://(.+\.)?vimeo\.com/.*#i';
    private const TWITTER = '#https?://(www\.)?twitter\.com/\w{1,15}/status(es)?/.*#i';
    private const DAILYMOTION = '#https?://(www\.)?dailymotion\.com/.*#i';

    /** WP_oEmbed's shape: match mask => [ endpoint URL, is regex ]. */
    private const PROVIDERS = [
        self::YOUTUBE => [ 'https://www.youtube.com/oembed', true ],
        self::TWITTER => [ 'https://publish.twitter.com/oembed', true ],
        self::VIMEO => [ 'https://vimeo.com/api/oembed.{format}', true ],
        self::DAILYMOTION => [ 'https://www.dailymotion.com/services/oembed', true ],
    ];

    public function test_only_the_whitelisted_providers_remain(): void {
        self::assertSame([ self::YOUTUBE, self::VIMEO ], array_keys(readme_oembed_whitelist(self::PROVIDERS)));
    }

    public function test_a_host_added_through_the_filter_lets_its_provider_pass(): void {
        add_filter('pblsh_readme_oembed_providers', static fn(array $hosts): array => [ ...$hosts, 'dailymotion.com' ]);

        self::assertSame([ self::YOUTUBE, self::VIMEO, self::DAILYMOTION ], array_keys(readme_oembed_whitelist(self::PROVIDERS)));
    }
}
