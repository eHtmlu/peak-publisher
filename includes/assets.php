<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * The asset vocabulary of both channels — wordpress.org's rules, applied to the SVN assets/
 * directory of a wporg plugin and to the uploads directory of a self-hosted one: the slots
 * the plugin page consumes, the one filename rule that decides which file belongs to which
 * slot, the byte limits wordpress.org enforces silently, the choice among several candidates
 * of a slot, the manifest (wordpress.org's three post metas, one entry shape), the
 * classification of a directory listing, the validation of an upload and the screenshot
 * captions. Pure functions on arrays and posts, plus the one place that reads and writes the
 * manifest metas (read_asset_manifest() / write_asset_manifest()); LocalAssetStore and
 * WporgAssetSync do the file I/O, AssetManager (classes/AssetManager.php) is the facade the
 * REST and public API call.
 */

// wordpress.org's byte limits per asset type (binary MB): a larger or empty file is silently
// ignored by its import — Peak Publisher refuses it before any write, on both channels.
const PBLSH_ASSET_LIMITS = [ 'icon' => 1048576, 'banner' => 4194304, 'screenshot' => 10485760 ];

// The manifest: wordpress.org's three post metas, on both plugin post types, keyed by filename.
const PBLSH_ASSET_META_KEYS = [ 'icon' => 'assets_icons', 'banner' => 'assets_banners', 'screenshot' => 'assets_screenshots' ];
// The banner's average color for the generated icon — wordpress.org's meta name, both post types.
const PBLSH_ASSETS_COLOR_META = 'assets_banners_color';

const PBLSH_ASSET_RASTER_EXTS = [ 'png', 'jpg', 'gif' ];


/**
 * The MIME type a raster asset carries as svn:mime-type on wordpress.org — what the plugin
 * handbook asks committers to set, so the SVN origin serves the file as an image and SVN
 * treats it as binary. Null for an SVG: to SVN that is text (diffs, merges), a property not
 * starting with text/ would turn it binary for nothing — the origin serves .svg as
 * image/svg+xml by its extension anyway. $ext as the filename rule reads it
 * (classify_asset_filename(): lowercase, jpeg possible) or as an upload was detected.
 */
function asset_mime_type(string $ext): ?string {
    return [ 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif' ][$ext] ?? null;
}


/**
 * The slots the plugin page consumes (128x128/256x256 icons, icon.svg, 772x250/1544x500
 * banners, numbered screenshots) — the one table behind the editor (PblshData.assetSlots),
 * the validation and the classification. banner.svg is imported by wordpress.org but never
 * shown, so its slot exists only behind the filter.
 */
function get_asset_slots(): array {
    $slot = static fn(string $type, $resolution, string $prefix, array $exts, ?int $width, ?int $height, string $label, string $group): array => [
        'type' => $type,
        'resolution' => $resolution,
        'prefix' => $prefix,
        'exts' => $exts,
        'expectedW' => $width,
        'expectedH' => $height,
        'label' => $label,
        'group' => $group,
        'maxBytes' => PBLSH_ASSET_LIMITS[$type],
    ];
    $slots = [
        'icon_svg' => $slot('icon', false, 'icon', [ 'svg' ], null, null, __('SVG', 'peak-publisher'), 'icons'),
        'icon_256' => $slot('icon', '256x256', 'icon-256x256', PBLSH_ASSET_RASTER_EXTS, 256, 256, __('256×256 (Retina)', 'peak-publisher'), 'icons'),
        'icon_128' => $slot('icon', '128x128', 'icon-128x128', PBLSH_ASSET_RASTER_EXTS, 128, 128, __('128×128', 'peak-publisher'), 'icons'),
        'banner_svg' => $slot('banner', false, 'banner', [ 'svg' ], null, null, __('SVG', 'peak-publisher'), 'banners'),
        'banner_hd' => $slot('banner', '1544x500', 'banner-1544x500', PBLSH_ASSET_RASTER_EXTS, 1544, 500, __('1544×500 (Retina)', 'peak-publisher'), 'banners'),
        'banner_sd' => $slot('banner', '772x250', 'banner-772x250', PBLSH_ASSET_RASTER_EXTS, 772, 250, __('772×250', 'peak-publisher'), 'banners'),
        'screenshot' => $slot('screenshot', null, 'screenshot', PBLSH_ASSET_RASTER_EXTS, null, null, __('Screenshot', 'peak-publisher'), 'screenshots'),
    ];
    if (!apply_filters('pblsh_enable_banner_svg', false)) {
        unset($slots['banner_svg']);
    }
    return $slots;
}


/**
 * The definition behind a slot id — a fixed slot's key, or 'screenshot-N' (N ≥ 1) with the
 * number under 'number'. Null for anything else, including the bare 'screenshot'.
 */
function asset_slot_definition(string $slot_id): ?array {
    $slots = get_asset_slots();
    if (preg_match('/^screenshot-(\d+)$/', $slot_id, $m) && (int) $m[1] >= 1) {
        return [ ...$slots['screenshot'], 'number' => (int) $m[1] ];
    }
    return $slot_id !== 'screenshot' && isset($slots[$slot_id]) ? $slots[$slot_id] : null;
}


/** The name Peak Publisher writes for a slot: lowercase, jpg for JPEG, the number without leading zeros. */
function asset_canonical_filename(string $slot_id, string $ext): string {
    $slot = asset_slot_definition($slot_id);
    $ext = strtolower($ext);
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    return (isset($slot['number']) ? 'screenshot-' . $slot['number'] : $slot['prefix']) . '.' . $ext;
}


/**
 * wordpress.org's one filename rule, its regex verbatim (plugin-directory import, flags iu):
 * type, then optionally -resolution, -rtl and -locale in this order, then the extension; an
 * SVG only as {type}.svg. The resolution is normalized like there — non-digits become x, a
 * screenshot number becomes an integer string ('01' → '1', '0' stays '0').
 *
 * @return array{type:string, resolution:string|false, rtl:bool, locale:string, ext:string}|null
 */
function classify_asset_filename(string $filename): ?array {
    if (!preg_match('!^(?P<type>screenshot|banner|icon)(?:-(?P<resolution>\d+(?:\D\d+)?)(?P<rtl>-rtl)?(?:-(?P<locale>[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?))?\.(?P<ext>png|jpg|jpeg|gif)|\.(?P<svg>svg))$!iu', $filename, $m)) {
        return null;
    }
    $type = strtolower($m['type']);
    $resolution = ($m['resolution'] ?? '') !== '' ? $m['resolution'] : false;
    if ($resolution !== false) {
        $resolution = $type === 'screenshot' ? (string) ((int) $resolution) : preg_replace('/[^0-9]/u', 'x', $resolution);
    }
    return [
        'type' => $type,
        'resolution' => $resolution,
        'rtl' => ($m['rtl'] ?? '') !== '',
        'locale' => (string) ($m['locale'] ?? ''),
        'ext' => strtolower(($m['svg'] ?? '') !== '' ? 'svg' : $m['ext']),
    ];
}


/**
 * The slot a classified file belongs to by type and resolution, whatever its locale or RTL
 * suffix: 'icon_128', 'banner_sd', 'screenshot-3'. Null when wordpress.org never consumes the
 * combination (an unused resolution, banner.svg without the filter, screenshot.svg,
 * screenshot-0 — listed by wordpress.org, but it can never have a caption).
 */
function asset_slot_id(array $class, ?array $slots = null): ?string {
    if ($class['type'] === 'screenshot') {
        return $class['resolution'] !== false && (int) $class['resolution'] >= 1 ? 'screenshot-' . $class['resolution'] : null;
    }
    foreach ($slots ?? get_asset_slots() as $slot_id => $slot) {
        if ($slot['type'] === $class['type'] && $slot['resolution'] === $class['resolution']) {
            return $slot_id;
        }
    }
    return null;
}


/**
 * wordpress.org's choice among the files of one slot — find_best_asset() for en_US, LTR: with
 * more than one candidate the locale chain en_US → en → default filters (when none matches,
 * every candidate stays), then non-RTL is preferred, then the extension descending
 * (svg > png > jpg > jpeg > gif) with ties keeping the listing order. A single candidate wins
 * whatever its suffixes — a lone localized banner is shown to every visitor.
 *
 * @param array<int, array{filename:string, locale:string, rtl:bool}> $candidates in listing order
 */
function select_shown_asset(array $candidates): ?array {
    $candidates = array_values($candidates);
    if ($candidates === []) {
        return null;
    }
    if (count($candidates) > 1) {
        foreach ([ 'en_US', 'en', '' ] as $locale) {
            $matching = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['locale'] === $locale));
            if ($matching !== []) {
                $candidates = $matching;
                break;
            }
        }
    }
    if (count($candidates) > 1) {
        $ltr = array_values(array_filter($candidates, static fn(array $candidate): bool => !$candidate['rtl']));
        $candidates = $ltr !== [] ? $ltr : $candidates;
    }
    if (count($candidates) > 1) {
        // usort is stable since PHP 8.0 — equal extensions keep the listing order.
        usort($candidates, static fn(array $a, array $b): int => strtolower(pathinfo($b['filename'], PATHINFO_EXTENSION)) <=> strtolower(pathinfo($a['filename'], PATHINFO_EXTENSION)));
    }
    return $candidates[0];
}


