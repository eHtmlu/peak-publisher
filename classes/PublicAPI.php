<?php

namespace Pblsh;

defined('ABSPATH') || exit;


class PublicAPI {
    private static $instance = null;

    const NAMESPACE = 'pblsh/v1';

    private function __construct() {
        $this->register_routes();
    }

    public static function init(): self {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/plugins/update-check', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_update_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins/info', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_info'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins/download/(?P<slug>[^/]+)(?:/(?P<version>[^/]+))?', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_download'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'slug' => ['required' => true],
                'version' => ['required' => false, 'default' => ''],
            ],
        ]);
    }

    public function check_permission(): bool {
        $settings = get_publisher_settings();
        $ip_whitelist = $settings['ip_whitelist'];

        if (empty($ip_whitelist)) {
            return true;
        }

        $ip_types = [
            4 => ['dns_type' => DNS_A, 'filter_option' => FILTER_FLAG_IPV4, 'allowed_ips' => [], 'cidr_ranges' => []],
            6 => ['dns_type' => DNS_AAAA, 'filter_option' => FILTER_FLAG_IPV6, 'allowed_ips' => [], 'cidr_ranges' => []],
        ];

        // split into ip addresses, cidr ranges and domain names
        $domain_names = [];
        foreach ($ip_whitelist as $item) {

            // cidr range
            if (str_contains($item, '/')) {
                [$ip, $subnet] = explode('/', $item, 2);
        
                // Prüfen, ob Prefix eine Zahl ist
                if (ctype_digit($subnet)) {
                    $subnet = (int) $subnet;
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $subnet >= 0 && $subnet <= 32) {
                        $ip_types[4]['cidr_ranges'][] = [$ip, $subnet];
                    } else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $subnet >= 0 && $subnet <= 128) {
                        $ip_types[6]['cidr_ranges'][] = [$ip, $subnet];
                    }
                }
                continue;
            }
        
            if (filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip_types[4]['allowed_ips'][] = $item;
                continue;
            }
            
            if (filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ip_types[6]['allowed_ips'][] = $item;
                continue;
            }
            
            if (filter_var($item, FILTER_VALIDATE_DOMAIN)) {
                $domain_names[] = $item;
                continue;
            }
        }

        // get client ip and determine ip type
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return false;
        }
        $client_ip = filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP);
        if (filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_type = $ip_types[4];
        } else if (filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip_type = $ip_types[6];
        } else {
            return false;
        }
        
        // check if client ip is in any of the allowed ip addresses
        if (in_array($client_ip, $ip_type['allowed_ips'])) {
            return true;
        }

        // check if client ip is in any of the allowed cidr ranges
        foreach ($ip_type['cidr_ranges'] as $cidr_range) {
            if ($this->ip_in_range($client_ip, $cidr_range[0], $cidr_range[1])) {
                return true;
            }
        }

        // Get Domain from client user-agent
        $untrusted_client_domain = $this->extract_sanitized_but_untrusted_client_domain_from_user_agent();
        if (empty($untrusted_client_domain)) {
            return false;
        }

        // check if domain is in any of the allowed domain names
        if (in_array($untrusted_client_domain, $domain_names)) {

            // resolve domain to ip addresses to get trustworthy values
            $dns_records = dns_get_record($untrusted_client_domain, $ip_type['dns_type']);

            // check if any of the trustworthy ip addresses matches the client ip
            foreach ($dns_records as $dns_record) {
                if ($client_ip === $dns_record['ip']) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function extract_sanitized_but_untrusted_client_domain_from_user_agent() {

        // Get user agent
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The sanitization process is not a one-liner, it's done below in this function.
        $unsanitized_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : false;
        if ($unsanitized_user_agent === false) {
            return false;
        }

        // Extracting only a domain name (if there is one) and ignore the rest of the user agent, so we only need to sanitize the domain name
        if (!preg_match('/^.*?https?:\/\/([^\/\:]+).*$/', $unsanitized_user_agent, $matches)) {
            return false;
        }
        $unsanitized_untrusted_client_domain = $matches[1];

        // Transform domain name to ASCII
        $unsanitized_untrusted_client_domain_ascii = idn_to_ascii($unsanitized_untrusted_client_domain);
        if ($unsanitized_untrusted_client_domain_ascii === false) {
            return false;
        }

        // Check if ASCII domain is a valid domain name
        $sanitized_untrusted_client_domain_ascii = filter_var($unsanitized_untrusted_client_domain_ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($sanitized_untrusted_client_domain_ascii === false) {
            return false;
        }

        // Convert ASCII domain name back to UTF-8
        $sanitized_untrusted_client_domain = idn_to_utf8($sanitized_untrusted_client_domain_ascii);
        if ($sanitized_untrusted_client_domain === false) {
            return false;
        }

        // Return UTF-8 domain name if it is a valid domain name
        return $sanitized_untrusted_client_domain;
    }

    public function ip_in_range(string $ip, string $subnet, int $bits): bool {

        // not yet implemented, so we return always false to deny access
        return false;
        
        /* if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        list($subnet, $bits) = explode('/', $range, 2);
        $bits = (int) $bits;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            $subnet_long &= $mask;
            return ($ip_long & $mask) === $subnet_long;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip_bin = self::inet6_to_bits($ip);
            $subnet_bin = self::inet6_to_bits($subnet);
            return substr($ip_bin, 0, $bits) === substr($subnet_bin, 0, $bits);
        } */
    }

    public function checkdnsrr(string $domain, string $type): bool {
        return gethostbyname($domain) !== $domain;
    }

    public function handle_info(\WP_REST_Request $request) {
        $action = (string) ($request->get_param('action') ?? '');
        $slug = (string) ($request->get_param('request')['slug'] ?? '');
        $locale = (string) ($request->get_param('request')['locale'] ?? '');
        $wp_version = (string) ($request->get_param('request')['wp_version'] ?? '');

        if ($action !== 'plugin_information') {
            return wp_send_json_error('Action not implemented.');
        }
        if ($slug === '') {
            return wp_send_json_error('Invalid input');
        }

        $plugin_infos = $this->get_plugin_infos($slug);
        if (empty($plugin_infos)) {
            return wp_send_json_error('Plugin not found.');
        }
        $plugin = $plugin_infos['plugin'];
        $releases = $plugin_infos['releases'];
        $latest_release = $plugin_infos['release'];
        $latest_version = $latest_release->post_title;
        $plugin_data = $plugin_infos['release_data']['plugin_data'];

        $author = (string) ($plugin_data['Author'] ?? '');
        $author_profile = (string) ($plugin_data['AuthorURI'] ?? '');
        $description = (string) ($plugin_data['Description'] ?? '');
        $requires = (string) ($plugin_data['RequiresWP'] ?? '');
        $requires_php = (string) ($plugin_data['RequiresPHP'] ?? '');
        $requires_plugins = (array) ($plugin_data['RequiresPlugins'] ? array_map('trim', explode(',', $plugin_data['RequiresPlugins'])) : []);
        $homepage = (string) ($plugin_data['PluginURI'] ?? '');

        $last_updated = (new \DateTime($latest_release->post_date_gmt))->format('Y-m-d g:ia \G\M\T');//->format('Y-m-d g:ia \G\M\T');

        $versions = array_map(fn($release) => rest_url(self::NAMESPACE . '/plugins/download/' . $plugin->post_name . '/' . $release->post_title), $releases);
        $versions['trunk'] = rest_url(self::NAMESPACE . '/plugins/download/' . $plugin->post_name);

        return array_merge(
            ['name' => $plugin_data['Name']],
            ['slug' => $plugin->post_name],
            ['version' => $latest_version],
            empty($author) ? [] : [ 'author' => $author ],
            empty($author_profile) ? [] : [ 'author_profile' => $author_profile ],
            empty($requires) ? [] : [ 'requires' => $requires ],
            empty($requires_php) ? [] : [ 'requires_php' => $requires_php ],
            empty($requires_plugins) ? [] : [ 'requires_plugins' => $requires_plugins ],
            ['last_updated' => $last_updated],
            empty($homepage) ? [] : [ 'homepage' => $homepage ],
            ['sections' => [
                'description' => $description,
            ]],
            ['download_link' => $versions[$latest_version]],
            ['versions' => $versions],
        );
    }

    /**
     * Streams a release ZIP through WordPress to bypass web-server access limits.
     */
    public function handle_download(\WP_REST_Request $request) {
        $slug = (string) ($request->get_param('slug') ?? '');
        $version = (string) ($request->get_param('version') ?? '');
        if ($slug === '') {
            wp_send_json_error('Invalid parameters');
            return;
        }

        $plugin_infos = $this->get_plugin_infos($slug, $version);
        if (empty($plugin_infos)) {
            return wp_send_json_error('Plugin not found.');
        }
        $release = $plugin_infos['release'];

        $zip_rel = (string) get_post_meta($release->ID, '_pblsh_zip_path', true);
        if ($zip_rel === '') {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }
        $zip_abs = trailingslashit(publisher_upload_basedir()) . ltrim($zip_rel, '/\\');
        if (!file_exists($zip_abs) || !is_readable($zip_abs)) {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }

        $wp_filesystem = get_wp_filesystem();
        $data = $wp_filesystem->get_contents($zip_abs);
        if ($data === false) {
            return new \WP_Error('no_file', 'File not found', ['status' => 404]);
        }

        $filename = basename($zip_abs);
        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . (string) filesize($zip_abs));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file output
        echo $data;
        exit;
    }

    public function handle_update_check(\WP_REST_Request $request) {
        // get post parameters
        $params = $request->get_body_params();
        $params_plugins = json_decode($params['plugins'] ?? '[]', true);

        if (!isset($params_plugins['plugins']) || !is_array($params_plugins['plugins'])) {
            return new \WP_Error('invalid_parameters', 'Invalid parameters', ['status' => 400]);
        }

        $results = [
            'plugins' => [],
            'translations' => [],
        ];
        foreach ($params_plugins['plugins'] as $plugin_basename => $plugin_data) {
            $slug = explode('/', $plugin_basename, 2)[0];
            $plugin_infos = $this->get_plugin_infos($slug);
            if (empty($plugin_infos)) {
                continue;
            }
            $plugin = $plugin_infos['plugin'];
            $release = $plugin_infos['release'];
            $release_data = $plugin_infos['release_data'];
            $latest_version = $release->post_title;
            $plugin_data = $release_data['plugin_data'];

            $requires_plugins = (array) ($plugin_data['RequiresPlugins'] ? array_map('trim', explode(',', $plugin_data['RequiresPlugins'])) : []);
            
            $results['plugins'][$plugin_basename] = array_merge(
                ['slug' => $plugin->post_name],
                ['plugin' => $plugin_basename],
                ['version' => $latest_version],
                ['package' => rest_url(self::NAMESPACE . '/plugins/download/' . $plugin->post_name . '/' . $latest_version)],
                empty($plugin_data['RequiresWP']) ? [] : [ 'requires' => $plugin_data['RequiresWP'] ],
                empty($plugin_data['RequiresPHP']) ? [] : [ 'requires_php' => $plugin_data['RequiresPHP'] ],
                empty($requires_plugins) ? [] : [ 'requires_plugins' => $requires_plugins ]
            );
        }

        return $results;
    }

    private function get_plugin_infos(string $slug, ?string $version = ''): ?array {
        $plugins = get_posts([
            'post_type' => 'pblsh_plugin',
            'post_status' => 'publish',
            'post_parent' => 0,
            'name' => $slug,
        ]);
        if (count($plugins) !== 1) {
            return null;
        }
        $plugin = $plugins[0];

        $releases_query = new \WP_Query([
            'post_type' => 'pblsh_release',
            'post_status' => 'publish',
            'post_parent' => $plugin->ID,
            'posts_per_page' => -1,
        ]);

        $releases = [];
        foreach ($releases_query->posts as $release) {
            $releases[$release->post_title] = $release;
        }

        if (count($releases) === 0) {
            return null;
        }

        // order releases by version
        uksort($releases, function($a, $b) {
            return version_compare($a, $b, '>');
        });

        $requested_release = null;
        if ($version) {
            $version_normalized = normalize_version_number($version);
            foreach ($releases as $release_version => $release) {
                $release_version_normalized = normalize_version_number($release_version);
                if ($release_version_normalized === $version_normalized) {
                    $requested_release = $release;
                }
            }
        }
        else {
            $requested_release = $releases[array_key_last($releases)];
        }

        if (!$requested_release) {
            return null;
        }

        $release_data = json_decode((string) ($requested_release->post_content ?? ''), true);

        return [
            'plugin' => $plugin,
            'releases' => $releases,
            'release' => $requested_release,
            'release_data' => $release_data,
        ];
    }
}

PublicAPI::init();


