<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Releases as stored artifacts, both channels: the snapshot a release post carries — what
 * the APIs and the editor read; self-hosted derived from the release ZIP, wporg from the
 * tag —, the content hash of a shipped tree, the entries of a release ZIP, and the one
 * entry point of an edit after the upload (set_release_tested_up_to()). The upload state is
 * the record of the upload (meta _pblsh_upload_state, docs/data-schema.md); the snapshot is
 * the release as it is now.
 */

/**
 * The entries of a tree: every directory and file below $root, paths relative to $root with
 * '/' separators; a file's `stream` opens its content.
 *
 * @return iterable<array{path:string, is_file:bool, stream:?callable}>
 */
function directory_entries(string $root): iterable {
    $root = rtrim($root, '/\\');
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $entry) {
        $abs = $entry->getPathname();
        $is_file = $entry->isFile();
        yield [
            'path' => str_replace('\\', '/', substr($abs, strlen($root) + 1)),
            'is_file' => $is_file,
            'stream' => $is_file ? static fn() => fopen($abs, 'rb') : null,
        ];
    }
}


/**
 * The entries of a release ZIP below its one root folder ({slug}/): every directory the
 * archive lists explicitly or implies by a file path — a ZIP built before directory entries
 * were written hashes like the tree without its empty folders —, every file with its content.
 * The ZIP helpers trust their callers: the edit and the migration check the file and the zip
 * extension (release_zip_support()) before any of them runs.
 *
 * @return iterable<array{path:string, is_file:bool, stream:?callable}>
 */
function zip_entries(string $zip_path): iterable {
    $zip = new \ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return;
    }
    try {
        $dirs = [];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $slash = strpos($name, '/');
            $rel = $slash === false ? '' : substr($name, $slash + 1);
            if ($rel === '') {
                continue; // the root folder itself
            }
            if (str_ends_with($rel, '/')) {
                $dirs[rtrim($rel, '/')] = true;
                continue;
            }
            $files[$rel] = $name;
            for ($parent = dirname($rel); $parent !== '.' && $parent !== ''; $parent = dirname($parent)) {
                $dirs[$parent] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            yield [ 'path' => (string) $dir, 'is_file' => false, 'stream' => null ];
        }
        foreach ($files as $rel => $name) {
            yield [ 'path' => (string) $rel, 'is_file' => true, 'stream' => static fn() => $zip->getStream($name) ];
        }
    } finally {
        $zip->close();
    }
}


/**
 * The content hash of a shipped tree: sha256 of every entry's path and, for files, of its
 * content, sorted and concatenated — one formula, two sources (directory_entries() at
 * analyze, zip_entries() after an edit). Stored as the release meta
 * _pblsh_directory_content_hash.
 */
function content_hash_from_entries(iterable $entries): string {
    $hashes = [];
    foreach ($entries as $entry) {
        $hashes[] = hash('sha256', $entry['path']);
        if ($entry['is_file']) {
            $stream = ($entry['stream'])();
            $ctx = hash_init('sha256');
            if (is_resource($stream)) {
                hash_update_stream($ctx, $stream);
                fclose($stream);
            }
            $hashes[] = hash_final($ctx);
        }
    }
    sort($hashes);
    return hash('sha256', implode('', $hashes));
}


/** The absolute path of a self-hosted release's ZIP, '' without one. */
function release_zip_path(\WP_Post $release): string {
    $rel = (string) get_post_meta((int) $release->ID, '_pblsh_zip_path', true);
    return $rel === '' ? '' : trailingslashit(peak_publisher_upload_basedir()) . ltrim($rel, '/\\');
}


/** Whether this host can read and edit release ZIPs: the PHP zip extension (ZipArchive). */
function release_zip_support(): bool {
    return class_exists('\\ZipArchive');
}


/** The error of an edit on a host without the zip extension — the build has its PclZip fallback, the edit has none. */
function release_zip_unsupported_error(): \WP_Error {
    return new \WP_Error('release_zip_unsupported', __('Editing a release ZIP needs the PHP zip extension (ZipArchive), which this server lacks.', 'peak-publisher'), [ 'status' => 500 ]);
}


/** One entry's content, null when the archive cannot be opened or the entry is absent. */
function read_release_zip_entry(string $zip_path, string $name): ?string {
    $zip = new \ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return null;
    }
    $content = $zip->getFromName($name);
    $zip->close();
    return $content === false ? null : $content;
}


/**
 * The snapshot of a self-hosted release from its ZIP — the same three keys as a wporg tag
 * snapshot: plugin_data (read_plugin_headers() of the main file, the first top-level PHP
 * file with a plugin name), plugin_info, plugin_readme_txt (parse_readme_txt() of the
 * top-level readme, wordpress.org's file precedence). Null when the archive cannot be opened
 * or no main file is found.
 */
