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
        public static int $next_post_id = 1;

        public static function reset(): void {
            self::$posts = [];
            self::$meta = [];
            self::$options = [];
            self::$actions = [];
            self::$next_post_id = 1;
        }
    }
}

namespace {

    use Pblsh\Tests\FakeWordPress;

    const MINUTE_IN_SECONDS = 60;

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

    function __(string $text, string $domain = 'default'): string {
        return $text;
    }

    function wp_slash($value) {
        return $value;
    }

    function wp_json_encode($data, int $flags = 0, int $depth = 512) {
        return json_encode($data, $flags, $depth);
    }
}
