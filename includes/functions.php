<?php

namespace Pblsh;

use Exception;

defined('ABSPATH') || exit;


/**
 * Gets Update URI.
 */
function get_update_uri(): string {
    return trailingslashit(home_url('wp-json/pblsh/v1/'));
}


/**
 * The one authoritative source for channel display texts, keyed by hosting type.
 * Exposed to the client as PblshData.channelTexts — upload state and targets carry
 * facts only, never UI text.
 */
function get_channel_texts(): array {
    return [
        'wporg' => [
            'label' => __('wordpress.org', 'peak-publisher'),
            'description' => __('Manages plugins in the official directory.', 'peak-publisher'),
        ],
        'self_hosted' => [
            'label' => __('Self-hosted', 'peak-publisher'),
            'description' => __('Manages plugins hosted on this site.', 'peak-publisher'),
        ],
    ];
}


/**
 * Gets deep links to the plugin's FAQ entries on wordpress.org, keyed by topic.
 * The questions must match the readme.txt FAQ questions verbatim — wordpress.org builds its anchors from them.
 */
function get_peak_publisher_faq_urls(): array {
    $questions = [
        'bothChannels' => 'Can I use both distribution channels for one plugin?',
        'switchLater' => 'Can I switch the distribution channel later?',
        'credentialStorage' => 'How are my wordpress.org credentials stored?',
        'whyImport' => 'Why is an import needed to manage a wordpress.org plugin?',
        'versionFormat' => 'Which version numbers can I publish?',
    ];
    return array_map(
        fn($question) => 'https://wordpress.org/plugins/peak-publisher/#' . rawurlencode(strtolower(trim($question))),
        $questions
    );
}


/**
 * Returns the installed Peak Publisher version. The plugin header is the only place it is declared.
 */
function get_peak_publisher_version(): string {
    return (string) (get_file_data(PBLSH_PLUGIN_FILE, [ 'Version' => 'Version' ])['Version'] ?? '');
}


/**
 * The one User-Agent for every request Peak Publisher sends to wordpress.org
 * (info API, download check, SVN client). Only the plugin identifies itself —
 * never the site.
 */
function wporg_user_agent(): string {
    static $user_agent = null;
    if ($user_agent === null) {
        $user_agent = 'PeakPublisher/' . get_peak_publisher_version() . ' (+https://www.wppeak.com/)';
    }
    return $user_agent;
}


/**
 * Gets the embed code.
 */
function get_bootstrap_code(string $version = 'basicV2'): string {
    if ($version !== 'basicV1' && $version !== 'basicV2') {
        return '';
    }
    $code = @file_get_contents(PBLSH_PLUGIN_DIR . 'assets/bootstrap-codes/' . $version . '.php.txt');
    return is_string($code) ? $code : '';
}


/**
 * Gets the bootstrap codes.
 */
function get_bootstrap_codes(): array {
    return [
        'basicV1' => get_bootstrap_code('basicV1'),
        'basicV2' => get_bootstrap_code('basicV2'),
    ];
}


/**
 * Returns a stable salt.
 */
function get_secret_salt(): string {
    $salt = get_option('pblsh_secret_salt');
    if (!is_string($salt) || $salt === '') {
        $salt = wp_generate_password(64, true, true);
        update_option('pblsh_secret_salt', $salt, false);
    }
    return $salt;
}

/**
 * Records an installation occurrence for the given plugin.
 */
function record_plugin_installation(int $plugin_post_id, string $user_agent, string $installed_version = ''): void {
    if ($plugin_post_id <= 0) {
        return;
    }
    $settings = get_peak_publisher_settings();
    if (empty($settings['count_plugin_installations'])) {
        return;
    }
    // Only pings from the bootstrap code count; the home URL in the user agent identifies the site.
    $expected_user_agent_pattern = '#^PeakPublisherBootstrapCode/[^;]+; WordPress/[^;]+; https?://([^;]+)(;.*)?$#';
    if (empty($user_agent) || !preg_match($expected_user_agent_pattern, $user_agent, $matches)) {
        return;
    }

    // The key hashes only the home URL (without scheme, lowercased) so that it survives WordPress and
    // bootstrap updates and the switch to https. The secret salt keeps the stored keys non-reversible.
    $site_identity = strtolower($matches[1]);
    $key = substr(preg_replace('/[^a-z0-9]/i', '', base64_encode(hash('sha256', get_secret_salt() . '|' . $site_identity, true))), 0, 14);

    // Update the installations list.
    $list = get_plugin_installations_list($plugin_post_id);
    $now = time();
    $installed_version_normalized = $installed_version !== '' ? normalize_version_number($installed_version) : '';
    if (!isset($list[$key]) || !is_array($list[$key])) {
        $list[$key] = [
            'first_seen' => $now,
            'last_seen' => $now,
            'count' => 1,
            'last_version' => $installed_version,
            'last_version_normalized' => $installed_version_normalized,
        ];
    } else {
        $list[$key]['last_seen'] = $now;
        $list[$key]['count'] = (int) ($list[$key]['count'] ?? 0) + 1;
        if ($installed_version !== '') {
            $list[$key]['last_version'] = $installed_version;
            $list[$key]['last_version_normalized'] = $installed_version_normalized;
        }
    }
    set_plugin_installations_list($plugin_post_id, $list);
}

