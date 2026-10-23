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


/**
 * The whitelist's entries by kind — the one reading of the setting, for the public API's
 * check (PublicAPI::check_permission()) and the save's requirements
 * (whitelist_requirements_error()): IPv4 and IPv6 addresses, CIDR ranges of either family as
 * [address, prefix length] with a numeric prefix within the family's bounds, and domain names.
 * An entry that is none of these is dropped.
 *
 * @return array{4: array{addresses: string[], ranges: array<array{string, int}>}, 6: array{addresses: string[], ranges: array<array{string, int}>}, domains: string[]}
 */
function classify_ip_whitelist(array $entries): array {
    $lists = [ 4 => [ 'addresses' => [], 'ranges' => [] ], 6 => [ 'addresses' => [], 'ranges' => [] ], 'domains' => [] ];
    foreach ($entries as $entry) {
        $entry = trim((string) $entry);
        if (str_contains($entry, '/')) {
            [ $address, $prefix ] = explode('/', $entry, 2);
            if (!ctype_digit($prefix)) {
                continue;
            }
            $bits = (int) $prefix;
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $bits <= 32) {
                $lists[4]['ranges'][] = [ $address, $bits ];
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $bits <= 128) {
                $lists[6]['ranges'][] = [ $address, $bits ];
            }
        } elseif (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $lists[4]['addresses'][] = $entry;
        } elseif (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lists[6]['addresses'][] = $entry;
        } elseif (filter_var($entry, FILTER_VALIDATE_DOMAIN)) {
            $lists['domains'][] = $entry;
        }
    }
    return $lists;
}


/**
 * What the server lacks for the whitelist as entered, or null. A domain name is confirmed
 * through its IDN form and DNS (PublicAPI::get_untrusted_client_domain_from_user_agent()),
 * which needs the intl extension: without it the entry is refused at the save instead of
 * never matching.
 */
function whitelist_requirements_error(array $entries): ?\WP_Error {
    if (classify_ip_whitelist($entries)['domains'] !== [] && !function_exists('idn_to_ascii')) {
        return new \WP_Error('settings_intl_missing', __('Domain names in the whitelist need the PHP intl extension, which this server lacks. Enter IP addresses instead.', 'peak-publisher'), [ 'status' => 400, 'field' => 'ip_whitelist' ]);
    }
    return null;
}