/**
 * Classifies a directory listing the way wordpress.org's import reads it. A slot member has a
 * name the rule assigns to a slot (type and a resolution the plugin page consumes), no locale
 * or RTL suffix and a size within wordpress.org's limit; one member per slot is the winner
 * (select_shown_asset()), the others are variants that go with the next replace or delete.
 * Everything else — localized and RTL variants, unused sizes, SVG banners while the filter is
 * off, empty and oversize files, foreign files and directories — is counted, never managed.
 *
 * @param array<int, array{name:string, type:string, size:int|null, revision:int}> $entries in listing order (bytewise by name)
 */
function classify_asset_listing(array $entries, ?array $slots = null): array {
    $slots = $slots ?? get_asset_slots();
    $candidates = [];
    $other = 0;
    foreach ($entries as $entry) {
        $class = ($entry['type'] ?? 'file') === 'file' ? classify_asset_filename((string) $entry['name']) : null;
        $slot_id = $class === null ? null : asset_slot_id($class, $slots);
        $size = (int) ($entry['size'] ?? 0);
        if ($slot_id === null || $class['locale'] !== '' || $class['rtl'] || $size <= 0 || $size > PBLSH_ASSET_LIMITS[$class['type']]) {
            $other++;
            continue;
        }
        $candidates[$slot_id][] = [ 'filename' => (string) $entry['name'], 'locale' => '', 'rtl' => false, 'entry' => $entry, 'class' => $class ];
    }

    $listing = [];
    foreach ($candidates as $slot_id => $members) {
        $winner = select_shown_asset($members);
        $listing[$slot_id] = [
            'winner' => [
                'filename' => $winner['filename'],
                'revision' => (int) $winner['entry']['revision'],
                'resolution' => $winner['class']['resolution'],
                'filesize' => (int) $winner['entry']['size'],
            ],
            'members' => [ $winner['filename'], ...array_values(array_filter(array_column($members, 'filename'), static fn(string $name): bool => $name !== $winner['filename'])) ],
        ];
    }
    return [ 'slots' => $listing, 'other_files' => $other, 'color_source' => select_banner_color_source($entries) ];
}


