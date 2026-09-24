<?php

namespace Pblsh;

defined('ABSPATH') || exit;


const WPORG_PASSWORD_MASKED = '__MASKED__';
const WPORG_ENCRYPTED_PREFIX = 'aes256gcm:';
const WPORG_CREDENTIAL_AAD = 'peak-publisher/wporg-credentials/v1';


function wporg_credentials_error(string $code, string $message, int $status, ?string $field = null): \WP_Error {
    // Store the HTTP status with the error so REST handlers can respond correctly.
    $data = [
        'status' => $status,
    ];
    // Attach the input field path when the UI can highlight a specific value.
    if ($field !== null && $field !== '') {
        $data['field'] = $field;
    }
    return new \WP_Error($code, $message, $data);
}


function wporg_error_with_field(\WP_Error $error, string $field): \WP_Error {
    // Normalize arbitrary WP_Error data into an array before adding metadata.
    $data = $error->get_error_data();
    if (!is_array($data)) {
        $data = [];
    }
    // Preserve an existing field path if the lower-level error already set one.
    if (empty($data['field'])) {
        $data['field'] = $field;
    }
    return new \WP_Error($error->get_error_code(), $error->get_error_message(), $data);
}


function wporg_string_from_value($value): string {
    // Accept only real strings so credentials are not silently coerced.
    return is_string($value) ? $value : '';
}


function wporg_is_encrypted_password(string $password): bool {
    // Check the explicit storage prefix before treating a value as ciphertext.
    return str_starts_with($password, WPORG_ENCRYPTED_PREFIX);
}


/**
 * Decodes a "base64:" prefixed key string into exactly 32 raw bytes.
 *
 * @return string|null Null when the value is not a valid key string.
 */
function decode_prefixed_key($value): ?string {
    // The prefix makes stored key values self-describing and easy to validate.
    if (!is_string($value) || !str_starts_with($value, 'base64:')) {
        return null;
    }

    // AES-256-GCM requires exactly 32 raw bytes of key material.
    $decoded = base64_decode(substr($value, 7), true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
}


function get_encryption_key_file_path(): string {
    return trailingslashit(peak_publisher_upload_basedir()) . 'encryption-key.php';
}


/**
 * Returns the automatically managed file-based key (32 raw bytes).
 *
 * The key lives as a PHP file inside the secured plugin upload dir, so it is
 * never served as plain text — Apache is covered by the deny-all .htaccess,
 * nginx executes the file and hits the ABSPATH guard.
 *
 * @return string|\WP_Error
 */
function get_file_encryption_key(bool $create) {
    $path = get_encryption_key_file_path();

    if (!file_exists($path)) {
        // Decryption must never invent a fresh key: without the original file the ciphertexts are lost anyway.
        if (!$create) {
            return wporg_credentials_error(
                'credential_storage_unavailable',
                __('The credential encryption key file is missing.', 'peak-publisher'),
                500,
                'wporg_credentials.storage'
            );
        }

        ensure_upload_dir_is_ready_and_secured();
        $content = "<?php\n"
            . "defined('ABSPATH') || exit;\n"
            . "return 'base64:" . base64_encode(random_bytes(32)) . "';\n";
        // Exclusive create keeps concurrent first-time saves from overwriting each other's key.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            // A partial write (e.g. disk full) must not leave a fragment behind —
            // it would fail every later read permanently. Removed is only what
            // this call just created; the next save retries cleanly.
            $written = fwrite($handle, $content);
            $closed = fclose($handle);
            if ($written !== strlen($content) || !$closed) {
                @unlink($path);
                return wporg_credentials_error(
                    'credential_storage_unavailable',
                    __('The credential encryption key file could not be created.', 'peak-publisher'),
                    500,
                    'wporg_credentials.storage'
                );
            }
            @chmod($path, 0600);
        } elseif (!file_exists($path)) {
            return wporg_credentials_error(
                'credential_storage_unavailable',
                __('The credential encryption key file could not be created.', 'peak-publisher'),
                500,
                'wporg_credentials.storage'
            );
        }
    }

    $key = decode_prefixed_key(@include $path);
    if ($key === null) {
        return wporg_credentials_error(
            'credential_storage_unavailable',
            __('The credential encryption key file is unreadable or invalid.', 'peak-publisher'),
            500,
            'wporg_credentials.storage'
        );
    }

    return $key;
}


/**
 * Returns the optional wp-config hardening key (32 raw bytes), null when not configured.
 *
 * @return string|null|\WP_Error
 */
