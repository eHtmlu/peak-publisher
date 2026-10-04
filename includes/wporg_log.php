<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The operations log of a wordpress.org plugin: the marker's record of every SVN write Peak
 * Publisher committed (deploy, stable tag, tag delete — later assets and readme edits): who
 * did it, with which wordpress.org account, in which revision, with the operation's own
 * details. The WordPress actor is not in the public SVN log and only recorded here. Newest
 * first and unbounded — a record is never truncated silently; flips and asset commits are
 * rare. Written by WporgOperations::commit_files() after a successful commit; read by the
 * import forecast (wporg_operation_revisions(): the Stable tag flips); the reader for the
 * editor comes later. The upload state stays the record of a release, this is the record of
 * the plugin.
 */
const PBLSH_WPORG_OPERATIONS_META = '_pblsh_wporg_operations';

function record_wporg_operation(int $marker_id, string $operation, string $username, int $revision, array $details): void {
    // Re-validation when reading back from persistence: a foreign shape starts a new log.
    $log = get_post_meta($marker_id, PBLSH_WPORG_OPERATIONS_META, true);
    $log = is_array($log) ? array_values($log) : [];
    $user = wp_get_current_user();
    array_unshift($log, [
        'operation' => $operation,
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
        'user' => [ 'id' => (int) $user->ID, 'login' => (string) $user->user_login ],
        'username' => $username,
        'revision' => $revision,
        'details' => $details,
    ]);
    update_post_meta($marker_id, PBLSH_WPORG_OPERATIONS_META, $log);
}


/**
 * The revisions of the marker's own commits of one operation, newest first — the import
 * forecast recognizes a Stable tag flip only by them.
 *
 * @return int[]
 */
function wporg_operation_revisions(int $marker_id, string $operation): array {
    $log = get_post_meta($marker_id, PBLSH_WPORG_OPERATIONS_META, true);
    $revisions = [];
    foreach (is_array($log) ? $log : [] as $entry) {
        // Re-validation when reading back from persistence: only well-formed entries count.
        if (is_array($entry) && ($entry['operation'] ?? null) === $operation && (int) ($entry['revision'] ?? 0) > 0) {
            $revisions[] = (int) $entry['revision'];
        }
    }
    return $revisions;
}
