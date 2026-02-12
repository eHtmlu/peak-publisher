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
 * Gets Update URI.
 */
function get_update_uri(): string {
    return trailingslashit(home_url('wp-json/pblsh/v1/'));
}


/**
 * Gets the embed code.
 */
function get_bootstrap_code(string $version = 'basicV2'): string {
    if ($version !== 'basicV1' && $version !== 'basicV2') {
        return '';
    }
    $code = @file_get_contents(PBLSH_PLUGIN_DIR . 'assets/bootstrap-codes/' . $version . '.php.txt');
    return is_string($code) ? $code : '';
}


/**
 * Gets the bootstrap codes.
 */
function get_bootstrap_codes(): array {
    return [
        'basicV1' => get_bootstrap_code('basicV1'),
        'basicV2' => get_bootstrap_code('basicV2'),
    ];
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
    return $out;
}

 
/**
 * Returns a stable salt.
 */
function get_secret_salt(): string {
    $salt = get_option('pblsh_secret_salt');
    if (!is_string($salt) || $salt === '') {
        $salt = wp_generate_password(64, true, true);
        update_option('pblsh_secret_salt', $salt, false);
    }
    return $salt;
}

/**
 * Records an installation occurrence for the given plugin.
 */
function record_plugin_installation(int $plugin_post_id, string $user_agent, string $installed_version = ''): void {
    if ($plugin_post_id <= 0) {
        return;
    }
    $settings = get_peak_publisher_settings();
    if (empty($settings['count_plugin_installations'])) {
        return;
    }
    $expected_user_agent_pattern = '#^PeakPublisherBootstrapCode/[^;]+; WordPress/[^;]+; https?://[^;]+(;.*)?$#';
    if (empty($user_agent) || !preg_match($expected_user_agent_pattern, $user_agent)) {
        return;
    }

    // Generate a short key based on the user agent and the secret salt.
    $key = substr(preg_replace('/[^a-z0-9]/i', '', base64_encode(hash('sha256', get_secret_salt() . '|' . $user_agent, true))), 0, 14);

    // Update the installations list.
    $list = get_plugin_installations_list($plugin_post_id);
    $now = time();
    $installed_version_normalized = $installed_version !== '' ? normalize_version_number($installed_version) : '';
    if (!isset($list[$key]) || !is_array($list[$key])) {
        $list[$key] = [
            'first_seen' => $now,
            'last_seen' => $now,
            'count' => 1,
            'last_version' => $installed_version,
            'last_version_normalized' => $installed_version_normalized,
        ];
    } else {
        $list[$key]['last_seen'] = $now;
        $list[$key]['count'] = (int) ($list[$key]['count'] ?? 0) + 1;
        if ($installed_version !== '') {
            $list[$key]['last_version'] = $installed_version;
            $list[$key]['last_version_normalized'] = $installed_version_normalized;
        }
    }
    set_plugin_installations_list($plugin_post_id, $list);
}

/**
 * Returns unique installation count for a plugin.
 */
function get_plugin_installations_count(int $plugin_post_id): int {
    $list = get_plugin_installations_list($plugin_post_id);
    return count($list);
}

/**
 * Returns number of installations currently on a specific normalized version.
 */
function get_plugin_installations_count_by_version(int $plugin_post_id, string $normalized_version): int {
    $normalized_version = normalize_version_number($normalized_version);
    if ($normalized_version === '') {
        return 0;
    }
    $list = get_plugin_installations_list($plugin_post_id);
    $count = 0;
    foreach ($list as $row) {
        $v = (string) ($row['last_version_normalized'] ?? '');
        if ($v !== '' && $v === $normalized_version) {
            $count++;
        }
    }
    return $count;
}

/**
 * Returns the installations list (filtered by default to active within 24h).
 */