function get_config_encryption_key() {
    // The constant is an optional hardening layer, documented in the plugin FAQ.
    if (!defined('PBLSH_ENCRYPTION_KEY')) {
        return null;
    }

    $key = decode_prefixed_key(constant('PBLSH_ENCRYPTION_KEY'));
    if ($key === null) {
        return wporg_credentials_error(
            'encryption_key_invalid',
            __('PBLSH_ENCRYPTION_KEY is defined but invalid. It must use the base64: format with a 32-byte key.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

    return $key;
}


/**
 * Returns the combined base key material: file key, extended by the optional wp-config key.
 *
 * There is exactly one scheme — adding or removing the wp-config constant changes the
 * material, existing ciphertexts fail authentication, and the UI asks for the password again.
 *
 * @return string|\WP_Error
 */
function get_encryption_key(bool $create) {
    $file_key = get_file_encryption_key($create);
    if (is_wp_error($file_key)) {
        return $file_key;
    }

    $config_key = get_config_encryption_key();
    if (is_wp_error($config_key)) {
        return $config_key;
    }

    return $file_key . ($config_key ?? '');
}


/**
 * Returns the defect that makes credential storage inoperable, null when storage works.
 *
 * A missing key file is not a defect — it is created on the first save. Checking
 * therefore never creates the file.
 *
 * @return \WP_Error|null
 */
function get_credential_storage_error(): ?\WP_Error {
    if (file_exists(get_encryption_key_file_path())) {
        $file_key = get_file_encryption_key(false);
        if (is_wp_error($file_key)) {
            return $file_key;
        }
    }

    $config_key = get_config_encryption_key();
    if (is_wp_error($config_key)) {
        return $config_key;
    }

    return null;
}


/**
 * Reports whether credential storage is operational, for the account form UI.
 *
 * @return array{status:'ok'|'error', message:string|null}
 */
function get_credential_storage_status(): array {
    $error = get_credential_storage_error();
    return $error === null
        ? ['status' => 'ok', 'message' => null]
        : ['status' => 'error', 'message' => $error->get_error_message()];
}


/**
 * Returns the site-local encryption context id.
 *
 * @return string|\WP_Error
 */
function get_encryption_context_id(bool $create = true) {
    // Reuse the site-local context so encrypted values remain stable over time.
    $context_id = get_option('pblsh_encryption_context_id', '');
    if (is_string($context_id) && $context_id !== '') {
        return $context_id;
    }

    // Read-only callers must not create a context as a side effect.
    if (!$create) {
        return wporg_credentials_error(
            'encryption_context_failed',
            __('The wporg credential encryption context is missing.', 'peak-publisher'),
            500,
            'wporg_credentials.encryption_context'
        );
    }

    // Create a random context id to bind ciphertexts to this WordPress site.
    try {
        $context_id = bin2hex(random_bytes(16));
    } catch (\Throwable $e) {
        return wporg_credentials_error(
            'encryption_context_failed',
            __('Could not create the wporg credential encryption context.', 'peak-publisher'),
            500,
            'wporg_credentials.encryption_context'
        );
    }

    // Add the option atomically so concurrent requests can share one context.
    $added = add_option('pblsh_encryption_context_id', $context_id, '', false);
    if ($added) {
        return $context_id;
    }

    // If another request won the race, load the context it created.
    $context_id = get_option('pblsh_encryption_context_id', '');
    if (is_string($context_id) && $context_id !== '') {
        return $context_id;
    }

    // Fail closed if no usable context exists after the atomic add attempt.
    return wporg_credentials_error(
        'encryption_context_failed',
        __('Could not load the wporg credential encryption context.', 'peak-publisher'),
        500,
        'wporg_credentials.encryption_context'
    );
}


function derive_site_encryption_key(string $base_key, string $context_id): string {
    // Derive a site-specific data key from the configured base key.
    return hash_hkdf('sha256', $base_key, 32, WPORG_CREDENTIAL_AAD, $context_id);
}


/**
 * @return string|\WP_Error
 */
function encrypt_wporg_password(string $plain) {
    // Validate the configured base key before creating any ciphertext.
    $base_key = get_encryption_key(true);
    if (is_wp_error($base_key)) {
        return $base_key;
    }

    // Create the site context only when a real password is being encrypted.
    $context_id = get_encryption_context_id(true);
    if (is_wp_error($context_id)) {
        return $context_id;
    }

    // Generate a unique nonce for AES-GCM encryption.
    try {
        $iv = random_bytes(12);
    } catch (\Throwable $e) {
        return wporg_credentials_error(
            'credential_encrypt_failed',
            __('Could not encrypt the wordpress.org password.', 'peak-publisher'),
            500
        );
    }

    // Encrypt the password with authenticated encryption and fixed AAD.
    $data_key = derive_site_encryption_key($base_key, $context_id);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $data_key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        WPORG_CREDENTIAL_AAD,
        16
    );

    // Reject partial or unauthenticated encryption results.
    if (!is_string($ciphertext) || !is_string($tag) || strlen($tag) !== 16) {
        return wporg_credentials_error(
            'credential_encrypt_failed',
            __('Could not encrypt the wordpress.org password.', 'peak-publisher'),
            500
        );
    }

    // Store IV, tag, and ciphertext together behind a recognizable prefix.
    return WPORG_ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
}


/**
 * @return string|\WP_Error
 */
function decrypt_wporg_password(string $encrypted) {
    // Decryption must not create a missing key file behind the user's back.
    $base_key = get_encryption_key(false);
    if (is_wp_error($base_key)) {
        return $base_key;
    }

    // Only values with the expected prefix are treated as encrypted passwords.
    if (!wporg_is_encrypted_password($encrypted)) {
        return wporg_credentials_error(
            'credential_decrypt_failed',
            __('Stored wordpress.org credentials could not be decrypted.', 'peak-publisher'),
            400
        );
    }

    // Decode and minimally validate the stored IV, tag, and ciphertext payload.
    $payload = base64_decode(substr($encrypted, strlen(WPORG_ENCRYPTED_PREFIX)), true);
    if (!is_string($payload) || strlen($payload) < 29) {
        return wporg_credentials_error(
            'credential_decrypt_failed',
            __('Stored wordpress.org credentials could not be decrypted.', 'peak-publisher'),
            400
        );
    }

    // Decryption must not create a missing context behind the user's back.
    $context_id = get_encryption_context_id(false);
    if (is_wp_error($context_id)) {
        return wporg_credentials_error(
            'credential_decrypt_failed',
            __('Stored wordpress.org credentials could not be decrypted.', 'peak-publisher'),
            400
        );
    }

    // Split the packed payload back into the AES-GCM parameters.
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    // Use the same site-specific derived key that was used for encryption.
    $data_key = derive_site_encryption_key($base_key, $context_id);
    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $data_key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        WPORG_CREDENTIAL_AAD
    );

    // Authentication failure or malformed ciphertext must fail closed.
    if (!is_string($plain)) {
        return wporg_credentials_error(
            'credential_decrypt_failed',
            __('Stored wordpress.org credentials could not be decrypted.', 'peak-publisher'),
            400
        );
    }

    return $plain;
}


/**
 * @return string|\WP_Error
 */
function normalize_wporg_username($username, ?string $field = null) {
    // Trim admin input while rejecting non-string values as empty.
    $trimmed = trim(wporg_string_from_value($username));
    if ($trimmed === '') {
        return wporg_credentials_error(
            'invalid_username',
            __('Invalid wordpress.org username.', 'peak-publisher'),
            400,
            $field
        );
    }

    // Let WordPress validate the username without silently changing it.
    $sanitized = sanitize_user($trimmed, true);
    if ($sanitized !== $trimmed) {
        return wporg_credentials_error(
            'invalid_username',
            __('Invalid wordpress.org username.', 'peak-publisher'),
            400,
            $field
        );
    }

    return $sanitized;
}


/**
 * Finds the stored account row matching an already-normalized username.
 *
 * @return int|string|null Key of the matching row, null when absent.
 */
function find_wporg_account_index(array $accounts, string $normalized_username) {
    foreach ($accounts as $index => $account) {
        // Skip malformed stored rows.
        if (!is_array($account)) {
            continue;
        }
        $stored_username = normalize_wporg_username($account['username'] ?? null);
        if (!is_wp_error($stored_username) && $stored_username === $normalized_username) {
            return $index;
        }
    }
    return null;
}


/**
 * Resolves API masking/preserve signals against the currently stored option.
 *
 * @return array|\WP_Error
 */
function resolve_masked_wporg_passwords(array $incoming, array $current) {
    // Preserve stored accounts when the request did not include the account field.
    if (!array_key_exists('wporg_accounts', $incoming)) {
        $incoming['wporg_accounts'] = is_array($current['wporg_accounts'] ?? null) ? $current['wporg_accounts'] : [];
        return $incoming;
    }

    $current_accounts = is_array($current['wporg_accounts'] ?? null) ? $current['wporg_accounts'] : [];

    // Normalize incoming account rows to a sequential list for field paths.
    $resolved_accounts = [];
    $incoming_accounts = is_array($incoming['wporg_accounts']) ? array_values($incoming['wporg_accounts']) : [];
    foreach ($incoming_accounts as $index => $account) {
        // Treat malformed incoming rows as empty rows.
        if (!is_array($account)) {
            $account = [];
        }

        // Extract raw strings without coercing non-string input.
        $username_raw = wporg_string_from_value($account['username'] ?? '');
        $password = wporg_string_from_value($account['password'] ?? '');
        $username_trimmed = trim($username_raw);
        $username_field = 'wporg_accounts.' . $index . '.username';
        $password_field = 'wporg_accounts.' . $index . '.password';

        // Skip incoming rows that have only empty fields (no username and no password).
        if ($username_trimmed === '' && ($password === '' || $password === WPORG_PASSWORD_MASKED)) {
            continue;
        }

        // Resolve masked (or preserved encrypted) passwords against stored data.
        if ($password === WPORG_PASSWORD_MASKED || wporg_is_encrypted_password($password)) {
            $normalized = normalize_wporg_username($username_raw, $username_field);
            if (is_wp_error($normalized)) {
                return $normalized;
            }

            // Look up the stored encrypted password for this normalized user.
            $stored_index = find_wporg_account_index($current_accounts, $normalized);
            $stored_password = $stored_index !== null
                ? wporg_string_from_value($current_accounts[$stored_index]['password'] ?? '')
                : '';

            // Replace the UI mask with the stored encrypted password.
            if ($password === WPORG_PASSWORD_MASKED) {
                if ($stored_password === '') {
                    return wporg_credentials_error(
                        'password_required',
                        __('A password is required for this wordpress.org account.', 'peak-publisher'),
                        400,
                        $password_field
                    );
                }
                $account['username'] = $normalized;
                $account['password'] = $stored_password;
            } elseif ($stored_password !== $password) {
                // Reject encrypted payloads that do not match the stored value.
                return wporg_credentials_error(
                    'credential_decrypt_failed',
                    __('Submitted wordpress.org credentials cannot be preserved.', 'peak-publisher'),
                    400,
                    $password_field
                );
            } else {
                // Keep the stored encrypted password and normalize the username.
                $account['username'] = $normalized;
            }
        }

        // Pass resolved rows to the save-path validator.
        $resolved_accounts[] = $account;
    }

    // Replace the incoming account payload with mask-resolved rows.
    $incoming['wporg_accounts'] = $resolved_accounts;
    return $incoming;
}


/**
 * Validates, verifies and encrypts already mask-resolved wporg accounts.
 * $current_accounts (the stored rows) carries the credential-verdict stamps of
 * unchanged accounts through the save.
 *
 * @return array|\WP_Error
 */
function sanitize_wporg_accounts(array $resolved_accounts, array $current_accounts = []) {
    // Validate all rows before encrypting so storage is updated atomically.
    $validated = [];
    $seen_usernames = [];

    foreach (array_values($resolved_accounts) as $index => $account) {
        // Treat malformed rows as empty rows.
        if (!is_array($account)) {
            $account = [];
        }

        // Extract raw strings without coercing non-string input.
        $username_raw = wporg_string_from_value($account['username'] ?? '');
        $password = wporg_string_from_value($account['password'] ?? '');
        $username_field = 'wporg_accounts.' . $index . '.username';
        $password_field = 'wporg_accounts.' . $index . '.password';
        $username_trimmed = trim($username_raw);

        // Ignore completely empty rows from the account editor.
        if ($username_trimmed === '' && ($password === '' || $password === WPORG_PASSWORD_MASKED)) {
            continue;
        }

        // Normalize and validate the username for storage.
        $username = normalize_wporg_username($username_raw, $username_field);
        if (is_wp_error($username)) {
            return $username;
        }

        // Saving an account requires a real password after mask resolution.
        if ($password === '' || $password === WPORG_PASSWORD_MASKED) {
            return wporg_credentials_error(
                'password_required',
                __('A password is required for this wordpress.org account.', 'peak-publisher'),
                400,
                $password_field
            );
        }

        // Prevent ambiguous lookups by enforcing unique usernames.
        if (isset($seen_usernames[$username])) {
            return wporg_credentials_error(
                'duplicate_username',
                __('This wordpress.org username is configured more than once.', 'peak-publisher'),
                400,
                $username_field
            );
        }
        $seen_usernames[$username] = true;

        // Defer encryption until every row has passed validation.
        $validated[] = [
            'username' => $username,
            'password' => $password,
            'password_field' => $password_field,
            'encrypt' => !wporg_is_encrypted_password($password),
        ];
    }

    // New plaintext passwords are verified against wordpress.org before anything
    // is stored — the account card's "Verified" state must rest on a real verdict.
    // Deliberate last gate before persisting credentials that would otherwise only
    // fail much later at deploy time.
    require_once PBLSH_PLUGIN_DIR . 'classes/WporgPluginSvnClient.php';
    foreach ($validated as &$account) {
        if (empty($account['encrypt'])) {
            continue;
        }
        try {
            $client = new WporgPluginSvnClient($account['username'], $account['password']);
            $client->test_credentials();
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'invalid_credentials') {
                return wporg_credentials_error(
                    'invalid_credentials',
                    __('wordpress.org rejected these SVN credentials. The account was not saved.', 'peak-publisher'),
                    401,
                    $account['password_field']
                );
            }
            return wporg_credentials_error(
                'credentials_check_failed',
                __('Could not verify the credentials with wordpress.org. The account was not saved — please try again.', 'peak-publisher'),
                502,
                $account['password_field']
            );
        } catch (\Throwable $e) {
            return wporg_credentials_error(
                'credentials_check_failed',
                __('Could not verify the credentials with wordpress.org. The account was not saved — please try again.', 'peak-publisher'),
                502,
                $account['password_field']
            );
        }
        $account['verified_at'] = time();
    }
    unset($account);

    // Encrypt new plaintext passwords and preserve existing ciphertexts.
    $out = [];
    foreach ($validated as $account) {
        $password = $account['password'];
        if (!empty($account['encrypt'])) {
            $encrypted = encrypt_wporg_password($password);
            if (is_wp_error($encrypted)) {
                // Attach the password field path when encryption fails.
                if ($encrypted->get_error_code() === 'credential_encrypt_failed') {
                    return wporg_error_with_field($encrypted, $account['password_field']);
                }
                return $encrypted;
            }
            $password = $encrypted;
        }

        // Store only the normalized username, encrypted password and verdict stamps.
        $row = [
            'username' => $account['username'],
            'password' => $password,
        ];
        if (!empty($account['verified_at'])) {
            // Freshly verified in this save — starts with a clean slate.
            $row['verified_at'] = $account['verified_at'];
        } else {
            // Stored verdict stamps of unchanged accounts survive the save.
            $stored_index = find_wporg_account_index($current_accounts, $account['username']);
            if ($stored_index !== null) {
                $stored = $current_accounts[$stored_index];
                if (!empty($stored['verified_at'])) {
                    $row['verified_at'] = (int) $stored['verified_at'];
                }
                if (!empty($stored['rejected_at'])) {
                    $row['rejected_at'] = (int) $stored['rejected_at'];
                }
            }
        }
        $out[] = $row;
    }

    return $out;
}


