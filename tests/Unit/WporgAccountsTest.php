<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit;

use Pblsh\Tests\FakeWordPress;
use function Pblsh\delete_wporg_account;
use function Pblsh\encrypt_wporg_password;
use function Pblsh\find_wporg_account;
use function Pblsh\get_encryption_key_file_path;
use function Pblsh\get_usable_wporg_account_usernames;
use function Pblsh\get_wporg_account;
use function Pblsh\get_wporg_accounts;
use function Pblsh\get_wporg_credentials;
use function Pblsh\record_wporg_account_verdict;
use function Pblsh\record_wporg_credentials_verdict;
use function Pblsh\select_wporg_account_username;
use function Pblsh\store_wporg_account;
use function Pblsh\wporg_account_for_api;
use function Pblsh\wporg_account_password;
use function Pblsh\wporg_account_password_is_usable;
use function Pblsh\wporg_account_verdict;
use function Pblsh\wporg_accounts_payload;
use function Pblsh\wporg_username_spelling;

/**
 * The wordpress.org accounts module: posts of type pblsh_wporg_account, looked up by
 * username without regard to case, their verdict and the credentials behind the cipher.
 */
final class WporgAccountsTest extends TestCase {

    public function test_accounts_are_listed_in_the_order_they_were_connected(): void {
        store_wporg_account(null, 'alpha', 'aes256gcm:x');
        $beta = store_wporg_account(null, 'beta', 'aes256gcm:x');
        // An older date on the later connection must not reorder: the ID is the order.
        FakeWordPress::$posts[$beta->ID]->post_date = '2020-01-01 00:00:00';

        self::assertSame([ 'alpha', 'beta' ], array_map(static fn(\WP_Post $p): string => $p->post_title, get_wporg_accounts()));
    }

    public function test_find_ignores_case_and_get_checks_the_post_type(): void {
        $account = store_wporg_account(null, 'ehtmlu', 'aes256gcm:x');
        $plugin = $this->create_plugin('pblsh_plugin', 'some-plugin');

        self::assertSame($account->ID, find_wporg_account('EHTMLU')->ID);
        self::assertNull(find_wporg_account('someone-else'));
        self::assertSame($account->ID, get_wporg_account($account->ID)->ID);
        self::assertNull(get_wporg_account($plugin->ID));
        self::assertNull(get_wporg_account(999));
    }

    public function test_store_renames_replaces_the_password_and_resets_the_verdict(): void {
        $account = store_wporg_account(null, 'eHtmlu', 'aes256gcm:old');
        record_wporg_credentials_verdict('eHtmlu', false);

        $updated = store_wporg_account($account, 'ehtmlu', 'aes256gcm:new');

        self::assertSame($account->ID, $updated->ID);
        self::assertSame('ehtmlu', $updated->post_title);
        self::assertSame('aes256gcm:new', get_post_meta($updated->ID, '_pblsh_password', true));
        self::assertNull(wporg_account_verdict($updated)['rejected_at']);
        self::assertNotNull(wporg_account_verdict($updated)['verified_at']);
        self::assertCount(1, get_wporg_accounts());
    }

    public function test_store_keeps_the_password_when_none_arrives(): void {
        $account = store_wporg_account(null, 'eHtmlu', 'aes256gcm:old');
        record_wporg_credentials_verdict('eHtmlu', false);

        $updated = store_wporg_account($account, 'ehtmlu', null);

        self::assertSame('ehtmlu', $updated->post_title);
        self::assertSame('aes256gcm:old', get_post_meta($updated->ID, '_pblsh_password', true));
        self::assertNull(wporg_account_verdict($updated)['rejected_at']);
    }

    public function test_store_reports_a_failed_insert(): void {
        FakeWordPress::$post_write_error = new \WP_Error('db_insert_error', 'Could not insert post into the database.');

        $result = store_wporg_account(null, 'ehtmlu', 'aes256gcm:x');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('db_insert_error', $result->get_error_code());
        self::assertSame([], get_wporg_accounts());
    }

    public function test_store_reports_a_failed_rename_and_writes_nothing(): void {
        $account = store_wporg_account(null, 'eHtmlu', 'aes256gcm:old');
        record_wporg_credentials_verdict('eHtmlu', false);
        FakeWordPress::$post_write_error = new \WP_Error('db_update_error', 'Could not update post in the database.');

        $result = store_wporg_account($account, 'ehtmlu', 'aes256gcm:new');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('db_update_error', $result->get_error_code());
        self::assertSame('eHtmlu', get_post($account->ID)->post_title);
        self::assertSame('aes256gcm:old', get_post_meta($account->ID, '_pblsh_password', true));
        self::assertNotNull(wporg_account_verdict($account)['rejected_at']);
    }

    public function test_delete_removes_the_post_for_good(): void {
        $account = store_wporg_account(null, 'ehtmlu', 'aes256gcm:x');
        delete_wporg_account($account);
        self::assertSame([], get_wporg_accounts());
        self::assertNull(get_post($account->ID));
    }

