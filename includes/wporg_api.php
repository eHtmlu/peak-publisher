<?php

namespace Pblsh;

defined('ABSPATH') || exit;

// The module's error type — every failure it reports is a WporgSvnException.
require_once PBLSH_PLUGIN_DIR . 'classes/WporgSvnException.php';


/**
 * Client for the wordpress.org plugins info API (api.wordpress.org/plugins/info/1.2) —
 * a function module like wporg_cache.php. Transport and format failures throw
 * WporgSvnException('wporg_api_unavailable'); the consumer decides whether to degrade
 * (the directory hint falls back to 'unknown') or to surface the error (discovery).
 */

const PBLSH_WPORG_API_URL = 'https://api.wordpress.org/plugins/info/1.2/';

/** The API answers 422 above this many slugs per plugin_information request. */
const PBLSH_WPORG_API_SLUGS_PER_REQUEST = 100;

/**
 * Every switchable field of plugin_information. wporg_api_fields() disables all but the
 * wanted ones, so a request carries only what its consumer reads (a few hundred bytes
 * instead of the full listing). name, slug, version, author, author_profile,
 * requires_plugins, num_ratings and support_threads* cannot be switched off.
 */
const PBLSH_WPORG_API_OPTIONAL_FIELDS = [
    'active_installs', 'added', 'banners', 'compatibility', 'contributors', 'description', 'donate_link',
    'downloaded', 'download_link', 'homepage', 'icons', 'last_updated', 'rating', 'ratings', 'reviews',
    'requires', 'requires_php', 'sections', 'short_description', 'tags', 'tested', 'stable_tag', 'blocks',
    'block_assets', 'author_block_count', 'author_block_rating', 'language_packs', 'versions', 'screenshots',
    'blueprints', 'preview_link', 'upgrade_notice', 'business_model', 'repository_url', 'support_url',
    'commercial_support_url',
];


/**
 * Builds the fields parameter in the API's minus form: every switchable field disabled
 * except the wanted ones. (The array form fields[x]=false does not disable anything —
 * the API casts the string "false" to true.)
 *
 * @param string[] $wanted Field names to include.
 */
function wporg_api_fields(array $wanted): string {
    $fields = [];
    foreach (PBLSH_WPORG_API_OPTIONAL_FIELDS as $field) {
        $fields[] = (in_array($field, $wanted, true) ? '' : '-') . $field;
    }
    return implode(',', $fields);
}


/**
 * Reads the directory state of the given plugins in one request per 100 slugs.
 *
 * @param string[] $slugs  Normalized plugin slugs.
 * @param string   $fields The fields parameter (see wporg_api_fields()).
 * @return array<string, array{state:string, data:array}> Keyed by slug. state: ok (data = the
 *         listing as delivered) | closed (data = { name, closed: { date, text, reason } }, the
 *         API's false values as null, its description sentence as closed.text) | not_found
 *         (data = []). Covers closed AND temporarily disabled plugins as closed — the API
 *         reports both the same way.
 * @throws WporgSvnException wporg_api_unavailable on transport or format failures.
 */
function wporg_api_plugin_information(array $slugs, string $fields): array {
    $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs), static fn(string $slug): bool => $slug !== '')));

    $out = [];
    foreach (array_chunk($slugs, PBLSH_WPORG_API_SLUGS_PER_REQUEST) as $chunk) {
        // The slugs form always answers HTTP 200 with a map slug → listing; misses and
        // closures travel per slug inside the map.
        $response = wporg_api_get([
            'action' => 'plugin_information',
            'request' => [
                'slugs' => implode(',', $chunk),
                'fields' => $fields,
            ],
        ]);
        foreach ($chunk as $slug) {
            $listing = $response[$slug] ?? null;
            if (!is_array($listing)) {
                throw wporg_api_unavailable();
            }
            $out[$slug] = wporg_api_plugin_state($listing);
        }
    }

    return $out;
}


/**
 * Lists the plugins a wordpress.org account submitted (query_plugins by author).
 *
 * @return array<int, array{slug:string, name:string, icon:string|null, active_installs:int|null}>
 * @throws WporgSvnException Invalid username (from normalize_wporg_username) or wporg_api_unavailable.
 */
