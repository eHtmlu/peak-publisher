<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The cipher behind the wordpress.org passwords: the key material (file key, optional
 * wp-config key, site context), encrypt/decrypt and the storage status. The accounts
 * themselves live in wporg_accounts.php.
 */

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
        // Exclusive create keeps concurrent first-time saves from overwriting each other's key —
        // a mode WP_Filesystem has not, like the 0600 below: the key file is written directly.
        $handle = @fopen($path, 'x'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above
        if ($handle !== false) {
            // A partial write (e.g. disk full) must not leave a fragment behind —
            // it would fail every later read permanently. Removed is only what
            // this call just created; the next save retries cleanly.
            $written = fwrite($handle, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above
            $closed = fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above
            if ($written !== strlen($content) || !$closed) {
                wp_delete_file($path);
                return wporg_credentials_error(
                    'credential_storage_unavailable',
                    __('The credential encryption key file could not be created.', 'peak-publisher'),
                    500,
                    'wporg_credentials.storage'
                );
            }
            @chmod($path, 0600); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- see above
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
