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
function get_bootstrap_code(): string {
    $file_content = file_get_contents(PBLSH_PLUGIN_DIR . 'includes/bootstrap-codes/basicV1.php');
    preg_match('/\/\*+ BOOTSTRAP CODE START \*+\/\s*(.*?)\s*\/\*+ BOOTSTRAP CODE END \*+\//s', $file_content, $matches);
    $code = $matches[1] ?? '';
    return $code;
}


/**
 * Gets plugin settings (defaults + sanitized option).
 */
function get_peak_publisher_settings(): array {
    $defaults = [
        'standalone_mode' => false,
        'auto_add_top_level_folder' => true,
        'auto_remove_workspace_artifacts' => true,
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
