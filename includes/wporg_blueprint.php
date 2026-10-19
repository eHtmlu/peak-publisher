<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The blueprint vocabulary of a wordpress.org plugin's Live Preview — assets/blueprints/blueprint.json,
 * after wordpress.org's rules: which files its import reads, what Peak Publisher accepts before it
 * stores a blueprint (what wordpress.org would ignore or choke on is refused), the encoding that
 * keeps a blueprint as it was meant, and a faithful port of the plugin directory's normalization —
 * the blueprint as the plugin page serves it. Pure functions; WporgAssetSync does the I/O.
 */

// wordpress.org's limit: a larger blueprint is skipped by its import, silently.
const PBLSH_BLUEPRINT_MAX_BYTES = 102400;
// The one file the plugin page uses for the Live Preview.
const PBLSH_BLUEPRINT_FILENAME = 'blueprint.json';


/** wordpress.org's rule for the files of assets/blueprints/ it imports, its regex verbatim (no flags: case-sensitive, ASCII \w). */
function is_blueprint_filename(string $name): bool {
    return (bool) preg_match('!^blueprint[-\w]*\.json$!', $name);
}


/**
 * A listing of assets/blueprints/ as wordpress.org's import reads it: the preview file
 * (blueprint.json, exactly so), the files the import takes (blueprint.json among them) and
 * everything else — counted, never managed.
 *
 * @param array<int, array{name:string, type:string, size:int|null, revision:int}> $entries
 * @return array{blueprint: ?array{name:string, size:int, revision:int}, blueprint_files:int, other_files:int}
 */
function classify_blueprint_listing(array $entries): array {
    $out = [ 'blueprint' => null, 'blueprint_files' => 0, 'other_files' => 0 ];
    foreach ($entries as $entry) {
        $name = (string) $entry['name'];
        if (($entry['type'] ?? 'file') !== 'file' || !is_blueprint_filename($name)) {
            $out['other_files']++;
            continue;
        }
        $out['blueprint_files']++;
        if ($name === PBLSH_BLUEPRINT_FILENAME) {
            $out['blueprint'] = [ 'name' => $name, 'size' => (int) ($entry['size'] ?? 0), 'revision' => (int) $entry['revision'] ];
        }
    }
    return $out;
}


/**
 * What a blueprint must be before Peak Publisher stores it: within wordpress.org's limit, JSON,
 * an object — anything else is ignored by the import —, and steps the import can read: a list
 * whose entries are objects (or null/false, which it drops), a step's options an object. A
 * string among the steps makes the import throw, and the whole import of that commit fails,
 * the commit's images included.
 *
 * @return true|\WP_Error blueprint_too_large (413), blueprint_invalid_json, blueprint_not_object,
 *         blueprint_invalid_steps (400)
 */
function validate_blueprint_contents(string $contents): bool|\WP_Error {
    $error = static fn(string $code, string $message, int $status = 400): \WP_Error => new \WP_Error($code, $message, [ 'status' => $status ]);

    if (strlen($contents) > PBLSH_BLUEPRINT_MAX_BYTES) {
        return $error('blueprint_too_large', sprintf(__('The blueprint is %1$s — wordpress.org ignores blueprints over %2$s.', 'peak-publisher'), format_asset_size(strlen($contents)), format_asset_size(PBLSH_BLUEPRINT_MAX_BYTES)), 413);
    }
    $decoded = json_decode($contents, false);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $error('blueprint_invalid_json', __('The blueprint is not valid JSON.', 'peak-publisher'));
    }
    if (!$decoded instanceof \stdClass) {
        return $error('blueprint_not_object', __('A blueprint is a JSON object — it starts with {.', 'peak-publisher'));
    }
    if (property_exists($decoded, 'steps')) {
        $steps_ok = is_array($decoded->steps);
        foreach ($steps_ok ? $decoded->steps : [] as $step) {
            if (!($step instanceof \stdClass || $step === null || $step === false)
                || ($step instanceof \stdClass && property_exists($step, 'options') && !$step->options instanceof \stdClass)) {
                $steps_ok = false;
                break;
            }
        }
        if (!$steps_ok) {
            return $error('blueprint_invalid_steps', __('"steps" must be a list of objects, and a step\'s "options" an object — anything else makes the import on wordpress.org fail for the whole commit.', 'peak-publisher'));
        }
    }
    return true;
}


