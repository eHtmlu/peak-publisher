<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The readme vocabulary of both channels: the one access to wordpress.org's parser
 * (parse_readme_txt()), the one rendering through the_content (render_readme_sections()),
 * the one place a header line is written into a readme (set_readme_header()) or into the
 * plugin file's header comment (set_plugin_file_header()), and Tested up to — the value as
 * wordpress.org's parser reads it, the effective value of a release as its import derives it,
 * the WordPress releases this site knows, the carry at upload and the plan of an edit. Works
 * on contents, not on files: the file I/O lives in the classes and in includes/releases.php —
 * the exceptions are reading a plugin file's headers (read_plugin_headers()) and core's
 * version check (known_wordpress_releases()).
 */


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
 * Sets the header line `$key: $value` of a readme — the one place a readme header is written;
 * set_readme_stable_tag() (the pointer: R1 in analyze, the trunk variant of a deploy, the flip)
 * and Tested up to (the edit, the carry-over at upload) are its consumers. Works on the header
 * block exactly as wordpress.org's parser
 * reads it (Parser::parse_readme_contents()): the title as the parser finds it (a first line
 * that is no known header, a GitHub-style underline, the "Plugin Name" placeholder with the
 * real name below), then the `Key: value` lines, blank lines tolerated, but an unknown key
 * after a blank line already belongs to the short description; the block ends with its last
 * header line. Sets every line of the block that names the header — the key or an alias the
 * parser knows for it (`Tested:` for `Tested up to`), case-insensitive, each line's own spelling
 * kept; the parser keeps the last one it reads. A block without such a line gets one after its
 * last header line when $insert_when_missing allows it, else the content comes back untouched.
 * Keeps every line ending and a UTF-8 BOM as they are, and never touches anything below the
 * block — a "Stable tag:" or "Tested up to:" in the description included.
 *
 * Valid UTF-8 only: the caller guarantees it (analyze records when a readme could not be
 * processed, wporg targets refuse such a readme).
 *
 * @param string $key The header as wordpress.org's parser names it: 'Stable tag', 'Tested up to'.
 * @param bool $insert_when_missing Whether a block without the header gets the line.
 * @throws \InvalidArgumentException On invalid UTF-8 — a caller bug, not a user error.
 */
function set_readme_header(string $content, string $key, string $value, bool $insert_when_missing = true): string {
    if (!is_utf8($content)) {
        throw new \InvalidArgumentException('set_readme_header() needs valid UTF-8');
    }
    $original = $content;
    // The parser strips a BOM before reading; it goes back in front of the result.
    $bom = has_utf8_bom($content) ? "\xEF\xBB\xBF" : '';
    $content = strip_utf8_bom($content);
    $new_header = $key . ': ' . $value;
    $key_lower = strtolower(trim($key));
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
    // The header's parser field: every line whose key maps to it is this header, alias or not.
    $field = $valid_headers[$key_lower] ?? null;
    if ($field === null) {
        throw new \InvalidArgumentException('set_readme_header() knows no readme header "' . $key . '"');
    }
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
        return $insert_when_missing ? $bom . $new_header . "\n" : $original;
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
        $line_key = $header_key($text);
        if ($line_key === null || ($after_blank && !isset($valid_headers[$line_key]))) {
            break;
        }
        if (($valid_headers[$line_key] ?? null) === $field) {
            // The value after the line's own key and colon, whatever the key's spelling.
            $lines[$i]['text'] = preg_replace_callback('/^([^:]*:[ \t]*).*$/u', static fn(array $m): string => $m[1] . $value, $text);
            $replaced = true;
        }
        $last_header = $i;
        $after_blank = false;
    }
    if ($replaced) {
        return $join($lines);
    }
    if (!$insert_when_missing) {
        return $original;
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

/** The Stable tag line of a readme — the one pointer line, through set_readme_header(). */
function set_readme_stable_tag(string $content, string $value): string {
    return set_readme_header($content, 'Stable tag', $value);
}



/**
 * Sets the line `$key: $value` in the plugin file's header comment, where get_file_data()
 * reads it: within the first 8 KB, the first line matching its own pattern — an optional
 * `<?php`, the comment prefix, the key, a colon (case-insensitive). The value ends where
 * WordPress's cleanup ends it: at a comment or PHP closer on the same line, which stays.
 * Only replaces — a file that does not carry the line is returned as it is: the readme is
 * the canonical place of a Tested up to, and a line is never planted into an author's PHP.
 * Line endings are kept; a `Tested up to:` with an empty value carries the line.
 */
function set_plugin_file_header(string $content, string $key, string $value): string {
    $head = substr($content, 0, 8192);
    // The line ends before "\r?\n": a lookahead, so a CR stays where it is.
    $pattern = '/^((?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_replace('/\s+/', '[ \t]+', preg_quote($key, '/')) . ':)[ \t]*[^\r\n]*?((?:[ \t]*(?:\*\/|\?>)[^\r\n]*)?)(?=\r?$)/mi';
    $replaced = preg_replace_callback($pattern, static fn(array $m): string => $m[1] . ' ' . $value . $m[2], $head, 1, $count);
    if (!is_string($replaced) || $count === 0) {
        return $content;
    }
    return $replaced . substr($content, 8192);
}


