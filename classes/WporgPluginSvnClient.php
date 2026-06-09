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

    private function request(string $method, string $path = '', array $headers = [], ?string $body = null): array {
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

        $response = wp_remote_request($this->build_url($path), $args);
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

    private function build_url(string $path): string {
        $path = trim($path, '/');
        if ($path === '') {
            return self::REPO_URL;
        }

        $segments = array_map('rawurlencode', explode('/', $path));
        return self::REPO_URL . implode('/', $segments);
    }
}
