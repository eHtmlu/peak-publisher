<?php

namespace Pblsh;

defined('ABSPATH') || exit;


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
 * Gets the assets directory for a specific plugin slug.
 */
function get_plugin_assets_basedir(string $plugin_slug): string {
    return trailingslashit(peak_publisher_upload_basedir()) . 'plugins/' . sanitize_file_name($plugin_slug) . '/assets';
}


/**
 * Ensures the assets directory for a plugin slug exists and is publicly accessible.
 * Assets are served as direct static files (not via REST API).
 * Writes .htaccess to override the parent "Deny all" for Apache; Nginx serves files directly.
 */
function ensure_plugin_assets_dir(string $plugin_slug): void {
    $basedir  = get_plugin_assets_basedir($plugin_slug);
    wp_mkdir_p($basedir);
    if (!file_exists($basedir . '/index.php')) {
        file_put_contents($basedir . '/index.php', '<?php exit;');
    }
    if (!file_exists($basedir . '/.htaccess')) {
        file_put_contents($basedir . '/.htaccess',
            '# Allow direct access to image assets only' . "\n" .
            '<FilesMatch "\.(png|jpe?g|gif|svg)$">' . "\n" .
            '  <IfModule mod_authz_core.c>' . "\n" .
            '    Require all granted' . "\n" .
            '  </IfModule>' . "\n" .
            '  <IfModule !mod_authz_core.c>' . "\n" .
            '    Order Allow,Deny' . "\n" .
            '    Allow from all' . "\n" .
            '  </IfModule>' . "\n" .
            '</FilesMatch>' . "\n"
        );
    }
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