/**
 * Sanitizes a Tested up to value as wordpress.org's readme parser does: "WordPress" / "WP"
 * stripped, a suffix from "-" on dropped, x.y or x.y.z — anything else is '' (no value). The
 * parser's second rule, the ceiling of stable branch + 0.1, is deliberately not applied here:
 * it is a finding the checklist and the editor report (the client's TestedUpToUtils), never a
 * silent normalization.
 */
function sanitize_tested_up_to(string $value): string {
    $value = trim(str_ireplace([ 'WordPress', 'WP' ], '', trim($value)));
    [ $value ] = explode('-', $value);
    return preg_match('/^\d+\.\d(\.\d+)?$/', $value) ? $value : '';
}


/**
 * The format a new value may take: major.minor only. wordpress.org maps a value to the latest
 * point release of its branch through a table of its real release names (7.1, 7.1.1, 7.1.2,
 * …), compared as strings: a patch digit that names no release — 7.1.0, a name no release
 * ever had, or 7.1.9 before it exists — stays as written and compares below every 7.1.x on
 * sites, "Not tested" included. Major.minor is always a name in that table.
 */
function is_valid_tested_up_to(string $value): bool {
    return (bool) preg_match('/^\d+\.\d$/', $value);
}


/**
 * The effective Tested up to of a release, as wordpress.org derives it: its import lets a
 * well-formed plugin header value (`^[\d.]{3,}$`) override the readme's — a precedence of the
 * import, not a recommendation: the readme stays the documented place. One rule for both
 * channels — the public APIs, the admin payloads and the upload analysis read it here.
 *
 * @param array|null $plugin_data read_plugin_headers() of the main file (key TestedUpTo).
 * @param array|null $readme_content The parsed readme (parse_readme_txt(); key `tested`).
 * @return array{value:string, source:'header'|'readme'|null, header:string, readme:string}
 *         value '' with source null = no statement at all.
 */
function effective_tested_up_to(?array $plugin_data, ?array $readme_content): array {
    $header_raw = trim((string) ($plugin_data['TestedUpTo'] ?? ''));
    $header = preg_match('/^[\d.]{3,}$/', $header_raw) ? sanitize_tested_up_to($header_raw) : '';
    $readme = sanitize_tested_up_to((string) ($readme_content['tested'] ?? ''));
    if ($header !== '') {
        return [ 'value' => $header, 'source' => 'header', 'header' => $header, 'readme' => $readme ];
    }
    if ($readme !== '') {
        return [ 'value' => $readme, 'source' => 'readme', 'header' => '', 'readme' => $readme ];
    }
    return [ 'value' => '', 'source' => null, 'header' => '', 'readme' => '' ];
}


/**
 * The plugin headers of a main file — the one reading place: WordPress's own list
 * (get_plugin_data()) plus `Tested up to` as TestedUpTo, which core does not know but
 * wordpress.org reads. Every stored plugin_data comes through here.
 */
