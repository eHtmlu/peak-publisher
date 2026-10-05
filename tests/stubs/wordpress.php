<?php
/**
 * A minimal in-memory WordPress for the unit tests: the functions the plugin's function
 * modules call, backed by one store that every test resets (Pblsh\Tests\FakeWordPress).
 * Deliberately no mocking framework — the modules need posts, meta, options, hooks and
 * i18n, nothing more. Behavior follows WordPress where the modules depend on it (option
 * semantics, 'any' post status, meta existence); everything else is the simplest thing.
 */

declare(strict_types=1);

namespace Pblsh\Tests {

    final class FakeWordPress {
        /** @var array<int, \WP_Post> */
        public static array $posts = [];
        /** @var array<int, array<string, mixed>> */
        public static array $meta = [];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var array<int, array{hook:string, callback:callable, priority:int}> */
        public static array $actions = [];
        /** @var array<string, callable[]> The filter callbacks per hook, in the order they were added. */
        public static array $filters = [];
        /** @var array<int, array{code:int, body:string}|\WP_Error> Scripted answers of wp_remote_get(), consumed in order. */
        public static array $http_responses = [];
        /** @var array<int, array{url:string, args:array}> Every wp_remote_get() call, in order. */
        public static array $http_requests = [];
        public static int $next_post_id = 1;
        /** The uploads base directory of this test — a fresh temp directory per test. */
        public static string $upload_dir = '';

        public static function reset(): void {
            self::$posts = [];
            self::$meta = [];
            self::$options = [];
            self::$actions = [];
            self::$filters = [];
            self::$http_responses = [];
            self::$http_requests = [];
            self::$next_post_id = 1;
            if (self::$upload_dir !== '' && is_dir(self::$upload_dir)) {
                self::remove_directory(self::$upload_dir);
            }
            self::$upload_dir = sys_get_temp_dir() . '/pblsh-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir(self::$upload_dir, 0777, true);
        }

        private static function remove_directory(string $dir): void {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }
}

namespace {

    use Pblsh\Tests\FakeWordPress;

    const MINUTE_IN_SECONDS = 60;
    const HOUR_IN_SECONDS = 3600;
    const DAY_IN_SECONDS = 86400;

    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_name = '';
        public int $post_parent = 0;
        public string $post_content = '';
        public string $post_date = '2026-01-01 00:00:00';
        public string $post_date_gmt = '2026-01-01 00:00:00';
        public string $post_modified_gmt = '2026-01-01 00:00:00';

        public function __construct(array $fields = []) {
            foreach ($fields as $field => $value) {
                if (property_exists($this, $field)) {
                    $this->$field = $value;
                }
            }
        }
    }

    class WP_Error {
        public function __construct(private string $code = '', private string $message = '', private $data = '') {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }

    class WP_User {
        public int $ID = 0;
        public string $user_login = '';
    }

    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }

    function get_post($post = null): ?WP_Post {
        if ($post instanceof WP_Post) {
            return FakeWordPress::$posts[$post->ID] ?? null;
        }
        $stored = FakeWordPress::$posts[(int) $post] ?? null;
        return $stored === null ? null : clone $stored;
    }

    /**
     * The get_posts() arguments the modules use: post_type (string|array), post_status
     * (string|array; 'any' = every status except the internal ones), post_parent,
     * post_parent__in, name, title, posts_per_page (-1 = all), fields ('ids').
     * Newest first like WordPress' default ordering.
     */
    function get_posts(array $args = []): array {
        $types = (array) ($args['post_type'] ?? 'post');
        $status = $args['post_status'] ?? 'publish';
        $statuses = $status === 'any' ? null : (array) $status;
        $limit = (int) ($args['posts_per_page'] ?? 5);

        $matches = [];
        foreach (FakeWordPress::$posts as $post) {
            if (!in_array($post->post_type, $types, true)) continue;
            if ($statuses === null ? in_array($post->post_status, ['trash', 'auto-draft'], true) : !in_array($post->post_status, $statuses, true)) continue;
            if (isset($args['post_parent']) && $post->post_parent !== (int) $args['post_parent']) continue;
            if (isset($args['post_parent__in']) && !in_array($post->post_parent, array_map('intval', $args['post_parent__in']), true)) continue;
            if (isset($args['name']) && $post->post_name !== (string) $args['name']) continue;
            if (isset($args['title']) && $post->post_title !== (string) $args['title']) continue;
            $matches[] = clone $post;
        }
        usort($matches, static fn(WP_Post $a, WP_Post $b): int => [$b->post_date, $b->ID] <=> [$a->post_date, $a->ID]);
        if ($limit > 0) {
            $matches = array_slice($matches, 0, $limit);
        }
        if (($args['fields'] ?? '') === 'ids') {
            return array_map(static fn(WP_Post $post): int => $post->ID, $matches);
        }
        return $matches;
    }

    function wp_insert_post(array $postarr, bool $wp_error = false): int {
        $post = new WP_Post($postarr);
        $post->ID = FakeWordPress::$next_post_id++;
        FakeWordPress::$posts[$post->ID] = $post;
        foreach ((array) ($postarr['meta_input'] ?? []) as $key => $value) {
            update_post_meta($post->ID, (string) $key, $value);
        }
        return $post->ID;
    }

