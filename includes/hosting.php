<?php

namespace Pblsh;

defined('ABSPATH') || exit;


const PBLSH_PLUGIN_POST_TYPES = ['pblsh_plugin', 'pblsh_wporg_plugin'];


/**
 * Checks whether a post is one of Peak Publisher's plugin post types.
 */
function is_plugin_post(?\WP_Post $post): bool {
    return $post instanceof \WP_Post && in_array($post->post_type, PBLSH_PLUGIN_POST_TYPES, true);
}


/**
 * Mints a plugin slug from a user-chosen name, mirroring wordpress.org's slug generation
 * (plugin-directory class-upload-handler.php): transform instead of reject.
 * Returns '' when nothing usable remains.
 */
function generate_plugin_slug(string $name): string {
    $slug = remove_accents($name);
    $slug = preg_replace('/[^a-z0-9 _.-]/i', '', $slug);
    $slug = str_replace('_', '-', $slug);
    return sanitize_title_with_dashes($slug);
}


/**
 * Finds the plugin post of the given post type by its slug.
 */
function get_plugin_post_by_slug(string $post_type, string $slug): ?\WP_Post {
    // An empty slug must not match: WP_Query drops an empty 'name' filter and would return an arbitrary post.
    if ($slug === '') {
        return null;
    }

    $posts = get_posts([
        'post_type' => $post_type,
        'post_status' => 'any',
        'name' => $slug,
        'posts_per_page' => 1,
    ]);

    return !empty($posts) && $posts[0] instanceof \WP_Post ? $posts[0] : null;
}


/**
 * Gets the public hosting type name for a plugin post, post ID, or post type string.
 */
function get_plugin_hosting_type($post_or_id): string {
    if ($post_or_id instanceof \WP_Post) {
        $post_type = $post_or_id->post_type;
    } elseif (is_int($post_or_id)) {
        $post = get_post($post_or_id);
        $post_type = $post instanceof \WP_Post ? $post->post_type : '';
    } else {
        $post_type = (string) $post_or_id;
    }

    if ($post_type === 'pblsh_plugin') {
        return 'self_hosted';
    }
    if ($post_type === 'pblsh_wporg_plugin') {
        return 'wporg';
    }
    return '';
}


/**
 * Checks whether a plugin post, post ID, or post type string represents a wp.org plugin.
 */
function is_wporg_plugin($post_or_id): bool {
    return get_plugin_hosting_type($post_or_id) === 'wporg';
}


/**
 * Validates a canonical plugin slug. Both channels share the wordpress.org slug format.
 *
 * @return string|\WP_Error
 */
function normalize_plugin_slug($slug, ?string $field = null) {
    $slug = is_string($slug) ? trim($slug) : '';
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        $data = [ 'status' => 400 ];
        if ($field !== null && $field !== '') {
            $data['field'] = $field;
        }
        return new \WP_Error(
            'invalid_slug',
            __('Invalid plugin slug.', 'peak-publisher'),
            $data
        );
    }

    return $slug;
}
