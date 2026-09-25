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

require_once PBLSH_PLUGIN_DIR . 'includes/hosting.php';
require_once PBLSH_PLUGIN_DIR . 'includes/wporg_cache.php';
require_once PBLSH_PLUGIN_DIR . 'includes/functions.php';