/** A blueprint as objects — {} and [] stay apart — or null when it is no JSON object. */
function decode_blueprint(string $contents): ?\stdClass {
    $decoded = json_decode($contents, false);
    return $decoded instanceof \stdClass ? $decoded : null;
}


/**
 * The file Peak Publisher writes for a blueprint it changed: pretty printed with four spaces,
 * slashes and Unicode as they are, 1.0 stays 1.0, a closing newline. Only changes made through
 * the fields and the dependency rule encode; JSON typed in the editor is stored as typed.
 */
function encode_blueprint(\stdClass $blueprint): string {
    // Encoding a decoded JSON object cannot fail — a failure would be a bug, and it throws.
    return json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
}


/**
 * One step as the plugin directory's normalize_blueprint_json() leaves it, the body of its loop
 * without the self-install part: an install from a downloads.wordpress.org URL becomes a slug
 * resource (the version is lost, the key moves to the end), a pluginZipFile with a slug becomes
 * pluginData. Assoc arrays, as decoded there.
 */
function normalize_blueprint_step_like_wporg(array $step): array {
    if (in_array($step['step'] ?? null, [ 'installPlugin', 'installTheme' ], true)) {
        foreach ([ 'pluginZipFile', 'pluginData', 'themeZipFile', 'themeData' ] as $key) {
            $url = $step[$key]['url'] ?? '';
            if (is_string($url) && preg_match('!^https?://downloads\.wordpress\.org/[^/]+/(?P<slug>[a-z0-9-_]+)(\.(?P<version>.+?))?\.zip($|[?])!i', $url, $m)) {
                unset($step[$key]);
                if ($step['step'] === 'installPlugin') {
                    $step['pluginData'] = [ 'resource' => 'wordpress.org/plugins', 'slug' => $m['slug'] ];
                } else {
                    $step['themeData'] = [ 'resource' => 'wordpress.org/themes', 'slug' => $m['slug'] ];
                }
            }
        }
    }
    if (isset($step['pluginZipFile']['slug'])) {
        $step['pluginData'] = [ 'resource' => 'wordpress.org/plugins', 'slug' => $step['pluginZipFile']['slug'] ];
        unset($step['pluginZipFile']);
    }
    return $step;
}


/**
 * The blueprint as the plugin page serves it — a port of the plugin directory's
 * normalize_blueprint_json() (cli/class-import.php): falsy steps dropped, every step normalized
 * (normalize_blueprint_step_like_wporg()), the install step of the plugin itself forced to
 * activate, or appended at the end when there is none (akismet is bundled with WordPress).
 * Assoc arrays, as decoded there; encode_blueprint_like_wporg() makes the served text. Null
 * where the import ignores the file or would throw on it — what validate_blueprint_contents()
 * refuses (re-validation: the contents may be a file from wordpress.org).
 */
function normalize_blueprint_like_wporg(string $contents, string $slug): ?array {
    if (validate_blueprint_contents($contents) !== true) {
        return null;
    }
    $decoded = json_decode($contents, true);
    $has_self_install_step = false;
    if (isset($decoded['steps'])) {
        $decoded['steps'] = array_values(array_filter($decoded['steps']));
        foreach ($decoded['steps'] as &$step) {
            $step = normalize_blueprint_step_like_wporg($step);
            if (($step['step'] ?? null) === 'installPlugin' && isset($step['pluginData']['slug']) && $slug === $step['pluginData']['slug']) {
                $has_self_install_step = true;
                $step['options'] ??= [];
                $step['options']['activate'] = true;
            }
        }
        unset($step);
    }
    if (!$has_self_install_step && $slug !== 'akismet') {
        $decoded['steps'][] = [
            'step' => 'installPlugin',
            'pluginData' => [ 'resource' => 'wordpress.org/plugins', 'slug' => $slug ],
            'options' => [ 'activate' => true ],
        ];
    }
    return $decoded;
}


/** The normalized blueprint as wordpress.org stores and serves it: json_encode() without flags — an empty object reads [] there. */
function encode_blueprint_like_wporg(array $normalized): string {
    return (string) json_encode($normalized);
}


/** A step as assoc arrays — the form normalize_blueprint_step_like_wporg() reads. */
function blueprint_step_as_array(\stdClass $step): array {
    return json_decode((string) json_encode($step), true);
}


