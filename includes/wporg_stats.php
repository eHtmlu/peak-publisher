<?php

namespace Pblsh;

defined('ABSPATH') || exit;

// The module's error type — the client's failures and its own arrive as WporgSvnException.
require_once PBLSH_PLUGIN_DIR . 'classes/WporgSvnException.php';


/**
 * The daily figures of wordpress.org plugins — active installs, downloads, rating and
 * the closed state — read from the plugins info API and cached per marker as post
 * meta. A function module like wporg_cache.php, deliberately apart from it: the marker
 * cache is revision-driven (SVN), this cache is time-driven — wordpress.org publishes
 * new figures once a day at 00:00 UTC, so they are fetched when they are first needed
 * after that cut-off. Regenerable, not part of the declared schema (docs/data-schema.md).
 */

const PBLSH_WPORG_STATS_META = '_pblsh_wporg_stats';

/** A claimed or failed attempt blocks the next one for this long. */
const PBLSH_WPORG_STATS_RETRY_INTERVAL = HOUR_IN_SECONDS;


/**
 * The stored shape. `state` is the last determinate outcome; `last_error` the last
 * failed attempt beside it — never a state of its own, so a failure cannot displace
 * the last good figures. Times are Unix timestamps, 0 = never.
 */
function wporg_stats_defaults(): array {
    return [
        'state' => 'never',          // never | ok | closed | not_found
        'active_installs' => null,   // wordpress.org's rounded bucket, 1:1
        'downloaded' => null,
        'rating' => null,            // 0–100 like the API
        'num_ratings' => null,
        'closed' => null,            // { date: string|null, text: string }
        'fetched_at' => 0,           // last success
        'attempted_at' => 0,         // claim time of the last attempt
        'last_error' => null,        // { code, message, at }
    ];
}


function get_wporg_stats(int $plugin_id): array {
    // Re-validation when reading back from persistence: the meta may be absent (never
    // fetched) or of a foreign shape — unknown keys are dropped, missing ones read as never.
    $defaults = wporg_stats_defaults();
    $stored = get_post_meta($plugin_id, PBLSH_WPORG_STATS_META, true);
    return is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
}


/**
 * Whether a marker's figures are due, the one rule for both paths. The automatic path
 * fetches once a day: the first time the figures are needed after 00:00 UTC, and not
 * again within an hour of the last attempt (a claim whose request died). The manual
 * path ($force) skips the daily cut-off and is blocked only within an hour after a
 * *failed* attempt — after a success a repeat is fine: a freshly approved plugin turns
 * from not_found to ok with its first commit, and the user wants to see that.
 */
function is_wporg_stats_refresh_due(array $stats, bool $force, int $now): bool {
    $retry_after = $now - PBLSH_WPORG_STATS_RETRY_INTERVAL;
    if ($force) {
        return (int) ($stats['last_error']['at'] ?? 0) <= $retry_after;
    }
    $day_start = $now - ($now % DAY_IN_SECONDS);
    return (int) $stats['fetched_at'] < $day_start && (int) $stats['attempted_at'] <= $retry_after;
}


/**
 * Fetches the due figures of every marker (or of one) in one batched request per 100
 * slugs and writes them. Returns the IDs of every marker touched — successes and
 * failed attempts alike — so the caller can serve their new state.
 *
 * @return int[]
 */
