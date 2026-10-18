<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * When the plugin page shows a commit — wordpress.org's import queue, played over the plugin's
 * recent commits. In the plugin directory a watcher reads the SVN log every 30 s and sums up
 * the commits of a plugin since its last run; queue() folds that into an import still waiting;
 * queue_run_time() schedules a touched or deleted tag and a Stable tag flip to an existing tag
 * 5 s after the commit, any other trunk change — assets/ included — 15 minutes after it, so
 * every further trunk commit restarts the wait and a tag pulls it forward. Pointer moves —
 * flips, deploys that make their release current — are known for Peak Publisher's own commits
 * only (the operations log); a foreign readme commit counts as a trunk change. A running import
 * is invisible from outside: a commit landing during one is forecast like a fresh commit — the
 * pessimistic side, which never promises what then fails to appear. A forecast also carries
 * what the import must leave in the directory stamp, and ends early once the stamp shows it
 * (wporg_import_confirmed()). Pure functions; refresh_wporg_import_forecast() does the one
 * request and the cache.
 */
const PBLSH_WPORG_IMPORT_META = '_pblsh_wporg_import';   // { expected_at, reason, assets, stable_touched_at, computed_at } — a cache, regenerable
const PBLSH_WPORG_IMPORT_WATCHER_DELAY = 30;              // the watcher's interval: the latest a commit is seen
const PBLSH_WPORG_IMPORT_SHOWN_AFTER = 180;               // the forecast stays this long past the expected time — the import's own duration
const PBLSH_WPORG_IMPORT_LOG_LIMIT = 20;                  // commits the forecast replays


/**
 * The plugin's commits from a log REPORT with changed paths, read the way the SVN watcher reads
 * them (summarize_plugin_changes()): trunk and every tag touched, tags deleted (a D on the tag
 * directory itself), the readme at the root of trunk or a tag, assets/ — which counts as trunk
 * for the schedule, while trunk_touched says whether trunk itself was changed (the forecast's
 * wording). Paths of other plugins in the same commit are not ours. Ascending by revision.
 *
 * @param array<int, array{revision:int, time:int, paths:array<string, string>}> $log_entries
 * @param int[] $flip_revisions revisions of Peak Publisher's own pointer moves (pointer_move_revisions())
 * @return array<int, array{revision:int, time:int, tags_touched:string[], tags_deleted:string[], readme_touched:bool, assets_touched:bool, trunk_touched:bool, flip:bool}>
 */
function wporg_commits_from_log(array $log_entries, string $slug, array $flip_revisions): array {
    $flips = array_map('intval', $flip_revisions);
    $commits = [];
    foreach ($log_entries as $log) {
        $commit = [ 'revision' => (int) $log['revision'], 'time' => (int) $log['time'], 'tags_touched' => [], 'tags_deleted' => [], 'readme_touched' => false, 'assets_touched' => false, 'trunk_touched' => false, 'flip' => in_array((int) $log['revision'], $flips, true) ];
        foreach ($log['paths'] as $path => $action) {
            $parts = explode('/', trim((string) $path, '/'));
            if (($parts[0] ?? '') !== $slug || !isset($parts[1])) {
                continue;
            }
            if ($parts[1] === 'trunk') {
                $commit['tags_touched'][] = 'trunk';
                $commit['trunk_touched'] = true;
            } elseif ($parts[1] === 'tags' && isset($parts[2])) {
                if ($action === 'D' && !isset($parts[3])) {
                    $commit['tags_deleted'][] = $parts[2];
                } else {
                    $commit['tags_touched'][] = $parts[2];
                }
            } elseif ($parts[1] === 'assets') {
                $commit['assets_touched'] = true;
            }
            if (preg_match('!/(trunk|tags/[^/]+)/readme\.(txt|md)$!i', (string) $path)) {
                $commit['readme_touched'] = true;
            }
        }
        if ($commit['assets_touched'] && !in_array('trunk', $commit['tags_touched'], true)) {
            $commit['tags_touched'][] = 'trunk';
        }
        $commit['tags_touched'] = array_values(array_unique($commit['tags_touched']));
        $commit['tags_deleted'] = array_values(array_unique($commit['tags_deleted']));
        $commits[] = $commit;
    }
    usort($commits, static fn(array $a, array $b): int => $a['revision'] <=> $b['revision']);
    return $commits;
}


/** queue_run_time(): seconds from the commit until the import runs. */
function wporg_import_delay(array $args): int {
    $trunk_only = array_values(array_unique($args['tags_touched'])) === [ 'trunk' ] && $args['tags_deleted'] === [];
    if (!$trunk_only || ($args['readme_touched'] && $args['flip'])) {
        return 5;
    }
    return 15 * MINUTE_IN_SECONDS;
}


