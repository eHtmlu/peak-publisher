<?php

namespace Pblsh;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WporgSvnException.php';


/**
 * The operations layer for everything Peak Publisher does against wordpress.org:
 * SVN deploys, stable tag flips and tag deletes (under a per-slug lock), tag and revision reads,
 * the account/access probe and the directory hint. Every write is one commit
 * through commit_files(), which also keeps the operations log. It owns the fixed
 * error catalog of these operations (exception()); the HTTP transport lives in
 * WporgPluginSvnClient.
 */
class WporgOperations {
    /**
     * The one write path to wordpress.org SVN: every commit Peak Publisher makes — deploy,
     * stable tag, tag delete, assets, later readme edits — is one Plan committed here
     * under the plugin's lock. $build_plan builds the Plan *under the lock* from fresh remote
     * reads (never a cache), so its facts hold when the commit lands, and it throws when the
     * remote state contradicts what the caller's dialog showed.
     *
     * Plan = [
     *   'deletes' => string[]                                  paths relative to the plugin root
     *   'mkdirs'  => string[]                                  directories to create, one MKCOL each
     *   'copies'  => array{path:string, from_path:string, from_revision:int}[]
     *                                                          server-side copies with history, of a
     *                                                          file or a whole folder (a new tag)
     *   'puts'    => array{path:string, local_path:string}[]   a PUT replaces existing content
     *   'mime_types' => array{path:string, type:string}[]      svn:mime-type set on the file, after its put or copy
     *   'message' => string                                    the commit message
     *   'details' => array                                     the operations log entry's details
     *   'cleanup' => string[]                                  temp files deleted in finally
     * ]
     * Every entry counts as a change: a directory is listed only when it may be missing,
     * never one known to exist (creating an existing one is tolerated, but it is no change) —
     * and with every parent that may be missing too: mkdir() creates the one directory.
     * The primitive orders the operations (wporg_commit_operations()): deletes deep-first,
     * mkdirs shallow-first, then copies, puts and mime types by path — what lies below a copied
     * path after the copy. An empty plan (none of these) commits nothing:
     * committed false, revision null, no log entry.
     *
     * @param \WP_Post      $marker       The wporg marker (post_name = slug; the log lives on it).
     * @param string        $operation    deploy | stable_tag | delete_tag | assets | readme — the
     *                                    lock's label and the log entry's operation.
     * @param callable      $build_plan   fn(WporgPluginSvnClient $client, int $base_revision, callable $report): Plan
     *                                    — $report('read', $done) reports the plan's long reads
     *                                    (a deploy's folder listings), which keeps the lock
     *                                    alive while they run.
     * @param callable|null $after_commit fn(int $revision): void — runs once the commit landed,
     *                                    still under the lock: local state written from the plan
     *                                    must not interleave with another writer. It must not
     *                                    throw; the commit cannot be taken back.
     * @param callable|null $progress     fn(string $phase, int $done, int $total): void — how far
     *                                    the write got: read (what $build_plan reports), send
     *                                    ($done of $total operations of the plan), save (the
     *                                    commit's MERGE and what follows it under the lock).
     * @return array{revision:int|null, committed:bool, base_revision:int, details:array}
     *         base_revision = the plugin's revision the plan was built against; a caller whose
     *         cache was fresh there can advance it once plugin_changed_only_in() confirms that
     *         nothing else changed since (revisions count across the whole repository, so they
     *         never simply follow each other).
     * @throws WporgSvnException Credentials, lock, not_found, concurrent change, transport
     *         errors — and whatever $build_plan throws.
     */
    public static function commit_files(\WP_Post $marker, string $username, string $operation, callable $build_plan, ?callable $after_commit = null, ?callable $progress = null): array {
        raise_wporg_time_limit();

        $wporg_slug = self::normalize_slug_or_throw($marker->post_name);
        $username = normalize_wporg_username($username, 'username');
        if (is_wp_error($username)) {
            throw WporgSvnException::from_wp_error($username);
        }
        $credentials = get_wporg_credentials($username);
        if (is_wp_error($credentials)) {
            throw WporgSvnException::from_wp_error($credentials);
        }
        if ($credentials === null || empty($credentials['password'])) {
            throw self::exception('account_not_configured');
        }
        // The stored spelling — the one wordpress.org's commit authorization knows; the
        // caller's may be a marker's older spelling of the same account.
        $username = $credentials['username'];

        $lock = self::acquire_wporg_deploy_lock($wporg_slug, $username, $operation, (int) $marker->ID);
        // Every report is the lock's sign of life (LOCK_HEARTBEAT) — a deploy of a large plugin
        // holds it for minutes — and, for the caller, the write's progress.
        $beat_at = time();
        $report = static function(string $phase, int $done = 0, int $total = 0) use ($lock, $progress, &$beat_at): void {
            if (time() - $beat_at >= self::LOCK_HEARTBEAT) {
                self::renew_wporg_deploy_lock($lock);
                $beat_at = time();
            }
            if ($progress !== null) {
                $progress($phase, $done, $total);
            }
        };
        $client = null;
        $plan = [ 'cleanup' => [] ];
        try {
            $base_revision = self::get_plugin_revision($wporg_slug);
            if ($base_revision === null) {
                throw self::exception('not_found');
            }
            $client = self::svn_client($username, $credentials['password']);

            $plan = self::normalize_plan($build_plan($client, $base_revision, $report));
            $operations = wporg_commit_operations($plan);
            if ($operations === []) {
                return [ 'revision' => null, 'committed' => false, 'base_revision' => (int) $base_revision, 'details' => $plan['details'] ];
            }

            $total = count($operations);
            $done = 0;
            $report('send', $done, $total);
            // The revision guard sits in the commit's opening read: the plan is void when the
            // plugin changed since it was built.
            $client->begin_commit($wporg_slug, (int) $base_revision);
            foreach ($operations as $step) {
                match ($step['op']) {
                    'delete' => $client->del($step['path']),
                    'mkdir' => $client->mkdir($step['path']),
                    'copy' => $client->copy($step['from_path'], $step['from_revision'], $step['path']),
                    'put' => $client->add_file($step['path'], $step['local_path']),
                    'mime_type' => $client->set_mime_type($step['path'], $step['type']),
                };
                $report('send', ++$done, $total);
            }
            $report('save');
            $commit = $client->commit($plan['message']);
            // A sign of life after the MERGE, its longest silence: what follows under the lock
            // (log, forecast, the caller's write-through) starts a silence of its own.
            $report('save');

            // A successful commit is the strongest possible credential verdict.
            record_wporg_credentials_verdict($username, true);
            $revision = (int) ($commit['revision'] ?? 0);
            record_operation((int) $marker->ID, $operation, $username, $revision, $plan['details']);
            // Every own commit changes when the plugin page shows it: the forecast follows the log entry.
            refresh_wporg_import_forecast($marker, $client);
            if ($after_commit !== null) {
                $after_commit($revision);
            }
            return [ 'revision' => $revision, 'committed' => true, 'base_revision' => (int) $base_revision, 'details' => $plan['details'] ];
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'invalid_credentials') {
                record_wporg_credentials_verdict($username, false);
            }
            if ($client instanceof WporgPluginSvnClient) {
                $client->abort();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($client instanceof WporgPluginSvnClient) {
                $client->abort();
            }
            throw $e;
        } finally {
            self::release_wporg_deploy_lock($lock);
            foreach ($plan['cleanup'] as $path) {
                if (file_exists($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    /**
     * Publishes a prepared plugin directory as tags/{version} and, with $touch_trunk, as trunk
     * — one commit through commit_files() — and executes the upload's current-release
     * decision: the live trunk readme is read under the lock and compared with what the dialog
     * showed (settle_pointer_decision()); trunk gets the workspace readme with the decided
     * Stable tag when it gets code (R2), only its Stable tag line when the pointer changes on a
     * tag-only deploy (R3), nothing otherwise. The tag gets the workspace as it is (R1 made its
     * readme name its own version); a new tag as a copy of trunk or its predecessor tag plus what
     * differs from it (new_tag_plan()), in the same commit.
     *
     * @param array{make_current:bool, relation:string, expected:?string, readme_file_name:string} $pointer
     *        The decision, the relation and the pointer the dialog showed (null = unknown), and
     *        the workspace readme's file name (finalize guarantees one).
     * @param callable|null $after_commit fn(array $deploy): void — the caller's local
     *        write-through, run once the commit landed and still under the lock (see
     *        commit_files()): no refresh of the marker can read SVN between the commit and
     *        what the caller writes from it. $deploy is what this method returns, without
     *        commit_files()'s own base_revision and details.
     * @param callable|null $progress See commit_files(); the phase read counts the folders
     *        listed in trunk, the tag and the tag's copy source.
     * @return array{revision:int|null, committed:bool, details:array, touched_trunk:bool,
     *         stable_tag_before:?string, stable_tag_written:?string, pointer_changed:bool, trunk_readme:?array}
     *         stable_tag_before = the live pointer (null = unreadable), stable_tag_written = the
     *         value written to trunk (null = trunk readme untouched), trunk_readme = the cache
     *         entry of the written trunk readme (null = none written).
     */
    public static function deploy_directory(\WP_Post $marker, string $root, string $version, string $username, bool $touch_trunk, array $pointer, ?callable $after_commit = null, ?callable $progress = null): array {
        $wporg_slug = self::normalize_slug_or_throw($marker->post_name);
        $version = self::safe_path_segment($version);
        $root = trailingslashit($root);
        if (!is_dir($root) || !is_readable($root)) {
            throw self::exception('deploy_directory_missing');
        }
        $readme_file_name = (string) ($pointer['readme_file_name'] ?? '');
        if ($readme_file_name === '' || !is_file($root . $readme_file_name)) {
            // finalize blocks uploads without a readme (wporg_readme_required) — an invariant here.
            throw new \RuntimeException('wporg_readme_required');
        }

        $outcome = [ 'stable_tag_before' => null, 'stable_tag_written' => null, 'pointer_changed' => false, 'trunk_readme' => null ];
        $result = self::commit_files($marker, $username, 'deploy', function(WporgPluginSvnClient $client, int $base_revision, callable $report) use ($wporg_slug, $root, $version, $touch_trunk, $pointer, $readme_file_name, &$outcome): array {
            $local_tree = self::collect_local_tree($root);
            if (!self::tree_has_php_file($local_tree)) {
                throw self::exception('wporg_tag_requires_php');
            }

            // One listing per folder: the reads of a large plugin take a while, the count shows them going.
            $folders = 0;
            $on_folder = static function() use ($report, &$folders): void {
                $report('read', ++$folders);
            };
            $report('read', 0);

            // The live pointer, under the lock — from the trunk tree the trunk plan needs anyway
            // (null: there is no trunk).
            $remote_trunk = $touch_trunk ? self::read_remote_tree($client, $wporg_slug, 'trunk', $on_folder) : null;
            try {
                $live = self::read_trunk_readme($client, $wporg_slug, $touch_trunk ? ($remote_trunk ?? []) : null);
            } catch (WporgSvnException $e) {
                $live = null;
            }
            $decision = self::settle_pointer_decision($client, $wporg_slug, $pointer, $live, $version, $touch_trunk);
            $outcome['stable_tag_before'] = $live['stable_tag'] ?? null;
            $outcome['pointer_changed'] = $decision['pointer_changed'];

            $tag_base = 'tags/' . $version;
            $remote_tag = self::read_remote_tree($client, $wporg_slug, $tag_base, $on_folder);
            $plans = [ $remote_tag !== null
                ? wporg_reconcile_plan($tag_base, $local_tree, $remote_tag)
                : self::new_tag_plan($client, $wporg_slug, $tag_base, $version, $local_tree, $touch_trunk, $remote_trunk, $base_revision, $on_folder) ];

            // The readme variant is a temp file: until the plan is returned only this closure
            // knows it, so a failure after its creation cleans it up here.
            $cleanup = [];
            try {
                if ($touch_trunk) {
                    // trunk gets the workspace, its readme as the variant with the decided pointer.
                    $variant = self::readme_variant((string) file_get_contents($root . $readme_file_name), $decision['pointer_value']);
                    $cleanup[] = $variant['path'];
                    $trunk_tree = $local_tree;
                    $trunk_tree[$readme_file_name] = [ 'type' => 'file', 'path' => $variant['path'], 'size' => $variant['size'], 'hash' => $variant['hash'] ];
                    $plans[] = wporg_reconcile_plan('trunk', $trunk_tree, $remote_trunk ?? []);
                    $outcome['stable_tag_written'] = $decision['pointer_value'];
                    $outcome['trunk_readme'] = self::trunk_readme_cache_entry([ ...$variant, 'file_name' => $readme_file_name ]);
                } elseif ($decision['pointer_changed']) {
                    // Tag only, pointer changes: just the Stable tag line — in trunk's own readme
                    // (readable, settle_pointer_decision() made sure), or the workspace readme when
                    // trunk has none yet.
                    $trunk_has_readme = is_array($live) && $live['file_name'] !== null;
                    $variant = self::readme_variant($trunk_has_readme ? $live['content'] : (string) file_get_contents($root . $readme_file_name), $decision['pointer_value']);
                    $cleanup[] = $variant['path'];
                    $file_name = $trunk_has_readme ? $live['file_name'] : $readme_file_name;
                    // trunk may not exist at all when it has no readme; an existing trunk is no change.
                    $plans[] = [ 'deletes' => [], 'mkdirs' => $trunk_has_readme ? [] : [ 'trunk' ], 'copies' => [], 'puts' => [ [ 'path' => 'trunk/' . $file_name, 'local_path' => $variant['path'] ] ] ];
                    $outcome['stable_tag_written'] = $decision['pointer_value'];
                    $outcome['trunk_readme'] = self::trunk_readme_cache_entry([ ...$variant, 'file_name' => $file_name ]);
                }
            } catch (\Throwable $e) {
                foreach ($cleanup as $path) {
                    if (file_exists($path)) {
                        wp_delete_file($path);
                    }
                }
                throw $e;
            }

            // The commit message tells the log reader at once whether the release went live.
            $stable_tag_note = $decision['pointer_changed']
                ? sprintf('stable tag set to %s', $decision['pointer_value'])
                : ($live !== null && $live['stable_tag'] !== '' ? sprintf('stable tag stays %s', $live['stable_tag']) : 'stable tag unchanged');

            return [
                ...self::merge_reconcile_plans($plans),
                'message' => sprintf('Publish version %s of %s (%s) via Peak Publisher', $version, $wporg_slug, $stable_tag_note),
                'details' => [
                    'version' => $version,
                    'deploy_mode' => $touch_trunk ? 'trunk_and_tag' : 'tag_only',
                    'make_current' => $decision['make_current'],
                    'stable_tag_before' => $outcome['stable_tag_before'],
                    'stable_tag_written' => $outcome['stable_tag_written'],
                ],
                'cleanup' => $cleanup,
            ];
        }, $after_commit === null ? null : static function(int $revision) use ($after_commit, $touch_trunk, &$outcome): void {
            $after_commit([ 'revision' => $revision, 'committed' => true, 'touched_trunk' => $touch_trunk, ...$outcome ]);
        }, $progress);

        return [ ...$result, 'touched_trunk' => $touch_trunk, ...$outcome ];
    }

    /**
     * The plan of a tag that does not exist yet: a copy of the first candidate source
     * (wporg_tag_copy_candidates()) that exists and is not empty — as committed in
     * $base_revision, the commit's staged changes cannot be copied — plus what the upload has
     * otherwise; without such a source, the upload in full. $remote_trunk is trunk's tree when
     * the deploy read it (trunk goes along; null: there is none); a tag-only deploy reads the
     * candidate it reaches.
     */
    private static function new_tag_plan(WporgPluginSvnClient $client, string $wporg_slug, string $tag_base, string $version, array $local_tree, bool $touch_trunk, ?array $remote_trunk, int $base_revision, callable $on_folder): array {
        try {
            $tags = array_map(
                static fn(array $entry): string => (string) $entry['name'],
                array_filter(self::direct_children($client->list_directory($wporg_slug . '/tags/', 1), $wporg_slug . '/tags'), static fn(array $entry): bool => ($entry['type'] ?? '') === 'dir')
            );
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() !== 'not_found') {
                throw $e;
            }
            $tags = [];
        }

        foreach (wporg_tag_copy_candidates($version, $tags, $touch_trunk) as $source) {
            $source_tree = $source === 'trunk' && $touch_trunk ? $remote_trunk : self::read_remote_tree($client, $wporg_slug, $source, $on_folder);
            if (!empty($source_tree)) {
                return wporg_reconcile_plan($tag_base, $local_tree, $source_tree, [ 'from_path' => $source, 'from_revision' => $base_revision ]);
            }
        }
        return wporg_reconcile_plan($tag_base, $local_tree, []);
    }

    /**
     * Makes tags/{version} the current release on wordpress.org: one commit that rewrites the
     * Stable tag line of trunk's readme (R2 — only existing tags, never trunk). Built under
     * the lock from the live readme and compared with the value the editor showed, so a flip
     * by someone else is refused, never overwritten; null = the editor could not read the
     * pointer (no expectation to compare with).
     *
     * @return array{revision:int, committed:bool, details:array, from:string, trunk_readme:array}
     *         from = the Stable tag before, trunk_readme = the cache entry of the written readme.
     * @throws WporgSvnException current_release_target_missing (the tag does not exist),
     *         wporg_trunk_readme_unreadable, wporg_trunk_readme_missing (trunk has no readme —
     *         a release with one must be published first), current_release_changed,
     *         invalid_current_release_target (it is the current release already).
     */
    public static function set_stable_tag(\WP_Post $marker, string $version, ?string $expected_stable_tag, string $username): array {
        $wporg_slug = self::normalize_slug_or_throw($marker->post_name);
        $version = self::safe_path_segment($version);

        $outcome = [];
        $result = self::commit_files($marker, $username, 'stable_tag', function(WporgPluginSvnClient $client) use ($wporg_slug, $version, $expected_stable_tag, &$outcome): array {
            if (!self::tag_exists($client, $wporg_slug, $version)) {
                throw self::exception('current_release_target_missing');
            }
            try {
                $live = self::read_trunk_readme($client, $wporg_slug);
            } catch (WporgSvnException $e) {
                throw self::exception('wporg_trunk_readme_unreadable');
            }
            if ($live['file_name'] === null) {
                throw self::exception('wporg_trunk_readme_missing');
            }
            if ($expected_stable_tag !== null && $live['stable_tag'] !== $expected_stable_tag) {
                throw self::exception('current_release_changed');
            }
            if ($live['stable_tag'] === $version) {
                throw self::exception('invalid_current_release_target');
            }

            // The variant is the closure's last step: nothing can throw between its creation
            // and the plan that lists it for cleanup.
            $variant = self::readme_variant($live['content'], $version);
            $outcome = [ 'from' => $live['stable_tag'], 'trunk_readme' => self::trunk_readme_cache_entry([ ...$variant, 'file_name' => $live['file_name'] ]) ];
            return [
                'puts' => [ [ 'path' => 'trunk/' . $live['file_name'], 'local_path' => $variant['path'] ] ],
                'message' => sprintf('Set stable tag to version %s of %s via Peak Publisher', $version, $wporg_slug),
                'details' => [ 'from' => $live['stable_tag'], 'to' => $version ],
                'cleanup' => [ $variant['path'] ],
            ];
        });

        return [ ...$result, ...$outcome ];
    }

    /**
     * Sets Tested up to of tags/{version} in one commit: the readme's header line and, when the
     * main file carries the line, that line too; trunk gets the same lines when its main file
     * names this very version — trunk carries the files of the highest deployed version, and
     * only then is the edit also trunk's. Built under the lock from fresh reads; the tag's
     * snapshot is read back into its release post while the lock is held.
     *
     * @param string $expected The effective value the dialog showed.
     * @return array{revision:int|null, committed:bool, base_revision:int, details:array, from:string, trunk:array{state:string, version:?string}, trunk_readme:?array}
     *         trunk: state written | other_version (version = trunk's) | absent; trunk_readme:
     *         the marker cache's entry of trunk's readme when the commit changed it, else null.
     * @throws WporgSvnException wporg_tag_not_found (404), tested_up_to_nowhere (409),
     *         tested_up_to_changed (409), invalid_tested_up_to_target (400), the primitive's.
     */
    public static function set_tested_up_to(\WP_Post $marker, string $version, string $value, string $expected, string $username): array {
        $wporg_slug = self::normalize_slug_or_throw($marker->post_name);
        $version = self::safe_path_segment($version);

        $outcome = [];
        $result = self::commit_files($marker, $username, 'tested_up_to', function(WporgPluginSvnClient $client) use ($wporg_slug, $version, $value, $expected, &$outcome): array {
            $tag_base = $wporg_slug . '/tags/' . $version;
            try {
                $entries = $client->list_directory($tag_base . '/', 1);
            } catch (WporgSvnException $e) {
                if ($e->get_error_code() === 'not_found') {
                    throw self::exception('wporg_tag_not_found');
                }
                throw $e;
            }
            $children = self::direct_children($entries, $tag_base);
            $readme_entry = self::find_readme_entry($children);
            $readme_name = $readme_entry !== null ? (string) $readme_entry['name'] : '';
            $readme = $readme_entry !== null ? self::normalize_readme_content($client->read_file($tag_base . '/' . $readme_name)) : null;
            // The main file is found by reading the tag's PHP files; its content is kept for the plan.
            $read_contents = [];
            $plugin_file = self::plugin_file_data_from_children($wporg_slug, $children, static function(array $entry) use ($client, &$read_contents): ?string {
                $path = (string) ($entry['path'] ?? '');
                return $path !== '' ? ($read_contents[basename((string) ($entry['name'] ?? ''))] = $client->read_file($path)) : null;
            });
            $main_name = (string) ($plugin_file['plugin_info']['main_file'] ?? '');
            $main = $read_contents[$main_name] ?? null;
            $effective = effective_tested_up_to($plugin_file['plugin_data'], $readme !== null ? parse_readme_txt($readme) : null);

            // The tag's files as plan_tested_up_to_edit() decides — the rule both channels share.
            $changes = plan_tested_up_to_edit($readme, $main, $effective, $value, $expected);
            if (is_wp_error($changes)) {
                throw WporgSvnException::from_wp_error($changes);
            }
            $names = [ 'readme' => $readme_name, 'main' => $main_name ];
            $files = [];
            foreach ($changes as $file => $content) {
                $files['tags/' . $version . '/' . $names[$file]] = $content;
            }

            // trunk follows when it carries this version: its main file names it — its existing
            // lines only, nothing is created there (tested_up_to_line_changes()). The outcome says
            // what was written; a main file without a version is no mirror of anything.
            $trunk = [ 'state' => 'absent', 'version' => null ];
            $trunk_readme_entry = null;
            $trunk_main = $main_name !== '' ? self::read_file_or_null($client, $wporg_slug . '/trunk/' . $main_name) : null;
            if ($trunk_main !== null) {
                $trunk_data = self::parse_plugin_data_from_contents($main_name, $trunk_main) ?? [];
                $trunk_version = (string) ($trunk_data['Version'] ?? '');
                if ($trunk_version !== '' && normalize_version_number($trunk_version) === normalize_version_number($version)) {
                    $trunk_readme = self::read_trunk_readme($client, $wporg_slug);
                    $trunk_changes = tested_up_to_line_changes($trunk_readme['file_name'] !== null ? $trunk_readme['content'] : null, $trunk_main, $value, false);
                    $trunk_names = [ 'readme' => (string) $trunk_readme['file_name'], 'main' => $main_name ];
                    foreach ($trunk_changes as $file => $content) {
                        $files['trunk/' . $trunk_names[$file]] = $content;
                    }
                    if ($trunk_changes !== []) {
                        $trunk['state'] = 'written';
                    }
                    // The marker cache's entry of trunk's readme as this commit leaves it — only
                    // when the readme itself changed; a changed main file leaves it as it was.
                    if (isset($trunk_changes['readme'])) {
                        $trunk_readme_entry = self::trunk_readme_cache_entry([ ...$trunk_readme, 'tested' => $value ]);
                    }
                } elseif ($trunk_version !== '') {
                    $trunk = [ 'state' => 'other_version', 'version' => $trunk_version ];
                }
            }

            $puts = [];
            $cleanup = [];
            foreach ($files as $path => $content) {
                $variant = self::file_variant($content);
                $puts[] = [ 'path' => $path, 'local_path' => $variant['path'] ];
                $cleanup[] = $variant['path'];
            }
            $outcome = [ 'from' => $effective['value'], 'trunk' => $trunk, 'trunk_readme' => $trunk_readme_entry ];
            return [
                'puts' => $puts,
                'message' => sprintf('Set Tested up to %s for version %s of %s via Peak Publisher', $value, $version, $wporg_slug),
                'details' => [ 'version' => $version, 'from' => $effective['value'], 'to' => $value, 'source_before' => $effective['source'], 'files' => array_keys($files), 'trunk' => $trunk ],
                'cleanup' => $cleanup,
            ];
        }, static function(int $revision) use ($marker, $version): void {
            // Still under the lock: the tag's snapshot read back from SVN, as after a deploy. A
            // failure returns a WP_Error the next refresh heals; after_commit must not throw.
            sync_wporg_deployed_release_post($marker, $version);
        });

        return [ ...$result, ...$outcome ];
    }

    /** A file read that answers null for not_found — a tag or trunk without the file. */
    private static function read_file_or_null(WporgPluginSvnClient $client, string $path): ?string {
        try {
            return $client->read_file($path);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    public static function list_tags(string $wporg_slug): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $client = self::svn_client();
        $base_path = $wporg_slug . '/tags';

        try {
            $entries = $client->list_directory($base_path . '/', 1);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return [];
            }
            throw $e;
        }

        $tags = [];
        foreach (self::direct_children($entries, $base_path) as $entry) {
            if (($entry['type'] ?? '') !== 'dir') {
                continue;
            }

            $version = (string) ($entry['name'] ?? '');
            if ($version === '') {
                continue;
            }

            // last_modified and revision are the tag directory's last change anywhere beneath it.
            $tags[] = [
                'version' => $version,
                'last_modified' => (string) ($entry['last_modified'] ?? ''),
                'revision' => (int) ($entry['revision'] ?? 0),
            ];
        }

        usort($tags, function(array $a, array $b): int {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        return $tags;
    }


    public static function get_plugin_revision(string $wporg_slug): ?int {
        return self::get_plugin_revisions($wporg_slug)['revision'] ?? null;
    }

    /**
     * The plugin root's revision and those of its direct children (trunk, tags, assets,
     * branches) in one listing — a directory's revision bubbles up from every change beneath
     * it. Null when the plugin does not exist on SVN.
     *
     * @return array{revision:int, children:array<string,int>}|null
     */
    public static function get_plugin_revisions(string $wporg_slug): ?array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        try {
            $entries = self::svn_client()->list_directory($wporg_slug . '/', 1);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return null;
            }
            throw $e;
        }

        // The listed directory itself comes first when its path does not match literally.
        $root = $entries[0] ?? [];
        foreach ($entries as $entry) {
            if (self::normalize_path((string) ($entry['path'] ?? '')) === $wporg_slug) {
                $root = $entry;
            }
        }
        $revision = (int) ($root['revision'] ?? 0);
        if ($revision <= 0) {
            return null;
        }

        $children = [];
        foreach (self::direct_children($entries, $wporg_slug) as $entry) {
            $children[(string) ($entry['name'] ?? '')] = (int) ($entry['revision'] ?? 0);
        }
        return [ 'revision' => $revision, 'children' => $children ];
    }

    public static function fetch_tag_data(string $wporg_slug, string $version): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $version = self::safe_path_segment($version);
        $client = self::svn_client();
        $base_path = $wporg_slug . '/tags/' . $version;
        $entries = $client->list_directory($base_path . '/', 1);
        $children = self::direct_children($entries, $base_path);

        $plugin_file = self::fetch_plugin_file_data($client, $children, $wporg_slug);

        return [
            'plugin_data' => $plugin_file['plugin_data'],
            'plugin_info' => $plugin_file['plugin_info'],
            'plugin_readme_txt' => self::fetch_readme_data($client, $children),
        ];
    }

    /**
     * Whether nothing but the named direct children of the plugin root changed since
     * $base_revision (get_plugin_revisions()); a vanished plugin counts as changed. A caller
     * that committed into one child only can keep a cache fresh at its new revision when this
     * holds.
     */
    public static function plugin_changed_only_in(string $wporg_slug, array $children, int $base_revision): bool {
        $revisions = self::get_plugin_revisions($wporg_slug);
        if ($revisions === null) {
            return false;
        }
        foreach ($revisions['children'] as $name => $revision) {
            if (!in_array((string) $name, $children, true) && $revision > $base_revision) {
                return false;
            }
        }
        return true;
    }

    /**
     * The assets/ directory as wordpress.org's import reads it: the direct children bytewise by
     * name like svn ls, each with size and last-changed revision, and the directory's own
     * revision (the mirror's anchor). Null when the plugin has no assets/ directory.
     *
     * @return array{revision:int, entries:array<int, array{name:string, type:string, size:int|null, revision:int}>}|null
     */
    public static function list_assets(string $wporg_slug, ?WporgPluginSvnClient $client = null): ?array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $base_path = $wporg_slug . '/assets';
        try {
            $entries = ($client ?? self::svn_client())->list_directory($base_path . '/', 1);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return null;
            }
            throw $e;
        }

        $revision = 0;
        foreach ($entries as $entry) {
            if (self::normalize_path((string) ($entry['path'] ?? '')) === $base_path) {
                $revision = (int) ($entry['revision'] ?? 0);
            }
        }
        $children = array_map(static fn(array $entry): array => [
            'name' => (string) ($entry['name'] ?? ''),
            'type' => (string) ($entry['type'] ?? 'file'),
            'size' => $entry['size'] ?? null,
            'revision' => (int) ($entry['revision'] ?? 0),
        ], self::direct_children($entries, $base_path));
        usort($children, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [ 'revision' => $revision, 'entries' => $children ];
    }

    /**
     * Downloads assets/{filename} into $local_path, streamed; a failure leaves no file behind.
     * $filename comes from a list_assets() entry that classify_asset_listing() accepted. With
     * $revision the file as it was then (a copy source the mirror no longer holds).
     */
    public static function download_asset(string $wporg_slug, string $filename, string $local_path, int $size, ?WporgPluginSvnClient $client = null, ?int $revision = null): void {
        try {
            ($client ?? self::svn_client())->download_file(self::normalize_slug_or_throw($wporg_slug) . '/assets/' . $filename, $local_path, $size, $revision);
        } catch (\Throwable $e) {
            if (file_exists($local_path)) {
                wp_delete_file($local_path);
            }
            throw $e;
        }
    }

    /**
     * The whole history of the plugin's tags/ with changed paths, newest first — one request,
     * the input that dates every tag (wporg_tag_publication_times()). Empty when tags/ does not
     * exist.
     */
    public static function fetch_tags_log(string $wporg_slug): array {
        return self::svn_client()->get_log_entries(self::normalize_slug_or_throw($wporg_slug) . '/tags', null);
    }

    /**
     * The plugin's last $limit commits with their changed paths — the import forecast's input
     * (includes/wporg_import_timing.php). Newest first; empty when the plugin does not exist.
     */
    public static function fetch_log_entries(string $wporg_slug, int $limit, ?WporgPluginSvnClient $client = null): array {
        return ($client ?? self::svn_client())->get_log_entries(self::normalize_slug_or_throw($wporg_slug), $limit);
    }

    /**
     * Runs $fn under the plugin's write lock — the lock every commit takes — for a local write
     * that must not interleave with a commit or another local write (a change of the assets
     * working copy, the refresh of a marker from SVN). A holder that is over in seconds is
     * waited for; throws the catalog's deploy_in_progress when a commit holds the lock,
     * wporg_plugin_busy when a local holder outlasts the wait (acquire_wporg_deploy_lock()
     * decides by the holder).
     */
    public static function under_plugin_lock(string $wporg_slug, string $operation, int $plugin_id, callable $fn): mixed {
        $lock = self::acquire_wporg_deploy_lock(self::normalize_slug_or_throw($wporg_slug), '', $operation, $plugin_id);
        try {
            return $fn();
        } finally {
            self::release_wporg_deploy_lock($lock);
        }
    }

    /**
     * Runs $fn under the plugin's write lock — the lock every commit takes — or not at all: a
     * reader skips instead of waiting, whoever holds the lock. Answers false when the lock is
     * held, else what $fn returns (so $fn answers something other than false). A write that
     * must not be skipped uses under_plugin_lock().
     */
    public static function try_under_plugin_lock(string $wporg_slug, string $operation, int $plugin_id, callable $fn): mixed {
        try {
            $lock = self::acquire_wporg_deploy_lock(self::normalize_slug_or_throw($wporg_slug), '', $operation, $plugin_id, false);
        } catch (WporgSvnException $e) {
            if (in_array($e->get_error_code(), [ 'deploy_in_progress', 'wporg_plugin_busy' ], true)) {
                return false;
            }
            throw $e;
        }

        try {
            return $fn();
        } finally {
            self::release_wporg_deploy_lock($lock);
        }
    }

    /**
     * Reads trunk's readme for the marker cache: the pointer (Stable tag) and the screenshot
     * captions — the `trunk_readme` structure of wporg_cache.php.
     *
     * @return array{stable_tag:string, file_name:string|null, screenshots:array<int,string>, tested:string}
     *         file_name null = trunk has no readme (stable_tag '' then); stable_tag '' = no
     *         header; otherwise the parser's sanitized value, 'trunk' included; tested = the
     *         readme's Tested up to as the parser reads it ('' = none).
     * @throws WporgSvnException When trunk cannot be listed or the readme not read.
     */
    public static function fetch_trunk_readme(string $wporg_slug): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        return self::trunk_readme_cache_entry(self::read_trunk_readme(self::svn_client(), $wporg_slug));
    }

    /**
     * Reads trunk's readme with its content — for the cache entry and for rewriting its Stable
     * tag line. $trunk_tree is a read_remote_tree() of trunk when the caller has one (the
     * trunk_and_tag deploy), else trunk is listed.
     *
     * @return array{stable_tag:string, file_name:string|null, screenshots:array, tested:string, content:string}
     *         content is the file as parsed: UTF-8, BOM stripped.
     * @throws WporgSvnException When trunk cannot be listed or the file not read.
     */
    private static function read_trunk_readme(WporgPluginSvnClient $client, string $wporg_slug, ?array $trunk_tree = null): array {
        $none = [ 'stable_tag' => '', 'file_name' => null, 'screenshots' => [], 'tested' => '', 'content' => '' ];
        if ($trunk_tree === null) {
            $base_path = $wporg_slug . '/trunk';
            try {
                $entries = $client->list_directory($base_path . '/', 1);
            } catch (WporgSvnException $e) {
                if ($e->get_error_code() === 'not_found') {
                    return $none; // no trunk at all — nothing carries a pointer
                }
                throw $e;
            }
            $readme = self::find_readme_entry(self::direct_children($entries, $base_path));
            $file_name = $readme !== null ? (string) ($readme['name'] ?? 'readme.txt') : null;
        } else {
            // Keys are relative paths; PHP turns a numeric file name into an int key, hence the casts.
            $top_level_files = array_map('strval', array_keys(array_filter($trunk_tree, static fn(array $entry, $rel): bool => ($entry['type'] ?? '') === 'file' && !str_contains((string) $rel, '/'), ARRAY_FILTER_USE_BOTH)));
            $file_name = find_wporg_readme_file_name($top_level_files);
        }
        if ($file_name === null) {
            return $none;
        }

        $content = self::normalize_readme_content($client->read_file($wporg_slug . '/trunk/' . $file_name));
        $parsed = parse_readme_txt($content);
        return [
            'stable_tag' => (string) ($parsed['stable_tag'] ?? ''),
            'file_name' => $file_name,
            'screenshots' => is_array($parsed['screenshots'] ?? null) ? $parsed['screenshots'] : [],
            'tested' => sanitize_tested_up_to((string) ($parsed['tested'] ?? '')),
            'content' => $content,
        ];
    }

    /**
     * The marker cache's `trunk_readme` entry of a read or written readme (without the
     * content): the pointer, the file, the captions, and Tested up to — what the editor shows
     * while wordpress.org distributes trunk.
     */
    private static function trunk_readme_cache_entry(array $readme): array {
        return [ 'stable_tag' => $readme['stable_tag'], 'file_name' => $readme['file_name'], 'screenshots' => $readme['screenshots'], 'tested' => $readme['tested'] ];
    }

    public static function fetch_tags_data_batch(string $wporg_slug, array $versions): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $normalized_versions = [];
        foreach ($versions as $version) {
            $normalized_versions[] = self::safe_path_segment((string) $version);
        }
        $versions = array_values(array_unique($normalized_versions));
        if (empty($versions)) {
            return [];
        }

        $client = self::svn_client();
        $base_paths_by_version = [];
        foreach ($versions as $version) {
            $base_paths_by_version[$version] = $wporg_slug . '/tags/' . $version;
        }

        $directory_paths = array_map(static fn($base_path) => $base_path . '/', array_values($base_paths_by_version));
        $directories_by_path = $client->list_directories_multi($directory_paths, 1, 5);

        $children_by_version = [];
        $readme_by_version = [];
        $file_paths = [];
        foreach ($base_paths_by_version as $version => $base_path) {
            $children = self::direct_children($directories_by_path[$base_path . '/'] ?? [], $base_path);
            $children_by_version[$version] = $children;

            foreach ($children as $entry) {
                $path = (string) ($entry['path'] ?? '');
                $name = (string) ($entry['name'] ?? '');
                if (($entry['type'] ?? '') === 'file' && substr($name, -4) === '.php' && $path !== '') {
                    $file_paths[] = $path;
                }
            }

            $readme = self::find_readme_entry($children);
            if ($readme !== null && (string) ($readme['path'] ?? '') !== '') {
                $readme_by_version[$version] = $readme;
                $file_paths[] = (string) $readme['path'];
            }
        }

        $file_contents = $client->read_files_multi($file_paths, 10);
        $out = [];
        foreach ($versions as $version) {
            $plugin_file = self::plugin_file_data_from_prefetched_files($wporg_slug, $children_by_version[$version] ?? [], $file_contents);
            $out[$version] = [
                'plugin_data' => $plugin_file['plugin_data'],
                'plugin_info' => $plugin_file['plugin_info'],
                'plugin_readme_txt' => self::readme_data_from_prefetched_file($readme_by_version[$version] ?? null, $file_contents),
            ];
        }

        return $out;
    }

