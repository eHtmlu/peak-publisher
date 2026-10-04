<?php
/**
 * PHPUnit bootstrap: the plugin's function modules on top of the in-memory WordPress
 * fake (tests/stubs/wordpress.php). The classes (REST, upload, SVN) are request-bound
 * and stay out — they belong to the manual test round.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The modules' load guard (`defined('ABSPATH') || exit`) and the plugin constants.
define('ABSPATH', __DIR__ . '/');
define('PBLSH_PLUGIN_FILE', dirname(__DIR__) . '/peak-publisher.php');
define('PBLSH_PLUGIN_DIR', dirname(__DIR__) . '/');
define('PBLSH_PLUGIN_URL', 'https://example.test/wp-content/plugins/peak-publisher/');

require_once __DIR__ . '/stubs/wordpress.php';

require_once PBLSH_PLUGIN_DIR . 'includes/settings.php';
require_once PBLSH_PLUGIN_DIR . 'includes/encryption.php';
require_once PBLSH_PLUGIN_DIR . 'includes/hosting.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_cache.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_api.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_directory.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_log.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_import_timing.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_assets.php';
require_once PBLSH_PLUGIN_DIR . 'includes/functions.php';
require_once PBLSH_PLUGIN_DIR . 'includes/uploads.php';
require_once PBLSH_PLUGIN_DIR . 'includes/assets.php';
require_once PBLSH_PLUGIN_DIR . 'includes/upgrade.php';