/**
 * Returns unique installation count for a plugin.
 */
function get_plugin_installations_count(int $plugin_post_id): int {
    $list = get_plugin_installations_list($plugin_post_id);
    return count($list);
}

/**
 * The REST view of a self-hosted plugin's installations: the exact count of unique
 * sites of the last 24 hours, or 'disabled' when the setting switches the counting off
 * — the client reads the state, never the setting. The wordpress.org counterpart is
 * serialize_wporg_directory() (includes/wporg_directory.php).
 */
function serialize_self_hosted_installations(int $plugin_post_id): array {
    if (empty(get_peak_publisher_settings()['count_plugin_installations'])) {
        return [ 'state' => 'disabled', 'count' => null ];
    }
    return [ 'state' => 'ok', 'count' => get_plugin_installations_count($plugin_post_id) ];
}

/**
 * Returns number of installations currently on a specific normalized version.
 */
function get_plugin_installations_count_by_version(int $plugin_post_id, string $normalized_version): int {
    $normalized_version = normalize_version_number($normalized_version);
    if ($normalized_version === '') {
        return 0;
    }
    $list = get_plugin_installations_list($plugin_post_id);
    $count = 0;
    foreach ($list as $row) {
        $v = (string) ($row['last_version_normalized'] ?? '');
        if ($v !== '' && $v === $normalized_version) {
            $count++;
        }
    }
    return $count;
}

/**
 * Returns the installations list (filtered by default to active within 24h).
 */
function get_plugin_installations_list(int $plugin_post_id): array {
    $meta_key = '_pblsh_installations';
    $list = get_post_meta($plugin_post_id, $meta_key, true);
    if (!is_array($list) || empty($list)) { return []; }
    $ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 24 * 60 * 60;
    $now = time();
    $out = [];
    foreach ($list as $k => $row) {
        $last = (int) ($row['last_seen'] ?? 0);
        if ($last > 0 && ($now - $last) > $ttl) {
            continue;
        }
        $out[$k] = $row;
    }
    if (count($out) !== count($list)) { // if some installations are stale, update the list
        update_post_meta($plugin_post_id, $meta_key, $out);
    }
    return $out;
}

/**
 * Persists the installations list.
 */
function set_plugin_installations_list(int $plugin_post_id, array $list): void {
    update_post_meta($plugin_post_id, '_pblsh_installations', $list);
}
/**
 * Raises the PHP execution time limit for request cycles that perform many
 * sequential wordpress.org SVN requests (deploys, tag syncs, imports).
 */
function raise_wporg_time_limit(): void {
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
}


/**
 * Whether a plugin version can be published: digits and dots, optionally followed by
 * -rc, -beta or -alpha with an optional number (1.2.0, 1.2.0-beta1, 2.0-RC.2).
 *
 * This is the format the wordpress.org import warns about when violated, and the vocabulary
 * PHP's version_compare() — which WordPress uses to decide whether to offer an update — orders
 * the way authors expect. Any other letter segment sorts below the numeric release (only
 * segments starting with a lowercase "p" sort above it), so "1.0.1a" is never offered to sites
 * on 1.0.1. Within this format the string is usable verbatim as the release ZIP's version part
 * and as the wordpress.org SVN tag.
 */
const PBLSH_PUBLISHABLE_VERSION_PATTERN = '/^\d+(?:\.\d+)*(?<pre_release>-(?:rc|beta|alpha)(?:\.?\d+)?)?$/i';

function is_publishable_version(string $version): bool {
    return preg_match(PBLSH_PUBLISHABLE_VERSION_PATTERN, $version) === 1;
}


/**
 * Whether a publishable version carries the pre-release suffix (-rc, -beta, -alpha): a
 * release for testers, which never becomes the current release by default.
 */
function is_pre_release_version(string $version): bool {
    return preg_match(PBLSH_PUBLISHABLE_VERSION_PATTERN, $version, $found) === 1 && !empty($found['pre_release']);
}


