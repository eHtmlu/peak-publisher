<?php

namespace Pblsh;

use Exception;

defined('ABSPATH') || exit;


/**
 * Gets Update URI.
 */
function get_update_uri(): string {
    return trailingslashit(home_url('wp-json/pblsh/v1/'));
}


/**
 * Gets the embed code.
 */
function get_bootstrap_code(string $version = 'basicV2'): string {
    if ($version !== 'basicV1' && $version !== 'basicV2') {
        return '';
    }
    $code = @file_get_contents(PBLSH_PLUGIN_DIR . 'assets/bootstrap-codes/' . $version . '.php.txt');
    return is_string($code) ? $code : '';
}


/**
 * Gets the bootstrap codes.
 */
function get_bootstrap_codes(): array {
    return [
        'basicV1' => get_bootstrap_code('basicV1'),
        'basicV2' => get_bootstrap_code('basicV2'),
    ];
}


/**
 * Returns a stable salt.
 */
function get_secret_salt(): string {
    $salt = get_option('pblsh_secret_salt');
    if (!is_string($salt) || $salt === '') {
        $salt = wp_generate_password(64, true, true);
        update_option('pblsh_secret_salt', $salt, false);
    }
    return $salt;
}

/**
 * Records an installation occurrence for the given plugin.
 */
function record_plugin_installation(int $plugin_post_id, string $user_agent, string $installed_version = ''): void {
    if ($plugin_post_id <= 0) {
        return;
    }
    $settings = get_peak_publisher_settings();
    if (empty($settings['count_plugin_installations'])) {
        return;
    }
    $expected_user_agent_pattern = '#^PeakPublisherBootstrapCode/[^;]+; WordPress/[^;]+; https?://[^;]+(;.*)?$#';
    if (empty($user_agent) || !preg_match($expected_user_agent_pattern, $user_agent)) {
        return;
    }

    // Generate a short key based on the user agent and the secret salt.
    $key = substr(preg_replace('/[^a-z0-9]/i', '', base64_encode(hash('sha256', get_secret_salt() . '|' . $user_agent, true))), 0, 14);

    // Update the installations list.
    $list = get_plugin_installations_list($plugin_post_id);
    $now = time();
    $installed_version_normalized = $installed_version !== '' ? normalize_version_number($installed_version) : '';
    if (!isset($list[$key]) || !is_array($list[$key])) {
        $list[$key] = [
            'first_seen' => $now,
            'last_seen' => $now,
            'count' => 1,
            'last_version' => $installed_version,
            'last_version_normalized' => $installed_version_normalized,
        ];
    } else {
        $list[$key]['last_seen'] = $now;
        $list[$key]['count'] = (int) ($list[$key]['count'] ?? 0) + 1;
        if ($installed_version !== '') {
            $list[$key]['last_version'] = $installed_version;
            $list[$key]['last_version_normalized'] = $installed_version_normalized;
        }
    }
    set_plugin_installations_list($plugin_post_id, $list);
}

/**
 * Returns unique installation count for a plugin.
 */
function get_plugin_installations_count(int $plugin_post_id): int {
    $list = get_plugin_installations_list($plugin_post_id);
    return count($list);
}

/**
 * Returns number of installations currently on a specific normalized version.
 */
function get_plugin_installations_count_by_version(int $plugin_post_id, string $normalized_version): int {
    $normalized_version = normalize_version_number($normalized_version);
    if ($normalized_version === '') {
        return 0;
    }
    $list = get_plugin_installations_list($plugin_post_id);
    $count = 0;
    foreach ($list as $row) {
        $v = (string) ($row['last_version_normalized'] ?? '');
        if ($v !== '' && $v === $normalized_version) {
            $count++;
        }
    }
    return $count;
}

/**
 * Returns the installations list (filtered by default to active within 24h).
 */
