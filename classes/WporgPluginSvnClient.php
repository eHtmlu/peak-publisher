<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class WporgPluginSvnClientException extends \RuntimeException {
    private string $error_code;
    private int $http_status;

    public function __construct(string $error_code, string $message, int $http_status) {
        parent::__construct($message);
        $this->error_code = $error_code;
        $this->http_status = $http_status;
    }

    public function get_error_code(): string {
        return $this->error_code;
    }

    public function get_http_status(): int {
        return $this->http_status;
    }
}


class WporgPluginSvnClient {
    private const REPO_URL = 'https://plugins.svn.wordpress.org/';

    private ?string $username;
    private ?string $password;

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
            throw new WporgPluginSvnClientException(
                'invalid_credentials',
                __('Invalid wordpress.org username or password.', 'peak-publisher'),
                401
            );
        }

        throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
                'not_found',
                __('wordpress.org SVN path was not found.', 'peak-publisher'),
                404
            );
        }
        if (!$this->is_success_status($status) && $status !== 207) {
            throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
                'not_found',
                __('wordpress.org SVN file was not found.', 'peak-publisher'),
                404
            );
        }
        if (!$this->is_success_status($status)) {
            throw new WporgPluginSvnClientException(
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
                throw new WporgPluginSvnClientException(
                    'not_found',
                    __('wordpress.org SVN path was not found.', 'peak-publisher'),
                    404
                );
            }
            if (!$this->is_success_status($status) && $status !== 207) {
                throw new WporgPluginSvnClientException(
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
                throw new WporgPluginSvnClientException(
                    'not_found',
                    __('wordpress.org SVN file was not found.', 'peak-publisher'),
                    404
                );
            }
            if (!$this->is_success_status($status)) {
                throw new WporgPluginSvnClientException(
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
            $before = $this->propfind($root_path, 0);
            $before_status = (int) ($before['status'] ?? 0);
            if ($before_status === 404) {
                return $this->can_write_result(
                    'not_found',
                    false,
                    __('Plugin not found on wordpress.org SVN.', 'peak-publisher')
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
            if (!$this->is_success_status($options_status)) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN did not allow a write-access probe activity.', 'peak-publisher')
                );
            }

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
            if (!$this->is_success_status($mkactivity_status)) {
                return $this->can_write_result(
                    'error',
                    false,
                    __('wordpress.org SVN could not create a temporary write-access probe activity.', 'peak-publisher')
                );
            }
            $activity_created = true;

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
        } catch (WporgPluginSvnClientException $e) {
            return $this->can_write_result('error', false, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->can_write_result(
                'error',
                false,
                __('wordpress.org SVN write-access check failed.', 'peak-publisher')
            );
        } finally {
            if ($activity_created && $activity_url !== '') {
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
        $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<D:checkout xmlns:D="DAV:"><D:activity-set><D:href>' .
            htmlspecialchars($activity_url, ENT_QUOTES | ENT_XML1) .
            '</D:href></D:activity-set></D:checkout>';

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
            throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
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
                throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
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
            throw new WporgPluginSvnClientException(
                'svn_read_failed',
                __('wordpress.org SVN returned an invalid XML response.', 'peak-publisher'),
                502
            );
        }

        $sx->registerXPathNamespace('D', 'DAV:');
        $responses = $sx->xpath('//D:response') ?: [];
        if (empty($responses)) {
            throw new WporgPluginSvnClientException(
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