/** The wordpress.org slug of a plugin install of the shorthand `plugins` (a slug string, or a wordpress.org/plugins resource), null for anything else. */
function blueprint_shorthand_plugin_slug($entry): ?string {
    if (is_string($entry)) {
        return str_contains($entry, '://') ? null : $entry;
    }
    if ($entry instanceof \stdClass) {
        $entry = blueprint_step_as_array($entry);
    }
    return is_array($entry) && ($entry['resource'] ?? null) === 'wordpress.org/plugins' && is_string($entry['slug'] ?? null) ? $entry['slug'] : null;
}


/**
 * The steps of a blueprint as the fields read them, in order: the step's index in `steps`,
 * what it is to wordpress.org's import (normalize_blueprint_step_like_wporg()) and, for the
 * windows, the slug it installs: kind `plugin` (a wordpress.org/plugins resource), `theme` (a
 * wordpress.org/themes resource), `theme_other` (a theme from elsewhere), `redirect_skip` (the
 * step Peak Publisher writes to drop a plugin's activation redirect, as written — slug: that
 * plugin's), `other`. Steps the import drops (null, false, an empty object) are left out.
 *
 * @return array<int, array{index:int, kind:string, slug:?string, step:array}>
 */
function blueprint_step_kinds(\stdClass $blueprint): array {
    $kinds = [];
    foreach (is_array($blueprint->steps ?? null) ? $blueprint->steps : [] as $index => $step) {
        if (!$step instanceof \stdClass || (array) $step === []) {
            continue;
        }
        $normalized = normalize_blueprint_step_like_wporg(blueprint_step_as_array($step));
        $type = $normalized['step'] ?? null;
        $kind = 'other';
        $slug = null;
        if ($type === 'installPlugin' && is_string($normalized['pluginData']['slug'] ?? null)) {
            $slug = $normalized['pluginData']['slug'];
            $kind = 'plugin';
        } elseif ($type === 'installTheme') {
            $is_wporg = ($normalized['themeData']['resource'] ?? null) === 'wordpress.org/themes' && is_string($normalized['themeData']['slug'] ?? null);
            $kind = $is_wporg ? 'theme' : 'theme_other';
            $slug = $is_wporg ? $normalized['themeData']['slug'] : null;
        } elseif (($skip = blueprint_redirect_skip_slug($normalized)) !== null && trim((string) $normalized['code']) === blueprint_redirect_skip_step($skip)->code) {
            $kind = 'redirect_skip';
            $slug = $skip;
        }
        $kinds[] = [ 'index' => (int) $index, 'kind' => $kind, 'slug' => $slug, 'step' => $normalized ];
    }
    return $kinds;
}


/** The index in `steps` of the plugin's own install step as wordpress.org recognizes it (its pluginData slug), null when there is none. */
function blueprint_self_install_index(\stdClass $blueprint, string $own_slug): ?int {
    foreach (blueprint_step_kinds($blueprint) as $step) {
        if ($step['kind'] === 'plugin' && $step['slug'] === $own_slug) {
            return $step['index'];
        }
    }
    return null;
}


/**
 * The Live Preview's fields — windows onto the JSON, each reading its part:
 * - landing_page: `landingPage` (null: not set, Playground lands on /); read-only when it is no string.
 * - theme: the one wordpress.org theme a step installs; read-only when there are several or one from elsewhere.
 * - plugins: every wordpress.org plugin installed, the shorthand first (it runs before the
 *   steps), the plugin itself left out; `required` = named by the Requires Plugins of the
 *   current release ($required). Shown only — the header is where a plugin's needs belong.
 *   The plugin itself stands among them at its place (`own`) — the order is the install
 *   order —, at the end when the blueprint has no step for it (wordpress.org appends one).
 *   `redirect`, for a plugin with a known activation redirect (PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS):
 *   skipped when a step after its install drops the redirect, however that step is written,
 *   else open — the landing page is never reached then; null for every other plugin.
 * - more: what no window shows, by name with how often it occurs — the other steps first (their
 *   `step`; `step` when it has none), in the order of their first appearance, then the
 *   top-level settings.
 *
 * @param string[] $required
 */
