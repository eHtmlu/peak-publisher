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
        'count_plugin_installations' => true,
        'workspace_artifacts_to_remove' => [
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
 * Gets the plugin settings (defaults + sanitized option). Runs on every public request
 * (IP whitelist, install counting). The wordpress.org accounts are no setting — they
 * live in includes/wporg_accounts.php.
 */
function get_peak_publisher_settings(): array {
    $defaults = get_peak_publisher_settings_defaults();
    $raw = get_option('pblsh_settings');
    $data = is_array($raw) ? $raw : [];
    return sanitize_peak_publisher_settings(array_merge($defaults, $data));
}

/**
 * Updates the plugin settings.
 */
function update_peak_publisher_settings(array $settings): void {
    update_option('pblsh_settings', sanitize_peak_publisher_settings($settings), false);
}

/**
 * Sanitizes the plugin settings — the same shape on the read and the save path.
 */
function sanitize_peak_publisher_settings(array $settings): array {
    $out = [];
    $out['standalone_mode'] = (bool) ($settings['standalone_mode'] ?? false);
    $out['auto_remove_workspace_artifacts'] = (bool) ($settings['auto_remove_workspace_artifacts'] ?? true);
    $out['count_plugin_installations'] = (bool) ($settings['count_plugin_installations'] ?? true);
    $workspace_artifacts_to_remove = $settings['workspace_artifacts_to_remove'] ?? [];
    if (!is_array($workspace_artifacts_to_remove)) {
        $workspace_artifacts_to_remove = [];
    }
    $out['workspace_artifacts_to_remove'] = array_values(array_filter(array_map(function($v){
        $v = trim((string) $v);
        $v = wp_basename($v);
        return $v !== '' ? $v : null;
    }, $workspace_artifacts_to_remove)));
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
