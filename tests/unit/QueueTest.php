<?php
/**
 * Unit tests for AltRelay_Queue.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class QueueTest
 */
class QueueTest extends TestCase
{

    /**
     * Set up test fixtures.
     */
    protected function set_up(): void
    {
        parent::set_up();
        altrelay_test_reset_statics();

        global $altrelay_test_state, $wpdb;
        $altrelay_test_state = array(
            'post_meta' => array(),
            'options' => array(),
            'transients' => array(),
            'user_caps' => array('upload_files' => true, 'edit_post' => true),
            'post_fields' => array(),
            'is_admin' => false,
            'updated_posts' => array(),
            'cache_cleaned' => array(),
            'as_actions' => array(),
            'as_unscheduled' => array(),
            'as_pending' => array(),
            'as_failed' => array(),
            'scheduled_events' => array(),
            'cleared_hooks' => array(),
            'cron_array' => array(),
            'post_types_by_id' => array(42 => 'attachment', 43 => 'attachment'),
        );

        $wpdb = new wpdb();
    }

    /**
     * Force the WP-Cron fallback.
     */
    private function use_cron(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
    }

    /**
     * Test that enqueue schedules an Action Scheduler action.
     */
    public function test_enqueue_uses_action_scheduler(): void
    {
        global $altrelay_test_state;

        AltRelay_Queue::enqueue(42, 7);

        $this->assertSame(
            array(
                array(
                    'hook' => 'altrelay_process_sync',
                    'args' => array(42, 0, 7),
                    'group' => 'altrelay',
                ),
            ),
            $altrelay_test_state['as_actions']
        );
        $this->assertEmpty($altrelay_test_state['scheduled_events']);
        $this->assertSame('action-scheduler', AltRelay_Queue::backend());
    }

    /**
     * Regression (audit bug 10): the alt text is not part of the job, so its
     * length cannot make the job too large to store.
     */
    public function test_job_carries_no_alt_text(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => str_repeat('A very long description. ', 800));

        AltRelay_Queue::enqueue(42, 7);