function get_plugin_installations_list(int $plugin_post_id): array {
    $meta_key = '_pblsh_installations';
    $list = get_post_meta($plugin_post_id, $meta_key, true);
    if (!is_array($list) || empty($list)) { return []; }
    $ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 24 * 60 * 60;
    $now = time();
    $out = [];
    foreach ($list as $k => $row) {
        $last = (int) ($row['last_seen'] ?? 0);
        if ($last > 0 && ($now - $last) > $ttl) {
            continue;
        }
        $out[$k] = $row;
    }
    if (count($out) !== count($list)) { // if some installations are stale, update the list
        update_post_meta($plugin_post_id, $meta_key, $out);
    }
    return $out;
}

/**
 * Persists the installations list.
 */
function set_plugin_installations_list(int $plugin_post_id, array $list): void {
    update_post_meta($plugin_post_id, '_pblsh_installations', $list);
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
 * Selects the readme file name using wordpress.org's readme import precedence.
 *
 * @param string[] $files File names from the plugin root.
 */
function find_wporg_readme_file_name(array $files): ?string {
    try {
        // START - Copy of WordPress.org code ( https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/cli/i18n/class-readme-import.php )
        $readme_files = preg_grep( '!^readme.(txt|md)$!i', $files );
        if ( ! $readme_files ) {
            throw new Exception( 'Plugin has no readme file.' );
        }

        $readme_file = reset( $readme_files );
        foreach ( $readme_files as $f ) {
            if ( '.txt' == strtolower( substr( $f, - 4 ) ) ) {
                $readme_file = $f;
                break;
            }
        }
        // END - Copy of WordPress.org code

        return is_string($readme_file) ? $readme_file : null;
    } catch (\Throwable $e) {
        return null;
    }
}


/**
 * Parses a WordPress.org-style readme.txt into headers/sections and includes raw content.
 * Returns associative array suitable for storage under data.plugin_readme_txt.
 *
 * @param string $content Content of the readme.txt file.
 * @return array Parsed readme.txt content.
 */
function parse_readme_txt(string $content): array {
    require_once PBLSH_PLUGIN_DIR . 'libs/plugin-directory/readme/class-parser.php';
    require_once PBLSH_PLUGIN_DIR . 'libs/plugin-directory/class-markdown.php';

    // Use the official parser of wordpress.org
    $parser = new \Pblsh\Vendor\WordPressdotorg\Plugin_Directory\Readme\Parser($content);
    // Return the full parser data structure
    $data = get_object_vars($parser);
    return is_array($data) ? $data : [];
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
 * Detects the text encoding of a given string.
 * Returns encoding label (e.g., 'UTF-8', 'UTF-16', 'Windows-1252') or false if unknown.
 */
function detect_text_encoding(string $content) {
    if (function_exists('mb_detect_encoding')) {
        $content = strip_utf8_bom($content);
        $detect_order = 'UTF-8, UTF-16, UTF-16LE, UTF-16BE, Windows-1252, ISO-8859-1, ISO-8859-15, ASCII';
        $enc = @mb_detect_encoding($content, $detect_order, true);
        if (is_string($enc) && $enc !== '') {
            return $enc;
        }
    }
    return false;
}


/**
 * Checks if a string is UTF-8.
 */
function is_utf8(string $content): bool {
    $content = strip_utf8_bom($content);
    return preg_match('//u', $content) === 1;
}


/**
 * Checks if the content does NOT start with a UTF-8 BOM.
 */
function has_utf8_bom(string $content): bool {
    return substr($content, 0, 3) === "\xEF\xBB\xBF";
}


/**
 * Converts a string to UTF-8 using a provided source encoding (if known).
 * If $source_encoding is falsy or 'UTF-8', performs best-effort cleanup only.
 */
function convert_to_utf8(string $content, $source_encoding = null): string {
    $converted = $content;
    if (is_string($source_encoding) && strtoupper($source_encoding) !== 'UTF-8') {
        if (function_exists('mb_convert_encoding')) {
            $maybe = @mb_convert_encoding($content, 'UTF-8', $source_encoding);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        } elseif (function_exists('iconv')) {
            $maybe = @iconv($source_encoding, 'UTF-8//IGNORE', $content);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        }
    }

    // Final safety: if still invalid UTF-8, drop invalid sequences.
    if (function_exists('iconv') && is_utf8($converted)) {
        $maybe = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
        if (is_string($maybe) && $maybe !== '') {
            $converted = $maybe;
        }
    }
    return is_string($converted) ? $converted : $content;
}


/**
 * Strips the UTF-8 BOM from a string.
 */
function strip_utf8_bom(string $content): string {
    return has_utf8_bom($content) ? substr($content, 3) : $content;
}






/**
 * Retrieve the average color of a specified image.
 *
 * Samples five points (rule of thirds + center) and averages their RGB values.
 * Algorithm matches Jetpack's Tonesque library used by WordPress.org Plugin Directory.
 *
 * Based on WordPress.org Plugin Directory.
 * @see https://github.com/WordPress/wordpress.org — class-tools.php
 * @see Jetpack Tonesque — grab_points() / grab_color() / get_color()
 *
 * @param string $file_path Absolute filesystem path to the image.
 * @return string|false Average color as a 6-char lowercase hex value (no #), false on failure.
 */
function get_image_average_color( string $file_path ) {
    if ( ! function_exists( 'imagecreatefromstring' ) ) {
        return false;
    }

    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
    $data = file_get_contents( $file_path );
    if ( $data === false ) {
        return false;
    }

    $img = @imagecreatefromstring( $data );
    if ( ! $img ) {
        return false;
    }

    $width  = imagesx( $img );
    $height = imagesy( $img );

    // Sample five points based on rule of thirds and center (same as Tonesque::grab_points).
    $left_x   = (int) round( $width / 3 );
    $right_x  = (int) round( ( $width / 3 ) * 2 );
    $top_y    = (int) round( $height / 3 );
    $bottom_y = (int) round( ( $height / 3 ) * 2 );
    $center_x = (int) round( $width / 2 );
    $center_y = (int) round( $height / 2 );

    $points = [
        imagecolorat( $img, $left_x,   $top_y ),
        imagecolorat( $img, $right_x,  $top_y ),
        imagecolorat( $img, $left_x,   $bottom_y ),
        imagecolorat( $img, $right_x,  $bottom_y ),
        imagecolorat( $img, $center_x, $center_y ),
    ];

    // Average the RGB channels (same as Tonesque::grab_color).
    $r = [];
    $g = [];
    $b = [];
    foreach ( $points as $color_index ) {
        $c  = imagecolorsforindex( $img, $color_index );
        $r[] = $c['red'];
        $g[] = $c['green'];
        $b[] = $c['blue'];
    }

    imagedestroy( $img );

    $red   = (int) round( array_sum( $r ) / 5 );
    $green = (int) round( array_sum( $g ) / 5 );
    $blue  = (int) round( array_sum( $b ) / 5 );

    return sprintf( '%02x%02x%02x', $red, $green, $blue );
}


/**
 * Retrieve the Geopattern SVG URL for a given plugin.
 *
 * Based on WordPress.org Plugin Directory.
 * @see https://github.com/WordPress/wordpress.org — class-template.php
 *
 * @param \WP_Post|int|string $post   Post object, ID, or plugin slug.
 * @param string|null         $color  Optional hex color (6 chars, no #). If null, read from post meta.
 * @return string Geopattern icon URL.
 */
function get_geopattern_icon_url( $post = null, ?string $color = null ): string {
    if ( is_string( $post ) ) {
        // Treat as slug — look up the post.
        $plugin = get_page_by_path( $post, OBJECT, 'pblsh_plugin' );
    } else {
        $plugin = get_post( $post );
    }

    if ( ! $plugin ) {
        return '';
    }

    if ( is_null( $color ) ) {
        $color = get_post_meta( $plugin->ID, 'assets_banners_color', true );
    }

    if ( strlen( $color ) === 6 && strspn( $color, 'abcdef0123456789' ) === 6 ) {
        $color = "_{$color}";
    } else {
        $color = '';
    }

    // The slug + color combine to form the cache buster, like on wordpress.org.
    $url = rest_url( 'pblsh/v1/plugins/geopattern-icon/' . $plugin->post_name . $color . '.svg' );

    return $url;
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