/**
 * The upload's default answer to "does this release become the current release?" — one
 * decision tree for both channels: exactly one relation per upload, evaluated in this
 * order. Where the channels differ, the reason is always the same: on wordpress.org the
 * decision is forced exactly where trunk receives this code (first, and no_current with
 * trunk_and_tag) — wordpress.org would serve it either way, so a tag pointer is the better
 * state; self-hosted serves nothing without a pointer, and a pre-release must not become
 * current by default even then.
 *
 * @param array   $current      resolve_current_release() of the plugin (or its wporg marker).
 * @param bool    $has_releases The plugin has releases (wporg: the mirror has).
 * @param string  $version      The uploaded version V.
 * @param bool    $is_wporg
 * @param ?string $deploy_mode  wporg only: trunk_and_tag | tag_only (whether trunk gets code).
 * @return array{relation:string, pre_release:bool, default_make_current:bool, choice:bool}
 *         relation: first | unknown | repairs_pointer | no_current | equal | higher | lower;
 *         choice: whether the user may deviate from the default.
 */
function decide_current_release(array $current, bool $has_releases, string $version, bool $is_wporg, ?string $deploy_mode = null): array {
    $pre_release = is_pre_release_version($version);
    $decide = static fn(string $relation, bool $default, bool $choice): array => [
        'relation' => $relation,
        'pre_release' => $pre_release,
        'default_make_current' => $default,
        'choice' => $choice,
    ];
    $state = (string) $current['state'];
    $normalized_version = normalize_version_number($version);

    if ($state === 'tag_missing' && normalize_version_number((string) $current['pointer']) === $normalized_version) {
        // The pointer already names this version — the release makes it valid; opting out
        // would be a no-op. Before "first" on purpose: a pointer naming V while the plugin
        // has no release at all (a readme bumped ahead of its tag, a deleted release) makes
        // V current whatever the switch says.
        return $decide('repairs_pointer', true, false);
    }
    if (!$has_releases) {
        // wporg: forced in every pointer state, trunk carries this very code. self-hosted:
        // opting out leaves the plugin without a current release (nothing is offered).
        return $is_wporg ? $decide('first', true, false) : $decide('first', !$pre_release, true);
    }
    if ($state === 'unknown') {
        // finalize re-evaluates with the live value and stops if the relation would differ.
        return $decide('unknown', !$pre_release, true);
    }
    if (in_array($state, [ 'trunk', 'tag_missing', 'none' ], true)) {
        // wporg: with trunk getting the code a tag pointer is always better — forced; with
        // tag_only trunk stays as it is, and so may the pointer that serves it (trunk, an
        // invalid tag, none at all: wordpress.org serves trunk for each). self-hosted:
        // opting out keeps the pointer empty or invalid.
        if ($is_wporg && $deploy_mode === 'trunk_and_tag') {
            return $decide('no_current', true, false);
        }
        return $decide('no_current', !$pre_release, true);
    }

    $comparison = version_compare($normalized_version, normalize_version_number((string) $current['release']->post_title));
    if ($comparison === 0) {
        // Replace: the pointer stays, nothing is written.
        return $decide('equal', true, false);
    }
    if ($comparison > 0) {
        return $decide('higher', !$pre_release, true);
    }
    // A hotfix on an older line; opting in is a rollback with fix.
    return $decide('lower', false, true);
}


/**
 * Normalizes a version number.
 */
function normalize_version_number(string $version): string {
    $version = strtolower(trim($version));
    $version = str_replace(['-', '_', '+'], '.', $version);
    $version = preg_replace('/([^.\d]+)/', '.$1.', $version);
    $version = preg_replace('/\.{2,}/', '.', $version);
    $version = trim($version, '.');
    return $version;
}



/**
 * The one selection behind "current release" on both channels: the entry whose version
 * the plugin's pointer names. Pure — null for an empty pointer, for 'trunk' (wordpress.org
 * distributes trunk then) and for a pointer no entry matches. Entries are keyed by their
 * version string (release post_title, tag name, import bundle version), so the import can
 * select before any post exists.
 *
 * @template T
 * @param array<string, T> $releases_by_version
 * @return T|null
 */
function select_current_release(array $releases_by_version, ?string $pointer) {
    if ($pointer === null || $pointer === '' || $pointer === 'trunk') {
        return null;
    }
    return $releases_by_version[$pointer] ?? null;
}