    /**
     * Deletes tags/{version} in one commit. R4 — the current release cannot be deleted
     * (wordpress.org would fall back to distributing trunk): the live trunk readme is the
     * last gate before the irreversible delete, read under the lock; no cache check before it.
     *
     * @throws WporgSvnException wporg_tag_not_found (404) when the tag is gone already — the
     *         caller answers with the code, the client refreshes, the tag sync removes the
     *         post; current_release_protected (409); wporg_trunk_readme_unreadable.
     */
    public static function delete_tag(\WP_Post $marker, string $version, string $username): array {
        $wporg_slug = self::normalize_slug_or_throw($marker->post_name);
        $version = self::safe_path_segment($version);

        return self::commit_files($marker, $username, 'delete_tag', static function(WporgPluginSvnClient $client) use ($wporg_slug, $version): array {
            if (!self::tag_exists($client, $wporg_slug, $version)) {
                throw self::exception('wporg_tag_not_found');
            }
            try {
                $live = self::read_trunk_readme($client, $wporg_slug);
            } catch (WporgSvnException $e) {
                throw self::exception('wporg_trunk_readme_unreadable');
            }
            if ($live['stable_tag'] === $version) {
                throw self::exception('current_release_protected');
            }
            return [
                'deletes' => [ 'tags/' . $version ],
                'message' => sprintf('Delete version %s of %s via Peak Publisher', $version, $wporg_slug),
                'details' => [ 'version' => $version ],
            ];
        });
    }