function release_snapshot_from_zip(string $zip_path): ?array {
    $zip = new \ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return null;
    }
    try {
        $root = '';
        $top_level = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts = explode('/', (string) $zip->getNameIndex($i));
            if ($root === '') {
                $root = $parts[0];
            }
            if (count($parts) === 2 && $parts[1] !== '' && $parts[0] === $root) {
                $top_level[] = $parts[1];
            }
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $plugin_data = null;
        $main_file = '';
        foreach ($top_level as $file) {
            if (substr($file, -4) !== '.php') {
                continue;
            }
            $tmp = wp_tempnam($file);
            if (!is_string($tmp) || $tmp === '') {
                continue;
            }
            file_put_contents($tmp, (string) $zip->getFromName($root . '/' . $file));
            $data = read_plugin_headers($tmp);
            wp_delete_file($tmp);
            if (!empty($data['Name'])) {
                $plugin_data = $data;
                $main_file = $file;
                break;
            }
        }
        if ($plugin_data === null) {
            return null;
        }
        $readme_info = default_plugin_readme_txt_data();
        $readme_name = find_wporg_readme_file_name($top_level);
        if ($readme_name !== null) {
            $content = (string) $zip->getFromName($root . '/' . $readme_name);
            if (!is_utf8($content)) {
                $content = convert_to_utf8($content, detect_text_encoding($content));
            }
            $parsed = parse_readme_txt(strip_utf8_bom($content));
            $readme_info = [ 'found' => true, 'file_name' => $readme_name, 'content' => json_encode($parsed) !== false ? $parsed : [] ];
        }
        $version = (string) ($plugin_data['Version'] ?? '');
        return [
            'plugin_data' => $plugin_data,
            'plugin_info' => [
                'normalized_version' => normalize_version_number($version),
                'release_slug' => get_release_slug('self_hosted', $root, $version),
                'main_file' => $main_file,
                'plugin_basename' => $root . '/' . $main_file,
                'plugin_slug' => $root,
            ],
            'plugin_readme_txt' => $readme_info,
        ];
    } finally {
        $zip->close();
    }
}


/**
 * The snapshot of a self-hosted release from the upload state that built its ZIP — the same
 * three keys, from the facts finalize has in hand (the workspace is what was zipped): no ZIP
 * read, so a host without the zip extension builds releases as before. plugin_info keeps the
 * release's facts only; the upload's (slug candidates, bootstrap, content hash) stay in the
 * upload state.
 */
function release_snapshot_from_upload_state(array $data): array {
    $info = is_array($data['plugin_info'] ?? null) ? $data['plugin_info'] : [];
    return [
        'plugin_data' => is_array($data['plugin_data'] ?? null) ? $data['plugin_data'] : [],
        'plugin_info' => [
            'normalized_version' => (string) ($info['normalized_version'] ?? ''),
            'release_slug' => (string) ($info['release_slug'] ?? ''),
            'main_file' => basename((string) ($info['main_file'] ?? '')),
            'plugin_basename' => (string) ($info['plugin_basename'] ?? ''),
            'plugin_slug' => (string) ($info['plugin_slug'] ?? ''),
        ],
        'plugin_readme_txt' => is_array($data['plugin_readme_txt'] ?? null) ? $data['plugin_readme_txt'] : default_plugin_readme_txt_data(),
    ];
}


/** The stored snapshot — re-validation when reading back from persistence: only the three keys count. */
function release_snapshot(\WP_Post $release): array {
    $content = json_decode((string) $release->post_content, true);
    $content = is_array($content) ? $content : [];
    return [
        'plugin_data' => is_array($content['plugin_data'] ?? null) ? $content['plugin_data'] : [],
        'plugin_info' => is_array($content['plugin_info'] ?? null) ? $content['plugin_info'] : [],
        'plugin_readme_txt' => is_array($content['plugin_readme_txt'] ?? null) ? $content['plugin_readme_txt'] : default_plugin_readme_txt_data(),
    ];
}


/** Writes a release's snapshot as its content; false when it cannot be encoded or stored. */
function write_release_snapshot(\WP_Post $release, array $snapshot): bool {
    $json = wp_json_encode($snapshot);
    if ($json === false) {
        return false;
    }
    return !is_wp_error(write_data_post([ 'ID' => (int) $release->ID, 'post_content' => wp_slash($json) ], true));
}