function blueprint_fields(\stdClass $blueprint, string $own_slug, array $required): array {
    $landing = property_exists($blueprint, 'landingPage') ? $blueprint->landingPage : null;
    $plugins = [];   // with the index of the install step; -1 for the shorthand list, which runs before every step
    $plugin = static fn(string $slug, int $index): array => [ 'slug' => $slug, 'required' => $slug !== $own_slug && in_array($slug, $required, true), 'own' => $slug === $own_slug, 'index' => $index ];
    foreach (is_array($blueprint->plugins ?? null) ? $blueprint->plugins : [] as $entry) {
        $slug = blueprint_shorthand_plugin_slug($entry);
        if ($slug !== null) {
            $plugins[] = $plugin($slug, -1);
        }
    }
    $themes = [];
    $foreign_themes = 0;
    $skips = [];   // slug → the indexes of the steps dropping its activation redirect
    $more = [];    // name → count, in the order of first appearance
    foreach (blueprint_step_kinds($blueprint) as $step) {
        if ($step['kind'] === 'plugin') {
            $plugins[] = $plugin($step['slug'], $step['index']);
        } elseif ($step['kind'] === 'theme') {
            $themes[] = $step['slug'];
        } elseif ($step['kind'] === 'theme_other') {
            $foreign_themes++;
        } else {
            $skip = blueprint_redirect_skip_slug($step['step']);
            if ($skip !== null) {
                $skips[$skip][] = $step['index'];
            }
            if ($step['kind'] === 'other') {
                $name = is_string($step['step']['step'] ?? null) && $step['step']['step'] !== '' ? $step['step']['step'] : 'step';
                $more[$name] = ($more[$name] ?? 0) + 1;
            }
        }
    }
    foreach (array_diff(array_keys((array) $blueprint), [ 'landingPage', 'login', 'steps', 'plugins', '$schema', 'meta' ]) as $key) {
        $more[$key] = ($more[$key] ?? 0) + 1;
    }
    if (!in_array(true, array_column($plugins, 'own'), true)) {
        $plugins[] = $plugin($own_slug, PHP_INT_MAX);
    }
    $plugins = array_map(static function(array $entry) use ($skips): array {
        $redirect = null;
        if (isset(PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS[$entry['slug']])) {
            $dropped = array_filter($skips[$entry['slug']] ?? [], static fn(int $index): bool => $index > $entry['index']);
            $redirect = $dropped !== [] ? 'skipped' : 'open';
        }
        return [ 'slug' => $entry['slug'], 'required' => $entry['required'], 'own' => $entry['own'], 'redirect' => $redirect ];
    }, $plugins);

    return [
        'landing_page' => [ 'value' => is_string($landing) ? $landing : null, 'editable' => $landing === null || is_string($landing) ],
        'theme' => [ 'slug' => $themes[0] ?? null, 'editable' => count($themes) <= 1 && $foreign_themes === 0 ],
        'plugins' => $plugins,
        'more' => array_map(static fn($name, int $count): array => [ 'name' => (string) $name, 'count' => $count ], array_keys($more), array_values($more)),
    ];
}


/** A copy to change — the caller's object stays as it was. */
function clone_blueprint(\stdClass $blueprint): \stdClass {
    return unserialize(serialize($blueprint));
}


/** The landing page set where it is, or as the first key when the blueprint had none. */
function set_blueprint_landing_page(\stdClass $blueprint, string $path): \stdClass {
    $copy = clone_blueprint($blueprint);
    if (property_exists($copy, 'landingPage')) {
        $copy->landingPage = $path;
        return $copy;
    }
    return (object) [ 'landingPage' => $path, ...(array) $copy ];
}


/**
 * Plugins that send the first admin visit after their activation elsewhere — WooCommerce to its
 * setup — and the transient carrying the redirect: dropped right after the install, the landing
 * page stays, as wordpress.org's own blueprints do it (WordPress/blueprints: woo-shipping,
 * woocommerce-product-feed). By slug. The fields read the fact (blueprint_fields(): `redirect`),
 * Peak Publisher's writes add the step (blueprint_dependency_steps(), add_blueprint_redirect_skip()).
 */
const PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS = [ 'woocommerce' => '_wc_activation_redirect' ];

