<?php

namespace Pblsh;

defined('ABSPATH') || exit;

// The module's error type — the client's failures and its own arrive as WporgSvnException.
require_once PBLSH_PLUGIN_DIR . 'classes/WporgSvnException.php';


/**
 * The directory cache of wordpress.org plugins — what the plugins info API says about a
 * marker, cached per marker as post meta: its figures (active installs, downloads, rating, the
 * closed state) and the directory stamp — `version`, `last_updated` and the asset revisions —,
 * the change detector for the list — with the check's own record: when a plugin was last
 * brought in step with wordpress.org, and the last failure since. A function module like
 * wporg_cache.php, deliberately apart from it: the marker cache is revision-driven (SVN), this
 * cache is time-driven — the stamps are compared every few minutes while someone looks at the
 * list, every listing that check reads brings the figures along, and only a marker whose stamp
 * moved gets the SVN refresh. wordpress.org serves each plugin's listing from a cache of up to
 * a day, rebuilt at a different time for every plugin (plugin-directory:
 * class-plugins-info-api.php), so new figures appear at any hour; taking them with every check
 * costs no request of its own. The API is built for that traffic, the SVN server is not.
 * Regenerable, not part of the declared schema (docs/data-schema.md).
 */

const PBLSH_WPORG_DIRECTORY_META = '_pblsh_wporg_directory';

/** The site-wide claim of the last stamp check — the list compares the stamps at most this often. */
const PBLSH_WPORG_DIRECTORY_CHECKED_OPTION = 'pblsh_wporg_directory_checked_at';
const PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL = 5 * MINUTE_IN_SECONDS;


/**
 * The stored shape. `state` is the outcome of the last listing that had one, with its
 * figures — a failed check leaves them standing. The check has its own record: `checked_at`
 * is when the plugin was last brought in step with wordpress.org — its stamp compared and,
 * had it moved, the SVN refresh completed, or SVN read directly —, `stamp` the directory's
 * state that check found, kept through closures and failures, and `check_error` the last
 * failed check since: wordpress.org unreachable, a listing without figures, an SVN refresh
 * that failed. `downloads_fetched_at` is the day's claim of the download history's fetch
 * (refresh_wporg_download_stats()). Times are Unix timestamps, 0 = never.
 */
function wporg_directory_defaults(): array {
    return [
        'state' => 'never',          // never | ok | closed | not_found
        'active_installs' => null,   // wordpress.org's rounded bucket, 1:1
        'downloaded' => null,
        'rating' => null,            // 0–100 like the API
        'num_ratings' => null,
        'closed' => null,            // { date: string|null, text: string }
        'stamp' => null,             // { version, last_updated, assets } of the directory at the last completed check
        'checked_at' => 0,           // the last completed check
        'check_error' => null,       // { code, message, at }, null after a completed check
        'downloads_fetched_at' => 0, // the last fetch of the download history, claimed before the request
    ];
}


function get_wporg_directory(int $plugin_id): array {
    // Re-validation when reading back from persistence: the meta may be absent (never
    // fetched) or of a foreign shape — unknown keys are dropped, missing ones read as never.
    $defaults = wporg_directory_defaults();
    $stored = get_post_meta($plugin_id, PBLSH_WPORG_DIRECTORY_META, true);
    $directory = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    $directory['stamp'] = wporg_directory_stored_stamp($directory['stamp']);
    return $directory;
}


/** A stored stamp as wporg_directory_stamp() shapes it, or null for any other shape: no stamp, the next check is a first look. */
function wporg_directory_stored_stamp($stamp): ?array {
    if (!is_array($stamp) || !is_string($stamp['version'] ?? null) || !is_string($stamp['last_updated'] ?? null) || !is_array($stamp['assets'] ?? null)) {
        return null;
    }
    foreach ($stamp['assets'] as $filename => $revision) {
        if (!is_string($filename) || !is_int($revision)) {
            return null;
        }
    }
    return [ 'version' => $stamp['version'], 'last_updated' => $stamp['last_updated'], 'assets' => $stamp['assets'] ];
}


/**
 * Whether the stamps are due for a comparison: site-wide, at most every
 * PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL — however often clients ask, the server answers with
 * a request only this often, and tells them when the next one is due
 * (wporg_directory_next_check_in()). One clock for all markers; what the comparison found
 * for each is the marker's own record (checked_at, check_error).
 */