/**
 * @return array{username:string,password:string}|null|\WP_Error
 */
function get_wporg_credentials(string $username) {
    // Normalize the requested username before matching stored accounts.
    $normalized = normalize_wporg_username($username);
    if (is_wp_error($normalized)) {
        return $normalized;
    }

    // Load stored accounts directly to avoid the masked settings API output.
    $settings = get_option('pblsh_settings');
    $accounts = is_array($settings) && is_array($settings['wporg_accounts'] ?? null) ? $settings['wporg_accounts'] : [];
    $index = find_wporg_account_index($accounts, $normalized);
    if ($index === null) {
        // No account exists for this username.
        return null;
    }

    // Decrypt only the selected account's password.
    $password = decrypt_wporg_password(wporg_string_from_value($accounts[$index]['password'] ?? ''));
    if (is_wp_error($password)) {
        return $password;
    }

    // Return plaintext only to internal backend callers.
    return [
        'username' => $normalized,
        'password' => $password,
    ];
}


/**
 * Reports whether a stored password actually decrypts with the current key material.
 * Re-validation when reading back from persistence: the key file or the wp-config
 * constant may have changed since the ciphertext was written.
 */
function wporg_password_is_usable($stored_password): bool {
    $password = wporg_string_from_value($stored_password);
    if (!wporg_is_encrypted_password($password)) {
        return false;
    }
    return !is_wp_error(decrypt_wporg_password($password));
}