/**
 * Resolves a plugin's current release — the release sites receive — from the plugin's
 * pointer. The channels differ only in the pointer's source: wporg the Stable tag of
 * trunk/readme.txt (marker cache `trunk_readme`, SVN is authoritative), self-hosted the
 * plugin meta `_pblsh_current_release`. "current" is derived here and never stored per
 * release.
 *
 * @param \WP_Post[]|null $release_posts The plugin's release posts when the caller already
 *        loaded them (list views load every parent in one query); loaded here otherwise.
 * @return array{state:string, pointer:string|null, release:?\WP_Post, latest:?\WP_Post, reference:?\WP_Post}
 *         state: current | none (empty pointer) | tag_missing (the pointer names a version
 *         without release) | trunk (wporg: the pointer is trunk) | unknown (wporg: the trunk
 *         readme was not readable at the last refresh; pointer null). latest = the highest
 *         version of all releases; reference = release ?? latest — the one fallback for the
 *         plugin name and readme-derived data.
 */
function resolve_current_release(\WP_Post $plugin, ?array $release_posts = null): array {
    if ($release_posts === null) {
        $release_posts = get_posts([
            'post_type' => 'pblsh_release',
            'post_status' => 'any',
            'post_parent' => (int) $plugin->ID,
            'posts_per_page' => -1,
        ]);
    }

    $by_version = [];
    $latest = null;
    $latest_normalized = '';
    foreach ($release_posts as $release) {
        if (!$release instanceof \WP_Post) {
            continue;
        }
        $version = (string) $release->post_title;
        $normalized = normalize_version_number($version);
        if ($normalized === '') {
            continue;
        }
        $by_version[$version] = $release;
        if ($latest === null || version_compare($normalized, $latest_normalized, '>')) {
            $latest = $release;
            $latest_normalized = $normalized;
        }
    }

    $is_wporg = is_wporg_plugin($plugin);
    if ($is_wporg) {
        $cache = wporg_decode_json_object((string) $plugin->post_content);
        // A cache without the key predates the trunk readme in the cache (re-validation
        // when reading back from persistence): the next revision-driven refresh fills
        // it — until then the pointer is unknown.
        $trunk_readme = $cache['trunk_readme'] ?? null;
        $pointer = is_array($trunk_readme) ? (string) ($trunk_readme['stable_tag'] ?? '') : null;
    } else {
        // A plugin without the meta (created before the pointer existed, migration not run
        // yet) reads as '' — no current release.
        $pointer = (string) get_post_meta((int) $plugin->ID, '_pblsh_current_release', true);
    }

    $release = select_current_release($by_version, $pointer);
    if ($pointer === null) {
        $state = 'unknown';
    } elseif ($release instanceof \WP_Post) {
        $state = 'current';
    } elseif ($pointer === '') {
        $state = 'none';
    } elseif ($is_wporg && $pointer === 'trunk') {
        $state = 'trunk';
    } else {
        $state = 'tag_missing';
    }

    return [
        'state' => $state,
        'pointer' => $pointer,
        'release' => $release,
        'latest' => $latest,
        'reference' => $release ?? $latest,
    ];
}


/**
 * The release sites receive, or null when the plugin has none (state none/tag_missing/unknown).
 */
function get_current_release(\WP_Post $plugin): ?\WP_Post {
    return resolve_current_release($plugin)['release'];
}


/**
 * Makes another release of a self-hosted plugin the current one — the second of the two
 * write sites of `_pblsh_current_release` (the other is the upload's finalize). The
 * caller's expectation is compared first, so a flip by another admin since the editor
 * showed its pointer is never overwritten silently.
 *
 * @return array{from:string, to:string}|\WP_Error current_release_target_missing (404) when
 *         no release carries the version, invalid_current_release_target (400) when it is
 *         the current one already, current_release_changed (409) when the pointer differs
 *         from the expected one.
 */
function flip_current_release_pointer(\WP_Post $plugin, string $version, string $expected_pointer) {
    $current = resolve_current_release($plugin);
    $target = get_posts([
        'post_type' => 'pblsh_release',
        'post_status' => 'any',
        'post_parent' => (int) $plugin->ID,
        'title' => $version,
        'posts_per_page' => 1,
    ]);
    if (empty($target)) {
        return new \WP_Error('current_release_target_missing', __('The release to make current no longer exists.', 'peak-publisher'), [ 'status' => 404 ]);
    }
    if ($current['release'] instanceof \WP_Post && (int) $current['release']->ID === (int) $target[0]->ID) {
        return new \WP_Error('invalid_current_release_target', __('This release is the current release already.', 'peak-publisher'), [ 'status' => 400 ]);
    }
    if ((string) $current['pointer'] !== $expected_pointer) {
        return new \WP_Error('current_release_changed', __('The current release changed in the meantime. Reload the plugin and check again.', 'peak-publisher'), [ 'status' => 409 ]);
    }

    update_post_meta((int) $plugin->ID, '_pblsh_current_release', $version);
    refresh_plugin_title_from_reference((int) $plugin->ID);

    return [ 'from' => (string) $current['pointer'], 'to' => $version ];
}