function refresh_wporg_stats(?int $plugin_id = null, bool $force = false): array {
    if ($plugin_id === null) {
        $markers = get_posts([ 'post_type' => 'pblsh_wporg_plugin', 'post_status' => 'any', 'posts_per_page' => -1 ]);
    } else {
        $marker = get_post($plugin_id);
        $markers = is_wporg_plugin($marker) ? [ $marker ] : [];
    }

    // The claim: attempted_at is written before the remote call, so a request dying
    // mid-way (fatal, OOM) does not cause a retry storm — the automatic path waits an
    // hour, the manual one stays open (no failed attempt was recorded). Read-then-write
    // on post meta, not atomic: a rare second request from a parallel tab is harmless.
    $now = time();
    $due = [];
    foreach ($markers as $marker) {
        $stats = get_wporg_stats((int) $marker->ID);
        if (!is_wporg_stats_refresh_due($stats, $force, $now)) {
            continue;
        }
        $stats['attempted_at'] = $now;
        update_post_meta((int) $marker->ID, PBLSH_WPORG_STATS_META, $stats);
        $due[$marker->post_name] = [ (int) $marker->ID, $stats ];
    }
    if ($due === []) {
        return [];
    }

    raise_wporg_time_limit();
    $fields = wporg_api_fields([ 'active_installs', 'downloaded', 'rating' ]);
    $touched = [];
    // One client call per chunk, so a transport failure marks only the slugs of the
    // chunk it hit — the chunks before it are already written.
    foreach (array_chunk(array_keys($due), PBLSH_WPORG_API_SLUGS_PER_REQUEST) as $slugs) {
        try {
            $results = wporg_api_plugin_information($slugs, $fields);
        } catch (WporgSvnException $e) {
            foreach ($slugs as $slug) {
                [$id, $stats] = $due[$slug];
                update_post_meta($id, PBLSH_WPORG_STATS_META, wporg_stats_with_error($stats, $e, $now));
                $touched[] = $id;
            }
            continue;
        }
        foreach ($slugs as $slug) {
            [$id, $stats] = $due[$slug];
            try {
                $next = wporg_stats_from_listing($results[$slug], $now);
            } catch (WporgSvnException $e) {
                $next = wporg_stats_with_error($stats, $e, $now);
            }
            update_post_meta($id, PBLSH_WPORG_STATS_META, $next);
            $touched[] = $id;
        }
    }
    return $touched;
}


/**
 * The stored figures for one plugin_information result (see wporg_api_plugin_state()):
 * a determinate outcome replaces state, figures and closed facts and clears the last
 * error. A listing without installation figures is an error of its own, not a state.
 *
 * @param array{state:string, data:array} $result
 * @throws WporgSvnException wporg_stats_incomplete
 */
function wporg_stats_from_listing(array $result, int $attempted_at): array {
    $stats = [
        ...wporg_stats_defaults(),
        'state' => $result['state'],
        'fetched_at' => $attempted_at,
        'attempted_at' => $attempted_at,
    ];
    if ($result['state'] === 'closed') {
        $stats['closed'] = [ 'date' => $result['data']['closed']['date'], 'text' => $result['data']['closed']['text'] ];
        return $stats;
    }
    if ($result['state'] !== 'ok') {
        return $stats;
    }

    $data = $result['data'];
    if (!is_numeric($data['active_installs'] ?? null)) {
        throw new WporgSvnException(
            'wporg_stats_incomplete',
            __('wordpress.org answered without installation figures.', 'peak-publisher'),
            502
        );
    }
    $figure = static fn($value): ?int => is_numeric($value) ? (int) $value : null;
    $stats['active_installs'] = (int) $data['active_installs'];
    $stats['downloaded'] = $figure($data['downloaded'] ?? null);
    $stats['rating'] = $figure($data['rating'] ?? null);
    $stats['num_ratings'] = $figure($data['num_ratings'] ?? null);
    return $stats;
}


/** The previous figures with the failed attempt recorded beside them — code and message intact. */
function wporg_stats_with_error(array $stats, WporgSvnException $e, int $at): array {
    $stats['last_error'] = [ 'code' => $e->get_error_code(), 'message' => $e->getMessage(), 'at' => $at ];
    return $stats;
}


/**
 * The REST view of a marker's figures: `installations` — the list's and the header's
 * cell, one shape with the self-hosted count (serialize_self_hosted_installations()) —
 * and `wporg_stats`, the editor's dashboard. Unix times become ISO 8601 UTC, never → null.
 *
 * @return array{installations: array, wporg_stats: array}
 */
function serialize_wporg_installations(int $plugin_id): array {
    $stats = get_wporg_stats($plugin_id);
    $iso = static fn(int $timestamp): ?string => $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    return [
        'installations' => [
            'state' => $stats['state'],
            'count' => $stats['active_installs'],
            'fetched_at' => $iso((int) $stats['fetched_at']),
            'attempted_at' => $iso((int) $stats['attempted_at']),
            'last_error' => $stats['last_error'] === null ? null : [
                'code' => $stats['last_error']['code'],
                'message' => $stats['last_error']['message'],
                'at' => $iso((int) $stats['last_error']['at']),
            ],
        ],
        'wporg_stats' => [
            'downloaded' => $stats['downloaded'],
            'rating' => $stats['rating'],
            'num_ratings' => $stats['num_ratings'],
            'closed' => $stats['closed'],
            'fetched_at' => $iso((int) $stats['fetched_at']),
        ],
    ];
}