function is_wporg_directory_check_due(int $checked_at, int $now): bool {
    return $checked_at <= $now - PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL;
}


/** When the stamps were last compared (the claim), 0 = never. */
function wporg_directory_checked_at(): int {
    return (int) get_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, 0);
}


/**
 * The directory stamp of a plugin_information result — what the directory shows of the
 * plugin's SVN state, the change detector: `version`, the directory's stable version;
 * `last_updated`, which wordpress.org's import bumps only when the version changed or the
 * commit touched the stable tag's folder (plugin-directory: class-import.php, "Bump last
 * updated if") — an assets commit of a plugin with a tagged release and a trunk commit
 * leave it alone —; and `assets`, the revision of every asset the directory serves, by
 * filename (wporg_api_asset_revisions()), which every assets commit moves. Null when the
 * listing holds no such state (closed, not found).
 */
function wporg_directory_stamp(array $result): ?array {
    if ($result['state'] !== 'ok') {
        return null;
    }
    $data = $result['data'];
    $last_updated = (string) ($data['last_updated'] ?? '');
    if ($last_updated === '') {
        return null;
    }
    return [
        'version' => (string) ($data['version'] ?? ''),
        'last_updated' => $last_updated,
        'assets' => wporg_api_asset_revisions($data),
    ];
}


/**
 * One batched request per 100 slugs. Site-wide ($plugin_ids null): every marker, when the
 * stamp check is due. For the given markers alone — the editor's refresh of one, the markers
 * an import just created —: those, always; such a look neither consults nor claims the
 * site-wide check, which stays the list's. Every listing brings the marker's figures, and
 * every stamp is compared with the stored one: an unchanged stamp, a first look and a listing
 * without one (closed, not found) complete the marker's check here. A stamp that moved does
 * not — it is answered, ID → the new stamp, and the check completes when the caller has
 * refreshed the plugin against SVN (confirm_wporg_directory_check()); until then the old
 * stamp stands, so a refresh that failed is found again by the next check. $force — the
 * editor's refresh — completes no check here either: that caller reads SVN whatever the
 * directory says, and the check is that read's to complete. A listing that failed —
 * wordpress.org unreachable, or answering without installation figures — is the failed
 * check of the markers it concerns; their figures and stamp stand.
 *
 * @param int[]|null $plugin_ids
 * @return array<int, array{version:string, last_updated:string}|null> the checks left open
 *         for the caller, by marker ID: the stamp to confirm with (null = the listing holds none)
 */
function refresh_wporg_directory(?array $plugin_ids = null, bool $force = false): array {
    $site_wide = $plugin_ids === null;
    $now = time();
    // The site-wide claim is written before the remote call, so a request dying mid-way
    // (fatal, OOM) does not cause a retry storm — the clients wait for the next check. It is
    // written with nothing to compare too: the clients ask again when it says so, never at once.
    if ($site_wide) {
        if (!$force && !is_wporg_directory_check_due(wporg_directory_checked_at(), $now)) {
            return [];
        }
        update_option(PBLSH_WPORG_DIRECTORY_CHECKED_OPTION, $now, false);
    }
    $markers = $site_wide
        ? get_posts([ 'post_type' => 'pblsh_wporg_plugin', 'post_status' => 'any', 'posts_per_page' => -1 ])
        : array_values(array_filter(array_map('get_post', $plugin_ids), static fn($post): bool => is_wporg_plugin($post)));
    if ($markers === []) {
        return [];
    }
    $wanted = [];
    foreach ($markers as $marker) {
        $wanted[$marker->post_name] = [ (int) $marker->ID, get_wporg_directory((int) $marker->ID) ];
    }

    raise_wporg_time_limit();
    // The figures, and the stamp's fields: last_updated and the asset URLs with their revisions.
    $fields = wporg_api_fields([ 'active_installs', 'downloaded', 'rating', 'last_updated', 'icons', 'banners', 'screenshots' ]);
    $open = [];
    // One client call per chunk, so a transport failure marks only the slugs of the
    // chunk it hit — the chunks before it are already written.
    foreach (array_chunk(array_keys($wanted), PBLSH_WPORG_API_SLUGS_PER_REQUEST) as $slugs) {
        try {
            $results = wporg_api_plugin_information($slugs, $fields);
        } catch (WporgSvnException $e) {
            foreach ($slugs as $slug) {
                [$id, $directory] = $wanted[$slug];
                update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, wporg_directory_check_failed($directory, $e, $now));
            }
            continue;
        }
        foreach ($slugs as $slug) {
            [$id, $directory] = $wanted[$slug];
            try {
                $next = [ ...$directory, ...wporg_figures_from_listing($results[$slug]) ];
            } catch (WporgSvnException $e) {
                update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, wporg_directory_check_failed($directory, $e, $now));
                continue;
            }
            $stamp = wporg_directory_stamp($results[$slug]);
            if ($force || ($stamp !== null && $directory['stamp'] !== null && $stamp !== $directory['stamp'])) {
                $open[$id] = $stamp;
            } else {
                $next = wporg_directory_checked($next, $stamp, $now);
            }
            update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, $next);
        }
    }
    return $open;
}