    function wp_update_post(array $postarr, bool $wp_error = false): int {
        $id = (int) ($postarr['ID'] ?? 0);
        $post = FakeWordPress::$posts[$id] ?? null;
        if ($post === null) {
            return 0;
        }
        foreach ($postarr as $field => $value) {
            if ($field !== 'ID' && property_exists($post, $field)) {
                $post->$field = $value;
            }
        }
        return $id;
    }

    function wp_delete_post(int $id, bool $force = false): ?WP_Post {
        $post = FakeWordPress::$posts[$id] ?? null;
        unset(FakeWordPress::$posts[$id], FakeWordPress::$meta[$id]);
        return $post;
    }

    function get_post_meta(int $id, string $key = '', bool $single = false) {
        if (!array_key_exists($key, FakeWordPress::$meta[$id] ?? [])) {
            return $single ? '' : [];
        }
        $value = FakeWordPress::$meta[$id][$key];
        return $single ? $value : [ $value ];
    }

    function update_post_meta(int $id, string $key, $value): bool {
        FakeWordPress::$meta[$id][$key] = $value;
        return true;
    }

    function delete_post_meta(int $id, string $key): bool {
        unset(FakeWordPress::$meta[$id][$key]);
        return true;
    }

    function metadata_exists(string $type, int $id, string $key): bool {
        return array_key_exists($key, FakeWordPress::$meta[$id] ?? []);
    }

    function get_option(string $name, $default = false) {
        return array_key_exists($name, FakeWordPress::$options) ? FakeWordPress::$options[$name] : $default;
    }

    /** False when the option exists — the atomic add the migration lock relies on. */
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = null): bool {
        if (array_key_exists($name, FakeWordPress::$options)) {
            return false;
        }
        FakeWordPress::$options[$name] = $value;
        return true;
    }

    /** False when the value is unchanged, like WordPress. */
    function update_option(string $name, $value, $autoload = null): bool {
        if (array_key_exists($name, FakeWordPress::$options) && FakeWordPress::$options[$name] === $value) {
            return false;
        }
        FakeWordPress::$options[$name] = $value;
        return true;
    }

    function delete_option(string $name): bool {
        if (!array_key_exists($name, FakeWordPress::$options)) {
            return false;
        }
        unset(FakeWordPress::$options[$name]);
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        FakeWordPress::$actions[] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
        return true;
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        FakeWordPress::$filters[$hook][] = $callback;
        return true;
    }

    /** The hook's filters in the order they were added (priorities are not modeled); without one the value passes through. */
    function apply_filters(string $hook, $value, ...$args) {
        foreach (FakeWordPress::$filters[$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }

    function sanitize_file_name(string $filename): string {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', basename($filename));
    }

    function trailingslashit(string $value): string {
        return rtrim($value, '/\\') . '/';
    }

    /** The uploads directory: the test's temp directory (see FakeWordPress::$upload_dir). */
    function wp_upload_dir(): array {
        return [ 'basedir' => FakeWordPress::$upload_dir, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false ];
    }

    function wp_mkdir_p(string $dir): bool {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }

    function rest_url(string $path = ''): string {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }

    function __(string $text, string $domain = 'default'): string {
        return $text;
    }

    function wp_slash($value) {
        return $value;
    }

    function wp_json_encode($data, int $flags = 0, int $depth = 512) {
        return json_encode($data, $flags, $depth);
    }

    /**
     * The HTTP boundary: answers come from the scripted queue (a WP_Error is returned
     * as is, like a transport failure); every call is recorded for assertions.
     */
    function wp_remote_get(string $url, array $args = []) {
        FakeWordPress::$http_requests[] = [ 'url' => $url, 'args' => $args ];
        $response = array_shift(FakeWordPress::$http_responses);
        if ($response === null) {
            throw new \LogicException('wp_remote_get() called without a scripted response for ' . $url);
        }
        return $response;
    }

    function wp_remote_retrieve_response_code($response) {
        return is_array($response) ? $response['code'] : '';
    }

    function wp_remote_retrieve_body($response): string {
        return is_array($response) ? $response['body'] : '';
    }

    function add_query_arg(array $args, string $url): string {
        return $url . '?' . http_build_query($args);
    }

    function wp_strip_all_tags(string $text): string {
        return trim(strip_tags($text));
    }

    function wp_basename(string $path): string {
        return basename($path);
    }

    function esc_url_raw(string $url): string {
        return $url;
    }

    // What the bundled wordpress.org readme parser calls while parsing (the readme tests
    // read a Stable tag back through it): escaping and tag balancing are pass-throughs,
    // the fake has no users, so every contributor is "ignored".
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function force_balance_tags(string $text): string {
        return $text;
    }

    function wp_kses(string $text, $allowed_html, array $allowed_protocols = []): string {
        return $text;
    }

    function get_user_by(string $field, $value) {
        return false;
    }

    /** The acting user of the request; the tests run as one fixed administrator. */
    function wp_get_current_user(): WP_User {
        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'admin';
        return $user;
    }

    /** The plugin header fields the modules read (the version for the User-Agent). */
    function get_file_data(string $file, array $headers): array {
        $head = (string) file_get_contents($file, false, null, 0, 8192);
        $out = [];
        foreach ($headers as $key => $header) {
            $out[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi', $head, $m) ? trim($m[1]) : '';
        }
        return $out;
    }
}