function read_plugin_headers(string $file): array {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data($file, false, false);
    $data['TestedUpTo'] = (string) (get_file_data($file, [ 'TestedUpTo' => 'Tested up to' ])['TestedUpTo'] ?? '');
    return $data;
}


/** The branch (`x.y`) of a WordPress version string, '' when it names none. */
function wordpress_branch(string $version): string {
    return preg_match('/^(\d+\.\d+)/', $version, $m) ? $m[1] : '';
}


/**
 * The WordPress releases this server knows without a request of its own: the running
 * version and the offers of core's version check — the site transient update_core, refreshed
 * twice daily, naming the latest release and, when that differs, the latest of the running
 * branch.
 *
 * @return string[] Full version strings, the running version first.
 */
function known_wordpress_releases(): array {
    $releases = [ function_exists('wp_get_wp_version') ? wp_get_wp_version() : (string) get_bloginfo('version') ];
    $transient = get_site_transient('update_core');
    $updates = is_object($transient) && isset($transient->updates) && is_array($transient->updates) ? $transient->updates : [];
    foreach ($updates as $offer) {
        if (is_object($offer)) {
            $releases[] = (string) ($offer->current ?? '');
        }
    }
    return array_values(array_unique(array_filter($releases, static fn(string $release): bool => $release !== '')));
}


/**
 * The current stable branch of WordPress (`x.y`): the highest branch among the releases this
 * server knows (known_wordpress_releases()) — core's own version check, never a request of
 * Peak Publisher's own. The running version is the floor: without a usable transient, or one
 * older than the site, the site decides.
 */
function wordpress_stable_branch(): string {
    $stable = '';
    foreach (known_wordpress_releases() as $release) {
        $branch = wordpress_branch($release);
        if ($branch !== '' && ($stable === '' || version_compare($branch, $stable, '>'))) {
            $stable = $branch;
        }
    }
    return $stable;
}


/**
 * The Tested up to as the public API serves it to a site. Core compares the value with the
 * site's full version (update-core.php, the plugin details popup): 7.1 is below 7.1.2, so
 * wordpress.org serves every value as the latest point release of its branch. Without the
 * directory's table of releases, the highest version of the value's branch this server
 * knows: the value itself, the asking site's own version and the releases core's version
 * check reports here (known_wordpress_releases()). A site in another branch gets no more
 * than that — the branch decides its comparison anyway. The one place the served value is
 * derived.
 */
function served_tested_up_to(string $value, string $site_version): string {
    $branch = wordpress_branch($value);
    if ($branch === '') {
        return $value;
    }
    $served = $value;
    foreach (array_merge([ $site_version ], known_wordpress_releases()) as $candidate) {
        if (wordpress_branch($candidate) === $branch && version_compare($candidate, $served, '>')) {
            $served = $candidate;
        }
    }
    return $served;
}


/**
 * The carry at upload: the value finalize writes into every existing Tested up to line of the
 * upload when one says something else — the highest of the plugin header, the readme and the
 * predecessor's effective value, versions compared, trimmed to major.minor (the only part
 * that counts: wordpress.org serves every value as the latest point release of its branch —
 * and a patch digit it cannot map, 7.1.0, would stay as written, see is_valid_tested_up_to()),
 * never downward, never when the upload has no value (a missing value is its own signal,
 * confirmed in the checklist). A lower value in one place is a slip, not intent: an edit
 * that reached only one file, a header line that stayed behind the readme. Null when every
 * line already says the value.
 *
 * `from` is 'predecessor' when the predecessor's value wins (judged on major.minor), else
 * null — the checklist lists the upload's places with their values itself (facts.upload);
 * `normalized` whether a patch digit was dropped.
 *
 * A line that exists but is not readable — a plugin header `7.2-RC1`, which wordpress.org's
 * import ignores, or a readme value its parser drops — counts as absent here: the facts
 * carry readable values only, so the carry neither names nor rewrites such a line on its own.
 * It is rewritten, unnamed, only when the carry runs for another reason or by an edit, as both
 * set every existing line (tested_up_to_line_changes()).
 * Kept so on purpose, a rare case: naming it would take the header's raw text and the
 * parser's warning in the facts, and a sentence for each.
 *
 * @param array{value:string, header:string, readme:string} $upload effective_tested_up_to() of the upload.
 * @return ?array{value:string, from:'predecessor'|null, normalized:bool}
 */