function get_plugin_installations_list(int $plugin_post_id): array {
    $meta_key = '_pblsh_installations';
    $list = get_post_meta($plugin_post_id, $meta_key, true);
    if (!is_array($list) || empty($list)) { return []; }
    $ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 24 * 60 * 60;
    $now = time();
    $out = [];
    foreach ($list as $k => $row) {
        $last = (int) ($row['last_seen'] ?? 0);
        if ($last > 0 && ($now - $last) > $ttl) {
            continue;
        }
        $out[$k] = $row;
    }
    if (count($out) !== count($list)) { // if some installations are stale, update the list
        update_post_meta($plugin_post_id, $meta_key, $out);
    }
    return $out;
}

/**
 * Persists the installations list.
 */
function set_plugin_installations_list(int $plugin_post_id, array $list): void {
    update_post_meta($plugin_post_id, '_pblsh_installations', $list);
}
 
/**
 * Ensures the upload directory is ready and secured.
 */
function ensure_upload_dir_is_ready_and_secured(): void {
    $basedir = peak_publisher_upload_basedir();
    $htaccess = $basedir . '/.htaccess';
    $indexphp = $basedir . '/index.php';
    if (file_exists($htaccess) && file_exists($indexphp)) {
        return;
    }
    wp_mkdir_p($basedir);
    file_put_contents($htaccess, 
        '<IfModule mod_authz_core.c>' . "\n" .
        '  Require all denied' . "\n" .
        '</IfModule>' . "\n" .
        '<IfModule !mod_authz_core.c>' . "\n" .
        '  Order Allow,Deny' . "\n" .
        '  Deny from all' . "\n" .
        '</IfModule>' . "\n"
    );
    file_put_contents($indexphp, '<?php exit;');
}


/**
 * Gets the upload directory basedir.
 */
function peak_publisher_upload_basedir(): string {
    return peak_publisher_upload_dir()['basedir'];
}


/**
 * Gets the upload directory.
 */
function peak_publisher_upload_dir(): array {
    $wp_upload_dir = wp_upload_dir();
    $path = $wp_upload_dir['basedir'] . '/pblsh-peak-publisher';
    $url = $wp_upload_dir['baseurl'] . '/pblsh-peak-publisher';
    return [
        'path' => $path,
        'url' => $url,
        'subdir' => '',
        'basedir' => $path,
        'baseurl' => $url,
        'error' => $wp_upload_dir['error'],
    ];
}


/**
 * Remove all empty folders from a directory recursively.
 */
function remove_empty_folders(string $dir): void {
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $path) {
        if ($path->isDir() && !(new \FilesystemIterator($path))->valid()) {
            get_wp_filesystem()->delete($path->getPathname());
        }
    }
}


/**
 * Opportunistically cleans the temporary upload directory.
 *
 * Scope:
 * - Only directories that match our temp naming scheme:
 *   YYYYMMDD-HHMMSS_[A-Za-z0-9]{8}_user-{ID}[_deleted-{UNIX}-[A-Za-z0-9]+]
 *
 * Removes:
 * - Matching directories that were marked as deleted (suffix "_deleted-...").
 * - Matching directories older than 24 hours (derived from the timestamp prefix).
 *
 * Safe to call during user-triggered requests; ignores unexpected files/folders.
 */
function maybe_cleanup_tmp_uploads(): void {
    $base = peak_publisher_upload_basedir() . '/tmp';
    if (!is_dir($base) || !is_readable($base)) {
        return;
    }

    $now = time();
    $ttl = 24 * 60 * 60;
    $fs = get_wp_filesystem();

    try {
        $dir = new \DirectoryIterator($base);
    } catch (\Throwable $e) {
        return;
    }

    foreach ($dir as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir()) {
            $name = $entry->getFilename();
            $path = $entry->getPathname();
            // Strictly match our temp folder pattern:
            //   {YYYYMMDD-HHMMSS}_{RANDOM8}_user-{USERID}[_deleted-{UNIX}-{RANDOM}]
            $match = [];
            $matched = (bool) preg_match(
                '/^(?P<stamp>\d{8}-\d{6})_(?P<rand>[A-Za-z0-9]{8})_user-\d+(?P<deleted>_deleted-\d+-[A-Za-z0-9]+)?$/',
                $name,
                $match
            );
            if ($matched) {
                $created_ts = null;
                $dt = \DateTimeImmutable::createFromFormat('Ymd-His', $match['stamp'], new \DateTimeZone('UTC'));
                if ($dt instanceof \DateTimeImmutable) {
                    $created_ts = $dt->getTimestamp();
                }
                $is_marked_deleted = !empty($match['deleted']);
                $is_older_than_ttl = ($created_ts !== null) ? (($now - $created_ts) > $ttl) : false;
                if ($is_marked_deleted || $is_older_than_ttl) {
                    // recursive delete; ignore failures
                    $fs->delete(trailingslashit($path), true);
                }
            }
            continue;
        }
        continue;
    }
}


