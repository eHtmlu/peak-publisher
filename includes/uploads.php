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
 * A plugin's assets directory per channel and layer: self-hosted `plugins/{slug}/assets`;
 * a wordpress.org plugin keeps the mirror of its SVN assets/ under
 * `wporg-plugins/{slug}/mirror/assets` and the pending changes under
 * `wporg-plugins/{slug}/pending/assets` — a channel tree of its own, because the same slug
 * may exist on both channels.
 *
 * @param 'shipped'|'pending' $layer shipped = what sites and the plugin page get (the
 *        self-hosted directory, the wordpress.org mirror)
 */
function get_plugin_assets_dir(\WP_Post $plugin, string $layer = 'shipped'): string {
    return peak_publisher_upload_basedir() . '/' . plugin_assets_relative_dir($plugin, $layer);
}


function get_plugin_assets_url(\WP_Post $plugin, string $layer = 'shipped'): string {
    return peak_publisher_upload_dir()['baseurl'] . '/' . plugin_assets_relative_dir($plugin, $layer);
}


function plugin_assets_relative_dir(\WP_Post $plugin, string $layer): string {
    $slug = sanitize_file_name($plugin->post_name);
    if (is_wporg_plugin($plugin)) {
        return 'wporg-plugins/' . $slug . '/' . ($layer === 'pending' ? 'pending' : 'mirror') . '/assets';
    }
    return 'plugins/' . $slug . '/assets';
}


/**
 * Creates a plugin's assets directory and makes it publicly readable for images only —
 * assets are served as direct static files; the uploads base keeps denying everything else.
 * Writes .htaccess to override the parent "Deny all" for Apache; Nginx serves files directly.
 */
function ensure_plugin_assets_dir(\WP_Post $plugin, string $layer = 'shipped'): void {
    ensure_upload_dir_is_ready_and_secured();
    $basedir = get_plugin_assets_dir($plugin, $layer);
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
    $pattern = '/^(?P<stamp>\d{8}-\d{6})_(?P<rand>[A-Za-z0-9]{8})_user-\d+(?P<deleted>_deleted-\d+-[A-Za-z0-9]+)?$/';

    try {
        $dir = new \DirectoryIterator($base);
    } catch (\Throwable $e) {
        return;
    }

    foreach ($dir as $entry) {
        if ($entry->isDot() || !$entry->isDir()) {
            continue;
        }

        $name = $entry->getFilename();
        $match = [];
        $matched = (bool) preg_match($pattern, $name, $match);
        if (!$matched) {
            continue;
        }

        $created_ts = null;
        $dt = \DateTimeImmutable::createFromFormat('Ymd-His', $match['stamp'], new \DateTimeZone('UTC'));
        if ($dt instanceof \DateTimeImmutable) {
            $created_ts = $dt->getTimestamp();
        }

        $is_marked_deleted = !empty($match['deleted']);
        $is_older_than_ttl = $created_ts !== null ? (($now - $created_ts) > $ttl) : false;
        if ($is_marked_deleted || $is_older_than_ttl) {
            get_wp_filesystem()->delete(trailingslashit($entry->getPathname()), true);
        }
    }
}


/**
 * Deletes a directory recursively after renaming it out of the active path.
 */
function delete_directory_with_race_protection(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    $path = rtrim($path, '/\\');
    $new_path = trailingslashit(dirname($path)) . basename($path) . '_deleted-' . time() . '-' . wp_generate_password(10, false) . '/';
    $fs = get_wp_filesystem();
    if ($fs->move($path, $new_path)) {
        $fs->delete($new_path, true);
    }
}


/**
 * The status of an upload's finalize, a file in the upload's working directory: written while
 * it runs, read back by the upload dialog. A gateway in front of the site may end the request
 * long before a large wordpress.org deploy is done while PHP carries on, so the dialog learns
 * how far the publish got and how it ended from this file, not from the request's answer.
 *
 * Status = [
 *   'started_at' => int          when this attempt started
 *   'alive_at'   => int          its last sign of life (every write)
 *   'phase'      => string       wporg prepare | read | send | save; self-hosted prepare | build
 *   'done'       => int          read: the folders read; send: the operations sent
 *   'total'      => int          send: the commit's operations; 0 = no total known
 *   'response'   => array|null   the finalize response, once the attempt ended
 * ]
 */
const PBLSH_FINALIZE_STATUS_FILE = 'finalize.json';

/**
 * A running finalize without a sign of life for this long is taken for dead (killed by the
 * host, a fatal error). Its longest silent stretch is the MERGE of a wordpress.org commit,
 * measured at about 8 s.
 */
const PBLSH_FINALIZE_LOST_AFTER = 120; // seconds


function read_finalize_status(string $tmp_root): ?array {
    // Read back from disk: a file cut short or edited by hand is no status.
    $file = $tmp_root . PBLSH_FINALIZE_STATUS_FILE;
    if (!is_file($file)) {
        return null;
    }
    $status = json_decode((string) file_get_contents($file), true);
    return is_array($status) ? $status : null;
}


/**
 * Records a finalize status. An ok response is final: an attempt that ran beside a successful
 * one (the user published again after the first went silent) never hides that success. The
 * file is replaced by a rename, so a reader never sees half of it.
 */
function record_finalize_status(string $tmp_root, array $status): void {
    if ((read_finalize_status($tmp_root)['response']['status'] ?? null) === 'ok') {
        return;
    }
    $file = $tmp_root . PBLSH_FINALIZE_STATUS_FILE;
    $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($temp, json_encode($status)) !== false) {
        rename($temp, $file);
    }
}


/**
 * What the upload dialog learns about a finalize: not_started (no status), running with its
 * progress, lost (no sign of life for PBLSH_FINALIZE_LOST_AFTER), or finished with its response.
 */
function finalize_status_view(?array $status, int $now): array {
    if ($status === null) {
        return [ 'state' => 'not_started' ];
    }
    if (is_array($status['response'] ?? null)) {
        return [ 'state' => 'finished', 'response' => $status['response'] ];
    }
    if ($now - (int) ($status['alive_at'] ?? 0) > PBLSH_FINALIZE_LOST_AFTER) {
        return [ 'state' => 'lost' ];
    }
    return [
        'state' => 'running',
        'phase' => (string) ($status['phase'] ?? ''),
        'done' => (int) ($status['done'] ?? 0),
        'total' => (int) ($status['total'] ?? 0),
    ];
}