/** The effective Tested up to of a stored release (effective_tested_up_to() over its snapshot). */
function release_tested_up_to(\WP_Post $release): array {
    $snapshot = release_snapshot($release);
    return effective_tested_up_to($snapshot['plugin_data'], $snapshot['plugin_readme_txt']['content'] ?? null);
}


/**
 * Replaces entries of a release ZIP in place: on a copy — the original stays whatever fails —,
 * then an atomic rename. ZipArchive only: the build keeps a PclZip fallback for hosts without
 * the zip extension, the edit does not (release_zip_unsupported_error() says so).
 *
 * @param array<string, string> $entries ZIP entry name => new content.
 * @return true|\WP_Error release_zip_write_failed (500).
 */
function replace_release_zip_entries(string $zip_path, array $entries): true|\WP_Error {
    $failed = static fn(): \WP_Error => new \WP_Error('release_zip_write_failed', __('The release ZIP could not be written. The stored file is unchanged.', 'peak-publisher'), [ 'status' => 500 ]);
    $copy = $zip_path . '.pblsh-edit';
    if (!@copy($zip_path, $copy)) {
        return $failed();
    }
    $zip = new \ZipArchive();
    $ok = $zip->open($copy) === true;
    if ($ok) {
        foreach ($entries as $name => $content) {
            $ok = $zip->addFromString($name, $content) && $ok;
        }
        $ok = $zip->close() && $ok;
    }
    if (!$ok || !@rename($copy, $zip_path)) {
        @unlink($copy);
        return $failed();
    }
    return true;
}


/**
 * Sets Tested up to of one release — the entry point of both channels: self-hosted here (the
 * ZIP's readme line and, when the main file carries the line, that line too; then the snapshot,
 * the content hash and the operations log), wporg through WporgOperations::set_tested_up_to()
 * (one commit in the tag and, when trunk carries this version, trunk; the caller resolves the
 * account). The value must be major.minor; lowering is allowed. Both channels write what
 * plan_tested_up_to_edit() decides.
 *
 * @param string $expected The effective value the client showed — a different live value
 *        refuses the edit (tested_up_to_changed, 409), as a moved pointer refuses a flip.
 * @param string|null $wporg_username The wordpress.org account a wporg edit commits as.
 * @return array{version:string, from:string, to:string, revision:?int, trunk:?array{state:string, version:?string}}|\WP_Error
 *         trunk (wporg, WporgOperations::set_tested_up_to()): whether trunk followed; null self-hosted.
 */