/**
 * The directory with a completed check: when, the stamp it found — null when the listing
 * holds none or SVN was read without asking the directory, the last one stays then — and no
 * failure left.
 */
function wporg_directory_checked(array $directory, ?array $stamp, int $at): array {
    return [ ...$directory, 'stamp' => $stamp ?? $directory['stamp'], 'checked_at' => $at, 'check_error' => null ];
}


/**
 * Completes a marker's check after its SVN refresh: the plugin is in step with wordpress.org
 * as of now, and $stamp — the one refresh_wporg_directory() left open, null when the
 * directory gave none — is the next comparison.
 */
function confirm_wporg_directory_check(int $plugin_id, ?array $stamp = null): void {
    update_post_meta($plugin_id, PBLSH_WPORG_DIRECTORY_META, wporg_directory_checked(get_wporg_directory($plugin_id), $stamp, time()));
}


/** Records a marker's failed SVN refresh as its failed check — the stamp and checked_at stay, the next check tries again. */
function record_wporg_directory_check_error(int $plugin_id, WporgSvnException $e): void {
    update_post_meta($plugin_id, PBLSH_WPORG_DIRECTORY_META, wporg_directory_check_failed(get_wporg_directory($plugin_id), $e, time()));
}


/** Seconds until the site-wide stamp check is due again, 0 = now — what the client's timer waits for. */
function wporg_directory_next_check_in(int $now): int {
    return max(0, wporg_directory_checked_at() + PBLSH_WPORG_DIRECTORY_CHECK_INTERVAL - $now);
}


/**
 * The figures of one plugin_information result (see wporg_api_plugin_state()): a
 * determinate outcome — listed, closed, not found — with its figures and closed facts,
 * replacing the stored ones; the check's record is the caller's. A listing without
 * installation figures has no outcome: it fails.
 *
 * @param array{state:string, data:array} $result
 * @return array{state:string, active_installs:?int, downloaded:?int, rating:?int, num_ratings:?int, closed:?array}
 * @throws WporgSvnException wporg_stats_incomplete
 */
