<?php

namespace Pblsh;

defined('ABSPATH') || exit;

// The module's error type — the client's failures and its own arrive as WporgSvnException.
require_once PBLSH_PLUGIN_DIR . 'classes/WporgSvnException.php';


/**
 * The directory cache of wordpress.org plugins — what the plugins info API says about a
 * marker, cached per marker as post meta: the daily figures (active installs, downloads,
 * rating, the closed state) and the directory stamp, `version` and `last_updated`, the
 * change detector for the list. A function module like wporg_cache.php, deliberately apart
 * from it: the marker cache is revision-driven (SVN), this cache is time-driven — figures are
 * published once a day at 00:00 UTC and fetched when first needed after that cut-off; the
 * stamp is compared every few minutes while someone looks at the list, and only a marker
 * whose stamp moved gets the SVN refresh. The API is built for that traffic, the SVN server
 * is not. Regenerable, not part of the declared schema (docs/data-schema.md).
 */

const PBLSH_WPORG_DIRECTORY_META = '_pblsh_wporg_directory';

/** A claimed or failed figures attempt blocks the next automatic one for this long. */
const PBLSH_WPORG_FIGURES_RETRY_INTERVAL = HOUR_IN_SECONDS;

/** The site-wide claim of the last stamp check — the list compares the stamps at most this often. */
const PBLSH_WPORG_DIRECTORY_CHECKED_OPTION = 'pblsh_wporg_directory_checked_at';
const PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL = 5 * MINUTE_IN_SECONDS;


/**
 * The stored shape. `state` is the last determinate outcome; `last_error` the last
 * failed attempt beside it — never a state of its own, so a failure cannot displace
 * the last good figures. `stamp` is the directory's state at the last look, kept through
 * closures and failures. Times are Unix timestamps, 0 = never.
 */
function wporg_directory_defaults(): array {
    return [
        'state' => 'never',          // never | ok | closed | not_found
        'active_installs' => null,   // wordpress.org's rounded bucket, 1:1
        'downloaded' => null,
        'rating' => null,            // 0–100 like the API
        'num_ratings' => null,
        'closed' => null,            // { date: string|null, text: string }
        'fetched_at' => 0,           // last success of the figures
        'attempted_at' => 0,         // claim time of the last figures attempt
        'last_error' => null,        // { code, message, at }
        'stamp' => null,             // { version, last_updated } of the directory at the last look
    ];
}


function get_wporg_directory(int $plugin_id): array {
    // Re-validation when reading back from persistence: the meta may be absent (never
    // fetched) or of a foreign shape — unknown keys are dropped, missing ones read as never.
    $defaults = wporg_directory_defaults();
    $stored = get_post_meta($plugin_id, PBLSH_WPORG_DIRECTORY_META, true);
    $directory = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    $stamp = $directory['stamp'];
    $directory['stamp'] = is_array($stamp) && is_string($stamp['version'] ?? null) && is_string($stamp['last_updated'] ?? null)
        ? [ 'version' => $stamp['version'], 'last_updated' => $stamp['last_updated'] ]
        : null;
    return $directory;
}


/**
 * Whether a marker's figures are due on the automatic path, which fetches once a day:
 * the first time the figures are needed after 00:00 UTC, and not again within an hour
 * of the last attempt — whether that failed or died as a claim. The manual refresh is
 * always due (refresh_wporg_directory() with $force): one click is one request, and after a
 * failure the user wants to try again right away, not in an hour.
 */
function is_wporg_figures_due(array $directory, int $now): bool {
    $day_start = $now - ($now % DAY_IN_SECONDS);
    return (int) $directory['fetched_at'] < $day_start
        && (int) $directory['attempted_at'] <= $now - PBLSH_WPORG_FIGURES_RETRY_INTERVAL;
}


/**
 * Whether the stamps are due for a comparison: site-wide, at most every
 * PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL — the list asks on every load, the server answers
 * with a request only this often. One timestamp for all markers, not one per marker: the
 * comparison knows no failure per plugin, a marker without a listing simply has no stamp.
 */
function is_wporg_directory_check_due(int $checked_at, int $now): bool {
    return $checked_at <= $now - PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL;
}