function carry_tested_up_to(array $upload, ?string $predecessor_effective): ?array {
    if ($upload['value'] === '') {
        return null;
    }
    // The higher of two values, the first with equal values: nothing moves without a reason.
    $higher = static fn(string $a, string $b): string => $b !== '' && ($a === '' || version_compare($b, $a, '>')) ? $b : $a;
    $own = $higher($upload['header'], $upload['readme']);
    $highest = $higher($own, (string) $predecessor_effective);
    $value = wordpress_branch($highest);
    $changes = array_filter([ $upload['header'], $upload['readme'] ], static fn(string $line): bool => $line !== '' && $line !== $value);
    if ($changes === []) {
        return null;
    }
    $from = wordpress_branch($highest) !== wordpress_branch($own) ? 'predecessor' : null;
    return [ 'value' => $value, 'from' => $from, 'normalized' => $highest !== $value ];
}


/**
 * The new contents of the files that carry a Tested up to — the one rule of every write: the
 * readme's lines and the plugin file's line are set where they exist, each returned only when
 * it changes (so a log names what changed); with $create_in_readme a readme in which nothing
 * changed gets the line inserted, the readme being the documented place. A plugin file line
 * is never planted. The carry at upload and trunk set existing lines only; the edit of a
 * release creates (plan_tested_up_to_edit()).
 *
 * @param string|null $readme The readme's content, null without one.
 * @param string|null $main The main plugin file's content, null without one.
 * @return array{readme?:string, main?:string} The changed contents.
 */
function tested_up_to_line_changes(?string $readme, ?string $main, string $value, bool $create_in_readme): array {
    $changes = [];
    if ($readme !== null && ($changed = set_readme_header($readme, 'Tested up to', $value, false)) !== $readme) {
        $changes['readme'] = $changed;
    }
    if ($main !== null && ($changed = set_plugin_file_header($main, 'Tested up to', $value)) !== $main) {
        $changes['main'] = $changed;
    }
    if ($create_in_readme && $changes === [] && $readme !== null) {
        $changes['readme'] = set_readme_header($readme, 'Tested up to', $value);
    }
    return $changes;
}


/**
 * The plan of an edit of a release's Tested up to on its files — one plan for both channels:
 * the value the client showed must still be the effective one, the new value must differ, and
 * the release must have a place for it (tested_up_to_line_changes() with the readme line
 * created). The caller writes the changed contents — the ZIP's entries self-hosted, a commit
 * on wordpress.org.
 *
 * @param array{value:string} $effective effective_tested_up_to() of the release as it is now.
 * @param string $expected The effective value the client showed.
 * @return array{readme?:string, main?:string}|\WP_Error tested_up_to_changed (409),
 *         invalid_tested_up_to_target (400), tested_up_to_nowhere (409).
 */
function plan_tested_up_to_edit(?string $readme, ?string $main, array $effective, string $value, string $expected): array|\WP_Error {
    if ($effective['value'] !== $expected) {
        return new \WP_Error('tested_up_to_changed', __('Tested up to of this release changed in the meantime. Reload the plugin and check again.', 'peak-publisher'), [ 'status' => 409 ]);
    }
    if ($effective['value'] === $value) {
        return new \WP_Error('invalid_tested_up_to_target', sprintf(__('Tested up to is %s already.', 'peak-publisher'), $value), [ 'status' => 400 ]);
    }
    $changes = tested_up_to_line_changes($readme, $main, $value, true);
    if ($changes === []) {
        // Neither a readme nor the plugin file's line: the locked pencil's case — its tooltip
        // and this message say the same.
        return new \WP_Error('tested_up_to_nowhere', __('Tested up to cannot be added to this release. Upload it again with a value.', 'peak-publisher'), [ 'status' => 409 ]);
    }
    return $changes;
}

