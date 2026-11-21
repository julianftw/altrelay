<?php
/**
 * Unit tests for the main plugin bootstrap (altrelay.php).
 *
 * Tests activation, deactivation, init, editor asset enqueuing,
 * and textdomain loading.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class BootstrapTest
 */
class BootstrapTest extends TestCase
{

    /**
     * Set up test fixtures.
     */
    protected function set_up(): void
    {
        parent::set_up();
        altrelay_test_reset_statics();

        global $altrelay_test_state;
        $altrelay_test_state = array(
            'post_meta' => array(),
            'options' => array(),
            'transients' => array(),
            'user_caps' => array('upload_files' => true, 'edit_post' => true),
            'post_fields' => array(),
            'is_admin' => false,
            'updated_posts' => array(),
            'cache_cleaned' => array(),
            'hooks' => array(),
            'menu_pages' => array(),
            'enqueued_styles' => array(),
            'enqueued_scripts' => array(),
            'redirected_to' => null,
            'loaded_textdomain' => null,
            'cleared_hooks' => array(),
            'as_unscheduled' => array(),
        );

        $GLOBALS['wpdb'] = new wpdb();

        // The components are created once per request; each test is one.
        AltRelay_Plugin::reset();
    }

    /**
     * Test that activation stores the version and queues the welcome notice,
     * without autoloading the flag.
     */
    public function test_activate_stores_version_and_welcome_flag(): void
    {
        global $altrelay_test_state;

        altrelay_activate();

        $this->assertSame('1.0.0', $altrelay_test_state['options']['altrelay_version']);
        $this->assertSame('1', $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION]);
        $this->assertFalse($altrelay_test_state['option_autoload'][AltRelay_Admin::WELCOME_OPTION]);
        $this->assertArrayNotHasKey('altrelay_activation_redirect', $altrelay_test_state['transients']);
    }

    /**
     * Test that deactivation cancels queued work on both back ends.
     */
    public function test_deactivate_cancels_queue(): void
    {
        global $altrelay_test_state;

        altrelay_deactivate();

        $this->assertSame(AltRelay_Queue::CRON_HOOK, $altrelay_test_state['cleared_hooks'][0]['hook']);
        $this->assertSame(AltRelay_Queue::ACTION_HOOK, $altrelay_test_state['as_unscheduled'][0]['hook']);
    }

    /**
     * Test that altrelay_init registers sync and frontend hooks.
     */
    public function test_init_registers_sync_and_frontend_hooks(): void
    {
        global $altrelay_test_state;

        altrelay_init();

        // Sync hooks.
        $this->assertArrayHasKey('updated_postmeta', $altrelay_test_state['hooks']);
        $this->assertArrayHasKey('added_post_meta', $altrelay_test_state['hooks']);

        // Frontend hooks: per block, plus priming and the no-do_blocks fallback.
        $this->assertArrayHasKey('render_block', $altrelay_test_state['hooks']);
        $this->assertArrayHasKey('the_content', $altrelay_test_state['hooks']);

        // Queue workers.
        $this->assertArrayHasKey(AltRelay_Queue::ACTION_HOOK, $altrelay_test_state['hooks']);
        $this->assertArrayHasKey(AltRelay_Queue::CRON_HOOK, $altrelay_test_state['hooks']);
    }

    /**
     * Test that altrelay_init registers admin hooks when is_admin is true.
     */
    public function test_init_registers_admin_hooks_when_admin(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['is_admin'] = true;

        altrelay_init();

        $this->assertArrayHasKey('admin_menu', $altrelay_test_state['hooks']);
        $this->assertArrayHasKey('admin_notices', $altrelay_test_state['hooks']);
        $this->assertArrayHasKey('admin_enqueue_scripts', $altrelay_test_state['hooks']);
        $this->assertArrayHasKey('admin_post_altrelay_review', $altrelay_test_state['hooks']);
        $this->assertArrayNotHasKey('admin_init', $altrelay_test_state['hooks'], 'The activation redirect is gone.');
    }

    /**
     * Test that altrelay_init does not register admin hooks on the frontend.
     */
    public function test_init_skips_admin_hooks_on_frontend(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['is_admin'] = false;

        altrelay_init();

        $this->assertArrayNotHasKey('admin_menu', $altrelay_test_state['hooks']);
    }

    /**
     * Test that altrelay_enqueue_editor_assets enqueues the editor script.
     */
    public function test_enqueue_editor_assets(): void
    {
        global $altrelay_test_state;

        $js_path = ALTRELAY_PLUGIN_DIR . 'assets/js/editor-sync.js';
        if (!file_exists($js_path)) {
            $this->markTestSkipped('editor-sync.js not found at ' . $js_path);
        }

        altrelay_enqueue_editor_assets();

        $this->assertContains('altrelay-editor-sync', $altrelay_test_state['enqueued_scripts'] ?? array());
    }

    /**
     * Test that the editor script gets its translations wired up.
     *
     * Without wp_set_script_translations() the editor notice stays English
     * even when a translation for the plugin is installed.
     */
    public function test_editor_script_translations_are_registered(): void
    {
        global $altrelay_test_state;

        $js_path = ALTRELAY_PLUGIN_DIR . 'assets/js/editor-sync.js';
        if (!file_exists($js_path)) {
            $this->markTestSkipped('editor-sync.js not found at ' . $js_path);
        }

        altrelay_enqueue_editor_assets();

        $this->assertNotEmpty($altrelay_test_state['script_translations'] ?? array());

        $call = $altrelay_test_state['script_translations'][0];
        $this->assertSame('altrelay-editor-sync', $call['handle']);
        $this->assertSame('altrelay', $call['domain']);
        $this->assertStringEndsWith('languages', rtrim($call['path'], '/\\'));
    }

    /**
     * Test that the editor script declares exactly the packages it uses.
     *
     * A missing dependency breaks the script when WordPress loads it before
     * the package; an extra one loads code for nothing. Both directions are
     * checked against the script source.
     *
     * WordPress.org-hosted plugins get translations from translate.wordpress.org,
     * so load_plugin_textdomain() is intentionally not called.
     */
    public function test_editor_script_dependencies_match_usage(): void
    {
        $php = (string) file_get_contents(ALTRELAY_PLUGIN_DIR . 'includes/altrelay-functions.php');
        $js = (string) file_get_contents(ALTRELAY_PLUGIN_DIR . 'assets/js/editor-sync.js');

        $this->assertSame(1, preg_match("/'altrelay-editor-sync',\s*ALTRELAY_PLUGIN_URL[^;]*?array\(([^)]*)\)/s", $php, $match));
        preg_match_all("/'wp-([a-z-]+)'/", $match[1], $declared);

        $to_global = static fn(string $handle): string => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $handle))));
        $declared_globals = array_map($to_global, $declared[1]);

        preg_match_all('/\bwp\.([a-zA-Z]+)\b/', $js, $used);
        $used_globals = $used[1];

        // The core/notices store is used through wp.data, but only exists when
        // the wp-notices package is loaded, so it counts as a use.
        if (str_contains($js, '"core/notices"')) {
            $used_globals[] = 'notices';
        }

        // wp.customize is the Customizer's own object, not a package. The
        // script only touches it where it exists.
        $used_globals = array_values(array_diff(array_unique($used_globals), array('customize')));

        sort($declared_globals);
        sort($used_globals);

        $this->assertSame($used_globals, $declared_globals);
        $this->assertStringNotContainsString('load_plugin_textdomain', $php);
        $this->assertStringContainsString('Requires PHP: 8.2', (string) file_get_contents(ALTRELAY_PLUGIN_DIR . 'altrelay.php'));
    }

    /**
     * Test that the editor receives the marker and the site's empty-alt rule.
     */
    public function test_editor_settings_are_printed(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_treat_empty_alt_as_decorative'] = array(
            static fn(): bool => true,
        );

        altrelay_enqueue_editor_assets();

        $inline = $altrelay_test_state['inline_scripts'][0];
        $this->assertSame('altrelay-editor-sync', $inline['handle']);
        $this->assertSame('before', $inline['position']);
        $this->assertSame('window.altrelayEditor = {"marker":"altrelay-decorative","emptyIsDecorative":true,"coreDecorative":false};', $inline['data']);
    }

    /**
     * Test the minimum WordPress and PHP versions.
     */
    public function test_meets_requirements(): void
    {
        $this->assertTrue(altrelay_meets_requirements('6.7', '8.2.0'));
        $this->assertTrue(altrelay_meets_requirements('7.1.4', '8.4.1'));
        $this->assertTrue(altrelay_meets_requirements('6.7-RC1', '8.2.0'));
        $this->assertFalse(altrelay_meets_requirements('6.6.2', '8.4.1'));
        $this->assertFalse(altrelay_meets_requirements('6.2.15', '8.2.0'));
        $this->assertFalse(altrelay_meets_requirements('7.1', '8.1.30'));
    }

    /**
     * Test that the running versions are used by default.
     */
    public function test_meets_requirements_defaults_to_running_versions(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['wp_version'] = '7.1';
        $this->assertTrue(altrelay_meets_requirements());

        $altrelay_test_state['wp_version'] = '6.2.15';
        $this->assertFalse(altrelay_meets_requirements());
    }

    /**
     * Test that the runtime check matches the headers WordPress enforces.
     */
    public function test_minimum_versions_match_headers(): void
    {
        foreach (array('altrelay.php', 'readme.txt') as $file) {
            $source = (string) file_get_contents(ALTRELAY_PLUGIN_DIR . $file);

            $this->assertMatchesRegularExpression('/^\s*\*?\s*Requires at least:\s*' . preg_quote(ALTRELAY_MIN_WP, '/') . '\s*$/m', $source, $file);
            $this->assertMatchesRegularExpression('/^\s*\*?\s*Requires PHP:\s*' . preg_quote(ALTRELAY_MIN_PHP, '/') . '\s*$/m', $source, $file);
        }
    }

    /**
     * Test that administrators learn why the plugin is dormant.
     */
    public function test_requirements_notice_names_versions(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['wp_version'] = '6.2.15';
        $altrelay_test_state['user_caps'] = array('activate_plugins' => true);

        ob_start();
        altrelay_requirements_notice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('requires WordPress 6.7 and PHP 8.2 or newer', $output);
        $this->assertStringContainsString('This site runs WordPress 6.2.15 on PHP ' . PHP_VERSION, $output);
    }

    /**
     * Test that the notice is hidden from users who cannot manage plugins.
     */
    public function test_requirements_notice_hidden_without_capability(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps'] = array();

        ob_start();
        altrelay_requirements_notice();

        $this->assertSame('', ob_get_clean());
    }

    /**
     * Test that the plugin header does not contain an invalid "Tested up to" field.
     */
    public function test_plugin_header_has_no_tested_up_to(): void
    {
        $source = file_get_contents(ALTRELAY_PLUGIN_DIR . 'altrelay.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Tested up to:', $source);
    }

    /**
     * Test that activation creates the "review first" switch, switched off
     * and autoloaded, without overwriting a choice that was already made.
     */
    public function test_activate_creates_the_review_first_option(): void
    {
        global $altrelay_test_state;

        altrelay_activate();

        $this->assertSame('0', $altrelay_test_state['options'][AltRelay_Content::REVIEW_FIRST_OPTION]);
        $this->assertTrue($altrelay_test_state['option_autoload'][AltRelay_Content::REVIEW_FIRST_OPTION], 'Read on the frontend, so it must not cost a query.');

        $altrelay_test_state['options'][AltRelay_Content::REVIEW_FIRST_OPTION] = '1';

        altrelay_activate();

        $this->assertSame('1', $altrelay_test_state['options'][AltRelay_Content::REVIEW_FIRST_OPTION]);
    }

    /**
     * Test that the upgrade routine creates the option on sites where the
     * activation hook never ran, and only acts when the version changed.
     */
    public function test_upgrade_creates_default_options_once(): void
    {
        global $altrelay_test_state;

        altrelay_maybe_upgrade();

        $this->assertSame('0', $altrelay_test_state['options'][AltRelay_Content::REVIEW_FIRST_OPTION]);
        $this->assertSame('1.0.0', $altrelay_test_state['options']['altrelay_version']);
        $this->assertSame(array(false, '1.0.0'), $altrelay_test_state['actions'][0]['args']);

        unset($altrelay_test_state['options'][AltRelay_Content::REVIEW_FIRST_OPTION]);
        $altrelay_test_state['actions'] = array();

        altrelay_maybe_upgrade();

        $this->assertArrayNotHasKey(AltRelay_Content::REVIEW_FIRST_OPTION, $altrelay_test_state['options']);
        $this->assertEmpty($altrelay_test_state['actions']);
    }

    /**
     * Regression (audit, Bootstrap): a network-wide deactivation clears the
     * queue of every site, not only the current one.
     */
    /**
     * Regression (sixth audit): network deactivation read every site of the
     * network at once. It reads them a page of 100 at a time, and still
     * reaches every one.
     */
    public function test_network_deactivation_reads_the_sites_page_by_page(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['is_multisite'] = true;
        $altrelay_test_state['site_ids'] = range(1, 250);

        altrelay_deactivate(true);

        $this->assertCount(250, $altrelay_test_state['cleared_hooks']);
        $this->assertSame(array(0, 100, 200), array_column($altrelay_test_state['get_sites_calls'], 'offset'));
        $this->assertSame(array(100, 100, 100), array_column($altrelay_test_state['get_sites_calls'], 'number'));
    }

    public function test_network_deactivation_clears_every_site(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['is_multisite'] = true;
        $altrelay_test_state['site_ids'] = array(1, 2, 3);

        altrelay_deactivate(true);

        $this->assertCount(3, $altrelay_test_state['cleared_hooks']);
        $this->assertCount(3, $altrelay_test_state['as_unscheduled']);
        $this->assertSame(1, $altrelay_test_state['current_blog'], 'The current site is restored.');

        // A site Action Scheduler never ran on has no tables for it. While
        // another site is switched to, the lookup is silenced and the
        // previous setting put back; the current site is left as it is.
        $this->assertSame(array(true, false, true, false), $GLOBALS['wpdb']->suppressed);
    }

    /**
     * Test that deactivating on one site of a network, or a network-wide
     * flag on a single site, only touches the current site.
     */
    public function test_single_site_deactivation_stays_on_the_site(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['is_multisite'] = true;
        $altrelay_test_state['site_ids'] = array(1, 2, 3);

        altrelay_deactivate(false);

        $this->assertCount(1, $altrelay_test_state['cleared_hooks']);

        $altrelay_test_state['is_multisite'] = false;
        $altrelay_test_state['cleared_hooks'] = array();

        altrelay_deactivate(true);

        $this->assertCount(1, $altrelay_test_state['cleared_hooks']);
        $this->assertArrayNotHasKey('current_blog', $altrelay_test_state);
    }

    /**
     * Regression (audit, Bootstrap): the component instances are exposed,
     * so other code can remove the hooks AltRelay registered.
     */
    public function test_components_are_exposed(): void
    {
        global $altrelay_test_state;

        altrelay_init();

        $this->assertInstanceOf(AltRelay_Sync::class, altrelay('sync'));
        $this->assertInstanceOf(AltRelay_Queue::class, altrelay('queue'));
        $this->assertInstanceOf(AltRelay_Report::class, altrelay('report'));
        $this->assertInstanceOf(AltRelay_Frontend::class, altrelay('frontend'));
        $this->assertNull(altrelay('admin'), 'The dashboard only exists in the admin.');
        $this->assertNull(altrelay('nonsense'));
        $this->assertSame(altrelay('frontend'), altrelay('frontend'));

        // The exposed instance is the one that was hooked.
        $this->assertSame(altrelay('frontend'), $altrelay_test_state['hooks']['render_block'][0]['callback'][0]);
        $this->assertSame(altrelay('sync'), $altrelay_test_state['hooks']['updated_postmeta'][0]['callback'][0]);
        $this->assertSame(altrelay('queue'), $altrelay_test_state['hooks'][AltRelay_Queue::ACTION_HOOK][0]['callback'][0]);
    }

    /**
     * Test that the admin component is exposed in the admin.
     */
    public function test_admin_component_is_exposed_in_the_admin(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['is_admin'] = true;

        $this->assertInstanceOf(AltRelay_Admin::class, altrelay('admin'));
        $this->assertCount(5, AltRelay_Plugin::components());
    }

    /**
     * Test that the hooks which keep the cached numbers fresh are registered
     * on every request, not only in the admin.
     */
    public function test_init_registers_cache_invalidation_everywhere(): void
    {
        global $altrelay_test_state;

        altrelay_init();

        foreach (array('post_updated', 'wp_insert_post', 'before_delete_post', 'add_attachment', 'delete_attachment', 'deleted_post_meta') as $hook) {
            $this->assertArrayHasKey($hook, $altrelay_test_state['hooks']);
        }
    }

    /**
     * Test that every documented @since tag names a released version.
     *
     * Regression (audit, Documentation): the code had no @since tags.
     */
    public function test_public_api_is_tagged_with_since(): void
    {
        $files = array_merge(
            array(ALTRELAY_PLUGIN_DIR . 'altrelay.php', ALTRELAY_PLUGIN_DIR . 'uninstall.php'),
            (array) glob(ALTRELAY_PLUGIN_DIR . 'includes/*.php')
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            $name = basename((string) $file);

            // Every public or global function, and every hook, says since when.
            preg_match_all('~/\*\*(?:(?!\*/).)*?\*/\s*(?:final\s+)?(?:public\s+(?:static\s+)?function|function|class|public\s+const)\s+(\w+)~s', $source, $documented, PREG_SET_ORDER);

            // The main file holds only the header, a guard and the constants.
            if ('altrelay.php' !== $name) {
                $this->assertNotEmpty($documented, $name);
            }

            foreach ($documented as $block) {
                $this->assertStringContainsString('@since 1.0.0', $block[0], $name . ': ' . $block[1] . ' has no @since tag.');
            }

            preg_match_all('~/\*\*(?:(?!\*/).)*?\*/\s*(?:[^;{}]*?)\b(?:apply_filters|do_action)\(\s*\'(altrelay_\w+)\'~s', $source, $hooks, PREG_SET_ORDER);

            foreach ($hooks as $hook) {
                if (str_contains($hook[0], 'This filter is documented in')) {
                    continue;
                }

                $this->assertStringContainsString('@since 1.0.0', $hook[0], $name . ': the ' . $hook[1] . ' hook has no @since tag.');
            }
        }
    }

    /**
     * Regression (third audit, bug 7): a dormant install loaded the queue
     * class to clear its queue, which needs PHP 8. The plain fallback must
     * name what the class names.
     */
    /**
     * Regression (sixth audit): a second copy of the plugin, active at the
     * same time, stopped the site with a fatal error on functions declared
     * twice. It stays out of the way and says so.
     */
    public function test_a_second_copy_stays_out_of_the_way(): void
    {
        global $altrelay_test_state;

        $before = count($altrelay_test_state['hooks']['admin_notices'] ?? array());

        $altrelay_test_state['user_caps']['activate_plugins'] = true;

        // This copy is loaded already; load it again as a second copy would be.
        include ALTRELAY_PLUGIN_DIR . 'altrelay.php';

        $notices = $altrelay_test_state['hooks']['admin_notices'] ?? array();
        $this->assertCount($before + 1, $notices);

        ob_start();
        end($notices)['callback']();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Two copies of AltRelay are active. The one in &quot;' . basename(ALTRELAY_PLUGIN_DIR) . '&quot; is not running.', $output);

        $altrelay_test_state['user_caps']['activate_plugins'] = false;
        ob_start();
        end($notices)['callback']();
        $this->assertSame('', ob_get_clean(), 'Only for those who can deactivate plugins.');
    }

    public function test_the_dormant_queue_clean_up_uses_the_queue_s_names(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/altrelay-functions.php');
        $start = (int) strpos($source, 'function altrelay_clear_queue()');
        $body = substr($source, $start, (int) strpos($source, "
}
", $start) - $start);

        foreach (array(AltRelay_Queue::ACTION_HOOK, AltRelay_Queue::CRON_HOOK, AltRelay_Queue::QUEUE_OPTION, AltRelay_Queue::LOCK_OPTION) as $name) {
            $this->assertStringContainsString("'$name'", $body);
        }

        $this->assertStringNotContainsString('require', substr($source, (int) strpos($source, 'function altrelay_deactivate('), 600), 'Deactivation loads no class file.');
    }
}
