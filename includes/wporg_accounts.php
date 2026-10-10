<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The wordpress.org accounts: one post of type pblsh_wporg_account per connected
 * account — the username as its title, the encrypted SVN password, wordpress.org's
 * latest verdict about the credentials and the resolved avatar URL as its metas
 * (docs/data-schema.md). An account is found by its username without regard to case,
 * like wordpress.org's logins; its post ID addresses it in REST. The cipher behind the
 * password lives in encryption.php.
 */

const PBLSH_WPORG_ACCOUNT_POST_TYPE = 'pblsh_wporg_account';


/**
 * @return string|\WP_Error
 */
function normalize_wporg_username($username, ?string $field = null) {
    // Trim admin input while rejecting non-string values as empty.
    $trimmed = trim(wporg_string_from_value($username));
    if ($trimmed === '') {
        return wporg_credentials_error('invalid_username', __('Invalid wordpress.org username.', 'peak-publisher'), 400, $field);
    }

    // Let WordPress validate the username without silently changing it — the spelling
    // is the user's (wordpress.org checks commit rights against the exact login).
    $sanitized = sanitize_user($trimmed, true);
    if ($sanitized !== $trimmed) {
        return wporg_credentials_error('invalid_username', __('Invalid wordpress.org username.', 'peak-publisher'), 400, $field);
    }

    return $sanitized;
}


/**
 * @return \WP_Post[] Every connected account, in the order they were connected.
 */
