<?php

namespace Pblsh;

defined('ABSPATH') || exit;


function wporg_credentials_error(string $code, string $message, int $status, ?string $field = null): \WP_Error {
    $data = [
        'status' => $status,
    ];
    if ($field !== null && $field !== '') {
        $data['field'] = $field;
    }
    return new \WP_Error($code, $message, $data);
}


/**
 * Returns the configured 32-byte base encryption key.
 *
 * @return string|\WP_Error
 */
function get_encryption_key() {
    if (!defined('PBLSH_ENCRYPTION_KEY')) {
        return wporg_credentials_error(
            'encryption_key_missing',
            __('PBLSH_ENCRYPTION_KEY is not defined.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

    $configured = constant('PBLSH_ENCRYPTION_KEY');
    if (!is_string($configured) || !str_starts_with($configured, 'base64:')) {
        return wporg_credentials_error(
            'encryption_key_invalid',
            __('PBLSH_ENCRYPTION_KEY is defined but invalid. It must use the base64: format with a 32-byte key.', 'peak-publisher'),
            400,
            'wporg_credentials.encryption_key'
        );
    }

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
    $key = get_encryption_key();
    if (!is_wp_error($key)) {
        return [
            'status' => 'valid',
            'message' => null,
        ];
    }

    $code = $key->get_error_code();
    return [
        'status' => $code === 'encryption_key_missing' ? 'missing' : 'invalid',
        'message' => $key->get_error_message(),
    ];
}


function generate_encryption_key_snippet(): string {
    return "define('PBLSH_ENCRYPTION_KEY', 'base64:" . base64_encode(random_bytes(32)) . "');";
}