/**
 * The expected time the plugin page shows the last commit, or null when nothing is pending or
 * the forecast is over (shown until PBLSH_WPORG_IMPORT_SHOWN_AFTER past the expected time).
 * Every commit before the waiting import ran is folded into it — the watcher sums up the
 * commits of its run before queue(), and queue() merges into an import still waiting — and the
 * merged picture decides the time, counted from the latest commit. The watcher's interval is
 * added as the upper bound of when the commit is seen.
 *
 * Beside the time, what the import must leave in the directory stamp, for the stamp to end the
 * forecast early (wporg_import_confirmed()): `assets` — assets were committed, the page will
 * show them —, `stable_touched_at` — the time of the latest commit that moved the pointer or
 * touched the folder of the current release ($stable_folder: a tag, 'trunk', null unknown),
 * after which wordpress.org bumps `last_updated` (plugin-directory: class-import.php, "Bump
 * last updated if") —, null when no commit did: a trunk commit beside the stable folder or an
 * edit of another tag leaves the stamp alone.
 *
 * @return array{expected_at:int, reason:'tag'|'flip'|'assets'|'trunk', assets:bool, stable_touched_at:?int}|null
 */
function predict_wporg_import(array $commits, int $now, ?string $stable_folder): ?array {
    $event = null;
    foreach ($commits as $commit) {
        $time = (int) $commit['time'];
        $stable_touched = $commit['flip'] || ($stable_folder === 'trunk' ? $commit['trunk_touched'] : ($stable_folder !== null && $stable_folder !== '' && in_array($stable_folder, $commit['tags_touched'], true)));
        $args = [ ...array_intersect_key($commit, array_flip([ 'tags_touched', 'tags_deleted', 'readme_touched', 'assets_touched', 'trunk_touched', 'flip' ])), 'stable_touched_at' => $stable_touched ? $time : null ];
        if ($event !== null && $event['nextrun'] > $time) {
            $touched = array_filter([ $event['args']['stable_touched_at'], $args['stable_touched_at'] ], 'is_int');
            $args = [
                'tags_touched' => array_values(array_unique([ ...$event['args']['tags_touched'], ...$args['tags_touched'] ])),
                'tags_deleted' => array_values(array_unique([ ...$event['args']['tags_deleted'], ...$args['tags_deleted'] ])),
                'readme_touched' => $event['args']['readme_touched'] || $args['readme_touched'],
                'assets_touched' => $event['args']['assets_touched'] || $args['assets_touched'],
                'trunk_touched' => $event['args']['trunk_touched'] || $args['trunk_touched'],
                'flip' => $event['args']['flip'] || $args['flip'],
                'stable_touched_at' => $touched === [] ? null : max($touched),
            ];
        }
        $event = [ 'nextrun' => $time + wporg_import_delay($args), 'args' => $args ];
    }
    if ($event === null) {
        return null;
    }
    $expected_at = $event['nextrun'] + PBLSH_WPORG_IMPORT_WATCHER_DELAY;
    if ($expected_at + PBLSH_WPORG_IMPORT_SHOWN_AFTER <= $now) {
        return null;
    }
    // A merged picture with a tag is a tag; a flip is only a flip when nothing but trunk moved;
    // the 15-minute wait is about assets only while trunk itself stayed untouched.
    $args = $event['args'];
    $reason = wporg_import_delay([ ...$args, 'flip' => false ]) === 5 ? 'tag'
        : ($args['readme_touched'] && $args['flip'] ? 'flip'
        : ($args['assets_touched'] && !$args['trunk_touched'] ? 'assets' : 'trunk'));
    return [ 'expected_at' => $expected_at, 'reason' => $reason, 'assets' => (bool) $args['assets_touched'], 'stable_touched_at' => $args['stable_touched_at'] ];
}


/**
 * The facts the directory stamp is read against to tell whether the import a forecast waits
 * for has landed (wporg_import_confirmed()): whether the plugin page is behind the assets
 * mirror (assets_import_waiting()), and the version of the current release — what the
 * directory's `version` shows once a flip, or a tag that became current, is imported.
 *
 * @param array $current resolve_current_release()
 * @return array{import_waiting:bool, current_version:?string}
 */
function wporg_import_facts(\WP_Post $marker, array $current): array {
    $id = (int) $marker->ID;
    return [
        'import_waiting' => assets_import_waiting(get_wporg_directory($id)['stamp'], wporg_mirror_slots(read_asset_manifest($id), get_wporg_assets_state($id))),
        'current_version' => $current['release'] instanceof \WP_Post ? (string) $current['release']->post_title : null,
    ];
}