    /**
     * Writes a readme with its Stable tag set to $value into a temp file for a PUT and parses
     * what was written. The caller lists the path in the Plan's cleanup.
     *
     * @return array{path:string, size:int, hash:string, stable_tag:string, screenshots:array, tested:string}
     */
    private static function readme_variant(string $content, string $value): array {
        $variant = set_readme_stable_tag($content, $value);
        $parsed = parse_readme_txt($variant);
        return [
            ...self::file_variant($variant),
            'stable_tag' => (string) ($parsed['stable_tag'] ?? ''),
            'screenshots' => is_array($parsed['screenshots'] ?? null) ? $parsed['screenshots'] : [],
            'tested' => sanitize_tested_up_to((string) ($parsed['tested'] ?? '')),
        ];
    }

    /**
     * Writes content into a temp file for a PUT; the caller lists the path in the Plan's cleanup.
     *
     * @return array{path:string, size:int, hash:string}
     */
    private static function file_variant(string $content): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $path = wp_tempnam('pblsh-put');
        if (!is_string($path) || $path === '' || @file_put_contents($path, $content) === false) {
            throw self::exception('wporg_commit_file_failed');
        }
        return [ 'path' => $path, 'size' => strlen($content), 'hash' => md5($content) ];
    }

    /**
     * Settles the upload's pointer decision against the live trunk readme, under the lock:
     * the relation the dialog showed must still hold when the commit lands. Throws
     * current_release_changed when the pointer moved since the dialog — or, after a dialog
     * that could not read it (relation unknown), when the live relation would be one the user
     * never saw: equal or repairs_pointer (V names the pointer) or lower (V below a current
     * release), said in that situation's own words since nothing moved; higher, no_current
     * and first carry the user's decision. Throws
     * wporg_trunk_readme_unreadable when the plan needs the live readme and it could not be
     * read: preserving an unknown pointer while trunk gets code, or replacing the line in a
     * file that cannot be read. Writing the workspace variant with the new pointer needs
     * nothing old.
     *
     * @param array{make_current:bool, relation:string, expected:?string} $pointer
     * @param array|null $live read_trunk_readme(), null when it could not be read
     * @return array{make_current:bool, pointer_value:string, pointer_changed:bool}
     */
    private static function settle_pointer_decision(WporgPluginSvnClient $client, string $wporg_slug, array $pointer, ?array $live, string $version, bool $touch_trunk): array {
        $make_current = !empty($pointer['make_current']);

        if ($live === null) {
            if ($touch_trunk ? !$make_current : $make_current) {
                throw self::exception('wporg_trunk_readme_unreadable');
            }
            return [ 'make_current' => $make_current, 'pointer_value' => $version, 'pointer_changed' => $make_current ];
        }

        $live_pointer = $live['stable_tag'];
        $expected = $pointer['expected'] ?? null;
        if ($expected !== null && $live_pointer !== $expected) {
            throw self::exception('current_release_changed');
        }
        if ((string) ($pointer['relation'] ?? '') === 'unknown' && $live_pointer !== '' && $live_pointer !== 'trunk') {
            $normalized_version = normalize_version_number($version);
            $normalized_pointer = normalize_version_number($live_pointer);
            $names_this_version = $normalized_pointer === $normalized_version;
            $below_current = !$names_this_version
                && version_compare($normalized_version, $normalized_pointer, '<')
                && self::tag_exists($client, $wporg_slug, $live_pointer);
            if ($names_this_version || $below_current) {
                // The code is the dialog's cue to reload the facts; the message is this
                // situation's own — the catalog's "changed in the meantime" would be wrong,
                // the pointer never moved, the dialog never knew it.
                throw new WporgSvnException('current_release_changed', $names_this_version
                    ? sprintf(__('The current release on wordpress.org could now be read: it is %s already. Reload the upload facts and check the decision again.', 'peak-publisher'), $live_pointer)
                    : sprintf(__('The current release on wordpress.org could now be read: it is %1$s, newer than %2$s. Reload the upload facts and check the decision again.', 'peak-publisher'), $live_pointer, $version), 409);
            }
        }

        $pointer_value = $make_current ? $version : $live_pointer;
        return [ 'make_current' => $make_current, 'pointer_value' => $pointer_value, 'pointer_changed' => $pointer_value !== $live_pointer ];
    }

    /** Whether tags/{tag} exists — a fresh listing, never the cache. */
    private static function tag_exists(WporgPluginSvnClient $client, string $wporg_slug, string $tag): bool {
        try {
            $client->list_directory($wporg_slug . '/tags/' . self::safe_path_segment($tag) . '/', 0);
            return true;
        } catch (WporgSvnException $e) {
            if (in_array($e->get_error_code(), [ 'not_found', 'invalid_svn_path_segment' ], true)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Resolves the account a wordpress.org operation runs with and probes it:
     * repository existence and credential acceptance. Write access is not
     * probeable — wordpress.org decides it at MERGE time (2026-08); the upfront
     * ownership signal is directory_hint().
     *
     * Exactly one account is probed, and there is no fallback to another one when
     * it is rejected: a rotated password must surface for the account the user
     * meant, never be bypassed by a silent identity switch.
     *
     * @return array{status:string, username:string|null, message:string|null}
     *         status: ok|no_credentials|not_found|credentials_rejected|error;
     *         username: the resolved account, null only for no_credentials.
     */
    public static function resolve_wporg_account_access(string $wporg_slug, ?string $preferred_username = null): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $username = select_wporg_account_username($preferred_username);
        if ($username === null) {
            return [
                'status' => 'no_credentials',
                'username' => null,
                'message' => __('No wordpress.org accounts are configured.', 'peak-publisher'),
            ];
        }

        $credentials = get_wporg_credentials($username);
        if (is_wp_error($credentials) || $credentials === null || empty($credentials['password'])) {
            return [
                'status' => 'error',
                'username' => $username,
                'message' => is_wp_error($credentials) ? $credentials->get_error_message() : __('Could not verify wordpress.org SVN access.', 'peak-publisher'),
            ];
        }

        try {
            $access = self::svn_client($credentials['username'], $credentials['password'])->check_repo_access($wporg_slug);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'username' => $username,
                'message' => $e instanceof WporgSvnException ? $e->getMessage() : __('Could not verify wordpress.org SVN access.', 'peak-publisher'),
            ];
        }

        $status = (string) ($access['status'] ?? 'error');
        // The MKACTIVITY inside the access check is a real credential verdict.
        if ($status === 'ok') {
            record_wporg_credentials_verdict($username, true);
        } elseif ($status === 'credentials_rejected') {
            record_wporg_credentials_verdict($username, false);
        }

        return [
            'status' => $status,
            'username' => $username,
            'message' => isset($access['message']) && is_string($access['message']) ? $access['message'] : null,
        ];
    }

    /**
     * Directory/ownership hint for a plugin slug — the best available upfront signal
     * since write access is not probeable before the MERGE. Heuristic by design, so
     * callers warn and never block. Two orthogonal facts, so the UI can present them
     * as separate points:
     * - state — published | fresh (approved, page not live yet; 'created' = ISO 8601
     *   UTC time of the repository creation commit) | closed (or temporarily disabled;
     *   'reason' when public) | unknown
     * - relation — the account's connection to the plugin:
     *   owner (directory author_profile, or the creation commit "Adding {title} by
     *   {login}." — the format wordpress.org's own SVN watcher parses) |
     *   committer (recent SVN commit history: the only public evidence of actual
     *   commit access; checked lazily, only to rescue a pending not_listed verdict —
     *   absence proves nothing, so it never downgrades) |
     *   listed (public contributor list) | not_listed | unknown
     * 'owner' carries the plugin owner's name when known, for contrast display.
     * 'name'/'icon'/'description' carry the directory listing's identity (published;
     * closed responses still provide the name) so the UI can show WHICH plugin the
     * directory serves under this slug before anything is imported.
     *
     * Remote failures degrade to 'unknown' facts — for a valid slug the hint
     * never throws, so callers consume it without a guard.
     *
     * @return array{state:string, relation:string, owner:string|null, created:string|null, reason:string|null, name:string|null, icon:string|null, description:string|null, release_count:int|null, closed_date:string|null}
     */
    public static function directory_hint(string $wporg_slug, string $username): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $hint = self::compute_directory_hint($wporg_slug, $username);

        // Extension/override point (also used to simulate states in development —
        // some, like a freshly approved own plugin, cannot occur on demand).
        $filtered = apply_filters('pblsh_wporg_directory_hint', $hint, $wporg_slug, $username);
        return is_array($filtered) ? array_merge($hint, array_intersect_key($filtered, $hint)) : $hint;
    }

    private static function compute_directory_hint(string $wporg_slug, string $username): array {
        $hint = [
            'state' => 'unknown',
            'relation' => 'unknown',
            'owner' => null,
            'created' => null,
            'reason' => null,
            'name' => null,
            'icon' => null,
            'description' => null,
            'release_count' => null,
            'active_installs' => null,
            'closed_date' => null,
        ];
        $normalized = normalize_wporg_username($username);
        if (is_wp_error($normalized)) {
            return $hint;
        }

        // Deliberately uncached: the hint is only requested a few times per upload flow,
        // and after the user fixes something on wordpress.org (contributor added, plugin
        // published or reopened) the next check must reflect it immediately.
        try {
            $info = wporg_api_plugin_information(
                [ $wporg_slug ],
                wporg_api_fields([ 'active_installs', 'contributors', 'icons', 'short_description', 'versions' ])
            )[$wporg_slug];
        } catch (WporgSvnException $e) {
            // Remote failures degrade to 'unknown' facts — for a valid slug the hint never throws.
            return $hint;
        }

        if ($info['state'] === 'ok') {
            $data = $info['data'];
            $hint['state'] = 'published';
            $hint['name'] = wporg_api_clean_text($data['name'] ?? '');
            $hint['icon'] = wporg_api_pick_icon_url($data['icons'] ?? null);
            $hint['description'] = wporg_api_clean_text($data['short_description'] ?? '');
            // versions is the tagged versions plus one trunk entry (present only when tags
            // exist). The tag count is what the import will create as releases — one
            // release per SVN tag.
            $hint['release_count'] = is_array($data['versions'] ?? null)
                ? count(array_diff(array_map('strval', array_keys($data['versions'])), [ 'trunk' ]))
                : null;
            // wordpress.org's rounded bucket — the import table shows it for manually
            // added slugs, which the discovery did not deliver.
            $hint['active_installs'] = is_numeric($data['active_installs'] ?? null) ? (int) $data['active_installs'] : null;

            // author_profile is the profile URL of the current plugin owner (post author);
            // its last path segment is the owner's user_nicename.
            $directory_owner = '';
            $author_profile = wporg_string_from_value($data['author_profile'] ?? '');
            if ($author_profile !== '') {
                $profile_path = trim((string) parse_url($author_profile, PHP_URL_PATH), '/');
                $directory_owner = $profile_path !== '' ? basename($profile_path) : '';
            }
            $hint['owner'] = $directory_owner !== '' ? $directory_owner : null;
            // contributors is keyed by user_nicename (a requested field, always set for
            // published plugins — the directory falls back to the post author).
            $contributors = array_map('strval', array_keys(is_array($data['contributors'] ?? null) ? $data['contributors'] : []));

            // Owner beats the contributor listing — it also covers owners a readme
            // forgot to list as contributors.
            if ($directory_owner !== '' && self::username_in_list($normalized, [ $directory_owner ])) {
                $hint['relation'] = 'owner';
            } elseif (self::username_in_list($normalized, $contributors)) {
                $hint['relation'] = 'listed';
            } elseif (self::has_committed_before($wporg_slug, $normalized)) {
                // Last rescue before the warning: team and agency committers often appear
                // in neither owner nor readme — but their commits are public. One extra
                // SVN roundtrip, paid only in the case that would otherwise warn.
                $hint['relation'] = 'committer';
            } else {
                $hint['relation'] = 'not_listed';
            }
            return $hint;
        }

        if ($info['state'] === 'closed') {
            // The closed API response carries no owner or contributor data — the
            // relation deliberately stays unknown instead of guessing.
            $hint['state'] = 'closed';
            $hint['reason'] = $info['data']['closed']['reason'];
            $hint['name'] = $info['data']['name'];
            $hint['closed_date'] = $info['data']['closed']['date'];
            return $hint;
        }

        // not_found: callers only ask after the SVN probe confirmed the repository exists,
        // so this means approved but not published yet. The page only goes live with the
        // first commit, but the repository creation commit already names the owner and
        // the approval time.
        $hint['state'] = 'fresh';
        $creation = self::creation_commit_facts($wporg_slug);
        if ($creation !== null) {
            $hint['owner'] = $creation['owner'];
            $hint['created'] = $creation['created'];
            $hint['relation'] = self::username_in_list($normalized, [ $creation['owner'] ]) ? 'owner' : 'not_listed';
        }
        return $hint;
    }

    /** How many of the account's plugins the spelling proof reads the history of. */
    private const SPELLING_PROOF_PLUGINS = 3;

    /**
     * The spelling of a username as wordpress.org's commit history records it: the
     * spelling that passed the commit authorization, which checks the login exactly
     * (the password check ignores case, so a probe cannot tell). Read from the recent
     * commits of the account's first plugins; null when the history shows no commit by
     * the account — no plugins listed under it, other committers, API or SVN unavailable.
     */
    public static function proven_username_spelling(string $username): ?string {
        try {
            $plugins = wporg_api_query_plugins_by_author($username);
        } catch (\Throwable $e) {
            return null;
        }
        foreach (array_slice($plugins, 0, self::SPELLING_PROOF_PLUGINS) as $plugin) {
            // SVN reads are anonymous on wordpress.org — no credentials needed.
            $authors = self::svn_client()->get_recent_log_authors((string) $plugin['slug']);
            foreach ($authors ?? [] as $author) {
                if (strcasecmp((string) $author, $username) === 0) {
                    return (string) $author;
                }
            }
        }
        return null;
    }

    /**
     * Reports whether the account appears in the plugin's recent commit history.
     * False also covers "could not check" — the caller treats it as absence, which
     * only means the warning stays (upgrade-only escalation).
     */
    private static function has_committed_before(string $wporg_slug, string $username): bool {
        try {
            // SVN reads are anonymous on wordpress.org — the rescue also works
            // while no stored account is usable.
            $authors = self::svn_client()->get_recent_log_authors($wporg_slug);
        } catch (\Throwable $e) {
            return false;
        }
        return is_array($authors) && self::username_in_list($username, $authors);
    }

    /**
     * Extracts owner login and commit time from the repository creation commit,
     * null when the log is unavailable or does not match the fixed creation-message
     * format.
     *
     * @return array{owner:string, created:string|null}|null
     */
    private static function creation_commit_facts(string $wporg_slug): ?array {
        try {
            // SVN reads are anonymous on wordpress.org — no credentials needed.
            $entry = self::svn_client()->get_initial_log_entry($wporg_slug);
        } catch (\Throwable $e) {
            return null;
        }

        $message = trim((string) ($entry['message'] ?? ''));
        // The same pattern wordpress.org's SVN watcher uses for exactly this message.
        if ($message === '' || !preg_match('/^Adding (.+) by (.+)\.$/i', $message, $m)) {
            return null;
        }
        $owner = trim($m[2]);
        if ($owner === '') {
            return null;
        }

        // SVN reports the commit time with microseconds (2023-03-24T00:40:23.193847Z);
        // the client parses 'created' with new Date(), and the ECMAScript date format
        // only guarantees three fractional digits — normalize at the boundary to
        // second-precision UTC. An unparseable date is an unknown creation time,
        // not a failed hint.
        $created = null;
        $raw_date = trim((string) ($entry['date'] ?? ''));
        if ($raw_date !== '') {
            try {
                $created = (new \DateTimeImmutable($raw_date))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
            }
        }
        return [
            'owner' => $owner,
            'created' => $created,
        ];
    }

    /**
     * Case-tolerant membership test: the info API keys contributors by user_nicename,
     * the creation commit carries the raw login — compare against both forms.
     */
    private static function username_in_list(string $username, array $list): bool {
        $candidates = array_unique(array_filter([
            strtolower($username),
            sanitize_title($username),
        ]));
        foreach ($list as $entry) {
            if (in_array(strtolower((string) $entry), $candidates, true)) {
                return true;
            }
        }
        return false;
    }

    private static function collect_local_tree(string $root): array {
        $root = trailingslashit($root);
        $normalized_root = trailingslashit(wp_normalize_path($root));
        $tree = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $entry) {
            $abs = (string) $entry->getPathname();

            // Reject raw backslashes before wp_normalize_path() can reinterpret them as separators on Unix.
            if (DIRECTORY_SEPARATOR !== '\\' && str_starts_with($abs, $root) && str_contains(substr($abs, strlen($root)), '\\')) {
                throw self::exception('invalid_svn_path');
            }

            // Build the SVN path from normalized local paths and ensure it stays under the deploy root.
            $normalized_abs = wp_normalize_path($abs);
            if (!str_starts_with($normalized_abs, $normalized_root)) {
                throw self::exception('invalid_svn_path');
            }

            $rel = trim(substr($normalized_abs, strlen($normalized_root)), '/');
            if ($rel === '') {
                continue;
            }

            // SVN deploy paths must use forward-slash segments without traversal markers.
            foreach (explode('/', $rel) as $part) {
                if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '..') || str_contains($part, '\\')) {
                    throw self::exception('invalid_svn_path');
                }
            }

            if ($entry->isDir()) {
                $tree[$rel] = [
                    'type' => 'dir',
                    'path' => $abs,
                    'size' => null,
                ];
                continue;
            }

            if ($entry->isFile()) {
                $tree[$rel] = [
                    'type' => 'file',
                    'path' => $abs,
                    'size' => (int) (@filesize($abs) ?: 0),
                    'hash' => md5_file($abs) ?: '',
                ];
            }
        }

        ksort($tree);
        return $tree;
    }

    private static function tree_has_php_file(array $tree): bool {
        foreach ($tree as $path => $entry) {
            if (($entry['type'] ?? '') === 'file' && str_ends_with((string) $path, '.php')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The remote tree below $base as a flat map of relative paths — type, path, size and, for a
     * file, its MD5 — null when $base does not exist (an existing empty folder is []). Read
     * level by level: the base's own listing tells whether it exists, the folders of each level
     * below are listed in parallel (list_directories_multi(), as the import does) — one listing
     * per folder, a large plugin has dozens to hundreds.
     *
     * @param callable|null $on_folder fn(): void — called after each folder listed.
     */
    private static function read_remote_tree(WporgPluginSvnClient $client, string $wporg_slug, string $base, ?callable $on_folder = null): ?array {
        $base = trim($base, '/');
        $root = $wporg_slug . '/' . $base;
        $tree = [];
        try {
            $listings = [ '' => $client->list_directory($root . '/', 1) ];
            while ($listings !== []) {
                $below = [];
                foreach ($listings as $relative_dir => $entries) {
                    if ($on_folder !== null) {
                        $on_folder();
                    }
                    foreach (self::direct_children($entries, trim($root . '/' . $relative_dir, '/')) as $entry) {
                        $name = (string) ($entry['name'] ?? '');
                        $type = (string) ($entry['type'] ?? '');
                        if ($name === '' || ($type !== 'dir' && $type !== 'file')) {
                            continue;
                        }
                        $rel = trim($relative_dir . '/' . $name, '/');
                        $tree[$rel] = [
                            'type' => $type,
                            'path' => $base . '/' . $rel,
                            'size' => $entry['size'] ?? null,
                            'md5' => $entry['md5'] ?? null,
                        ];
                        if ($type === 'dir') {
                            $below[$rel] = $root . '/' . $rel . '/';
                        }
                    }
                }
                $by_path = $below !== [] ? $client->list_directories_multi(array_values($below), 1, 5) : [];
                $listings = array_map(static fn(string $path): array => $by_path[$path] ?? [], $below);
            }
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return null;
            }
            throw $e;
        }
        ksort($tree);
        return $tree;
    }

    /** Concatenates the reconcile plans of several bases into one Plan (commit_files() orders them). */
    private static function merge_reconcile_plans(array $plans): array {
        $merged = [ 'deletes' => [], 'mkdirs' => [], 'copies' => [], 'puts' => [] ];
        foreach ($plans as $plan) {
            foreach (array_keys($merged) as $key) {
                $merged[$key] = [ ...$merged[$key], ...$plan[$key] ];
            }
        }
        return $merged;
    }

    /** Fills a Plan's optional keys; wporg_commit_operations() puts them into commit order. */
    private static function normalize_plan(array $plan): array {
        $deletes = array_values(array_filter((array) ($plan['deletes'] ?? []), 'is_string'));
        $mkdirs = array_values(array_filter((array) ($plan['mkdirs'] ?? []), 'is_string'));
        $copies = array_values(array_filter((array) ($plan['copies'] ?? []), static fn($copy): bool => is_array($copy) && !empty($copy['path']) && !empty($copy['from_path']) && (int) ($copy['from_revision'] ?? 0) > 0));
        $puts = array_values(array_filter((array) ($plan['puts'] ?? []), static fn($put): bool => is_array($put) && !empty($put['path']) && !empty($put['local_path'])));
        $mime_types = array_values(array_filter((array) ($plan['mime_types'] ?? []), static fn($typed): bool => is_array($typed) && !empty($typed['path']) && !empty($typed['type'])));
        return [
            'deletes' => $deletes,
            'mkdirs' => $mkdirs,
            'copies' => $copies,
            'puts' => $puts,
            'mime_types' => $mime_types,
            'message' => trim((string) ($plan['message'] ?? '')),
            'details' => is_array($plan['details'] ?? null) ? $plan['details'] : [],
            'cleanup' => array_values(array_filter((array) ($plan['cleanup'] ?? []), 'is_string')),
        ];
    }

    // Lock holders that are over in seconds — the assets pull, a working-copy change and the
    // refresh of a marker from SVN, no commit. A caller that runs into one waits for it
    // (LOCAL_LOCK_WAIT): a click must not fail because the background check was reading the
    // plugin at that moment. One that outlasts the wait is told wporg_plugin_busy; a commit
    // takes minutes and is told at once, as deploy_in_progress.
    private const LOCAL_LOCK_OPERATIONS = [ 'assets_pull', 'assets_change', 'refresh' ];
    private const LOCAL_LOCK_WAIT = 10; // seconds

    // A lock is left over from a request that died once it showed no sign of life for
    // LOCK_STALE_AFTER, and is taken over. Its holder's sign of life is alive_at: a commit
    // renews it every LOCK_HEARTBEAT while it works (commit_files()), so a deploy that runs
    // for many minutes keeps its lock; the short holders never need to.
    private const LOCK_STALE_AFTER = 5 * 60; // seconds
    private const LOCK_HEARTBEAT = 30; // seconds

    private static function acquire_wporg_deploy_lock(string $wporg_slug, string $username, string $operation = 'deploy', ?int $plugin_id = null, bool $wait = true): array {
        // Acquire a cross-site lock for this wporg slug
        $key = 'pblsh_wporg_deploy_lock_' . $wporg_slug;
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $lock_blog_id = (is_multisite() && function_exists('get_main_site_id')) ? (int) get_main_site_id() : $blog_id;
        $payload = [
            'key' => $key,
            'blog_id' => $blog_id,
            'lock_blog_id' => $lock_blog_id,
            'acquired_at' => time(),
            'alive_at' => time(),
            'username' => $username,
            'operation' => $operation,
            'wporg_slug' => $wporg_slug,
            'plugin_id' => $plugin_id,
        ];

        $acquire = static function() use ($key, $payload): bool {
            return add_option($key, $payload, '', 'no');
        };

        $read_existing = static function() use ($key) {
            return get_option($key, null);
        };

        $delete_existing = static function() use ($key): void {
            delete_option($key);
        };

        $switched = false;
        if (is_multisite() && $lock_blog_id !== $blog_id) {
            switch_to_blog($lock_blog_id);
            $switched = true;
        }

        try {
            $deadline = microtime(true) + ($wait ? self::LOCAL_LOCK_WAIT : 0);
            while (true) {
                if ($acquire()) {
                    return $payload;
                }

                $existing = $read_existing();
                $alive_at = is_array($existing) ? (int) ($existing['alive_at'] ?? 0) : 0;
                // Reclaim stale locks before failing the deploy
                if ($alive_at > 0 && (time() - $alive_at) > self::LOCK_STALE_AFTER) {
                    $delete_existing();
                    if ($acquire()) {
                        return $payload;
                    }
                }

                // The holder decides: a commit takes minutes and ends the attempt, a pull, a
                // working-copy change or a refresh seconds — worth the wait while it lasts.
                $held_by = is_array($existing) ? (string) ($existing['operation'] ?? '') : '';
                $local = in_array($held_by, self::LOCAL_LOCK_OPERATIONS, true);
                if (!$local || microtime(true) >= $deadline) {
                    throw self::exception($local ? 'wporg_plugin_busy' : 'deploy_in_progress');
                }
                usleep(250000);
                // The holder is another request: this one's copy of the option is its own
                // cached read, which no release over there clears.
                wp_cache_delete($key, 'options');
            }
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function release_wporg_deploy_lock(array $lock): void {
        self::on_own_lock($lock, static function(string $key): void {
            delete_option($key);
        });
    }

    /** The holder's sign of life (LOCK_HEARTBEAT): alive_at moves on. */
    private static function renew_wporg_deploy_lock(array $lock): void {
        self::on_own_lock($lock, static function(string $key, array $existing): void {
            update_option($key, [ ...$existing, 'alive_at' => time() ], false);
        });
    }

    /**
     * Runs $fn(string $key, array $existing) while the lock is still this holder's — one that
     * lost it (taken over after it went silent) never touches the new holder's lock.
     */
    private static function on_own_lock(array $lock, callable $fn): void {
        $key = (string) ($lock['key'] ?? '');
        if ($key === '') {
            return;
        }

        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $lock_blog_id = (int) ($lock['lock_blog_id'] ?? $blog_id);
        $switched = false;
        if (is_multisite() && $lock_blog_id !== $blog_id) {
            switch_to_blog($lock_blog_id);
            $switched = true;
        }

        try {
            // Another request may have taken the lock over: the option as stored, not this
            // request's cached copy of it.
            wp_cache_delete($key, 'options');
            $existing = get_option($key, null);
            $matches = is_array($existing)
                && (int) ($existing['acquired_at'] ?? 0) === (int) ($lock['acquired_at'] ?? 0)
                && (string) ($existing['username'] ?? '') === (string) ($lock['username'] ?? '')
                && (string) ($existing['operation'] ?? '') === (string) ($lock['operation'] ?? '')
                && (string) ($existing['wporg_slug'] ?? '') === (string) ($lock['wporg_slug'] ?? '');
            if ($matches) {
                $fn($key, $existing);
            }
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function svn_client(?string $username = null, ?string $password = null): WporgPluginSvnClient {
        require_once __DIR__ . '/WporgPluginSvnClient.php';
        return new WporgPluginSvnClient($username, $password);
    }

    private static function normalize_slug_or_throw(string $wporg_slug): string {
        $slug = normalize_plugin_slug($wporg_slug);
        if (is_wp_error($slug)) {
            throw WporgSvnException::from_wp_error($slug);
        }
        return $slug;
    }

    private static function safe_path_segment(string $segment): string {
        $segment = trim($segment);
        if ($segment === '' || str_contains($segment, '/') || str_contains($segment, '\\') || str_contains($segment, '..')) {
            throw self::exception('invalid_svn_path_segment');
        }
        return $segment;
    }

    /**
     * Catalog of this workflow's fixed errors — message and HTTP status for each code
     * live only here, so repeated throw sites cannot drift apart.
     */
    private static function exception(string $code): WporgSvnException {
        [$message, $status] = match ($code) {
            'deploy_directory_missing' => [__('The prepared publish directory is missing.', 'peak-publisher'), 500],
            'account_not_configured' => [__('Account not configured.', 'peak-publisher'), 400],
            'wporg_tag_requires_php' => [__('wordpress.org requires the plugin to contain at least one PHP file.', 'peak-publisher'), 400],
            'not_found' => [__('The plugin was not found on wordpress.org SVN.', 'peak-publisher'), 404],
            'invalid_svn_path' => [__('The plugin contains a file or folder path that cannot be published to wordpress.org SVN. Remove path segments containing ".." or backslashes and try again.', 'peak-publisher'), 400],
            'invalid_svn_path_segment' => [__('The version cannot be used as a wordpress.org SVN path segment. Remove slashes, backslashes, and ".." from the version.', 'peak-publisher'), 400],
            'deploy_in_progress' => [__('Another change to this plugin is still being written to wordpress.org. Try again in a few minutes.', 'peak-publisher'), 409],
            'wporg_plugin_busy' => [__("This plugin's data is being updated right now. Try again in a moment.", 'peak-publisher'), 409],
            'current_release_changed' => [__('The current release on wordpress.org changed in the meantime. Reload and check the decision again.', 'peak-publisher'), 409],
            'wporg_trunk_readme_unreadable' => [__('trunk/readme.txt could not be read from wordpress.org, so the Stable tag cannot be handled safely. Try again.', 'peak-publisher'), 502],
            'wporg_commit_file_failed' => [__('A file for the commit to wordpress.org could not be prepared on this server.', 'peak-publisher'), 500],
            'current_release_target_missing' => [__('The tag to make current no longer exists on wordpress.org.', 'peak-publisher'), 404],
            'invalid_current_release_target' => [__('This release is the current release on wordpress.org already.', 'peak-publisher'), 400],
            'wporg_trunk_readme_missing' => [__('wordpress.org has no readme.txt in trunk yet — publish a release with a readme.txt first.', 'peak-publisher'), 409],
            'current_release_protected' => [__('This is the current release on wordpress.org — make another release current before deleting it.', 'peak-publisher'), 409],
            'wporg_tag_not_found' => [__('This tag no longer exists on wordpress.org.', 'peak-publisher'), 404],
        };
        return new WporgSvnException($code, $message, $status);
    }

    private static function direct_children(array $entries, string $base_path): array {
        $base_path = self::normalize_path($base_path);
        $children = [];

        foreach ($entries as $entry) {
            $path = self::normalize_path((string) ($entry['path'] ?? ''));
            if ($path === '' || $path === $base_path) {
                continue;
            }
            if (dirname($path) !== $base_path) {
                continue;
            }
            $children[] = $entry;
        }

        return $children;
    }

    private static function normalize_path(string $path): string {
        $path = rawurldecode($path);
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        return trim($path, '/');
    }

    private static function fetch_plugin_file_data(WporgPluginSvnClient $client, array $children, string $wporg_slug): array {
        return self::plugin_file_data_from_children($wporg_slug, $children, static function(array $entry) use ($client): ?string {
            $path = (string) ($entry['path'] ?? '');
            return $path !== '' ? $client->read_file($path) : null;
        });
    }

    private static function plugin_file_data_from_children(string $wporg_slug, array $children, callable $read_file): array {
        foreach ($children as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['type'] ?? '') !== 'file' || substr($name, -4) !== '.php') {
                continue;
            }

            $contents = $read_file($entry);
            if ($contents === null) {
                continue;
            }

            $plugin_data = self::parse_plugin_data_from_contents($name, $contents);
            if ($plugin_data !== null) {
                return self::plugin_file_snapshot($wporg_slug, $entry, $plugin_data);
            }
        }

        return [
            'plugin_data' => null,
            'plugin_info' => null,
        ];
    }

    private static function fetch_readme_data(WporgPluginSvnClient $client, array $children): array {
        $readme_info = default_plugin_readme_txt_data();

        $readme = self::find_readme_entry($children);
        if ($readme === null) {
            return $readme_info;
        }

        try {
            $content = $client->read_file((string) $readme['path']);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return $readme_info;
            }
            throw $e;
        }

        return self::readme_info_from_content($readme, $content);
    }

    private static function find_readme_entry(array $children): ?array {
        $entries_by_name = [];
        foreach ($children as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '') {
                $entries_by_name[$name] = $entry;
            }
        }

        $readme_file = find_wporg_readme_file_name(array_keys($entries_by_name));
        return $readme_file !== null ? ($entries_by_name[$readme_file] ?? null) : null;
    }

    private static function plugin_file_data_from_prefetched_files(string $wporg_slug, array $children, array $file_contents): array {
        return self::plugin_file_data_from_children($wporg_slug, $children, static function(array $entry) use ($file_contents): ?string {
            $path = (string) ($entry['path'] ?? '');
            return $path !== '' && array_key_exists($path, $file_contents) ? (string) $file_contents[$path] : null;
        });
    }

    private static function plugin_file_snapshot(string $wporg_slug, array $entry, array $plugin_data): array {
        $main_file = basename((string) ($entry['name'] ?? ''));

        return [
            'plugin_data' => $plugin_data,
            'plugin_info' => [
                'normalized_version' => normalize_version_number((string) ($plugin_data['Version'] ?? '')),
                'release_slug' => get_release_slug('wporg', $wporg_slug, (string) ($plugin_data['Version'] ?? '')),
                'main_file' => $main_file,
                'bootstrap_file' => false,
                'bootstrap_version' => '',
                'bootstrap_is_latest' => false,
                'plugin_basename' => $main_file !== '' ? $wporg_slug . '/' . $main_file : '',
                'plugin_slug' => $wporg_slug,
                'content_hash' => '',
            ],
        ];
    }

    private static function parse_plugin_data_from_contents(string $name, string $contents): ?array {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp = wp_tempnam($name !== '' ? $name : 'pblsh-wporg-plugin.php');
        if (!is_string($tmp) || $tmp === '') {
            return null;
        }

        try {
            if (@file_put_contents($tmp, $contents) === false) {
                return null;
            }

            $plugin_data = read_plugin_headers($tmp);
            return !empty($plugin_data['Name']) ? $plugin_data : null;
        } finally {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
        }
    }

    private static function readme_data_from_prefetched_file(?array $readme, array $file_contents): array {
        if ($readme === null) {
            return default_plugin_readme_txt_data();
        }

        $path = (string) ($readme['path'] ?? '');
        if ($path === '' || !array_key_exists($path, $file_contents)) {
            return default_plugin_readme_txt_data();
        }

        return self::readme_info_from_content($readme, (string) $file_contents[$path]);
    }

    private static function readme_info_from_content(array $readme, string $content): array {
        $parsed = parse_readme_txt(self::normalize_readme_content($content));

        return [
            'found' => true,
            'file_name' => (string) ($readme['name'] ?? 'readme.txt'),
            'content' => json_encode($parsed) !== false ? $parsed : [],
        ];
    }

    private static function normalize_readme_content(string $content): string {
        if (!is_utf8($content)) {
            $content = convert_to_utf8($content, detect_text_encoding($content));
        }
        return strip_utf8_bom($content);
    }
}
