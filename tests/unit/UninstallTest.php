<?php
/**
 * Unit tests for the uninstall handler.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class UninstallTest
 */
class UninstallTest extends TestCase
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
            'options' => array('altrelay_version' => '1.0.0'),
            'transients' => array(),
            'user_caps' => array('upload_files' => true, 'edit_post' => true),
            'post_fields' => array(),
            'is_admin' => false,
            'updated_posts' => array(),
            'cache_cleaned' => array(),
        );
    }

    /**
     * Load uninstall.php as WordPress would, then return.
     *
     * @param bool $multisite Whether to simulate a multisite network.
     * @param int[] $site_ids Site IDs to return from get_sites().
     */
    private function run_uninstall(bool $multisite = false, array $site_ids = array(1)): void
    {
        global $altrelay_test_state, $wpdb;

        if (!$wpdb instanceof wpdb) {
            $wpdb = new wpdb();
        }

        $altrelay_test_state['is_multisite'] = $multisite;
        $altrelay_test_state['site_ids'] = $site_ids;

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'altrelay/altrelay.php');
        }

        require dirname(__DIR__, 2) . '/uninstall.php';
    }

    /**
     * Test that uninstall removes the version option.
     */
    public function test_uninstall_removes_version_option(): void
    {
        global $altrelay_test_state;

        $this->assertArrayHasKey('altrelay_version', $altrelay_test_state['options']);

        $this->run_uninstall();

        $this->assertArrayNotHasKey('altrelay_version', $altrelay_test_state['options']);
    }

    /**
     * Test that uninstall removes the transient and the welcome flag.
     */
    public function test_uninstall_removes_transient_and_welcome_flag(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'attachment_id' => 42,
            'post_count' => 3,
            'time' => time(),
        );
        $altrelay_test_state['options']['altrelay_show_welcome'] = '1';

        $this->run_uninstall();

        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
        $this->assertArrayNotHasKey('altrelay_show_welcome', $altrelay_test_state['options']);
    }

    /**
     * Test that uninstall removes everything the plugin stores, by the
     * names the plugin itself uses.
     *
     * uninstall.php runs without the plugin loaded, so it repeats the names
     * as strings; this keeps them in step with the constants.
     */
    public function test_uninstall_removes_every_option_and_transient(): void
    {
        global $altrelay_test_state;

        $options = array('altrelay_version', AltRelay_Admin::WELCOME_OPTION, AltRelay_Content::REVIEW_FIRST_OPTION, AltRelay_Queue::QUEUE_OPTION, AltRelay_Queue::LOCK_OPTION);
        $transients = array(
            AltRelay_Sync::LAST_SYNC_TRANSIENT,
            AltRelay_Sync::ERRORS_TRANSIENT,
            AltRelay_Report::STATS_TRANSIENT,
            AltRelay_Report::USAGE_TRANSIENT,
        );

        foreach ($options as $option) {
            $altrelay_test_state['options'][$option] = '1';
        }

        foreach ($transients as $transient) {
            $altrelay_test_state['transients'][$transient] = array('x');
        }

        $this->run_uninstall();

        $this->assertSame(array(), $altrelay_test_state['options']);
        $this->assertSame(array(), $altrelay_test_state['transients']);
        $this->assertSame(array('_altrelay_decorative', '_altrelay_marking', '_altrelay_stale_alts'), $altrelay_test_state['deleted_meta_keys'], 'Every record AltRelay keeps on images and posts goes.');
    }

    /**
     * Test that the option name in uninstall.php matches the plugin constant.
     *
     * uninstall.php runs without the plugin loaded, so it cannot use the
     * constant and repeats the string; this keeps the two in step.
     */
    public function test_uninstall_uses_the_welcome_option_name(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');

        $this->assertStringContainsString("delete_option( '" . AltRelay_Admin::WELCOME_OPTION . "' )", $source);
    }

    /**
     * Test that uninstall leaves unrelated options and transients alone.
     */
    public function test_uninstall_leaves_unrelated_data_alone(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['options']['blogname'] = 'My Site';
        $altrelay_test_state['transients']['unrelated'] = 'keep me';

        $this->run_uninstall();

        $this->assertArrayHasKey('blogname', $altrelay_test_state['options']);
        $this->assertArrayHasKey('unrelated', $altrelay_test_state['transients']);
    }

    /**
     * Test that uninstall loops over every site on multisite.
     */
    public function test_uninstall_handles_multisite(): void
    {
        global $altrelay_test_state;

        $this->run_uninstall(true, array(1, 2, 3));

        $this->assertArrayNotHasKey('altrelay_version', $altrelay_test_state['options']);
        // restore_current_blog() is called after the loop.
        $this->assertSame(1, $altrelay_test_state['current_blog']);
    }

    /**
     * Regression (sixth audit): uninstall read every site of a network at
     * once. It reads them a page of 100 at a time, and reaches every one.
     */
    public function test_uninstall_reads_the_sites_page_by_page(): void
    {
        global $altrelay_test_state;

        $this->run_uninstall(true, range(1, 150));

        $this->assertSame(array(0, 100), array_column($altrelay_test_state['get_sites_calls'], 'offset'));
        $this->assertCount(150, array_filter($altrelay_test_state['deleted_meta_keys'], static fn(string $key): bool => '_altrelay_decorative' === $key));
    }

    /**
     * Test that uninstall does not touch post content.
     */
    public function test_uninstall_does_not_modify_posts(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img class="wp-image-42" alt="synced" />',
        );

        $this->run_uninstall();

        $this->assertSame(
            '<img class="wp-image-42" alt="synced" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that uninstall clears queued work.
     *
     * A plugin deleted without being deactivated first would otherwise leave
     * Action Scheduler actions pointing at a hook that no longer exists.
     */
    public function test_uninstall_clears_queued_work(): void
    {
        global $altrelay_test_state;

        $this->run_uninstall();

        $this->assertCount(1, $altrelay_test_state['as_unscheduled']);
        $this->assertSame('altrelay_process_sync', $altrelay_test_state['as_unscheduled'][0]['hook']);

        // Empty group on purpose: Action Scheduler only cancels by hook when
        // the group is empty, otherwise it does an exact-args lookup.
        $this->assertSame('', $altrelay_test_state['as_unscheduled'][0]['group']);

        $this->assertCount(1, $altrelay_test_state['cleared_hooks']);
        $this->assertSame('altrelay_cron_sync', $altrelay_test_state['cleared_hooks'][0]['hook']);
    }
}
