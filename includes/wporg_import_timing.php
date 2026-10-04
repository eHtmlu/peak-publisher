<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * When the plugin page shows a commit — wordpress.org's import queue, played over the plugin's
 * recent commits. In the plugin directory a watcher reads the SVN log every 30 s and sums up
 * the commits of a plugin since its last run; queue() folds that into an import still waiting;
 * queue_run_time() schedules a touched or deleted tag and a Stable tag flip to an existing tag
 * 5 s after the commit, any other trunk change — assets/ included — 15 minutes after it, so
 * every further trunk commit restarts the wait and a tag pulls it forward. Flips are known for
 * Peak Publisher's own commits only (the operations log); a foreign readme commit counts as a
 * trunk change. A running import is invisible from outside: a commit landing during one is
 * forecast like a fresh commit — the pessimistic side, which never promises what then fails to
 * appear. Pure functions; refresh_wporg_import_forecast() does the one request and the cache.
 */
const PBLSH_WPORG_IMPORT_META = '_pblsh_wporg_import';   // { expected_at, reason, computed_at } — a cache, regenerable
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
 * @param int[] $flip_revisions revisions of Peak Publisher's own Stable tag flips
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
 * @return array{expected_at:int, reason:'tag'|'flip'|'assets'|'trunk'}|null
 */
function predict_wporg_import(array $commits, int $now): ?array {
    $event = null;
    foreach ($commits as $commit) {
        $time = (int) $commit['time'];
        $args = array_intersect_key($commit, array_flip([ 'tags_touched', 'tags_deleted', 'readme_touched', 'assets_touched', 'trunk_touched', 'flip' ]));
        if ($event !== null && $event['nextrun'] > $time) {
            $args = [
                'tags_touched' => array_values(array_unique([ ...$event['args']['tags_touched'], ...$args['tags_touched'] ])),
                'tags_deleted' => array_values(array_unique([ ...$event['args']['tags_deleted'], ...$args['tags_deleted'] ])),
                'readme_touched' => $event['args']['readme_touched'] || $args['readme_touched'],
                'assets_touched' => $event['args']['assets_touched'] || $args['assets_touched'],
                'trunk_touched' => $event['args']['trunk_touched'] || $args['trunk_touched'],
                'flip' => $event['args']['flip'] || $args['flip'],
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
    return [ 'expected_at' => $expected_at, 'reason' => $reason ];
}


/** The stored forecast while it lasts — re-validation when reading back from persistence. */
function stored_wporg_import(int $marker_id, int $now): ?array {
    $stored = get_post_meta($marker_id, PBLSH_WPORG_IMPORT_META, true);
    if (!is_array($stored) || !isset($stored['expected_at'], $stored['reason']) || (int) $stored['expected_at'] + PBLSH_WPORG_IMPORT_SHOWN_AFTER <= $now) {
        return null;
    }
    return $stored;
}


/** The stored forecast for the REST payload, while it lasts; expected_at as ISO 8601 UTC. */
function serialize_wporg_import(int $marker_id, int $now): ?array {
    $stored = stored_wporg_import($marker_id, $now);
    return $stored === null ? null : [ 'expected_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $stored['expected_at']), 'reason' => (string) $stored['reason'] ];
}


/**
 * Whether the plugin's load should compute the forecast anew: one is still shown and it is
 * older than the watcher's interval. The reload right after an own commit finds one seconds
 * old, and wordpress.org cannot have acted on anything newer in that time.
 */
function wporg_import_forecast_due(int $marker_id, int $now): bool {
    $stored = stored_wporg_import($marker_id, $now);
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
    $flips = wporg_operation_revisions((int) $marker->ID, 'stable_tag');
    $forecast = predict_wporg_import(wporg_commits_from_log($entries, $marker->post_name, $flips), time());
    if ($forecast === null) {
        delete_post_meta((int) $marker->ID, PBLSH_WPORG_IMPORT_META);
        return;
    }
    update_post_meta((int) $marker->ID, PBLSH_WPORG_IMPORT_META, [ ...$forecast, 'computed_at' => time() ]);
}