/** The step dropping a plugin's activation redirect — the one shape Peak Publisher writes; blueprint_step_kinds() knows it by its code. */
function blueprint_redirect_skip_step(string $slug): \stdClass {
    return (object) [ 'step' => 'runPHP', 'code' => "<?php require_once '/wordpress/wp-load.php'; delete_transient('" . PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS[$slug] . "');" ];
}

/** The plugin whose activation redirect a step's PHP drops — any delete of the transient counts, however the step is written —, null for every other step. */
function blueprint_redirect_skip_slug(array $step): ?string {
    if (($step['step'] ?? null) !== 'runPHP' || !is_string($step['code'] ?? null)) {
        return null;
    }
    foreach (PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS as $slug => $transient) {
        if (preg_match('/delete_transient\(\s*[\'"]' . preg_quote($transient, '/') . '[\'"]\s*\)/', $step['code']) === 1) {
            return $slug;
        }
    }
    return null;
}

/** The install steps of dependencies as Peak Publisher writes them: each activated, a known activation redirect dropped right after it. */
function blueprint_dependency_steps(array $slugs): array {
    $steps = [];
    foreach ($slugs as $slug) {
        $steps[] = blueprint_install_step('plugin', $slug);
        if (isset(PBLSH_BLUEPRINT_ACTIVATION_REDIRECTS[$slug])) {
            $steps[] = blueprint_redirect_skip_step($slug);
        }
    }
    return $steps;
}

/** The step dropping the plugin's activation redirect inserted right after its install step — at the steps' start when the shorthand list installs it. */
function add_blueprint_redirect_skip(\stdClass $blueprint, string $slug): \stdClass {
    $copy = clone_blueprint($blueprint);
    $list = is_array($copy->steps ?? null) ? $copy->steps : [];
    $after = -1;
    foreach (blueprint_step_kinds($copy) as $step) {
        if ($step['kind'] === 'plugin' && $step['slug'] === $slug) {
            $after = $step['index'];
            break;
        }
    }
    array_splice($list, $after + 1, 0, [ blueprint_redirect_skip_step($slug) ]);
    $copy->steps = $list;
    return $copy;
}

/** The default theme of the stable branch — a cache, regenerable (docs/data-schema.md): { branch, slug, checked_at }. */
const PBLSH_WP_DEFAULT_THEME_OPTION = 'pblsh_wp_default_theme';

/**
 * The theme a Live Preview starts with when the blueprint sets none: the default theme of the
 * WordPress Playground boots, its latest — WP_DEFAULT_THEME of the stable branch
 * (wordpress_stable_branch(), core's own version check), read from core's default-constants.php
 * on core.svn.wordpress.org once per branch and kept until the branch moves; a branch never
 * changes it. Null while the branch is unknown or the read fails — the row names no theme then,
 * and the next look asks again.
 */