function get_wporg_accounts(): array {
    return get_posts([
        'post_type' => PBLSH_WPORG_ACCOUNT_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
}


/**
 * The account of a username, in any spelling: wordpress.org's logins are unique without
 * regard to case, so are the accounts here.
 */
function find_wporg_account(string $username): ?\WP_Post {
    foreach (get_wporg_accounts() as $account) {
        if (strcasecmp($account->post_title, $username) === 0) {
            return $account;
        }
    }
    return null;
}


function get_wporg_account(int $id): ?\WP_Post {
    $post = get_post($id);
    return $post instanceof \WP_Post && $post->post_type === PBLSH_WPORG_ACCOUNT_POST_TYPE && $post->post_status === 'publish' ? $post : null;
}


/**
 * Writes an account: creates the post, or renames the existing one and replaces its
 * password ($encrypted_password null keeps the stored one — a save that only renames
 * or re-probes). Only called after wordpress.org accepted the credentials, so the
 * verdict starts over as accepted now. The username arrives validated and in its
 * settled spelling (save_wporg_account()).
 *
 * @return \WP_Post|\WP_Error The account, or the database's refusal to write it.
 */
function store_wporg_account(?\WP_Post $account, string $username, ?string $encrypted_password) {
    if ($account === null) {
        $id = wp_insert_post([
            'post_type' => PBLSH_WPORG_ACCOUNT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $username,
        ], true);
        if (is_wp_error($id)) {
            return $id;
        }
        $account = get_post((int) $id);
    } elseif ($account->post_title !== $username) {
        $renamed = wp_update_post([ 'ID' => (int) $account->ID, 'post_title' => $username ], true);
        if (is_wp_error($renamed)) {
            return $renamed;
        }
        $account = get_post((int) $account->ID);
    }
    if ($encrypted_password !== null) {
        update_post_meta((int) $account->ID, '_pblsh_password', $encrypted_password);
    }
    update_post_meta((int) $account->ID, '_pblsh_verdict', [ 'verified_at' => time(), 'rejected_at' => null ]);
    return $account;
}


/** No trash: a ciphertext has no business lingering there. */
function delete_wporg_account(\WP_Post $account): void {
    wp_delete_post((int) $account->ID, true);
}


/**
 * wordpress.org's latest verdict about the account's credentials: when it last accepted
 * them, and when it rejected them since (null = never / not since).
 *
 * @return array{verified_at:int|null, rejected_at:int|null}
 */
function wporg_account_verdict(\WP_Post $account): array {
    // Re-validation when reading back from persistence: the meta may be absent or malformed.
    $stored = get_post_meta((int) $account->ID, '_pblsh_verdict', true);
    $stored = is_array($stored) ? $stored : [];
    return [
        'verified_at' => (int) ($stored['verified_at'] ?? 0) > 0 ? (int) $stored['verified_at'] : null,
        'rejected_at' => (int) ($stored['rejected_at'] ?? 0) > 0 ? (int) $stored['rejected_at'] : null,
    ];
}


/**
 * Records wordpress.org's latest verdict about an account's credentials —
 * called at the places the verdict actually happens (save/test probes, upload
 * access checks, deploys; MKACTIVITY/MERGE responses). Reads never validate
 * credentials and must not call this. Positive verdicts are throttled so
 * upload-dialog refreshes do not write the meta over and over; explicit
 * user-requested probes pass $force to always refresh the timestamp — the
 * badge jump is their visible success feedback.
 */
function record_wporg_account_verdict(\WP_Post $account, bool $verified, bool $force = false): void {
    $verdict = wporg_account_verdict($account);
    if ($verified) {
        $recently_verified = (int) $verdict['verified_at'] > time() - 15 * MINUTE_IN_SECONDS;
        if (!$force && $recently_verified && $verdict['rejected_at'] === null) {
            return;
        }
        $verdict = [ 'verified_at' => time(), 'rejected_at' => null ];
    } else {
        $verdict['rejected_at'] = time();
    }
    update_post_meta((int) $account->ID, '_pblsh_verdict', $verdict);
}


/** The verdict for the account a username names, in any spelling; an unknown name records nothing. */
function record_wporg_credentials_verdict(string $username, bool $verified, bool $force = false): void {
    $account = find_wporg_account($username);
    if ($account !== null) {
        record_wporg_account_verdict($account, $verified, $force);
    }
}


/**
 * The SVN password of an account, decrypted — plaintext for internal backend callers
 * only, never for the UI.
 *
 * @return string|\WP_Error The cipher's refusal: no or malformed ciphertext, or key
 *         material that changed since it was written.
 */
function wporg_account_password(\WP_Post $account) {
    return decrypt_wporg_password(wporg_string_from_value(get_post_meta((int) $account->ID, '_pblsh_password', true)));
}


/**
 * Whether the account can publish right now: its stored password decrypts with the
 * current key material. Re-validation when reading back from persistence: the key
 * file or the wp-config constant may have changed since the ciphertext was written.
 */
function wporg_account_password_is_usable(\WP_Post $account): bool {
    return is_string(wporg_account_password($account));
}


/**
 * The credentials of an account, the username in the stored spelling — the one that
 * wordpress.org's commit authorization knows (save_wporg_account()); callers pass it
 * on to the SVN client, never the spelling they asked with.
 *
 * @return array{username:string,password:string}|null|\WP_Error
 */
function get_wporg_credentials(string $username) {
    $normalized = normalize_wporg_username($username);
    if (is_wp_error($normalized)) {
        return $normalized;
    }

    $account = find_wporg_account($normalized);
    if ($account === null) {
        return null;
    }

    $password = wporg_account_password($account);
    if (is_wp_error($password)) {
        return $password;
    }

    return [
        'username' => (string) $account->post_title,
        'password' => $password,
    ];
}


/**
 * @return string[] The usernames, in their stored spelling, of every account whose password decrypts.
 */
function get_usable_wporg_account_usernames(): array {
    $usernames = [];
    foreach (get_wporg_accounts() as $account) {
        if (wporg_account_password_is_usable($account)) {
            $usernames[] = (string) $account->post_title;
        }
    }
    return $usernames;
}


/**
 * The one place that picks the stored account for a wordpress.org operation:
 * the preferred account (the marker's last-deploy account, or the add-new
 * flow's intent) when it is usable, else the first usable account, else null.
 * Returns the stored spelling: the preference may name the account in another
 * case (a marker from before the spelling was corrected).
 */
function select_wporg_account_username(?string $preferred_username = null): ?string {
    $usable = get_usable_wporg_account_usernames();
    if ($preferred_username !== null && trim($preferred_username) !== '') {
        foreach ($usable as $username) {
            if (strcasecmp($username, trim($preferred_username)) === 0) {
                return $username;
            }
        }
    }
    return $usable[0] ?? null;
}


/**
 * Settles the spelling of an entered username against the spelling the commit history
 * proves for it (WporgOperations::proven_username_spelling(): the same name, case
 * apart, or null). The proof wins: wordpress.org checks commit rights against the
 * exact login, and the entered spelling would fail there for certain. Without proof
 * the entry stays; an uppercase letter then earns the hint that usernames registered
 * since 2016 are lowercase — older ones may not be, which is why the hint is a hint.
 *
 * @return array{username:string, spelling:array{status:'corrected'|'unverified', username:string}|null}
 *         spelling: corrected → username is the saved spelling; unverified → username is
 *         the lowercase candidate; null → nothing to say.
 */
function wporg_username_spelling(string $entered, ?string $proven): array {
    if ($proven !== null) {
        return $proven === $entered
            ? [ 'username' => $entered, 'spelling' => null ]
            : [ 'username' => $proven, 'spelling' => [ 'status' => 'corrected', 'username' => $proven ] ];
    }
    $lowercase = strtolower($entered);
    return [
        'username' => $entered,
        'spelling' => $lowercase === $entered ? null : [ 'status' => 'unverified', 'username' => $lowercase ],
    ];
}


/**
 * Probes credentials against wordpress.org — an MKACTIVITY, the only request that
 * checks the password. Null when accepted; otherwise the failure with the SVN client's
 * code, message and status, attached to the password field.
 */
function probe_wporg_credentials(string $username, string $password): ?\WP_Error {
    require_once PBLSH_PLUGIN_DIR . 'classes/WporgPluginSvnClient.php';
    try {
        (new WporgPluginSvnClient($username, $password))->test_credentials();
        return null;
    } catch (WporgSvnException $e) {
        return wporg_credentials_error($e->get_error_code(), $e->getMessage(), $e->get_http_status(), 'password');
    } catch (\Throwable $e) {
        return wporg_credentials_error('svn_auth_check_failed', __('wordpress.org SVN returned an unexpected authentication response.', 'peak-publisher'), 502, 'password');
    }
}


/** Resolves the account's avatar anew; '' when wordpress.org does not answer (the UI shows its placeholder). */
function refresh_wporg_account_avatar(\WP_Post $account): void {
    update_post_meta((int) $account->ID, '_pblsh_avatar_url', (string) wporg_profile_avatar_url((string) $account->post_title));
}


/**
 * The one place an account is connected or changed: validates the username, probes the
 * credentials against wordpress.org (deliberate last gate before persisting — a password
 * that fails here would otherwise fail much later, at deploy time), settles the
 * username's spelling when it is new or changed, encrypts, stores, resolves the avatar.
 * $password '' keeps the stored password of $account, which must still decrypt.
 *
 * @return array{account:\WP_Post, spelling:array|null}|\WP_Error
 */
function save_wporg_account(?\WP_Post $account, $username, string $password) {
    // Up to eight remote requests (probe, plugins, three logs, avatar) on a slow day.
    raise_wporg_time_limit();

    $username = normalize_wporg_username($username, 'username');
    if (is_wp_error($username)) {
        return $username;
    }

    $other = find_wporg_account($username);
    if ($other !== null && ($account === null || (int) $other->ID !== (int) $account->ID)) {
        return wporg_credentials_error('duplicate_username', __('This wordpress.org account is already connected.', 'peak-publisher'), 409, 'username');
    }

    $keeps_stored_password = $password === '';
    if ($keeps_stored_password) {
        $stored = $account === null ? null : wporg_account_password($account);
        if (!is_string($stored)) {
            return wporg_credentials_error('password_required', __('A password is required for this wordpress.org account.', 'peak-publisher'), 400, 'password');
        }
        $password = $stored;
    } else {
        // Credentials are only accepted when they can be stored securely.
        $storage_error = get_credential_storage_error();
        if ($storage_error !== null) {
            return $storage_error;
        }
    }

    $probe_error = probe_wporg_credentials($username, $password);
    if ($probe_error !== null) {
        return $probe_error;
    }

    $spelling = null;
    if ($account === null || $account->post_title !== $username) {
        require_once PBLSH_PLUGIN_DIR . 'classes/WporgOperations.php';
        $settled = wporg_username_spelling($username, WporgOperations::proven_username_spelling($username));
        $username = $settled['username'];
        $spelling = $settled['spelling'];
    }

    $encrypted = null;
    if (!$keeps_stored_password) {
        $encrypted = encrypt_wporg_password($password);
        if (is_wp_error($encrypted)) {
            return $encrypted->get_error_code() === 'credential_encrypt_failed' ? wporg_error_with_field($encrypted, 'password') : $encrypted;
        }
    }

    $account = store_wporg_account($account, $username, $encrypted);
    if (is_wp_error($account)) {
        return $account;
    }
    refresh_wporg_account_avatar($account);
    return [ 'account' => $account, 'spelling' => $spelling ];
}


/**
 * The account as the admin UI sees it: never the ciphertext, but whether it still
 * decrypts (false after a key-material change — the UI then asks for the password
 * again instead of failing at deploy time) and wordpress.org's latest verdict.
 */
function wporg_account_for_api(\WP_Post $account): array {
    $verdict = wporg_account_verdict($account);
    return [
        'id' => (int) $account->ID,
        'username' => (string) $account->post_title,
        'password_usable' => wporg_account_password_is_usable($account),
        'verified_at' => $verdict['verified_at'],
        'credentials_rejected' => $verdict['rejected_at'] !== null && $verdict['rejected_at'] >= (int) $verdict['verified_at'],
        'avatar_url' => wporg_string_from_value(get_post_meta((int) $account->ID, '_pblsh_avatar_url', true)),
    ];
}


/**
 * What every accounts endpoint answers: the accounts and whether credential storage works.
 *
 * @return array{accounts:array, storage:array{status:string, message:string|null}}
 */
function wporg_accounts_payload(): array {
    return [
        'accounts' => array_map(__NAMESPACE__ . '\\wporg_account_for_api', get_wporg_accounts()),
        'storage' => get_credential_storage_status(),
    ];
}
