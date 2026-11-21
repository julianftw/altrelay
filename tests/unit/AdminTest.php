<?php
/**
 * Unit tests for AltRelay_Admin.
 *
 * Tests the dashboard: menu registration, capability checks, the welcome
 * notice, rendering of every section, and the form handlers.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class AdminTest
 */
class AdminTest extends TestCase
{

    /**
     * The admin instance.
     *
     * @var AltRelay_Admin
     */
    private AltRelay_Admin $admin;

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
            'user_caps' => array('upload_files' => true, 'edit_post' => true, 'manage_options' => true),
            'post_fields' => array(),
            'is_admin' => true,
            'user_logged_in' => true,
            'updated_posts' => array(),
            'cache_cleaned' => array(),
            'hooks' => array(),
            'menu_pages' => array(),
            'enqueued_styles' => array(),
            'enqueued_scripts' => array(),
            'redirected_to' => null,
        );

        $wpdb = new wpdb();

        $this->admin = new AltRelay_Admin();
    }

    /**
     * Reset the request.
     */
    protected function tear_down(): void
    {
        $_POST = array();
        $_GET = array();
        parent::tear_down();
    }

    /**
     * Render the dashboard against a described database.
     *
     * The queries run in a fixed order: two counts, the page of images
     * without alt text and their usage (only when some are missing), then
     * the candidates of the two review lists.
     *
     * @param array<string, mixed> $db total, with_alt, missing, usage, review, decorative.
     * @return string
     */
    private function render(array $db = array()): string
    {
        global $wpdb;

        $total = $db['total'] ?? 1;

        $wpdb = new wpdb();
        $wpdb->stub_var_results = array((string) $total, (string) ($db['with_alt'] ?? $total), (string) ($db['recorded_count'] ?? 0));

        $queue = array();

        if (isset($db['missing'])) {
            $queue[] = array_map('strval', $db['missing']);
            $wpdb->stub_row_queue = array($db['usage'] ?? array());
        }

        if (isset($db['recorded'])) {
            $queue[] = array_map('strval', $db['recorded']);
        }

        // The content lists each have a tab of their own.
        if (!isset($_GET['tab'])) {
            if (isset($db['review']) || isset($db['review_chunks'])) {
                $_GET['tab'] = 'content';
            } elseif (isset($db['decorative']) || isset($db['decorative_chunks'])) {
                $_GET['tab'] = 'decorative';
            }
        }

        // Only the open tab's list is read. Each chunk of candidates comes
        // from two queries: the window of posts with images, then the
        // candidates within it. An empty window ends the list after the first.
        $lists = array('content' => 'review', 'decorative' => 'decorative');
        $list = $lists[$_GET['tab'] ?? ''] ?? null;

        if (null !== $list) {
            foreach ($db[$list . '_chunks'] ?? array($db[$list] ?? array()) as $chunk) {
                $queue[] = array_map('strval', $chunk);

                if (!empty($chunk)) {
                    $queue[] = array_map('strval', $chunk);
                }
            }
        }

        $wpdb->stub_col_results_queue = $queue;

        ob_start();
        $this->admin->render_dashboard();
        return (string) ob_get_clean();
    }

    /**
     * Run a form handler and return the URL it redirected to.
     *
     * @param callable $handler The handler.
     * @return string
     */
    private function run_handler(callable $handler): string
    {
        global $altrelay_test_state;
        $altrelay_test_state['throw_on_redirect'] = true;

        try {
            $handler();
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Redirect: ', $e->getMessage());
            return (string) $altrelay_test_state['redirected_to'];
        }

        $this->fail('Expected the handler to redirect.');
    }

    /**
     * Give posts 10 and 11 an empty-alt copy of image 42, whose library alt is "Dog".
     */
    private function review_fixture(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        foreach (array(10, 11) as $id) {
            $altrelay_test_state['post_fields'][$id] = array(
                'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />',
                'post_title' => 'Post ' . $id,
            );
        }
    }

    /**
     * Test that register() hooks everything the dashboard needs.
     */
    public function test_register_hooks(): void
    {
        global $altrelay_test_state;

        $this->admin->register();

        foreach (
            array(
                'admin_menu',
                'admin_enqueue_scripts',
                'admin_notices',
                'admin_post_altrelay_save_alts',
                'admin_post_altrelay_review',
                'admin_post_altrelay_review_first',
                'admin_post_altrelay_clear_errors',
                'admin_post_altrelay_dismiss_welcome',
                'plugin_action_links_altrelay/altrelay.php',
            ) as $hook
        ) {
            $this->assertArrayHasKey($hook, $altrelay_test_state['hooks']);
        }
    }

    /**
     * Test that the dashboard is a Media submenu for users who can upload.
     */
    public function test_add_menu_page_registers_menu(): void
    {
        global $altrelay_test_state;

        $this->admin->add_menu_page();

        $this->assertSame(
            array(array('parent' => 'upload.php', 'slug' => 'altrelay', 'capability' => 'upload_files')),
            $altrelay_test_state['menu_pages']
        );
    }

    /**
     * Test that the dashboard refuses users without upload_files.
     */
    public function test_render_dashboard_blocks_unauthorized_users(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['upload_files'] = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die');

        $this->admin->render_dashboard();
    }

    /**
     * Test the dashboard of a library where every image is described.
     */
    public function test_render_dashboard_shows_all_clear_notice(): void
    {
        $output = $this->render(array('total' => 5));

        $this->assertStringContainsString('AltRelay Dashboard', $output);
        $this->assertStringContainsString('5 of 5 images have alt text', $output);
        $this->assertStringContainsString('Every image has alt text or is marked decorative', $output);

        $_GET['tab'] = 'content';
        $this->assertStringContainsString('No empty alt text to review.', $this->render(array('total' => 5)));

        $_GET['tab'] = 'decorative';
        $this->assertStringContainsString('No images are marked decorative.', $this->render(array('total' => 5)));
    }

    /**
     * Regression (sixth audit, Performance): every visit read both content
     * lists, parsing up to a thousand posts. Each list has a tab now, and
     * only the open one is read.
     */
    public function test_each_list_has_a_tab_and_only_the_open_one_is_read(): void
    {
        global $wpdb;

        $output = $this->render(array('total' => 10, 'with_alt' => 7, 'missing' => array(1)));

        $this->assertStringContainsString('<a href="https://example.com/wp-admin/upload.php?page=altrelay" class="nav-tab nav-tab-active" aria-current="page">Images without alt text (3)</a>', $output);
        $this->assertStringContainsString('<a href="https://example.com/wp-admin/upload.php?page=altrelay&tab=content" class="nav-tab">Empty alt text in your content</a>', $output);
        $this->assertStringContainsString('<a href="https://example.com/wp-admin/upload.php?page=altrelay&tab=decorative" class="nav-tab">Images marked decorative</a>', $output);
        $this->assertCount(0, array_filter($wpdb->queries, static fn(string $q): bool => str_contains($q, 'ORDER BY ID DESC')), 'No content list is read.');

        $_GET = array('tab' => 'decorative', 'altrelay_type' => 'page');
        $output = $this->render(array('decorative' => array()));

        $this->assertStringContainsString('class="nav-tab nav-tab-active" aria-current="page">Images marked decorative</a>', $output);
        $this->assertStringContainsString('page=altrelay&tab=content&altrelay_type=page"', $output, 'The filter goes along.');
        $this->assertStringContainsString('<input type="hidden" name="tab" value="decorative" />', $output);
        $this->assertStringNotContainsString('Images without alt text in the Media Library', $output);

        // A link from before the tabs still opens its list.
        $_GET = array('review_before' => '50');
        $this->assertStringContainsString('id="altrelay-review"', $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7))));
    }

    /**
     * Regression (sixth audit, i18n): "1 of 1 images have alt text". Each
     * number now has a sentence, and a plural form, of its own.
     */
    public function test_summary_follows_the_plural_of_each_number(): void
    {
        $this->assertStringContainsString('1 of 1 image has alt text.', $this->render(array('total' => 1, 'with_alt' => 1)));
        AltRelay_Report::flush_stats();
        $this->assertStringContainsString('3 of 7 images have alt text. 2 more images are marked decorative wherever they are used.', $this->render(array('total' => 7, 'with_alt' => 3, 'recorded_count' => 2)));
    }

    /**
     * Test the dashboard of an empty library.
     */
    public function test_render_dashboard_with_empty_library(): void
    {
        $output = $this->render(array('total' => 0));

        $this->assertStringContainsString('No images found in the Media Library yet.', $output);
        $this->assertStringNotContainsString('Images without alt text in the Media Library', $output);
    }

    /**
     * Test the table of images without alt text.
     */
    public function test_render_dashboard_shows_missing_images(): void
    {
        $output = $this->render(
            array(
                'total' => 10,
                'with_alt' => 7,
                'missing' => array(1, 2, 3),
                'usage' => array('2', '0', '5'),
            )
        );

        $this->assertStringContainsString('Images without alt text in the Media Library', $output);
        $this->assertStringContainsString('7 of 10 images have alt text', $output);
        $this->assertStringContainsString('test-image-1.jpg', $output);
        $this->assertStringContainsString('test-image-2.jpg', $output);
        $this->assertStringContainsString('test-image-3.jpg', $output);
        $this->assertStringContainsString('2 posts', $output);
        $this->assertStringContainsString('0 posts', $output);
        $this->assertStringContainsString('5 posts', $output);
        $this->assertStringContainsString('value="nonce-altrelay_save_alts"', $output);
    }

    /**
     * Test that every alt text field is labelled, limited and described.
     *
     * Regression (audit, Accessibility): the 500 character limit used to
     * cut text off without telling anyone.
     */
    public function test_missing_table_fields_are_labelled_and_show_the_limit(): void
    {
        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7)));

        $this->assertStringContainsString('<label class="screen-reader-text" for="altrelay-alt-7">', $output);
        $this->assertStringContainsString('Alt text for test-image-7.jpg', $output);
        $this->assertStringContainsString('id="altrelay-alt-7" name="alt[7]" value="" maxlength="500" aria-describedby="altrelay-alt-hint"', $output);
        $this->assertStringContainsString('<p id="altrelay-alt-hint">', $output);
        $this->assertStringContainsString('up to 500 characters long', $output);
    }

    /**
     * Test that controls are named with whole sentences.
     *
     * Regression (audit, i18n): "Edit" plus "%s in the Media Library" was
     * two fragments a translator could not reorder.
     */
    public function test_controls_are_named_with_whole_strings(): void
    {
        $this->review_fixture();

        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7)));
        $this->assertStringContainsString('aria-label="Edit test-image-7.jpg in the Media Library"', $output);

        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'review' => array(10)));
        $this->assertStringContainsString('aria-label="Fill alt text in &quot;Post 10&quot;"', $output);
        $this->assertStringContainsString('aria-label="Mark decorative in &quot;Post 10&quot;"', $output);
        $this->assertStringNotContainsString('<span class="screen-reader-text">in ', $output);
    }

    /**
     * Test that a thumbnail links to the full image, so it can be judged.
     */
    public function test_thumbnails_link_to_the_full_image(): void
    {
        global $altrelay_test_state;

        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7)));

        // Regression (fifth audit, WCAG 2.5.3 and 2.4.4): every thumbnail
        // link had the same name. The thumbnail's alt text now says which
        // image the link opens.
        $this->assertStringContainsString('<a href="https://example.com/wp-content/uploads/image-7.jpg"><img src="test.jpg" alt="View the full image of test-image-7.jpg" /></a>', $output);

        $altrelay_test_state['no_attachment_url'] = true;
        $altrelay_test_state['transients'] = array();

        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7)));

        $this->assertStringContainsString('<img src="test.jpg" alt="" />', $output);
        $this->assertStringNotContainsString('View the full image', $output);
    }

    /**
     * Test that no pagination is rendered for a single page.
     */
    public function test_no_pagination_single_page(): void
    {
        $output = $this->render(array('total' => 10, 'with_alt' => 7, 'missing' => array(1, 2, 3)));

        $this->assertStringNotContainsString('tablenav-pages', $output);
    }

    /**
     * Test pagination of the missing-alt table.
     */
    public function test_pagination_renders_on_multiple_pages(): void
    {
        $_GET['paged'] = '2';

        $output = $this->render(array('total' => 200, 'with_alt' => 50, 'missing' => array(1)));

        $this->assertStringContainsString('3 pages', $output);
        $this->assertStringContainsString('2 of 3', $output);
        $this->assertStringContainsString('href="https://example.com/wp-admin/upload.php?page=altrelay&paged=1"', $output);
        $this->assertStringContainsString('href="https://example.com/wp-admin/upload.php?page=altrelay&paged=3"', $output);
        $this->assertStringContainsString('aria-label="Image pages"', $output);
    }

    /**
     * Test that the first page has no "previous" and the last no "next".
     */
    public function test_pagination_ends(): void
    {
        $first = $this->render(array('total' => 200, 'with_alt' => 50, 'missing' => array(1)));

        $this->assertStringNotContainsString('prev-page', $first);
        $this->assertStringContainsString('next-page', $first);

        $_GET['paged'] = '3';
        $GLOBALS['altrelay_test_state']['transients'] = array();

        $last = $this->render(array('total' => 200, 'with_alt' => 50, 'missing' => array(1)));

        $this->assertStringContainsString('prev-page', $last);
        $this->assertStringNotContainsString('next-page', $last);
    }

    /**
     * Test that a page beyond the last one explains itself and offers a way back.
     */
    public function test_page_beyond_the_last_one(): void
    {
        global $wpdb;

        $_GET['paged'] = '9';

        $output = $this->render(array('total' => 200, 'with_alt' => 50));

        $this->assertStringContainsString('There are no images on this page.', $output);
        $this->assertStringContainsString('prev-page', $output);
        $this->assertCount(0, array_filter($wpdb->queries, static fn(string $q): bool => str_contains($q, 'ORDER BY ID DESC')), 'The content lists are on tabs of their own.');
    }

    /**
     * Test that the tables carry no redundant ARIA roles.
     */
    public function test_table_has_no_redundant_role(): void
    {
        $output = $this->render(array('total' => 2, 'with_alt' => 1, 'missing' => array(7)));

        $this->assertStringNotContainsString('role="table"', $output);
        $this->assertStringContainsString('<caption class="screen-reader-text">', $output);
        $this->assertStringContainsString('<th scope="col"', $output);
    }

    /**
     * Test the summary of recent sync activity.
     */
    public function test_render_dashboard_shows_sync_notice(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'post_count' => 3,
            'time' => time() - 300,
        );

        $output = $this->render();

        $this->assertStringContainsString('Last sync: 3 posts updated 5 minutes ago.', $output);
    }

    /**
     * Test that a broken transient shows nothing.
     */
    public function test_render_dashboard_ignores_malformed_sync_log(): void
    {
        global $altrelay_test_state;

        $this->assertStringNotContainsString('Last sync', $this->render());

        $altrelay_test_state['transients'] = array('altrelay_last_sync' => array('post_count' => 3));

        $this->assertStringNotContainsString('Last sync', $this->render());

        $altrelay_test_state['transients'] = array('altrelay_last_sync' => array('time' => time(), 'post_count' => array()));

        $this->assertStringNotContainsString('Last sync', $this->render(), 'A sync that updated nothing is not reported as one.');
    }

    /**
     * Test that waiting background work is visible.
     */
    public function test_queue_notice_shows_waiting_jobs(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['as_pending'] = array(array(1, 0, 0), array(2, 0, 0));

        $this->assertStringContainsString('2 alt text updates are waiting to run in the background.', $this->render());

        $altrelay_test_state['as_pending'] = array(array(1, 0, 0));
        $altrelay_test_state['transients'] = array();

        $this->assertStringContainsString('1 alt text update is waiting', $this->render());
    }

    /**
     * Test that failed background jobs are visible.
     *
     * Regression (audit, Admin dashboard): only waiting jobs were shown.
     */
    public function test_failed_jobs_are_shown(): void
    {
        global $altrelay_test_state;

        $this->assertStringNotContainsString('altrelay-failed-notice', $this->render());

        $altrelay_test_state['as_failed'] = array(array(1, 0, 0), array(2, 0, 0));
        $altrelay_test_state['transients'] = array();

        $output = $this->render();

        $this->assertStringContainsString('altrelay-failed-notice', $output);
        $this->assertStringContainsString('2 background updates failed.', $output);
        $this->assertStringContainsString('Scheduled Actions', $output);
    }

    /**
     * Test that posts that could not be saved are shown, with a way to
     * clear the message.
     */
    public function test_sync_errors_are_shown(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array(
            'count' => 3,
            'post_id' => 10,
            'message' => 'Could not update post in the database',
            'time' => time(),
        );
        $altrelay_test_state['post_fields'][10]['post_title'] = 'Summer <catalogue>';

        $output = $this->render();

        $this->assertStringContainsString('AltRelay could not update 3 posts.', $output);
        $this->assertStringContainsString('Most recent: &quot;Summer &lt;catalogue&gt;&quot; (Could not update post in the database).', $output);
        $this->assertStringContainsString('name="action" value="altrelay_clear_errors"', $output);
        $this->assertStringContainsString('value="nonce-altrelay_clear_errors"', $output);
    }

    /**
     * Test that the failed post is not named to someone who may not see it,
     * and that a failed query has no post to name.
     */
    public function test_sync_errors_do_not_leak_titles(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array(
            'count' => 1,
            'post_id' => 10,
            'message' => 'Oops',
        );
        $altrelay_test_state['post_fields'][10]['post_title'] = 'Confidential';
        $altrelay_test_state['user_caps_for']['edit_post'][10] = false;

        $output = $this->render();

        $this->assertStringContainsString('AltRelay could not update 1 post. Most recent error: Oops.', $output);
        $this->assertStringNotContainsString('Confidential', $output);

        $altrelay_test_state['transients'] = array('altrelay_sync_errors' => array('count' => 1, 'post_id' => 0, 'message' => 'Table crashed'));

        $this->assertStringContainsString('Most recent error: Table crashed.', $this->render());

        $altrelay_test_state['transients'] = array('altrelay_sync_errors' => array('count' => 'many'));

        $this->assertStringNotContainsString('altrelay-errors-notice', $this->render());
    }

    /**
     * Test clearing the error record.
     */
    public function test_clear_errors(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array('count' => 1);

        $url = $this->run_handler(array($this->admin, 'handle_clear_errors'));

        $this->assertArrayNotHasKey('altrelay_sync_errors', $altrelay_test_state['transients']);
        $this->assertStringContainsString('altrelay_result=errors_cleared', $url);
        $this->assertSame(array('altrelay_clear_errors'), $altrelay_test_state['checked_referers']);
    }

    /**
     * Test that clearing errors needs a capability.
     */
    public function test_clear_errors_requires_capability(): void
    {
        global $altrelay_test_state;

        // Regression (fifth audit): an author, who can upload files, could
        // hide failed updates before an administrator saw them.
        $altrelay_test_state['user_caps']['upload_files'] = true;
        $altrelay_test_state['user_caps']['manage_options'] = false;
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array('count' => 1);

        try {
            $this->admin->handle_clear_errors();
            $this->fail('Expected the capability check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('You do not have permission', $e->getMessage());
        }

        $this->assertArrayHasKey('altrelay_sync_errors', $altrelay_test_state['transients']);
    }

    /**
     * Test the "review first" switch while it is off.
     */
    public function test_review_first_panel_when_off(): void
    {
        $_GET['tab'] = 'content';
        $output = $this->render();

        $this->assertStringContainsString('Review first is off.', $output);
        $this->assertStringContainsString('name="action" value="altrelay_review_first"', $output);
        $this->assertStringContainsString('name="review_first" value="1"', $output);
        $this->assertStringContainsString('Turn review first on', $output);
        $this->assertStringContainsString('AltRelay treats that as &quot;not written yet&quot;', $output);
    }

    /**
     * Test the "review first" switch while it is on.
     */
    public function test_review_first_panel_when_on(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options']['altrelay_review_first'] = '1';

        $_GET['tab'] = 'content';
        $output = $this->render();

        $this->assertStringContainsString('Review first is on.', $output);
        $this->assertStringContainsString('name="review_first" value="0"', $output);
        $this->assertStringContainsString('Turn review first off', $output);
        $this->assertStringContainsString('While review first is on, AltRelay leaves them alone.', $output);
    }

    /**
     * Test that only administrators get the switch.
     */
    public function test_review_first_panel_for_non_administrators(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['manage_options'] = false;

        $output = $this->render();

        $this->assertStringContainsString('Only an administrator can change this setting.', $output);
        $this->assertStringNotContainsString('value="altrelay_review_first"', $output);
    }

    /**
     * Test turning the switch on and off.
     */
    public function test_review_first_handler(): void
    {
        global $altrelay_test_state;

        $_POST = array('review_first' => '1');
        $url = $this->run_handler(array($this->admin, 'handle_review_first'));

        $this->assertSame('1', $altrelay_test_state['options']['altrelay_review_first']);
        $this->assertTrue($altrelay_test_state['option_autoload']['altrelay_review_first'], 'The frontend reads it, so it must be autoloaded.');
        $this->assertStringContainsString('altrelay_result=review_first_on', $url);
        $this->assertTrue(AltRelay_Content::empty_alt_is_decorative());

        $_POST = array('review_first' => '0');
        $url = $this->run_handler(array($this->admin, 'handle_review_first'));

        $this->assertSame('0', $altrelay_test_state['options']['altrelay_review_first']);
        $this->assertStringContainsString('altrelay_result=review_first_off', $url);
        $this->assertFalse(AltRelay_Content::empty_alt_is_decorative());
    }

    /**
     * Test that the switch needs manage_options and a valid nonce.
     */
    public function test_review_first_handler_is_protected(): void
    {
        global $altrelay_test_state;
        $_POST = array('review_first' => '1');

        $altrelay_test_state['user_caps']['manage_options'] = false;

        try {
            $this->admin->handle_review_first();
            $this->fail('Expected the capability check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('You do not have permission', $e->getMessage());
        }

        $altrelay_test_state['user_caps']['manage_options'] = true;
        $altrelay_test_state['nonce_valid'] = false;

        try {
            $this->admin->handle_review_first();
            $this->fail('Expected the nonce check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die: nonce', $e->getMessage());
        }

        $this->assertArrayNotHasKey('altrelay_review_first', $altrelay_test_state['options']);
    }

    /**
     * Test that the admin CSS is only loaded on the dashboard.
     */
    public function test_enqueue_assets_skips_other_pages(): void
    {
        global $altrelay_test_state;

        $this->admin->enqueue_admin_assets('edit.php');

        $this->assertEmpty($altrelay_test_state['enqueued_styles']);
    }

    /**
     * Test that the admin CSS is loaded on the dashboard.
     */
    public function test_enqueue_assets_fires_on_plugin_page(): void
    {
        global $altrelay_test_state;

        $this->admin->enqueue_admin_assets('media_page_altrelay');

        $this->assertSame(array('altrelay-admin'), $altrelay_test_state['enqueued_styles']);
    }

    /**
     * Test that the Plugins screen gets a Dashboard link first.
     */
    public function test_plugin_action_links_adds_dashboard_link(): void
    {
        $links = $this->admin->plugin_action_links(array('deactivate' => '<a href="#">Deactivate</a>'));

        $this->assertSame('<a href="https://example.com/wp-admin/upload.php?page=altrelay">Dashboard</a>', $links[0]);
        $this->assertCount(2, $links);
    }

    /**
     * Test that the welcome notice shows on the Plugins screen.
     */
    public function test_welcome_notice_renders_on_plugins_screen(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';
        $altrelay_test_state['screen_id'] = 'plugins';

        ob_start();
        $this->admin->render_welcome_notice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Review images', $output);
        $this->assertStringContainsString('review those first', $output);
        $this->assertStringContainsString('action=altrelay_dismiss_welcome', $output);
        $this->assertStringContainsString('_wpnonce=nonce-altrelay_dismiss_welcome', $output);
    }

    /**
     * Test that the welcome notice stays off unrelated screens.
     */
    public function test_welcome_notice_hidden_on_other_screens(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';
        $altrelay_test_state['screen_id'] = 'edit-post';

        ob_start();
        $this->admin->render_welcome_notice();

        $this->assertSame('', ob_get_clean());
    }

    /**
     * Test that the welcome notice needs the upload_files capability.
     */
    public function test_welcome_notice_hidden_without_capability(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';
        $altrelay_test_state['user_caps']['upload_files'] = false;

        ob_start();
        $this->admin->render_welcome_notice();

        $this->assertSame('', ob_get_clean());
    }

    /**
     * Test that dismissing deletes the flag and returns to the referring page.
     */
    public function test_dismiss_welcome(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';
        $altrelay_test_state['referer'] = 'https://example.com/wp-admin/upload.php';

        $url = $this->run_handler(array($this->admin, 'handle_dismiss_welcome'));

        $this->assertArrayNotHasKey(AltRelay_Admin::WELCOME_OPTION, $altrelay_test_state['options']);
        $this->assertSame('https://example.com/wp-admin/upload.php', $url);
    }

    /**
     * Test that dismissing without a referer goes to the Plugins screen.
     */
    public function test_dismiss_welcome_without_referer(): void
    {
        $url = $this->run_handler(array($this->admin, 'handle_dismiss_welcome'));

        $this->assertSame('https://example.com/wp-admin/plugins.php', $url);
    }

    /**
     * Test that a forged dismiss request changes nothing.
     */
    public function test_dismiss_welcome_rejects_bad_nonce(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';
        $altrelay_test_state['nonce_valid'] = false;

        try {
            $this->admin->handle_dismiss_welcome();
            $this->fail('Expected the nonce check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die: nonce', $e->getMessage());
        }

        $this->assertArrayHasKey(AltRelay_Admin::WELCOME_OPTION, $altrelay_test_state['options']);
    }

    /**
     * Test that visiting the dashboard clears the welcome notice.
     */
    public function test_dashboard_visit_clears_welcome(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['options'][AltRelay_Admin::WELCOME_OPTION] = '1';

        // Regression (fifth audit): the page wrote to the database while it
        // rendered. Its writes happen on its load hook now.
        $this->render();
        $this->assertArrayHasKey(AltRelay_Admin::WELCOME_OPTION, $altrelay_test_state['options'], 'Rendering only reads.');

        $this->admin->load_dashboard();

        $this->assertArrayNotHasKey(AltRelay_Admin::WELCOME_OPTION, $altrelay_test_state['options']);
    }

    /**
     * Test that the dashboard's load hook is registered with the page.
     */
    public function test_the_load_hook_comes_with_the_page(): void
    {
        global $altrelay_test_state;

        $this->admin->add_menu_page();

        $this->assertNotEmpty($altrelay_test_state['hooks']['load-media_page_altrelay'] ?? array());
    }

    /**
     * Test that inline alt text is saved only where it should be.
     */
    public function test_save_alts(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_types_by_id'] = array(5 => 'attachment', 7 => 'post', 8 => 'attachment');
        $altrelay_test_state['user_caps_for']['edit_post'][8] = false;

        $_POST = array(
            'paged' => '2',
            'alt' => array(
                '5' => ' A <b>dog</b> on a beach ',
                '6' => '',
                '7' => 'Not an attachment',
                '8' => 'Not allowed',
                '9' => array('not a string'),
            ),
        );

        $url = $this->run_handler(array($this->admin, 'handle_save_alts'));

        $this->assertSame(array(array(5, '_wp_attachment_image_alt', 'A dog on a beach')), $altrelay_test_state['meta_updates']);
        $this->assertStringContainsString('altrelay_result=saved', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertStringContainsString('paged=2', $url);
    }

    /**
     * Test that the length limit is enforced on the server, in characters.
     *
     * The maxlength attribute is only a hint to the browser.
     */
    public function test_save_alts_enforces_the_length_limit(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_types_by_id'] = array(5 => 'attachment');

        $_POST = array('alt' => array('5' => str_repeat('ä', 499) . ' and far too much more'));

        $this->run_handler(array($this->admin, 'handle_save_alts'));

        $saved = $altrelay_test_state['meta_updates'][0][2];

        $this->assertSame(499, mb_strlen($saved), 'Cut at 500 characters, then trimmed of the trailing space.');
        $this->assertSame(str_repeat('ä', 499), $saved);
        $this->assertSame('Short', AltRelay_Admin::limit_alt('Short'));
        $this->assertSame(500, mb_strlen(AltRelay_Admin::limit_alt(str_repeat('x', 900))));
    }

    /**
     * Test that saving with nothing filled in reports nothing changed.
     */
    public function test_save_alts_with_nothing_filled(): void
    {
        $_POST = array('alt' => array('5' => ''));

        $url = $this->run_handler(array($this->admin, 'handle_save_alts'));

        $this->assertStringContainsString('altrelay_result=none', $url);
        $this->assertStringContainsString('paged=1', $url);
    }

    /**
     * Test that a forged save request writes nothing.
     */
    public function test_save_alts_rejects_bad_nonce(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['nonce_valid'] = false;
        $_POST = array('alt' => array('5' => 'Dog'));

        try {
            $this->admin->handle_save_alts();
            $this->fail('Expected the nonce check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die: nonce', $e->getMessage());
        }

        $this->assertEmpty($altrelay_test_state['meta_updates'] ?? array());
    }

    /**
     * Test that saving needs the upload_files capability.
     */
    public function test_save_alts_requires_capability(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['upload_files'] = false;
        $_POST = array('alt' => array('5' => 'Dog'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: You do not have permission to do this.');

        $this->admin->handle_save_alts();
    }

    /**
     * Test the per-row Fill button.
     */
    public function test_review_single_fill(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $_POST = array('altrelay_single' => 'fill:10:42', 'review_before' => '77');

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertStringContainsString('alt="Dog"', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertStringContainsString('alt=""', $altrelay_test_state['post_fields'][11]['post_content']);
        $this->assertStringContainsString('altrelay_result=filled', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertStringContainsString('review_before=77', $url);
        $this->assertStringEndsWith('#altrelay-review', $url);
    }

    /**
     * Test the bulk Mark decorative action, including per-post permissions and
     * junk input.
     */
    public function test_review_bulk_mark_decorative(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $altrelay_test_state['user_caps_for']['edit_post'][11] = false;

        $_POST = array(
            'altrelay_bulk_action' => 'decorative',
            'items' => array('10:42', '11:42', 'junk', '0:42', array('nested')),
        );

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertStringNotContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][11]['post_content']);
        $this->assertStringContainsString('altrelay_result=decorative', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertStringNotContainsString('review_before', $url, 'The first page needs no parameter.');
    }

    /**
     * Regression (audit bug 7): marking a linked image is refused, and the
     * redirect says how many were.
     */
    public function test_review_mark_decorative_reports_refused_links(): void
    {
        global $altrelay_test_state;

        $linked = '<a href="https://example.com/"><img src="a.jpg" alt="" class="wp-image-42" /></a>';

        $altrelay_test_state['post_fields'][10] = array('post_content' => $linked);
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />' . $linked);

        $_POST = array('altrelay_bulk_action' => 'decorative', 'items' => array('10:42', '11:42'));

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertSame($linked, $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertStringContainsString('altrelay_result=decorative', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertStringContainsString('altrelay_refused=2', $url);
    }

    /**
     * Regression (seventh audit): posts the user could not edit were left
     * out silently, and the warning that says so was never shown.
     */
    public function test_review_reports_posts_the_user_cannot_edit(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        $altrelay_test_state['user_caps_for']['edit_post'][10] = false;

        $_POST = array('altrelay_single' => 'fill:10:42');

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertStringContainsString('altrelay_result=none', $url);
        $this->assertStringContainsString('altrelay_skipped=1', $url);

        $_GET = array('altrelay_result' => 'none', 'altrelay_skipped' => '2');
        $output = $this->render();

        $this->assertStringContainsString('2 posts were left alone, because you cannot edit them.', $output);
        $this->assertStringNotContainsString('Nothing was changed.', $output, 'One warning says it all.');
    }

    /**
     * Test removing the decorative mark from the dashboard.
     */
    public function test_review_unmark(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
        );

        $_POST = array('altrelay_single' => 'unmark:10:42', 'decorative_before' => '55', 'review_before' => '9');

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertSame('<img src="a.jpg" alt="Dog" class="wp-image-42" />', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertStringContainsString('altrelay_result=unmarked', $url);
        $this->assertStringContainsString('decorative_before=55', $url);
        $this->assertStringNotContainsString('review_before', $url);
        $this->assertStringEndsWith('#altrelay-decorative', $url);
    }

    /**
     * Test that the handlers use the sync engine the dashboard was given.
     */
    public function test_handlers_use_the_injected_sync_engine(): void
    {
        $sync = new class () extends AltRelay_Sync {
            /** @var list<array{0: int, 1: int}> */
            public array $filled = array();

            public function fill_post(int $post_id, int $attachment_id, bool $dry_run = false): bool
            {
                $this->filled[] = array($post_id, $attachment_id);
                return true;
            }
        };

        $_POST = array('altrelay_single' => 'fill:10:42');

        $this->run_handler(array(new AltRelay_Admin($sync), 'handle_review'));

        $this->assertSame(array(array(10, 42)), $sync->filled);
    }

    /**
     * Test that unknown operations and malformed items change nothing.
     */
    public function test_review_rejects_unknown_operation(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();

        foreach (array('delete:10:42', 'fill:10', 'fill:x:42', 'fill:0:42') as $single) {
            $_POST = array('altrelay_single' => $single);

            $url = $this->run_handler(array($this->admin, 'handle_review'));

            $this->assertStringContainsString('altrelay_result=none', $url, $single);
        }

        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that applying without choosing a bulk action changes nothing.
     */
    public function test_review_bulk_without_action(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $_POST = array('altrelay_bulk_action' => '', 'items' => array('10:42'));

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertStringContainsString('altrelay_result=none', $url);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that a forged review request writes nothing.
     */
    public function test_review_rejects_bad_nonce(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $altrelay_test_state['nonce_valid'] = false;
        $_POST = array('altrelay_single' => 'fill:10:42');

        try {
            $this->admin->handle_review();
            $this->fail('Expected the nonce check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die: nonce', $e->getMessage());
        }

        $this->assertStringContainsString('alt=""', $altrelay_test_state['post_fields'][10]['post_content']);
    }

    /**
     * Test that the review needs the upload_files capability.
     */
    public function test_review_requires_capability(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $altrelay_test_state['user_caps']['upload_files'] = false;
        $_POST = array('altrelay_single' => 'fill:10:42');

        try {
            $this->admin->handle_review();
            $this->fail('Expected the capability check to stop the request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('You do not have permission', $e->getMessage());
        }

        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that the review lists empty-alt images with labelled controls.
     */
    public function test_review_section_lists_rows(): void
    {
        $this->review_fixture();

        $output = $this->render(array('review' => array(10)));

        $this->assertStringContainsString('name="altrelay_single" value="fill:10:42"', $output);
        $this->assertStringContainsString('name="altrelay_single" value="decorative:10:42"', $output);
        $this->assertStringContainsString('<label for="altrelay-item-10-42" class="screen-reader-text">', $output);
        $this->assertStringContainsString('Select &quot;Dog&quot; in &quot;Post 10&quot;', $output);
        $this->assertStringContainsString('value="nonce-altrelay_review"', $output);
        $this->assertStringContainsString('<option value="fill">Fill from library</option>', $output);
        $this->assertStringNotContainsString('altrelay-list-nav', $output, 'One short page needs no navigation.');
    }

    /**
     * Test that a post can be opened to see the image in context.
     *
     * Regression (audit, Admin dashboard): a 60 pixel thumbnail is not
     * enough to judge whether an image is decorative.
     */
    public function test_review_rows_link_to_the_post(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $altrelay_test_state['post_fields'][10]['post_title'] = '';
        $altrelay_test_state['post_statuses'][11] = 'draft';
        $altrelay_test_state['post_fields'][12] = $altrelay_test_state['post_fields'][11];
        $altrelay_test_state['post_types_by_id'][12] = 'wp_block';

        $output = $this->render(array('review' => array(12, 11, 10)));

        $this->assertStringContainsString('<a href="https://example.com/wp-admin/post.php?post=10&action=edit"><strong>(no title)</strong></a>', $output);
        $this->assertStringContainsString('href="https://example.com/?p=10"><span aria-hidden="true">View in context</span><span class="screen-reader-text">View in context: (no title)</span></a>', $output);
        $this->assertStringContainsString('href="https://example.com/?p=11&preview=true"', $output, 'An unpublished post opens as a preview.');
        $this->assertStringNotContainsString('?p=12', $output, 'A synced pattern has no page of its own.');
    }

    /**
     * Regression (audit bug 5): a post the user cannot edit is not listed,
     * so its title is never shown to them.
     */
    public function test_review_hides_posts_the_user_cannot_edit(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();
        $altrelay_test_state['post_fields'][11]['post_title'] = 'Private: merger plans';
        $altrelay_test_state['user_caps_for']['edit_post'][11] = false;

        $output = $this->render(array('review' => array(11, 10)));

        $this->assertStringContainsString('value="fill:10:42"', $output);
        $this->assertStringNotContainsString('merger plans', $output);
        $this->assertStringNotContainsString('value="fill:11:42"', $output);
    }

    /**
     * Regression (audit bug 7): a linked image can be filled but not marked,
     * and the row says why.
     */
    public function test_review_row_for_a_linked_image(): void
    {
        global $altrelay_test_state;

        $linked = '<a href="https://example.com/"><img src="a.jpg" alt="" class="wp-image-42" /></a>';

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_fields'][10] = array('post_content' => $linked);
        $altrelay_test_state['post_fields'][11] = array('post_content' => $linked . '<img src="a.jpg" alt="" class="wp-image-42" />');

        $output = $this->render(array('review' => array(11, 10)));

        $this->assertSame(2, substr_count($output, 'altrelay-linked-note'));
        $this->assertStringContainsString('value="fill:10:42"', $output);
        $this->assertStringNotContainsString('value="decorative:10:42"', $output, 'Every copy is linked: nothing to mark.');
        $this->assertStringContainsString('value="decorative:11:42"', $output, 'One copy is not linked and can be marked.');
    }

    /**
     * Test that decorative images and images without library alt are not listed.
     */
    public function test_review_skips_decorative_and_undescribed_images(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<figure class="altrelay-decorative"><img src="a.jpg" alt="" class="wp-image-42" /></figure>'
                . '<img src="b.jpg" alt="" class="wp-image-43" />'
                . '<img src="c.jpg" alt="Described" class="wp-image-42" />',
        );
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';

        $output = $this->render(array('review' => array(10)));

        $this->assertStringContainsString('No empty alt text to review.', $output);
        $this->assertStringNotContainsString('altrelay-review-table', $output);
    }

    /**
     * Regression (audit bug 6): when more posts follow, the list offers the
     * next page, starting after the last post it read.
     */
    public function test_review_offers_the_next_page(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';

        $ids = range(300, 201);

        foreach ($ids as $id) {
            $altrelay_test_state['post_fields'][$id] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        }

        $output = $this->render(array('review' => $ids));

        $this->assertSame(20, substr_count($output, 'name="altrelay_single" value="fill:'));
        $this->assertStringContainsString('value="fill:300:42"', $output);
        $this->assertStringContainsString('value="fill:281:42"', $output);
        $this->assertStringNotContainsString('value="fill:280:42"', $output);
        $this->assertStringContainsString('href="https://example.com/wp-admin/upload.php?page=altrelay&tab=content&review_before=281#altrelay-review">Next page</a>', $output);
        $this->assertStringNotContainsString('Back to the start', $output);
    }

    /**
     * Test a later page of the list.
     */
    public function test_review_later_page_links_back_to_the_start(): void
    {
        global $wpdb;
        $this->review_fixture();
        $_GET['review_before'] = '281';

        $output = $this->render(array('review' => array(10)));

        $this->assertStringContainsString('name="review_before" value="281"', $output);
        $this->assertStringContainsString('href="https://example.com/wp-admin/upload.php?page=altrelay&tab=content#altrelay-review">Back to the start</a>', $output);
        $this->assertStringNotContainsString('>Next page</a>', $output);

        $review_query = array_values(array_filter($wpdb->queries, static fn(string $q): bool => str_contains($q, 'ID < 281')));

        $this->assertCount(2, $review_query, "The window and the candidates within it.");
    }

    /**
     * Regression (audit bug 6): a page without rows does not claim that
     * there is nothing to review while later posts have not been read.
     */
    public function test_review_page_without_rows_says_there_is_more(): void
    {
        // Five full chunks of candidates, none of which holds a row.
        $chunks = array();
        for ($chunk = 0; $chunk < 5; $chunk++) {
            $chunks[] = range(1000 - $chunk * 100, 901 - $chunk * 100);
        }

        $output = $this->render(array('review_chunks' => $chunks));

        $this->assertStringContainsString('Checked 500 more posts and found nothing to show. There are more posts to check.', $output);
        $this->assertStringNotContainsString('No empty alt text to review.', $output);
        $this->assertStringContainsString('review_before=501#altrelay-review">Next page</a>', $output);
    }

    /**
     * Test the end of the list.
     */
    public function test_review_end_of_list(): void
    {
        $_GET['review_before'] = '5';

        $output = $this->render();

        $this->assertStringContainsString('You have reached the end of the list.', $output);
        $this->assertStringContainsString('Back to the start', $output);
    }

    /**
     * Test the list of decorative images and its unmark control.
     *
     * Regression (audit, Admin dashboard): there was no way to unmark.
     */
    public function test_decorative_section_lists_rows(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />'
                . '<img src="b.jpg" alt="" class="wp-image-43 altrelay-decorative" />'
                . '<img src="c.jpg" alt="" role="presentation" class="wp-image-44" />',
            'post_title' => 'Landing page',
        );

        $output = $this->render(array('decorative' => array(10)));

        $this->assertStringContainsString('Images marked decorative', $output);
        $this->assertStringContainsString('name="altrelay_single" value="unmark:10:42" aria-label="Remove decorative mark in &quot;Landing page&quot;"', $output);
        $this->assertStringContainsString('value="unmark:10:43"', $output);
        $this->assertStringContainsString('No alt text in the Media Library yet.', $output);
        $this->assertStringNotContainsString('unmark:10:44', $output, 'A role typed by hand is not AltRelay\'s to remove.');
    }

    /**
     * Test paging through the decorative list.
     */
    public function test_decorative_section_navigation(): void
    {
        $_GET['decorative_before'] = '40';

        $output = $this->render();

        $this->assertStringContainsString('You have reached the end of the list.', $output);
        $this->assertStringContainsString('upload.php?page=altrelay&tab=decorative#altrelay-decorative">Back to the start</a>', $output);
    }

    /**
     * Test the result notices.
     */
    public function test_result_notice_renders(): void
    {
        global $altrelay_test_state;

        $expected = array(
            'saved' => 'Saved alt text for 3 images. Posts using them are being updated in the background.',
            'filled' => 'Filled alt text in 3 posts.',
            'decorative' => 'Marked images as decorative in 3 posts.',
            'unmarked' => 'Removed the decorative mark in 3 posts.',
            'review_first_on' => 'Review first is on.',
            'review_first_off' => 'Review first is off.',
            'errors_cleared' => 'The list of failed updates was cleared.',
            'queue_running' => 'Nothing was left to start: WP-Cron is already running the waiting updates.',
            'none' => 'Nothing was changed.',
        );

        foreach ($expected as $result => $message) {
            $_GET = array('altrelay_result' => $result, 'altrelay_count' => '3');
            $altrelay_test_state['transients'] = array();

            $this->assertStringContainsString($message, $this->render(), $result);
        }

        $_GET = array('altrelay_result' => 'saved', 'altrelay_count' => '1');
        $altrelay_test_state['transients'] = array();

        $this->assertStringContainsString('Saved alt text for 1 image.', $this->render());
    }

    /**
     * Test the notice about linked images that were refused.
     */
    public function test_result_notice_for_refused_links(): void
    {
        global $altrelay_test_state;

        $_GET = array('altrelay_result' => 'decorative', 'altrelay_count' => '1', 'altrelay_refused' => '2');
        $output = $this->render();

        $this->assertStringContainsString('2 images were not marked because each is the only content of a link.', $output);
        $this->assertStringContainsString('Marked images as decorative in 1 post.', $output);

        // Nothing changed, but the reason is the refusal, not a missing selection.
        $_GET = array('altrelay_result' => 'none', 'altrelay_count' => '0', 'altrelay_refused' => '1');
        $altrelay_test_state['transients'] = array();
        $output = $this->render();

        $this->assertStringContainsString('1 image was not marked because it is the only content of a link.', $output);
        $this->assertStringNotContainsString('Nothing was changed.', $output);
    }

    /**
     * Test that unknown result keys render nothing.
     */
    public function test_result_notice_ignores_unknown_keys(): void
    {
        $_GET = array('altrelay_result' => '<script>alert(1)</script>', 'altrelay_count' => 'x');

        $output = $this->render();

        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringNotContainsString('notice-success is-dismissible', $output);
    }


    /**
     * Regression (second audit, bug 7): the result notice came back on
     * every reload, because its arguments stayed in the address bar.
     */
    public function test_result_arguments_are_removed_after_the_page_loads(): void
    {
        global $altrelay_test_state;

        $this->admin->register();

        $this->assertArrayHasKey('removable_query_args', $altrelay_test_state['hooks']);
        $this->assertSame(
            array('message', 'altrelay_result', 'altrelay_count', 'altrelay_refused', 'altrelay_skipped'),
            $this->admin->removable_query_args(array('message'))
        );
        $this->assertSame('not a list', $this->admin->removable_query_args('not a list'));
    }

    /**
     * Regression (second audit, bug 7): update_post_meta() unslashes its
     * value, so a backslash typed into an alt field was lost.
     */
    public function test_save_alts_keeps_backslashes(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_types_by_id'] = array(5 => 'attachment');

        // WordPress slashes request data.
        $_POST = array('alt' => array('5' => wp_slash('A path: C:\Photos\dog.jpg')));

        $this->run_handler(array($this->admin, 'handle_save_alts'));

        $this->assertSame('A path: C:\Photos\dog.jpg', $altrelay_test_state['post_meta'][5]['_wp_attachment_image_alt']);
    }

    /**
     * Regression (second audit, bug 7): the raw database error was shown
     * to everyone who can upload files.
     */
    public function test_sync_error_details_are_for_administrators_only(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['manage_options'] = false;
        $altrelay_test_state['transients']['altrelay_sync_errors'] = array(
            'count' => 1,
            'post_id' => 10,
            'message' => 'Table wp_posts is marked as crashed',
        );
        $altrelay_test_state['post_fields'][10]['post_title'] = 'Summer';

        $output = $this->render();

        $this->assertStringContainsString('Most recent: &quot;Summer&quot;. An administrator can see the error message on this page.', $output);
        $this->assertStringNotContainsString('crashed', $output);

        $altrelay_test_state['transients']['altrelay_sync_errors']['post_id'] = 0;
        $output = $this->render();

        $this->assertStringContainsString('AltRelay could not update 1 post. An administrator can see the error message on this page.', $output);
        $this->assertStringNotContainsString('crashed', $output);
    }

    /**
     * Regression (second audit, bug 5): posts the acting user could not
     * edit were skipped without a trace.
     */
    public function test_skipped_posts_are_reported_with_a_way_to_update_them(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['edit_others_posts'] = true;
        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'time' => time() - 60,
            'post_count' => 3,
            'skipped_posts' => array(10, 11),
            'skipped_attachments' => array(42),
        );

        $output = $this->render();

        $this->assertStringContainsString('Last sync: 3 posts updated', $output);
        $this->assertStringContainsString('2 posts were not updated 5 minutes ago, because the person who changed the alt text cannot edit them.', $output);
        $this->assertStringContainsString('name="action" value="altrelay_resync"', $output);
        $this->assertStringContainsString('value="nonce-altrelay_resync"', $output);

        // Someone who cannot edit other people's posts is told who can.
        $altrelay_test_state['user_caps']['edit_others_posts'] = false;
        $output = $this->render();

        $this->assertStringContainsString('An editor or administrator can update them from this page.', $output);
        $this->assertStringNotContainsString('altrelay_resync', $output);
    }

    /**
     * Test that "Update them now" syncs the images again as the person who
     * asked, and forgets the skipped posts.
     */
    public function test_resync_queues_the_skipped_images(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['edit_others_posts'] = true;
        $altrelay_test_state['current_user_id'] = 7;
        $altrelay_test_state['post_types_by_id'] = array(42 => 'attachment', 43 => 'post');
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'time' => time(),
            'post_count' => 0,
            'skipped_count' => 4,
            'skipped_attachments' => array(42, 43),
        );

        $url = $this->run_handler(array($this->admin, 'handle_resync'));

        $this->assertStringContainsString('altrelay_result=resync', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertSame(1, AltRelay_Queue::pending_count());
        $this->assertSame(0, AltRelay_Sync::last_sync()['skipped_count']);
        $this->assertSame(array(), AltRelay_Sync::last_sync()['skipped_attachments']);
    }

    /**
     * Test that only someone who can edit other people's posts may resync.
     */
    public function test_resync_requires_capability(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['edit_others_posts'] = false;

        $this->expectException(\RuntimeException::class);
        $this->admin->handle_resync();
    }

    /**
     * Test the post-type filter and the row count of the review lists.
     */
    public function test_review_lists_can_be_narrowed_to_one_post_type(): void
    {
        global $wpdb;
        $this->review_fixture();

        $output = $this->render(array('review' => array(10)));

        $this->assertStringContainsString('<select name="altrelay_type" id="altrelay-type">', $output);
        $this->assertStringContainsString('<option value="page" >Pages</option>', $output);
        $this->assertStringContainsString('1 image on this page.', $output);

        $_GET['altrelay_type'] = 'page';
        $output = $this->render(array('review' => array(10)));

        $this->assertStringContainsString("<option value=\"page\"  selected='selected'>Pages</option>", $output);
        $this->assertStringContainsString('name="altrelay_type" value="page"', $output);
        $review_queries = array_filter($wpdb->queries, static fn(string $q): bool => str_contains($q, 'ORDER BY ID DESC'));
        foreach ($review_queries as $query) {
            $this->assertStringContainsString("post_type IN ('page')", $query);
        }

        // A type AltRelay does not sync is ignored.
        $_GET['altrelay_type'] = 'secret';
        $this->render(array('review' => array(10)));
        $review_queries = array_values(array_filter($wpdb->queries, static fn(string $q): bool => str_contains($q, 'ORDER BY ID DESC')));
        $this->assertStringContainsString("post_type IN ('post','page')", $review_queries[0]);
    }

    /**
     * Test that the review form passes the post type on to the redirect.
     */
    public function test_review_handler_keeps_the_post_type(): void
    {
        global $altrelay_test_state;
        $this->review_fixture();

        $_POST = array('altrelay_single' => 'fill:10:42', 'review_before' => '0', 'altrelay_type' => 'page');

        $url = $this->run_handler(array($this->admin, 'handle_review'));

        $this->assertStringContainsString('altrelay_type=page', $url);
    }


    /**
     * Test that waiting WP-Cron jobs can be run from the dashboard.
     *
     * Regression (third audit, bug 3): a stalled queue only said "1 update
     * is waiting", with no way to start it.
     */
    public function test_waiting_jobs_can_be_run_from_the_dashboard(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['user_caps']['edit_others_posts'] = true;
        $altrelay_test_state['post_types_by_id'] = array(42 => 'attachment');
        update_option('altrelay_queue', array('waiting' => array('42:0' => array(42, 0, 0)), 'running' => array()));

        $output = $this->render();

        $this->assertStringContainsString('1 alt text update is waiting', $output);
        $this->assertStringContainsString('name="action" value="altrelay_run_queue"', $output);
        $this->assertEmpty($altrelay_test_state['scheduled_events'] ?? array(), 'Rendering only reads.');

        $this->admin->load_dashboard();
        $this->assertNotEmpty($altrelay_test_state['scheduled_events'], 'Opening the dashboard restarts a lost worker run.');

        $url = $this->run_handler(array($this->admin, 'handle_run_queue'));

        $this->assertStringContainsString('altrelay_result=ran_queue', $url);
        $this->assertStringContainsString('altrelay_count=1', $url);
        $this->assertSame(0, AltRelay_Queue::pending_count());
    }

    /**
     * Regression (fourth audit): when WP-Cron took the waiting jobs between
     * page load and click, "Run them now" said to select an item.
     */
    public function test_run_queue_with_nothing_left_says_so(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['user_caps']['edit_others_posts'] = true;

        $url = $this->run_handler(array($this->admin, 'handle_run_queue'));

        $this->assertStringContainsString('altrelay_result=queue_running', $url);
    }

    /**
     * Regression (fourth audit, Admin dashboard): an image whose update
     * stopped could only be finished by changing its alt text again.
     */
    public function test_images_whose_update_stopped_can_be_run_again(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['user_caps']['edit_others_posts'] = true;
        $altrelay_test_state['current_user_id'] = 7;
        $altrelay_test_state['post_types_by_id'] = array(42 => 'attachment', 43 => 'attachment');
        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'time' => time(),
            'post_count' => 0,
            'skipped_posts' => array(5),
            'skipped_attachments' => array(43),
        );
        AltRelay_Sync::record_error(0, 'The background update of image 42 stopped: Out of memory', 42);

        $output = $this->render();

        $this->assertStringContainsString('Once the cause is fixed, the images can be run again', $output);
        $this->assertStringContainsString('Try those images again', $output);

        $url = $this->run_handler(array($this->admin, 'handle_resync'));

        $this->assertStringContainsString('altrelay_result=resync', $url);
        $this->assertStringContainsString('altrelay_count=2', $url, 'The stopped image and the skipped one.');
        $this->assertSame(array('43:7', '42:7'), $this->flushed_keys());
        $this->assertSame(array(), AltRelay_Sync::images_to_retry());

        // Regression (fifth audit): the message stayed after the images were
        // run again, until it was cleared by hand. Failures that come back
        // are recorded anew.
        $this->assertFalse(get_transient('altrelay_sync_errors'));
    }

    /**
     * The keys of the waiting WP-Cron jobs, once written.
     *
     * @return list<string>
     */
    private function flushed_keys(): array
    {
        AltRelay_Queue::flush();

        return array_keys(get_option('altrelay_queue')['waiting'] ?? array());
    }

    /**
     * Test that someone who cannot edit other people's posts is told who
     * can run the images again.
     */
    public function test_images_to_run_again_need_an_editor(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['edit_others_posts'] = false;
        AltRelay_Sync::record_error(10, 'Broke', 42);

        $output = $this->render();

        $this->assertStringNotContainsString('Try those images again', $output);
        $this->assertStringContainsString('An editor or administrator can run them again from this page.', $output);
    }

    /**
     * An image without alt text that was left empty on purpose can be
     * marked decorative from the list, by a button that is not part of the
     * alt text form, so Enter in an alt text field still saves alt text.
     */
    public function test_images_without_alt_text_can_be_marked_decorative(): void
    {
        $output = $this->render(
            array(
                'total' => 10,
                'with_alt' => 7,
                'recorded_count' => 1,
                'missing' => array(1, 2),
                'usage' => array('2', '0'),
                'recorded' => array(3),
            )
        );

        // Regression (fifth audit): "1 more are marked decorative".
        $this->assertStringContainsString('7 of 10 images have alt text. 1 more image is marked decorative wherever it is used.', $output);
        $this->assertStringContainsString('an empty field can mean either &quot;not described yet&quot; or &quot;decorative&quot;', $output);
        $this->assertStringContainsString('form="altrelay-mark-image" name="attachment" value="1"', $output);
        $this->assertStringContainsString('<form id="altrelay-mark-image"', $output);
        $this->assertStringContainsString('value="nonce-altrelay_mark_image"', $output);

        // Regression (fifth audit, WCAG 2.5.3): the accessible name did not
        // contain the visible label. It starts with it now.
        // Regression (sixth audit, i18n): the name was put together from the
        // label and a fragment, which translators could not reorder. It is a
        // whole string of its own now.
        $this->assertMatchesRegularExpression('#<span aria-hidden="true">Mark decorative</span>\s*<span class="screen-reader-text">Mark decorative: test-image-1.jpg, wherever it is used</span>#', $output);
        $this->assertStringNotContainsString('aria-label="Mark', $output);
        $this->assertMatchesRegularExpression('#<span aria-hidden="true">List as missing again</span>\s*<span class="screen-reader-text">List as missing again: test-image-3.jpg</span>#', $output);

        // Regression (fifth audit): one click rewrote every post that uses
        // the image without asking.
        $this->assertStringContainsString('data-altrelay-confirm="Mark test-image-1.jpg decorative in all 2 posts that use it? Every copy gets empty alt text, and screen readers skip them."', $output);
        $this->assertStringContainsString('data-altrelay-confirm="Mark test-image-2.jpg decorative in the 1 post that uses it?', $output, 'An image counted in no post is asked about as one.');

        // The save button comes first in the alt text form: it is the one Enter uses.
        $form = substr($output, strpos($output, 'value="altrelay_save_alts"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $before = (string) strstr($form, 'Save alt text', true);
        // The two row buttons, then the save button itself.
        $this->assertSame(3, substr_count($before, '<button type="submit"'));
        $this->assertSame(2, substr_count($before, '<button type="submit" form="altrelay-mark-image"'), 'Every button before it belongs to the other form.');

        // The images recorded as decorative, with a way back.
        $this->assertStringContainsString('1 image has no alt text on purpose', $output);
        $this->assertStringContainsString('form="altrelay-list-image-again" name="attachment" value="3"', $output);
        $this->assertStringContainsString('value="nonce-altrelay_list_image_again"', $output);
    }

    /**
     * Test that the decorative images stay reachable when every other image
     * is described.
     */
    public function test_recorded_images_are_listed_when_nothing_else_is_missing(): void
    {
        $output = $this->render(
            array(
                'total' => 5,
                'with_alt' => 3,
                'recorded_count' => 2,
                'recorded' => array(3, 4),
            )
        );

        $this->assertStringContainsString('Every image has alt text or is marked decorative', $output);
        $this->assertStringContainsString('2 images have no alt text on purpose', $output);
        $this->assertStringNotContainsString('most recent are shown', $output);
    }

    /**
     * Test the outcomes of "Mark decorative" on an image.
     */
    public function test_mark_image_handler_reports_the_outcome(): void
    {
        global $altrelay_test_state, $wpdb;
        $altrelay_test_state['post_types_by_id'] = array(42 => 'attachment');
        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $altrelay_test_state['current_user_id'] = 7;

        // Used in two posts. Regression (fifth audit): every post was marked
        // in the one request, which an image used in thousands of posts
        // could not finish. The work is queued now.
        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');
        $_POST = array('attachment' => '42', 'paged' => '2');

        $url = $this->run_handler(array($this->admin, 'handle_mark_image'));

        $this->assertStringContainsString('altrelay_result=image_started', $url);
        $this->assertStringContainsString('paged=2', $url);
        $this->assertStringNotContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content'], 'Nothing is marked in the request itself.');
        $this->assertSame(array('42:7'), $this->flushed_keys());
        $this->assertSame(7, AltRelay_Sync::marking_state(42)['user'] ?? null);
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));

        // Used nowhere.
        $wpdb->stub_col_results = array();
        $url = $this->run_handler(array($this->admin, 'handle_mark_image'));
        $this->assertStringContainsString('altrelay_result=image_unused', $url);

        // No such image, or one the user may not edit.
        $_POST = array('attachment' => '0');
        $this->assertStringContainsString('altrelay_result=none', $this->run_handler(array($this->admin, 'handle_mark_image')));
        $altrelay_test_state['user_caps_for']['edit_post'][42] = false;
        $_POST = array('attachment' => '42');
        $this->assertStringContainsString('altrelay_result=none', $this->run_handler(array($this->admin, 'handle_mark_image')));
    }

    /**
     * Test that an image recorded as decorative can be listed as missing
     * alt text again.
     */
    public function test_list_image_again_handler(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42]['_altrelay_decorative'] = '1';
        $_POST = array('attachment' => '42');

        $this->assertStringContainsString('altrelay_result=image_listed', $this->run_handler(array($this->admin, 'handle_list_image_again')));
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertStringContainsString('altrelay_result=none', $this->run_handler(array($this->admin, 'handle_list_image_again')), 'Nothing left to undo.');
    }

    /**
     * Test the notices after marking an image.
     */
    public function test_mark_image_notices(): void
    {
        $_GET = array('altrelay_result' => 'image_started', 'altrelay_count' => '0');
        $this->assertStringContainsString('AltRelay is marking the image decorative in every post that uses it, in the background.', $this->render());

        $_GET = array('altrelay_result' => 'image_unused', 'altrelay_count' => '0');
        $this->assertStringContainsString('The image is not used in any post yet.', $this->render());

        $_GET = array('altrelay_result' => 'image_listed', 'altrelay_count' => '0');
        $this->assertStringContainsString('The image is listed as missing alt text again.', $this->render());
    }

    /**
     * Test that an image being marked in the background says so in its row,
     * and that a run that left copies unmarked says why.
     */
    public function test_marking_progress_is_shown_in_the_row(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][1]['_altrelay_marking'] = array('user' => 7, 'next' => 0, 'done' => false);
        $altrelay_test_state['post_meta'][2]['_altrelay_marking'] = array('user' => 7, 'marked' => 3, 'refused' => 1, 'skipped' => 2, 'failed' => 1, 'done' => true);

        $output = $this->render(array('total' => 10, 'with_alt' => 7, 'missing' => array(1, 2, 4)));

        $this->assertStringContainsString('Being marked decorative in the background.', $output);
        $this->assertStringContainsString('<li>Marked decorative in 3 posts, but not everywhere yet:</li>', $output);
        $this->assertStringContainsString('<li>1 copy is the only content of a link and needs alt text.</li>', $output);
        $this->assertStringContainsString('<li>2 posts could not be edited by the person who asked.', $output);
        $this->assertStringContainsString('<li>1 post could not be saved.</li>', $output);
        $this->assertSame(2, substr_count($output, 'altrelay-marking-note'), 'Image 4 is not being marked.');
    }

    /**
     * Test that the dashboard script that asks before marking is loaded on
     * the dashboard only.
     */
    public function test_the_confirmation_script_is_loaded_on_the_dashboard(): void
    {
        global $altrelay_test_state;

        $this->admin->enqueue_admin_assets('upload.php');
        $this->assertEmpty($altrelay_test_state['enqueued_scripts'] ?? array());

        $this->admin->enqueue_admin_assets('media_page_altrelay');
        $this->assertContains('altrelay-admin', $altrelay_test_state['enqueued_scripts'] ?? array());
    }

    /**
     * Test that only someone who can edit other people's posts runs jobs.
     */
    public function test_run_queue_requires_capability(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_caps']['edit_others_posts'] = false;

        $this->expectException(\RuntimeException::class);
        $this->admin->handle_run_queue();
    }
}