function wp_default_theme(): ?string {
    $branch = wordpress_stable_branch();
    if ($branch === '') {
        return null;
    }
    // Re-validation when reading back from persistence: the branch's own answer, or none.
    $stored = get_option(PBLSH_WP_DEFAULT_THEME_OPTION, []);
    if (is_array($stored) && ($stored['branch'] ?? null) === $branch && is_string($stored['slug'] ?? null) && is_wordpress_org_slug($stored['slug'])) {
        return $stored['slug'];
    }
    $response = wp_remote_get('https://core.svn.wordpress.org/branches/' . rawurlencode($branch) . '/wp-includes/default-constants.php', [
        'timeout' => 10,
        'redirection' => 3,
        'user-agent' => wporg_user_agent(),
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200
        || preg_match("/define\(\s*'WP_DEFAULT_THEME',\s*'([a-z0-9-]+)'\s*\)/", (string) wp_remote_retrieve_body($response), $m) !== 1) {
        return null;
    }
    update_option(PBLSH_WP_DEFAULT_THEME_OPTION, [ 'branch' => $branch, 'slug' => $m[1], 'checked_at' => time() ], false);
    return $m[1];
}

/** An install step of a wordpress.org plugin or theme, activated — the one shape Peak Publisher writes. */
function blueprint_install_step(string $type, string $slug): \stdClass {
    return $type === 'theme'
        ? (object) [ 'step' => 'installTheme', 'themeData' => (object) [ 'resource' => 'wordpress.org/themes', 'slug' => $slug ], 'options' => (object) [ 'activate' => true ] ]
        : (object) [ 'step' => 'installPlugin', 'pluginData' => (object) [ 'resource' => 'wordpress.org/plugins', 'slug' => $slug ], 'options' => (object) [ 'activate' => true ] ];
}


/** Steps inserted right before the plugin's own install step, or at the end when there is none (wordpress.org appends it there). */
function insert_blueprint_steps(\stdClass $blueprint, string $own_slug, array $steps): \stdClass {
    $copy = clone_blueprint($blueprint);
    $list = is_array($copy->steps ?? null) ? $copy->steps : [];
    $own = blueprint_self_install_index($copy, $own_slug);
    array_splice($list, $own ?? count($list), 0, $steps);
    $copy->steps = $list;
    return $copy;
}


/**
 * The theme field written: '' removes the theme's step; a slug replaces the one wordpress.org
 * theme step's resource (its options stay) or inserts a new step before the plugin's own. The
 * caller has made sure the field is editable (blueprint_fields()).
 */
function set_blueprint_theme(\stdClass $blueprint, string $own_slug, string $slug): \stdClass {
    $theme = null;
    foreach (blueprint_step_kinds($blueprint) as $step) {
        if ($step['kind'] === 'theme') {
            $theme = $step['index'];
            break;
        }
    }
    if ($theme === null) {
        return $slug === '' ? clone_blueprint($blueprint) : insert_blueprint_steps($blueprint, $own_slug, [ blueprint_install_step('theme', $slug) ]);
    }
    $copy = clone_blueprint($blueprint);
    if ($slug === '') {
        array_splice($copy->steps, $theme, 1);
        return $copy;
    }
    $step = $copy->steps[$theme];
    unset($step->themeZipFile, $step->themeData);
    $copy->steps[$theme] = (object) [ 'step' => $step->step, 'themeData' => (object) [ 'resource' => 'wordpress.org/themes', 'slug' => $slug ], ...array_diff_key((array) $step, [ 'step' => true ]) ];
    return $copy;
}


/** JavaScript's truthiness — Playground reads options.activate there. */
function blueprint_js_truthy($value): bool {
    return !($value === false || $value === null || $value === 0 || $value === 0.0 || $value === '');
}


/**
 * The Requires Plugins the blueprint does not install before the plugin — read as wordpress.org
 * serves it (normalize_blueprint_like_wporg()): a plugin counts when the shorthand `plugins`
 * names it (it runs before every step) or a step installs it from wordpress.org and activates it
 * (activate defaults to true) before the plugin's own install step. Playground installs no
 * dependencies itself, and WordPress activates no plugin without its active dependencies: each
 * one missing makes the whole preview fail. A blueprint wordpress.org ignores has no preview to
 * fail: none missing.
 *
 * @param string[] $required
 * @return string[] in the order of $required
 */
function blueprint_missing_dependencies(string $contents, string $own_slug, array $required): array {
    $normalized = normalize_blueprint_like_wporg($contents, $own_slug);
    if ($normalized === null || $required === []) {
        return [];
    }
    $installed = [];
    foreach (is_array($normalized['plugins'] ?? null) ? $normalized['plugins'] : [] as $entry) {
        $installed[] = blueprint_shorthand_plugin_slug($entry);
    }
    foreach ($normalized['steps'] ?? [] as $step) {
        if (($step['step'] ?? null) !== 'installPlugin') {
            continue;
        }
        $slug = $step['pluginData']['slug'] ?? null;
        if ($slug === $own_slug) {
            break;
        }
        $options = is_array($step['options'] ?? null) ? $step['options'] : [];
        if (($step['pluginData']['resource'] ?? null) === 'wordpress.org/plugins' && (!array_key_exists('activate', $options) || blueprint_js_truthy($options['activate']))) {
            $installed[] = $slug;
        }
    }
    return array_values(array_diff($required, $installed));
}


/** The dependencies added as Peak Publisher writes them (blueprint_dependency_steps()) right before the plugin's own step, in the given order — nothing removed, nothing moved. */
function add_blueprint_dependencies(\stdClass $blueprint, string $own_slug, array $slugs): \stdClass {
    return insert_blueprint_steps($blueprint, $own_slug, blueprint_dependency_steps($slugs));
}


/**
 * A new Live Preview: the plugins screen (where wordpress.org's own generator lands), logged in
 * (playground.wordpress.net logs every blueprint from a URL in anyway), the dependencies, then
 * the plugin itself — written out, so the file says what happens. No preferredVersions,
 * features or phpExtensionBundles: Playground's defaults are the same choice (latest
 * WordPress, networking on), and written out they would age.
 */
function new_blueprint(string $own_slug, array $required): \stdClass {
    return (object) [
        'landingPage' => '/wp-admin/plugins.php',
        'login' => true,
        'steps' => [ ...blueprint_dependency_steps($required), blueprint_install_step('plugin', $own_slug) ],
    ];
}


/** Whether a decoded blueprint holds an empty object anywhere. */
function blueprint_has_empty_object($value): bool {
    if ($value instanceof \stdClass) {
        $value = (array) $value;
        if ($value === []) {
            return true;
        }
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            if (blueprint_has_empty_object($item)) {
                return true;
            }
        }
    }
    return false;
}


