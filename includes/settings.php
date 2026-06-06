<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Checks if the operating mode is standalone.
 */
function is_standalone(): bool {
    static $is_standalone = null;
    if ($is_standalone === null) {
        $is_standalone = get_peak_publisher_settings()['standalone_mode'] ?? false;
    }
    return $is_standalone;
}


/**
 * Gets plugin settings (defaults + sanitized option).
 */
function get_peak_publisher_settings(): array {
    $defaults = [
        'standalone_mode' => false,
        'auto_add_top_level_folder' => true,
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
    $raw = get_option('pblsh_settings');
    $data = is_array($raw) ? $raw : [];
    $merged = sanitize_peak_publisher_settings(array_merge($defaults, $data));
    return $merged;
}

/**
 * Updates the plugin settings.
 */
function update_peak_publisher_settings(array $settings): void {
    update_option('pblsh_settings', sanitize_peak_publisher_settings($settings), false);
}

/**
 * Sanitizes the plugin settings.
 */
function sanitize_peak_publisher_settings(array $settings): array {
    $out = [];
    $out['standalone_mode'] = (bool) ($settings['standalone_mode'] ?? false);
    $out['auto_add_top_level_folder'] = (bool) ($settings['auto_add_top_level_folder'] ?? true);
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