/**
 * A10 — the banner whose average color tints the generated icon: the first banner file in
 * listing order that the rule matches and the limit admits, whatever its locale, RTL suffix,
 * size or SVG-ness.
 */
function select_banner_color_source(array $entries): ?array {
    foreach ($entries as $entry) {
        $class = ($entry['type'] ?? 'file') === 'file' ? classify_asset_filename((string) $entry['name']) : null;
        $size = (int) ($entry['size'] ?? 0);
        if ($class !== null && $class['type'] === 'banner' && $size > 0 && $size <= PBLSH_ASSET_LIMITS['banner']) {
            return [ 'filename' => (string) $entry['name'], 'revision' => (int) $entry['revision'] ];
        }
    }
    return null;
}


/**
 * A local assets directory as a listing — bytewise by name like svn ls, the file time as
 * revision. Dot files (the access guard, temp files of a write in progress) and the index.php
 * guard are not assets.
 */
function list_asset_directory(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $entries = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name[0] === '.' || $name === 'index.php') {
            continue;
        }
        $path = $dir . '/' . $name;
        $is_dir = is_dir($path);
        $entries[] = [ 'name' => $name, 'type' => $is_dir ? 'dir' : 'file', 'size' => $is_dir ? null : (int) filesize($path), 'revision' => (int) filemtime($path) ];
    }
    usort($entries, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $entries;
}


/**
 * The manifest of a plugin — slot_id → entry — from wordpress.org's three metas (keyed by
 * filename). The one reader of the asset metas. Re-validation when reading back from
 * persistence: only entries whose filename the rule assigns to a slot of the meta's type count.
 */
function read_asset_manifest(int $plugin_id): array {
    $manifest = [];
    foreach (PBLSH_ASSET_META_KEYS as $type => $meta_key) {
        $stored = get_post_meta($plugin_id, $meta_key, true);
        foreach (is_array($stored) ? $stored : [] as $filename => $entry) {
            $class = is_array($entry) ? classify_asset_filename((string) $filename) : null;
            $slot_id = $class === null || $class['type'] !== $type ? null : asset_slot_id($class);
            if ($slot_id === null) {
                continue;
            }
            $manifest[$slot_id] = [
                'filename' => (string) $filename,
                'revision' => (int) ($entry['revision'] ?? 0),
                'resolution' => $class['resolution'],
                'filesize' => (int) ($entry['filesize'] ?? 0),
                'width' => isset($entry['width']) ? (int) $entry['width'] : null,
                'height' => isset($entry['height']) ? (int) $entry['height'] : null,
            ];
        }
    }
    return $manifest;
}


