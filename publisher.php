<?php

/**
 * Plugin Name: Publisher
 * Description: The easiest way to self-host, manage and publish your own custom plugins.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: ehtmlu
 * Author URI: https://ehtmlu.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: publisher
 */

namespace Pblsh;

defined('ABSPATH') || exit;




define('PBLSH_PLUGIN_FILE', __FILE__);
define('PBLSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PBLSH_PLUGIN_URL', plugin_dir_url(__FILE__));


// General setup.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/init.php';



// Initialize admin interface.
if (is_admin()) {
    require_once __DIR__ . '/classes/AdminUI.php';
    return; // Skip everything else because it's not a REST request.
}


// Initialize API.
function rest_api_init() {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We only check if the request URI contains the string 'pblsh-admin', so we don't need further sanitization.
    $seems_to_be_admin_api_request = isset($_SERVER['REQUEST_URI']) && str_contains(wp_unslash($_SERVER['REQUEST_URI']), 'pblsh-admin');
    
    // ATTENTION: This is NOT a security check, but a soft check to avoid unnecessary work for public requests
    // The APIs have their own security checks.

    if ($seems_to_be_admin_api_request) {
        require_once __DIR__ . '/classes/AdminAPI.php';
    } else {
        require_once __DIR__ . '/classes/PublicAPI.php';
    }
}
add_action('rest_api_init', __NAMESPACE__ . '\\rest_api_init');