function wporg_api_query_plugins_by_author(string $username): array {
    $normalized_username = normalize_wporg_username($username);
    if (is_wp_error($normalized_username)) {
        throw WporgSvnException::from_wp_error($normalized_username);
    }

    $per_page = 250;
    $page = 1;
    $pages = 1;
    $seen = [];
    $plugins = [];

    do {
        $data = wporg_api_get([
            'action' => 'query_plugins',
            'request' => [
                'author' => $normalized_username,
                'per_page' => $per_page,
                'page' => $page,
                // icons are an opt-in field of the 1.2 API; active_installs is a default.
                'fields' => 'icons',
            ],
        ]);
        if (!is_array($data['plugins'] ?? null)) {
            throw wporg_api_unavailable();
        }

        $response_plugins = $data['plugins'];
        foreach ($response_plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $slug = normalize_plugin_slug($plugin['slug'] ?? null);
            if (is_wp_error($slug) || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $name = wporg_api_clean_text($plugin['name'] ?? '');
            $plugins[] = [
                'slug' => $slug,
                'name' => $name ?? $slug,
                'icon' => wporg_api_pick_icon_url($plugin['icons'] ?? null),
                'active_installs' => is_numeric($plugin['active_installs'] ?? null) ? (int) $plugin['active_installs'] : null,
            ];
        }

        $info = is_array($data['info'] ?? null) ? $data['info'] : [];
        $pages_from_response = isset($info['pages']) ? (int) $info['pages'] : 0;
        if ($pages_from_response > 0) {
            $pages = $pages_from_response;
        } elseif (count($response_plugins) >= $per_page) {
            $pages = $page + 1;
        } else {
            $pages = $page;
        }

        $page++;
    } while ($page <= $pages);

    return $plugins;
}


/** Picks the preferred icon URL from a wordpress.org icons map (or null). */
function wporg_api_pick_icon_url($icons): ?string {
    if (!is_array($icons)) {
        return null;
    }
    foreach ([ 'svg', '2x', '1x', 'default' ] as $icon_key) {
        if (!empty($icons[$icon_key]) && is_string($icons[$icon_key])) {
            return $icons[$icon_key];
        }
    }
    return null;
}


/**
 * A directory display text cleaned for direct output: tags stripped, entities decoded,
 * trimmed; null for anything that is not a non-empty string (the API uses false for
 * absent values).
 */
function wporg_api_clean_text($value): ?string {
    $text = trim(html_entity_decode(wp_strip_all_tags(wporg_string_from_value($value)), ENT_QUOTES | ENT_HTML5));
    return $text !== '' ? $text : null;
}


/**
 * The one error of this module, constructed where it is thrown.
 */
function wporg_api_unavailable(): WporgSvnException {
    return new WporgSvnException(
        'wporg_api_unavailable',
        __('wordpress.org API unavailable, try again later.', 'peak-publisher'),
        503
    );
}


/**
 * GET against the info API, decoded. Every non-2xx status, transport error or non-JSON
 * body is wporg_api_unavailable — the slugs form and query_plugins never encode a
 * determinate outcome in the HTTP status.
 *
 * @throws WporgSvnException
 */
function wporg_api_get(array $query): array {
    $response = wp_remote_get(add_query_arg($query, PBLSH_WPORG_API_URL), [
        'timeout' => 20,
        'redirection' => 3,
        'user-agent' => wporg_user_agent(),
    ]);
    if (is_wp_error($response)) {
        throw wporg_api_unavailable();
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        throw wporg_api_unavailable();
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        throw wporg_api_unavailable();
    }

    return $data;
}


/**
 * Classifies one plugin_information listing by the API's own contract: closed plugins
 * answer with error "closed" (plus the closed flag), every other error is a miss, and a
 * successful listing never carries an error key.
 */
function wporg_api_plugin_state(array $listing): array {
    if (!isset($listing['error'])) {
        return [ 'state' => 'ok', 'data' => $listing ];
    }

    if ($listing['error'] === 'closed' || !empty($listing['closed'])) {
        return [
            'state' => 'closed',
            'data' => [
                'name' => wporg_api_clean_text($listing['name'] ?? ''),
                'closed' => [
                    'date' => wporg_api_clean_text($listing['closed_date'] ?? ''),
                    'text' => (string) wporg_api_clean_text($listing['description'] ?? ''),
                    'reason' => wporg_api_clean_text($listing['reason_text'] ?? ''),
                ],
            ],
        ];
    }

    return [ 'state' => 'not_found', 'data' => [] ];
}