/**
 * Sets the plugin's title from its reference release (current, else latest): the plugin
 * name follows what sites receive. Both channels; called after every change of the
 * pointer or of the release set.
 */
function refresh_plugin_title_from_reference(int $plugin_id): void {
    $plugin = get_post($plugin_id);
    if (!is_plugin_post($plugin)) {
        return;
    }
    $reference = resolve_current_release($plugin)['reference'];
    if (!$reference instanceof \WP_Post) {
        return;
    }
    $content = wporg_decode_json_object((string) $reference->post_content);
    $name = (string) ($content['plugin_data']['Name'] ?? '');
    if ($name === '' || $plugin->post_title === $name) {
        return;
    }
    wp_update_post([
        'ID' => $plugin_id,
        'post_title' => $name,
    ]);
}


/**
 * Generates a slug for a release. Both channels share the pblsh_release post
 * type (one slug namespace), and the same plugin slug × version may exist on
 * wporg and self_hosted at once — so wporg releases carry their channel as
 * prefix. Self-hosted is the unmarked home namespace, mirroring pblsh_plugin
 * vs. pblsh_wporg_plugin; shipped self-hosted release slugs thus stay valid
 * as they are — do not "symmetrize" the prefix. The underscore separator
 * keeps the prefix unforgeable: plugin slugs never contain underscores.
 */
function get_release_slug(string $hosting_type, string $plugin_slug, string $version): string {
    $prefix = $hosting_type === 'self_hosted' ? '' : $hosting_type . '_';
    return sanitize_title($prefix . $plugin_slug . '_' . normalize_version_number($version));
}


/**
 * Selects the readme file name using wordpress.org's readme import precedence.
 *
 * @param string[] $files File names from the plugin root.
 */
function find_wporg_readme_file_name(array $files): ?string {
    try {
        // START - Copy of WordPress.org code ( https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/cli/i18n/class-readme-import.php )
        $readme_files = preg_grep( '!^readme.(txt|md)$!i', $files );
        if ( ! $readme_files ) {
            throw new Exception( 'Plugin has no readme file.' );
        }

        $readme_file = reset( $readme_files );
        foreach ( $readme_files as $f ) {
            if ( '.txt' == strtolower( substr( $f, - 4 ) ) ) {
                $readme_file = $f;
                break;
            }
        }
        // END - Copy of WordPress.org code

        return is_string($readme_file) ? $readme_file : null;
    } catch (\Throwable $e) {
        return null;
    }
}


/**
 * Returns the default storage shape for parsed plugin readme data.
 */
function default_plugin_readme_txt_data(): array {
    return [
        'found' => false,
        'file_name' => '',
        'content' => [],
    ];
}


/**
 * Parses a WordPress.org-style readme.txt into headers/sections and includes raw content.
 * Returns associative array suitable for storage under data.plugin_readme_txt.
 *
 * $content is readme content and nothing else. The parser's own constructor takes "a
 * filepath, URL, or contents" and reads whatever a path or URL names, so it is replaced:
 * a readme that merely looks like one is parsed as the text it is, never dereferenced.
 *
 * @param string $content Content of the readme.txt file.
 * @return array Parsed readme.txt content.
 */
function parse_readme_txt(string $content): array {
    require_once PBLSH_PLUGIN_DIR . 'libs/plugin-directory/readme/class-parser.php';
    require_once PBLSH_PLUGIN_DIR . 'libs/plugin-directory/class-markdown.php';

    // Use the official parser of wordpress.org
    $parser = new class($content) extends \Pblsh\Vendor\WordPressdotorg\Plugin_Directory\Readme\Parser {
        public function __construct(string $content) {
            if ($content !== '') {
                $this->parse_readme_contents($content);
            }
        }
    };
    // Return the full parser data structure
    $data = get_object_vars($parser);
    return is_array($data) ? $data : [];
}


/**
 * The oEmbed providers a readme may embed from — wordpress.org's whitelist for plugin readmes
 * (Plugin_Directory::oembed_whitelist()), "limited to providers that add video support to
 * plugin readme files".
 *
 * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/class-plugin-directory.php
 *
 * @param array $providers WP_oEmbed's providers: match mask => [ endpoint URL, is regex ].
 * @return array The providers of the whitelisted hosts.
 */
function readme_oembed_whitelist(array $providers): array {
    /**
     * Filters the hosts a readme may embed from. A provider WordPress does not know has to
     * be registered as well (wp_oembed_add_provider()).
     *
     * @param string[] $whitelist Hosts, each matched as part of a provider's endpoint URL.
     */
    $whitelist = (array) apply_filters('pblsh_readme_oembed_providers', array(
        'youtube.com',
        'vimeo.com',
        'wordpress.com',
        'wordpress.tv',
        'vine.co',
        'soundcloud.com',
        'instagram.com',
        'mixcloud.com',
        'cloudup.com',
    ));

    // START - Copy of WordPress.org code, the whitelist above taken out of the callback
    return array_filter( $providers, function ( $provider ) use ( $whitelist ) {
        foreach ( $whitelist as $url ) {
            if ( false !== strpos( $provider[0], $url ) ) {
                return true;
            }
        }

        return false;
    } );
    // END - Copy of WordPress.org code
}


