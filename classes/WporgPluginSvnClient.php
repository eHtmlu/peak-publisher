<?php

namespace Pblsh;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WporgSvnException.php';


class WporgPluginSvnClient {
    private const REPO_URL = 'https://plugins.svn.wordpress.org/';

    private ?string $username;
    private ?string $password;
    private string $commit_slug = '';
    private string $activity_url = '';
    private string $activity_dav_url = '';
    private string $working_baseline_url = '';
    private string $working_root_url = '';
    private bool $activity_created = false;
    private bool $committed = false;

    public function __construct(?string $username = null, ?string $password = null) {
        $this->username = $username !== '' ? $username : null;
        $this->password = $password !== '' ? $password : null;
    }

    /**
     * Validates the credentials with a write-class request: wordpress.org stopped
     * authenticating read requests entirely (verified 2026-08-19 — PROPFIND accepts
     * any Authorization header), so only MKACTIVITY actually checks the password.
     * The probe activity never touches repository content and is deleted right away.
     */
    public function test_credentials(): array {
        $activity = $this->create_activity('', 'pblsh-probe');
        if (in_array($activity['options_status'], [ 401, 403 ], true)
            || in_array($activity['mkactivity_status'], [ 401, 403 ], true)) {
            throw new WporgSvnException(
                'invalid_credentials',
                __('Invalid wordpress.org username or password.', 'peak-publisher'),
                401
            );
        }
        if ($activity['step'] !== 'ok') {
            throw new WporgSvnException(
                'svn_auth_check_failed',
                __('wordpress.org SVN returned an unexpected authentication response.', 'peak-publisher'),
                502
            );
        }

        $this->discard_activity($activity['activity_url']);
        return [
            'status' => 'ok',
        ];
    }

    public function list_directory(string $path = '', int $depth = 1): array {
        $depth = max(0, min(1, $depth));
        return $this->directory_entries_from_response($this->propfind($path, $depth));
    }

    public function read_file(string $path): string {
        return $this->file_body_from_response($this->request('GET', $path));
    }

    public static function is_batch_transport_available(): bool {
        return function_exists('curl_multi_exec') && function_exists('curl_init');
    }