    public function test_credentials_decrypt_with_the_stored_spelling(): void {
        $encrypted = encrypt_wporg_password('svn_secret');
        self::assertIsString($encrypted);
        $account = store_wporg_account(null, 'ehtmlu', $encrypted);

        self::assertSame('svn_secret', wporg_account_password($account));
        self::assertTrue(wporg_account_password_is_usable($account));
        self::assertSame([ 'username' => 'ehtmlu', 'password' => 'svn_secret' ], get_wporg_credentials('EHTMLU'));
        self::assertNull(get_wporg_credentials('nobody'));
        self::assertSame([ 'ehtmlu' ], get_usable_wporg_account_usernames());
    }

    public function test_password_is_unusable_after_the_key_material_changed(): void {
        $account = store_wporg_account(null, 'ehtmlu', encrypt_wporg_password('svn_secret'));
        file_put_contents(get_encryption_key_file_path(), "<?php\ndefined('ABSPATH') || exit;\nreturn 'base64:" . base64_encode(random_bytes(32)) . "';\n");

        self::assertInstanceOf(\WP_Error::class, wporg_account_password($account));
        self::assertFalse(wporg_account_password_is_usable($account));
        self::assertInstanceOf(\WP_Error::class, get_wporg_credentials('ehtmlu'));
        self::assertSame([], get_usable_wporg_account_usernames());
        self::assertNull(select_wporg_account_username('ehtmlu'));
        self::assertFalse(wporg_account_for_api($account)['password_usable']);
    }

    public function test_selection_prefers_the_preferred_account_in_the_stored_spelling(): void {
        store_wporg_account(null, 'first', encrypt_wporg_password('a'));
        store_wporg_account(null, 'Otto42', encrypt_wporg_password('b'));

        self::assertSame('Otto42', select_wporg_account_username('otto42'));
        self::assertSame('first', select_wporg_account_username('unknown'));
        self::assertSame('first', select_wporg_account_username(null));
    }

    public function test_verdicts_are_recorded_throttled_and_forced(): void {
        $account = store_wporg_account(null, 'ehtmlu', 'aes256gcm:x');
        update_post_meta($account->ID, '_pblsh_verdict', [ 'verified_at' => 1000, 'rejected_at' => null ]);

        record_wporg_credentials_verdict('EHTMLU', false);
        self::assertSame(1000, wporg_account_verdict($account)['verified_at']);
        self::assertNotNull(wporg_account_verdict($account)['rejected_at']);
        self::assertTrue(wporg_account_for_api($account)['credentials_rejected']);

        record_wporg_credentials_verdict('ehtmlu', true);
        self::assertNull(wporg_account_verdict($account)['rejected_at'], 'A rejection is cleared by the next acceptance, throttle or not.');
        self::assertGreaterThan(1000, wporg_account_verdict($account)['verified_at']);

        $recent = time() - 60;
        update_post_meta($account->ID, '_pblsh_verdict', [ 'verified_at' => $recent, 'rejected_at' => null ]);
        record_wporg_credentials_verdict('ehtmlu', true);
        self::assertSame($recent, wporg_account_verdict($account)['verified_at'], 'A recent acceptance is not rewritten.');
        record_wporg_account_verdict($account, true, true);
        self::assertGreaterThan($recent, wporg_account_verdict($account)['verified_at'], 'An explicit probe always refreshes the stamp.');

        record_wporg_credentials_verdict('nobody', true);
        self::assertCount(1, get_wporg_accounts());
    }

    /**
     * The spelling a save settles on: the commit history's proof wins, an unproven
     * entry stays and earns the lowercase hint only for an uppercase letter.
     *
     * @dataProvider spellings
     */
    public function test_username_spelling(string $entered, ?string $proven, string $saved, ?array $spelling): void {
        self::assertSame([ 'username' => $saved, 'spelling' => $spelling ], wporg_username_spelling($entered, $proven));
    }

    public static function spellings(): array {
        return [
            'proven as entered' => [ 'Otto42', 'Otto42', 'Otto42', null ],
            'proven in another case' => [ 'eHtmlu', 'ehtmlu', 'ehtmlu', [ 'status' => 'corrected', 'username' => 'ehtmlu' ] ],
            'no proof, uppercase' => [ 'eHtmlu', null, 'eHtmlu', [ 'status' => 'unverified', 'username' => 'ehtmlu' ] ],
            'no proof, lowercase' => [ 'ehtmlu', null, 'ehtmlu', null ],
            'no proof, legacy characters' => [ 'john.doe', null, 'john.doe', null ],
        ];
    }

    public function test_api_form_and_payload(): void {
        $account = store_wporg_account(null, 'ehtmlu', encrypt_wporg_password('svn_secret'));
        update_post_meta($account->ID, '_pblsh_avatar_url', 'https://secure.gravatar.com/avatar/abc?s=128&d=retro');

        $form = wporg_account_for_api($account);
        self::assertSame($account->ID, $form['id']);
        self::assertSame('ehtmlu', $form['username']);
        self::assertTrue($form['password_usable']);
        self::assertIsInt($form['verified_at']);
        self::assertFalse($form['credentials_rejected']);
        self::assertSame('https://secure.gravatar.com/avatar/abc?s=128&d=retro', $form['avatar_url']);
        self::assertArrayNotHasKey('password', $form);

        $payload = wporg_accounts_payload();
        self::assertSame([ $form ], $payload['accounts']);
        self::assertSame('ok', $payload['storage']['status']);
    }
}