/**
 * Renders readme sections for the plugin information as wordpress.org's API does: through
 * the_content, under the limits its plugin directory sets for its whole site — no oEmbed
 * discovery, embeds from the whitelisted providers only, none but the allowed shortcodes,
 * no [embed]. A readme can thereby make this server contact the whitelisted providers and
 * nobody else. Unlike there, the limits hold for this rendering alone: the site keeps its
 * own embeds and shortcodes.
 *
 * @see https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/class-plugin-directory.php
 *
 * @param array<string, string> $sections The parsed sections by key.
 * @return array<string, string> The rendered HTML by key.
 */
function render_readme_sections(array $sections): array {
    global $shortcode_tags;

    // oEmbed whitelisting. WordPress fixes the provider list when it builds its oEmbed object,
    // so the list is exchanged on the object: an oembed_providers filter added here would come
    // too late or outlive this rendering.
    $no_discovery = static fn(): bool => false;
    add_filter('embed_oembed_discover', $no_discovery, PHP_INT_MAX);
    $oembed = _wp_oembed_get_object();
    $site_providers = $oembed->providers;
    $oembed->providers = readme_oembed_whitelist($site_providers);

    $site_shortcode_tags = $shortcode_tags;
    /**
     * Filters the shortcodes a readme may use. The default is wordpress.org's video
     * shortcodes; WordPress registers neither, they work where a plugin provides them.
     *
     * @param string[] $allowed_shortcodes Shortcode tags.
     */
    $allowed_shortcodes = (array) apply_filters('pblsh_readme_shortcodes', array(
        'youtube',
        'vimeo',
    ));

    // START - Copy of WordPress.org code (Plugin_Directory::remove_other_shortcodes())
    $not_allowed_shortcodes = array_diff( array_keys( $shortcode_tags ), $allowed_shortcodes );
    foreach ( $not_allowed_shortcodes as $tag ) {
        remove_shortcode( $tag );
    }

    // remove special embed shortcode handling
    $embed_shortcode_removed = remove_filter( 'the_content', array( $GLOBALS['wp_embed'], 'run_shortcode' ), 8 );
    // END - Copy of WordPress.org code

    try {
        $rendered = [];
        foreach ($sections as $section_key => $section_content) {
            $rendered[$section_key] = apply_filters('the_content', $section_content, $section_key);
        }
        return $rendered;
    } finally {
        remove_filter('embed_oembed_discover', $no_discovery, PHP_INT_MAX);
        $oembed->providers = $site_providers;
        $shortcode_tags = $site_shortcode_tags;
        if ($embed_shortcode_removed) {
            add_filter('the_content', array($GLOBALS['wp_embed'], 'run_shortcode'), 8);
        }
    }
}


/**
 * Sets the `Stable tag:` header of a readme to $value — the one place the pointer is written
 * into a readme: R1 in analyze (the release's readme names its own version), the trunk
 * variant of a deploy, the flip. Works on the header block exactly as wordpress.org's parser
 * reads it (Parser::parse_readme_contents()): the title as the parser finds it (a first line
 * that is no known header, a GitHub-style underline, the "Plugin Name" placeholder with the
 * real name below), then the `Key: value` lines, blank lines tolerated, but an unknown key
 * after a blank line already belongs to the short description; the block ends with its last
 * header line. Sets every Stable tag line of the block (case-insensitive, the key's spelling
 * kept — the parser keeps the last one it reads), inserts one after the last header line when
 * there is none, keeps every line ending and a UTF-8 BOM as they are, and never touches
 * anything below the block — a "Stable tag:" in the description included.
 *
 * Valid UTF-8 only: the caller guarantees it (analyze records when a readme could not be
 * processed, wporg targets refuse such a readme).
 *
 * @throws \InvalidArgumentException On invalid UTF-8 — a caller bug, not a user error.
 */