/**
 * What wordpress.org would break, never blocking: an empty object, which its import stores as an
 * empty list — Playground then refuses the blueprint —, and more blueprint files than the ten
 * its import takes, in no fixed order. $contents null: a blueprint that is not there or was not
 * read.
 *
 * @return array<int, array{code:string, message:string}>
 */
function blueprint_warnings(?string $contents, int $blueprint_files): array {
    $warnings = [];
    // The blueprint itself may be {}: wordpress.org adds its steps there, so it stays an object.
    $decoded = $contents === null ? null : decode_blueprint($contents);
    if ($decoded !== null && blueprint_has_empty_object(array_values((array) $decoded))) {
        $warnings[] = [ 'code' => 'empty_object', 'message' => __('The blueprint contains an empty object ({}). wordpress.org stores it as an empty list ([]), and Playground then refuses the blueprint. Remove it or give it a value.', 'peak-publisher') ];
    }
    if ($blueprint_files > 10) {
        $warnings[] = [ 'code' => 'too_many_files', 'message' => sprintf(__('assets/blueprints/ holds %d blueprint files. wordpress.org imports only 10 of them, in no fixed order, so blueprint.json may be left out. Delete the ones you do not need on wordpress.org.', 'peak-publisher'), $blueprint_files) ];
    }
    return $warnings;
}


/** The link that tests a blueprint in WordPress Playground before it is committed: the served text in the URL fragment, encoded once. */
function blueprint_playground_url(string $served): string {
    return 'https://playground.wordpress.net/#' . rawurlencode($served);
}


/**
 * Why wordpress.org ignores the blueprint.json it has — its import skips it silently, and the
 * plugin page has no Live Preview: `too_large` (not read here either), `invalid` (no JSON
 * object, or steps its import cannot read); null when it is used.
 */
function blueprint_ignored_reason(array $file): ?string {
    if ($file['contents'] === null) {
        return 'too_large';
    }
    return validate_blueprint_contents($file['contents']) === true ? null : 'invalid';
}


/**
 * What the Live Preview must install before the plugin: the Requires Plugins of the release
 * wordpress.org distributes — the preview installs the plugin by its slug, the current
 * release. Null without one (wordpress.org distributes trunk, or the pointer is unknown):
 * nothing to compare with.
 *
 * @return array{version:string, slugs:string[]}|null
 */
function live_preview_requirements(\WP_Post $plugin): ?array {
    $release = resolve_current_release($plugin)['release'];
    return $release instanceof \WP_Post ? [ 'version' => (string) $release->post_title, 'slugs' => release_requires_plugins($release) ] : null;
}


/** A release's Requires Plugins as wordpress.org's slugs, from its snapshot. */
function release_requires_plugins(\WP_Post $release): array {
    return requires_plugins_slugs((string) (release_snapshot($release)['plugin_data']['RequiresPlugins'] ?? ''));
}


/**
 * What a commit that makes a release current writes into blueprint.json: the release's Requires
 * Plugins the blueprint does not install before the plugin, added (add_blueprint_dependencies()),
 * the file written as Peak Publisher writes a blueprint; `read` is the file it started from.
 * Null when nothing is missing, when wordpress.org ignores the file anyway, or when the written
 * file would be one wordpress.org ignores (pretty printing can push it over the limit) — it is not
 * touched then, and the detection reports the gap.
 *
 * @param string[] $required
 * @return array{contents:string, added:string[], read:string}|null
 */
