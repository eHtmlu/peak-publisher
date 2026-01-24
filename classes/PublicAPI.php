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
        $settings = get_peak_publisher_settings();
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
        $untrusted_client_domain = $this->get_untrusted_client_domain_from_user_agent();
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

    private function get_untrusted_client_domain_from_user_agent() {

        // Get a sanitized user agent
        $sanitized_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($sanitized_user_agent === '') {
            return false;
        }

        // Extract only a domain name (if there is one) and ignore the rest of the user agent
        if (!preg_match('/^.*?https?:\/\/([^\/\:]+).*$/', $sanitized_user_agent, $matches)) {
            return false;
        }
        $unvalidated_untrusted_client_domain = $matches[1];

        // Transform domain name to ASCII
        $unvalidated_untrusted_client_domain_ascii = idn_to_ascii($unvalidated_untrusted_client_domain);
        if ($unvalidated_untrusted_client_domain_ascii === false) {
            return false;
        }

        // Check if ASCII domain is a valid domain name
        $validated_untrusted_client_domain_ascii = filter_var($unvalidated_untrusted_client_domain_ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($validated_untrusted_client_domain_ascii === false) {
            return false;
        }

        // Convert ASCII domain name back to UTF-8
        $validated_untrusted_client_domain = idn_to_utf8($validated_untrusted_client_domain_ascii);
        if ($validated_untrusted_client_domain === false) {
            return false;
        }

        // Return UTF-8 domain name if it is a valid domain name
        return $validated_untrusted_client_domain;
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

    /**
     * Handle the plugin information API request.
     * 
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php
     * 
     * @param \WP_REST_Request $request The REST request object.
     * @return array The plugin information.
     */
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
        $latest_release_content = json_decode((string) $latest_release->post_content, true);
        $latest_version = $latest_release->post_title;
        $plugin_data = $plugin_infos['release_data']['plugin_data'];


        // Based on the original code from WordPress.org  ( https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php )
        $post = $latest_release;

		$result            = array();
		$result['name']    = $plugin_data['Name'];
		$result['slug']    = $plugin->post_name;
		$result['version'] = $latest_version ?: '0.0';

        $author = (string) ($plugin_data['Author'] ?? '');
        $author_uri = (string) ($plugin_data['AuthorURI'] ?? '');
        
        $profile_url = $author_uri;
		$result['author'] = $profile_url ? sprintf(
			'<a href="%s">%s</a>',
			esc_url_raw( $profile_url ),
			$author
		) : $author;

		$result['author_profile'] = $profile_url;
		$result['contributors']   = array();

		$result['requires']         = empty($plugin_data['RequiresWP']) ? false : $plugin_data['RequiresWP'];
		$result['tested']           = empty($latest_release_content['plugin_readme_txt']['content']['tested']) ? false : $latest_release_content['plugin_readme_txt']['content']['tested'];
		$result['requires_php']     = empty($plugin_data['RequiresPHP']) ? false : $plugin_data['RequiresPHP'];
		$result['requires_plugins'] = empty($plugin_data['RequiresPlugins']) ? [] : array_map('trim', explode(',', $plugin_data['RequiresPlugins']));
		$result['compatibility']    = array();

		// Determine the last_updated date.
		$last_updated = $post->last_updated ?: $post->post_modified_gmt; // Prefer the post_meta unless not set.
		if ( '0000-00-00 00:00:00' === $last_updated ) {
			$last_updated = $post->post_date_gmt;
		}

		$result['last_updated']             = gmdate( 'Y-m-d g:ia \G\M\T', strtotime( $last_updated ) );
		$result['added']                    = gmdate( 'Y-m-d', strtotime( $plugin->post_date_gmt ) );
		$result['homepage']                 = empty($plugin_data['PluginURI']) ? '' : $plugin_data['PluginURI'];
		$result['sections']                 = array();

        $sections = $latest_release_content['plugin_readme_txt']['content']['sections'] ?? [];

        foreach ($sections as $section_key => $section_content) {
            $result['sections'][$section_key] = apply_filters( 'the_content', $section_content, $section_key);
        }

		if ( ! empty( $result['sections']['faq'] ) ) {
			$result['sections']['faq'] = $this->get_simplified_faq_markup( $result['sections']['faq'] );
		}

		$result['short_description'] = $latest_release_content['plugin_readme_txt']['content']['short_description'] ?? $plugin_data['Description'] ?? '';
		$result['description']       = $result['sections']['description'] ?? $result['short_description'];
		$result['download_link']     = rest_url(self::NAMESPACE . '/plugins/download/' . $plugin->post_name . '/' . $latest_release->post_title);
		$result['upgrade_notice']    = $latest_release_content['plugin_readme_txt']['content']['upgrade_notice'] ?? '';

        $terms = array_map(fn($term) => (object) [
            'slug' => sanitize_title($term),
            'name' => $term
        ], $latest_release_content['plugin_readme_txt']['content']['tags'] ?? []);

		$result['tags'] = array();
		if ( $terms ) {
			foreach ( $terms as $term ) {
				$result['tags'][ $term->slug ] = $term->name;
			}
		}

		$result['stable_tag'] = $latest_version ?: 'trunk';

		$result['versions'] = array();
		if ( $versions = array_keys($releases) ) {
			if ( 'trunk' != $result['stable_tag'] ) {
				array_push( $versions, 'trunk' );
			}
			foreach ( $versions as $version ) {
				$result['versions'][ $version ] = rest_url(self::NAMESPACE . '/plugins/download/' . $plugin->post_name . ($version === 'trunk' ? '' : '/' . $version));
			}
		}

		$result['donate_link'] = $latest_release_content['plugin_readme_txt']['content']['donate_link'] ?? '';

        $expected_fields = $this->get_expected_fields('plugin_information');
        foreach ($expected_fields as $field => $value) {
            if ($value !== true && isset($result[$field])) {
                unset($result[$field]);
            }
        }

        return $result;
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
        $zip_abs = trailingslashit(peak_publisher_upload_basedir()) . ltrim($zip_rel, '/\\');
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
        $filename = sanitize_file_name($filename);
        header('X-Content-Type-Options: nosniff');
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

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $results = [
            'plugins' => [],
            'translations' => [],
        ];
        foreach ($params_plugins['plugins'] as $plugin_basename => $plugin_payload) {
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

            // Record installation ping (only if a site URL could be extracted)
            $client_installed_version = (string) ($plugin_payload['Version'] ?? '');
            record_plugin_installation((int) $plugin->ID, (string) $user_agent, (string) $client_installed_version);
            
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

        // order releases by version (ascending); latest will be array_key_last
        uksort($releases, function($a, $b) {
            return version_compare($a, $b);
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

    /**
     * Get the expected fields for the plugin information API.
     * 
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/standalone/class-plugins-info-api-request.php
     * @param string $method The method to get the fields for.
     * @return array The expected fields.
     */
    private function get_expected_fields($method): array {

        static $fields = array(
            'active_installs'        => false,
            'added'                  => false,
            'banners'                => false,
            'compatibility'          => false,
            'contributors'           => false,
            'description'            => false,
            'donate_link'            => false,
            'downloaded'             => false,
            'download_link'          => false,
            'homepage'               => false,
            'icons'                  => false,
            'last_updated'           => false,
            'rating'                 => false,
            'ratings'                => false,
            'reviews'                => false, // NOTE: sub-key of 'sections'.
            'requires'               => false,
            'requires_php'           => false,
            'sections'               => false,
            'short_description'      => false,
            'tags'                   => false,
            'tested'                 => false,
            'stable_tag'             => false,
            'blocks'                 => false,
            'block_assets'           => false,
            'author_block_count'     => false,
            'author_block_rating'    => false,
            'language_packs'         => false,
            'versions'               => false,
            'screenshots'            => false,
            'blueprints'             => false,
            'preview_link'           => false,
            'upgrade_notice'         => false,
            'business_model'         => false,
            'repository_url'         => false,
            'support_url'            => false,
            'commercial_support_url' => false,
        );

        static $plugins_info_fields_defaults = array(
            'added'             => true,
            'compatibility'     => true,
            'contributors'      => false,
            'bare_contributors' => true,
            'downloaded'        => true,
            'download_link'     => true,
            'donate_link'       => true,
            'homepage'          => true,
            'last_updated'      => true,
            'rating'            => true,
            'ratings'           => true,
            'requires'          => true,
            'requires_php'      => true,
            'sections'          => true,
            'tags'              => true,
            'tested'            => true,
            'versions'          => true,
            'screenshots'       => true,
        );

        // Alterations made to default fields in the info/1.2 API.
        static $plugins_info_fields_defaults_12 = array(
            'downloaded'             => false,
            'bare_contributors'      => false,
            'compatibility'          => false,
            'description'            => false,
            'banners'                => true,
            'reviews'                => true,
            'active_installs'        => true,
            'contributors'           => true,
            'preview_link'           => true,
            'upgrade_notice'         => true,
            'business_model'         => true,
            'repository_url'         => true,
            'support_url'            => true,
            'commercial_support_url' => true,
        );

        if ( 'plugin_information' === $method ) {
			$fields = array_merge(
				$fields,
				$plugins_info_fields_defaults,
				$plugins_info_fields_defaults_12,
			);
		}

        return $fields;
    }

	/**
     * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/api/routes/class-plugin.php
     * NOTE: The original wordpress.org implementation used plain string replacements that failed
     * to replace <dt>/<dd> when attributes were present (e.g. <dt class="...">). As a result,
     * tags with attributes were left untouched. This plugin fixes that by performing
     * attribute-preserving replacements (<dt ...> -> <h4 ...>, <dd ...> -> <p ...>) and by
     * removing <dl> and <h3> wrappers without altering their inner content.
     * ------------------------------------------------------------
	 * Return a 'simplified' markup for the FAQ screen.
	 * WordPress only supports a whitelisted selection of tags, `<dl>` is not one of them.
	 *
	 * @see https://core.trac.wordpress.org/browser/tags/4.7/src/wp-admin/includes/plugin-install.php#L478
	 * @param string $markup The existing Markup.
	 * @return string Them markup with `<dt>` replaced with `<h4>` and `<dd>` with `<p>`.
	 */
	protected function get_simplified_faq_markup( $markup ) {

        /*
        // Original wordpress.org implementation.
        $markup = str_replace(
            array( '<dl>', '</dl>', '<dt>', '</dt>', '<h3>', '</h3>', '<dd>', '</dd>' ),
            array( '',     '',      '<h4>', '</h4>', '',      '',     '<p>',  '</p>'  ),
            $markup
        );
        */

		// Our own implementation.
		$patterns = array(
			'/<dl[^>]*>/i',
			'/<\/dl>/i',
			'/<dt(\s[^>]*)?>/i',
			'/<\/dt>/i',
			'/<h3[^>]*>/i',
			'/<\/h3>/i',
			'/<dd(\s[^>]*)?>/i',
			'/<\/dd>/i',
		);
		$replacements = array(
			'',
			'',
			'<h4$1>',
			'</h4>',
			'',
			'',
			'<p$1>',
			'</p>',
		);
		$markup = preg_replace( $patterns, $replacements, $markup );

		return $markup;
	}
}

PublicAPI::init();


