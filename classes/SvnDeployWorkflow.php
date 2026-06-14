<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class SvnDeployWorkflow {
    public static function list_tags(string $wporg_slug): array {
        $wporg_slug = self::normalize_slug_or_throw($wporg_slug);
        $client = self::read_client();
        $base_path = $wporg_slug . '/tags';

        try {
            $entries = $client->list_directory($base_path . '/', 1);
        } catch (WporgPluginSvnClientException $e) {
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
        $client = self::read_client();

        try {
            $entries = $client->list_directory($wporg_slug . '/', 0);
        } catch (WporgPluginSvnClientException $e) {
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
        $client = self::read_client();
        $base_path = $wporg_slug . '/tags/' . $version;
        $entries = $client->list_directory($base_path . '/', 1);
        $children = self::direct_children($entries, $base_path);

        return [
            'plugin_data' => self::fetch_plugin_data($client, $children),
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

        $client = self::read_client();
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
            $out[$version] = [
                'plugin_data' => self::plugin_data_from_prefetched_files($children_by_version[$version] ?? [], $file_contents),
                'plugin_readme_txt' => self::readme_data_from_prefetched_file($readme_by_version[$version] ?? null, $file_contents),
            ];
        }

        return $out;
    }

    public static function discover_plugins_by_author(string $username): array {
        $normalized_username = normalize_wporg_username($username);
        if (is_wp_error($normalized_username)) {
            throw new \RuntimeException($normalized_username->get_error_code());
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
                ],
            ], 'https://api.wordpress.org/plugins/info/1.2/');

            $response = wp_remote_get($url, [
                'timeout' => 20,
                'redirection' => 3,
                'user-agent' => 'Peak Publisher wordpress.org Discovery',
            ]);
            if (is_wp_error($response)) {
                throw new \RuntimeException('wporg_api_unavailable');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('wporg_api_unavailable');
            }

            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($data) || !is_array($data['plugins'] ?? null)) {
                throw new \RuntimeException('wporg_api_unavailable');
            }

            $response_plugins = $data['plugins'];
            foreach ($response_plugins as $plugin) {
                if (!is_array($plugin)) {
                    continue;
                }

                $slug = normalize_wporg_slug($plugin['slug'] ?? null);
                if (is_wp_error($slug) || isset($seen[$slug])) {
                    continue;
                }

                $seen[$slug] = true;
                $name = html_entity_decode(wp_strip_all_tags((string) ($plugin['name'] ?? $slug)), ENT_QUOTES | ENT_HTML5);
                $plugins[] = [
                    'slug' => $slug,
                    'name' => $name !== '' ? $name : $slug,
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

    private static function read_client(): WporgPluginSvnClient {
        require_once __DIR__ . '/WporgPluginSvnClient.php';
        return new WporgPluginSvnClient();
    }

    private static function normalize_slug_or_throw(string $wporg_slug): string {
        $slug = normalize_wporg_slug($wporg_slug);
        if (is_wp_error($slug)) {
            throw new \RuntimeException($slug->get_error_code());
        }
        return $slug;
    }

    private static function safe_path_segment(string $segment): string {
        $segment = trim($segment);
        if ($segment === '' || str_contains($segment, '/') || str_contains($segment, '\\') || str_contains($segment, '..')) {
            throw new \RuntimeException('invalid_svn_path_segment');
        }
        return $segment;
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

    private static function fetch_plugin_data(WporgPluginSvnClient $client, array $children): ?array {
        foreach ($children as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $name = (string) ($entry['name'] ?? '');
            if (($entry['type'] ?? '') !== 'file' || substr($name, -4) !== '.php') {
                continue;
            }

            $plugin_data = self::parse_plugin_data_from_contents($name, $client->read_file($path));
            if ($plugin_data !== null) {
                return $plugin_data;
            }
        }

        return null;
    }

    private static function fetch_readme_data(WporgPluginSvnClient $client, array $children): array {
        $readme_info = [
            'found' => false,
            'file_name' => '',
            'content' => [],
        ];

        $readme = self::find_readme_entry($children);
        if ($readme === null) {
            return $readme_info;
        }

        try {
            $content = $client->read_file((string) $readme['path']);
        } catch (WporgPluginSvnClientException $e) {
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

    private static function plugin_data_from_prefetched_files(array $children, array $file_contents): ?array {
        foreach ($children as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $name = (string) ($entry['name'] ?? '');
            if (($entry['type'] ?? '') !== 'file' || substr($name, -4) !== '.php' || !array_key_exists($path, $file_contents)) {
                continue;
            }

            $plugin_data = self::parse_plugin_data_from_contents($name, (string) $file_contents[$path]);
            if ($plugin_data !== null) {
                return $plugin_data;
            }
        }

        return null;
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
            return self::empty_readme_info();
        }

        $path = (string) ($readme['path'] ?? '');
        if ($path === '' || !array_key_exists($path, $file_contents)) {
            return self::empty_readme_info();
        }

        return self::readme_info_from_content($readme, (string) $file_contents[$path]);
    }

    private static function readme_info_from_content(array $readme, string $content): array {
        $parsed = parse_readme_txt(self::normalize_readme_content($content));

        return [
            'found' => true,
            'file_name' => (string) ($readme['name'] ?? 'readme.txt'),
            'content' => json_encode($parsed) !== false ? $parsed : null,
        ];
    }

    private static function empty_readme_info(): array {
        return [
            'found' => false,
            'file_name' => '',
            'content' => [],
        ];
    }

    private static function normalize_readme_content(string $content): string {
        if (!is_utf8($content)) {
            $content = convert_to_utf8($content, detect_text_encoding($content));
        }
        return strip_utf8_bom($content);
    }
}
