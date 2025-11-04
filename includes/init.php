<?php

namespace Pblsh;

defined('ABSPATH') || exit;



/**
 * General initialization for standalone mode - Admin specific initialization is handled in admin.php
 */
if (is_standalone()) {
    // Disable themes.
    add_filter('wp_using_themes', '__return_false');

    // Re-enable redirection from "/admin" to real admin url (because it's disabled by disabling themes)
    add_action('init', function() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We only check if the request URI is 'admin', so we don't need further sanitization.
        if ( isset($_SERVER['REQUEST_URI']) && trim(wp_unslash($_SERVER['REQUEST_URI']), '/') === 'admin' ) {
            wp_safe_redirect(admin_url());
            exit;
        }
    });

    // Prevent new bundled themes from being installed during core updates
    if (!defined('CORE_UPGRADE_SKIP_NEW_BUNDLED')) {
        define('CORE_UPGRADE_SKIP_NEW_BUNDLED', true);
    }

    // Redirect to Publisher on login
    add_action('load-index.php', function() {
        if ( defined('DOING_AJAX') && DOING_AJAX ) return;
        if ( ! current_user_can('read') ) return;
        wp_safe_redirect( admin_url('admin.php?page=pblsh') );
        exit;
    });

    // Disable comment system
    add_filter( 'comments_open', '__return_false' );
    add_filter( 'comments_array', function() { return []; }, 10 );
    add_action( 'admin_menu', function() { remove_menu_page( 'edit-comments.php' ); } );
    add_action( 'wp_before_admin_bar_render', function() {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('comments');
    } );

    // Disable public access
    add_filter('pre_option_blog_public', function($value) {
        return 0;
    });

    // Disable XML-RPC
    add_filter('xmlrpc_enabled', '__return_false');

    // Disable posts and pages and attachments
    add_action('init', function() {
        global $wp_post_types;
        foreach (['post', 'page', 'attachment'] as $type) {
            if (isset($wp_post_types[$type])) {
                $wp_post_types[$type]->public = false;
                $wp_post_types[$type]->show_ui = false;
                $wp_post_types[$type]->show_in_menu = false;
                $wp_post_types[$type]->show_in_admin_bar = false;
                $wp_post_types[$type]->show_in_nav_menus = false;
                $wp_post_types[$type]->exclude_from_search = true;
            }
        }
    }, 11);
    add_action('admin_menu', function() {
        remove_menu_page('upload.php');
    });

    // Disable index and themes
    add_action('admin_menu', function() {
        remove_menu_page('index.php');
        remove_menu_page('themes.php');
    });

    // Disable site name in admin bar
    add_action('admin_bar_menu', function($wp_admin_bar) {
        $wp_admin_bar->remove_node('site-name');
    }, 999);
}



/**
 * Initialize Custom Post Types.
 */
function init_custom_post_types(): void {
    // pblsh_plugin
    register_post_type('pblsh_plugin', [
        'labels' => [
            'name' => 'Plugins',
            'singular_name' => 'Plugin',
            'menu_name' => 'Plugins',
            'name_admin_bar' => 'Plugin',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Plugin',
            'new_item' => 'New Plugin',
            'edit_item' => 'Edit Plugin',
            'view_item' => 'View Plugin',
            'all_items' => 'All Plugins',
            'search_items' => 'Search Plugins',
            'not_found' => 'No plugins found',
            'not_found_in_trash' => 'No plugins found in Trash',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        // Disable REST to avoid block editor for this CPT.
        'show_in_rest' => false,
        'supports' => ['title'/* , 'editor' */],
        'capability_type' => 'post',
        'has_archive' => false,
        'rewrite' => false,
    ]);

    // pblsh_release
    register_post_type('pblsh_release', [
        'labels' => [
            'name' => 'Releases',
            'singular_name' => 'Release',
            'menu_name' => 'Releases',
            'name_admin_bar' => 'Release',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Release',
            'new_item' => 'New Release',
            'edit_item' => 'Edit Release',
            'view_item' => 'View Release',
            'all_items' => 'All Releases',
            'search_items' => 'Search Releases',
            'not_found' => 'No releases found',
            'not_found_in_trash' => 'No releases found in Trash',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports' => ['title'/* , 'editor' */],
        'capability_type' => 'post',
        'has_archive' => false,
        'rewrite' => false,
    ]);
}
add_action('init', __NAMESPACE__ . '\\init_custom_post_types');
