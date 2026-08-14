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

    public function test_credentials(): array {
        $response = $this->request('PROPFIND', '', [
            'Depth' => '0',
        ]);
        $status = (int) ($response['status'] ?? 0);

        if ($status === 207 || ($status >= 200 && $status < 300)) {
            return [
                'status' => 'ok',
            ];
        }

        if ($status === 401 || $status === 403) {
            throw new WporgSvnException(
                'invalid_credentials',
                __('Invalid wordpress.org username or password.', 'peak-publisher'),
                401
            );
        }

        throw new WporgSvnException(
            'svn_auth_check_failed',
            __('wordpress.org SVN returned an unexpected authentication response.', 'peak-publisher'),
            502
        );
    }

    public function list_directory(string $path = '', int $depth = 1): array {
        $depth = max(0, min(1, $depth));
        $response = $this->propfind($path, $depth);
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

    public function read_file(string $path): string {
        $response = $this->request('GET', $path);
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

    public static function is_batch_transport_available(): bool {
        return function_exists('curl_multi_exec') && function_exists('curl_init');
    }

    public function list_directories_multi(array $paths, int $depth = 1, int $concurrency = 5): array {
        $depth = max(0, min(1, $depth));
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:propfind xmlns:D="DAV:"><D:prop>' .
            '<D:resourcetype/><D:getlastmodified/><D:getcontentlength/>' .
            '<D:version-name/><D:checked-in/><D:version-controlled-configuration/>' .
            '</D:prop></D:propfind>';

        $responses = $this->request_paths_multi('PROPFIND', $paths, [
            'Depth' => (string) $depth,
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body, $concurrency);

        $out = [];
        foreach ($responses as $path => $response) {
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
            $out[$path] = $this->entries_from_multistatus_body((string) ($response['body'] ?? ''));
        }

        return $out;
    }

    public function read_files_multi(array $paths, int $concurrency = 10): array {
        $responses = $this->request_paths_multi('GET', $paths, [], null, $concurrency);
        $out = [];

        foreach ($responses as $path => $response) {
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
            $out[$path] = (string) ($response['body'] ?? '');
        }

        return $out;
    }

    public function can_write(string $path): array {
        $path = trim($path, '/');
        if ($path === '' || $this->username === null || $this->password === null) {
            return $this->can_write_result(
                'error',
                false,
                __('Stored wordpress.org credentials are required for this check.', 'peak-publisher')
            );
        }

        $root_path = $path . '/';
        $activity_created = false;
        $activity_url = '';
        $cleanup_status = 0;

        try {
            // Read the current revision before probing write access
            $before = $this->propfind($root_path, 0);
            $before_status = (int) ($before['status'] ?? 0);
            if ($before_status === 404) {
                return $this->can_write_result(
                    'not_found',
                    false,
                    __('Plugin not found on wordpress.org SVN.', 'peak-publisher')
                );
            }
            if ($before_status === 401) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher')
                );
            }
            if ($before_status === 403) {
                return $this->can_write_result(
                    'no_write_access',
                    false,
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher')
                );
            }
            if (!$this->is_success_status($before_status) && $before_status !== 207) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN returned an unexpected plugin lookup response.', 'peak-publisher')
                );
            }

            $before_revision = $this->first_prop((string) $before['body'], 'version-name');
            $checked_in_href = $this->first_nested_href((string) $before['body'], 'checked-in');
            if ($checked_in_href === '') {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN did not return a checked-in resource for this plugin.', 'peak-publisher')
                );
            }

            $options = $this->options_activity_collection($root_path);
            $options_status = (int) ($options['status'] ?? 0);
            if ($options_status === 401) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher')
                );
            }
            if ($options_status === 403) {
                return $this->can_write_result(
                    'no_write_access',
                    false,
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher')
                );
            }
            if (!$this->is_success_status($options_status)) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN did not allow a write-access probe activity.', 'peak-publisher')
                );
            }

            // Create a temporary SVN activity for the access probe
            $activity_collection = $this->first_nested_href((string) $options['body'], 'activity-collection-set');
            if ($activity_collection === '') {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN did not return an activity collection.', 'peak-publisher')
                );
            }

            $activity_url = rtrim($this->absolutize_url($activity_collection), '/') .
                '/pblsh-probe-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            $mkactivity = $this->request_url('MKACTIVITY', $activity_url);
            $mkactivity_status = (int) ($mkactivity['status'] ?? 0);
            if ($mkactivity_status === 401) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher')
                );
            }
            if ($mkactivity_status === 403) {
                return $this->can_write_result(
                    'no_write_access',
                    false,
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher')
                );
            }
            if (!$this->is_success_status($mkactivity_status)) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN could not create a temporary write-access probe activity.', 'peak-publisher')
                );
            }
            $activity_created = true;

            // Check out the root without writing a new revision
            $checkout_url = $this->absolutize_url($checked_in_href);
            $checkout = $this->checkout($checkout_url, $activity_url);
            $checkout_status = (int) ($checkout['status'] ?? 0);

            $probe_status = 'error';
            $has_write_access = false;
            $message = __('wordpress.org SVN returned an unexpected write-access response.', 'peak-publisher');
            if ($this->is_success_status($checkout_status)) {
                $probe_status = 'ok';
                $has_write_access = true;
                $message = null;
            } elseif ($checkout_status === 403) {
                $probe_status = 'no_write_access';
                $message = __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher');
            } elseif ($checkout_status === 401) {
                $message = __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher');
            }
        } catch (WporgSvnException $e) {
            return $this->can_write_result('error', false, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->can_write_result(
                'error',
                false,
                __('wordpress.org SVN write-access check failed.', 'peak-publisher')
            );
        } finally {
            if ($activity_created && $activity_url !== '') {
                // Remove the temporary probe activity
                try {
                    $cleanup = $this->request_url('DELETE', $activity_url);
                    $cleanup_status = (int) ($cleanup['status'] ?? 0);
                } catch (\Throwable $e) {
                    $cleanup_status = 0;
                }
            }
        }

        if ($activity_created && !$this->is_success_status($cleanup_status)) {
            return $this->can_write_result(
                'error',
                false,
                __('wordpress.org SVN temporary write-access probe activity could not be cleaned up.', 'peak-publisher')
            );
        }

        try {
            // Ensure the probe did not create a revision
            $after = $this->propfind($root_path, 0);
            $after_revision = $this->first_prop((string) $after['body'], 'version-name');
            if ($before_revision !== '' && $after_revision !== '' && $before_revision !== $after_revision) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN revision changed during the write-access probe.', 'peak-publisher')
                );
            }
        } catch (\Throwable $e) {
            if ($has_write_access) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN write-access probe could not verify that no revision was created.', 'peak-publisher')
                );
            }
        }

        return $this->can_write_result($probe_status, $has_write_access, $message);
    }

    public function begin_commit(string $wporg_slug): void {
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
            if ($root_status === 401) {
                throw new WporgSvnException(
                    'invalid_credentials',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher'),
                    401
                );
            }
            if ($root_status === 403) {
                throw new WporgSvnException(
                    'no_write_access',
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher'),
                    403
                );
            }
            if (!$this->is_success_status($root_status) && $root_status !== 207) {
                throw new WporgSvnException(
                    'svn_read_failed',
                    __('wordpress.org SVN returned an unexpected plugin lookup response.', 'peak-publisher'),
                    502
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

            $options = $this->options_activity_collection($root_path);
            $options_status = (int) ($options['status'] ?? 0);
            if ($options_status === 401) {
                throw new WporgSvnException(
                    'invalid_credentials',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher'),
                    401
                );
            }
            if ($options_status === 403) {
                throw new WporgSvnException(
                    'no_write_access',
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher'),
                    403
                );
            }
            if (!$this->is_success_status($options_status)) {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not allow a commit activity.', 'peak-publisher'),
                    502
                );
            }

            $activity_collection = $this->first_nested_href((string) $options['body'], 'activity-collection-set');
            if ($activity_collection === '') {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN did not return an activity collection.', 'peak-publisher'),
                    502
                );
            }

            $this->activity_url = rtrim($this->absolutize_url($activity_collection), '/') .
                '/pblsh-commit-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

            // Create an SVN activity for this commit
            $mkactivity = $this->request_url('MKACTIVITY', $this->activity_url);
            $mkactivity_status = (int) ($mkactivity['status'] ?? 0);
            if ($mkactivity_status === 401) {
                throw new WporgSvnException(
                    'invalid_credentials',
                    __('The saved wordpress.org credentials were rejected by SVN.', 'peak-publisher'),
                    401
                );
            }
            if ($mkactivity_status === 403) {
                throw new WporgSvnException(
                    'no_write_access',
                    __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher'),
                    403
                );
            }
            if (!$this->is_success_status($mkactivity_status)) {
                throw new WporgSvnException(
                    'svn_commit_setup_failed',
                    __('wordpress.org SVN could not create a commit activity.', 'peak-publisher'),
                    502
                );
            }
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
                throw new WporgSvnException(
                    $root_checkout_status === 403 ? 'no_write_access' : 'svn_commit_setup_failed',
                    $root_checkout_status === 403
                        ? __('The saved wordpress.org account does not have SVN write access for this plugin.', 'peak-publisher')
                        : __('wordpress.org SVN could not check out the plugin working root.', 'peak-publisher'),
                    $root_checkout_status === 403 ? 403 : 502
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
                $this->write_error_message($code, __('wordpress.org SVN file upload failed.', 'peak-publisher')),
                $status > 0 ? $status : 502
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
            $this->write_error_message($code, __('wordpress.org SVN delete failed.', 'peak-publisher')),
            $status > 0 ? $status : 502
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
                $this->write_error_message($code, __('wordpress.org SVN directory creation failed.', 'peak-publisher')),
                $status > 0 ? $status : 502
            );
        }
    }

    public function commit(string $message): array {
        $this->require_commit_context();
        $message = trim($message);
        if ($message === '') {
            $message = 'Deploy via Peak Publisher';
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
                    $this->write_error_message($code, __('wordpress.org SVN commit failed.', 'peak-publisher')),
                    $merge_status > 0 ? $merge_status : 502
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

        try {
            $this->request_url('DELETE', $this->activity_url);
        } catch (\Throwable $e) {
        }

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
            'User-Agent: Peak Publisher SVN Client',
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

            $body = curl_exec($ch);
            if ($body === false) {
                throw new WporgSvnException(
                    'svn_unavailable',
                    curl_error($ch) ?: __('wordpress.org SVN is not reachable.', 'peak-publisher'),
                    503
                );
            }

            return [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => (string) $body,
                'headers' => [],
            ];
        } finally {
            curl_close($ch);
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
            return __('The wordpress.org account has no write access to this plugin.', 'peak-publisher');
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

        return $this->write_error_code_from_status($status);
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
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:propfind xmlns:D="DAV:"><D:prop>' .
            '<D:resourcetype/><D:getlastmodified/><D:getcontentlength/>' .
            '<D:version-name/><D:checked-in/><D:version-controlled-configuration/>' .
            '</D:prop></D:propfind>';

        return $this->request('PROPFIND', $path, [
            'Depth' => (string) $depth,
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
    }

    private function options_activity_collection(string $path): array {
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:options xmlns:D="DAV:"><D:activity-collection-set/></D:options>';

        return $this->request('OPTIONS', $path, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
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

    private function can_write_result(string $status, bool $has_write_access, ?string $message): array {
        return [
            'status' => $status,
            'has_write_access' => $has_write_access,
            'message' => $message,
        ];
    }

    private function request(string $method, string $path = '', array $headers = [], ?string $body = null): array {
        return $this->request_url($method, $this->build_url($path), $headers, $body);
    }

    private function request_url(string $method, string $url, array $headers = [], ?string $body = null): array {
        $request_headers = array_merge([
            'User-Agent' => 'Peak Publisher SVN Client',
        ], $headers);

        if ($this->username !== null && $this->password !== null) {
            $request_headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $args = [
            'method' => strtoupper($method),
            'headers' => $request_headers,
            'timeout' => 20,
            'redirection' => 0,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new WporgSvnException(
                'svn_unavailable',
                __('wordpress.org SVN is not reachable.', 'peak-publisher'),
                503
            );
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'headers' => wp_remote_retrieve_headers($response),
        ];
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
                'User-Agent' => 'Peak Publisher SVN Client',
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
                curl_close($ch);
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
            curl_close($ch);
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