    public function list_directories_multi(array $paths, int $depth = 1, int $concurrency = 5): array {
        $depth = max(0, min(1, $depth));
        $responses = $this->request_paths_multi('PROPFIND', $paths, [
            'Depth' => (string) $depth,
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $this->propfind_body(), $concurrency);

        $out = [];
        foreach ($responses as $path => $response) {
            $out[$path] = $this->directory_entries_from_response($response);
        }

        return $out;
    }

    public function read_files_multi(array $paths, int $concurrency = 10): array {
        $responses = $this->request_paths_multi('GET', $paths, [], null, $concurrency);
        $out = [];

        foreach ($responses as $path => $response) {
            $out[$path] = $this->file_body_from_response($response);
        }

        return $out;
    }

    /**
     * Downloads a file into a local path — the assets mirror's downloads, up to 10 MB each,
     * which the HTTP API streams to disk instead of holding them in memory; the timeout grows
     * with the expected size like the upload's. Status handling is read_file()'s. A failure
     * may leave a partial file for the caller to remove. With $revision the file is read as it
     * was in that revision, through the baseline collection (!svn/bc/) — the history keeps
     * what the mirror lost.
     */
    public function download_file(string $path, string $local_path, int $expected_size, ?int $revision = null): void {
        $url = $revision === null
            ? $this->build_url($path)
            : self::REPO_URL . '!svn/bc/' . $revision . '/' . $this->encode_svn_path($path);
        $this->file_body_from_response($this->request_url('GET', $url, [], null, [
            'stream' => true,
            'filename' => $local_path,
            'timeout' => max(30, min(300, (int) ceil($expected_size / 100000))),
        ]));
    }

    /**
     * Checks whether the plugin repository exists and the stored credentials are accepted.
     *
     * wordpress.org stopped enforcing per-plugin write access on WebDAV probe requests
     * (verified 2026-08-19: CHECKOUT against a foreign plugin succeeds; commits are
     * rejected at MERGE time instead), so this check deliberately claims nothing about
     * write access. Ownership hints come from the public contributor list instead.
     *
     * @return array{status:string, message:string|null} status: ok|not_found|credentials_rejected|error
     */
    public function check_repo_access(string $path): array {
        $path = trim($path, '/');
        if ($path === '' || $this->username === null || $this->password === null) {
            return $this->repo_access_result(
                'error',
                __('Stored wordpress.org credentials are required for this check.', 'peak-publisher')
            );
        }

        $root_path = $path . '/';
        try {
            $lookup = $this->propfind($root_path, 0);
            $lookup_status = (int) ($lookup['status'] ?? 0);
            if ($lookup_status === 404) {
                return $this->repo_access_result(
                    'not_found',
                    __('Plugin not found on wordpress.org SVN.', 'peak-publisher')
                );
            }
            // 401 and 403 are both credential rejections — the same reading as test_credentials().
            if ($lookup_status === 401 || $lookup_status === 403) {
                return $this->repo_access_result(
                    'credentials_rejected',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher')
                );
            }
            if (!$this->is_success_status($lookup_status) && $lookup_status !== 207) {
                return $this->repo_access_result(
                    'error',
                    __('wordpress.org SVN returned an unexpected plugin lookup response.', 'peak-publisher')
                );
            }

            // Reads are anonymous on wordpress.org SVN — only a write-class request
            // (MKACTIVITY) actually validates the credentials. The probe activity is
            // deleted right away and never touches repository content.
            $activity = $this->create_activity($root_path, 'pblsh-probe');
            // 401 and 403 are both credential rejections — the same reading as test_credentials().
            if (in_array($activity['options_status'], [ 401, 403 ], true)
                || in_array($activity['mkactivity_status'], [ 401, 403 ], true)) {
                return $this->repo_access_result(
                    'credentials_rejected',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher')
                );
            }
            if ($activity['step'] === 'options') {
                return $this->repo_access_result(
                    'error',
                    __('wordpress.org SVN did not allow a credentials probe.', 'peak-publisher')
                );
            }
            if ($activity['step'] === 'collection') {
                return $this->repo_access_result(
                    'error',
                    __('wordpress.org SVN did not return an activity collection.', 'peak-publisher')
                );
            }
            if ($activity['step'] !== 'ok') {
                return $this->repo_access_result(
                    'error',
                    __('wordpress.org SVN could not create the temporary credentials probe activity.', 'peak-publisher')
                );
            }
            $this->discard_activity($activity['activity_url']);
        } catch (WporgSvnException $e) {
            return $this->repo_access_result('error', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->repo_access_result(
                'error',
                __('wordpress.org SVN credentials check failed.', 'peak-publisher')
            );
        }

        return $this->repo_access_result('ok', null);
    }

    /**
     * Opens a commit: reads the plugin root's commit resources, compares the root's version-name
     * (it bubbles up from every subtree) with $expected_revision — the revision the caller built
     * its plan against; a change since then voids the plan, and nothing has been created yet to
     * clean up — then creates the activity and checks out the working baseline and root.
     */
    public function begin_commit(string $wporg_slug, int $expected_revision): void {
        $wporg_slug = trim($wporg_slug, '/');
        if ($wporg_slug === '' || $this->username === null || $this->password === null) {
            throw new WporgSvnException(
                'svn_credentials_required',
                __('Stored wordpress.org credentials are required for SVN writes.', 'peak-publisher'),
                401
            );
        }
        if ($this->activity_created && !$this->committed) {
            throw new \RuntimeException('svn_commit_already_open');
        }

        $this->reset_commit_state();
        $this->commit_slug = $wporg_slug;
        $root_path = $wporg_slug . '/';

        try {
            // Read the commit resources from the plugin root
            $root = $this->propfind($root_path, 0);
            $root_status = (int) ($root['status'] ?? 0);
            if ($root_status === 404) {
                throw new WporgSvnException(
                    'not_found',
                    __('wordpress.org SVN path was not found.', 'peak-publisher'),
                    404
                );
            }
            // 401 and 403 are both credential rejections — the same reading as the
            // probes: wordpress.org decides plugin write access only at MERGE time.
            if ($root_status === 401 || $root_status === 403) {
                throw new WporgSvnException(
                    'invalid_credentials',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher'),
                    401
                );
            }
            if (!$this->is_success_status($root_status) && $root_status !== 207) {
                throw new WporgSvnException(
                    'svn_read_failed',
                    __('wordpress.org SVN returned an unexpected plugin lookup response.', 'peak-publisher'),
                    502
                );
            }

            if ($this->revision_int($this->first_prop((string) $root['body'], 'version-name')) !== $expected_revision) {
                throw new WporgSvnException(
                    'wporg_concurrent_external_change',
                    __('The plugin changed on wordpress.org while the commit was being prepared. Please try again.', 'peak-publisher'),
                    409
                );
            }

            $root_checked_in = $this->first_nested_href((string) $root['body'], 'checked-in');
            $root_vcc = $this->first_nested_href((string) $root['body'], 'version-controlled-configuration');
            if ($root_checked_in === '' || $root_vcc === '') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not return the commit resources for this plugin.', 'peak-publisher'),
                    502
                );
            }

            // Create an SVN activity for this commit. 401 and 403 are both credential
            // rejections — the same reading as the probes (write access: MERGE time).
            $activity = $this->create_activity($root_path, 'pblsh-commit');
            if (in_array($activity['options_status'], [ 401, 403 ], true)
                || in_array($activity['mkactivity_status'], [ 401, 403 ], true)) {
                throw new WporgSvnException(
                    'invalid_credentials',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher'),
                    401
                );
            }
            if ($activity['step'] === 'options') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not allow a commit activity.', 'peak-publisher'),
                    502
                );
            }
            if ($activity['step'] === 'collection') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not return an activity collection.', 'peak-publisher'),
                    502
                );
            }
            if ($activity['step'] !== 'ok') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN could not create a commit activity.', 'peak-publisher'),
                    502
                );
            }
            $this->activity_url = $activity['activity_url'];
            $this->activity_created = true;

            // Check out the working baseline and plugin root
            $baseline_checkout = $this->checkout($this->absolutize_url($root_vcc), $this->activity_url);
            $baseline_status = (int) ($baseline_checkout['status'] ?? 0);
            if (!$this->is_success_status($baseline_status)) {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN could not check out the working baseline.', 'peak-publisher'),
                    502
                );
            }
            $baseline_location = $this->response_header($baseline_checkout, 'location');
            if ($baseline_location === '') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not return a working baseline.', 'peak-publisher'),
                    502
                );
            }
            $this->working_baseline_url = $this->absolutize_url($baseline_location);

            $root_checkout = $this->checkout($this->absolutize_url($root_checked_in), $this->activity_url);
            $root_checkout_status = (int) ($root_checkout['status'] ?? 0);
            if (!$this->is_success_status($root_checkout_status)) {
                // No 403 special case: the credentials passed MKACTIVITY just before,
                // and wordpress.org enforces write access only at MERGE time.
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN could not check out the plugin working root.', 'peak-publisher'),
                    502
                );
            }
            $working_root = $this->response_header($root_checkout, 'location');
            if ($working_root === '') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not return a plugin working root.', 'peak-publisher'),
                    502
                );
            }
            $this->working_root_url = $this->absolutize_url($working_root);
            // Align DAV URLs before write operations
            $this->activity_dav_url = $this->align_url_origin($this->activity_url, $this->working_root_url);
            $this->working_baseline_url = $this->align_url_origin($this->working_baseline_url, $this->activity_dav_url);
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    public function add_file(string $path, string $local_path): void {
        $this->require_commit_context();
        $path = $this->safe_relative_path($path);
        if (!is_file($local_path) || !is_readable($local_path)) {
            throw new WporgSvnException(
                'local_file_not_readable',
                __('A file in the prepared upload could not be read.', 'peak-publisher'),
                500
            );
        }

        // Stream the local file into the working SVN resource
        $response = $this->stream_put_file($this->working_url($path, false), $local_path);
        $status = (int) ($response['status'] ?? 0);
        if (!$this->is_success_status($status)) {
            $code = $this->write_error_code_from_response($response);
            throw new WporgSvnException(
                $code,
                $this->write_failure_message($code, __('wordpress.org SVN file upload failed.', 'peak-publisher'), $response),
                $this->write_failure_status($code, $status)
            );
        }
    }

    public function del(string $path): void {
        $this->require_commit_context();
        $path = $this->safe_relative_path($path);

        $response = $this->request_url('DELETE', $this->working_url($path, false));
        $status = (int) ($response['status'] ?? 0);
        if ($this->is_success_status($status) || $status === 404) {
            return;
        }

        $code = $this->write_error_code_from_response($response);
        throw new WporgSvnException(
            $code,
            $this->write_failure_message($code, __('wordpress.org SVN delete failed.', 'peak-publisher'), $response),
            $this->write_failure_status($code, $status)
        );
    }

    /**
     * Copies a committed file into the commit — svn copy with history (the log shows
     * "from path:REV"). The source is pinned to $from_revision through the baseline collection
     * URL (!svn/bc/), so deletes in the same commit do not touch it; a swap of two files is one
     * commit without an intermediate name. The target must not exist: delete it first to replace
     * it. wordpress.org accepts this since it was tried there on 2026-10-01; !svn/ver/ sources
     * are refused with 405.
     */
    public function copy(string $from_path, int $from_revision, string $to_path): void {
        $this->require_commit_context();
        $from_path = $this->safe_relative_path($from_path);
        $to_path = $this->safe_relative_path($to_path);
        $source = $this->align_url_origin(
            $this->absolutize_url('/!svn/bc/' . $from_revision . '/' . $this->encode_svn_path($this->commit_slug . '/' . $from_path)),
            $this->working_root_url
        );

        $response = $this->request_url('COPY', $source, [
            'Destination' => $this->working_url($to_path, false),
            'Depth' => 'infinity',
            'Overwrite' => 'F',
        ]);
        $status = (int) ($response['status'] ?? 0);
        if ($this->is_success_status($status)) {
            return;
        }

        $code = $this->write_error_code_from_response($response);
        throw new WporgSvnException(
            $code,
            $this->write_failure_message($code, __('wordpress.org SVN copy failed.', 'peak-publisher'), $response),
            $this->write_failure_status($code, $status)
        );
    }

    /**
     * Sets svn:mime-type on a file of the commit — a PROPPATCH on its working resource, after
     * the PUT or COPY that wrote it. The property is what the plugin handbook asks committers
     * to set on images: the SVN origin serves the file under it, and the svn client treats
     * the file as binary.
     */
    public function set_mime_type(string $path, string $mime_type): void {
        $this->require_commit_context();
        $path = $this->safe_relative_path($path);
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:propertyupdate xmlns:D="DAV:" xmlns:S="http://subversion.tigris.org/xmlns/svn/">' .
            '<D:set><D:prop><S:mime-type>' . htmlspecialchars($mime_type, ENT_QUOTES | ENT_XML1) . '</S:mime-type></D:prop></D:set>' .
            '</D:propertyupdate>';
        $response = $this->request_url('PROPPATCH', $this->working_url($path, false), [ 'Content-Type' => 'text/xml; charset=utf-8' ], $body);
        $status = (int) ($response['status'] ?? 0);
        if ($this->is_success_status($status) && !$this->multistatus_has_failure((string) ($response['body'] ?? ''))) {
            return;
        }

        $code = $this->write_error_code_from_response($response);
        throw new WporgSvnException(
            $code,
            $this->write_failure_message($code, __('wordpress.org SVN property change failed.', 'peak-publisher'), $response),
            $this->write_failure_status($code, $status)
        );
    }

    public function mkdir(string $path): void {
        $this->require_commit_context();
        $path = $this->safe_relative_path($path);

        $parts = explode('/', trim($path, '/'));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . '/' . $part;
            $response = $this->request_url('MKCOL', $this->working_url($current, true));
            $status = (int) ($response['status'] ?? 0);
            if ($this->is_success_status($status) || $status === 405 || $status === 409) {
                continue;
            }

            $code = $this->write_error_code_from_response($response);
            throw new WporgSvnException(
                $code,
                $this->write_failure_message($code, __('wordpress.org SVN directory creation failed.', 'peak-publisher'), $response),
                $this->write_failure_status($code, $status)
            );
        }
    }

    public function commit(string $message): array {
        $this->require_commit_context();
        $message = trim($message);
        if ($message === '') {
            $message = 'Publish via Peak Publisher';
        }

        try {
            // Set the SVN commit message
            $proppatch = $this->proppatch_log($this->working_baseline_url, $message);
            $proppatch_status = (int) ($proppatch['status'] ?? 0);
            if (!$this->is_success_status($proppatch_status) || ($proppatch_status === 207 && $this->multistatus_has_failure((string) ($proppatch['body'] ?? '')))) {
                throw new WporgSvnException(
                    'svn_commit_log_failed',
                    __('wordpress.org SVN could not set the commit message.', 'peak-publisher'),
                    $proppatch_status > 0 ? $proppatch_status : 502
                );
            }

            // Merge the activity into the repository
            $merge = $this->merge_activity($this->activity_dav_url);
            $merge_status = (int) ($merge['status'] ?? 0);
            if (!$this->is_success_status($merge_status) && $merge_status !== 207) {
                $code = $this->write_error_code_from_response($merge);
                throw new WporgSvnException(
                    $code,
                    $this->write_failure_message($code, __('wordpress.org SVN commit failed.', 'peak-publisher'), $merge),
                    $this->write_failure_status($code, $merge_status)
                );
            }
            if ($merge_status === 207 && $this->multistatus_has_failure((string) ($merge['body'] ?? ''))) {
                $code = $this->response_indicates_size_rejected($merge_status, (string) ($merge['body'] ?? ''))
                    ? 'wporg_size_rejected'
                    : 'wporg_concurrent_external_change';
                throw new WporgSvnException(
                    $code,
                    $this->write_error_message($code, __('wordpress.org SVN commit failed.', 'peak-publisher')),
                    $code === 'wporg_size_rejected' ? 413 : 409
                );
            }

            $this->committed = true;
            // Extract and require the committed revision
            $revision = $this->extract_merge_revision($merge);
            if ($revision <= 0) {
                $this->reset_commit_state(true);
                throw new WporgSvnException(
                    'svn_commit_revision_missing',
                    __('wordpress.org SVN committed but did not return a revision.', 'peak-publisher'),
                    502
                );
            }

            $this->reset_commit_state(true);
            return [
                'revision' => $revision,
                'committed' => true,
            ];
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    public function abort(): void {
        if (!$this->activity_created || $this->committed || $this->activity_url === '') {
            return;
        }

        $this->discard_activity($this->activity_url);
        $this->reset_commit_state();
    }

    private function reset_commit_state(bool $after_commit = false): void {
        $this->commit_slug = '';
        $this->activity_url = '';
        $this->activity_dav_url = '';
        $this->working_baseline_url = '';
        $this->working_root_url = '';
        $this->activity_created = false;
        $this->committed = $after_commit ? true : false;
    }

    private function require_commit_context(): void {
        if (
            !$this->activity_created ||
            $this->activity_dav_url === '' ||
            $this->working_baseline_url === '' ||
            $this->working_root_url === '' ||
            $this->committed
        ) {
            throw new \RuntimeException('svn_commit_not_open');
        }
    }

    private function safe_relative_path(string $path): string {
        $path = trim($path);
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '\\') || str_contains($path, '..')) {
            throw new \RuntimeException('invalid_svn_path');
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('invalid_svn_path');
            }
        }

        return implode('/', $parts);
    }

    private function working_url(string $path, bool $collection): string {
        $url = rtrim($this->working_root_url, '/') . '/' . $this->encode_svn_path($path);
        return $collection ? trailingslashit($url) : $url;
    }

    private function encode_svn_path(string $path): string {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function response_header(array $response, string $name): string {
        $headers = $response['headers'] ?? [];
        $name = strtolower($name);

        if (is_object($headers) && method_exists($headers, 'get')) {
            $value = $headers->get($name);
            if (is_array($value)) {
                $value = reset($value);
            }
            return is_string($value) ? trim($value) : '';
        }

        if ($headers instanceof \ArrayAccess && isset($headers[$name])) {
            $value = $headers[$name];
            if (is_array($value)) {
                $value = reset($value);
            }
            return is_string($value) ? trim($value) : '';
        }

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) !== $name) {
                    continue;
                }
                if (is_array($value)) {
                    $value = reset($value);
                }
                return is_string($value) ? trim($value) : '';
            }
        }

        return '';
    }

    private function stream_put_file(string $url, string $local_path): array {
        // Stream local files to SVN without loading them into memory
        if (!function_exists('curl_init')) {
            throw new WporgSvnException(
                'svn_transport_unavailable',
                __('PHP cURL is required for streaming wordpress.org SVN uploads.', 'peak-publisher'),
                500
            );
        }

        $size = filesize($local_path);
        $size = is_int($size) ? $size : 0;
        $timeout = max(30, min(300, (int) ceil($size / 100000)));
        $fh = fopen($local_path, 'rb');
        if (!is_resource($fh)) {
            throw new WporgSvnException(
                'local_file_not_readable',
                __('A file in the prepared upload could not be read.', 'peak-publisher'),
                500
            );
        }

        $headers = [
            'User-Agent: ' . wporg_user_agent(),
            'Content-Type: application/octet-stream',
        ];
        if ($this->username !== null && $this->password !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new WporgSvnException(
                'svn_transport_unavailable',
                __('PHP cURL could not initialize a wordpress.org SVN upload.', 'peak-publisher'),
                500
            );
        }

        try {
            // Execute the PUT request and return the HTTP response
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fh,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $started = microtime(true);
            $body = curl_exec($ch);
            if ($body === false) {
                $this->log_request('PUT', $url, 0, $started, curl_error($ch));
                throw new WporgSvnException(
                    'svn_unavailable',
                    sprintf(__('wordpress.org SVN is not reachable: %s', 'peak-publisher'), curl_error($ch) ?: __('connection failed', 'peak-publisher')),
                    503
                );
            }

            $result = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => (string) $body,
                'headers' => [],
            ];
            $this->log_request('PUT', $url, $result['status'], $started, $result['status'] >= 400 ? $result['body'] : null);
            return $result;
        } finally {
            // The cURL handle is freed with $ch (curl_close() has no effect since PHP 8.0 and
            // is deprecated since 8.5); only the file handle needs closing.
            fclose($fh);
        }
    }

    private function proppatch_log(string $url, string $message): array {
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:propertyupdate xmlns:D="DAV:" xmlns:S="http://subversion.tigris.org/xmlns/svn/">' .
            '<D:set><D:prop><S:log>' . htmlspecialchars($message, ENT_QUOTES | ENT_XML1) . '</S:log></D:prop></D:set>' .
            '</D:propertyupdate>';

        return $this->request_url('PROPPATCH', $url, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
    }

    private function merge_activity(string $merge_url): array {
        $activity_href = $this->dav_href($this->activity_dav_url);
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:merge xmlns:D="DAV:">' .
            '<D:source><D:href>' . htmlspecialchars($activity_href, ENT_QUOTES | ENT_XML1) . '</D:href></D:source>' .
            '<D:no-auto-merge/><D:no-checkout/>' .
            '<D:prop><D:checked-in/><D:version-name/><D:resourcetype/><D:creationdate/><D:creator-displayname/></D:prop>' .
            '</D:merge>';

        return $this->request_url('MERGE', $merge_url, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
    }

    private function multistatus_has_failure(string $body): bool {
        if (!preg_match_all('~<[^>]*:?status\b[^>]*>\s*HTTP/\S+\s+(\d{3})~i', $body, $matches)) {
            return false;
        }

        foreach ($matches[1] as $status) {
            if ((int) $status >= 400) {
                return true;
            }
        }

        return false;
    }

    private function extract_merge_revision(array $response): int {
        foreach (['svn-revision', 'x-svn-revision', 'revision'] as $header) {
            $value = $this->response_header($response, $header);
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        $body = (string) ($response['body'] ?? '');
        if (preg_match_all('~<[^>]*:?version-name[^>]*>\s*(\d+)\s*</[^>]*:?version-name>~i', $body, $matches)) {
            return max(array_map('intval', $matches[1]));
        }
        return 0;
    }

    // Message counterpart of write_error_code_from_response(): user-facing text for codes
    // that deserve more guidance than the operation's generic failure message.
    private function write_error_message(string $code, string $fallback): string {
        if ($code === 'wporg_size_rejected') {
            return __('wordpress.org rejected the commit, likely because the plugin is too large. Try splitting binary assets, or contact wp.org plugin reviewers if you need a higher limit.', 'peak-publisher');
        }
        if ($code === 'no_write_access') {
            // wordpress.org takes the password regardless of the username's case, but its
            // pre-commit hook checks the commit rights against the exact spelling.
            return sprintf(__('The wordpress.org account "%s" has no write access to this plugin. Check the account\'s spelling, including capitalization.', 'peak-publisher'), (string) $this->username);
        }
        if ($code === 'wporg_concurrent_external_change') {
            return __('wordpress.org SVN reported a commit conflict.', 'peak-publisher');
        }
        return $fallback;
    }

    private function write_error_code_from_response(array $response): string {
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($this->response_indicates_size_rejected($status, $body)) {
            return 'wporg_size_rejected';
        }
        // wordpress.org enforces per-plugin commit rights in its pre-commit hook (since 2026-08
        // no longer at the WebDAV layer): the MERGE fails with 500 and the hook's text
        // "Access denied: user 'x' cannot modify: /slug/...".
        if ($status >= 500 && stripos($body, 'access denied') !== false && stripos($body, 'cannot modify') !== false) {
            return 'no_write_access';
        }

        return $this->write_error_code_from_status($status);
    }

    /** The HTTP status a failed write answers with: a denied commit is a 403 whatever wordpress.org's hook answered. */
    private function write_failure_status(string $code, int $status): int {
        if ($code === 'no_write_access') {
            return 403;
        }
        return $status > 0 ? $status : 502;
    }

    private function response_indicates_size_rejected(int $status, string $body): bool {
        if ($status === 413) {
            return true;
        }
        if ($status !== 207 && $status < 500) {
            return false;
        }

        foreach (['too large', 'payload too large', 'request entity too large', 'entity too large', 'size limit'] as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function write_error_code_from_status(int $status): string {
        if ($status === 401 || $status === 403) {
            return 'no_write_access';
        }
        if ($status === 404) {
            return 'not_found';
        }
        if ($status === 409 || $status === 412) {
            return 'wporg_concurrent_external_change';
        }
        if ($status === 413) {
            return 'wporg_size_rejected';
        }
        if ($status >= 500) {
            return 'svn_unavailable';
        }
        return 'svn_write_failed';
    }

    private function propfind(string $path, int $depth): array {
        return $this->request('PROPFIND', $path, [
            'Depth' => (string) $depth,
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $this->propfind_body());
    }

    // The one authoritative PROPFIND property list — the single (propfind) and batch
    // (list_directories_multi) transports must request identical fields; drift here would
    // fail silently with different data per transport.
    private function propfind_body(): string {
        return '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:propfind xmlns:D="DAV:"><D:prop>' .
            '<D:resourcetype/><D:getlastmodified/><D:getcontentlength/>' .
            '<D:version-name/><D:checked-in/><D:version-controlled-configuration/>' .
            '</D:prop></D:propfind>';
    }

    /**
     * Validates a directory-read (PROPFIND) response and extracts its entries — shared by
     * list_directory() and list_directories_multi(), so status handling and error texts
     * cannot drift between the single and batch transports.
     */
    private function directory_entries_from_response(array $response): array {
        $status = (int) ($response['status'] ?? 0);
        if ($status === 404) {
            throw new WporgSvnException(
                'not_found',
                __('wordpress.org SVN path was not found.', 'peak-publisher'),
                404
            );
        }
        if (!$this->is_success_status($status) && $status !== 207) {
            throw new WporgSvnException(
                'svn_read_failed',
                __('wordpress.org SVN returned an unexpected read response.', 'peak-publisher'),
                502
            );
        }
        return $this->entries_from_multistatus_body((string) ($response['body'] ?? ''));
    }

    /**
     * Validates a file-read (GET) response and extracts its body — shared by read_file()
     * and read_files_multi(), same anti-drift rationale as directory_entries_from_response().
     */
    private function file_body_from_response(array $response): string {
        $status = (int) ($response['status'] ?? 0);
        if ($status === 404) {
            throw new WporgSvnException(
                'not_found',
                __('wordpress.org SVN file was not found.', 'peak-publisher'),
                404
            );
        }
        if (!$this->is_success_status($status)) {
            throw new WporgSvnException(
                'svn_read_failed',
                __('wordpress.org SVN returned an unexpected file response.', 'peak-publisher'),
                502
            );
        }
        return (string) ($response['body'] ?? '');
    }

    /**
     * The path's log as items — a log REPORT from the node's last-changed revision downward
     * (newest first) or from revision 0 upward, $limit items, with the changed paths when
     * asked: revision, SVN's date string, author, message, and the paths with their action
     * A|M|D|R. The one place that builds and reads a log report. Empty when the node does not
     * exist.
     *
     * @return array<int, array{revision:int, date:string, author:string, message:string, paths:array<string, string>}>
     * @throws WporgSvnException svn_read_failed for an unexpected log response; transport errors as they are
     */
    private function log_items(string $path, int $limit, bool $newest_first, bool $with_paths): array {
        $path = trim($path, '/');
        if ($path === '' || $limit < 1) {
            throw new \RuntimeException('invalid_svn_log_request');
        }
        $root_path = $path . '/';
        // The log report needs numeric revisions — the node's last-changed revision stands in for HEAD.
        $head = $this->first_prop((string) ($this->propfind($root_path, 0)['body'] ?? ''), 'version-name');
        if ($head === '' || !ctype_digit($head)) {
            return [];
        }

        [ $start, $end ] = $newest_first ? [ $head, '0' ] : [ '0', $head ];
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<S:log-report xmlns:S="svn:">' .
            '<S:start-revision>' . $start . '</S:start-revision>' .
            '<S:end-revision>' . $end . '</S:end-revision>' .
            '<S:limit>' . $limit . '</S:limit>' .
            ($with_paths ? '<S:discover-changed-paths/>' : '') .
            '<S:path></S:path>' .
            '</S:log-report>';
        $report = $this->request('REPORT', $root_path, [ 'Content-Type' => 'text/xml; charset=utf-8' ], $body);
        if (!$this->is_success_status((int) ($report['status'] ?? 0))) {
            throw new WporgSvnException('svn_read_failed', __('wordpress.org SVN returned an unexpected log response.', 'peak-publisher'), 502);
        }

        $items = [];
        preg_match_all('~<[^>]*:?log-item>(.*?)</[^>]*:?log-item>~s', (string) ($report['body'] ?? ''), $matches);
        foreach ($matches[1] as $item) {
            $paths = [];
            preg_match_all('~<(?:[A-Za-z]+:)?(added|modified|deleted|replaced)-path[^>]*>([^<]*)</(?:[A-Za-z]+:)?\1-path>~', $item, $changes, PREG_SET_ORDER);
            foreach ($changes as $change) {
                $paths[html_entity_decode($change[2], ENT_QUOTES | ENT_XML1)] = [ 'added' => 'A', 'modified' => 'M', 'deleted' => 'D', 'replaced' => 'R' ][$change[1]];
            }
            $items[] = [
                'revision' => (int) $this->first_prop($item, 'version-name'),
                'date' => $this->first_prop($item, 'date'),
                'author' => $this->first_prop($item, 'creator-displayname'),
                'message' => $this->first_prop($item, 'comment'),
                'paths' => $paths,
            ];
        }
        return $items;
    }

    /**
     * The path's oldest log entry (the creation commit), or null when it cannot be read.
     * wordpress.org creates every plugin repository with the fixed message "Adding {title} by
     * {user_login}." — their own SVN watcher parses this format, which makes it a reliable
     * ownership hint for freshly approved plugins.
     *
     * @return array{revision:int, date:string, message:string}|null date is SVN's ISO 8601 string
     */
    public function get_initial_log_entry(string $path): ?array {
        try {
            $first = $this->log_items($path, 1, false, false)[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
        if ($first === null || $first['message'] === '') {
            return null;
        }
        return [ 'revision' => $first['revision'], 'date' => $first['date'], 'message' => $first['message'] ];
    }

    /**
     * The path's last $limit log entries with their changed paths — what wordpress.org's SVN
     * watcher reads to schedule imports. Paths are repository-absolute
     * (/slug/trunk/readme.txt), the action A|M|D|R. Newest first; empty when the node does
     * not exist.
     *
     * @return array<int, array{revision:int, time:int, paths:array<string, string>}>
     * @throws WporgSvnException svn_read_failed for an unexpected log response; transport errors as they are
     */
    public function get_log_entries(string $path, int $limit): array {
        return array_map(static fn(array $item): array => [
            'revision' => $item['revision'],
            'time' => (int) strtotime($item['date']),
            'paths' => $item['paths'],
        ], $this->log_items($path, $limit, true, true));
    }

    /**
     * Returns the distinct committer names of the path's most recent log entries
     * (newest first), or null when the log cannot be read. The commit history is
     * the only public evidence of actual commit access — it covers deploys made
     * through Peak Publisher and external SVN commits alike.
     *
     * @return string[]|null
     */
    public function get_recent_log_authors(string $path, int $limit = 200): ?array {
        try {
            $items = $this->log_items($path, $limit, true, false);
        } catch (\Throwable $e) {
            return null;
        }
        $authors = [];
        foreach ($items as $item) {
            if ($item['author'] !== '') {
                $authors[$item['author']] = true;
            }
        }
        return $authors === [] ? null : array_keys($authors);
    }

    private function options_activity_collection(string $path): array {
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:options xmlns:D="DAV:"><D:activity-collection-set/></D:options>';

        return $this->request('OPTIONS', $path, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
    }

    /**
     * The shared OPTIONS → MKACTIVITY sequence behind every write-class
     * credentials probe and commit start: fetch the activity collection, derive
     * a unique activity URL, create the activity. Stops at the first failed
     * step and reports the raw statuses — interpreting them (probe verdict vs.
     * commit error) stays with each caller's contract.
     *
     * @return array{step:'options'|'collection'|'mkactivity'|'ok', options_status:int, mkactivity_status:int|null, activity_url:string|null}
     */
    private function create_activity(string $path, string $name_prefix): array {
        $options = $this->options_activity_collection($path);
        $result = [
            'step' => 'options',
            'options_status' => (int) ($options['status'] ?? 0),
            'mkactivity_status' => null,
            'activity_url' => null,
        ];
        if (!$this->is_success_status($result['options_status'])) {
            return $result;
        }

        $activity_collection = $this->first_nested_href((string) ($options['body'] ?? ''), 'activity-collection-set');
        if ($activity_collection === '') {
            $result['step'] = 'collection';
            return $result;
        }

        $result['activity_url'] = rtrim($this->absolutize_url($activity_collection), '/') .
            '/' . $name_prefix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $mkactivity = $this->request_url('MKACTIVITY', $result['activity_url']);
        $result['mkactivity_status'] = (int) ($mkactivity['status'] ?? 0);
        $result['step'] = $this->is_success_status($result['mkactivity_status']) ? 'ok' : 'mkactivity';
        return $result;
    }

    // Best-effort cleanup; a leftover empty activity has no repository effect.
    private function discard_activity(string $activity_url): void {
        try {
            $this->request_url('DELETE', $activity_url);
        } catch (\Throwable $e) {}
    }

    private function checkout(string $url, string $activity_url): array {
        $activity_href = $this->dav_href($activity_url);
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:checkout xmlns:D="DAV:"><D:activity-set><D:href>' .
            htmlspecialchars($activity_href, ENT_QUOTES | ENT_XML1) .
            '</D:href></D:activity-set><D:apply-to-version/>' .
            '</D:checkout>';

        return $this->request_url('CHECKOUT', $url, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
    }

    private function repo_access_result(string $status, ?string $message): array {
        return [
            'status' => $status,
            'message' => $message,
        ];
    }

    private function request(string $method, string $path = '', array $headers = [], ?string $body = null): array {
        return $this->request_url($method, $this->build_url($path), $headers, $body);
    }

    /**
     * One request through the WordPress HTTP API with the client's User-Agent and credentials,
     * logged. $options are further HTTP API arguments (a longer timeout, streaming to a file).
     */
    private function request_url(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array {
        $request_headers = array_merge([
            'User-Agent' => wporg_user_agent(),
        ], $headers);

        if ($this->username !== null && $this->password !== null) {
            $request_headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $args = array_merge([
            'method' => strtoupper($method),
            'headers' => $request_headers,
            'timeout' => 20,
            'redirection' => 0,
        ], $options);
        if ($body !== null) {
            $args['body'] = $body;
        }

        $started = microtime(true);
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->log_request($args['method'], $url, 0, $started, $response->get_error_message());
            throw new WporgSvnException(
                'svn_unavailable',
                sprintf(__('wordpress.org SVN is not reachable: %s', 'peak-publisher'), $response->get_error_message()),
                503
            );
        }

        $result = [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'headers' => wp_remote_retrieve_headers($response),
        ];
        $this->log_request($args['method'], $url, $result['status'], $started, $result['status'] >= 400 ? $result['body'] : null);
        return $result;
    }

    /**
     * One debug.log line per SVN request while WP_DEBUG is on: method, path, status and
     * duration — where the seconds of a commit go, and what wordpress.org answered when a
     * request failed (the SVN error text, else the start of the body).
     */
    private function log_request(string $method, string $url, int $status, float $started, ?string $detail): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $excerpt = $detail === null || $detail === '' ? '' : ' ' . $this->response_excerpt($detail);
        error_log(sprintf(
            'Peak Publisher SVN: %s %s -> %s in %d ms%s',
            strtoupper($method),
            (string) parse_url($url, PHP_URL_PATH),
            $status > 0 ? (string) $status : 'no response',
            (int) round((microtime(true) - $started) * 1000),
            $excerpt
        ));
    }

    /**
     * What a failed response says, fit for a log line or a message: the human-readable part
     * of a mod_dav_svn error (a hook's output, a conflict), else the body without markup —
     * at most 200 characters.
     */
    private function response_excerpt(string $body): string {
        $text = preg_match('~<[^>]*:?human-readable[^>]*>(.*?)</[^>]*:?human-readable>~is', $body, $m) ? $m[1] : (string) preg_replace('/<[^>]+>/', ' ', $body);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_XML1)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200);
    }

    /**
     * The message of a failed write: the code's own text where one exists, else the
     * operation's text with the status and what wordpress.org answered — a plain
     * "commit failed" hides the hook output or gateway error that explains it.
     */
    private function write_failure_message(string $code, string $fallback, array $response): string {
        if (in_array($code, [ 'wporg_size_rejected', 'no_write_access', 'wporg_concurrent_external_change' ], true)) {
            return $this->write_error_message($code, $fallback);
        }
        $status = (int) ($response['status'] ?? 0);
        $excerpt = $this->response_excerpt((string) ($response['body'] ?? ''));
        return $fallback . ' ' . ($excerpt === ''
            ? sprintf(__('(HTTP %d)', 'peak-publisher'), $status)
            : sprintf(__('(HTTP %1$d: %2$s)', 'peak-publisher'), $status, $excerpt));
    }

    private function request_paths_multi(string $method, array $paths, array $headers = [], ?string $body = null, int $concurrency = 5): array {
        if (!self::is_batch_transport_available()) {
            throw new WporgSvnException(
                'wporg_import_transport_unavailable',
                __('wordpress.org import needs curl_multi_exec support.', 'peak-publisher'),
                500
            );
        }

        $paths = array_values(array_unique(array_filter(array_map('strval', $paths), static fn($path) => $path !== '')));
        if (empty($paths)) {
            return [];
        }

        $method = strtoupper($method);
        $concurrency = max(1, min(10, $concurrency));
        $queue = $paths;
        $responses = [];
        $multi = curl_multi_init();
        if ($multi === false) {
            throw new WporgSvnException(
                'wporg_import_transport_unavailable',
                __('wordpress.org import could not initialize curl_multi_exec support.', 'peak-publisher'),
                500
            );
        }
        $handles = [];
        $error = null;

        $add_handle = function(string $path) use ($multi, $method, $headers, $body, &$handles): void {
            $request_headers = array_merge([
                'User-Agent' => wporg_user_agent(),
            ], $headers);

            if ($this->username !== null && $this->password !== null) {
                $request_headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
            }

            $header_lines = [];
            foreach ($request_headers as $name => $value) {
                $header_lines[] = $name . ': ' . $value;
            }

            $ch = curl_init($this->build_url($path));
            if ($ch === false) {
                throw new WporgSvnException(
                    'wporg_import_transport_unavailable',
                    __('wordpress.org import could not initialize a cURL request.', 'peak-publisher'),
                    500
                );
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $header_lines,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            curl_multi_add_handle($multi, $ch);
            $key = is_object($ch) ? spl_object_id($ch) : (int) $ch;
            $handles[$key] = [
                'handle' => $ch,
                'path' => $path,
            ];
        };

        while (!empty($queue) && count($handles) < $concurrency) {
            $add_handle(array_shift($queue));
        }

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($status !== CURLM_OK) {
                $error = __('wordpress.org SVN batch request failed.', 'peak-publisher');
                break;
            }

            while ($info = curl_multi_info_read($multi)) {
                $ch = $info['handle'];
                $key = is_object($ch) ? spl_object_id($ch) : (int) $ch;
                $path = (string) ($handles[$key]['path'] ?? '');

                if (($info['result'] ?? CURLE_OK) !== CURLE_OK) {
                    $error = curl_error($ch) ?: __('wordpress.org SVN is not reachable.', 'peak-publisher');
                } else {
                    $responses[$path] = [
                        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                        'body' => (string) curl_multi_getcontent($ch),
                        'headers' => [],
                    ];
                }

                curl_multi_remove_handle($multi, $ch);
                unset($handles[$key]);

                if ($error === null && !empty($queue)) {
                    $add_handle(array_shift($queue));
                }
            }

            if ($running > 0 && $error === null) {
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while (($running > 0 || !empty($handles)) && $error === null);

        foreach ($handles as $entry) {
            $ch = $entry['handle'];
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        if ($error !== null) {
            throw new WporgSvnException(
                'svn_unavailable',
                $error,
                503
            );
        }

        return $responses;
    }

    private function entries_from_multistatus_body(string $body): array {
        $entries = [];
        foreach ($this->parse_multistatus($body) as $entry) {
            $href = (string) ($entry['href'] ?? '');
            $relative_path = $this->href_to_repo_path($href);
            if ($relative_path === '') {
                continue;
            }

            $props = is_array($entry['props'] ?? null) ? $entry['props'] : [];
            $is_collection = (($props['resourcetype'] ?? '') === 'collection') || str_ends_with((string) parse_url($href, PHP_URL_PATH), '/');
            $entries[] = [
                'path' => $relative_path,
                'name' => basename(rtrim($relative_path, '/')),
                'type' => $is_collection ? 'dir' : 'file',
                'size' => isset($props['getcontentlength']) && ctype_digit((string) $props['getcontentlength']) ? (int) $props['getcontentlength'] : null,
                'last_modified' => (string) ($props['getlastmodified'] ?? ''),
                'revision' => $this->revision_int((string) ($props['version-name'] ?? '')),
            ];
        }

        return $entries;
    }

    private function build_url(string $path): string {
        $has_trailing_slash = str_ends_with($path, '/');
        $path = trim($path, '/');
        if ($path === '') {
            return self::REPO_URL;
        }

        $segments = array_map('rawurlencode', explode('/', $path));
        $url = self::REPO_URL . implode('/', $segments);
        return $has_trailing_slash ? trailingslashit($url) : $url;
    }

    private function absolutize_url(string $href): string {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        $parts = parse_url(self::REPO_URL);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? 'plugins.svn.wordpress.org');
        return $scheme . '://' . $host . '/' . ltrim($href, '/');
    }

    private function align_url_origin(string $url, string $origin_url): string {
        $url_parts = parse_url($url);
        $origin_parts = parse_url($origin_url);
        if (!is_array($url_parts) || !is_array($origin_parts) || empty($url_parts['path']) || empty($origin_parts['host'])) {
            return $url;
        }

        $scheme = (string) ($origin_parts['scheme'] ?? $url_parts['scheme'] ?? 'https');
        $host = (string) $origin_parts['host'];
        $port = isset($origin_parts['port']) ? ':' . $origin_parts['port'] : '';
        $query = isset($url_parts['query']) ? '?' . $url_parts['query'] : '';
        return $scheme . '://' . $host . $port . $url_parts['path'] . $query;
    }

    private function dav_href(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        return $path;
    }

    private function first_prop(string $xml, string $property): string {
        $quoted = preg_quote($property, '~');
        if (preg_match('~<[^>]*:?' . $quoted . '[^>]*>(.*?)</[^>]*:?' . $quoted . '>~s', $xml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1));
        }
        return '';
    }

    private function first_nested_href(string $xml, string $property): string {
        $quoted = preg_quote($property, '~');
        if (preg_match('~<[^>]*:?' . $quoted . '[^>]*>.*?<[^>]*:?href[^>]*>(.*?)</[^>]*:?href>~s', $xml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1));
        }
        return '';
    }

    private function parse_multistatus(string $xml): array {
        if (trim($xml) === '') {
            return [];
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            throw new WporgSvnException(
                'svn_read_failed',
                __('wordpress.org SVN returned an invalid XML response.', 'peak-publisher'),
                502
            );
        }

        $sx->registerXPathNamespace('D', 'DAV:');
        $responses = $sx->xpath('//D:response') ?: [];
        if (empty($responses)) {
            throw new WporgSvnException(
                'svn_read_failed',
                __('wordpress.org SVN returned an XML response without WebDAV entries.', 'peak-publisher'),
                502
            );
        }

        $out = [];
        foreach ($responses as $response) {
            $response->registerXPathNamespace('D', 'DAV:');
            $href_nodes = $response->xpath('D:href') ?: [];
            $href = isset($href_nodes[0]) ? (string) $href_nodes[0] : '';
            $props = [];

            foreach (($response->xpath('D:propstat/D:prop') ?: []) as $prop_node) {
                $prop_node->registerXPathNamespace('D', 'DAV:');
                foreach ($prop_node->children('DAV:') as $prop) {
                    $prop->registerXPathNamespace('D', 'DAV:');
                    $name = $prop->getName();
                    if ($name === 'resourcetype') {
                        $props[$name] = !empty($prop->xpath('D:collection')) ? 'collection' : '';
                        continue;
                    }

                    $href_prop_nodes = $prop->xpath('D:href') ?: [];
                    if (!empty($href_prop_nodes)) {
                        $props[$name] = (string) $href_prop_nodes[0];
                        continue;
                    }

                    $props[$name] = trim((string) $prop);
                }
            }

            $out[] = [
                'href' => $href,
                'props' => $props,
            ];
        }

        return $out;
    }

    private function href_to_repo_path(string $href): string {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;
        $path = rawurldecode($path);
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        return trim($path, '/');
    }

    private function revision_int(string $revision): int {
        $revision = trim($revision);
        return ctype_digit($revision) ? (int) $revision : 0;
    }

    private function is_success_status(int $status): bool {
        return $status >= 200 && $status < 300;
    }
}