function set_readme_stable_tag(string $content, string $value): string {
    if (!is_utf8($content)) {
        throw new \InvalidArgumentException('set_readme_stable_tag() needs valid UTF-8');
    }
    // The parser strips a BOM before reading; it goes back in front of the result.
    $bom = has_utf8_bom($content) ? "\xEF\xBB\xBF" : '';
    $content = strip_utf8_bom($content);
    $new_header = 'Stable tag: ' . $value;
    $join = static fn(array $lines): string => $bom . implode('', array_map(static fn(array $line): string => $line['text'] . $line['end'], $lines));

    // Lines beside their own endings, so every line ending survives.
    $parts = preg_split('/(\R)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = [];
    for ($i = 0; $i < count($parts); $i += 2) {
        $lines[] = [ 'text' => $parts[$i], 'end' => $parts[$i + 1] ?? '' ];
    }

    // Parser::parse_possible_header(): a line with a colon that is no title or markdown heading.
    $header_key = static function(string $line): ?string {
        if (!str_contains($line, ':') || str_starts_with($line, '#') || str_starts_with($line, '=')) {
            return null;
        }
        return strtolower(trim(explode(':', $line, 2)[0], " \t*-\r\n"));
    };
    $valid_headers = parse_readme_txt('')['valid_headers'] ?? [];
    $is_known_header = static function(string $line) use ($header_key, $valid_headers): bool {
        $key = $header_key($line);
        return $key !== null && isset($valid_headers[$key]);
    };
    // Parser::get_first_nonwhitespace(): a line whose trimmed text is empty() — '' and '0'
    // alike — is whitespace.
    $next_content = static function(int $from) use ($lines): ?int {
        for ($i = $from; $i < count($lines); $i++) {
            if (!empty(trim($lines[$i]['text']))) {
                return $i;
            }
        }
        return null;
    };

    $start = $next_content(0);
    if ($start === null) {
        return $bom . $new_header . "\n";
    }
    $last_header = null;
    if (!$is_known_header($lines[$start]['text'])) {
        // The title, as the parser takes it: any first line that is no known header (the
        // name may be left off entirely), a GitHub-style underline of = or - below it, and
        // for the placeholder "Plugin Name" the real name on the next short line.
        $title = strtolower(trim($lines[$start]['text'], "#= \t\0\x0B"));
        $last_header = $start++;
        if ($start < count($lines) && $lines[$start]['text'] !== '' && trim($lines[$start]['text'], '=-') === '') {
            $last_header = $start++;
        }
        if ($title === 'plugin name') {
            $name = $next_content($start);
            if ($name !== null && strlen($lines[$name]['text']) < 50 && !$is_known_header($lines[$name]['text'])) {
                $last_header = $name;
                $start = $name + 1;
            }
        }
    }
    // The header loop starts at the next content line (whitespace-only lines between the
    // title and the first header are skipped); inside the block only an empty() line is
    // tolerated — a whitespace-only one ends it, like every other non-header line.
    $start = $next_content($start) ?? count($lines);
    $after_blank = false;
    $replaced = false;
    for ($i = $start; $i < count($lines); $i++) {
        $text = $lines[$i]['text'];
        if (empty($text)) {
            $after_blank = true;
            continue;
        }
        $key = $header_key($text);
        if ($key === null || ($after_blank && !isset($valid_headers[$key]))) {
            break;
        }
        if ($key === 'stable tag') {
            $lines[$i]['text'] = preg_replace_callback('/^([ \t*\-]*stable\s+tag[ \t*\-]*:\s*).*$/iu', static fn(array $m): string => $m[1] . $value, $text);
            $replaced = true;
        }
        $last_header = $i;
        $after_blank = false;
    }
    if ($replaced) {
        return $join($lines);
    }

    if ($last_header === null) {
        array_unshift($lines, [ 'text' => $new_header, 'end' => "\n" ]);
        return $join($lines);
    }
    $new_line = [ 'text' => $new_header, 'end' => $lines[$last_header]['end'] ];
    if ($lines[$last_header]['end'] === '') {
        // The header line was the file's last line without a newline: it gets one, the new line ends the file.
        $lines[$last_header]['end'] = "\n";
    }
    array_splice($lines, $last_header + 1, 0, [ $new_line ]);
    return $join($lines);
}


/**
 * Gets the WordPress filesystem.
 */
function get_wp_filesystem(): \WP_Filesystem_Base {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        WP_Filesystem();
    }
    return $wp_filesystem;
}







/**
 * Detects the text encoding of a given string.
 * Returns encoding label (e.g., 'UTF-8', 'UTF-16', 'Windows-1252') or false if unknown.
 */
function detect_text_encoding(string $content) {
    if (function_exists('mb_detect_encoding')) {
        $content = strip_utf8_bom($content);
        $detect_order = 'UTF-8, UTF-16, UTF-16LE, UTF-16BE, Windows-1252, ISO-8859-1, ISO-8859-15, ASCII';
        $enc = @mb_detect_encoding($content, $detect_order, true);
        if (is_string($enc) && $enc !== '') {
            return $enc;
        }
    }
    return false;
}


