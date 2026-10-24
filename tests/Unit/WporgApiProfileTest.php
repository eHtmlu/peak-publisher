<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use function Pblsh\wporg_profile_avatar_url;

/** The avatar redirect of wordpress.org, resolved once on the server. */
final class WporgApiProfileTest extends TestCase {

    public function test_the_redirect_target_is_the_avatar_url(): void {
        FakeWordPress::$http_responses[] = [ 'code' => 302, 'body' => '', 'headers' => [ 'location' => 'https://secure.gravatar.com/avatar/abc?s=128&d=retro' ] ];

        self::assertSame('https://secure.gravatar.com/avatar/abc?s=128&d=retro', wporg_profile_avatar_url('ehtmlu'));
        self::assertSame('https://wordpress.org/grav-redirect.php?user=ehtmlu&s=128', FakeWordPress::$http_requests[0]['url']);
        self::assertSame(0, FakeWordPress::$http_requests[0]['args']['redirection'], 'The redirect is read, never followed.');
    }

    public function test_anything_but_the_redirect_resolves_nothing(): void {
        FakeWordPress::$http_responses[] = [ 'code' => 200, 'body' => '<html>' ];
        self::assertNull(wporg_profile_avatar_url('ehtmlu'));

        FakeWordPress::$http_responses[] = new \WP_Error('http_request_failed', 'timeout');
        self::assertNull(wporg_profile_avatar_url('ehtmlu'));

        FakeWordPress::$http_responses[] = [ 'code' => 302, 'body' => '', 'headers' => [ 'location' => '/relative' ] ];
        self::assertNull(wporg_profile_avatar_url('ehtmlu'));
    }
}
