<?php

namespace Pblsh;

defined('ABSPATH') || exit;



/**
 * General initialization for standalone mode - Admin specific initialization is handled in admin.php
 */
if (is_standalone()) {
    // Disable themes.
    add_filter('wp_using_themes', '__return_false');

    // Redirect frontend visitors to a configured URL.
    $standalone_redirect_url = get_peak_publisher_boot_settings()['standalone_redirect_url'];
    if ($standalone_redirect_url !== '') {
        add_action('wp', function() use ($standalone_redirect_url) {
            wp_redirect($standalone_redirect_url, 302);
            exit;
        }, 9999);
    }

    // Re-enable redirection from "/admin" to real admin url (because it's disabled by disabling themes)
    add_action('init', function() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We only check if the request URI is 'admin', so we don't need further sanitization.
        if ( isset($_SERVER['REQUEST_URI']) && trim(wp_unslash($_SERVER['REQUEST_URI']), '/') === 'admin' ) {
            wp_safe_redirect(admin_url());
            exit;
        }
    });

    // Redirect to Peak Publisher on login
    add_action('load-index.php', function() {
        if ( defined('DOING_AJAX') && DOING_AJAX ) return;
        if ( ! current_user_can('read') ) return;
        wp_safe_redirect( admin_url('admin.php?page=pblsh-peak-publisher') );
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

    // Highlight toolbar update icon (since Dashboard/Updates menu is hidden in standalone mode)
    add_action('admin_head', function() {
        ?>
        <style>
            .admin-color-fresh     { --pblsh-wp-update-notif-bg: #d63638; }
            .admin-color-light     { --pblsh-wp-update-notif-bg: #d64e07; }
            .admin-color-modern    { --pblsh-wp-update-notif-bg: #3858e9; }
            .admin-color-blue      { --pblsh-wp-update-notif-bg: #e1a948; }
            .admin-color-midnight  { --pblsh-wp-update-notif-bg: #69a8bb; }
            .admin-color-coffee    { --pblsh-wp-update-notif-bg: #9ea476; }
            .admin-color-sunrise   { --pblsh-wp-update-notif-bg: #ccaf0b; }
            .admin-color-ectoplasm { --pblsh-wp-update-notif-bg: #d46f15; }
            .admin-color-ocean     { --pblsh-wp-update-notif-bg: #aa9d88; }

            #wpadminbar #wp-admin-bar-updates .ab-label {
                position: relative;
                color: #fff !important;
                margin-left: 4px;
                margin-right: 6px;
                z-index: 0;
            }

            #wpadminbar #wp-admin-bar-updates .ab-label::after {
                content: " ";
                position: absolute;
                min-width: 20px;
                width: calc(6px + 100% + 6px);
                height: 20px;
                background: var(--pblsh-wp-update-notif-bg);
                border-radius: 10px;
                bottom: 50%;
                right: 50%;
                z-index: -1;
                transform: translate(50%, 50%);
            }
        </style>
        <?php
    });
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

    // pblsh_wporg_plugin
    register_post_type('pblsh_wporg_plugin', [
        'labels' => [
            'name' => 'WordPress.org Plugins',
            'singular_name' => 'WordPress.org Plugin',
            'menu_name' => 'WordPress.org Plugins',
            'name_admin_bar' => 'WordPress.org Plugin',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New WordPress.org Plugin',
            'new_item' => 'New WordPress.org Plugin',
            'edit_item' => 'Edit WordPress.org Plugin',
            'view_item' => 'View WordPress.org Plugin',
            'all_items' => 'All WordPress.org Plugins',
            'search_items' => 'Search WordPress.org Plugins',
            'not_found' => 'No wordpress.org plugins found',
            'not_found_in_trash' => 'No wordpress.org plugins found in Trash',
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