function wporg_figures_from_listing(array $result): array {
    $figures = [ 'state' => $result['state'], 'active_installs' => null, 'downloaded' => null, 'rating' => null, 'num_ratings' => null, 'closed' => null ];
    if ($result['state'] === 'closed') {
        $figures['closed'] = [ 'date' => $result['data']['closed']['date'], 'text' => $result['data']['closed']['text'] ];
        return $figures;
    }
    if ($result['state'] !== 'ok') {
        return $figures;
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
    $figures['active_installs'] = (int) $data['active_installs'];
    $figures['downloaded'] = $figure($data['downloaded'] ?? null);
    $figures['rating'] = $figure($data['rating'] ?? null);
    $figures['num_ratings'] = $figure($data['num_ratings'] ?? null);
    return $figures;
}


/** The directory with a failed check: figures, stamp and checked_at stand, the failure beside them. */
function wporg_directory_check_failed(array $directory, WporgSvnException $e, int $at): array {
    return [ ...$directory, 'check_error' => wporg_directory_error($e, $at) ];
}


/** A failure as the cache stores it — code and message intact. */
function wporg_directory_error(WporgSvnException $e, int $at): array {
    return [ 'code' => $e->get_error_code(), 'message' => $e->getMessage(), 'at' => $at ];
}


/**
 * The download history of the given markers, brought up to date from the stats API: once
 * per marker and UTC day, the API's last PBLSH_WPORG_DOWNLOAD_STATS_DAYS complete days
 * merged into the history kept here (PBLSH_DOWNLOADS_META) — the API's day wins within its
 * window, the days it no longer serves stay: the window moves on, the history does not.
 * Once a day, because the API serves complete days only: a day's fetch brings yesterday at
 * the latest with the next day's. The day's claim is written before the request, like the
 * directory's: a failed fetch is tried again tomorrow, with nothing lost to the window.
 * Called where the directory is refreshed — the client's look and the import; the
 * directory check itself stays the stamps' (refresh_wporg_directory()).
 *
 * @param \WP_Post[] $markers
 */
function refresh_wporg_download_stats(array $markers, int $now): void {
    foreach ($markers as $marker) {
        $id = (int) $marker->ID;
        $directory = get_wporg_directory($id);
        if (!is_wporg_download_stats_due((int) $directory['downloads_fetched_at'], $now)) {
            continue;
        }
        update_post_meta($id, PBLSH_WPORG_DIRECTORY_META, [ ...$directory, 'downloads_fetched_at' => $now ]);
        raise_wporg_time_limit();
        try {
            $api_days = wporg_api_download_stats($marker->post_name);
        } catch (WporgSvnException $e) {
            continue;
        }
        update_post_meta($id, PBLSH_DOWNLOADS_META, wporg_directory_merge_downloads(get_plugin_downloads($id), $api_days));
    }
}


/** Whether a marker's download history is due for its daily fetch: not yet fetched on the current UTC day. */
function is_wporg_download_stats_due(int $fetched_at, int $now): bool {
    return $fetched_at <= 0 || gmdate('Y-m-d', $fetched_at) !== gmdate('Y-m-d', $now);
}


/**
 * A download history with the API's days merged in: the API's count replaces the stored
 * one for every day it serves, the other days stay; days without downloads are not kept.
 *
 * @param array<string, int> $days     The history as stored (get_plugin_downloads()).
 * @param array<string, int> $api_days The API's days (wporg_api_download_stats()).
 * @return array<string, int> In day order.
 */
function wporg_directory_merge_downloads(array $days, array $api_days): array {
    foreach ($api_days as $day => $count) {
        if ($count > 0) {
            $days[$day] = $count;
        } else {
            unset($days[$day]);
        }
    }
    ksort($days, SORT_STRING);
    return $days;
}


/**
 * The REST view of a marker's directory cache: `installations` — the list's and the
 * header's cell, one shape with the self-hosted count (serialize_self_hosted_installations())
 * —, `downloads`, the figures in the shape of the self-hosted ones
 * (summarize_plugin_downloads()): the directory's all-time total and the windows of the
 * history fetched from the stats API, null until there is one —, `wporg_stats`, the editor's
 * dashboard, and `wporg_check`, when the plugin was last brought in step with wordpress.org
 * and the last failure since. Unix times become ISO 8601 UTC, never → null.
 *
 * @return array{installations: array, downloads: array, wporg_stats: array, wporg_check: array}
 */
function serialize_wporg_directory(int $plugin_id): array {
    $directory = get_wporg_directory($plugin_id);
    $days = get_plugin_downloads($plugin_id);
    $windows = $days === [] ? [ 'last_7_days' => null, 'last_30_days' => null ] : summarize_plugin_downloads($days);
    $iso = static fn(int $timestamp): ?string => $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    $error = static fn(?array $error): ?array => $error === null ? null : [
        'code' => $error['code'],
        'message' => $error['message'],
        'at' => $iso((int) $error['at']),
    ];
    return [
        'installations' => [
            'state' => $directory['state'],
            'count' => $directory['active_installs'],
        ],
        'downloads' => [
            'total' => $directory['downloaded'],
            'last_7_days' => $windows['last_7_days'],
            'last_30_days' => $windows['last_30_days'],
        ],
        'wporg_stats' => [
            'downloaded' => $directory['downloaded'],
            'rating' => $directory['rating'],
            'num_ratings' => $directory['num_ratings'],
            'closed' => $directory['closed'],
        ],
        'wporg_check' => [
            'checked_at' => $iso((int) $directory['checked_at']),
            'error' => $error($directory['check_error']),
        ],
    ];
}