function blueprint_dependency_carry(string $contents, string $own_slug, array $required): ?array {
    $missing = blueprint_missing_dependencies($contents, $own_slug, $required);
    $blueprint = decode_blueprint($contents);
    if ($missing === [] || $blueprint === null) {
        return null;
    }
    $carried = encode_blueprint(add_blueprint_dependencies($blueprint, $own_slug, $missing));
    return validate_blueprint_contents($carried) === true ? [ 'contents' => $carried, 'added' => $missing, 'read' => $contents ] : null;
}


/**
 * The fields of a blueprint in effect (blueprint_fields()) — none when there is none, or when it
 * is one wordpress.org would ignore or choke on: a field writes the file anew, and must not keep
 * such a file alive; the JSON is the way to fix it.
 *
 * @param string[] $required
 */
function blueprint_fields_of(?string $contents, string $own_slug, array $required): ?array {
    return $contents === null || validate_blueprint_contents($contents) !== true ? null : blueprint_fields(decode_blueprint($contents), $own_slug, $required);
}


/**
 * A change of the Live Preview decided against the blueprint in effect ($effective, null: none)
 * — the one place every kind of change becomes an entry's contents: create (a new blueprint,
 * none in effect), contents (JSON as typed, on top of the md5 the editor showed as `expected`),
 * landing_page and theme (`value` written into the JSON through its field), dependencies (the
 * missing Requires Plugins added), redirect_skip (the step dropping the activation redirect of
 * the plugin `value` names, when the fields read it as open), delete. Whatever a put would store passes
 * validate_blueprint_contents() first, however it was made. The caller validated the request's
 * values (the path's form, the theme's existence) and holds the lock.
 *
 * @param string[] $required
 * @return array{action:string, contents?:string}|\WP_Error|null null: nothing to change
 */
function decide_blueprint_change(string $kind, ?string $effective, array $params, string $own_slug, array $required): array|\WP_Error|null {
    $changed = new \WP_Error('blueprint_changed', __('The Live Preview was changed elsewhere since you started — load the current version first.', 'peak-publisher'), [ 'status' => 409 ]);
    $contents = null;
    if ($kind === 'delete') {
        return [ 'action' => 'delete' ];
    }
    if ($kind === 'create') {
        if ($effective !== null) {
            return $changed;
        }
        $contents = encode_blueprint(new_blueprint($own_slug, $required));
    } elseif ($kind === 'contents') {
        if ((string) ($params['expected'] ?? '') !== md5($effective ?? '')) {
            return $changed;
        }
        $contents = (string) ($params['contents'] ?? '');
    } else {
        $fields = blueprint_fields_of($effective, $own_slug, $required);
        if ($fields === null) {
            return $effective === null
                ? new \WP_Error('blueprint_not_found', __('There is no Live Preview whose fields could be changed.', 'peak-publisher'), [ 'status' => 404 ])
                : validate_blueprint_contents($effective);
        }
        $blueprint = decode_blueprint($effective);
        $value = (string) ($params['value'] ?? '');
        if ($kind === 'landing_page') {
            if (!$fields['landing_page']['editable']) {
                return $changed;
            }
            $contents = encode_blueprint(set_blueprint_landing_page($blueprint, $value));
        } elseif ($kind === 'theme') {
            if (!$fields['theme']['editable']) {
                return $changed;
            }
            $contents = encode_blueprint(set_blueprint_theme($blueprint, $own_slug, $value));
        } elseif ($kind === 'redirect_skip') {
            $open = array_filter($fields['plugins'], static fn(array $plugin): bool => $plugin['slug'] === $value && $plugin['redirect'] === 'open');
            if ($open === []) {
                return null;
            }
            $contents = encode_blueprint(add_blueprint_redirect_skip($blueprint, $value));
        } else {
            $missing = blueprint_missing_dependencies($effective, $own_slug, $required);
            if ($missing === []) {
                return null;
            }
            $contents = encode_blueprint(add_blueprint_dependencies($blueprint, $own_slug, $missing));
        }
    }
    $valid = validate_blueprint_contents($contents);
    return is_wp_error($valid) ? $valid : [ 'action' => 'put', 'contents' => $contents ];
}