        $this->assertSame(array(42, 0, 7), $altrelay_test_state['as_actions'][0]['args']);
        $this->assertLessThan(100, strlen((string) json_encode($altrelay_test_state['as_actions'][0]['args'])));
    }

    /**
     * Regression (audit bug 4): queueing looks for one exact job instead of
     * loading every waiting job, so its cost does not grow with the backlog.
     */
    public function test_enqueue_cost_does_not_grow_with_the_backlog(): void
    {
        global $altrelay_test_state;

        for ($id = 100; $id < 400; $id++) {
            $altrelay_test_state['as_pending'][] = array($id, 0, 0);
        }

        AltRelay_Queue::enqueue(42, 7);

        $this->assertCount(1, $altrelay_test_state['as_queries']);
        $this->assertSame(
            array(
                'hook' => 'altrelay_process_sync',
                'args' => array(42, 0, 7),
                'group' => 'altrelay',
                'status' => 'pending',
                'per_page' => 1,
            ),
            $altrelay_test_state['as_queries'][0]
        );
        $this->assertCount(1, $altrelay_test_state['as_actions']);
    }

    /**
     * Test that a second change to an image whose job is still waiting adds
     * no job: the waiting one reads the newest alt text when it runs.
     */
    public function test_enqueue_does_not_duplicate_a_waiting_job(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['as_pending'] = array(array(42, 0, 7));

        AltRelay_Queue::enqueue(42, 7);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertEmpty($altrelay_test_state['scheduled_events']);

        // Another image, and the same image changed by another user, are
        // jobs of their own.
        AltRelay_Queue::enqueue(43, 7);
        AltRelay_Queue::enqueue(42, 8);

        $this->assertSame(array(array(43, 0, 7), array(42, 0, 8)), array_column($altrelay_test_state['as_actions'], 'args'));
    }

    /**
     * Test that invalid input queues nothing.
     */
    public function test_enqueue_ignores_invalid_id(): void
    {
        global $altrelay_test_state;

        AltRelay_Queue::enqueue(0);
        AltRelay_Queue::enqueue(-5);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertEmpty($altrelay_test_state['scheduled_events']);
    }

    /**
     * Test that a negative user ID is stored as "no user".
     */
    public function test_enqueue_normalizes_the_user(): void
    {
        global $altrelay_test_state;

        AltRelay_Queue::enqueue(42, -3);

        $this->assertSame(array(42, 0, 0), $altrelay_test_state['as_actions'][0]['args']);
    }

    /**
     * Test the WP-Cron fallback.
     */
    public function test_enqueue_falls_back_to_cron(): void
    {
        global $altrelay_test_state;

        $this->use_cron();

        AltRelay_Queue::enqueue(42, 7);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting());
        $this->assertFalse($altrelay_test_state['option_autoload']['altrelay_queue'], 'The job list is not autoloaded.');
        $this->assertCount(1, $altrelay_test_state['scheduled_events'], 'One worker event, not one event per job.');
        $this->assertSame('altrelay_cron_sync', $altrelay_test_state['scheduled_events'][0]['hook']);
        $this->assertSame(array(), $altrelay_test_state['scheduled_events'][0]['args']);
        $this->assertSame('wp-cron', AltRelay_Queue::backend());
        $this->assertFalse($altrelay_test_state['lock_held'], 'The lock is released.');
    }

    /**
     * Regression (second audit, Performance): every job was a cron event of
     * its own in the autoloaded "cron" option, so a bulk change of 300
     * images added 36 KB to every page load until the jobs had run.
     */
    public function test_many_cron_jobs_share_one_worker_event(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $altrelay_test_state['post_types_by_id'] = array();

        for ($id = 1; $id <= 300; $id++) {
            AltRelay_Queue::enqueue($id, 1);
        }

        $this->assertCount(300, $this->waiting());
        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
        $this->assertSame(300, AltRelay_Queue::pending_count());
    }

    /**
     * Test that a change to an image that is already waiting adds nothing,
     * and that a new change restarts a waiting continuation from the start.
     */
    public function test_cron_jobs_are_deduplicated(): void
    {
        $this->use_cron();

        AltRelay_Queue::enqueue(42, 7);
        AltRelay_Queue::enqueue(42, 7);
        AltRelay_Queue::enqueue(42, 8);

        $this->assertSame(array('42:7' => array(42, 0, 7), '42:8' => array(42, 0, 8)), $this->waiting());

        $this->set_queue(array('42:7' => array(42, 300, 7)));
        AltRelay_Queue::enqueue(42, 7);

        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting());
    }

    /**
     * Test that the job list is changed under a lock, that a stale lock is
     * taken over, and that a held lock does not lose the job.
     */
    public function test_cron_job_list_is_changed_under_a_lock(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();

        // A lock left behind by a request that died long ago.
        $altrelay_test_state['lock_held'] = true;
        $wpdb->stub_var_results = array((string) (time() - 600));

        AltRelay_Queue::enqueue(42, 7);

        $this->assertArrayNotHasKey('lock_attempts', $altrelay_test_state, 'Nothing is written until the request ends.');

        AltRelay_Queue::flush();

        $this->assertSame(2, $altrelay_test_state['lock_attempts']);
        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting());
        $this->assertFalse($altrelay_test_state['lock_held']);
        $this->assertContains('options:altrelay_queue', $altrelay_test_state['cache_deleted'], 'The list is read fresh, not from a cache.');
    }

    /**
     * Regression (fifth audit): two requests that found the same expired
     * lock both deleted the row, so the second removed the lock the first
     * had just taken, and both went on. The expired lock is now removed
     * only while it still holds the value that was found expired, and a
     * request only ever releases its own lock.
     */
    public function test_an_expired_lock_taken_over_by_another_request_is_left_to_it(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();

        // This request read the expired value; another one has taken over since.
        $altrelay_test_state['lock_held'] = true;
        $altrelay_test_state['lock_value'] = sprintf('%.4F:7', microtime(true));
        $wpdb->stub_var_results = array((string) (time() - 600));

        AltRelay_Queue::enqueue(42, 7);
        AltRelay_Queue::flush();

        $this->assertTrue($altrelay_test_state['lock_held'], 'The other request keeps its lock.');
        $this->assertStringEndsWith(':7', $altrelay_test_state['lock_value']);
        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting(), 'The job is still written.');
    }

    /**
     * Test that a request releases only the lock it took.
     */
    public function test_a_request_releases_only_its_own_lock(): void
    {
        global $altrelay_test_state;

        $this->use_cron();

        AltRelay_Queue::enqueue(42, 7);
        AltRelay_Queue::flush();

        $this->assertFalse($altrelay_test_state['lock_held']);
        $this->assertSame(1, $altrelay_test_state['lock_taken']);

        // A lock some other request holds now is not this request's to release.
        $altrelay_test_state['lock_held'] = true;
        $altrelay_test_state['lock_value'] = 'another';
        AltRelay_Queue::cancel_all();

        $this->assertFalse($altrelay_test_state['lock_held'], 'Emptying the queue removes any lock.');
    }

    /**
     * Regression (compatibility testing): a request that once found no job
     * list noted the option as missing in its cache, and kept believing so
     * after another request had added it. Its change then replaced the
     * other request's jobs with its own.
     */
    public function test_a_stale_options_cache_does_not_lose_jobs(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $this->set_queue(array('111:0' => array(111, 0, 0)));
        $altrelay_test_state['stale_options']['altrelay_queue'] = true;

        AltRelay_Queue::enqueue(42, 7);
        AltRelay_Queue::flush();

        unset($altrelay_test_state['stale_options']);

        $this->assertSame(array('111:0', '42:7'), array_keys($this->waiting()));
    }

    /**
     * Regression (compatibility testing): deactivating the plugin that
     * brought Action Scheduler left AltRelay's jobs waiting for it forever.
     * A worker run is scheduled to take them over.
     */
    public function test_deactivating_a_plugin_schedules_a_run_for_the_jobs_action_scheduler_holds(): void
    {
        global $altrelay_test_state;

        if (!defined('ALTRELAY_PLUGIN_BASENAME')) {
            define('ALTRELAY_PLUGIN_BASENAME', 'altrelay/altrelay.php');
        }

        AltRelay_Queue::watch_action_scheduler('some-seo-plugin/some-seo-plugin.php');
        $this->assertEmpty($altrelay_test_state['scheduled_events'], 'Nothing is pending, so nothing is scheduled.');

        $altrelay_test_state['as_pending'] = array(array(42, 0, 7));

        AltRelay_Queue::watch_action_scheduler(ALTRELAY_PLUGIN_BASENAME);
        $this->assertEmpty($altrelay_test_state['scheduled_events'], 'AltRelay\'s own deactivation cancels its jobs instead.');

        AltRelay_Queue::watch_action_scheduler('some-seo-plugin/some-seo-plugin.php');
        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
        $this->assertSame('altrelay_cron_sync', $altrelay_test_state['scheduled_events'][0]['hook']);
        $this->assertGreaterThanOrEqual(time() + 59, $altrelay_test_state['scheduled_events'][0]['time']);
    }

    /**
     * Regression (fifth audit): a plugin with Action Scheduler deactivated
     * for the whole network scheduled the takeover on one site only. Every
     * site whose Action Scheduler table holds an AltRelay job gets its run.
     */
    public function test_a_network_wide_deactivation_schedules_a_run_on_each_site_with_jobs(): void
    {
        global $altrelay_test_state, $wpdb;

        if (!defined('ALTRELAY_PLUGIN_BASENAME')) {
            define('ALTRELAY_PLUGIN_BASENAME', 'altrelay/altrelay.php');
        }

        $altrelay_test_state['is_multisite'] = true;
        $altrelay_test_state['site_ids'] = array(1, 2, 3);

        // Sites 1 and 3 hold a job, site 2 does not.
        $wpdb->stub_var_results = array('1', null, '1');

        AltRelay_Queue::watch_action_scheduler('woocommerce/woocommerce.php', true);

        $this->assertCount(2, $altrelay_test_state['scheduled_events']);
        $this->assertSame(1, $altrelay_test_state['current_blog'] ?? 1, 'The original site is restored.');
        $this->assertCount(3, array_filter($wpdb->queries, static fn(string $query): bool => str_contains($query, "status = 'pending' LIMIT 1")));
    }

    /**
     * Test that taking jobs over waits while Action Scheduler is loaded,
     * even when AltRelay is told not to use it: it still runs its jobs.
     */
    public function test_jobs_are_not_taken_from_a_loaded_action_scheduler(): void
    {
        global $wpdb;

        $this->use_cron();

        $this->assertSame(0, AltRelay_Queue::adopt_stranded_jobs());
        $this->assertSame(array(), $wpdb->queries);
    }

    /**
     * Test the worker: it takes jobs one at a time, queues the next batch
     * at the end, and schedules another run while jobs are left.
     */
    public function test_cron_worker_runs_jobs_and_requeues_continuations(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $altrelay_test_state['filters']['altrelay_cron_time_limit'] = array(static fn(): int => 1);

        $ids = array();
        for ($i = 101; $i <= 100 + AltRelay_Queue::BATCH_LIMIT + 1; $i++) {
            $ids[] = (string) $i;
            $altrelay_test_state['post_fields'][$i] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }
        // The first batch finds every post, the second the one after the cursor.
        $wpdb->stub_col_results_queue = array($ids, array('201'));
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');

        $this->set_queue(array('42:7' => array(42, 0, 7), '99:0' => array(99, 0, 0)));

        $queue = new AltRelay_Queue();
        $queue->run_cron_queue();

        // 42's first batch, then 99 (deleted, dropped), then 42's second batch.
        $this->assertCount(AltRelay_Queue::BATCH_LIMIT + 1, $altrelay_test_state['updated_posts']);
        $this->assertSame(array(), $this->waiting());
        $this->assertSame(0, AltRelay_Queue::pending_count());
        $this->assertArrayNotHasKey('altrelay_queue', $altrelay_test_state['options'], 'An empty list is removed.');
        $this->assertEmpty($altrelay_test_state['scheduled_events']);
    }

    /**
     * Test that the worker stops when its time is up, and schedules the
     * next run.
     */
    public function test_cron_worker_stops_after_its_time_and_schedules_the_rest(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $altrelay_test_state['post_types_by_id'] = array();
        // No time at all: one job per run.
        $altrelay_test_state['filters']['altrelay_cron_time_limit'] = array(static fn(): int => 0);
        $this->set_queue(array('1:0' => array(1, 0, 0), '2:0' => array(2, 0, 0)));

        (new AltRelay_Queue())->run_cron_queue();

        $this->assertSame(array('2:0' => array(2, 0, 0)), $this->waiting());
        $this->assertSame(array(), $altrelay_test_state['scheduled_events'][0]['args']);
    }

    /**
     * Test that a job whose request died is queued again, and that a job
     * already running is not started a second time.
     */
    public function test_cron_worker_recovers_abandoned_jobs(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $altrelay_test_state['post_types_by_id'] = array();

        update_option(
            'altrelay_queue',
            array(
                'waiting' => array('6:0' => array(6, 0, 0)),
                'running' => array(
                    '5:0' => array(5, 200, 0, time() - 3600),
                    '6:0' => array(6, 0, 0, time()),
                ),
            )
        );

        (new AltRelay_Queue())->run_cron_queue();

        // 5 was abandoned and has run now; 6 is still running elsewhere.
        $stored = get_option('altrelay_queue');
        $this->assertSame(array('6:0' => array(6, 0, 0)), $stored['waiting']);
        $this->assertSame(array('6:0'), array_keys($stored['running']));
    }

    /**
     * Test that a newer change that arrives while a job runs is not lost:
     * the continuation of the older job is dropped instead.
     */
    public function test_a_change_during_a_cron_job_wins_over_its_continuation(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $altrelay_test_state['filters']['altrelay_cron_time_limit'] = array(static fn(): int => 0);

        $ids = array();
        for ($i = 101; $i <= 100 + AltRelay_Queue::BATCH_LIMIT + 1; $i++) {
            $ids[] = (string) $i;
            $altrelay_test_state['post_fields'][$i] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        }
        $wpdb->stub_col_results = $ids;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');

        // The alt text changes again while the first batch is saved.
        $altrelay_test_state['on_save_post'] = static function (): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_save_post']);
            AltRelay_Queue::enqueue(42, 7);
        };

        $this->set_queue(array('42:7' => array(42, 0, 7)));
        (new AltRelay_Queue())->run_cron_queue();

        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting(), 'The new job starts from the first post.');
    }

    /**
     * Test that a malformed job list is read as far as it makes sense.
     */
    public function test_malformed_cron_job_lists_are_ignored(): void
    {
        $this->use_cron();

        update_option('altrelay_queue', 'garbage');
        $this->assertSame(0, AltRelay_Queue::pending_count());

        update_option(
            'altrelay_queue',
            array(
                'waiting' => array('x' => 'nope', 'y' => array('a', 0, 0), 'z' => array(0, 0, 0), 'ok' => array('7', '0', '2')),
                'running' => 'nope',
            )
        );
        $this->assertSame(1, AltRelay_Queue::pending_count());
        $this->assertSame(array('7:2' => array(7, 0, 2)), $this->waiting());
    }

    /**
     * Jobs waiting in the WP-Cron list, read the way the queue reads them.
     *
     * @return array<string, array<int, int>>
     */
    private function waiting(): array
    {
        // Jobs are written when the request ends; this is that moment.
        AltRelay_Queue::flush();

        $stored = get_option('altrelay_queue', array());

        if (!is_array($stored)) {
            return array();
        }

        $jobs = array();

        foreach (is_array($stored['waiting'] ?? null) ? $stored['waiting'] : array() as $job) {
            if (is_array($job) && is_numeric($job[0] ?? null) && (int) $job[0] > 0 && is_numeric($job[1] ?? null) && is_numeric($job[2] ?? null)) {
                $jobs[(int) $job[0] . ':' . (int) $job[2]] = array((int) $job[0], (int) $job[1], (int) $job[2]);
            }
        }

        return $jobs;
    }

    /**
     * Put jobs in the WP-Cron list.
     *
     * @param array<string, array<int, int>> $waiting Waiting jobs.
     */
    private function set_queue(array $waiting): void
    {
        update_option('altrelay_queue', array('waiting' => $waiting, 'running' => array()), false);
    }
    /**
     * Test that a job Action Scheduler could not store goes to WP-Cron
     * instead of being lost.
     */
    public function test_enqueue_falls_back_to_cron_when_action_scheduler_refuses(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['as_refuses'] = true;

        AltRelay_Queue::enqueue(42, 7);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting());
        $this->assertSame(array(), $altrelay_test_state['scheduled_events'][0]['args']);
    }

    /**
     * Test that Action Scheduler is only used once its data store is ready.
     *
     * Its functions exist as soon as the library is loaded; a job scheduled
     * before "init" would be lost.
     */
    public function test_action_scheduler_is_not_used_before_it_is_ready(): void
    {
        global $altrelay_test_state;

        // Loaded, but "init" has not run.
        $altrelay_test_state['did_actions'] = array('init' => 0);
        $this->assertFalse(AltRelay_Queue::has_action_scheduler());

        // "init" is running: older versions set up their store during it.
        $altrelay_test_state['did_actions'] = array('init' => 1);
        $altrelay_test_state['doing_actions'] = array('init' => true);
        $this->assertFalse(AltRelay_Queue::has_action_scheduler());

        // Current versions say when they are ready, even during "init".
        $altrelay_test_state['did_actions']['action_scheduler_init'] = 1;
        $this->assertTrue(AltRelay_Queue::has_action_scheduler());

        // "init" is over.
        $altrelay_test_state['did_actions'] = array('init' => 1);
        $altrelay_test_state['doing_actions'] = array();
        $this->assertTrue(AltRelay_Queue::has_action_scheduler());

        // Not ready: the job goes to WP-Cron.
        $altrelay_test_state['did_actions'] = array('init' => 0);
        AltRelay_Queue::enqueue(42, 7);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertSame(array('42:7' => array(42, 0, 7)), $this->waiting());
    }

    /**
     * Test that the worker reads the alt text when it runs.
     */
    public function test_process_reads_the_alt_text_at_run_time(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'First version');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        AltRelay_Queue::enqueue(42);

        // The description changes again before the worker runs.
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = '  Second <b>version</b> ';

        (new AltRelay_Queue())->process(...$altrelay_test_state['as_actions'][0]['args']);

        $this->assertSame(
            '<img src="a.jpg" alt="Second version" class="wp-image-42" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
    }

    /**
     * Test that removed alt text is propagated as an empty alt.
     */
    public function test_process_propagates_cleared_alt_text(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        (new AltRelay_Queue())->process(42, 0, 0);

        $this->assertSame(
            '<img src="a.jpg" alt="" class="wp-image-42" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
    }

    /**
     * Regression (sixth audit): with Action Scheduler, a batch scheduled the
     * image's next batch even when a newer change to the image waited, which
     * covers every post anyway, so the work was done twice. The WP-Cron
     * queue already dropped such a batch.
     */
    public function test_a_next_batch_is_not_scheduled_behind_a_newer_change(): void
    {
        global $altrelay_test_state, $wpdb;

        // One more post than a batch takes.
        $ids = array_map('strval', range(10, 110));
        $wpdb->stub_col_results = $ids;

        foreach ($ids as $id) {
            $altrelay_test_state['post_fields'][(int) $id] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        }

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'New';

        // A newer change to the image waits.
        $altrelay_test_state['as_pending'] = array(array(42, 0, 7));

        (new AltRelay_Queue())->process(42, 0, 7);

        $this->assertEmpty($altrelay_test_state['as_actions'], 'No next batch behind the newer change.');

        // Without one, the next batch is scheduled.
        $altrelay_test_state['as_pending'] = array();
        (new AltRelay_Queue())->process(42, 0, 7);

        $this->assertCount(1, $altrelay_test_state['as_actions']);
        $this->assertSame(array(42, 109, 7), $altrelay_test_state['as_actions'][0]['args']);
    }

    /**
     * Test that a job for an image that was deleted meanwhile does nothing.
     */
    public function test_process_skips_deleted_attachments(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_types_by_id'][42] = 'post';

        (new AltRelay_Queue())->process(42, 0, 0);
        (new AltRelay_Queue())->process(0, 0, 0);

        $this->assertSame(0, $wpdb->query_count);
        $this->assertEmpty($altrelay_test_state['as_actions']);
    }

    /**
     * Job arguments that must be dropped without a type error.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function malformed_jobs(): array
    {
        return array(
            'a job from before the alt text was removed from it' => array(42, 'Some alt text', 0, 0),
            'a negative ID' => array(-42, 0, 0),
            'a float' => array(42.5, 0, 0),
            'null' => array(null, 0, 0),
            'an array' => array(array(42), 0, 0),
            'a non-numeric cursor' => array(42, 'x', 0),
            'a non-numeric user' => array(42, 0, 'admin'),
        );
    }

    /**
     * Test that the worker checks its arguments, which come from a database row.
     *
     * @param mixed ...$args Job arguments.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_jobs')]
    public function test_process_drops_malformed_jobs(mixed ...$args): void
    {
        global $altrelay_test_state, $wpdb;

        (new AltRelay_Queue())->process(...array_slice($args, 0, 3));

        $this->assertSame(0, $wpdb->query_count);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that numeric strings are accepted, as a queue may hand them over.
     */
    public function test_process_accepts_numeric_strings(): void
    {
        global $wpdb;

        (new AltRelay_Queue())->process('42', '0', '7');

        $this->assertSame(1, $wpdb->query_count);
    }

    /**
     * Test that an unfinished batch schedules the next one, with the cursor
     * and the acting user.
     */
    public function test_process_chains_when_not_done(): void
    {
        global $altrelay_test_state, $wpdb;

        $ids = array();
        for ($i = 101; $i <= 100 + AltRelay_Queue::BATCH_LIMIT + 5; $i++) {
            $ids[] = (string) $i;
            $altrelay_test_state['post_fields'][$i] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }
        $wpdb->stub_col_results = $ids;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');

        (new AltRelay_Queue())->process(42, 0, 7);

        $this->assertCount(AltRelay_Queue::BATCH_LIMIT, $altrelay_test_state['updated_posts']);
        $this->assertSame(array(array(42, 100 + AltRelay_Queue::BATCH_LIMIT, 7)), array_column($altrelay_test_state['as_actions'], 'args'));
    }

    /**
     * Test that a finished run schedules nothing.
     */
    public function test_process_stops_when_done(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        (new AltRelay_Queue())->process(42, 0, 0);

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertEmpty($altrelay_test_state['scheduled_events']);
    }

    /**
     * Test that the worker only touches posts the acting user may edit.
     */
    public function test_process_checks_the_acting_user(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_col_results = array('10', '11');
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');
        $altrelay_test_state['user_can_map'][7]['edit_post'][11] = false;

        foreach (array(10, 11) as $post_id) {
            $altrelay_test_state['post_fields'][$post_id] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }

        (new AltRelay_Queue())->process(42, 0, 7);

        $this->assertSame(array(10), array_column($altrelay_test_state['updated_posts'], 'ID'));
    }

    /**
     * Test that an Action Scheduler job whose next batch Action Scheduler
     * refuses continues on WP-Cron.
     */
    public function test_cron_processes_one_batch_and_chains(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();

        $ids = array();
        for ($i = 101; $i <= 100 + AltRelay_Queue::BATCH_LIMIT + 1; $i++) {
            $ids[] = (string) $i;
            $altrelay_test_state['post_fields'][$i] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }
        $wpdb->stub_col_results = $ids;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'New');

        (new AltRelay_Queue())->process(42, 0, 7);

        $this->assertEmpty($altrelay_test_state['as_actions']);
        $this->assertSame(array('42:7' => array(42, 100 + AltRelay_Queue::BATCH_LIMIT, 7)), $this->waiting());
    }

    /**
     * Test that the queue uses the sync engine it was given.
     */
    public function test_queue_uses_the_injected_sync_engine(): void
    {
        $sync = new class () extends AltRelay_Sync {
            /** @var list<array<int, mixed>> */
            public array $calls = array();

            public function sync_attachment(int $attachment_id, string $new_alt, bool $dry_run = false, int $after_id = 0, int $limit = 0, int $user_id = 0, array $post_types = array()): array
            {
                $this->calls[] = func_get_args();

                return array(
                    'updated' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'last_id' => $after_id,
                    'done' => true,
                    'post_ids' => array(),
                );
            }
        };

        (new AltRelay_Queue($sync))->process(42, 5, 7);

        $this->assertSame(array(array(42, '', false, 5, AltRelay_Queue::BATCH_LIMIT, 7)), $sync->calls);
    }

    /**
     * Test the hooks: Action Scheduler jobs carry three arguments, the
     * WP-Cron worker none.
     */
    public function test_register_hooks(): void
    {
        global $altrelay_test_state;

        (new AltRelay_Queue())->register();

        $this->assertSame(3, $altrelay_test_state['hooks']['altrelay_process_sync'][0]['accepted_args']);
        $this->assertSame(0, $altrelay_test_state['hooks']['altrelay_cron_sync'][0]['accepted_args']);
        $this->assertSame(2, $altrelay_test_state['hooks']['deactivated_plugin'][0]['accepted_args']);
    }

    /**
     * Test that the pending count adds both back ends and is not capped.
     */
    public function test_pending_count_reports_both_back_ends(): void
    {
        global $altrelay_test_state;

        for ($id = 1; $id <= 250; $id++) {
            $altrelay_test_state['as_pending'][] = array($id, 0, 0);
        }

        update_option(
            'altrelay_queue',
            array(
                'waiting' => array('1:0' => array(1, 0, 0), '2:0' => array(2, 0, 0)),
                'running' => array('3:0' => array(3, 0, 0, time())),
            )
        );

        $this->assertSame(253, AltRelay_Queue::pending_count());
    }

    /**
     * Test that the pending count is zero when idle.
     */
    public function test_pending_count_is_zero_when_idle(): void
    {
        $this->assertSame(0, AltRelay_Queue::pending_count());
        $this->assertSame(0, AltRelay_Queue::failed_count());
    }

    /**
     * Test that failed Action Scheduler runs are counted, so the dashboard
     * can show them.
     */
    public function test_failed_count_reports_failed_runs(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['as_pending'] = array(array(1, 0, 0));
        $altrelay_test_state['as_failed'] = array(array(2, 0, 0), array(3, 100, 0));

        $this->assertSame(2, AltRelay_Queue::failed_count());
        $this->assertSame(1, AltRelay_Queue::pending_count());

        // WP-Cron keeps no record of failures.
        $this->use_cron();

        $this->assertSame(0, AltRelay_Queue::failed_count());
        $this->assertSame(0, AltRelay_Queue::pending_count());
    }

    /**
     * Test that cancel_all() clears both back ends.
     *
     * The Action Scheduler group has to stay empty: with both hook and group
     * set it looks for exact arguments, which never match.
     */
    public function test_cancel_all_clears_every_pending_run(): void
    {
        global $altrelay_test_state;

        AltRelay_Queue::cancel_all();

        $this->assertSame(
            array(
                array(
                    'hook' => 'altrelay_process_sync',
                    'args' => array(),
                    'group' => '',
                ),
            ),
            $altrelay_test_state['as_unscheduled']
        );
        $this->assertSame(array(array('hook' => 'altrelay_cron_sync')), $altrelay_test_state['cleared_hooks']);
    }

    /**
     * Test that cancel_all() empties the WP-Cron job list and its lock.
     */
    public function test_cancel_all_empties_the_cron_job_list(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        AltRelay_Queue::enqueue(42, 7);
        $altrelay_test_state['lock_held'] = true;

        AltRelay_Queue::cancel_all();

        $this->assertSame(0, AltRelay_Queue::pending_count());
        $this->assertFalse($altrelay_test_state['lock_held']);
    }


    /**
     * Regression (third audit, bug 3): a job that threw ended the worker
     * run, and the jobs behind it waited with no run scheduled. Since the
     * fourth audit a post that throws is recorded on its own, with its image.
     */
    public function test_a_failing_cron_job_does_not_stop_the_others(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Forty-two');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => 'Forty-three');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" /><img src="b.jpg" alt="" class="wp-image-43" />',
        );
        $wpdb->stub_col_results = array('10');

        $scheduled_during_job = null;
        $altrelay_test_state['on_before_save'] = static function () use (&$scheduled_during_job): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_before_save']);
            $scheduled_during_job = wp_next_scheduled(AltRelay_Queue::CRON_HOOK);
            throw new RuntimeException('A save_post callback broke.');
        };

        $this->set_queue(array('42:0' => array(42, 0, 0), '43:0' => array(43, 0, 0)));

        $ran = (new AltRelay_Queue())->work_through_queue(5);

        $this->assertSame(2, $ran);
        $this->assertNotFalse($scheduled_during_job, 'The next run is scheduled before a job starts.');
        $this->assertStringContainsString('alt="Forty-three"', $altrelay_test_state['post_fields'][10]['post_content'], 'The second job ran.');
        $this->assertSame(0, AltRelay_Queue::pending_count());
        $this->assertSame('A save_post callback broke.', $altrelay_test_state['transients']['altrelay_sync_errors']['message']);
        $this->assertSame(10, $altrelay_test_state['transients']['altrelay_sync_errors']['post_id']);
        $this->assertSame(array(42), AltRelay_Sync::images_to_retry(), 'The dashboard can run the image again.');
        $this->assertEmpty($altrelay_test_state['scheduled_events'], 'Nothing is left to run.');
    }

    /**
     * Make the altrelay_alt_text filter throw for image 42.
     */
    private function break_image_42(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_alt_text'] = array(
            static function (string $alt, int $attachment_id): string {
                if (42 === $attachment_id) {
                    throw new RuntimeException('The filter broke.');
                }

                return $alt;
            },
        );
    }

    /**
     * Regression (fourth audit): a job that stops is recorded with its
     * image, in a translatable message, and the next job still runs.
     */
    public function test_a_cron_job_that_stops_is_recorded_with_its_image(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $this->break_image_42();
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Forty-two');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => 'Forty-three');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="b.jpg" alt="" class="wp-image-43" />');
        $wpdb->stub_col_results = array('10');

        $this->set_queue(array('42:0' => array(42, 0, 0), '43:0' => array(43, 0, 0)));

        $this->assertSame(2, (new AltRelay_Queue())->work_through_queue(5));
        $this->assertStringContainsString('alt="Forty-three"', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertSame('The background update of image 42 stopped: The filter broke.', $altrelay_test_state['transients']['altrelay_sync_errors']['message']);
        $this->assertSame(array(42), AltRelay_Sync::images_to_retry());
        $this->assertSame(0, AltRelay_Queue::pending_count());
    }

    /**
     * Test that an Action Scheduler job that throws is recorded with its
     * image and still fails, so Action Scheduler shows it.
     */
    public function test_an_action_scheduler_job_that_throws_is_recorded_and_fails(): void
    {
        $this->break_image_42();

        try {
            (new AltRelay_Queue())->process(42, 0, 0);
            $this->fail('The exception reaches Action Scheduler.');
        } catch (RuntimeException $e) {
            $this->assertSame('The filter broke.', $e->getMessage());
        }

        $this->assertSame(array(42), AltRelay_Sync::images_to_retry());
    }

    /**
     * Regression (fourth audit): a change made while switched to another
     * site of a network was queued on the site the request started on.
     */
    public function test_a_change_while_switched_is_queued_on_that_site(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $altrelay_test_state['is_multisite'] = true;

        AltRelay_Queue::enqueue(42, 1);
        switch_to_blog(2);
        AltRelay_Queue::enqueue(43, 1);
        restore_current_blog();

        AltRelay_Queue::flush();

        $this->assertSame(array('42:1'), array_keys($altrelay_test_state['options']['altrelay_queue']['waiting']));
        $this->assertSame(array('43:1'), array_keys($altrelay_test_state['blog_options'][2]['altrelay_queue']['waiting']));
        $this->assertSame(1, get_current_blog_id(), 'The request is back on its own site.');
    }

    /**
     * Test that jobs held while switched are written without switching when
     * the request is still on that site.
     */
    public function test_jobs_of_the_current_site_are_written_without_switching(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $altrelay_test_state['is_multisite'] = true;

        switch_to_blog(2);
        AltRelay_Queue::enqueue(43, 1);
        AltRelay_Queue::flush();

        $this->assertSame(2, get_current_blog_id());
        $this->assertSame(array('43:1'), array_keys($altrelay_test_state['blog_options'][2]['altrelay_queue']['waiting']));
        restore_current_blog();
    }

    /**
     * Regression (fourth audit, Performance): each job wrote the job list
     * twice, once to take it and once to mark it done.
     */
    public function test_finishing_a_job_and_taking_the_next_is_one_write(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $altrelay_test_state['post_types_by_id'][44] = 'attachment';
        $wpdb->stub_col_results = array();
        $this->set_queue(array('42:0' => array(42, 0, 0), '43:0' => array(43, 0, 0), '44:0' => array(44, 0, 0)));
        $altrelay_test_state['lock_taken'] = 0;

        $this->assertSame(3, (new AltRelay_Queue())->work_through_queue(5));
        $this->assertSame(4, $altrelay_test_state['lock_taken'], 'Take, done and take twice, done: four writes for three jobs.');
        $this->assertArrayNotHasKey('altrelay_queue', $altrelay_test_state['options']);
    }

    /**
     * Put a job in the WP-Cron list that a dead request left running.
     *
     * @param int $tries How often it was abandoned before.
     */
    private function set_abandoned_job(int $tries): void
    {
        update_option(
            'altrelay_queue',
            array(
                'waiting' => array(),
                'running' => array('42:0' => array(42, 0, 0, time() - 700)),
                'tries' => array('42:0' => $tries),
            ),
            false
        );
    }

    /**
     * Regression (fourth audit): a job whose request died was queued again
     * every ten minutes, forever.
     */
    public function test_an_abandoned_job_is_given_up_after_three_tries(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $this->set_abandoned_job(2);

        $this->assertSame(0, (new AltRelay_Queue())->work_through_queue(5));
        $this->assertArrayNotHasKey('altrelay_queue', $altrelay_test_state['options']);
        $this->assertStringContainsString('The background update of image 42 stopped: the request running it ended without finishing, 3 times in a row.', $altrelay_test_state['transients']['altrelay_sync_errors']['message']);
        $this->assertSame(array(42), AltRelay_Sync::images_to_retry());
        $this->assertEmpty($altrelay_test_state['scheduled_events'], 'Nothing is left to run.');
    }

    /**
     * Test that an abandoned job is tried again with its count raised, and
     * that the count is cleared once it finishes.
     */
    public function test_an_abandoned_job_is_tried_again_and_counted(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->use_cron();
        $this->set_abandoned_job(1);
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Forty-two');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        $wpdb->stub_col_results = array('10');

        $during = null;
        $altrelay_test_state['on_save_post'] = static function () use (&$during): void {
            $stored = get_option('altrelay_queue');
            $during = $stored['tries']['42:0'] ?? null;
        };

        $this->assertSame(1, (new AltRelay_Queue())->work_through_queue(5));
        $this->assertSame(2, $during, 'The second abandonment is counted while the job runs.');
        $this->assertStringContainsString('alt="Forty-two"', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertArrayNotHasKey('altrelay_queue', $altrelay_test_state['options'], 'Finishing clears the count.');
        $this->assertArrayNotHasKey('altrelay_sync_errors', $altrelay_test_state['transients']);
    }

    /**
     * Test that a newer change to an image whose job was abandoned starts
     * afresh, with every try left.
     */
    public function test_a_newer_change_resets_the_tries(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $this->set_abandoned_job(2);
        AltRelay_Queue::enqueue(42, 0);
        AltRelay_Queue::flush();

        $this->assertArrayNotHasKey('42:0', $altrelay_test_state['options']['altrelay_queue']['tries'] ?? array());

        // An abandoned job with a newer change waiting is not counted at all.
        $this->set_abandoned_job(2);
        $queue = get_option('altrelay_queue');
        $queue['waiting'] = array('42:0' => array(42, 0, 0));
        update_option('altrelay_queue', $queue, false);

        (new AltRelay_Queue())->work_through_queue(0);

        $this->assertArrayNotHasKey('altrelay_sync_errors', $altrelay_test_state['transients']);
    }

    /**
     * Test that a malformed count of tries is ignored.
     */
    public function test_malformed_tries_are_ignored(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        update_option(
            'altrelay_queue',
            array(
                'waiting' => array(),
                'running' => array('42:0' => array(42, 0, 0, time() - 700)),
                'tries' => array('42:0' => 'many', 7 => 2),
            ),
            false
        );

        (new AltRelay_Queue())->work_through_queue(0);

        $this->assertArrayNotHasKey('altrelay_sync_errors', $altrelay_test_state['transients'], 'Counted as the first abandonment.');
    }

    /**
     * Regression (third audit, Performance): while a dead request's job was
     * marked as running, every worker run rescheduled itself at once.
     */
    public function test_a_job_left_running_does_not_make_the_worker_spin(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $since = time() - 60;
        update_option(
            'altrelay_queue',
            array(
                'waiting' => array('5:0' => array(5, 0, 0)),
                'running' => array('5:0' => array(5, 100, 0, $since)),
            )
        );

        (new AltRelay_Queue())->run_cron_queue();

        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
        $this->assertGreaterThan($since + 600, $altrelay_test_state['scheduled_events'][0]['time'], 'The next run waits until the job counts as abandoned.');
    }

    /**
     * Regression (third audit, Performance): every queued job read and wrote
     * the whole job list, so queueing slowed down as the list grew.
     */
    public function test_jobs_are_written_once_per_request_or_per_hundred(): void
    {
        global $altrelay_test_state;

        $this->use_cron();

        for ($id = 1; $id <= 250; $id++) {
            AltRelay_Queue::enqueue($id, 1);
        }

        $this->assertSame(2, $altrelay_test_state['lock_taken'], 'Two writes for 250 jobs, after 100 and 200.');
        $this->assertSame(array('AltRelay_Queue', 'flush'), $altrelay_test_state['hooks']['shutdown'][0]['callback']);

        // The end of the request writes the rest.
        AltRelay_Queue::flush();

        $this->assertSame(3, $altrelay_test_state['lock_taken']);
        $this->assertCount(250, $this->waiting());
        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
    }

    /**
     * Test that the dashboard and WP-CLI can restart a queue whose worker
     * run was lost.
     */
    public function test_wake_schedules_a_lost_worker_run(): void
    {
        global $altrelay_test_state;

        $this->use_cron();
        $this->set_queue(array('42:0' => array(42, 0, 0)));

        $this->assertEmpty($altrelay_test_state['scheduled_events']);

        AltRelay_Queue::wake();

        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
        $this->assertLessThanOrEqual(time(), $altrelay_test_state['scheduled_events'][0]['time']);

        // Waking twice schedules one run.
        AltRelay_Queue::wake();
        $this->assertCount(1, $altrelay_test_state['scheduled_events']);
    }
}