function set_release_tested_up_to(\WP_Post $release, string $value, string $expected, ?string $wporg_username = null): array|\WP_Error {
    if (!is_valid_tested_up_to($value)) {
        /* translators: %s: the current WordPress version, major.minor */
        return new \WP_Error('invalid_tested_up_to', sprintf(__('Tested up to takes a WordPress version like %s.', 'peak-publisher'), wordpress_stable_branch()), [ 'status' => 400 ]);
    }
    $parent = get_post((int) $release->post_parent);
    if (!is_plugin_post($parent)) {
        return new \WP_Error('release_not_found', __('Release not found.', 'peak-publisher'), [ 'status' => 404 ]);
    }
    $version = (string) $release->post_title;

    if (is_wporg_plugin($parent)) {
        require_once PBLSH_PLUGIN_DIR . 'classes/WporgOperations.php';
        try {
            $result = WporgOperations::set_tested_up_to($parent, $version, $value, $expected, (string) $wporg_username);
        } catch (WporgSvnException $e) {
            return $e->to_wp_error();
        }
        // The plan always has files (plan_tested_up_to_edit()): a commit that did not land is an
        // invariant violation, never a success to report — a revision-less notice says nothing.
        if (!$result['committed']) {
            throw new \RuntimeException('tested_up_to_not_committed');
        }
        // The commit changed the tag and maybe trunk: a cache fresh at its base stays fresh
        // when nothing else moved, with trunk's readme as the commit wrote it; otherwise it goes
        // stale and serves that readme until the refresh — the flip's pattern.
        $known = $result['trunk_readme'] !== null ? [ 'trunk_readme' => $result['trunk_readme'] ] : [];
        try {
            $only_these = WporgOperations::plugin_changed_only_in($parent->post_name, $result['trunk']['state'] === 'written' ? [ 'tags', 'trunk' ] : [ 'tags' ], (int) $result['base_revision']);
        } catch (\Throwable $e) {
            $only_these = false;
        }
        if ($only_these) {
            advance_wporg_plugin_cache((int) $parent->ID, (int) $result['base_revision'], (int) $result['revision'], $known);
        } else {
            mark_wporg_plugin_cache_stale((int) $parent->ID, $known);
        }
        return [ 'version' => $version, 'from' => $result['from'], 'to' => $value, 'revision' => (int) $result['revision'], 'trunk' => $result['trunk'] ];
    }

    // The ZIP is the release: checked once here, the ZIP helpers below trust it.
    $zip = release_zip_path($release);
    if ($zip === '' || !is_file($zip)) {
        return new \WP_Error('release_zip_missing', __('The release ZIP is missing from disk — upload this version again.', 'peak-publisher'), [ 'status' => 409 ]);
    }
    if (!release_zip_support()) {
        return release_zip_unsupported_error();
    }
    $snapshot = release_snapshot($release);
    $effective = release_tested_up_to($release);
    // The slug is the ZIP's root folder ({slug}/), recorded in every snapshot.
    $root = (string) ($snapshot['plugin_info']['plugin_slug'] ?? '');
    $names = [
        'readme' => (string) ($snapshot['plugin_readme_txt']['file_name'] ?? ''),
        'main' => (string) ($snapshot['plugin_info']['main_file'] ?? ''),
    ];
    $read = static fn(string $name): ?string => $name !== '' ? read_release_zip_entry($zip, $root . '/' . $name) : null;
    $changes = plan_tested_up_to_edit($read($names['readme']), $read($names['main']), $effective, $value, $expected);
    if (is_wp_error($changes)) {
        return $changes;
    }
    $entries = [];
    foreach ($changes as $file => $content) {
        $entries[$root . '/' . $names[$file]] = $content;
    }
    $zip_sha256_before = (string) hash_file('sha256', $zip);
    $written = replace_release_zip_entries($zip, $entries);
    if (is_wp_error($written)) {
        return $written;
    }
    // The ZIP was just written from a readable one with only header lines changed: a snapshot
    // that cannot be read back or stored is an invariant violation, not a user error.
    $new_snapshot = release_snapshot_from_zip($zip);
    if ($new_snapshot === null || !write_release_snapshot($release, $new_snapshot)) {
        throw new \RuntimeException('release_snapshot_not_written');
    }
    update_post_meta((int) $release->ID, '_pblsh_directory_content_hash', content_hash_from_entries(zip_entries($zip)));
    record_operation((int) $parent->ID, 'tested_up_to', null, null, [
        'version' => $version,
        'from' => $effective['value'],
        'to' => $value,
        'source_before' => $effective['source'],
        'files' => array_keys($entries),
        'zip_sha256_before' => $zip_sha256_before,
        'zip_sha256_after' => (string) hash_file('sha256', $zip),
    ]);
    return [ 'version' => $version, 'from' => $effective['value'], 'to' => $value, 'revision' => null, 'trunk' => null ];
}


/**
 * The Tested up to facts of an upload against one target — the server yields facts, the
 * client judges (the validation split): the upload's effective value, the predecessor in the
 * version line (the replaced version, else the highest below) with its effective value, the
 * current release's, and the carry carry_tested_up_to() decides from the upload's lines and
 * the predecessor.
 *
 * @param array|false $related_releases The target plugin's related releases
 *        (UploadWorkflow::find_related_releases(): existing, previous, next, latest), false
 *        without a plugin.
 * @param \WP_Post|null $current_release The target's current release post, when it has one.
 * @return array{upload:array, predecessor:?array{version:string, value:string}, current:?array{version:string, value:string}, carry:?array{value:string, from:?string, normalized:bool}, stable_branch:string}
 *         stable_branch: the WordPress version the checklist judges against — recorded with the
 *         facts, so the upload state keeps what the dialog showed.
 */
function tested_up_to_upload_facts(array $plugin_data, ?array $readme_content, $related_releases, ?\WP_Post $current_release): array {
    $upload = effective_tested_up_to($plugin_data, $readme_content);
    $predecessor = null;
    if (is_array($related_releases)) {
        $info = $related_releases['existing'] ?: ($related_releases['previous'] ?: null);
        $post = is_array($info) ? get_post((int) $info['id']) : null;
        if ($post instanceof \WP_Post) {
            $predecessor = [ 'version' => (string) $info['version'], 'value' => release_tested_up_to($post)['value'] ];
        }
    }
    $current = $current_release instanceof \WP_Post
        ? [ 'version' => (string) $current_release->post_title, 'value' => release_tested_up_to($current_release)['value'] ]
        : null;
    return [
        'upload' => $upload,
        'predecessor' => $predecessor,
        'current' => $current,
        'carry' => carry_tested_up_to($upload, $predecessor['value'] ?? null),
        'stable_branch' => wordpress_stable_branch(),
    ];
}