/** Writes a manifest (slot_id → entry) into the three metas — the one writer of the asset metas. */
function write_asset_manifest(int $plugin_id, array $manifest): void {
    $metas = array_fill_keys(array_keys(PBLSH_ASSET_META_KEYS), []);
    foreach ($manifest as $slot_id => $entry) {
        $metas[asset_slot_definition((string) $slot_id)['type']][$entry['filename']] = $entry;
    }
    foreach (PBLSH_ASSET_META_KEYS as $type => $meta_key) {
        update_post_meta($plugin_id, $meta_key, $metas[$type]);
    }
}


/** The number of a new screenshot: above every occupied position. */
function next_screenshot_number(array $slot_ids): int {
    $max = 0;
    foreach ($slot_ids as $slot_id) {
        if (preg_match('/^screenshot-(\d+)$/', (string) $slot_id, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $max + 1;
}


/** wrong_dimensions when the measured size differs from the slot's expectation; unmeasured files get none. */
function asset_dimension_warnings(array $entry, array $slot): array {
    if ($slot['expectedW'] === null || ($entry['width'] ?? null) === null || ($entry['height'] ?? null) === null) {
        return [];
    }
    if ((int) $entry['width'] === $slot['expectedW'] && (int) $entry['height'] === $slot['expectedH']) {
        return [];
    }
    return [ [
        'code' => 'wrong_dimensions',
        'message' => sprintf(__("Expected: %1\$d×%2\$d px\nFound: %3\$d×%4\$d px", 'peak-publisher'), $slot['expectedW'], $slot['expectedH'], (int) $entry['width'], (int) $entry['height']),
    ] ];
}


/**
 * What an upload must be before a store writes it: an extension the slot allows (jpeg counts
 * as jpg), content of that type (raster by header, SVG by structure), not empty, within the
 * slot's byte limit (wordpress.org's — a larger file would be ignored there silently), and
 * measured; a raster image whose size differs from the slot's expectation is accepted with a
 * wrong_dimensions warning (wordpress.org checks no pixels either).
 *
 * @return array{ext:string, filesize:int, width:?int, height:?int, warnings:array}|\WP_Error
 *         asset_invalid_type (400), asset_empty (400), asset_too_large (413)
 */
function validate_asset_upload(string $path, string $original_name, array $slot): array|\WP_Error {
    $error = static fn(string $code, string $message, int $status): \WP_Error => new \WP_Error($code, $message, [ 'status' => $status ]);

    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    if (!in_array($ext, $slot['exts'], true)) {
        return $error('asset_invalid_type', sprintf(__('File type .%1$s is not allowed for this slot. Allowed types: %2$s.', 'peak-publisher'), $ext, implode(', ', $slot['exts'])), 400);
    }
    $filesize = (int) @filesize($path);
    if ($filesize <= 0) {
        return $error('asset_empty', __('The file is empty.', 'peak-publisher'), 400);
    }
    if ($filesize > $slot['maxBytes']) {
        return $error('asset_too_large', sprintf(__('The file is %1$s — %2$s may be at most %3$s (the wordpress.org limit).', 'peak-publisher'), format_asset_size($filesize), asset_type_plural($slot['type']), format_asset_size($slot['maxBytes'])), 413);
    }
    if ($ext === 'svg') {
        if (!is_valid_svg_file($path)) {
            return $error('asset_invalid_type', __('The file does not appear to be a valid SVG.', 'peak-publisher'), 400);
        }
        return [ 'ext' => 'svg', 'filesize' => $filesize, 'width' => null, 'height' => null, 'warnings' => [] ];
    }
    $size = @getimagesize($path);
    $detected = $size === false ? null : ([ IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif' ][$size[2]] ?? null);
    if ($detected === null) {
        return $error('asset_invalid_type', __('The file does not appear to be a valid image (PNG, JPG, or GIF).', 'peak-publisher'), 400);
    }
    if (!in_array($detected, $slot['exts'], true)) {
        return $error('asset_invalid_type', sprintf(__('Detected image type .%1$s is not allowed for this slot. Allowed types: %2$s.', 'peak-publisher'), $detected, implode(', ', $slot['exts'])), 400);
    }
    $facts = [ 'ext' => $detected, 'filesize' => $filesize, 'width' => (int) $size[0], 'height' => (int) $size[1] ];
    return [ ...$facts, 'warnings' => asset_dimension_warnings($facts, $slot) ];
}


/**
 * '1 MB', '4 MB', '1.1 MB', '820 KB' — binary units, like wordpress.org's limits. Rounded up
 * to one decimal, so a file just over a limit never reads as the limit itself.
 */
function format_asset_size(int $bytes): string {
    if ($bytes >= 1048576) {
        $tenths = (int) ceil($bytes * 10 / 1048576);
        return ($tenths % 10 === 0 ? (string) intdiv($tenths, 10) : number_format($tenths / 10, 1)) . ' MB';
    }
    return (string) (int) ceil($bytes / 1024) . ' KB';
}


function asset_type_plural(string $type): string {
    return match ($type) {
        'icon' => __('icons', 'peak-publisher'),
        'banner' => __('banners', 'peak-publisher'),
        default => __('screenshots', 'peak-publisher'),
    };
}


/**
 * A well-formed SVG document (DOMDocument when available, else a root-element check). Structure
 * only — no script sanitizing, like wordpress.org; the images render inside <img>, which never
 * executes scripts.
 */
function is_valid_svg_file(string $path): bool {
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return false;
    }

    if (class_exists('DOMDocument')) {
        $previous_state = libxml_use_internal_errors(true);
        $doc            = new \DOMDocument();
        $loaded         = $doc->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            return false;
        }

        $root = $doc->documentElement;
        return $root && strtolower($root->localName) === 'svg';
    }

    // Fallback: strip XML declaration and whitespace, then check for <svg root element.
    $trimmed = preg_replace('/^<\?xml[^?]*\?>\s*/si', '', trim($content));
    return (bool) preg_match('/^<svg[\s>]/i', $trimmed);
}


/**
 * The screenshot captions the plugin page shows and where they come from — the readme of the
 * release sites receive (resolve_current_release()): the current release's readme; on
 * wordpress.org, when the pointer names no tag (trunk, a missing tag, none), the trunk readme
 * the marker cache holds (that is what the plugin page shows then); otherwise (the pointer
 * unknown, or self-hosted without a current release) the latest release's readme, marked as a
 * fallback. Captions are bound to positions from 1, like wordpress.org renders them.
 *
 * @return array{captions: array<int, string>, source: array{state: string, version: ?string, fallback: bool}}
 */
function get_screenshot_captions(\WP_Post $plugin): array {
    $current = resolve_current_release($plugin);
    $state = $current['state'];

    if ($state === 'current') {
        $release = $current['release'];
        $fallback = false;
    } elseif (is_wporg_plugin($plugin) && in_array($state, [ 'trunk', 'tag_missing', 'none' ], true)) {
        $cache = wporg_decode_json_object((string) $plugin->post_content);
        $screenshots = is_array($cache['trunk_readme'] ?? null) ? ($cache['trunk_readme']['screenshots'] ?? []) : [];
        return [ 'captions' => asset_captions_from($screenshots), 'source' => [ 'state' => $state, 'version' => null, 'fallback' => false ] ];
    } else {
        $release = $current['reference'];
        $fallback = true;
    }

    $content = $release instanceof \WP_Post ? wporg_decode_json_object((string) $release->post_content) : [];
    return [
        'captions' => asset_captions_from($content['plugin_readme_txt']['content']['screenshots'] ?? []),
        'source' => [ 'state' => $state, 'version' => $release instanceof \WP_Post ? (string) $release->post_title : null, 'fallback' => $fallback ],
    ];
}


/** The parser's screenshots section as int-keyed captions (re-validation of stored data: only scalar entries count). */
function asset_captions_from($screenshots): array {
    $captions = [];
    if (is_array($screenshots)) {
        foreach ($screenshots as $n => $caption) {
            if ((int) $n >= 1 && is_scalar($caption)) {
                $captions[(int) $n] = (string) $caption;
            }
        }
    }
    ksort($captions);
    return $captions;
}


/**
 * The generated icon of a plugin without an icon: wordpress.org's own service for a wporg
 * plugin (byte-identical to the plugin page), Peak Publisher's endpoint (the same generator)
 * for a self-hosted one; the banner color, when valid, makes the pattern's tint and cache key.
 */
function get_geopattern_icon_url(\WP_Post $plugin, string $color): string {
    $suffix = strlen($color) === 6 && strspn($color, 'abcdef0123456789') === 6 ? '_' . $color : '';
    $base = is_wporg_plugin($plugin) ? 'https://s.w.org/plugins/geopattern-icon/' : geopattern_icon_base_url();
    return $base . $plugin->post_name . $suffix . '.svg';
}