/**
 * Checks if a string is UTF-8.
 */
function is_utf8(string $content): bool {
    $content = strip_utf8_bom($content);
    return preg_match('//u', $content) === 1;
}


/**
 * Checks if the content does NOT start with a UTF-8 BOM.
 */
function has_utf8_bom(string $content): bool {
    return substr($content, 0, 3) === "\xEF\xBB\xBF";
}


/**
 * Converts a string to UTF-8 using a provided source encoding (if known).
 * If $source_encoding is falsy or 'UTF-8', performs best-effort cleanup only.
 */
function convert_to_utf8(string $content, $source_encoding = null): string {
    $converted = $content;
    if (is_string($source_encoding) && strtoupper($source_encoding) !== 'UTF-8') {
        if (function_exists('mb_convert_encoding')) {
            $maybe = @mb_convert_encoding($content, 'UTF-8', $source_encoding);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        } elseif (function_exists('iconv')) {
            $maybe = @iconv($source_encoding, 'UTF-8//IGNORE', $content);
            if (is_string($maybe) && $maybe !== '') {
                $converted = $maybe;
            }
        }
    }

    // Final safety: if still invalid UTF-8, drop invalid sequences.
    if (function_exists('iconv') && is_utf8($converted)) {
        $maybe = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
        if (is_string($maybe) && $maybe !== '') {
            $converted = $maybe;
        }
    }
    return is_string($converted) ? $converted : $content;
}


/**
 * Strips the UTF-8 BOM from a string.
 */
function strip_utf8_bom(string $content): string {
    return has_utf8_bom($content) ? substr($content, 3) : $content;
}






/**
 * Retrieve the average color of a specified image.
 *
 * Samples five points (rule of thirds + center) and averages their RGB values.
 * Algorithm matches Jetpack's Tonesque library used by WordPress.org Plugin Directory.
 *
 * Based on WordPress.org Plugin Directory.
 * @see https://github.com/WordPress/wordpress.org — class-tools.php
 * @see Jetpack Tonesque — grab_points() / grab_color() / get_color()
 *
 * @param string $file_path Absolute filesystem path to the image.
 * @return string|false Average color as a 6-char lowercase hex value (no #), false on failure.
 */
function get_image_average_color( string $file_path ) {
    if ( ! function_exists( 'imagecreatefromstring' ) ) {
        return false;
    }

    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
    $data = file_get_contents( $file_path );
    if ( $data === false ) {
        return false;
    }

    $img = @imagecreatefromstring( $data );
    if ( ! $img ) {
        return false;
    }

    $width  = imagesx( $img );
    $height = imagesy( $img );

    // Sample five points based on rule of thirds and center (same as Tonesque::grab_points).
    $left_x   = (int) round( $width / 3 );
    $right_x  = (int) round( ( $width / 3 ) * 2 );
    $top_y    = (int) round( $height / 3 );
    $bottom_y = (int) round( ( $height / 3 ) * 2 );
    $center_x = (int) round( $width / 2 );
    $center_y = (int) round( $height / 2 );

    $points = [
        imagecolorat( $img, $left_x,   $top_y ),
        imagecolorat( $img, $right_x,  $top_y ),
        imagecolorat( $img, $left_x,   $bottom_y ),
        imagecolorat( $img, $right_x,  $bottom_y ),
        imagecolorat( $img, $center_x, $center_y ),
    ];

    // Average the RGB channels (same as Tonesque::grab_color).
    $r = [];
    $g = [];
    $b = [];
    foreach ( $points as $color_index ) {
        $c  = imagecolorsforindex( $img, $color_index );
        $r[] = $c['red'];
        $g[] = $c['green'];
        $b[] = $c['blue'];
    }

    imagedestroy( $img );

    $red   = (int) round( array_sum( $r ) / 5 );
    $green = (int) round( array_sum( $g ) / 5 );
    $blue  = (int) round( array_sum( $b ) / 5 );

    return sprintf( '%02x%02x%02x', $red, $green, $blue );
}


/**
 * Base URL of the public geopattern-icon endpoint (route registered in PublicAPI).
 */
function geopattern_icon_base_url(): string {
    return rest_url( 'pblsh/v1/plugins/geopattern-icon/' );
}


/**
 * Polyfills for PHP 8.0 functions.
 */
if (!function_exists('str_starts_with')) {
    function str_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return 0 === strpos( $haystack, $needle );
	}
}
if (!function_exists('str_contains')) {
    function str_contains( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return false !== strpos( $haystack, $needle );
	}
}
if (!function_exists('str_ends_with')) {
    function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack ) {
			return '' === $needle;
		}

		$len = strlen( $needle );

		return substr( $haystack, -$len, $len ) === $needle;
	}
}