/**
 * @return string[] Normalized usernames of all stored wordpress.org accounts whose password decrypts.
 */
function get_usable_wporg_account_usernames(): array {
    $settings = get_option('pblsh_settings');
    $accounts = is_array($settings) && is_array($settings['wporg_accounts'] ?? null) ? $settings['wporg_accounts'] : [];
    $usernames = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $username = normalize_wporg_username($account['username'] ?? null);
        if (is_wp_error($username) || isset($usernames[$username])) {
            continue;
        }
        if (!wporg_password_is_usable($account['password'] ?? '')) {
            continue;
        }
        $usernames[$username] = true;
    }

    return array_keys($usernames);
}


/**
 * The one place that picks the stored account for a wordpress.org operation:
 * the preferred account (the marker's last-deploy account, or the add-new
 * flow's intent) when it is usable, else the first usable account, else null.
 */
function select_wporg_account_username(?string $preferred_username = null): ?string {
    $usable = get_usable_wporg_account_usernames();
    if ($preferred_username !== null && trim($preferred_username) !== '') {
        $preferred = normalize_wporg_username($preferred_username, 'username');
        if (!is_wp_error($preferred) && in_array($preferred, $usable, true)) {
            return $preferred;
        }
    }
    return $usable[0] ?? null;
}


/**
 * Records wordpress.org's latest verdict about a stored account's credentials —
 * called at the places the verdict actually happens (save/test probes, upload
 * access checks, deploys; MKACTIVITY/MERGE responses). Reads never validate
 * credentials and must not call this. Positive verdicts are throttled so
 * upload-dialog refreshes do not write the option over and over; explicit
 * user-requested probes pass $force to always refresh the timestamp — the
 * badge jump is their visible success feedback.
 */
