<?php
/**
 * WordPress function stubs for unit testing.
 *
 * Provides minimal implementations of WordPress functions used by
 * the plugin so that unit tests can run without a full WP environment.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

// phpcs:disable WordPress.NamingConventions, WordPress.WP, WordPress.Security, Squiz.Commenting

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

/**
 * Stub store for test state.
 *
 * Tests can manipulate this to control stub behaviour.
 *
 * @var array<string, mixed>
 */
global $altrelay_test_state;
$altrelay_test_state = array(
    'post_meta' => array(),   // attachment_id => array( meta_key => meta_value )
    'options' => array(),
    'transients' => array(),
    'user_caps' => array('upload_files' => true, 'edit_post' => true),
    'post_fields' => array(),   // post_id => array( field => value )
    'is_admin' => false,
    'updated_posts' => array(),   // Track wpdb->update calls for verification.
    'cache_cleaned' => array(),   // Track clean_post_cache calls.
);

/*
 * -------------------------------------------------------------------------
 * WordPress function stubs
 * -------------------------------------------------------------------------
 */

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        global $altrelay_test_state;

        return 'version' === $show ? (string) ($altrelay_test_state['wp_version'] ?? '7.1') : '';
    }
}

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool
    {
        global $altrelay_test_state;

        // Per-object overrides: user_caps_for[capability][object ID] = bool.
        if (isset($args[0]) && is_int($args[0]) && isset($altrelay_test_state['user_caps_for'][$capability][$args[0]])) {
            return (bool) $altrelay_test_state['user_caps_for'][$capability][$args[0]];
        }

        return !empty($altrelay_test_state['user_caps'][$capability]);
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        global $altrelay_test_state;
        if (isset($altrelay_test_state['post_meta'][$post_id][$key])) {
            return $single ? $altrelay_test_state['post_meta'][$post_id][$key] : array($altrelay_test_state['post_meta'][$post_id][$key]);
        }
        return $single ? '' : array();
    }
}

if (!function_exists('update_meta_cache')) {
    /**
     * @param string $meta_type Meta type, e.g. 'post'.
     * @param int[]  $object_ids Object IDs to prime.
     * @return bool
     */
    function update_meta_cache(string $meta_type, array $object_ids): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['meta_cache_primed'][] = array(
            'type' => $meta_type,
            'ids' => $object_ids,
        );
        return true;
    }
}

if (!function_exists('get_post_field')) {
    /**
     * Stub: records the context, because reading post_content in the
     * default 'display' context would run it through display filters.
     */
    function get_post_field(string $field, int $post_id, string $context = 'display'): mixed
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_field_contexts'][] = $context;

        $value = $altrelay_test_state['post_fields'][$post_id][$field] ?? '';

        // What a display filter from another plugin would do to the content.
        if ('raw' !== $context && is_string($value) && !empty($altrelay_test_state['display_filter'])) {
            $value .= $altrelay_test_state['display_filter'];
        }

        return $value;
    }
}

