<?php

namespace Pblsh;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WporgSvnException.php';


class SvnDeployWorkflow {
    public static function deploy_directory(
        string $root,
        string $version,
        string $wporg_slug,
        string $username,
        bool $touch_trunk = true,
        ?callable $progress = null
    ): array {
        raise_wporg_time_limit();

        // Validate deploy inputs and account credentials
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $username = normalize_wporg_username($username, 'username');
        if (is_wp_error($username)) {
            throw WporgSvnException::from_wp_error($username);
        }
        $version = self::safe_path_segment($version);
        $root = trailingslashit($root);
        if (!is_dir($root) || !is_readable($root)) {
            throw self::exception('deploy_directory_missing');
        }

        $credentials = get_wporg_credentials($username);
        if (is_wp_error($credentials)) {
            throw WporgSvnException::from_wp_error($credentials);
        }
        if ($credentials === null || empty($credentials['password'])) {
            throw self::exception('account_not_configured');
        }

        // Lock this wporg slug before writing to SVN
        $lock = self::acquire_wporg_deploy_lock($wporg_slug, $username, 'deploy');
        $client = null;
        try {
            // Collect the local tree that will be reconciled into SVN
            $local_tree = self::collect_local_tree($root);
            if (!self::tree_has_php_file($local_tree)) {
                throw self::exception('wporg_tag_requires_php');
            }

            // Read the base revision before building the remote diff
            $deploy_base_revision = self::get_plugin_revision($wporg_slug);
            if ($deploy_base_revision === null) {
                throw self::exception('not_found');
            }

            $client = self::svn_client($username, $credentials['password']);

            if (is_callable($progress)) {
                $progress('diff');
            }

            // Build reconcile plans for trunk and the version tag
            $plans = [];
            if ($touch_trunk) {
                $remote_trunk = self::read_remote_tree($client, $wporg_slug, 'trunk');
                $plans[] = self::build_reconcile_plan($client, $wporg_slug, 'trunk', $local_tree, $remote_trunk);
            }

            $tag_base = 'tags/' . $version;
            $remote_tag = self::read_remote_tree($client, $wporg_slug, $tag_base);
            $plans[] = self::build_reconcile_plan($client, $wporg_slug, $tag_base, $local_tree, $remote_tag);

            // Abort if SVN changed since the diff was built
            $current_revision = self::get_plugin_revision($wporg_slug);
            if ($current_revision === null || (int) $current_revision !== (int) $deploy_base_revision) {
                throw self::exception('wporg_concurrent_external_change');
            }

            if (is_callable($progress)) {
                $progress('commit');
            }

            // Apply the reconcile plans and commit them as one revision
            $client->begin_commit($wporg_slug);
            foreach ($plans as $plan) {
                self::apply_reconcile_plan($client, $plan);
            }

            $commit = $client->commit(sprintf('Publish %s %s via Peak Publisher', $wporg_slug, $version));
            // A successful commit is the strongest possible credential verdict.
            record_wporg_credentials_verdict($username, true);
            return [
                'revision' => (int) ($commit['revision'] ?? 0),
                'committed' => true,
                'touched_trunk' => $touch_trunk,
            ];
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'invalid_credentials') {
                record_wporg_credentials_verdict($username, false);
            }
            if ($client instanceof WporgPluginSvnClient && in_array($e->get_error_code(), ['wporg_concurrent_external_change'], true)) {
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

            $tags[] = [
                'version' => $version,
                'date' => (string) ($entry['last_modified'] ?? ''),
                'revision' => (int) ($entry['revision'] ?? 0),
            ];
        }

        usort($tags, function(array $a, array $b): int {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        return $tags;
    }


    public static function get_plugin_revision(string $wporg_slug): ?int {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $client = self::svn_client();

        try {
            $entries = $client->list_directory($wporg_slug . '/', 0);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return null;
            }
            throw $e;
        }

        foreach ($entries as $entry) {
            if (self::normalize_path((string) ($entry['path'] ?? '')) === $wporg_slug) {
                $revision = (int) ($entry['revision'] ?? 0);
                return $revision > 0 ? $revision : null;
            }
        }

        $revision = (int) ($entries[0]['revision'] ?? 0);
        return $revision > 0 ? $revision : null;
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

    public static function delete_tag(string $wporg_slug, string $version, string $username): array {
        raise_wporg_time_limit();

        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $version = self::safe_path_segment($version);
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

        $lock = self::acquire_wporg_deploy_lock($wporg_slug, $username, 'delete');
        $client = null;
        try {
            $client = self::svn_client($username, $credentials['password']);
            $tag_path = $wporg_slug . '/tags/' . $version . '/';

            $deploy_base_revision = self::get_plugin_revision($wporg_slug);
            if ($deploy_base_revision === null) {
                throw self::exception('not_found');
            }

            try {
                $client->list_directory($tag_path, 0);
            } catch (WporgSvnException $e) {
                if ($e->get_error_code() === 'not_found') {
                    return [
                        'revision' => 0,
                        'committed' => false,
                        'deleted' => false,
                        'tag_existed' => false,
                    ];
                }
                throw $e;
            }

            $current_revision = self::get_plugin_revision($wporg_slug);
            if ($current_revision === null || (int) $current_revision !== (int) $deploy_base_revision) {
                throw self::exception('wporg_concurrent_external_change');
            }

            $client->begin_commit($wporg_slug);
            $client->del('tags/' . $version);
            $commit = $client->commit(sprintf('Delete %s %s via Peak Publisher', $wporg_slug, $version));

            return [
                'revision' => (int) ($commit['revision'] ?? 0),
                'committed' => true,
                'deleted' => true,
                'tag_existed' => true,
            ];
        } catch (WporgSvnException $e) {
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
            $access = self::svn_client($username, $credentials['password'])->check_repo_access($wporg_slug);
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
            'closed_date' => null,
        ];
        $normalized = normalize_wporg_username($username);
        if (is_wp_error($normalized)) {
            return $hint;
        }

        $info = self::fetch_directory_info($wporg_slug);

        if ($info['state'] === 'published') {
            $hint['state'] = 'published';
            $hint['name'] = $info['name'];
            $hint['icon'] = $info['icon'];
            $hint['description'] = $info['description'];
            $hint['release_count'] = $info['release_count'];
            $directory_owner = wporg_string_from_value($info['owner'] ?? '');
            $hint['owner'] = $directory_owner !== '' ? $directory_owner : null;
            // Owner beats the contributor listing — it also covers owners a readme
            // forgot to list as contributors.
            if ($directory_owner !== '' && self::username_in_list($normalized, [ $directory_owner ])) {
                $hint['relation'] = 'owner';
            } elseif (self::username_in_list($normalized, $info['contributors'])) {
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
            $hint['reason'] = $info['reason'];
            $hint['name'] = $info['name'];
            $hint['closed_date'] = $info['closed_date'];
            return $hint;
        }

        if ($info['state'] === 'unpublished') {
            // Freshly approved: the page only goes live with the first commit, but the
            // repository creation commit already names the owner and the approval time.
            $hint['state'] = 'fresh';
            $creation = self::creation_commit_facts($wporg_slug);
            if ($creation !== null) {
                $hint['owner'] = $creation['owner'];
                $hint['created'] = $creation['created'];
                $hint['relation'] = self::username_in_list($normalized, [ $creation['owner'] ]) ? 'owner' : 'not_listed';
            }
            return $hint;
        }

        return $hint;
    }

    /**
     * Fetches the plugin's public directory state from the plugins.info API.
     * Deliberately uncached: the hint is only requested a few times per upload flow,
     * and after the user fixes something on wordpress.org (contributor added, plugin
     * published or reopened) the next check must reflect it immediately.
     *
     * @return array{state:string, contributors:string[], owner:string|null, reason:string|null, name:string|null, icon:string|null, description:string|null, release_count:int|null, closed_date:string|null}
     *         state: published|closed|unpublished|unknown; owner = user_nicename of the
     *         current plugin owner (from author_profile), only for published plugins;
     *         release_count = number of tagged versions (what an import creates as
     *         releases), only for published plugins; closed_date = Y-m-d closure
     *         date, only for closed plugins (null when wordpress.org withholds it).
     */
    private static function fetch_directory_info(string $wporg_slug): array {
        $url = add_query_arg([
            'action' => 'plugin_information',
            'request' => [
                'slug' => $wporg_slug,
                // icons and short_description are opt-in fields in the 1.2 API.
                'fields' => [ 'contributors' => true, 'icons' => true, 'short_description' => true ],
            ],
        ], 'https://api.wordpress.org/plugins/info/1.2/');

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 3,
            'user-agent' => 'Peak Publisher wordpress.org Discovery',
        ]);
        $unknown = [ 'state' => 'unknown', 'contributors' => [], 'owner' => null, 'reason' => null, 'name' => null, 'icon' => null, 'description' => null, 'release_count' => null, 'closed_date' => null ];
        if (is_wp_error($response)) {
            return $unknown;
        }
        // The API answers unknown AND closed plugins with HTTP 404 — both carry a
        // meaningful JSON body, so 404 is a determinate outcome, not a failure.
        $status_code = (int) wp_remote_retrieve_response_code($response);
        if (!in_array($status_code, [200, 404], true)) {
            return $unknown;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return $unknown;
        }

        // The directory listing's display texts, cleaned for direct output.
        $clean_text = static function ($value): ?string {
            $text = trim(html_entity_decode(wp_strip_all_tags(wporg_string_from_value($value)), ENT_QUOTES | ENT_HTML5));
            return $text !== '' ? $text : null;
        };

        // The API's own contract tells the states apart: closed plugins answer with
        // error "closed" (plus the closed flag), every other error is a miss, and a
        // successful listing never carries an error key.
        if (($data['error'] ?? '') === 'closed' || !empty($data['closed'])) {
            // Covers closed AND temporarily disabled plugins — the API reports both as closed.
            $reason = wporg_string_from_value($data['reason_text'] ?? '');
            $info = [
                'state' => 'closed',
                'contributors' => [],
                'owner' => null,
                'reason' => $reason !== '' ? $reason : null,
                'name' => $clean_text($data['name'] ?? ''),
                'icon' => null,
                'description' => null,
                'release_count' => null,
                'closed_date' => $clean_text($data['closed_date'] ?? ''),
            ];
        } elseif (!isset($data['error'])) {
            // author_profile is the profile URL of the current plugin owner (post author);
            // its last path segment is the owner's user_nicename.
            $owner = '';
            $author_profile = wporg_string_from_value($data['author_profile'] ?? '');
            if ($author_profile !== '') {
                $profile_path = trim((string) parse_url($author_profile, PHP_URL_PATH), '/');
                $owner = $profile_path !== '' ? basename($profile_path) : '';
            }

            $icon = self::pick_icon_url($data['icons'] ?? null);

            // versions is a default field of plugin_information: the tagged versions
            // plus one trunk entry (present only when tags exist). The tag count is
            // what the import will create as releases — one release per SVN tag.
            $release_count = null;
            if (is_array($data['versions'] ?? null)) {
                $release_count = count(array_diff(array_map('strval', array_keys($data['versions'])), [ 'trunk' ]));
            }

            // contributors is keyed by user_nicename (a requested field, always set
            // for published plugins — the directory falls back to the post author).
            $contributors = is_array($data['contributors'] ?? null) ? $data['contributors'] : [];

            $info = [
                'state' => 'published',
                'contributors' => array_map('strval', array_keys($contributors)),
                'owner' => $owner !== '' ? $owner : null,
                'reason' => null,
                'name' => $clean_text($data['name'] ?? ''),
                'icon' => $icon,
                'description' => $clean_text($data['short_description'] ?? ''),
                'release_count' => $release_count,
                'closed_date' => null,
            ];
        } else {
            // "Plugin not found." — callers only ask after the SVN probe confirmed the
            // repository exists, so this means: approved but not published yet.
            $info = [
                'state' => 'unpublished',
                'contributors' => [],
                'owner' => null,
                'reason' => null,
                'name' => null,
                'icon' => null,
                'description' => null,
                'release_count' => null,
                'closed_date' => null
            ];
        }

        return $info;
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

    /** Picks the preferred icon URL from a wordpress.org icons map (or null). */
    private static function pick_icon_url($icons): ?string {
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

    public static function discover_plugins_by_author(string $username): array {
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
            $url = add_query_arg([
                'action' => 'query_plugins',
                'request' => [
                    'author' => $normalized_username,
                    'per_page' => $per_page,
                    'page' => $page,
                    // icons are an opt-in field of the 1.2 API.
                    'fields' => [ 'icons' => true ],
                ],
            ], 'https://api.wordpress.org/plugins/info/1.2/');

            $response = wp_remote_get($url, [
                'timeout' => 20,
                'redirection' => 3,
                'user-agent' => 'Peak Publisher wordpress.org Discovery',
            ]);
            if (is_wp_error($response)) {
                throw self::exception('wporg_api_unavailable');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                throw self::exception('wporg_api_unavailable');
            }

            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($data) || !is_array($data['plugins'] ?? null)) {
                throw self::exception('wporg_api_unavailable');
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
                $name = html_entity_decode(wp_strip_all_tags((string) ($plugin['name'] ?? $slug)), ENT_QUOTES | ENT_HTML5);
                $plugins[] = [
                    'slug' => $slug,
                    'name' => $name !== '' ? $name : $slug,
                    'icon' => self::pick_icon_url($plugin['icons'] ?? null),
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

    private static function read_remote_tree(WporgPluginSvnClient $client, string $wporg_slug, string $base): array {
        // Read the remote SVN subtree into a flat path map
        $base = trim($base, '/');
        $tree = [];
        self::read_remote_tree_into($client, $wporg_slug, $base, '', $tree);
        ksort($tree);
        return $tree;
    }

    private static function read_remote_tree_into(WporgPluginSvnClient $client, string $wporg_slug, string $base, string $relative_dir, array &$tree): void {
        $remote_dir = trim($wporg_slug . '/' . $base . '/' . trim($relative_dir, '/'), '/') . '/';

        try {
            $entries = $client->list_directory($remote_dir, 1);
        } catch (WporgSvnException $e) {
            if ($e->get_error_code() === 'not_found') {
                return;
            }
            throw $e;
        }

        $children = self::direct_children($entries, trim($wporg_slug . '/' . $base . '/' . trim($relative_dir, '/'), '/'));
        // Add direct children and recurse into directories
        foreach ($children as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $rel = trim($relative_dir . '/' . $name, '/');
            $type = (string) ($entry['type'] ?? '');
            if ($type !== 'dir' && $type !== 'file') {
                continue;
            }

            $tree[$rel] = [
                'type' => $type,
                'path' => trim($base . '/' . $rel, '/'),
                'size' => isset($entry['size']) ? $entry['size'] : null,
            ];

            if ($type === 'dir') {
                self::read_remote_tree_into($client, $wporg_slug, $base, $rel, $tree);
            }
        }
    }

    private static function build_reconcile_plan(WporgPluginSvnClient $client, string $wporg_slug, string $base, array $local_tree, array $remote_tree): array {
        $delete_paths = [];
        $mkdir_paths = [];
        $put_paths = [];

        // Plan remote paths that must disappear or change type
        foreach ($remote_tree as $rel => $remote) {
            $local = $local_tree[$rel] ?? null;
            if ($local === null || (string) ($local['type'] ?? '') !== (string) ($remote['type'] ?? '')) {
                $delete_paths[$rel] = [
                    'path' => self::join_svn_path($base, $rel),
                    'type' => (string) ($remote['type'] ?? ''),
                    'reason' => $local === null ? 'remote_only' : 'type_change',
                ];
            }
        }

        $delete_paths = self::compact_delete_paths($delete_paths);

        // Fetch same-size files in one batch for the content comparison below
        $compare_paths = [];
        foreach ($local_tree as $rel => $local) {
            $remote = $remote_tree[$rel] ?? null;
            if ((string) ($local['type'] ?? '') !== 'file' || !is_array($remote) || (string) ($remote['type'] ?? '') !== 'file') {
                continue;
            }
            if ((int) ($remote['size'] ?? -1) === (int) ($local['size'] ?? 0)) {
                $compare_paths[$rel] = $wporg_slug . '/' . self::join_svn_path($base, $rel);
            }
        }
        $remote_contents = self::read_remote_files($client, array_values($compare_paths));

        // Plan local directories and files that must be created or updated
        foreach ($local_tree as $rel => $local) {
            $remote = $remote_tree[$rel] ?? null;
            $local_type = (string) ($local['type'] ?? '');
            $remote_type = is_array($remote) ? (string) ($remote['type'] ?? '') : '';

            if ($local_type === 'dir') {
                if ($remote_type !== 'dir') {
                    $mkdir_paths[$rel] = self::join_svn_path($base, $rel);
                }
                continue;
            }

            if ($local_type !== 'file') {
                continue;
            }

            $needs_put = false;
            if ($remote_type !== 'file' || !isset($compare_paths[$rel])) {
                $needs_put = true;
            } else {
                $remote_content = (string) ($remote_contents[$compare_paths[$rel]] ?? '');
                $needs_put = md5($remote_content) !== (string) ($local['hash'] ?? '');
            }

            if ($needs_put) {
                $put_paths[$rel] = [
                    'path' => self::join_svn_path($base, $rel),
                    'local_path' => (string) ($local['path'] ?? ''),
                ];
            }
        }

        // Order operations so deletes are deep-first and directories are shallow-first
        uasort($delete_paths, static fn(array $a, array $b): int => substr_count((string) $b['path'], '/') <=> substr_count((string) $a['path'], '/'));
        uasort($mkdir_paths, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        uasort($put_paths, static fn(array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return [
            'base' => $base,
            'delete' => array_values($delete_paths),
            'mkdir' => array_values($mkdir_paths),
            'put' => array_values($put_paths),
        ];
    }

    private static function apply_reconcile_plan(WporgPluginSvnClient $client, array $plan): void {
        // Ensure the base directory exists before child operations
        $base = (string) ($plan['base'] ?? '');
        if ($base !== '') {
            $client->mkdir($base);
        }

        // Delete remote-only or type-changed paths first
        foreach (($plan['delete'] ?? []) as $delete) {
            if (is_array($delete) && !empty($delete['path'])) {
                $client->del((string) $delete['path']);
            }
        }

        // Create needed directories before uploading files
        foreach (($plan['mkdir'] ?? []) as $path) {
            if (is_string($path) && $path !== '') {
                $client->mkdir($path);
            }
        }

        // Upload changed or missing files
        foreach (($plan['put'] ?? []) as $put) {
            if (is_array($put) && !empty($put['path']) && !empty($put['local_path'])) {
                $client->add_file((string) $put['path'], (string) $put['local_path']);
            }
        }
    }

    private static function read_remote_files(WporgPluginSvnClient $client, array $paths): array {
        if (empty($paths)) {
            return [];
        }

        // Sequential fallback keeps deploys working on hosts without curl_multi
        if ($client::is_batch_transport_available()) {
            return $client->read_files_multi($paths);
        }

        $contents = [];
        foreach ($paths as $path) {
            $contents[$path] = $client->read_file($path);
        }
        return $contents;
    }

    private static function compact_delete_paths(array $delete_paths): array {
        // Drop child deletes when a parent directory delete already covers them
        $paths = array_keys($delete_paths);
        usort($paths, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        $kept = [];

        foreach ($paths as $path) {
            $skip = false;
            foreach ($kept as $kept_path) {
                if ($path !== $kept_path && str_starts_with($path, $kept_path . '/')) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $path;
            }
        }

        $out = [];
        foreach ($kept as $path) {
            $out[$path] = $delete_paths[$path];
        }
        return $out;
    }

    private static function join_svn_path(string $base, string $rel): string {
        return trim(trim($base, '/') . '/' . trim($rel, '/'), '/');
    }

    private static function acquire_wporg_deploy_lock(string $wporg_slug, string $username, string $operation = 'deploy', ?int $plugin_id = null): array {
        // Acquire a cross-site lock for this wporg slug
        $key = 'pblsh_wporg_deploy_lock_' . $wporg_slug;
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $lock_blog_id = (is_multisite() && function_exists('get_main_site_id')) ? (int) get_main_site_id() : $blog_id;
        $payload = [
            'key' => $key,
            'blog_id' => $blog_id,
            'lock_blog_id' => $lock_blog_id,
            'acquired_at' => time(),
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
            if ($acquire()) {
                return $payload;
            }

            $existing = $read_existing();
            $existing_time = is_array($existing) ? (int) ($existing['acquired_at'] ?? 0) : 0;
            // Reclaim stale locks before failing the deploy
            if ($existing_time > 0 && (time() - $existing_time) > 5 * 60) {
                $delete_existing();
                if ($acquire()) {
                    return $payload;
                }
            }

            throw self::exception('deploy_in_progress');
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function release_wporg_deploy_lock(array $lock): void {
        // Release only the lock owned by this deploy
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
            $existing = get_option($key, null);
            $matches = is_array($existing)
                && (int) ($existing['acquired_at'] ?? 0) === (int) ($lock['acquired_at'] ?? 0)
                && (string) ($existing['username'] ?? '') === (string) ($lock['username'] ?? '')
                && (string) ($existing['operation'] ?? '') === (string) ($lock['operation'] ?? '')
                && (string) ($existing['wporg_slug'] ?? '') === (string) ($lock['wporg_slug'] ?? '');
            if ($matches) {
                delete_option($key);
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
            'wporg_concurrent_external_change' => [__('The wordpress.org SVN repository changed while your release was being prepared. Please try again.', 'peak-publisher'), 409],
            'wporg_api_unavailable' => [__('wordpress.org API unavailable, try again later.', 'peak-publisher'), 503],
            'invalid_svn_path' => [__('The plugin contains a file or folder path that cannot be published to wordpress.org SVN. Remove path segments containing ".." or backslashes and try again.', 'peak-publisher'), 400],
            'invalid_svn_path_segment' => [__('The version cannot be used as a wordpress.org SVN path segment. Remove slashes, backslashes, and ".." from the version.', 'peak-publisher'), 400],
            'deploy_in_progress' => [__('Another change to this plugin is still being written to wordpress.org. Try again in a few minutes.', 'peak-publisher'), 409],
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
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp = wp_tempnam($name !== '' ? $name : 'pblsh-wporg-plugin.php');
        if (!is_string($tmp) || $tmp === '') {
            return null;
        }

        try {
            if (@file_put_contents($tmp, $contents) === false) {
                return null;
            }

            $plugin_data = get_plugin_data($tmp, false, false);
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
