<?php
/**
 * Runs when Peak Publisher is deleted through WordPress. Removes the secrets, nothing
 * else: the wordpress.org accounts (their encrypted SVN passwords), the encryption context
 * and the key file. Plugins, releases, ZIPs, assets and settings stay — they are the user's
 * work, and a reinstalled Peak Publisher finds them again; the accounts are reconnected then.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// The plugin is not loaded here: the two modules that name the key file's place.
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/encryption.php';

// The post type is not registered here; get_posts() queries it by name all the same.
$pblsh_account_ids = get_posts([
    'post_type' => 'pblsh_wporg_account',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
]);
foreach ($pblsh_account_ids as $pblsh_account_id) {
    wp_delete_post((int) $pblsh_account_id, true);
}

delete_option('pblsh_encryption_context_id');

$pblsh_key_file = Pblsh\get_encryption_key_file_path();
if (file_exists($pblsh_key_file)) {
    @unlink($pblsh_key_file);
}
