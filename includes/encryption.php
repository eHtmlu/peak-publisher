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
 * Returns the configured 32-byte base encryption key.
 *
 * @return string|\WP_Error
 */
function get_encryption_key() {
    // A missing constant means credentials cannot be safely stored or decrypted.
    if (!defined('PBLSH_ENCRYPTION_KEY')) {
        return wporg_credentials_error(
            'encryption_key_missing',
            __('PBLSH_ENCRYPTION_KEY is not defined.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

    // The prefix makes the configured value self-describing and easy to validate.
    $configured = constant('PBLSH_ENCRYPTION_KEY');
    if (!is_string($configured) || !str_starts_with($configured, 'base64:')) {
        return wporg_credentials_error(
            'encryption_key_invalid',
            __('PBLSH_ENCRYPTION_KEY is defined but invalid. It must use the base64: format with a 32-byte key.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

    // AES-256-GCM requires exactly 32 raw bytes of key material.
    $decoded = base64_decode(substr($configured, 7), true);
    if (!is_string($decoded) || strlen($decoded) !== 32) {
        return wporg_credentials_error(
            'encryption_key_invalid',
            __('PBLSH_ENCRYPTION_KEY is defined but invalid. It must decode to exactly 32 bytes.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

    return $decoded;
}


function get_encryption_key_status(): array {
    // Reuse the strict key validator and expose a UI-friendly status shape.
    $key = get_encryption_key();
    if (!is_wp_error($key)) {
        return [
            'status' => 'valid',
            'message' => null,
        ];
    }

    // Missing and invalid are separate states for setup guidance.
    $code = $key->get_error_code();
    return [
        'status' => $code === 'encryption_key_missing' ? 'missing' : 'invalid',
        'message' => $key->get_error_message(),
    ];
}


function generate_encryption_key_snippet(): string {
    // Generate a fresh 32-byte key for the wp-config.php setup snippet.
    return "define('PBLSH_ENCRYPTION_KEY', 'base64:" . base64_encode(random_bytes(32)) . "');";
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
    $base_key = get_encryption_key();
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
    // Validate the configured key before attempting to decrypt.
    $base_key = get_encryption_key();
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

    // Index current accounts by normalized username for mask resolution.
    $current_accounts = is_array($current['wporg_accounts'] ?? null) ? $current['wporg_accounts'] : [];
    $current_by_username = [];
    foreach ($current_accounts as $account) {
        // Ignore malformed stored rows instead of breaking the settings page.
        if (!is_array($account)) {
            continue;
        }
        // Skip stored rows with invalid usernames; they cannot resolve a mask.
        $normalized = normalize_wporg_username($account['username'] ?? null);
        if (is_wp_error($normalized)) {
            continue;
        }
        $current_by_username[$normalized] = $account;
    }

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
            $stored_password = '';
            if (isset($current_by_username[$normalized])) {
                $stored_password = wporg_string_from_value($current_by_username[$normalized]['password'] ?? '');
            }

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
 * Validates and encrypts already mask-resolved wporg accounts.
 *
 * @return array|\WP_Error
 */
function sanitize_wporg_accounts(array $resolved_accounts) {
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

        // Store only the normalized username and encrypted password.
        $out[] = [
            'username' => $account['username'],
            'password' => $password,
        ];
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
    foreach ($accounts as $account) {
        // Skip malformed stored rows.
        if (!is_array($account)) {
            continue;
        }
        // Match only the exact normalized wordpress.org username.
        $stored_username = normalize_wporg_username($account['username'] ?? null);
        if (is_wp_error($stored_username) || $stored_username !== $normalized) {
            continue;
        }

        // Decrypt only the selected account's password.
        $password = decrypt_wporg_password(wporg_string_from_value($account['password'] ?? ''));
        if (is_wp_error($password)) {
            return $password;
        }

        // Return plaintext only to internal backend callers.
        return [
            'username' => $normalized,
            'password' => $password,
        ];
    }

    // No account exists for this username.
    return null;
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

        // Use the mask token only when a real encrypted password exists.
        $out[] = [
            'username' => $username,
            'password' => $has_password ? WPORG_PASSWORD_MASKED : '',
            'has_password' => $has_password,
        ];
    }

    return $out;
}
