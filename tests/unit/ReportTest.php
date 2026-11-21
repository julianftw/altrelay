<?php
/**
 * Unit tests for AltRelay_Report.
 *
 * Tests the numbers and lists behind the dashboard and WP-CLI: what is
 * queried, what is cached and when the cache is dropped, and how the review
 * lists are read page by page.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class ReportTest
 */
class ReportTest extends TestCase
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
            'is_admin' => true,
            'hooks' => array(),
        );

        $wpdb = new wpdb();

        // "Already flushed in this request" is remembered statically.
        $flushed = new ReflectionProperty(AltRelay_Report::class, 'usage_flushed');
        $flushed->setValue(null, false);
    }

    /**
     * Give a post an image without alt text.
     *
     * @param int    $post_id Post ID.
     * @param string $content Post content. Default: one empty-alt copy of image 42.
     */
    private function post(int $post_id, string $content = '<img src="a.jpg" alt="" class="wp-image-42" />'): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_fields'][$post_id] = array('post_content' => $content);
    }

    /**
     * Test that the coverage numbers are counted once and then come from
     * the transient.
     *
     * Regression (audit, Performance): they were recounted on every
     * dashboard load unless a persistent object cache was installed.
     */
    public function test_stats_are_counted_once(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_var_results = array('10', '7', '1');

        $expected = array('total' => 10, 'with_alt' => 7, 'without_alt' => 2, 'decorative' => 1);

        $this->assertSame($expected, AltRelay_Report::get_alt_text_stats());
        $this->assertSame($expected, AltRelay_Report::get_alt_text_stats());
        $this->assertCount(3, $wpdb->queries);
        $this->assertSame(array('total' => 10, 'with_alt' => 7, 'decorative' => 1), $altrelay_test_state['transients']['altrelay_stats']);
    }

    /**
     * Test that only images that are in the library are counted.
     */
    public function test_stats_ignore_trashed_attachments(): void
    {
        global $wpdb;

        $wpdb->stub_var_results = array('1', '1');

        AltRelay_Report::get_alt_text_stats();

        foreach ($wpdb->queries as $query) {
            $this->assertStringContainsString("post_status IN ('inherit', 'private')", $query);
            $this->assertStringContainsString("post_mime_type LIKE 'image/%'", $query);
        }
    }

    /**
     * Test that the numbers cannot contradict each other, and that a
     * transient holding something else is not trusted.
     */
    public function test_stats_are_consistent(): void
    {
        global $altrelay_test_state, $wpdb;

        // A duplicated meta row makes "with alt" exceed the total.
        $wpdb->stub_var_results = array('3', '5', '2');

        $this->assertSame(array('total' => 3, 'with_alt' => 3, 'without_alt' => 0, 'decorative' => 0), AltRelay_Report::get_alt_text_stats());

        $altrelay_test_state['transients']['altrelay_stats'] = 'corrupt';
        $wpdb->stub_var_results = array('4', '1', '0');

        $this->assertSame(array('total' => 4, 'with_alt' => 1, 'without_alt' => 3, 'decorative' => 0), AltRelay_Report::get_alt_text_stats());
    }

    /**
     * Test that the numbers are dropped when alt text or the library changes,
     * and only then.
     */
    public function test_stats_are_dropped_when_they_go_stale(): void
    {
        global $altrelay_test_state;

        $report = new AltRelay_Report();
        $stats = array('total' => 1, 'with_alt' => 1);

        $altrelay_test_state['transients']['altrelay_stats'] = $stats;
        $report->on_meta_changed(5, 42, '_edit_lock');
        $this->assertArrayHasKey('altrelay_stats', $altrelay_test_state['transients']);

        $report->on_meta_changed(5, 42, '_wp_attachment_image_alt');
        $this->assertArrayNotHasKey('altrelay_stats', $altrelay_test_state['transients']);

        $altrelay_test_state['transients']['altrelay_stats'] = $stats;
        $report->on_meta_changed(5, 42, '_altrelay_decorative');
        $this->assertArrayNotHasKey('altrelay_stats', $altrelay_test_state['transients'], 'An image recorded as decorative changes the counts.');

        $altrelay_test_state['transients']['altrelay_stats'] = $stats;
        $report->on_meta_changed(array(5), 42, '_wp_attachment_image_alt');
        $this->assertArrayNotHasKey('altrelay_stats', $altrelay_test_state['transients']);

        $altrelay_test_state['transients']['altrelay_stats'] = $stats;
        AltRelay_Report::flush_stats();
        $this->assertArrayNotHasKey('altrelay_stats', $altrelay_test_state['transients']);
    }

    /**
     * Test the hooks that keep the cached numbers fresh.
     */
    public function test_register_hooks(): void
    {
        global $altrelay_test_state;

        (new AltRelay_Report())->register();

        $this->assertSame(
            array('added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'add_attachment', 'delete_attachment', 'post_updated', 'wp_insert_post', 'before_delete_post'),
            array_keys($altrelay_test_state['hooks'])
        );
        $this->assertSame(3, $altrelay_test_state['hooks']['deleted_post_meta'][0]['accepted_args']);
        $this->assertSame(0, $altrelay_test_state['hooks']['add_attachment'][0]['accepted_args'], 'Callbacks without parameters must not be handed any.');
        $this->assertSame(3, $altrelay_test_state['hooks']['post_updated'][0]['accepted_args']);
        $this->assertSame(2, $altrelay_test_state['hooks']['before_delete_post'][0]['accepted_args']);
    }

    /**
     * Test the list of images without alt text, paged and whole.
     */
    public function test_missing_alt_ids(): void
    {
        global $wpdb;

        $wpdb->stub_col_results = array('9', 'x', '0', '5');

        $this->assertSame(array(9, 5), AltRelay_Report::get_missing_alt_ids(3, 50));
        $this->assertStringContainsString('ORDER BY p.post_date DESC, p.ID DESC LIMIT 50 OFFSET 100', $wpdb->last_query);
        $this->assertStringContainsString('NOT EXISTS', $wpdb->last_query);
        $this->assertStringContainsString("LEFT JOIN `wp_postmeta` dm ON dm.post_id = p.ID AND dm.meta_key = '_altrelay_decorative'", $wpdb->last_query);
        $this->assertStringContainsString('AND ( dm.meta_id IS NULL ) = 1', $wpdb->last_query, 'Images recorded as decorative are left out.');

        $wpdb->stub_col_results = array('4');
        $this->assertSame(array(4), AltRelay_Report::get_missing_alt_ids(1, 20, true));
        $this->assertStringContainsString('AND ( dm.meta_id IS NULL ) = 0', $wpdb->last_query);
        $this->assertStringContainsString("'_altrelay_decorative'", $wpdb->last_query);

        $wpdb->stub_col_results = array('9', 'x', '0', '5');

        AltRelay_Report::get_missing_alt_ids();

        $this->assertStringContainsString('ORDER BY p.ID ASC', $wpdb->last_query);
        $this->assertStringNotContainsString('LIMIT', $wpdb->last_query);
    }

    /**
     * Test that usage is counted by the database, for a whole page of
     * images in one query, without loading any post content.
     *
     * Regression (audit, Performance): the full content of every matching
     * post was loaded into PHP.
     */
    public function test_usage_counts_are_counted_by_the_database(): void
    {
        global $wpdb;

        $wpdb->stub_row_queue = array(array('2', '0', '5'));

        $this->assertSame(array(10 => 2, 20 => 0, 42 => 5), AltRelay_Report::get_usage_counts(array(10, 20, '42', 10, 0)));
        $this->assertCount(1, $wpdb->queries);

        $query = $wpdb->queries[0];

        $this->assertSame(3, substr_count($query, 'SUM(CASE WHEN LOCATE('));
        $this->assertStringNotContainsString('SELECT post_content', $query);
        $this->assertStringContainsString("LOCATE('wp-image-42', post_content) > 0 AND post_content REGEXP 'wp-image-42([^0-9]|$)'", $query, 'A cheap test first; a digit boundary keeps 42 from counting 420.');
        $this->assertStringContainsString("post_content LIKE '%wp-image-%'", $query);
        $this->assertStringContainsString("post_content REGEXP 'wp-image-(10|20|42)([^0-9]|$)'", $query, 'Only posts that use one of the images are counted over.');
        $this->assertStringContainsString("post_type IN ('post','page')", $query);
        $this->assertStringContainsString("post_status IN ('publish','draft','pending','private','future')", $query);
    }

    /**
     * Test that usage counts are cached until a post changes.
     */
    public function test_usage_counts_are_cached_until_a_post_changes(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb->stub_row_queue = array(array('2'), array('3'));

        $this->assertSame(array(10 => 2), AltRelay_Report::get_usage_counts(array(10)));
        $this->assertSame(array(10 => 2), AltRelay_Report::get_usage_counts(array(10)));
        $this->assertCount(1, $wpdb->queries);

        AltRelay_Report::flush_usage();

        $this->assertArrayNotHasKey('altrelay_usage', $altrelay_test_state['transients']);
        $this->assertSame(array(10 => 3), AltRelay_Report::get_usage_counts(array(10)));
    }

    /**
     * Test that a bulk import, which saves thousands of posts, drops the
     * cache once and not once per post.
     */
    public function test_usage_cache_is_dropped_once_per_request(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['transients']['altrelay_usage'] = array('x' => array(1 => 1));

        AltRelay_Report::flush_usage();
        $this->assertArrayNotHasKey('altrelay_usage', $altrelay_test_state['transients']);

        // Nothing has been cached since, so there is nothing to drop.
        $altrelay_test_state['transients']['altrelay_usage'] = 'sentinel';
        AltRelay_Report::flush_usage();
        $this->assertSame('sentinel', $altrelay_test_state['transients']['altrelay_usage']);

        // Once counts are cached again, the next change drops them again.
        $wpdb->stub_row_queue = array(array('1'));
        AltRelay_Report::get_usage_counts(array(10));
        AltRelay_Report::flush_usage();
        $this->assertArrayNotHasKey('altrelay_usage', $altrelay_test_state['transients']);
    }

    /**
     * Test the edges of the usage count.
     */
    public function test_usage_counts_edge_cases(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->assertSame(array(), AltRelay_Report::get_usage_counts(array()));
        $this->assertSame(array(), AltRelay_Report::get_usage_counts(array(0, 'x')));

        // The query returned nothing at all.
        $this->assertSame(array(10 => 0, 20 => 0), AltRelay_Report::get_usage_counts(array(10, 20)));

        // No post type is synced: nothing to search.
        $queries = count($wpdb->queries);
        $altrelay_test_state['filters']['altrelay_post_types'] = array(static fn(): array => array());

        $this->assertSame(array(30 => 0), AltRelay_Report::get_usage_counts(array(30)));
        $this->assertCount($queries, $wpdb->queries);
    }

    /**
     * Test that the cache keeps a bounded number of pages.
     */
    public function test_usage_cache_stays_small(): void
    {
        global $altrelay_test_state, $wpdb;

        for ($id = 1; $id <= 25; $id++) {
            $wpdb->stub_row_queue[] = array('1');
            AltRelay_Report::get_usage_counts(array($id));
        }

        $this->assertCount(20, $altrelay_test_state['transients']['altrelay_usage']);
        $this->assertSame(array(25 => 1), end($altrelay_test_state['transients']['altrelay_usage']));
    }

    /**
     * Test the rows of the empty-alt review.
     */
    public function test_empty_alt_review_rows(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = ' Dog ';
        $altrelay_test_state['post_meta'][43]['_wp_attachment_image_alt'] = 'Cat';

        $this->post(
            10,
            '<img src="a.jpg" alt="" class="wp-image-42" />'
                . '<a href="/x"><img src="a.jpg" class="wp-image-42" /></a>'
                . '<img src="b.jpg" alt="Described" class="wp-image-43" />'
                . '<img src="c.jpg" alt="" class="wp-image-44" />'
                . '<img src="d.jpg" alt="" class="wp-image-43 altrelay-decorative" />'
        );
        $altrelay_test_state['post_fields'][10]['post_title'] = 'Landing page';

        $wpdb->stub_col_results = array('10');

        $review = AltRelay_Report::get_empty_alt_review();

        $this->assertSame(
            array(
                array(
                    'post_id' => 10,
                    'post_title' => 'Landing page',
                    'attachment_id' => 42,
                    'library_alt' => 'Dog',
                    'copies' => 2,
                    'linked' => 1,
                ),
            ),
            $review['rows']
        );
        $this->assertSame(0, $review['next']);
        $this->assertSame(1, $review['scanned']);
    }

    /**
     * Test what makes a post a candidate for the empty-alt review.
     *
     * Regression (audit, Admin dashboard): an image without any alt
     * attribute was only found when the post also held an alt="".
     */
    public function test_empty_alt_candidates_include_images_without_alt_attribute(): void
    {
        global $wpdb;

        $wpdb->stub_col_results = array('10');

        AltRelay_Report::get_empty_alt_review();

        $window = $wpdb->queries[0];
        $query = $wpdb->last_query;

        // Regression (fifth audit): the tests below ran over every post of
        // the site when few posts qualified. They run on a window of the
        // newest posts with images now.
        $this->assertStringContainsString("SELECT ID FROM `wp_posts` WHERE post_content LIKE '%wp-image-%'", $window);
        $this->assertStringContainsString('ORDER BY ID DESC LIMIT 1000', $window);
        $this->assertStringContainsString('AND ID >= 10 AND ID < ' . PHP_INT_MAX . ' ', $query, 'The candidates come from the window.');

        $this->assertStringContainsString("post_content LIKE '%wp-image-%'", $query);
        $this->assertStringContainsString("post_content LIKE '%alt=\\\"\\\"%'", $query);
        $this->assertStringContainsString("LENGTH( REPLACE( post_content, '<img', '' ) ) < LENGTH( REPLACE( post_content, 'alt=', '' ) )", $query, 'More <img than alt= means an image has no alt attribute.');
        $this->assertStringContainsString("post_content LIKE '%has-parallax%'", $query, 'A fixed Cover background has no alt attribute either.');
        $this->assertStringContainsString('ORDER BY ID DESC', $query);
        $this->assertStringContainsString('LIMIT 100', $query);
        $this->assertStringContainsString('AND ID < ' . PHP_INT_MAX . ' ', $query, 'The first page starts from the newest post.');
    }

    /**
     * Test that a chunk of posts, and the meta of their images, is loaded
     * with two queries instead of one per post and image.
     */
    public function test_review_loads_posts_and_meta_in_bulk(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->post(10);
        $this->post(11, '<img src="b.jpg" alt="" class="wp-image-43" /><img src="a.jpg" alt="" class="wp-image-42" />');
        $this->post(12, '<p>No image</p>');

        $wpdb->stub_col_results = array('12', '11', '10');

        AltRelay_Report::get_empty_alt_review();

        $this->assertSame(array(array(12, 11, 10)), $altrelay_test_state['primed_posts']);
        $this->assertCount(1, $altrelay_test_state['meta_cache_primed']);

        $primed = $altrelay_test_state['meta_cache_primed'][0]['ids'];
        sort($primed);

        $this->assertSame(array(42, 43), $primed);
    }

    /**
     * Regression (audit bug 6): a page is filled with rows, not with
     * candidate posts, and says where the next one starts.
     */
    public function test_review_pages_by_rows(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';

        // Posts 30 to 21 are candidates without a row; 20 to 11 have one each.
        for ($id = 30; $id >= 11; $id--) {
            $this->post($id, $id > 20 ? '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />' : '<img src="a.jpg" alt="" class="wp-image-42" />');
        }

        $wpdb->stub_col_results = array_map('strval', range(30, 11));

        $first = AltRelay_Report::get_empty_alt_review(0, 3);

        $this->assertSame(array(20, 19, 18), array_column($first['rows'], 'post_id'));
        $this->assertSame(18, $first['next'], 'The next page starts below the last post that was read.');
        $this->assertSame(13, $first['scanned']);

        $wpdb->stub_col_results = array_map('strval', range(17, 11));

        $second = AltRelay_Report::get_empty_alt_review($first['next'], 3);

        $this->assertStringContainsString('AND ID < 18', $wpdb->last_query);
        $this->assertSame(array(17, 16, 15), array_column($second['rows'], 'post_id'));
    }

    /**
     * Test that a list that ends exactly on a short chunk reports its end.
     */
    public function test_review_reports_the_end_of_the_list(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $this->post(10);
        $this->post(11);

        $wpdb->stub_col_results = array('11', '10');

        $review = AltRelay_Report::get_empty_alt_review(0, 5);

        $this->assertCount(2, $review['rows']);
        $this->assertSame(0, $review['next']);
    }

    /**
     * Test that one request never reads more than a fixed number of posts.
     */
    public function test_review_stops_after_a_bounded_number_of_posts(): void
    {
        global $wpdb;

        // Six full chunks of candidates without a single row, each the
        // answer to both the window and the candidate query.
        for ($chunk = 0; $chunk < 6; $chunk++) {
            $ids = array_map('strval', range(1000 - $chunk * 100, 901 - $chunk * 100));
            $wpdb->stub_col_results_queue[] = $ids;
            $wpdb->stub_col_results_queue[] = $ids;
        }

        $review = AltRelay_Report::get_empty_alt_review();

        $this->assertSame(array(), $review['rows']);
        $this->assertSame(500, $review['scanned']);
        $this->assertSame(501, $review['next']);
        $this->assertCount(10, $wpdb->queries);
    }

    /**
     * Test that windows without candidates are passed over, up to a limit,
     * after which the page says where the next one starts.
     */
    public function test_review_passes_over_windows_without_candidates(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $this->post(5);

        // A full window without candidates, then one with post 5.
        $wpdb->stub_col_results_queue = array(array_map('strval', range(2000, 1001)), array(), array('5'), array('5'));

        $review = AltRelay_Report::get_empty_alt_review();

        $this->assertSame(array(5), array_column($review['rows'], 'post_id'));
        $this->assertSame(0, $review['next']);
        $this->assertStringContainsString('AND ID < 1001 ', $wpdb->queries[2], 'The second window starts below the first.');

        // Ten full windows without a candidate: the page stops there.
        $wpdb = new wpdb();

        for ($window = 0; $window < 11; $window++) {
            $wpdb->stub_col_results_queue[] = array_map('strval', range(20000 - $window * 1000, 19001 - $window * 1000));
            $wpdb->stub_col_results_queue[] = array();
        }

        $review = AltRelay_Report::get_empty_alt_review();

        $this->assertSame(array(), $review['rows']);
        $this->assertSame(10001, $review['next'], 'The next page starts below the tenth window.');
        $this->assertCount(20, $wpdb->queries);
    }

    /**
     * Regression (audit bug 5): posts the current user cannot edit are
     * passed over. Without a user (WP-CLI, cron) every post is listed.
     */
    public function test_review_only_lists_posts_the_user_can_edit(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';
        $this->post(10);
        $this->post(11);
        $altrelay_test_state['post_fields'][11]['post_title'] = 'Private: salaries';
        $altrelay_test_state['user_caps_for']['edit_post'][11] = false;

        $wpdb->stub_col_results = array('11', '10');
        $altrelay_test_state['user_logged_in'] = true;

        $this->assertSame(array(10), array_column(AltRelay_Report::get_empty_alt_review()['rows'], 'post_id'));

        $altrelay_test_state['user_logged_in'] = false;

        $this->assertSame(array(11, 10), array_column(AltRelay_Report::get_empty_alt_review()['rows'], 'post_id'));
    }

    /**
     * Test the list of decorative images.
     */
    public function test_decorative_review_rows(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Dog';

        $this->post(
            10,
            '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />'
                . '<!-- wp:image {"id":43,"isDecorative":true} --><figure class="wp-block-image"><img src="b.jpg" alt="" class="wp-image-43" role="none"/></figure><!-- /wp:image -->'
                . '<img src="c.jpg" alt="" role="presentation" class="wp-image-44" />'
                . '<img src="d.jpg" alt="Not decorative" class="wp-image-45" />'
        );

        $wpdb->stub_col_results = array('10');

        $review = AltRelay_Report::get_decorative_review();

        $this->assertSame(array(42, 43), array_column($review['rows'], 'attachment_id'), 'A role typed by hand cannot be undone by AltRelay, so it is not listed.');
        $this->assertSame(array('Dog', ''), array_column($review['rows'], 'library_alt'));
        $this->assertStringContainsString("post_content LIKE '%altrelay-decorative%'", $wpdb->last_query);
        $this->assertStringContainsString('isDecorative', $wpdb->last_query);
    }

    /**
     * Test that nothing is searched when no post type is synced.
     */
    public function test_review_without_post_types(): void
    {
        global $altrelay_test_state, $wpdb;

        $altrelay_test_state['filters']['altrelay_post_types'] = array(static fn(): array => array());

        $this->assertSame(array('rows' => array(), 'next' => 0, 'scanned' => 0), AltRelay_Report::get_empty_alt_review());
        $this->assertSame(array('rows' => array(), 'next' => 0, 'scanned' => 0), AltRelay_Report::get_decorative_review(50));
        $this->assertEmpty($wpdb->queries);
    }


    /**
     * Regression (second audit, Performance): the usage counts were dropped
     * on every save_post, so a busy site recomputed them on every visit.
     */
    public function test_usage_counts_are_kept_through_saves_that_change_no_image(): void
    {
        global $altrelay_test_state;

        $with = new WP_Post(array('ID' => 5, 'post_content' => '<img class="wp-image-42" />'));
        $without = new WP_Post(array('ID' => 5, 'post_content' => '<p>Text</p>'));
        $edited = new WP_Post(array('ID' => 5, 'post_content' => '<p>Other text</p>'));

        $drops = function (callable $action): bool {
            global $altrelay_test_state;
            $altrelay_test_state['transients']['altrelay_usage'] = array('page' => array(42 => 3));
            (new ReflectionProperty(AltRelay_Report::class, 'usage_flushed'))->setValue(null, false);
            $action();
            return !isset($altrelay_test_state['transients']['altrelay_usage']);
        };

        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_updated(5, $edited, $without)), 'Text edits keep the cache.');
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_updated(5, $with, $with)), 'Saving without a change keeps it.');
        $this->assertTrue($drops(fn() => AltRelay_Report::on_post_updated(5, $with, $without)), 'An image added.');
        $this->assertTrue($drops(fn() => AltRelay_Report::on_post_updated(5, $without, $with)), 'An image removed.');

        $trashed = clone $with;
        $trashed->post_status = 'trash';
        $this->assertTrue($drops(fn() => AltRelay_Report::on_post_updated(5, $trashed, $with)), 'A post with images trashed.');

        $revision = new WP_Post(array('post_type' => 'revision', 'post_content' => '<img class="wp-image-42" />'));
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_updated(6, $revision, $without)), 'Revisions do not count.');
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_updated(5, 'x', 'y')));

        $this->assertTrue($drops(fn() => AltRelay_Report::on_post_inserted(7, $with, false)), 'A new post with an image.');
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_inserted(7, $with, true)), 'Updates are handled by post_updated.');
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_inserted(7, $without, false)));

        $this->assertTrue($drops(fn() => AltRelay_Report::on_post_deleting(5, $with)));
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_deleting(5, $without)));
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_deleting(5)));

        $retexted = new WP_Post(array('ID' => 5, 'post_content' => '<p>New text</p><img class="wp-image-42" />'));
        $this->assertFalse($drops(fn() => AltRelay_Report::on_post_updated(5, $retexted, $with)), 'Text edits around the same images keep the cache.');
    }

    /**
     * Regression (sixth audit, Performance): every save of a post with an
     * image dropped every cached count, so a busy site recomputed them on
     * almost every visit. Only the pages that count the images a save
     * added or removed are dropped.
     */
    public function test_a_save_drops_only_the_counts_it_changes(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['transients']['altrelay_usage'] = array(
            'a' => array(42 => 3, 43 => 1),
            'b' => array(50 => 2),
        );

        $before = new WP_Post(array('ID' => 5, 'post_content' => '<img class="wp-image-50" />'));
        $after = new WP_Post(array('ID' => 5, 'post_content' => '<img class="wp-image-50" /><img class="wp-image-43" />'));

        AltRelay_Report::on_post_updated(5, $after, $before);

        $this->assertSame(array('b' => array(50 => 2)), $altrelay_test_state['transients']['altrelay_usage']);

        // The last page that counts an affected image takes the cache with it.
        AltRelay_Report::flush_usage(array(50, 99));
        $this->assertArrayNotHasKey('altrelay_usage', $altrelay_test_state['transients']);

        // Nothing cached: nothing to do.
        AltRelay_Report::flush_usage(array(50));
        $this->assertArrayNotHasKey('altrelay_usage', $altrelay_test_state['transients']);
    }
}