/**
 * Whether the stamp shows what the forecast expects of it — the forecast ends then, whatever
 * its time said. Every expectation must hold. Assets: nothing of the mirror waits any more. The
 * stable folder touched, or the pointer moved: the directory's version is the current release's
 * and its `last_updated` is not before that commit, to the minute the directory gives —
 * wordpress.org bumps it exactly then, for a changed version or a commit to the current stable's
 * folder; an edit of the version that is current already changes no version, so the version
 * alone would confirm it at the first look. A forecast expecting nothing of the stamp — a trunk
 * commit beside the stable folder, an edit of another tag — leaves no trace in it and ends by
 * time alone. Nothing confirms before the first check.
 */
function wporg_import_confirmed(array $stored, ?array $stamp, array $facts): bool {
    if ($stamp === null) {
        return false;
    }
    $expects_assets = !empty($stored['assets']);
    $stable_touched_at = $stored['stable_touched_at'] ?? null;
    if (!$expects_assets && $stable_touched_at === null) {
        return false;
    }
    if ($expects_assets && $facts['import_waiting']) {
        return false;
    }
    if ($stable_touched_at !== null) {
        $updated = wporg_stamp_last_updated_time($stamp);
        if ($facts['current_version'] === null || $stamp['version'] !== $facts['current_version']
            || $updated === null || $updated < (int) $stable_touched_at - (int) $stable_touched_at % MINUTE_IN_SECONDS) {
            return false;
        }
    }
    return true;
}


/**
 * The stored forecast while it lasts — not past its time, not confirmed by the stamp
 * (wporg_import_confirmed()) — with the facts of wporg_import_facts(); re-validation when
 * reading back from persistence.
 */
function stored_wporg_import(int $marker_id, int $now, array $facts): ?array {
    $stored = get_post_meta($marker_id, PBLSH_WPORG_IMPORT_META, true);
    if (!is_array($stored) || !isset($stored['expected_at'], $stored['reason']) || !array_key_exists('assets', $stored) || !array_key_exists('stable_touched_at', $stored)
        || (int) $stored['expected_at'] + PBLSH_WPORG_IMPORT_SHOWN_AFTER <= $now) {
        return null;
    }
    return wporg_import_confirmed($stored, get_wporg_directory($marker_id)['stamp'], $facts) ? null : $stored;
}


/**
 * The stored forecast for the REST payload, while it lasts: expected_at as ISO 8601 UTC, the
 * reason, and `watch` — whether the stamp can end it (it expects something of the stamp), so
 * the client watches the stamp from the forecast's time on.
 */
function serialize_wporg_import(int $marker_id, int $now, array $facts): ?array {
    $stored = stored_wporg_import($marker_id, $now, $facts);
    return $stored === null ? null : [
        'expected_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $stored['expected_at']),
        'reason' => (string) $stored['reason'],
        'watch' => !empty($stored['assets']) || $stored['stable_touched_at'] !== null,
    ];
}


/**
 * Whether the plugin's load should compute the forecast anew: one is still shown and it is
 * older than the watcher's interval. The reload right after an own commit finds one seconds
 * old, and wordpress.org cannot have acted on anything newer in that time; a forecast the
 * stamp has confirmed is over, not recomputed.
 */
function wporg_import_forecast_due(int $marker_id, int $now, array $facts): bool {
    $stored = stored_wporg_import($marker_id, $now, $facts);
    return $stored !== null && (int) ($stored['computed_at'] ?? 0) + PBLSH_WPORG_IMPORT_WATCHER_DELAY <= $now;
}


/**
 * Recomputes the forecast from the plugin's recent commits (one log request) and stores it:
 * after every own commit (commit_files(), with the commit's client) and when the plugin loads
 * while a forecast is shown. Warn-only — without the log there is no forecast, never an error.
 */
function refresh_wporg_import_forecast(\WP_Post $marker, ?WporgPluginSvnClient $client = null): void {
    require_once PBLSH_PLUGIN_DIR . 'classes/WporgOperations.php';
    try {
        $entries = WporgOperations::fetch_log_entries($marker->post_name, PBLSH_WPORG_IMPORT_LOG_LIMIT, $client);
    } catch (\Throwable $e) {
        wporg_log_cache_error($marker, 'import forecast', $e);
        delete_post_meta((int) $marker->ID, PBLSH_WPORG_IMPORT_META);
        return;
    }
    // The folder of the current release as the pointer reads now; the commit that moves it is
    // known by the log, so a pointer the marker cache has not caught up with yet does no harm.
    $commits = wporg_commits_from_log($entries, $marker->post_name, pointer_move_revisions((int) $marker->ID));
    $forecast = predict_wporg_import($commits, time(), resolve_current_release($marker)['pointer']);
    if ($forecast === null) {
        delete_post_meta((int) $marker->ID, PBLSH_WPORG_IMPORT_META);
        return;
    }
    update_post_meta((int) $marker->ID, PBLSH_WPORG_IMPORT_META, [ ...$forecast, 'computed_at' => time() ]);
}