function record_wporg_credentials_verdict(string $username, bool $verified, bool $force = false): void {
    $normalized = normalize_wporg_username($username);
    if (is_wp_error($normalized)) {
        return;
    }

    $settings = get_option('pblsh_settings');
    if (!is_array($settings) || !is_array($settings['wporg_accounts'] ?? null)) {
        return;
    }

    $index = find_wporg_account_index($settings['wporg_accounts'], $normalized);
    if ($index === null) {
        return;
    }
    $account = $settings['wporg_accounts'][$index];

    if ($verified) {
        $recently_verified = (int) ($account['verified_at'] ?? 0) > time() - 15 * MINUTE_IN_SECONDS;
        if (!$force && $recently_verified && empty($account['rejected_at'])) {
            return;
        }
        $settings['wporg_accounts'][$index]['verified_at'] = time();
        unset($settings['wporg_accounts'][$index]['rejected_at']);
    } else {
        $settings['wporg_accounts'][$index]['rejected_at'] = time();
    }

    update_option('pblsh_settings', $settings, false);
}


function get_wporg_accounts_for_api(array $stored_accounts): array {
    // Build masked account data for REST responses.
    $out = [];
    foreach ($stored_accounts as $account) {
        // Ignore malformed stored rows in the read path.
        if (!is_array($account)) {
            continue;
        }

        // Never expose the stored ciphertext to the browser.
        $username = wporg_string_from_value($account['username'] ?? '');
        $password = wporg_string_from_value($account['password'] ?? '');
        $has_password = wporg_is_encrypted_password($password);

        $verified_at = (int) ($account['verified_at'] ?? 0);
        $rejected_at = (int) ($account['rejected_at'] ?? 0);

        // Use the mask token only when a real encrypted password exists.
        $out[] = [
            'username' => $username,
            'password' => $has_password ? WPORG_PASSWORD_MASKED : '',
            'has_password' => $has_password,
            // False after a key-material change (wp-config constant added/removed, key file lost):
            // the UI then asks for the password again instead of failing at deploy time.
            'password_usable' => $has_password && wporg_password_is_usable($password),
            // wordpress.org's latest credential verdict: when it last accepted the
            // stored credentials, and whether a rejection happened since then.
            'verified_at' => $verified_at > 0 ? $verified_at : null,
            'credentials_rejected' => $rejected_at > 0 && $rejected_at >= $verified_at,
        ];
    }

    return $out;
}