/**
 * Normalizes a version number.
 */
function normalize_version_number(string $version): string {
    $version = strtolower(trim($version));
    $version = str_replace(['-', '_', '+'], '.', $version);
    $version = preg_replace('/([^.\d]+)/', '.$1.', $version);
    $version = preg_replace('/\.{2,}/', '.', $version);
    $version = trim($version, '.');
    return $version;
}



/**
 * Generates a slug for a release.
 */
function get_release_slug(string $plugin_slug, string $version): string {
    return sanitize_title($plugin_slug . '_' . normalize_version_number($version));
}


/**
 * Gets the WordPress filesystem.
 */
function get_wp_filesystem(): \WP_Filesystem_Base {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        WP_Filesystem();
    }
    return $wp_filesystem;
}







/**
 * Detects the text encoding of a given string.
 * Returns encoding label (e.g., 'UTF-8', 'UTF-16', 'Windows-1252') or false if unknown.
 */
function detect_text_encoding(string $content) {
    if (function_exists('mb_detect_encoding')) {
        $content = strip_utf8_bom($content);
        $detect_order = 'UTF-8, UTF-16, UTF-16LE, UTF-16BE, Windows-1252, ISO-8859-1, ISO-8859-15, ASCII';
        $enc = @mb_detect_encoding($content, $detect_order, true);
        if (is_string($enc) && $enc !== '') {
            return $enc;
        }
    }
    return false;
}


/**
 * Checks if a string is UTF-8.
 */
function is_utf8(string $content): bool {
    $content = strip_utf8_bom($content);
    return preg_match('//u', $content) === 1;
}


/**
 * Checks if the content does NOT start with a UTF-8 BOM.
 */
function has_utf8_bom(string $content): bool {
    return substr($content, 0, 3) === "\xEF\xBB\xBF";
}


/**
 * Converts a string to UTF-8 using a provided source encoding (if known).
 * If $source_encoding is falsy or 'UTF-8', performs best-effort cleanup only.
 */
function convert_to_utf8(string $content, $source_encoding = null): string {
    $converted = $content;
    if (is_string($source_encoding) && strtoupper($source_encoding) !== 'UTF-8') {
        if (function_exists('mb_convert_encoding')) {
            $maybe = @mb_convert_encoding($content, 'UTF-8', $source_encoding);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        } elseif (function_exists('iconv')) {
            $maybe = @iconv($source_encoding, 'UTF-8//IGNORE', $content);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        }
    }

    // Final safety: if still invalid UTF-8, drop invalid sequences.
    if (function_exists('iconv') && is_utf8($converted)) {
        $maybe = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
        if (is_string($maybe) && $maybe !== '') {
            $converted = $maybe;
        }
    }
    return is_string($converted) ? $converted : $content;
}


/**
 * Strips the UTF-8 BOM from a string.
 */
function strip_utf8_bom(string $content): string {
    return has_utf8_bom($content) ? substr($content, 3) : $content;
}






/**
 * Polyfills for PHP 8.0 functions.
 */
if (!function_exists('str_starts_with')) {
    function str_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return 0 === strpos( $haystack, $needle );
	}
}
if (!function_exists('str_contains')) {
    function str_contains( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return false !== strpos( $haystack, $needle );
	}
}
if (!function_exists('str_ends_with')) {
    function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack ) {
			return '' === $needle;
		}

		$len = strlen( $needle );

		return substr( $haystack, -$len, $len ) === $needle;
	}
}