/** When the stamps were last compared (the claim), 0 = never. */
function wporg_directory_checked_at(): int {
    return (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
}


/**
 * The directory stamp of a plugin_information result: `version`, the directory's stable
 * version, and `last_updated`, the time of the last import — it moves with every commit
 * the directory imported, tag, trunk or assets (plugin-directory: class-import.php writes
 * post_modified_gmt into last_updated). Null when the listing holds no such state (closed,
 * not found).
 */
function wporg_directory_stamp(array $result): ?array {
    if ($result['state'] !== 'ok') {
        return null;
    }
    $data = $result['data'];
    $last_updated = (string) ($data['last_updated'] ?? '');
    return $last_updated === '' ? null : [ 'version' => (string) ($data['version'] ?? ''), 'last_updated' => $last_updated ];
}


/**
 * One batched request per 100 slugs for every marker (or the one) whose figures are due,
 * and for every marker in scope when the stamp check is due; $force — the manual refresh —
 * is due for both. Writes the due figures and compares every stamp with the stored one.
 * Answers `figures`: the IDs of every marker whose figures were touched — successes and
 * failed attempts alike, so the caller can serve their new state — and `changed`: the IDs
 * whose stamp moved since the last look, the ones the caller refreshes against SVN. The
 * first look only records the stamp.
 *
 * @return array{figures: int[], changed: int[]}
 */
function refresh_wporg_directory(?int $plugin_id = null, bool $force = false): array {
    if ($plugin_id === null) {
        $markers = get_posts([ 'post_type' => 'pblsh_wporg_plugin', 'post_status' => 'any', 'posts_per_page' => -1 ]);
    } else {
        $marker = get_post($plugin_id);
        $markers = is_wporg_plugin($marker) ? [ $marker ] : [];
    }
    $nothing = [ 'figures' => [], 'changed' => [] ];
    if ($markers === []) {
        return $nothing;
    }

    // The claims: attempted_at per marker and the site-wide checked_at are written before
    // the remote call, so a request dying mid-way (fatal, OOM) does not cause a retry
    // storm — the automatic paths wait. Read-then-write on post meta, not atomic: a rare
    // second request from a parallel tab is harmless.
    $now = time();
    $check_due = $force || is_wporg_directory_check_due(wporg_directory_checked_at(), $now);
    if ($check_due) {
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, $now, false);
    }
    $wanted = [];
    foreach ($markers as $marker) {
        $directory = get_wporg_directory((int) $marker->ID);
        $figures_due = $force || is_wporg_figures_due($directory, $now);
        if (!$figures_due && !$check_due) {
            continue;
        }
        if ($figures_due) {
            $directory['attempted_at'] = $now;
            update_post_meta((int) $marker->ID, PBLSH_WPORG_DIRECTORY_META, $directory);
        }
        $wanted[$marker->post_name] = [ (int) $marker->ID, $directory, $figures_due ];
    }
    if ($wanted === []) {
        return $nothing;
    }

    raise_wporg_time_limit();
    $fields = wporg_api_fields([ 'active_installs', 'downloaded', 'rating' ]);
    $figures = [];
    $changed = [];
    // One client call per chunk, so a transport failure marks only the slugs of the
    // chunk it hit — the chunks before it are already written.
    foreach (array_chunk(array_keys($wanted), PBLSH_WPORG_API_SLUGS_PER_REQUEST) as $slugs) {
        try {
            $results = wporg_api_plugin_information($slugs, $fields);
        } catch (WporgSvnException $e) {
            foreach ($slugs as $slug) {
                [$id, $directory, $figures_due] = $wanted[$slug];
                if ($figures_due) {
                    update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, wporg_directory_with_error($directory, $e, $now));
                    $figures[] = $id;
                }
            }
            continue;
        }
        foreach ($slugs as $slug) {
            [$id, $directory, $figures_due] = $wanted[$slug];
            $next = $directory;
            if ($figures_due) {
                try {
                    $next = [ ...wporg_figures_from_listing($results[$slug], $now), 'stamp' => $directory['stamp'] ];
                } catch (WporgSvnException $e) {
                    $next = wporg_directory_with_error($directory, $e, $now);
                }
                $figures[] = $id;
            }
            $stamp = wporg_directory_stamp($results[$slug]);
            if ($stamp !== null) {
                if ($directory['stamp'] !== null && $stamp !== $directory['stamp']) {
                    $changed[] = $id;
                }
                $next['stamp'] = $stamp;
            }
            update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, $next);
        }
    }
    return [ 'figures' => $figures, 'changed' => $changed ];
}


/**
 * The stored figures for one plugin_information result (see wporg_api_plugin_state()):
 * a determinate outcome replaces state, figures and closed facts and clears the last
 * error; the stamp is the caller's. A listing without installation figures is an error
 * of its own, not a state.
 *
 * @param array{state:string, data:array} $result
 * @throws WporgSvnException wporg_stats_incomplete
 */
function wporg_figures_from_listing(array $result, int $attempted_at): array {
    $directory = [
        ...wporg_directory_defaults(),
        'state' => $result['state'],
        'fetched_at' => $attempted_at,
        'attempted_at' => $attempted_at,
    ];
    if ($result['state'] === 'closed') {
        $directory['closed'] = [ 'date' => $result['data']['closed']['date'], 'text' => $result['data']['closed']['text'] ];
        return $directory;
    }
    if ($result['state'] !== 'ok') {
        return $directory;
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
    $directory['active_installs'] = (int) $data['active_installs'];
    $directory['downloaded'] = $figure($data['downloaded'] ?? null);
    $directory['rating'] = $figure($data['rating'] ?? null);
    $directory['num_ratings'] = $figure($data['num_ratings'] ?? null);
    return $directory;
}


/** The previous figures with the failed attempt recorded beside them — code and message intact. */
function wporg_directory_with_error(array $directory, WporgSvnException $e, int $at): array {
    $directory['last_error'] = [ 'code' => $e->get_error_code(), 'message' => $e->getMessage(), 'at' => $at ];
    return $directory;
}


/**
 * The REST view of a marker's figures: `installations` — the list's and the header's
 * cell, one shape with the self-hosted count (serialize_self_hosted_installations()) —
 * and `wporg_stats`, the editor's dashboard. Unix times become ISO 8601 UTC, never → null.
 *
 * @return array{installations: array, wporg_stats: array}
 */
function serialize_wporg_installations(int $plugin_id): array {
    $directory = get_wporg_directory($plugin_id);
    $iso = static fn(int $timestamp): ?string => $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    return [
        'installations' => [
            'state' => $directory['state'],
            'count' => $directory['active_installs'],
            'fetched_at' => $iso((int) $directory['fetched_at']),
            'attempted_at' => $iso((int) $directory['attempted_at']),
            'last_error' => $directory['last_error'] === null ? null : [
                'code' => $directory['last_error']['code'],
                'message' => $directory['last_error']['message'],
                'at' => $iso((int) $directory['last_error']['at']),
            ],
        ],
        'wporg_stats' => [
            'downloaded' => $directory['downloaded'],
            'rating' => $directory['rating'],
            'num_ratings' => $directory['num_ratings'],
            'closed' => $directory['closed'],
            'fetched_at' => $iso((int) $directory['fetched_at']),
        ],
    ];
}
