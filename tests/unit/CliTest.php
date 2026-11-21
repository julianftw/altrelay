<?php
/**
 * Unit tests for AltRelay_CLI.
 *
 * Tests the WP-CLI command surface: argument validation, dry-run behaviour,
 * and the audit output. WP_CLI itself is stubbed.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class CliTest
 */
class CliTest extends TestCase
{

    /**
     * The CLI instance.
     *
     * @var AltRelay_CLI
     */
    private AltRelay_CLI $cli;

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
            'cli_log' => array(),
            'cli_errors' => array(),
            'cli_success' => array(),
            'cli_warnings' => array(),
            'as_actions' => array(),
            'as_unscheduled' => array(),
            'as_pending' => array(),
            'scheduled_events' => array(),
            'cleared_hooks' => array(),
            'cron_array' => array(),
            'unscheduled_events' => array(),
        );

        require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-cli.php';

        $this->cli = new AltRelay_CLI();
    }

    /**
     * Test that sync rejects a non-numeric attachment ID.
     */
    public function test_sync_rejects_invalid_id(): void
    {
        global $altrelay_test_state;

        $this->cli->sync(array('not-a-number'), array());

        $this->assertNotEmpty($altrelay_test_state['cli_errors']);
        $this->assertStringContainsString('"not-a-number" is not a valid ID', $altrelay_test_state['cli_errors'][0]);
    }

    /**
     * Test that sync rejects a post that is not an attachment.
     */
    public function test_sync_rejects_non_attachment(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['posts'][10] = (object) array(
            'ID' => 10,
            'post_type' => 'post',
        );

        $this->cli->sync(array('10'), array());

        $this->assertNotEmpty($altrelay_test_state['cli_warnings']);
        $this->assertStringContainsString('not an attachment', $altrelay_test_state['cli_warnings'][0]);
    }

    /**
     * Test that sync warns when the attachment has no alt text.
     */
    public function test_sync_warns_without_alt_text(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_types_by_id'][42] = 'attachment';

        $this->cli->sync(array('42'), array());

        $this->assertSame(
            array('Attachment 42 has no alt text, skipping. Add alt text in the Media Library first.'),
            $altrelay_test_state['cli_warnings']
        );
        $this->assertStringContainsString('Updated 0 posts', $altrelay_test_state['cli_success'][0]);
    }

    /**
     * Test that a dry run reports changes without writing them.
     */
    public function test_sync_dry_run_writes_nothing(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['posts'][42] = (object) array(
            'ID' => 42,
            'post_type' => 'attachment',
        );
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Library alt',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->cli->sync(array('42'), array('dry-run' => true));

        $this->assertCount(0, $altrelay_test_state['updated_posts']);
        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
    }

    /**
     * Test that a real sync updates the post and reports the count.
     */
    public function test_sync_updates_posts(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['posts'][42] = (object) array(
            'ID' => 42,
            'post_type' => 'attachment',
        );
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Library alt',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->cli->sync(array('42'), array());

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertStringContainsString('alt="Library alt"', $altrelay_test_state['updated_posts'][0]['post_content']);
        $this->assertNotEmpty($altrelay_test_state['cli_success']);
        $this->assertStringContainsString('Updated 1 post', $altrelay_test_state['cli_success'][0]);
    }

    /**
     * Test that sync reports the real count, not a stale transient.
     *
     * Regression test: the command used to read altrelay_last_sync, so a sync
     * that changed nothing reported the count from an earlier run.
     */
    public function test_sync_reports_actual_count_not_stale_transient(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $altrelay_test_state['posts'][42] = (object) array(
            'ID' => 42,
            'post_type' => 'attachment',
        );
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Library alt',
        );

        // A stale transient from an earlier, unrelated sync.
        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'attachment_id' => 99,
            'post_count' => 7,
            'time' => time() - 60,
        );

        $this->cli->sync(array('42'), array());

        $this->assertNotEmpty($altrelay_test_state['cli_success']);
        $this->assertStringContainsString('Updated 0 posts', $altrelay_test_state['cli_success'][0]);
        $this->assertStringNotContainsString('7 post', $altrelay_test_state['cli_success'][0]);
    }

    /**
     * Test that sync reports a singular count for one updated post.
     */
    public function test_sync_reports_singular_count(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['posts'][42] = (object) array(
            'ID' => 42,
            'post_type' => 'attachment',
        );
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Library alt',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->cli->sync(array('42'), array());

        $this->assertStringContainsString('Updated 1 post.', $altrelay_test_state['cli_success'][0]);
    }

    /**
     * Test that a sync leaves the background queue alone.
     *
     * A queued run reads the alt text when it runs, so it can no longer
     * undo what this command writes, and nothing has to be cancelled.
     */
    public function test_sync_leaves_the_queue_alone(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->attachment_with_alt();
        $this->post_with_image(10);
        $altrelay_test_state['as_pending'] = array(array(42, 0, 0));

        $this->cli->sync(array('42'), array());

        $this->assertEmpty($altrelay_test_state['as_unscheduled']);
        $this->assertEmpty($altrelay_test_state['as_actions'], 'Saving a post during a sync must not queue anything.');
        $this->assertEmpty($altrelay_test_state['as_queries'] ?? array());
    }

    /**
     * Test that audit reports the coverage figures.
     */
    public function test_audit_reports_stats(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('10', '7', '1');
        $wpdb->stub_results_queue = array(array());

        $this->cli->audit(array(), array());

        $this->assertNotEmpty($altrelay_test_state['cli_formatted']);

        $row = $altrelay_test_state['cli_formatted'][0]['items'][0];

        $this->assertSame(10, $row['total']);
        $this->assertSame(7, $row['with_alt']);
        $this->assertSame(2, $row['without'], 'An image marked decorative wherever it is used has no alt text on purpose.');
        $this->assertSame(1, $row['decorative']);

        // Regression (sixth audit): images without alt text on purpose
        // counted as not covered.
        $this->assertSame('80%', $row['coverage']);
    }

    /**
     * Test that audit handles a site with no images.
     */
    public function test_audit_handles_empty_library(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('0', '0', '0');
        $wpdb->stub_results_queue = array(array());

        $this->cli->audit(array(), array());

        $row = $altrelay_test_state['cli_formatted'][0]['items'][0];

        $this->assertSame('n/a', $row['coverage']);
    }

    /**
     * Give a post content that embeds attachment 42.
     *
     * @param int    $post_id Post ID.
     * @param string $alt     Current alt in the post.
     */
    private function post_with_image(int $post_id, string $alt = 'old'): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_fields'][$post_id] = array(
            'post_content' => '<img src="a.jpg" alt="' . $alt . '" class="wp-image-42" />',
            'post_title' => 'Post ' . $post_id,
        );
        $altrelay_test_state['posts'][$post_id] = (object) array('ID' => $post_id, 'post_type' => 'post');
    }

    /**
     * Register attachment 42 with alt text.
     *
     * @param string $alt Library alt text.
     */
    private function attachment_with_alt(string $alt = 'Library alt'): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['posts'][42] = (object) array('ID' => 42, 'post_type' => 'attachment');
        $altrelay_test_state['post_types_by_id'][42] = 'attachment';
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = $alt;
    }

    /**
     * Test that totals add up across batches.
     *
     * Regression test: the per-attachment line used to report only the last
     * batch's count.
     */
    public function test_sync_totals_span_batches(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        foreach (array(10, 11, 12) as $id) {
            $this->post_with_image($id);
        }

        $wpdb = new wpdb();
        $wpdb->stub_col_results_queue = array(array('10', '11', '12'), array('11', '12'), array('12'));

        $this->cli->sync(array('42'), array('batch' => '1'));

        $this->assertSame(array('Updated 3 posts.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that a dry run lists the posts it would change.
     */
    public function test_sync_dry_run_lists_posts(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        $this->post_with_image(10);

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array('42'), array('dry-run' => true));

        $this->assertSame(array('Would update post 10 ("Post 10").'), $altrelay_test_state['cli_log']);
        $this->assertSame(array('Dry run: 1 post would be updated.'), $altrelay_test_state['cli_success']);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that several attachments get a progress bar.
     */
    public function test_sync_multiple_ids_shows_progress(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        $altrelay_test_state['posts'][43] = (object) array('ID' => 43, 'post_type' => 'attachment');
        $altrelay_test_state['post_meta'][43]['_wp_attachment_image_alt'] = 'Other';

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->cli->sync(array('42', '43'), array());

        $bar = $altrelay_test_state['progress_bars'][0];
        $this->assertSame(2, $bar['count']);
        $this->assertSame(2, $bar['bar']->ticks);
        $this->assertTrue($bar['bar']->finished);
    }

    /**
     * Test that duplicate IDs are synced once.
     */
    public function test_sync_deduplicates_ids(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->cli->sync(array('42', '42'), array());

        $this->assertEmpty($altrelay_test_state['progress_bars'] ?? array(), 'One unique ID needs no progress bar.');
    }

    /**
     * Test that posts the --user cannot edit are reported.
     */
    public function test_sync_reports_skipped_posts(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        $this->post_with_image(10);
        $altrelay_test_state['user_logged_in'] = true;
        $altrelay_test_state['user_caps_for']['edit_post'][10] = false;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array('42'), array());

        $this->assertStringContainsString('1 post was skipped', $altrelay_test_state['cli_warnings'][0]);
    }

    /**
     * Test that --all on a site without images finishes cleanly.
     */
    public function test_sync_all_with_nothing_to_do(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->cli->sync(array(), array('all' => true));

        $this->assertSame(array('Updated 0 posts.'), $altrelay_test_state['cli_success']);
        $this->assertEmpty($altrelay_test_state['cli_warnings']);
        $this->assertEmpty($altrelay_test_state['cli_errors']);
    }

    /**
     * Test that sync without IDs or --all is an error.
     */
    public function test_sync_requires_ids_or_all(): void
    {
        global $altrelay_test_state;

        $this->cli->sync(array(), array());

        $this->assertSame(array('Please provide an attachment ID, or use --all.'), $altrelay_test_state['cli_errors']);
    }

    /**
     * Test that audit --ids lists IDs without running the stats queries.
     */
    public function test_audit_ids_lists_missing(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('5', '9');

        $this->cli->audit(array(), array('ids' => true));

        $this->assertSame(array('5', '9'), $altrelay_test_state['cli_lines']);
        $this->assertCount(1, $wpdb->queries, 'Only the ID query should run.');
    }

    /**
     * Test that audit --format=count prints the number of images without alt.
     */
    public function test_audit_count_format(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('10', '7');

        $this->cli->audit(array(), array('format' => 'count'));

        $this->assertSame(array('3'), $altrelay_test_state['cli_lines']);
    }

    /**
     * Prepare one review candidate: post 10 with an empty alt on image 42.
     */
    private function review_candidate(): void
    {
        global $wpdb;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, '');

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('1');
        $wpdb->stub_col_results = array('10');
    }

    /**
     * Test that empty-alt lists review rows.
     */
    public function test_empty_alt_lists_rows(): void
    {
        global $altrelay_test_state;

        $this->review_candidate();

        $this->cli->empty_alt(array(), array('format' => 'csv'));

        $formatted = $altrelay_test_state['cli_formatted'][0];
        $this->assertSame('csv', $formatted['format']);
        $this->assertSame(
            array(array('post_id' => 10, 'post_title' => 'Post 10', 'attachment_id' => 42, 'library_alt' => 'Dog', 'copies' => 1, 'linked' => 0)),
            $formatted['items']
        );
    }

    /**
     * Test that empty-alt --format=count prints the number of rows.
     */
    public function test_empty_alt_count(): void
    {
        global $altrelay_test_state;

        $this->review_candidate();

        $this->cli->empty_alt(array(), array('format' => 'count'));

        $this->assertSame(array('1'), $altrelay_test_state['cli_lines']);
    }

    /**
     * Test that empty-alt reports a clean site.
     */
    public function test_empty_alt_with_nothing_to_review(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('0');

        $this->cli->empty_alt(array(), array());

        $this->assertSame(array('No empty alt text to review.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that fill writes library alt into every image of a post.
     */
    public function test_fill_whole_post(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, '');

        $this->cli->fill(array('10'), array());

        $this->assertStringContainsString('alt="Dog"', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertSame(array('Filled alt text in post 10.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that fill rejects an unknown post.
     */
    public function test_fill_rejects_unknown_post(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['cli_error_throws'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Please provide a valid post ID.');

        $this->cli->fill(array('999'), array());
    }

    /**
     * Test that mark-decorative marks the image.
     */
    public function test_mark_decorative(): void
    {
        global $altrelay_test_state;

        $this->post_with_image(10, 'Dog');

        $this->cli->mark_decorative(array('10', '42'), array());

        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertSame(array('Marked images as decorative in post 10.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that mark-decorative needs an attachment ID.
     */
    public function test_mark_decorative_requires_attachment(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['cli_error_throws'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one attachment ID');

        $this->cli->mark_decorative(array('10'), array());
    }

    /**
     * Test that running mark-decorative twice reports nothing to change.
     */
    public function test_mark_decorative_twice(): void
    {
        global $altrelay_test_state;

        $this->post_with_image(10, 'Dog');

        $this->cli->mark_decorative(array('10', '42'), array());
        $this->cli->mark_decorative(array('10', '42'), array());

        $this->assertSame('Nothing to change.', $altrelay_test_state['cli_success'][1]);
    }

    /**
     * Test that an empty review still prints valid JSON.
     *
     * Regression test: scripts parsing --format=json received a success
     * sentence instead of "[]" on a clean site.
     */
    public function test_empty_alt_json_is_valid_when_empty(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array('0');

        $this->cli->empty_alt(array(), array('format' => 'json'));

        $this->assertSame('json', $altrelay_test_state['cli_formatted'][0]['format']);
        $this->assertSame(array(), $altrelay_test_state['cli_formatted'][0]['items']);
        $this->assertEmpty($altrelay_test_state['cli_success']);
    }

    /**
     * Regression (audit, WP-CLI): sync --all saves a post once, however many
     * images it holds, instead of once per image.
     */
    public function test_sync_all_saves_each_post_once(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_meta'][43]['_wp_attachment_image_alt'] = 'Cat';
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" /><img src="b.jpg" alt="" class="wp-image-43" />',
        );

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array(), array('all' => true));

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(
            '<img src="a.jpg" alt="Dog" class="wp-image-42" /><img src="b.jpg" alt="Cat" class="wp-image-43" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
        $this->assertSame(array('Updated 1 post.'), $altrelay_test_state['cli_success']);
        $this->assertEmpty($altrelay_test_state['progress_bars'] ?? array());
    }

    /**
     * Test a dry run of sync --all.
     */
    public function test_sync_all_dry_run(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $this->post_with_image(10);

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array(), array('all' => true, 'dry-run' => true));

        $this->assertSame(array('Would update post 10 ("Post 10").'), $altrelay_test_state['cli_log']);
        $this->assertSame(array('Dry run: 1 post would be updated.'), $altrelay_test_state['cli_success']);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that --post-type limits both kinds of sync.
     */
    public function test_sync_can_be_limited_to_post_types(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        $this->cli->sync(array(), array('all' => true, 'post-type' => 'page, post,page'));

        $this->assertStringContainsString("post_type IN ('post','page')", $wpdb->last_query);

        $this->attachment_with_alt();
        $this->cli->sync(array('42'), array('post-type' => 'page'));

        $this->assertStringContainsString("post_type IN ('page')", $wpdb->last_query);
        $this->assertEmpty($altrelay_test_state['cli_errors']);
    }

    /**
     * Test that a post type AltRelay does not sync is an error, not a
     * silent run over nothing.
     */
    public function test_sync_rejects_unknown_post_types(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['cli_error_throws'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AltRelay does not sync the post type "nav_menu_item". Available: post, page.');

        $this->cli->sync(array(), array('all' => true, 'post-type' => 'nav_menu_item'));
    }

    /**
     * Test that a non-string --post-type (a bare flag) means "all types".
     */
    public function test_sync_ignores_a_bare_post_type_flag(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        $this->cli->sync(array(), array('all' => true, 'post-type' => true));

        $this->assertStringContainsString("post_type IN ('post','page')", $wpdb->last_query);
        $this->assertEmpty($altrelay_test_state['cli_errors']);
    }

    /**
     * Test that posts that could not be saved are reported.
     *
     * Regression (fifth audit): the command printed a success and a warning
     * and exited with status 0, so scripts could not tell. It ends with an
     * error now, which exits with status 1.
     */
    public function test_sync_reports_failed_posts(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->attachment_with_alt();
        $this->post_with_image(10);
        $altrelay_test_state['fail_update_post'] = true;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array('42'), array());

        $this->assertArrayNotHasKey('cli_success', array_filter($altrelay_test_state));
        $this->assertSame(array('Updated 0 posts.'), $altrelay_test_state['cli_log']);
        $this->assertSame(
            array('1 post could not be saved. Run "wp altrelay status" for the last error.'),
            $altrelay_test_state['cli_errors']
        );
    }

    /**
     * Test that a post command whose save fails ends with an error, not
     * with "Nothing to change".
     */
    public function test_post_command_reports_a_failed_save(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt();
        $this->post_with_image(10, '');
        $altrelay_test_state['fail_update_post'] = true;

        $this->cli->fill(array('10', '42'), array());

        $this->assertSame(array('Post 10 could not be saved. Run "wp altrelay status" for the error.'), $altrelay_test_state['cli_errors']);
        $this->assertEmpty($altrelay_test_state['cli_success'] ?? array());
    }

    /**
     * Regression (fifth audit): sync --all reached every post but kept the
     * images it would offer to run again.
     */
    public function test_sync_all_forgets_the_images_to_run_again(): void
    {
        global $wpdb;

        $this->attachment_with_alt();
        $this->post_with_image(10);
        AltRelay_Sync::record_error(0, 'Stopped.', 42);

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->sync(array(), array('all' => true, 'dry-run' => true));
        $this->assertSame(array(42), AltRelay_Sync::images_to_retry(), 'A dry run changes nothing.');

        $this->cli->sync(array(), array('all' => true));
        $this->assertSame(array(), AltRelay_Sync::images_to_retry());
    }

    /**
     * Regression (fifth audit): the review lists had no --post-type filter,
     * which the dashboard has.
     */
    public function test_review_lists_can_be_narrowed_to_post_types(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->empty_alt(array(), array('format' => 'count', 'post-type' => 'page'));

        $this->assertSame(array('1'), $altrelay_test_state['cli_lines']);
        $this->assertStringContainsString("post_type IN ('page')", $wpdb->last_query);

        $this->cli->decorative(array(), array('format' => 'count', 'post-type' => 'post,page'));

        $types = array_values(array_filter($wpdb->queries, static fn(string $query): bool => str_contains($query, 'altrelay-decorative')));
        $this->assertStringContainsString("post_type IN ('post')", $types[0]);
        $this->assertStringContainsString("post_type IN ('page')", $types[1]);
    }

    /**
     * Regression (audit, WP-CLI): fill and mark-decorative honour --user
     * the way sync does.
     */
    public function test_post_commands_honour_the_user(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, '');
        $altrelay_test_state['user_logged_in'] = true;
        $altrelay_test_state['user_caps_for']['edit_post'][10] = false;

        $this->cli->fill(array('10'), array());
        $this->cli->mark_decorative(array('10', '42'), array());
        $this->cli->unmark_decorative(array('10', '42'), array());

        $this->assertSame(
            array_fill(0, 3, 'The --user you ran as cannot edit post 10.'),
            $altrelay_test_state['cli_errors']
        );
        $this->assertEmpty($altrelay_test_state['updated_posts']);
        $this->assertEmpty($altrelay_test_state['cli_success']);
    }

    /**
     * Test that a user who may edit the post gets through.
     */
    public function test_post_commands_work_for_a_user_who_may_edit(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, '');
        $altrelay_test_state['user_logged_in'] = true;

        $this->cli->fill(array('10', '42'), array());

        $this->assertSame(array('Filled alt text in post 10.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test unmark-decorative.
     */
    public function test_unmark_decorative(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, 'Dog');

        $this->cli->mark_decorative(array('10', '42'), array());
        $this->cli->unmark_decorative(array('10', '42'), array());
        $this->cli->unmark_decorative(array('10', '42'), array());

        $this->assertSame('<img src="a.jpg" alt="Dog" class="wp-image-42" />', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertSame(
            array('Marked images as decorative in post 10.', 'Removed the decorative mark in post 10.', 'Nothing to change.'),
            $altrelay_test_state['cli_success']
        );
    }

    /**
     * Test that unmark-decorative needs an attachment ID.
     */
    public function test_unmark_decorative_requires_attachment(): void
    {
        global $altrelay_test_state;

        $this->cli->unmark_decorative(array('10'), array());

        $this->assertSame(array('Please provide a post ID and at least one attachment ID.'), $altrelay_test_state['cli_errors']);
        $this->assertEmpty($altrelay_test_state['cli_success']);
    }

    /**
     * Regression (audit bug 7): mark-decorative says when it refused a
     * linked image.
     */
    public function test_mark_decorative_reports_refused_links(): void
    {
        global $altrelay_test_state;

        $this->post_with_image(10);
        $altrelay_test_state['post_fields'][10]['post_content'] = '<a href="https://example.com/"><img src="a.jpg" alt="Dog" class="wp-image-42" /></a>';

        $this->cli->mark_decorative(array('10', '42'), array());

        $this->assertSame(
            array('1 image was not marked because it is the only content of a link and needs alt text.'),
            $altrelay_test_state['cli_warnings']
        );
        $this->assertSame(array('Nothing to change.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test the list of decorative images.
     */
    public function test_decorative_lists_rows(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->post_with_image(10);
        $altrelay_test_state['post_fields'][10]['post_content'] = '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />';

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->decorative(array(), array('format' => 'json'));

        $this->assertSame(
            array(array('post_id' => 10, 'post_title' => 'Post 10', 'attachment_id' => 42, 'library_alt' => '', 'copies' => 1, 'linked' => 0)),
            $altrelay_test_state['cli_formatted'][0]['items']
        );
        $this->assertStringContainsString('altrelay-decorative', $wpdb->last_query);

        $altrelay_test_state['cli_lines'] = array();
        $this->cli->decorative(array(), array('format' => 'count'));

        $this->assertSame(array('1'), $altrelay_test_state['cli_lines']);
    }

    /**
     * Test the decorative list when there is nothing to show.
     */
    public function test_decorative_with_nothing_marked(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        $this->cli->decorative(array(), array());

        $this->assertSame(array('No images are marked decorative.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that a review list is read to its end, page by page.
     */
    public function test_review_lists_are_read_to_the_end(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';

        $first = range(300, 201);
        $second = range(200, 196);

        foreach (array_merge($first, $second) as $id) {
            $altrelay_test_state['post_fields'][$id] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        }

        $wpdb = new wpdb();
        // A window of posts and the candidates in it, for each chunk.
        $wpdb->stub_col_results_queue = array(array_map('strval', $first), array_map('strval', $first), array_map('strval', $second), array_map('strval', $second));

        $this->cli->empty_alt(array(), array('format' => 'count'));

        $this->assertSame(array('105'), $altrelay_test_state['cli_lines']);
    }

    /**
     * Test the status command.
     *
     * Regression (audit, WP-CLI): there was no way to see the queue.
     */
    public function test_status_reports_queue_and_last_sync(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['as_pending'] = array(array(1, 0, 0), array(2, 0, 0));
        $altrelay_test_state['as_failed'] = array(array(3, 0, 0));
        $altrelay_test_state['options']['altrelay_review_first'] = '1';
        $altrelay_test_state['transients']['altrelay_last_sync'] = array('post_count' => 12, 'time' => 1700000000);
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array('count' => 2, 'post_id' => 10, 'message' => 'Could not update post', 'images' => array(42, '43', 'x'));

        $this->cli->status(array(), array('format' => 'json'));

        $formatted = $altrelay_test_state['cli_formatted'][0];

        $this->assertSame('json', $formatted['format']);
        $this->assertSame(
            array(
                'backend' => 'action-scheduler',
                'pending' => 2,
                'failed_jobs' => 1,
                'review_first' => 'on',
                'last_sync_posts' => 12,
                'skipped_posts' => 0,
                'last_sync_time' => '2023-11-14 22:13:20',
                'failed_posts' => 2,
                'last_failed_post' => 10,
                'last_error' => 'Could not update post',
                'retry_images' => '42 43',
            ),
            $formatted['items'][0]
        );
        $this->assertSame(array_keys($formatted['items'][0]), $formatted['fields']);
    }

    /**
     * Test the status of a site where nothing has happened yet.
     */
    public function test_status_of_an_idle_site(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);

        $this->cli->status(array(), array());

        $row = $altrelay_test_state['cli_formatted'][0]['items'][0];

        $this->assertSame('table', $altrelay_test_state['cli_formatted'][0]['format']);
        $this->assertSame('wp-cron', $row['backend']);
        $this->assertSame(0, $row['pending']);
        $this->assertSame('off', $row['review_first']);
        $this->assertSame('', $row['last_sync_time']);
        $this->assertSame('', $row['last_error']);
    }

    /**
     * Regression (second audit, WP-CLI): an empty audit --ids printed a
     * sentence, which broke $(wp altrelay audit --ids) in scripts.
     */
    public function test_audit_ids_prints_nothing_when_nothing_is_missing(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->cli->audit(array(), array('format' => 'ids'));

        $this->assertEmpty($altrelay_test_state['cli_lines'] ?? array());
        $this->assertEmpty($altrelay_test_state['cli_log'] ?? array());
    }

    /**
     * Regression (second audit, WP-CLI): fill, mark-decorative and
     * unmark-decorative had no dry run.
     */
    public function test_per_post_commands_have_a_dry_run(): void
    {
        global $altrelay_test_state;

        $this->attachment_with_alt('Dog');
        $this->post_with_image(10, '');
        $before = $altrelay_test_state['post_fields'][10]['post_content'];

        $this->cli->fill(array('10'), array('dry-run' => true));
        $this->cli->mark_decorative(array('10', '42'), array('dry-run' => true));

        $this->post_with_image(11, '');
        $this->cli->mark_decorative(array('11', '42'), array());
        $marked = $altrelay_test_state['post_fields'][11]['post_content'];
        $this->cli->unmark_decorative(array('11', '42'), array('dry-run' => true));

        $this->assertSame($before, $altrelay_test_state['post_fields'][10]['post_content'], 'A dry run saves nothing.');
        $this->assertSame($marked, $altrelay_test_state['post_fields'][11]['post_content']);
        $this->assertSame(
            array(
                'Dry run: alt text would be filled in post 10.',
                'Dry run: images would be marked as decorative in post 10.',
                'Marked images as decorative in post 11.',
                'Dry run: the decorative mark would be removed in post 11.',
            ),
            $altrelay_test_state['cli_success']
        );
    }


    /**
     * Test wp altrelay run-queue, and that status restarts a lost worker run.
     */
    public function test_run_queue_runs_the_waiting_jobs(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['post_types_by_id'] = array();
        update_option('altrelay_queue', array('waiting' => array('1:0' => array(1, 0, 0), '2:0' => array(2, 0, 0)), 'running' => array()));

        $this->cli->status(array(), array('format' => 'json'));
        $this->assertNotEmpty($altrelay_test_state['scheduled_events'] ?? array(), 'status restarts a lost worker run.');

        $this->cli->run_queue(array(), array());

        $this->assertSame(array('Ran 2 background updates; 0 left.'), $altrelay_test_state['cli_success']);
        $this->assertSame(0, AltRelay_Queue::pending_count());
    }

    /**
     * Regression (sixth audit): with Action Scheduler, run-queue said "Ran
     * 0 background updates; 0 left" while updates waited there. It says
     * where they are and how to run them.
     */
    public function test_run_queue_points_to_action_scheduler(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['as_pending'] = array(array(1, 0, 0), array(2, 0, 0));

        $this->cli->run_queue(array(), array());

        $this->assertSame(array('Action Scheduler runs AltRelay\'s updates on this site; 2 are waiting. Run them with "wp action-scheduler run --hooks=altrelay_process_sync".'), $altrelay_test_state['cli_warnings']);
        $this->assertEmpty($altrelay_test_state['cli_success'] ?? array());
    }

    /**
     * Regression (sixth audit): the dashboard's "Mark decorative" and "List
     * as missing again" had no command.
     */
    public function test_images_can_be_marked_decorative_everywhere_and_listed_again(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_types_by_id'][42] = 'attachment';
        $altrelay_test_state['post_types_by_id'][43] = 'attachment';
        $altrelay_test_state['post_meta'][43]['_wp_attachment_image_alt'] = 'Described';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $this->cli->mark_image_decorative(array('42', '43'), array());

        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertSame(array('Image 42: marked decorative in 1 post, and no longer listed as missing alt text.'), $altrelay_test_state['cli_log']);
        $this->assertSame(array('Attachment 43 is not an image without alt text, skipping.'), $altrelay_test_state['cli_warnings']);
        $this->assertSame(array('Done.'), $altrelay_test_state['cli_success']);

        $this->cli->list_image_again(array('42', '44'), array());

        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertSame('Image 44 was not marked decorative wherever it is used.', end($altrelay_test_state['cli_warnings']));
        $this->assertSame('1 image is listed as missing alt text again.', end($altrelay_test_state['cli_success']));
    }

    /**
     * Test that marking that cannot reach every copy says so, and that a
     * save that failed ends with an error.
     */
    public function test_marking_an_image_everywhere_reports_what_it_left(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_types_by_id'][42] = 'attachment';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<p><a href="/x"><img src="a.jpg" alt="" class="wp-image-42" /></a></p>');
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        $altrelay_test_state['fail_update_post'] = true;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $this->cli->mark_image_decorative(array('42'), array());

        $this->assertStringStartsWith('Image 42: marked decorative in 0 posts, but not everywhere. Copies that are a link\'s only content: 1.', $altrelay_test_state['cli_warnings'][0]);
        $this->assertSame(array('1 post could not be saved. Run "wp altrelay status" for the last error.'), $altrelay_test_state['cli_errors']);
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));

        $this->cli->mark_image_decorative(array(), array());
        $this->assertSame('Please provide at least one attachment ID.', end($altrelay_test_state['cli_errors']));
        $this->cli->list_image_again(array(), array());
        $this->assertSame('Please provide at least one attachment ID.', end($altrelay_test_state['cli_errors']));
    }

    /**
     * Regression (fourth audit, i18n): one job was reported as "Ran 1
     * background updates".
     */
    public function test_run_queue_reports_one_job_in_the_singular(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['post_types_by_id'] = array();
        update_option('altrelay_queue', array('waiting' => array('1:0' => array(1, 0, 0)), 'running' => array()));

        $this->cli->run_queue(array(), array());

        $this->assertSame(array('Ran 1 background update; 0 left.'), $altrelay_test_state['cli_success']);
    }

    /**
     * Test that syncing an image that had to be run again takes it off the
     * list, unless some of its posts still failed or were limited to types.
     */
    public function test_sync_takes_a_finished_image_off_the_retry_list(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['posts'][42] = (object) array('ID' => 42, 'post_type' => 'attachment');
        $altrelay_test_state['posts'][43] = (object) array('ID' => 43, 'post_type' => 'attachment');
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => 'Other alt');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        AltRelay_Sync::record_error(10, 'Broke', 42);
        AltRelay_Sync::record_error(11, 'Broke', 43);

        $this->cli->sync(array('42'), array('dry-run' => true));
        $this->assertSame(array(43, 42), AltRelay_Sync::images_to_retry(), 'A dry run changes nothing.');

        $this->cli->sync(array('42'), array());
        $this->assertSame(array(43), AltRelay_Sync::images_to_retry());
    }
}