if (!function_exists('clean_post_cache')) {
    function clean_post_cache(int $post_id): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['cache_cleaned'][] = $post_id;

        // What another request may have saved in the meantime.
        if (isset($altrelay_test_state['on_clean_post_cache']) && is_callable($altrelay_test_state['on_clean_post_cache'])) {
            $altrelay_test_state['on_clean_post_cache']($post_id);
        }
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        global $altrelay_test_state;
        return $altrelay_test_state['transients'][$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        global $altrelay_test_state;
        unset($altrelay_test_state['transients'][$transient]);
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
    {
        global $altrelay_test_state;
        $blog = (int) ($altrelay_test_state['current_blog'] ?? 1);
        $altrelay_test_state['option_writes'][] = array($blog, $option);

        // Other sites of a network keep options of their own.
        if (1 !== $blog) {
            $altrelay_test_state['blog_options'][$blog][$option] = $value;
            return true;
        }

        $altrelay_test_state['options'][$option] = $value;
        $altrelay_test_state['option_autoload'][$option] = $autoload;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $altrelay_test_state;
        $blog = (int) ($altrelay_test_state['current_blog'] ?? 1);
        if (1 !== $blog) {
            unset($altrelay_test_state['blog_options'][$blog][$option]);
            return true;
        }
        unset($altrelay_test_state['options'][$option]);
        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Stub apply_filters: runs registered callbacks for the hook.
     *
     * @param string $hook_name Hook name.
     * @param mixed  $value     Value to filter.
     * @param mixed  ...$args   Extra args.
     * @return mixed
     */
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        global $altrelay_test_state;

        // The fixtures use throwaway file names, so matching an image's file
        // against its attachment is opt-in: tests that are about it set
        // strict_image_match and describe the attachment's files.
        if ('altrelay_image_matches_attachment' === $hook_name && empty($altrelay_test_state['strict_image_match']) && empty($altrelay_test_state['filters'][$hook_name])) {
            return true;
        }

        if (empty($altrelay_test_state['filters'][$hook_name])) {
            return $value;
        }

        foreach ($altrelay_test_state['filters'][$hook_name] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (!function_exists('get_post')) {
    /**
     * Stub get_post.
     *
     * @param int|WP_Post|null $post Post ID or object.
     * @return WP_Post|null
     */
    function get_post(mixed $post = null): ?object
    {
        global $altrelay_test_state;

        $post_id = is_int($post) ? $post : 0;

        return $altrelay_test_state['posts'][$post_id] ?? null;
    }
}

if (!function_exists('get_post_type')) {
    /**
     * Stub get_post_type.
     *
     * @param int|WP_Post|null $post Post ID or object.
     * @return string|false
     */
    function get_post_type(mixed $post = null): string|false
    {
        global $altrelay_test_state;

        $post_id = is_int($post) ? $post : 0;

        // Like core, the type comes from the post when a test describes one.
        return $altrelay_test_state['post_types_by_id'][$post_id]
            ?? ($altrelay_test_state['posts'][$post_id]->post_type ?? 'post');
    }
}

if (!function_exists('get_post_types')) {
    /**
     * Stub get_post_types.
     *
     * @param array<string, mixed> $args Query args.
     * @param string               $output Output type.
     * @return array<string, string>
     */
    function get_post_types(array $args = array(), string $output = 'names'): array
    {
        global $altrelay_test_state;

        return $altrelay_test_state['post_types'] ?? array(
            'post' => 'post',
            'page' => 'page',
            'attachment' => 'attachment',
        );
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        global $altrelay_test_state;
        return !empty($altrelay_test_state['is_multisite']);
    }
}

if (!function_exists('get_sites')) {
    /**
     * Stub get_sites.
     *
     * @param array<string, mixed> $args Query args.
     * @return array<int, int>
     */
    function get_sites(array $args = array()): array
    {
        global $altrelay_test_state;
        $altrelay_test_state['get_sites_calls'][] = $args;

        // Paged like the real query: number 0 means all.
        $ids = array_values($altrelay_test_state['site_ids'] ?? array(1));
        $number = isset($args['number']) ? (int) $args['number'] : 0;

        return $number > 0 ? array_slice($ids, (int) ($args['offset'] ?? 0), $number) : $ids;
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $blog_id): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['current_blog'] = $blog_id;
        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['current_blog'] = 1;
        return true;
    }
}

if (!function_exists('ms_is_switched')) {
    function ms_is_switched(): bool
    {
        global $altrelay_test_state;
        return 1 !== ($altrelay_test_state['current_blog'] ?? 1);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        global $altrelay_test_state;
        return $altrelay_test_state['is_admin'];
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        global $altrelay_test_state;
        if (!isset($altrelay_test_state['hooks'])) {
            $altrelay_test_state['hooks'] = array();
        }
        $altrelay_test_state['hooks'][$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        global $altrelay_test_state;
        if (!isset($altrelay_test_state['hooks'])) {
            $altrelay_test_state['hooks'] = array();
        }

        // Core's kses filter is modelled as a flag, see has_filter().
        if ('content_save_pre' === $hook && 'wp_filter_post_kses' === $callback) {
            $altrelay_test_state['kses_active'] = true;
            $altrelay_test_state['kses_calls'][] = 'restore';
            return true;
        }
        if (altrelay_stub_kses_field($hook, $callback)) {
            unset($altrelay_test_state['kses_off'][$hook]);
            return true;
        }
        $altrelay_test_state['hooks'][$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test_nonce_' . $action;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = ''): int|false
    {
        return 1;
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message = '', string $title = '', array $args = array()): void
    {
        throw new \RuntimeException('wp_die: ' . $message);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        global $altrelay_test_state;
        return !empty($altrelay_test_state['doing_ajax']);
    }
}

if (!function_exists('is_network_admin')) {
    function is_network_admin(): bool
    {
        return false;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['redirected_to'] = $location;
        if (!empty($altrelay_test_state['throw_on_redirect'])) {
            throw new \RuntimeException('Redirect: ' . $location);
        }
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback = null, string $icon_url = '', int|float $position = null): string
    {
        global $altrelay_test_state;
        $altrelay_test_state['menu_pages'][] = array(
            'slug' => $menu_slug,
            'capability' => $capability,
        );
        return 'toplevel_page_' . $menu_slug;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, string $media = 'all'): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['enqueued_styles'][] = $handle;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, bool|array $in_footer = false): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['enqueued_scripts'][] = $handle;
    }
}

if (!function_exists('wp_set_script_translations')) {
    function wp_set_script_translations(string $handle, string $domain = 'default', string $path = ''): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['script_translations'][] = array(
            'handle' => $handle,
            'domain' => $domain,
            'path' => $path,
        );
        return true;
    }
}

if (!function_exists('wp_get_attachment_image')) {
    function wp_get_attachment_image(int $attachment_id, $size = 'thumbnail', bool $icon = false, array $attr = array()): string
    {
        return '<img src="test.jpg" alt="' . htmlspecialchars((string) ($attr['alt'] ?? ''), ENT_QUOTES) . '" />';
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file(int $attachment_id): string|false
    {
        return '/uploads/test-image-' . $attachment_id . '.jpg';
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $post_id): string|null
    {
        return 'https://example.com/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string
    {
        return $data;
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff(int $from, int $to = 0): string
    {
        return '5 minutes';
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array|string $key, string $value_or_url = '', string $url = ''): string
    {
        if (is_array($key)) {
            $base = $value_or_url ?: '';
            $sep = str_contains($base, '?') ? '&' : '?';
            return $base . $sep . http_build_query($key);
        }
        return $url . '&' . urlencode($key) . '=' . urlencode($value_or_url);
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        global $altrelay_test_state;

        // An options cache that noted the option as missing before another
        // request added it.
        if (!empty($altrelay_test_state['stale_options'][$option])) {
            return $default;
        }

        $blog = (int) ($altrelay_test_state['current_blog'] ?? 1);
        if (1 !== $blog) {
            return $altrelay_test_state['blog_options'][$blog][$option] ?? $default;
        }
        return $altrelay_test_state['options'][$option] ?? $default;
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, string|false $deprecated = false, string|false $plugin_rel_path = false): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['loaded_textdomain'] = $domain;
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return 'altrelay/altrelay.php';
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        global $altrelay_test_state;
        return !empty($altrelay_test_state['user_logged_in']);
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error stub.
     */
    class WP_Error
    {

        /** @var string */
        public string $code;

        /** @var string */
        public string $message;

        /**
         * Constructor.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         */
        public function __construct(string $code = '', string $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        /**
         * Get the error code.
         *
         * @return string
         */
        public function get_error_code(): string
        {
            return $this->code;
        }

        /**
         * Get the error message.
         *
         * @return string
         */
        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!function_exists('wp_update_post')) {
    /**
     * Stub wp_update_post: records the call and updates the stored content.
     *
     * @param array<string, mixed> $postarr Post data.
     * @param bool                 $wp_error Whether to return WP_Error on failure.
     * @return int|WP_Error
     */
    function wp_update_post(array $postarr = array(), bool $wp_error = false): int|WP_Error
    {
        global $altrelay_test_state;

        $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;

        if ($post_id <= 0) {
            return $wp_error ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
        }

        if (!empty($altrelay_test_state['fail_update_post'])) {
            return $wp_error ? new WP_Error('update_failed', 'Update failed.') : 0;
        }

        // Code that throws before the post is stored, a filter for example.
        if (isset($altrelay_test_state['on_before_save']) && is_callable($altrelay_test_state['on_before_save'])) {
            $altrelay_test_state['on_before_save']($post_id);
        }

        // Like sanitize_post(): every field passes through "pre_{$field}" and
        // "{$field}_save_pre", each in priority order, with core's kses
        // filters among them while they are hooked.
        $save_filters = array(
            'post_title' => 'title_save_pre',
            'post_content' => 'content_save_pre',
            'post_excerpt' => 'excerpt_save_pre',
            'post_content_filtered' => 'content_filtered_save_pre',
        );

        foreach ($save_filters as $field => $hook_name) {
            if (!isset($postarr[$field]) && !isset($altrelay_test_state['post_fields'][$post_id][$field])) {
                continue;
            }

            $value = isset($postarr[$field]) ? (string) $postarr[$field] : addslashes((string) $altrelay_test_state['post_fields'][$post_id][$field]);
            $value = altrelay_stub_run_filters('pre_' . $field, $value);
            $value = altrelay_stub_run_filters($hook_name, $value);

            $altrelay_test_state['post_fields'][$post_id][$field] = stripslashes((string) $value);

            if ('post_content' === $field) {
                $postarr['post_content'] = $altrelay_test_state['post_fields'][$post_id][$field];
            }
        }

        // Like wp_insert_post(): the modified date is set to now, then the
        // row passes through wp_insert_post_data.
        $data = array(
            'post_modified' => '2030-01-01 00:00:00',
            'post_modified_gmt' => '2030-01-01 00:00:00',
        );

        foreach ($altrelay_test_state['hooks']['wp_insert_post_data'] ?? array() as $hook) {
            $data = $hook['callback']($data, $postarr);
        }

        $postarr['post_modified'] = $data['post_modified'];

        $altrelay_test_state['updated_posts'][] = $postarr;

        // What a plugin hooked to save_post would do while kses is in whatever
        // state the caller left it.
        if (isset($altrelay_test_state['on_save_post']) && is_callable($altrelay_test_state['on_save_post'])) {
            $altrelay_test_state['on_save_post']($post_id);
        }

        return $post_id;
    }
}

// The real WP_HTML_Tag_Processor is loaded from tests/fixtures/load-html-api.php.

if (!class_exists('WP_CLI')) {
    /**
     * Minimal WP_CLI stub.
     */
    class WP_CLI
    {

        /**
         * Log a message.
         *
         * @param string $message Message.
         * @return void
         */
        public static function log(string $message): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_log'][] = $message;
        }

        /**
         * Print a raw line.
         *
         * @param string $message Message.
         * @return void
         */
        public static function line(string $message = ''): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_lines'][] = $message;
        }

        /**
         * Report success.
         *
         * @param string $message Message.
         * @return void
         */
        public static function success(string $message): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_success'][] = $message;
        }

        /**
         * Report a warning.
         *
         * @param string $message Message.
         * @return void
         */
        public static function warning(string $message): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_warnings'][] = $message;
        }

        /**
         * Report an error and stop.
         *
         * @param string $message Message.
         * @return void
         */
        public static function error(string $message): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_errors'][] = $message;

            // WP_CLI::error() halts the command. Tests opt in to that with
            // cli_error_throws, so code after an error is not exercised.
            if (!empty($altrelay_test_state['cli_error_throws'])) {
                throw new \RuntimeException('WP_CLI::error: ' . $message);
            }
        }

        /**
         * Register a command.
         *
         * @param string $name    Command name.
         * @param mixed  $handler Handler.
         * @return void
         */
        public static function add_command(string $name, mixed $handler): void
        {
            global $altrelay_test_state;
            $altrelay_test_state['cli_commands'][$name] = $handler;
        }
    }
}

// WP_CLI\Utils lives in its own file because it needs a namespace declaration.
require_once __DIR__ . '/cli-progress-stub.php';
require_once __DIR__ . '/wp-cli-utils-stub.php';

/**
 * Minimal wpdb stub for unit testing.
 */
class wpdb
{

    /** @var string */
    public string $prefix = 'wp_';

    /** @var string */
    public string $posts = 'wp_posts';

    /** @var string */
    public string $postmeta = 'wp_postmeta';

    /** @var string */
    public string $options = 'wp_options';

    /** @var array<int, array<string, string>> */
    public array $stub_col_results = array();

    /** @var array<int, array<string, mixed>> */
    public array $stub_update_log = array();

    /** @var list<string|null> */
    public array $stub_var_results = array();

    /** @var list<array<int, object>> */
    public array $stub_results_queue = array();

    /** @var string */
    public string $last_query = '';

    /** @var int */
    public int $query_count = 0;

    /** @var list<array<int, string>> */
    public array $stub_col_results_queue = array();

    /** @var list<string> Every query passed to get_var, get_results, get_row and get_col. */
    public array $queries = array();

    /** @var string Error of the last query, like the real wpdb. */
    public string $last_error = '';

    /** @var string|null When set, get_col() fails with this error. */
    public ?string $stub_col_error = null;

    /** @var list<array<int|string, mixed>|null> */
    public array $stub_row_queue = array();

    /**
     * Stub query: models the INSERT IGNORE that takes AltRelay's queue lock.
     *
     * @param string $query Query.
     * @return int|bool Rows affected.
     */
    public function query(string $query): int|bool
    {
        global $altrelay_test_state;
        $this->last_query = $query;
        $this->queries[] = $query;

        if (str_starts_with($query, 'INSERT IGNORE') && str_contains($query, "'altrelay_queue_lock'")) {
            $altrelay_test_state['lock_attempts'] = ($altrelay_test_state['lock_attempts'] ?? 0) + 1;

            if (!empty($altrelay_test_state['lock_held'])) {
                return 0;
            }

            $altrelay_test_state['lock_held'] = true;
            $altrelay_test_state['lock_value'] = preg_match("/'altrelay_queue_lock', '([^']*)'/", $query, $value) ? $value[1] : '';
            $altrelay_test_state['lock_taken'] = ($altrelay_test_state['lock_taken'] ?? 0) + 1;
            return 1;
        }

        return 0;
    }

    /**
     * Stub delete: releases AltRelay's queue lock.
     *
     * @param string               $table Table.
     * @param array<string, mixed> $where Where.
     * @return int|false
     */
    public function delete(string $table, array $where): int|false
    {
        global $altrelay_test_state;

        if ($this->options === $table && 'altrelay_queue_lock' === ($where['option_name'] ?? null)) {
            $held = !empty($altrelay_test_state['lock_held']);

            // Only the lock that holds this value, when one is given.
            if ($held && isset($where['option_value']) && isset($altrelay_test_state['lock_value']) && $where['option_value'] !== $altrelay_test_state['lock_value']) {
                return 0;
            }

            $altrelay_test_state['lock_held'] = false;
            unset($altrelay_test_state['lock_value']);
            return $held ? 1 : 0;
        }

        return 0;
    }

    /**
     * Stub prepare.
     *
     * @param string $query  Query string.
     * @param mixed  ...$args Arguments.
     * @return string
     */
    public function prepare(string $query, mixed ...$args): string
    {
        // WordPress accepts either variadic args or a single array of args.
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }

        // One pass over the query, like the real method: a value that happens
        // to contain "%s" or "%i" must not be taken for a placeholder.
        $args = array_values($args);
        $index = 0;

        $prepared = preg_replace_callback(
            '/%[sdif]/',
            static function (array $found) use ($args, &$index): string {
                if (!array_key_exists($index, $args)) {
                    return $found[0];
                }

                $arg = $args[$index++];

                switch ($found[0]) {
                    case '%d':
                        return (string) (int) $arg;
                    case '%f':
                        return (string) (float) $arg;
                    case '%i':
                        return '`' . str_replace('`', '', (string) $arg) . '`';
                    default:
                        return "'" . addslashes((string) $arg) . "'";
                }
            },
            $query
        );

        return (string) $prepared;
    }

    /**
     * Stub get_col.
     *
     * @param string $query SQL query.
     * @return array<int, string>
     */
    public function get_col(string $query): array
    {
        $this->last_query = $query;
        $this->queries[] = $query;
        ++$this->query_count;
        $this->last_error = '';

        if (null !== $this->stub_col_error) {
            $this->last_error = $this->stub_col_error;
            return array();
        }

        if (!empty($this->stub_col_results_queue)) {
            $next = array_shift($this->stub_col_results_queue);
            return is_array($next) ? $next : array();
        }

        return $this->stub_col_results;
    }

    /**
     * Stub get_var.
     *
     * @param string|null $query SQL query.
     * @return string|null
     */
    public function get_var(string $query = null): string|null
    {
        $this->queries[] = (string) $query;

        // AltRelay reads its job list straight from the options table.
        // The database itself, which no stale cache stands in front of.
        if (preg_match("/^SELECT option_value FROM `[^`]+` WHERE option_name = '([^']+)' LIMIT 1$/", (string) $query, $found)) {
            global $altrelay_test_state;
            $stale = $altrelay_test_state['stale_options'] ?? array();
            $altrelay_test_state['stale_options'] = array();
            $value = get_option($found[1], null);
            $altrelay_test_state['stale_options'] = $stale;
            return null === $value ? null : serialize($value);
        }

        return array_shift($this->stub_var_results);
    }

    /**
     * Stub get_results.
     *
     * @param string $query SQL query.
     * @return array<int, object>
     */
    public function get_results(string $query): array
    {
        $this->queries[] = $query;
        $result = array_shift($this->stub_results_queue);
        return is_array($result) ? $result : array();
    }

    /** @var list<bool> Every value passed to suppress_errors(). */
    public array $suppressed = array();

    /**
     * Stub suppress_errors.
     *
     * @param bool $suppress Whether to suppress.
     * @return bool The previous setting.
     */
    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = end($this->suppressed);
        $this->suppressed[] = $suppress;

        return (bool) $previous;
    }

    /**
     * Stub get_row.
     *
     * @param string $query  SQL query.
     * @param string $output Output type.
     * @return array<int|string, mixed>|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $query;
        return array_shift($this->stub_row_queue);
    }

    /**
     * Stub esc_like, matching core's escaping of LIKE wildcards.
     *
     * @param string $text Raw text.
     * @return string
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * Stub update.
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Data to update.
     * @param array<string, mixed> $where Where clause.
     * @return int|false
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $this->stub_update_log[] = array(
            'table' => $table,
            'data' => $data,
            'where' => $where,
        );
        return 1;
    }
}

/*
 * -------------------------------------------------------------------------
 * Stubs added for the queue, kses bypass, admin submenu and CLI work.
 * -------------------------------------------------------------------------
 */

if (!function_exists('has_filter')) {
    function has_filter(string $hook_name, mixed $callback = false): bool|int
    {
        global $altrelay_test_state;

        if ('content_save_pre' === $hook_name) {
            return empty($altrelay_test_state['kses_active']) ? false : 10;
        }

        // The other fields kses_init_filters() hooks, active together with
        // the content filter until one is removed.
        if (altrelay_stub_kses_field($hook_name, $callback)) {
            $initialised = !empty($altrelay_test_state['kses_active']) || !empty($altrelay_test_state['kses_initialised']);

            return !$initialised || !empty($altrelay_test_state['kses_off'][$hook_name]) ? false : 10;
        }

        // Tests opt in to "this callback is hooked" through registered_filters.
        $key = $hook_name . '|' . (is_string($callback) ? $callback : '');

        return empty($altrelay_test_state['registered_filters'][$key]) ? false : 10;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, mixed $callback, int $priority = 10): bool
    {
        global $altrelay_test_state;

        // Core's kses filter is modelled as a flag, see has_filter().
        if ('content_save_pre' === $hook && 'wp_filter_post_kses' === $callback) {
            $altrelay_test_state['kses_active'] = false;
            // The other field filters stay hooked.
            $altrelay_test_state['kses_initialised'] = true;
            $altrelay_test_state['kses_calls'][] = 'remove';
            return true;
        }
        if (altrelay_stub_kses_field($hook, $callback)) {
            $altrelay_test_state['kses_off'][$hook] = true;
            return true;
        }

        foreach ($altrelay_test_state['hooks'][$hook] ?? array() as $index => $registered) {
            if ($registered['callback'] === $callback && $registered['priority'] === $priority) {
                unset($altrelay_test_state['hooks'][$hook][$index]);
                return true;
            }
        }

        return false;
    }
}

if (!class_exists('WP_Post')) {
    /**
     * Stub WP_Post: the fields AltRelay reads.
     */
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_content = '';
        public string $post_title = '';

        /**
         * @param array<string, mixed> $fields Fields to set.
         */
        public function __construct(array $fields = array())
        {
            foreach ($fields as $name => $value) {
                $this->$name = $value;
            }
        }
    }
}

if (!class_exists('WP_Post_Type')) {
    /**
     * Stub WP_Post_Type: a name and its labels.
     */
    class WP_Post_Type
    {
        public string $name;

        public object $labels;

        public function __construct(string $name)
        {
            $this->name = $name;
            $this->labels = (object) array('name' => ucfirst($name) . 's');
        }
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?WP_Post_Type
    {
        return '' === $post_type ? null : new WP_Post_Type($post_type);
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? " selected='selected'" : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!class_exists('WP_Block')) {
    /**
     * Stub WP_Block: only what render_block_data callbacks read.
     */
    class WP_Block
    {
        /** @var array<string, mixed> */
        public array $parsed_block;

        /**
         * @param array<string, mixed> $parsed_block The parsed block.
         */
        public function __construct(array $parsed_block)
        {
            $this->parsed_block = $parsed_block;
        }
    }
}

if (!function_exists('altrelay_stub_run_filters')) {
    /**
     * Run a save filter the way WordPress does: every callback in priority
     * order, core's kses filter at priority 10 while it is hooked.
     */
    function altrelay_stub_run_filters(string $hook, mixed $value): mixed
    {
        global $altrelay_test_state;

        $callbacks = array();
        foreach ($altrelay_test_state['hooks'][$hook] ?? array() as $registered) {
            $callbacks[] = array($registered['priority'], count($callbacks), $registered['callback']);
        }

        $kses = 'title_save_pre' === $hook ? 'wp_filter_kses' : 'wp_filter_post_kses';
        if (str_ends_with($hook, '_save_pre') && is_int(has_filter($hook, $kses))) {
            $callbacks[] = array(10, count($callbacks), $kses);
        }

        usort($callbacks, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        foreach ($callbacks as $callback) {
            $value = $callback[2]($value);
        }

        return $value;
    }
}

if (!function_exists('altrelay_stub_kses_field')) {
    /**
     * Whether a hook and callback are one of the field filters kses hooks,
     * other than the content filter (which has its own flag).
     */
    function altrelay_stub_kses_field(string $hook, mixed $callback): bool
    {
        return ('title_save_pre' === $hook && 'wp_filter_kses' === $callback)
            || (in_array($hook, array('excerpt_save_pre', 'content_filtered_save_pre'), true) && 'wp_filter_post_kses' === $callback);
    }
}

if (!function_exists('wp_filter_kses')) {
    /**
     * Stub: the title filter, which allows only a few inline tags.
     */
    function wp_filter_kses(string $data): string
    {
        return strip_tags($data, '<a><b><em><i><strong><code>');
    }
}

if (!function_exists('wp_filter_post_kses')) {
    /**
     * Stub: strips what kses strips for users without unfiltered_html.
     * Like the real one, it works on slashed data.
     */
    function wp_filter_post_kses(string $data): string
    {
        global $altrelay_test_state;
        $altrelay_test_state['kses_filtered'][] = $data;

        return (string) preg_replace('#<(iframe|script)\b.*?</\1>#is', '', $data);
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('current_filter')) {
    function current_filter(): string|false
    {
        global $altrelay_test_state;
        return $altrelay_test_state['current_filter'] ?? 'the_content';
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook_name): int
    {
        global $altrelay_test_state;
        return (int) ($altrelay_test_state['did_actions'][$hook_name] ?? ('init' === $hook_name ? 1 : 0));
    }
}

if (!function_exists('doing_action')) {
    function doing_action(?string $hook_name = null): bool
    {
        global $altrelay_test_state;
        return !empty($altrelay_test_state['doing_actions'][(string) $hook_name]);
    }
}

if (!function_exists('_prime_post_caches')) {
    /**
     * @param int[] $ids Post IDs.
     */
    function _prime_post_caches(array $ids, bool $update_term_cache = true, bool $update_meta_cache = true): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['primed_posts'][] = $ids;
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id = 0): string|false
    {
        global $altrelay_test_state;

        if (!empty($altrelay_test_state['no_attachment_url'])) {
            return false;
        }

        return 'https://example.com/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

if (!function_exists('is_post_type_viewable')) {
    function is_post_type_viewable(mixed $post_type): bool
    {
        return !in_array($post_type, array('wp_block', 'wp_template', 'wp_template_part'), true);
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status(mixed $post = null): string|false
    {
        global $altrelay_test_state;
        return $altrelay_test_state['post_statuses'][(int) $post] ?? 'publish';
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(mixed $post = 0): string|false
    {
        return 'https://example.com/?p=' . (int) $post;
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link(mixed $post = null): ?string
    {
        return 'https://example.com/?p=' . (int) $post . '&preview=true';
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool
    {
        global $altrelay_test_state;

        $types = $altrelay_test_state['registered_post_types'] ?? array('post', 'page', 'attachment');

        return in_array($post_type, $types, true);
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback = null): string|false
    {
        global $altrelay_test_state;
        $altrelay_test_state['menu_pages'][] = array(
            'parent' => $parent_slug,
            'slug' => $menu_slug,
            'capability' => $capability,
        );
        return 'media_page_' . $menu_slug;
    }
}

if (!function_exists('wp_unschedule_hook')) {
    function wp_unschedule_hook(string $hook, bool $wp_error = false): int|false
    {
        global $altrelay_test_state;
        $altrelay_test_state['cleared_hooks'][] = array('hook' => $hook);
        return 1;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = array()): int|false
    {
        global $altrelay_test_state;

        foreach ($altrelay_test_state['scheduled_events'] ?? array() as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args && ($event['blog'] ?? 1) === get_current_blog_id()) {
                return $event['time'];
            }
        }

        return false;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook, array $args = array()): bool
    {
        global $altrelay_test_state;
        foreach ($altrelay_test_state['scheduled_events'] ?? array() as $index => $event) {
            if ($event['time'] === $timestamp && $event['hook'] === $hook && $event['args'] === $args) {
                unset($altrelay_test_state['scheduled_events'][$index]);
                $altrelay_test_state['scheduled_events'] = array_values($altrelay_test_state['scheduled_events']);
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = array()): int
    {
        global $altrelay_test_state;
        $before = count($altrelay_test_state['scheduled_events'] ?? array());
        $altrelay_test_state['scheduled_events'] = array_values(array_filter($altrelay_test_state['scheduled_events'] ?? array(), static fn(array $event): bool => !($event['hook'] === $hook && $event['args'] === $args)));
        return $before - count($altrelay_test_state['scheduled_events']);
    }
}

if (!function_exists('altrelay_test_reset_statics')) {
    /**
     * Forget what a previous test left in static properties, as a new
     * request would.
     */
    function altrelay_test_reset_statics(): void
    {
        if (class_exists('AltRelay_Queue')) {
            (new ReflectionProperty(AltRelay_Queue::class, 'buffer'))->setValue(null, array());
            (new ReflectionProperty(AltRelay_Queue::class, 'flush_hooked'))->setValue(null, false);
        }
        if (class_exists('AltRelay_Sync')) {
            (new ReflectionProperty(AltRelay_Sync::class, 'activated_per_site'))->setValue(null, false);
        }
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(int|string $key, string $group = ''): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['cache_deleted'][] = $group . ':' . $key;
        return true;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = array()): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['scheduled_events'][] = array(
            'time' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'blog' => get_current_blog_id(),
        );
        return true;
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$args): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['actions'][] = array('hook' => $hook_name, 'args' => $args);

        // Listeners a test hands in.
        foreach ($altrelay_test_state['action_callbacks'][$hook_name] ?? array() as $callback) {
            $callback(...$args);
        }
    }
}

if (!function_exists('as_enqueue_async_action')) {
    /**
     * Stub: like the real one, returns 0 when the job could not be stored.
     */
    function as_enqueue_async_action(string $hook, array $args = array(), string $group = ''): int
    {
        global $altrelay_test_state;

        if (!empty($altrelay_test_state['as_refuses'])) {
            return 0;
        }

        $altrelay_test_state['as_actions'][] = array(
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        );
        return count($altrelay_test_state['as_actions']);
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions(string $hook, array $args = array(), string $group = ''): int
    {
        global $altrelay_test_state;
        $altrelay_test_state['as_unscheduled'][] = array(
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        );
        return 1;
    }
}

if (!function_exists('as_get_scheduled_actions')) {
    /**
     * @param array<string, mixed> $query
     * @return array<int, object>
     */
    function as_get_scheduled_actions(array $query = array(), string $return_format = 'OBJECT'): array
    {
        global $altrelay_test_state;
        $altrelay_test_state['as_queries'][] = $query;

        // as_pending / as_failed hold lists of job arguments, by status.
        $status = isset($query['status']) ? (string) $query['status'] : 'pending';
        $all = array_values($altrelay_test_state['as_' . $status] ?? array());

        // The real store matches arguments exactly.
        if (isset($query['args'])) {
            $all = array_values(array_filter($all, static fn($args) => $args === $query['args']));
        }

        // Honour paging like the real store, so paging loops terminate.
        $per_page = isset($query['per_page']) ? (int) $query['per_page'] : 5;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;

        return $per_page < 0 ? array_slice($all, $offset) : array_slice($all, $offset, $per_page);
    }
}

if (!function_exists('_get_cron_array')) {
    /**
     * @return array<int, array<string, array<string, array{args: array<int, mixed>}>>>|false
     */
    function _get_cron_array(): array|false
    {
        global $altrelay_test_state;
        return $altrelay_test_state['cron_array'] ?? array();
    }
}


/*
 * -------------------------------------------------------------------------
 * Stubs added for decorative images, the dashboard review and CLI review.
 * -------------------------------------------------------------------------
 */

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        global $altrelay_test_state;
        return (int) ($altrelay_test_state['current_user_id'] ?? 0);
    }
}

if (!function_exists('user_can')) {
    /**
     * Stub: user_can_map[user ID][capability][object ID] = bool, default true.
     */
    function user_can(int $user_id, string $capability, mixed ...$args): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_can_calls'][] = array($user_id, $capability, $args[0] ?? null);

        $object_id = isset($args[0]) && is_int($args[0]) ? $args[0] : 0;

        return (bool) ($altrelay_test_state['user_can_map'][$user_id][$capability][$object_id] ?? true);
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        global $altrelay_test_state;
        return $altrelay_test_state['object_cache'][$group][$key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['object_cache'][$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_get_last_changed')) {
    function wp_cache_get_last_changed(string $group): string
    {
        global $altrelay_test_state;
        return (string) ($altrelay_test_state['last_changed'][$group] ?? '1');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(mixed $post = 0): string
    {
        global $altrelay_test_state;
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
        return (string) ($altrelay_test_state['post_fields'][$post_id]['post_title'] ?? ('Post ' . $post_id));
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '" />';
        if ($display) {
            echo $field;
        }
        return $field;
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $actionurl, string $action = '-1', string $name = '_wpnonce'): string
    {
        return $actionurl . (str_contains($actionurl, '?') ? '&' : '?') . $name . '=nonce-' . $action;
    }
}

if (!function_exists('check_admin_referer')) {
    /**
     * Stub: valid unless the test sets nonce_valid to false, mirroring
     * WordPress, which dies on an invalid nonce.
     */
    function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce'): int
    {
        global $altrelay_test_state;
        $altrelay_test_state['checked_referers'][] = $action;

        if (isset($altrelay_test_state['nonce_valid']) && false === $altrelay_test_state['nonce_valid']) {
            throw new \RuntimeException('wp_die: nonce');
        }

        return 1;
    }
}

if (!function_exists('wp_get_referer')) {
    function wp_get_referer(): string|false
    {
        global $altrelay_test_state;
        return $altrelay_test_state['referer'] ?? false;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
    {
        global $altrelay_test_state;
        if (array_key_exists($option, $altrelay_test_state['options'] ?? array())) {
            return false;
        }
        $altrelay_test_state['options'][$option] = $value;
        $altrelay_test_state['option_autoload'][$option] = $autoload;
        return true;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|bool
    {
        global $altrelay_test_state;
        // Like update_metadata(): the value arrives slashed and is unslashed.
        $meta_value = wp_unslash($meta_value);
        $altrelay_test_state['post_meta'][$post_id][$meta_key] = $meta_value;
        $altrelay_test_state['meta_updates'][] = array($post_id, $meta_key, $meta_value);
        return true;
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        global $altrelay_test_state;
        $id = $altrelay_test_state['screen_id'] ?? null;
        return null === $id ? null : (object) array('id' => $id);
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['inline_scripts'][] = array(
            'handle' => $handle,
            'data' => $data,
            'position' => $position,
        );
        return true;
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        global $altrelay_test_state;
        return (int) ($altrelay_test_state['current_blog'] ?? 1);
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option(string $option, mixed $default = false): mixed
    {
        global $altrelay_test_state;
        return $altrelay_test_state['site_options'][$option] ?? $default;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
    {
        global $altrelay_test_state;
        $had = isset($altrelay_test_state['post_meta'][$post_id][$meta_key]);
        unset($altrelay_test_state['post_meta'][$post_id][$meta_key]);
        return $had;
    }
}

if (!function_exists('delete_post_meta_by_key')) {
    function delete_post_meta_by_key(string $meta_key): bool
    {
        global $altrelay_test_state;
        $altrelay_test_state['deleted_meta_keys'][] = $meta_key;
        foreach (array_keys($altrelay_test_state['post_meta'] ?? array()) as $post_id) {
            unset($altrelay_test_state['post_meta'][$post_id][$meta_key]);
        }
        return true;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        if (is_string($data) && (str_starts_with($data, 'a:') || str_starts_with($data, 's:') || str_starts_with($data, 'i:') || 'N;' === $data || str_starts_with($data, 'b:'))) {
            return unserialize($data, array('allowed_classes' => false));
        }

        return $data;
    }
}

if (!function_exists('wp_rand')) {
    /**
     * Stub wp_rand.
     *
     * @param int $min Minimum.
     * @param int $max Maximum.
     * @return int
     */
    function wp_rand(int $min = 0, int $max = 0): int
    {
        return random_int($min, $max > 0 ? $max : PHP_INT_MAX);
    }
}

if (!function_exists('wp_is_post_revision')) {
    /**
     * Stub wp_is_post_revision.
     *
     * @param int $post_id Post ID.
     * @return int|false
     */
    function wp_is_post_revision(int $post_id): int|false
    {
        global $altrelay_test_state;
        return empty($altrelay_test_state['revisions'][$post_id]) ? false : 1;
    }
}
