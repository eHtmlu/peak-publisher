<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Gets settings needed during early plugin bootstrap.
 *
 * These two flags are read on every request (standalone gating), so they get
 * their own statically cached path instead of the full settings builder.
 *
 * @return array{standalone_mode:bool,standalone_redirect_url:string}
 */
function get_peak_publisher_boot_settings(): array {
    static $boot_settings = null;
    if ($boot_settings !== null) {
        return $boot_settings;
    }

    $raw = get_option('pblsh_settings');
    $data = is_array($raw) ? $raw : [];
    $redirect_url = trim((string) ($data['standalone_redirect_url'] ?? ''));

    $boot_settings = [
        'standalone_mode' => (bool) ($data['standalone_mode'] ?? false),
        'standalone_redirect_url' => $redirect_url !== '' ? esc_url_raw($redirect_url) : '',
    ];

    return $boot_settings;
}


/**
 * Checks if the operating mode is standalone.
 */
function is_standalone(): bool {
    static $is_standalone = null;
    if ($is_standalone === null) {
        $is_standalone = get_peak_publisher_boot_settings()['standalone_mode'];
    }
    return $is_standalone;
}


/**
 * Gets default plugin settings.
 */
function get_peak_publisher_settings_defaults(): array {
    return [
        'standalone_mode' => false,
        'auto_remove_workspace_artifacts' => true,
        'readme_txt_convert_to_utf8_without_bom' => true,
        'count_plugin_installations' => true,
        'wordspace_artifacts_to_remove' => [
            '.git',
            '.gitignore',
            '.gitattributes',
            '.github',
            '.svn',
            '.idea',
            '.vscode',
            'node_modules',
            'npm-debug.log',
            'yarn.lock',
            'package-lock.json',
            'composer.lock',
            'composer.json',
            'Thumbs.db',
            'desktop.ini',
            '__MACOSX',
            '.env',
            '.env.*',
            '*.log',
            '*.tmp',
            '*.bak',
            '*.orig',
            '.DS_Store*',
            '._*',
        ],
        'ip_whitelist' => [],
        'standalone_redirect_url' => '',
    ];
}

/**
 * Gets the operational plugin settings (defaults + sanitized option).
 *
 * Runs on every public request (IP whitelist, install counting), so it stays
 * free of credential metadata — that lives in get_peak_publisher_settings_for_api().
 */
function get_peak_publisher_settings(): array {
    $defaults = get_peak_publisher_settings_defaults();
    $raw = get_option('pblsh_settings');
    $data = is_array($raw) ? $raw : [];
    $merged = array_merge($defaults, $data);
    return sanitize_peak_publisher_non_secret_settings($merged);
}

/**
 * The settings shape served to the admin UI: the operational settings plus the
 * credential metadata (masked accounts with usability probes, storage status) —
 * per-account decrypt probes make this too expensive for the public path.
 */
function get_peak_publisher_settings_for_api(): array {
    $out = get_peak_publisher_settings();

    $raw = get_option('pblsh_settings');
    $stored_accounts = is_array($raw['wporg_accounts'] ?? null) ? $raw['wporg_accounts'] : [];
    $out['wporg_accounts'] = get_wporg_accounts_for_api($stored_accounts);

    $storage_status = get_credential_storage_status();
    $out['wporg_credentials'] = [
        'storage_status' => $storage_status['status'],
        'storage_message' => $storage_status['message'],
    ];

    return $out;
}

/**
 * Updates the plugin settings.
 *
 * @return true|\WP_Error
 */
function update_peak_publisher_settings(array $settings) {
    unset($settings['wporg_credentials']);

    $current = get_option('pblsh_settings');
    $current = is_array($current) ? $current : [];

    $resolved = resolve_masked_wporg_passwords($settings, $current);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $sanitized = sanitize_peak_publisher_settings($resolved, $current);
    if (is_wp_error($sanitized)) {
        return $sanitized;
    }

    update_option('pblsh_settings', $sanitized, false);
    return true;
}

/**
 * Sanitizes non-secret settings without touching stored credentials.
 */
function sanitize_peak_publisher_non_secret_settings(array $settings): array {
    $out = [];
    $out['standalone_mode'] = (bool) ($settings['standalone_mode'] ?? false);
    $out['auto_remove_workspace_artifacts'] = (bool) ($settings['auto_remove_workspace_artifacts'] ?? true);
    $out['readme_txt_convert_to_utf8_without_bom'] = (bool) ($settings['readme_txt_convert_to_utf8_without_bom'] ?? true);
    $out['count_plugin_installations'] = (bool) ($settings['count_plugin_installations'] ?? true);
    $wordspace_artifacts_to_remove = $settings['wordspace_artifacts_to_remove'] ?? [];
    if (!is_array($wordspace_artifacts_to_remove)) {
        $wordspace_artifacts_to_remove = [];
    }
    $out['wordspace_artifacts_to_remove'] = array_values(array_filter(array_map(function($v){
        $v = trim((string) $v);
        $v = wp_basename($v);
        return $v !== '' ? $v : null;
    }, $wordspace_artifacts_to_remove)));
    $ips = $settings['ip_whitelist'] ?? [];
    if (!is_array($ips)) {
        $ips = [];
    }
    $out['ip_whitelist'] = array_values(array_filter(array_map(function($ip){
        return trim((string) $ip);
    }, $ips)));
    $redirect_url = trim((string) ($settings['standalone_redirect_url'] ?? ''));
    $out['standalone_redirect_url'] = $redirect_url !== '' ? esc_url_raw($redirect_url) : '';
    return $out;
}

/**
 * Sanitizes the plugin settings for the save path. $current (the stored option)
 * carries the accounts' credential-verdict stamps through the save.
 *
 * @return array|\WP_Error
 */
function sanitize_peak_publisher_settings(array $settings, array $current = []) {
    $out = sanitize_peak_publisher_non_secret_settings($settings);

    $accounts = is_array($settings['wporg_accounts'] ?? null) ? $settings['wporg_accounts'] : [];
    $current_accounts = is_array($current['wporg_accounts'] ?? null) ? $current['wporg_accounts'] : [];
    $sanitized_accounts = sanitize_wporg_accounts($accounts, $current_accounts);
    if (is_wp_error($sanitized_accounts)) {
        return $sanitized_accounts;
    }
    $out['wporg_accounts'] = $sanitized_accounts;

    return $out;
}
