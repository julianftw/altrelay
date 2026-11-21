<?php
/**
 * Unit tests for AltRelay_Sync.
 *
 * Tests the sync engine: regex rewriting, propagation logic,
 * recursion guard, and capability checks.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SyncTest
 */
class SyncTest extends TestCase
{

    /**
     * The sync engine instance.
     *
     * @var AltRelay_Sync
     */
    private AltRelay_Sync $sync;

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
        );

        $this->sync = new AltRelay_Sync();

    }

    /**
     * Test rewriting alt on a simple img tag with existing alt.
     */
    public function test_rewrite_replaces_existing_alt(): void
    {
        $content = '<img src="photo.jpg" alt="old text" class="wp-image-42" />';
        $expected = '<img src="photo.jpg" alt="new text" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertSame($expected, $result);
    }

    /**
     * Test that an unmarked empty alt is filled.
     *
     * The block editor saves alt="" for every image inserted without alt
     * text, so an unmarked empty alt means "not written yet".
     */
    public function test_rewrite_fills_unmarked_empty_alt(): void
    {
        $content = '<img src="photo.jpg" alt="" class="wp-image-42" />';
        $expected = '<img src="photo.jpg" alt="new text" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertSame($expected, $result);
    }

    /**
     * Test that a marked decorative image is never changed.
     */
    public function test_rewrite_leaves_marked_decorative_image_alone(): void
    {
        $content = '<figure class="wp-block-image altrelay-decorative"><img src="photo.jpg" alt="" class="wp-image-42" /></figure>';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertSame($content, $result);
    }

    /**
     * Test that sites can keep treating empty alt as decorative.
     */
    public function test_rewrite_respects_empty_alt_when_site_opts_in(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_treat_empty_alt_as_decorative'] = array(
            static fn(): bool => true,
        );

        $content = '<img src="photo.jpg" alt="" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertSame($content, $result);
    }

    /**
     * Test that clearing and then re-setting alt text reaches every post.
     *
     * Regression test: when empty alt counted as decorative, the empty value
     * AltRelay wrote on clear blocked every later sync permanently.
     */
    public function test_clear_then_reset_reaches_the_post(): void
    {
        $content = '<img src="photo.jpg" alt="Old" class="wp-image-42" />';

        $cleared = AltRelay_Content::rewrite_attachment_alt($content, 42, '');
        $this->assertStringContainsString('alt=""', $cleared);

        $reset = AltRelay_Content::rewrite_attachment_alt($cleared, 42, 'New');
        $this->assertStringContainsString('alt="New"', $reset);
    }

    /**
     * Test that alt is added when missing entirely.
     */
    public function test_rewrite_adds_alt_when_missing(): void
    {
        $content = '<img src="photo.jpg" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertStringContainsString('alt="new text"', $result);
    }

    /**
     * Test that only the targeted image is rewritten, not others.
     */
    public function test_rewrite_does_not_affect_other_images(): void
    {
        $content = '<img src="a.jpg" alt="keep me" class="wp-image-10" />'
            . '<img src="b.jpg" alt="old" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('alt="keep me"', $result);
        $this->assertStringContainsString('alt="new text"', $result);
    }

    /**
     * Test that content without the target image class is returned unchanged.
     */
    public function test_rewrite_returns_unchanged_when_no_match(): void
    {
        $content = '<img src="photo.jpg" alt="hello" class="wp-image-99" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');
        $this->assertSame($content, $result);
    }

    /**
     * Test rewriting handles special HTML characters in alt text.
     */
    public function test_rewrite_handles_special_characters(): void
    {
        $content = '<img src="photo.jpg" alt="old" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'A & B "quoted"');
        $this->assertStringContainsString('alt="A &amp; B &quot;quoted&quot;"', $result);
    }

    /**
     * Test rewriting multiple occurrences of the same image.
     */
    public function test_rewrite_updates_multiple_occurrences(): void
    {
        $content = '<img src="a.jpg" alt="old1" class="wp-image-42" />'
            . '<p>text</p>'
            . '<img src="b.jpg" alt="old2" class="size-full wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'unified');

        $this->assertSame(2, substr_count($result, 'alt="unified"'));
    }

    /**
     * Test that on_alt_meta_changed ignores non-alt meta keys.
     */
    public function test_ignores_non_alt_meta_key(): void
    {
        global $altrelay_test_state;

        // Should not throw or do anything.
        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_metadata', 'some value');

        $this->assertEmpty($altrelay_test_state['transients']);
    }

    /**
     * Test that on_alt_meta_changed respects upload_files capability.
     */
    public function test_respects_capability_check(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['user_logged_in'] = true;
        $altrelay_test_state['user_caps']['upload_files'] = false;

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'test');

        $this->assertEmpty($altrelay_test_state['transients']);
    }

    /**
     * Regression (fourth audit): one post that threw on save stopped the
     * rest of the image's posts.
     */
    public function test_one_failing_post_does_not_stop_the_others(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11', '12');

        foreach (array(10, 11, 12) as $post_id) {
            $altrelay_test_state['post_fields'][$post_id] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }

        $altrelay_test_state['on_before_save'] = static function (int $post_id): void {
            if (11 === $post_id) {
                throw new \RuntimeException('Post 11 broke.');
            }
        };

        $report = $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(2, $report['updated']);
        $this->assertSame(1, $report['failed']);
        $this->assertStringContainsString('alt="new alt"', $altrelay_test_state['post_fields'][12]['post_content'], 'The post after the failing one is updated.');
        $this->assertSame(11, $altrelay_test_state['transients']['altrelay_sync_errors']['post_id']);
        $this->assertSame(array(42), AltRelay_Sync::images_to_retry());
    }

    /**
     * Test that a hook on altrelay_post_synced that throws counts as a failed
     * post, not as the end of the run.
     */
    public function test_a_throwing_synced_hook_is_recorded(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');

        $altrelay_test_state['action_callbacks']['altrelay_post_synced'] = array(
            static function (): void {
                throw new RuntimeException('Listener broke.');
            },
        );

        $this->assertSame(1, $this->sync->sync_attachment(42, 'new alt')['failed']);
        $this->assertSame('Listener broke.', $altrelay_test_state['transients']['altrelay_sync_errors']['message']);
    }

    /**
     * Describe image 42, without alt text, in posts 10 to 12.
     *
     * @param array<int, string> $contents Content by post ID.
     */
    private function posts_using_42(array $contents): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array_map('strval', array_keys($contents));
        $altrelay_test_state['post_types_by_id'][42] = 'attachment';

        foreach ($contents as $post_id => $content) {
            $altrelay_test_state['post_fields'][$post_id] = array('post_content' => $content);
        }
    }

    /**
     * An image without alt text that a user decided is decorative is marked
     * in every post that uses it, and recorded so the dashboard stops
     * listing it as missing alt text.
     */
    public function test_an_image_is_marked_decorative_wherever_it_is_used(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(
            array(
                10 => '<img src="a.jpg" alt="" class="wp-image-42" />',
                11 => '<p>Text</p><img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
            )
        );

        $report = $this->sync->mark_attachment_decorative(42);

        $this->assertSame(array('marked' => 1, 'refused' => 0, 'skipped' => 0, 'failed' => 0, 'recorded' => true), $report, 'Post 11 was marked already.');
        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42));

        // Described after all: no longer recorded as decorative.
        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', '   ');
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42), 'Blank alt text is still none.');
        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'A dog');
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
    }

    /**
     * Test that an image is only recorded as decorative when every copy was
     * marked: a linked copy needs a description, and a post the user cannot
     * edit keeps its copy.
     */
    public function test_an_image_is_not_recorded_while_copies_stay_unmarked(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(
            array(
                10 => '<img src="a.jpg" alt="" class="wp-image-42" />',
                11 => '<p><a href="https://example.com/"><img src="a.jpg" alt="" class="wp-image-42" /></a></p>',
                12 => '<img src="a.jpg" alt="" class="wp-image-42" />',
            )
        );
        $altrelay_test_state['user_caps_for']['edit_post'][12] = false;
        $altrelay_test_state['user_logged_in'] = true;

        $report = $this->sync->mark_attachment_decorative(42);

        $this->assertSame(array('marked' => 1, 'refused' => 1, 'skipped' => 1, 'failed' => 0, 'recorded' => false), $report);
        $this->assertStringNotContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][12]['post_content']);
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
    }

    /**
     * Test that a copy whose save fails, or throws, keeps the image off the
     * record.
     */
    public function test_an_image_is_not_recorded_when_a_save_fails(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(
            array(
                10 => '<img src="a.jpg" alt="" class="wp-image-42" />',
                11 => '<img src="a.jpg" alt="" class="wp-image-42" />',
            )
        );
        // Post 10 cannot be saved; post 11 throws while it is saved.
        $altrelay_test_state['fail_update_post'] = true;

        $this->assertSame(array('marked' => 0, 'refused' => 0, 'skipped' => 0, 'failed' => 2, 'recorded' => false), $this->sync->mark_attachment_decorative(42));

        $altrelay_test_state['fail_update_post'] = false;
        $altrelay_test_state['on_before_save'] = static function (int $post_id): void {
            if (11 === $post_id) {
                throw new RuntimeException('Broke.');
            }
        };

        $report = $this->sync->mark_attachment_decorative(42);

        $this->assertSame(1, $report['marked']);
        $this->assertFalse($report['recorded']);
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
    }

    /**
     * Regression (fifth audit): the dashboard marked every post in the one
     * request, which an image used in thousands of posts could not finish.
     * Starting keeps the progress on the image and queues a job; each job
     * marks one batch, and the last records the image.
     */
    public function test_marking_runs_batch_by_batch_through_the_queue(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_use_action_scheduler'] = array(static fn(): bool => false);
        $this->posts_using_42(
            array(
                10 => '<img src="a.jpg" alt="" class="wp-image-42" />',
                11 => '<img src="a.jpg" alt="" class="wp-image-42" />',
            )
        );

        // The first page of posts when marking starts, then one per batch.
        $GLOBALS['wpdb']->stub_col_results_queue = array(array('10', '11'), array('10', '11'), array('11'));

        $this->assertSame('started', $this->sync->start_marking_decorative(42, 7));
        AltRelay_Queue::flush();

        $this->assertSame(array('42:7' => array(42, 0, 7)), get_option('altrelay_queue')['waiting']);
        $this->assertStringNotContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content'], 'Nothing is marked until the job runs.');

        // One post per batch.
        $this->assertSame(10, $this->sync->continue_marking(42, 0, 1, 7));
        $this->assertSame(10, AltRelay_Sync::marking_state(42)['next']);
        $this->assertNull($this->sync->continue_marking(42, 0, 1, 7), 'A batch that does not start where the last ended syncs as usual.');
        $this->assertNull($this->sync->continue_marking(42, 10, 1, 8), 'Nor does a job of another user.');

        $this->assertSame(0, $this->sync->continue_marking(42, 10, 1, 7), 'The last page of posts ends the run.');

        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][11]['post_content']);
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertNull(AltRelay_Sync::marking_state(42), 'Nothing is left to show once every copy is marked.');
    }

    /**
     * Test that a run that leaves copies unmarked keeps what it found for
     * the dashboard, that starting again starts over, and that alt text
     * given meanwhile stops it.
     */
    public function test_marking_that_leaves_copies_keeps_what_it_found(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(
            array(
                10 => '<p><a href="https://example.com/"><img src="a.jpg" alt="" class="wp-image-42" /></a></p>',
                11 => '<img src="a.jpg" alt="" class="wp-image-42" />',
            )
        );

        $this->sync->start_marking_decorative(42, 0, false);
        $this->assertSame(0, $this->sync->continue_marking(42, 0, 0, 0));

        $state = AltRelay_Sync::marking_state(42);
        $this->assertSame(array(1, 1, true), array($state['marked'], $state['refused'], $state['done']));
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertNull($this->sync->continue_marking(42, 0, 0, 0), 'A finished run does not run again.');

        // Starting again starts over.
        $this->sync->start_marking_decorative(42, 0, false);
        $this->assertSame(array(0, false), array(AltRelay_Sync::marking_state(42)['marked'], AltRelay_Sync::marking_state(42)['done']));

        // Described meanwhile: the job syncs the new text instead.
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'A dog';
        $this->assertNull($this->sync->continue_marking(42, 0, 0, 0));
        $this->assertNull(AltRelay_Sync::marking_state(42));
        $this->assertSame('', $this->sync->start_marking_decorative(42, 0), 'An image with alt text is not marked.');
    }

    /**
     * Test that the queue worker hands marking jobs to the sync engine.
     */
    public function test_the_queue_runs_marking_jobs(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(array(10 => '<img src="a.jpg" alt="" class="wp-image-42" />'));
        $this->sync->start_marking_decorative(42, 0, false);

        (new AltRelay_Queue($this->sync))->process(42, 0, 0);

        $this->assertStringContainsString('altrelay-decorative', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42));
    }

    /**
     * Regression (sixth audit): a post open in an editor while an image's
     * alt text changed was rewritten in the background, and the editor,
     * which still showed the old text and saw no change of its own, saved
     * the old text back. The text the update replaced is remembered, and a
     * save that still holds it gets the current text.
     */
    public function test_a_save_from_an_editor_that_was_open_keeps_the_new_alt(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'New';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="Old" class="wp-image-42" />');
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="Old" class="wp-image-42" />');

        // Post 10 is open in an editor; post 11 is not; an old lock does not count.
        $altrelay_test_state['post_meta'][10]['_edit_lock'] = (time() - 10) . ':5';
        $altrelay_test_state['post_meta'][11]['_edit_lock'] = (time() - 3600) . ':5';

        $this->assertSame(2, $this->sync->sync_attachment(42, 'New')['updated']);

        $this->assertSame(array(42 => array('old' => array('Old'), 'time' => time())), AltRelay_Sync::stale_alts(10));
        $this->assertSame(array(), AltRelay_Sync::stale_alts(11));

        // The editor saves what it still shows: one untouched copy, one the author changed.
        $save = static fn(string $content): array => array('post_content' => wp_slash($content));
        $data = $this->sync->refresh_stale_alts(
            $save('<img src="a.jpg" alt="Old" class="wp-image-42" /><img src="a.jpg" alt="Typed" class="wp-image-42" />'),
            array('ID' => 10)
        );

        $this->assertSame('<img src="a.jpg" alt="New" class="wp-image-42" /><img src="a.jpg" alt="Typed" class="wp-image-42" />', wp_unslash($data['post_content']));
        $this->assertNotEmpty(AltRelay_Sync::stale_alts(10), 'Kept while the editor still holds the old text.');

        // Reloaded, the editor holds the new text: the record goes.
        $fresh = $save('<img src="a.jpg" alt="New" class="wp-image-42" />');
        $this->assertSame($fresh, $this->sync->refresh_stale_alts($fresh, array('ID' => 10)));
        $this->assertSame(array(), AltRelay_Sync::stale_alts(10));

        // Other saves pass through untouched.
        $this->assertSame($fresh, $this->sync->refresh_stale_alts($fresh, array('ID' => 11)));
        $this->assertSame($fresh, $this->sync->refresh_stale_alts($fresh, array()));
        $this->assertSame('not data', $this->sync->refresh_stale_alts('not data', array('ID' => 10)));
    }

    /**
     * Test that an old record is not acted on, and that a decorative copy
     * or one left empty on purpose is never refreshed.
     */
    public function test_stale_alts_expire_and_leave_decorative_copies_alone(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'New';
        $altrelay_test_state['post_meta'][10][AltRelay_Sync::STALE_META] = array(42 => array('old' => array('Old', ''), 'time' => time() - 2 * DAY_IN_SECONDS));

        $save = array('post_content' => '<img src="a.jpg" alt="Old" class="wp-image-42" />');

        $this->assertSame($save, $this->sync->refresh_stale_alts($save, array('ID' => 10)));
        $this->assertSame(array(), AltRelay_Sync::stale_alts(10), 'An expired record is dropped.');

        $altrelay_test_state['post_meta'][10][AltRelay_Sync::STALE_META] = array(42 => array('old' => array(''), 'time' => time()));
        $marked = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />');

        $this->assertSame($marked, $this->sync->refresh_stale_alts($marked, array('ID' => 10)));
    }

    /**
     * Regression (sixth audit): a post read by one job and saved by another
     * in between lost the other job's change. The stored content is read
     * again right before the save, and rewritten from there.
     */
    public function test_a_change_saved_meanwhile_is_kept(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="Old" class="wp-image-42" /><img src="b.jpg" alt="Old" class="wp-image-43" />');

        // Another request updates image 43 while this one works on 42.
        $altrelay_test_state['on_clean_post_cache'] = static function (int $post_id): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_clean_post_cache']);
            $altrelay_test_state['post_fields'][$post_id]['post_content'] = '<img src="a.jpg" alt="Old" class="wp-image-42" /><img src="b.jpg" alt="Other new" class="wp-image-43" />';
        };

        $this->assertSame(1, $this->sync->sync_attachment(42, 'New')['updated']);
        $this->assertSame('<img src="a.jpg" alt="New" class="wp-image-42" /><img src="b.jpg" alt="Other new" class="wp-image-43" />', $altrelay_test_state['post_fields'][10]['post_content']);
    }

    /**
     * Test that saving a post is watched for new copies of recorded images.
     */
    public function test_register_watches_saved_posts(): void
    {
        global $altrelay_test_state;

        $this->sync->register();

        $this->assertSame(2, $altrelay_test_state['hooks']['save_post'][0]['accepted_args']);
    }

    /**
     * Regression (fifth audit): once an image was recorded as decorative, a
     * new copy without the mark went unnoticed. Saving a post with such a
     * copy lists the image as missing alt text again.
     */
    public function test_a_new_unmarked_copy_lists_the_image_again(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_altrelay_decorative'] = '1';
        $altrelay_test_state['post_meta'][43]['_altrelay_decorative'] = '1';
        $post = static fn(string $content, string $type = 'post'): WP_Post => new WP_Post(array('ID' => 10, 'post_type' => $type, 'post_content' => $content));

        // Marked copies, and posts AltRelay does not sync, change nothing.
        $this->sync->on_post_saved(10, $post('<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" /><img src="b.jpg" alt="Described" class="wp-image-43" />'));
        $this->sync->on_post_saved(10, $post('<img src="a.jpg" alt="" class="wp-image-42" />', 'wp_navigation'));
        $this->sync->on_post_saved(10, $post('<p>No image</p>'));
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(43));

        $this->sync->on_post_saved(10, $post('<img src="a.jpg" alt="" class="wp-image-42" />'));
        $this->assertFalse(AltRelay_Sync::is_recorded_decorative(42));
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(43));

        // A revision is not a use.
        $altrelay_test_state['revisions'][11] = true;
        $this->sync->on_post_saved(11, $post('<img src="b.jpg" class="wp-image-43" />'));
        $this->assertTrue(AltRelay_Sync::is_recorded_decorative(43));
    }

    /**
     * Test that an image used nowhere is recorded at once, that one with
     * alt text or one that is no image is left alone, and that the record
     * can be removed.
     */
    public function test_recording_an_unused_image_and_forgetting_it(): void
    {
        global $altrelay_test_state;

        $this->posts_using_42(array());

        $this->assertTrue($this->sync->mark_attachment_decorative(42)['recorded']);
        $this->assertTrue(AltRelay_Sync::forget_recorded_decorative(42));
        $this->assertFalse(AltRelay_Sync::forget_recorded_decorative(42));

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'A dog';
        $this->assertFalse($this->sync->mark_attachment_decorative(42)['recorded']);

        $this->assertFalse($this->sync->mark_attachment_decorative(10)['recorded'], 'Not an attachment.');
    }

    /**
     * Test that the images to run again are kept once each, newest first,
     * and can be forgotten one by one or all at once.
     */
    public function test_images_to_retry_are_kept_and_forgotten(): void
    {
        AltRelay_Sync::forget_images_to_retry();
        $this->assertSame(array(), AltRelay_Sync::images_to_retry());

        AltRelay_Sync::record_error(10, 'One', 42);
        AltRelay_Sync::record_error(11, 'Two', 43);
        AltRelay_Sync::record_error(12, 'Three', 42);
        AltRelay_Sync::record_error(0, 'A query failed');

        $this->assertSame(array(42, 43), AltRelay_Sync::images_to_retry());

        AltRelay_Sync::forget_images_to_retry(array(42));
        $this->assertSame(array(43), AltRelay_Sync::images_to_retry());

        AltRelay_Sync::forget_images_to_retry();
        $this->assertSame(array(), AltRelay_Sync::images_to_retry());
        $this->assertSame(4, get_transient('altrelay_sync_errors')['count'], 'The errors themselves stay.');
    }

    /**
     * Regression (fourth audit): code switched to a site of the network
     * where AltRelay is not active queued a job that nothing would run.
     */
    public function test_no_job_is_queued_on_a_site_without_altrelay(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['is_multisite'] = true;
        $altrelay_test_state['user_logged_in'] = false;
        $altrelay_test_state['as_actions'] = array();

        // Loaded without being activated, as a must-use plugin: everywhere.
        $this->sync->register();
        switch_to_blog(2);
        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'other site');
        $this->assertCount(1, $altrelay_test_state['as_actions'], 'A must-use plugin runs on every site.');
        restore_current_blog();

        // Activated on site 1 only.
        $altrelay_test_state['options']['active_plugins'] = array(ALTRELAY_PLUGIN_BASENAME);
        $this->sync->register();
        $altrelay_test_state['as_actions'] = array();
        switch_to_blog(2);

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'other site');
        $this->assertCount(0, $altrelay_test_state['as_actions'], 'Not active on site 2.');

        $altrelay_test_state['blog_options'][2]['active_plugins'] = array(ALTRELAY_PLUGIN_BASENAME);
        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'other site');
        $this->assertCount(1, $altrelay_test_state['as_actions'], 'Active on site 2.');

        $altrelay_test_state['blog_options'][2]['active_plugins'] = array();
        $altrelay_test_state['site_options']['active_sitewide_plugins'] = array(ALTRELAY_PLUGIN_BASENAME => time());
        $this->sync->on_alt_meta_changed(1, 43, '_wp_attachment_image_alt', 'other site');
        $this->assertCount(2, $altrelay_test_state['as_actions'], 'Active on the whole network.');

        restore_current_blog();
    }

    /**
     * Test that programmatic contexts (no logged-in user) are allowed to sync.
     */
    public function test_allows_sync_without_logged_in_user(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['user_logged_in'] = false;
        $altrelay_test_state['user_caps']['upload_files'] = false;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'cli alt');

        // The hook path now queues the work instead of writing inline.
        $this->assertCount(1, $altrelay_test_state['as_actions']);
        // Attachment, cursor, user. The alt text is read when the job runs.
        $this->assertSame(array(42, 0, 0), $altrelay_test_state['as_actions'][0]['args']);
    }

    /**
     * Test propagation with a matching post.
     */
    public function test_propagate_updates_matching_post(): void
    {
        global $altrelay_test_state, $wpdb;

        // Set up a mock wpdb.
        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(10, $altrelay_test_state['updated_posts'][0]['ID']);
        $this->assertStringContainsString('alt="new alt"', $altrelay_test_state['updated_posts'][0]['post_content']);
    }

    /**
     * Test propagation skips posts the user cannot edit.
     */
    public function test_propagate_skips_posts_without_edit_cap(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['user_logged_in'] = true;
        $altrelay_test_state['user_caps']['edit_post'] = false;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(0, $altrelay_test_state['updated_posts']);
    }

    /**
     * Test propagation does nothing when no posts match.
     */
    public function test_propagate_does_nothing_without_posts(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(0, $altrelay_test_state['updated_posts']);
    }

    /**
     * Test that a failing wp_update_post does not count as a sync.
     */
    public function test_propagate_ignores_failed_update(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['fail_update_post'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
    }

    /**
     * Test that dollar signs and backslashes in alt text are not treated as backreferences.
     */
    public function test_rewrite_does_not_interpret_backreferences(): void
    {
        $content = '<img src="photo.jpg" alt="old" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'Cost $5 for C:\\1path');

        $this->assertStringContainsString('alt="Cost $5 for C:\\1path"', $result);
    }

    /**
     * Test rewriting a single-quoted alt attribute.
     */
    public function test_rewrite_handles_single_quoted_alt(): void
    {
        $content = "<img src='photo.jpg' alt='old text' class='wp-image-42' />";

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('alt="new text"', $result);
        $this->assertSame(1, substr_count($result, 'alt='));
    }

    /**
     * Test rewriting an unquoted alt attribute.
     */
    public function test_rewrite_handles_unquoted_alt(): void
    {
        $content = '<img src="photo.jpg" alt=old class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('alt="new text"', $result);
        $this->assertSame(1, substr_count($result, 'alt='));
    }

    /**
     * Test that wp-image-420 is not treated as wp-image-42.
     */
    public function test_rewrite_does_not_match_longer_attachment_ids(): void
    {
        $content = '<img src="photo.jpg" alt="keep" class="wp-image-420" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertSame($content, $result);
    }

    /**
     * Test that the attachment lookup uses a digit boundary.
     */
    public function test_find_posts_uses_digit_boundary_pattern(): void
    {
        global $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertStringContainsString('wp-image-42([^0-9]|$)', $wpdb->last_query);
        $this->assertStringContainsString('`wp_posts`', $wpdb->last_query);
    }

    /**
     * Test that the altrelay_should_sync filter can veto propagation.
     */
    public function test_should_sync_filter_can_veto(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['filters']['altrelay_should_sync'] = array(
            static fn(bool $should): bool => false,
        );

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'new alt');

        $this->assertCount(0, $altrelay_test_state['updated_posts']);
    }

    /**
     * Test that the altrelay_alt_text filter can rewrite the written value.
     */
    public function test_alt_text_filter_rewrites_value(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['filters']['altrelay_alt_text'] = array(
            static fn(string $alt): string => strtoupper($alt),
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertStringContainsString('alt="NEW ALT"', $altrelay_test_state['updated_posts'][0]['post_content']);
    }

    /**
     * Test that the altrelay_post_types filter excludes other post types.
     */
    public function test_post_types_filter_narrows_results(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $altrelay_test_state['post_types_by_id'] = array(
            10 => 'post',
            11 => 'page',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['post_fields'][11] = array(
            'post_content' => '<img src="b.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['filters']['altrelay_post_types'] = array(
            static fn(): array => array('page'),
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(11, $altrelay_test_state['updated_posts'][0]['ID']);
    }

    /**
     * Test that attachments are excluded from the default post type list.
     */
    public function test_attachments_excluded_from_default_post_types(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $altrelay_test_state['post_types_by_id'] = array(
            10 => 'attachment',
            11 => 'post',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['post_fields'][11] = array(
            'post_content' => '<img src="b.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(11, $altrelay_test_state['updated_posts'][0]['ID']);
    }

    /**
     * Test that propagation pages through results until a short batch.
     */
    public function test_propagate_pages_through_batches(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        // First batch is full (200), second batch is short (1) — two queries.
        $full_batch = array_map(static fn(int $i): string => (string) $i, range(1, 200));
        $wpdb->stub_col_results_queue = array($full_batch, array('201'));

        $altrelay_test_state['post_fields'][201] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(2, $wpdb->query_count);
        $this->assertCount(1, $altrelay_test_state['updated_posts']);
    }

    /**
     * Test that a limited run resumes at the first unprocessed post.
     *
     * Regression test: the cursor was advanced before the limit check, so the
     * post at every batch boundary was skipped and never synced.
     */
    public function test_limited_run_does_not_skip_boundary_post(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        // Each page mirrors the "ID > cursor" query the engine issues.
        $wpdb->stub_col_results_queue = array(
            array('1', '2', '3', '4', '5'),
            array('3', '4', '5'),
            array('5'),
        );

        foreach (range(1, 5) as $id) {
            $altrelay_test_state['post_fields'][$id] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }

        // Process two posts, then resume from the reported cursor.
        $first = $this->sync->sync_attachment(42, 'new alt', false, 0, 2);

        $this->assertFalse($first['done']);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(2, $first['last_id'], 'Cursor must point at the last processed post.');

        $second = $this->sync->sync_attachment(42, 'new alt', false, $first['last_id'], 2);

        $this->assertSame(2, $second['updated']);
        $this->assertSame(4, $second['last_id']);

        $third = $this->sync->sync_attachment(42, 'new alt', false, $second['last_id'], 2);

        $this->assertTrue($third['done']);
        $this->assertSame(1, $third['updated']);

        $updated_ids = array_column($altrelay_test_state['updated_posts'], 'ID');
        sort($updated_ids);
        $this->assertSame(array(1, 2, 3, 4, 5), $updated_ids, 'Every post must be synced exactly once.');
    }

    /**
     * Test that a fully filtered-out batch does not end the loop early.
     *
     * Regression test: the loop used to decide "is there another page?" from
     * the post-filter count, so a page of 200 rows that were all excluded by
     * altrelay_post_types stopped the scan and later posts were never visited.
     */
    public function test_filtered_batch_does_not_stop_pagination(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        // Page 1: 200 rows, all of type "post" (filtered out).
        $page_one = array_map(static fn(int $i): string => (string) $i, range(1, 200));
        // Page 2: one row of type "page" (allowed).
        $wpdb->stub_col_results_queue = array($page_one, array('500'));

        $types = array();
        for ($i = 1; $i <= 200; $i++) {
            $types[$i] = 'post';
        }
        $types[500] = 'page';

        $altrelay_test_state['post_types_by_id'] = $types;
        $altrelay_test_state['post_fields'][500] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['filters']['altrelay_post_types'] = array(
            static fn(): array => array('page'),
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(2, $wpdb->query_count, 'Both pages should be queried.');
        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(500, $altrelay_test_state['updated_posts'][0]['ID']);
    }

    /**
     * Test that the altrelay_alt_text filter runs once per sync, not per post.
     */
    public function test_alt_text_filter_runs_once_per_sync(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11', '12');

        foreach (array(10, 11, 12) as $id) {
            $altrelay_test_state['post_fields'][$id] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }

        $calls = 0;
        $altrelay_test_state['filters']['altrelay_alt_text'] = array(
            static function (string $alt) use (&$calls): string {
                ++$calls;
                return $alt;
            },
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(3, $altrelay_test_state['updated_posts']);
        $this->assertSame(1, $calls, 'Filter should run once per sync.');
    }

    /**
     * Test that log_sync_event stores a transient.
     */
    public function test_log_sync_event_stores_transient(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="photo.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'updated');

        $this->assertArrayHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
        $log = $altrelay_test_state['transients']['altrelay_last_sync'];
        $this->assertSame(1, $log['post_count']);
    }
    /**
     * Test that kses stays hooked through the save, and the post keeps its
     * markup anyway.
     *
     * Regression test: wp_update_post() runs content_save_pre, which strips
     * markup from the whole post when the user lacks unfiltered_html.
     */
    public function test_kses_stays_hooked_and_the_post_keeps_its_markup(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" /><iframe src="https://example.com/embed"></iframe>',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame('<img src="a.jpg" alt="new alt" class="wp-image-42" /><iframe src="https://example.com/embed"></iframe>', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertEmpty($altrelay_test_state['kses_calls'] ?? array(), 'kses is never unhooked.');
        $this->assertTrue($altrelay_test_state['kses_active']);
        foreach (array('pre_post_title', 'title_save_pre', 'pre_post_content', 'content_save_pre', 'pre_post_excerpt', 'excerpt_save_pre', 'pre_post_content_filtered', 'content_filtered_save_pre') as $hook) {
            $this->assertEmpty($altrelay_test_state['hooks'][$hook] ?? array(), "Nothing is left on $hook.");
        }
    }

    /**
     * Regression (third audit, bugs 1 and 2): filters that run before kses
     * changed the content, so it no longer matched the exempted string, and
     * kses then filtered the whole post. WordPress 7.0 strips block custom
     * CSS at priority 8 for anyone without edit_css; trim() and
     * convert_invalid_entities() change titles and content stored directly.
     */
    public function test_every_save_filter_leaves_the_synced_post_alone(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = true;
        $content = '<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>Hi &#150; there</p><!-- /wp:paragraph -->'
            . '<img src="a.jpg" alt="old" class="wp-image-42" /><iframe src="https://example.com/embed"></iframe>';
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => $content,
            'post_title' => '  E = mc<sup>2</sup>  ',
        );

        // Like WordPress 7.0's custom CSS removal, and core's other filters.
        $strip_css = static fn(string $data): string => (string) preg_replace('/,?\\\\?"css\\\\?":\\\\?"[^"\\\\]*\\\\?"/', '', $data);
        add_filter('content_save_pre', $strip_css, 8);
        add_filter('content_save_pre', static fn(string $data): string => str_replace('&#150;', '&#8211;', $data), 10);
        add_filter('title_save_pre', 'trim', 10);
        add_filter('pre_post_content', static fn(string $data): string => $data . '<!-- pre -->', 10);

        // A post saved by other code during the sync gets every filter.
        $altrelay_test_state['on_save_post'] = static function (): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_save_post']);

            wp_update_post(array('ID' => 99, 'post_content' => wp_slash('<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph --><iframe src="https://evil.example/"></iframe>'), 'post_title' => '  Side  '));
        };

        $this->sync->sync_attachment(42, 'New');

        $saved = $altrelay_test_state['post_fields'][10];
        $this->assertSame(str_replace('alt="old"', 'alt="New"', $content), $saved['post_content'], 'The custom CSS, the entity and the iframe stay.');
        $this->assertSame('  E = mc<sup>2</sup>  ', $saved['post_title']);

        $side = $altrelay_test_state['post_fields'][99];
        $this->assertStringNotContainsString('"css"', $side['post_content']);
        $this->assertStringNotContainsString('<iframe', $side['post_content']);
        $this->assertStringEndsWith('<!-- pre -->', $side['post_content']);
        $this->assertSame('Side', $side['post_title']);
    }

    /**
     * Test that kses is left alone when it was not active.
     */
    public function test_kses_untouched_when_inactive(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = false;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertEmpty($altrelay_test_state['kses_calls'] ?? array());
    }

    /**
     * Test that synced patterns are included in the syncable post types.
     */
    public function test_synced_patterns_are_syncable(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['registered_post_types'] = array('post', 'page', 'attachment', 'wp_block');
        $altrelay_test_state['post_types_by_id'] = array(10 => 'wp_block');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(10, $altrelay_test_state['updated_posts'][0]['ID']);
    }

    /**
     * Test that deleting the alt meta propagates an empty value.
     */
    public function test_deleted_meta_queues_empty_alt(): void
    {
        global $altrelay_test_state;

        $this->sync->on_alt_meta_deleted(array(1), 42, '_wp_attachment_image_alt', 'old');

        $this->assertCount(1, $altrelay_test_state['as_actions']);
        $this->assertSame(array(42, 0, 0), $altrelay_test_state['as_actions'][0]['args']);
    }

    /**
     * Test that deleting unrelated meta does nothing.
     */
    public function test_deleted_meta_ignores_other_keys(): void
    {
        global $altrelay_test_state;

        $this->sync->on_alt_meta_deleted(array(1), 42, '_wp_attachment_metadata', 'x');

        $this->assertEmpty($altrelay_test_state['as_actions'] ?? array());
    }

    /**
     * Test that the hook path queues rather than writing inline.
     */
    public function test_hook_path_enqueues_instead_of_writing(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'new alt');

        $this->assertCount(1, $altrelay_test_state['as_actions']);
        $this->assertCount(0, $altrelay_test_state['updated_posts']);
    }

    /**
     * Test that the should_sync filter can veto queueing.
     */
    public function test_should_sync_filter_vetoes_queueing(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_should_sync'] = array(
            static fn(): bool => false,
        );

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'new alt');

        $this->assertEmpty($altrelay_test_state['as_actions'] ?? array());
    }
    /**
     * Test that a ">" inside a quoted attribute does not truncate the tag.
     *
     * This is a real-HTML-API behaviour that a naive regex cannot handle.
     */
    public function test_rewrite_handles_gt_inside_attribute(): void
    {
        $content = '<img src="a.jpg" alt="a > b" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('alt="new text"', $result);
        $this->assertStringContainsString('class="wp-image-42"', $result);
    }

    /**
     * Test that markup after the image is left byte-for-byte intact.
     */
    public function test_rewrite_preserves_surrounding_markup(): void
    {
        $content = '<div class="wrap"><p>Before</p>'
            . '<img src="a.jpg" alt="old" class="wp-image-42" />'
            . '<iframe src="https://example.com/embed"></iframe>'
            . '<p>After</p></div>';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('<iframe src="https://example.com/embed"></iframe>', $result);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringContainsString('alt="new text"', $result);
    }

    /**
     * Test that an image inside a figure with a caption is rewritten.
     */
    public function test_rewrite_handles_figure_markup(): void
    {
        $content = '<figure class="wp-block-image"><img src="a.jpg" alt="old" class="wp-image-42" />'
            . '<figcaption>Caption</figcaption></figure>';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('alt="new text"', $result);
        $this->assertStringContainsString('<figcaption>Caption</figcaption>', $result);
    }

    /**
     * Test that an unclosed img tag does not swallow the rest of the document.
     */
    public function test_rewrite_handles_unclosed_img(): void
    {
        $content = '<img src="a.jpg" alt="old" class="wp-image-42"><p>After</p>';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'new text');

        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringContainsString('alt="new text"', $result);
    }

    /**
     * Test that an entity-encoded alt is decoded and re-encoded correctly.
     */
    public function test_rewrite_handles_entities(): void
    {
        $content = '<img src="a.jpg" alt="Tom &amp; Jerry" class="wp-image-42" />';

        $result = AltRelay_Content::rewrite_attachment_alt($content, 42, 'Tom & Jerry');

        $this->assertStringContainsString('alt="Tom &amp; Jerry"', $result);
    }

    /**
     * Test that a queued job only touches posts the acting user can edit.
     *
     * Regression test: the background worker runs with no logged-in user, so
     * an Author's change used to rewrite an Administrator's posts.
     */
    public function test_worker_checks_acting_user_per_post(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $altrelay_test_state['user_logged_in'] = false;
        $altrelay_test_state['user_can_map'][7]['edit_post'] = array(10 => true, 11 => false);
        foreach (array(10, 11) as $id) {
            $altrelay_test_state['post_fields'][$id] = array(
                'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            );
        }

        $result = $this->sync->sync_attachment(42, 'new alt', false, 0, 0, 7);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(array(10), $result['post_ids']);
        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(10, $altrelay_test_state['updated_posts'][0]['ID']);
    }

    /**
     * Test that the hook path records who made the change.
     */
    public function test_hook_path_queues_the_current_user(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['user_logged_in'] = true;
        $altrelay_test_state['current_user_id'] = 7;
        $altrelay_test_state['as_actions'] = array();

        $this->sync->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'new alt');

        $this->assertSame(array(42, 0, 7), $altrelay_test_state['as_actions'][0]['args']);
    }

    /**
     * Test that the search query filters by post type and status in SQL and
     * uses a LIKE prefilter before the REGEXP.
     */
    public function test_find_query_filters_types_in_sql(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();
        $altrelay_test_state['post_types'] = array('post' => 'post', 'page' => 'page');

        $this->sync->sync_attachment(42, 'alt');

        $this->assertStringContainsString("post_content LIKE '%wp-image-42%'", $wpdb->last_query);
        $this->assertStringContainsString("post_type IN ('post','page')", $wpdb->last_query);
        $this->assertStringContainsString("post_status IN ('publish','draft','pending','private','future')", $wpdb->last_query);
    }

    /**
     * Test that a successful sync fires altrelay_post_synced.
     */
    public function test_post_synced_action_fires(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['actions'] = array();
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $fired = array_values(array_filter(
            $altrelay_test_state['actions'],
            static fn(array $action): bool => 'altrelay_post_synced' === $action['hook']
        ));

        $this->assertCount(1, $fired);
        $this->assertSame(array(10, 42, 'new alt'), $fired[0]['args']);
    }

    /**
     * Test that a dry run reports the posts it would change without saving.
     */
    public function test_dry_run_reports_post_ids(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $result = $this->sync->sync_attachment(42, 'new alt', true);

        $this->assertSame(array(10), $result['post_ids']);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that fill_post writes the library alt into one post, even on sites
     * that treat empty alt as decorative, because the user asked for it.
     */
    public function test_fill_post_fills_empty_alt(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['filters']['altrelay_treat_empty_alt_as_decorative'] = array(
            static fn(): bool => true,
        );
        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Library alt';
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />',
        );

        $this->assertTrue($this->sync->fill_post(10, 42));
        $this->assertStringContainsString('alt="Library alt"', $altrelay_test_state['post_fields'][10]['post_content']);
    }

    /**
     * Regression (seventh audit): fill, mark and unmark in one post checked
     * that the user could edit it, but not that AltRelay syncs its type and
     * status, so a crafted request could change a revision.
     */
    public function test_per_post_actions_only_reach_posts_altrelay_syncs(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42]['_wp_attachment_image_alt'] = 'Library alt';
        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />');

        $altrelay_test_state['post_types_by_id'][10] = 'revision';
        $this->assertFalse($this->sync->fill_post(10, 42), 'A revision.');
        $this->assertFalse($this->sync->mark_post_decorative(10, 42)['changed']);

        $altrelay_test_state['post_types_by_id'][10] = 'post';
        $altrelay_test_state['post_statuses'][10] = 'trash';
        $this->assertFalse($this->sync->fill_post(10, 42), 'A status AltRelay does not sync.');

        $this->assertEmpty($altrelay_test_state['updated_posts'] ?? array());

        $altrelay_test_state['post_statuses'][10] = 'draft';
        $this->assertTrue($this->sync->fill_post(10, 42));
    }

    /**
     * Regression (seventh audit): saving a post read the decorative record
     * of each of its images with a query of its own.
     */
    public function test_a_saved_post_reads_its_images_meta_at_once(): void
    {
        global $altrelay_test_state;

        $post = new WP_Post(array('ID' => 10, 'post_type' => 'post', 'post_content' => '<img class="wp-image-42" /><img class="wp-image-43" /><img class="wp-image-42" />'));

        $this->sync->on_post_saved(10, $post);

        $this->assertCount(1, $altrelay_test_state['meta_cache_primed']);
        $this->assertSame(array(42, 43), $altrelay_test_state['meta_cache_primed'][0]['ids']);
    }

    /**
     * Test that fill_post does nothing when the library has no alt.
     */
    public function test_fill_post_without_library_alt(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />',
        );

        $this->assertFalse($this->sync->fill_post(10, 42));
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that mark_post_decorative marks and saves the post, through the
     * same kses-safe save as the sync.
     */
    public function test_mark_post_decorative_saves(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['kses_active'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="Something" class="wp-image-42" />',
        );

        $this->assertSame(array('changed' => true, 'refused' => 0), $this->sync->mark_post_decorative(10, 42));

        $saved = $altrelay_test_state['post_fields'][10]['post_content'];
        $this->assertStringContainsString('altrelay-decorative', $saved);
        $this->assertStringContainsString('alt=""', $saved);
        $this->assertEmpty($altrelay_test_state['kses_calls'] ?? array(), 'kses stays hooked.');
    }

    /**
     * Test that mark_post_decorative reports no change for an empty post.
     */
    public function test_mark_post_decorative_on_empty_post(): void
    {
        $this->assertSame(array('changed' => false, 'refused' => 0), $this->sync->mark_post_decorative(10, 42));
    }

    /**
     * Test that deleting an attachment does not queue a sitewide clear.
     *
     * Regression test: wp_delete_attachment() removes the alt meta, which was
     * treated as "alt cleared" and queued a rewrite for a deleted image.
     */
    public function test_deleting_an_attachment_queues_nothing(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['as_actions'] = array();

        $this->sync->on_attachment_deleting(42);
        $this->sync->on_alt_meta_deleted(array(5), 42, '_wp_attachment_image_alt', 'Old');

        $this->assertEmpty($altrelay_test_state['as_actions']);

        // Clearing alt on another attachment still syncs.
        $this->sync->on_alt_meta_deleted(array(6), 43, '_wp_attachment_image_alt', 'Old');

        $this->assertCount(1, $altrelay_test_state['as_actions']);
    }

    /**
     * Regression (audit bug 3): post content is read in the 'raw' context.
     * The default context runs it through display filters, and whatever
     * another plugin added there would be written to the database.
     */
    public function test_content_is_read_raw_so_display_filters_are_never_saved(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['display_filter'] = '<p>Added by a display filter</p>';
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'new alt');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');
        $this->sync->fill_post(10, 42);
        $this->sync->mark_post_decorative(10, 43);
        $this->sync->unmark_post_decorative(10, 43);

        $this->assertSame(
            '<img src="a.jpg" alt="new alt" class="wp-image-42" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
        $this->assertSame(array('raw'), array_values(array_unique($altrelay_test_state['post_field_contexts'])));
    }

    /**
     * Test that the kses exemption covers the synced content and nothing else.
     *
     * Regression (audit, Security): the kses filters used to be switched off
     * for the whole save, so anything a save_post callback saved meanwhile
     * went in unfiltered.
     */
    public function test_kses_exemption_covers_only_the_synced_content(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" /><iframe src="https://example.com/embed"></iframe>',
        );

        // Another plugin saves a post of its own while ours is being saved.
        $altrelay_test_state['on_save_post'] = static function (): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_save_post']);

            wp_update_post(
                array(
                    'ID' => 99,
                    'post_content' => wp_slash('<p>ok</p><iframe src="https://evil.example/"></iframe>'),
                )
            );
        };

        $this->sync->sync_attachment(42, 'It\'s "new"');

        $this->assertSame(
            '<img src="a.jpg" alt="It&#039;s &quot;new&quot;" class="wp-image-42" /><iframe src="https://example.com/embed"></iframe>',
            $altrelay_test_state['post_fields'][10]['post_content'],
            'The synced post must keep its markup.'
        );
        $this->assertSame(
            '<p>ok</p>',
            $altrelay_test_state['post_fields'][99]['post_content'],
            'A post saved by other code during the sync must still be filtered.'
        );
        $this->assertTrue($altrelay_test_state['kses_active']);
        $this->assertEmpty($altrelay_test_state['hooks']['content_save_pre'] ?? array(), 'The guard must not stay hooked.');
    }

    /**
     * Test that the title, excerpt and filtered content keep their markup.
     *
     * Regression (second audit, bug 1): only the content was exempt, so a
     * background sync ran the stored title and excerpt through kses and
     * "E = mc<sup>2</sup>" became "E = mc2".
     */
    public function test_kses_exemption_covers_every_field_of_the_synced_post(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
            'post_title' => 'E = mc<sup>2</sup> and H<sub>2</sub>O',
            'post_excerpt' => 'Watch: <iframe src="https://example.com/embed"></iframe>',
            'post_content_filtered' => '<p>cached</p><iframe src="https://example.com/embed"></iframe>',
        );

        // Another plugin saves a post of its own, title and excerpt included.
        $altrelay_test_state['on_save_post'] = static function (): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_save_post']);

            wp_update_post(
                array(
                    'ID' => 99,
                    'post_content' => '<p>ok</p>',
                    'post_title' => wp_slash('Side <script>alert(1)</script>post'),
                    'post_excerpt' => wp_slash('<iframe src="https://evil.example/"></iframe>'),
                )
            );
        };

        $this->sync->sync_attachment(42, 'New');

        $fields = $altrelay_test_state['post_fields'][10];

        $this->assertSame('<img src="a.jpg" alt="New" class="wp-image-42" />', $fields['post_content']);
        $this->assertSame('E = mc<sup>2</sup> and H<sub>2</sub>O', $fields['post_title']);
        $this->assertSame('Watch: <iframe src="https://example.com/embed"></iframe>', $fields['post_excerpt']);
        $this->assertSame('<p>cached</p><iframe src="https://example.com/embed"></iframe>', $fields['post_content_filtered']);

        $this->assertSame('Side alert(1)post', $altrelay_test_state['post_fields'][99]['post_title'], 'Other saves keep the title filter.');
        $this->assertSame('', $altrelay_test_state['post_fields'][99]['post_excerpt'], 'Other saves keep the excerpt filter.');

        // Every filter is back in place, and no guard is left behind.
        $this->assertSame(10, has_filter('title_save_pre', 'wp_filter_kses'));
        $this->assertSame(10, has_filter('excerpt_save_pre', 'wp_filter_post_kses'));
        $this->assertSame(10, has_filter('content_filtered_save_pre', 'wp_filter_post_kses'));
        $this->assertEmpty($altrelay_test_state['hooks']['title_save_pre'] ?? array());
        $this->assertEmpty($altrelay_test_state['hooks']['excerpt_save_pre'] ?? array());
        $this->assertEmpty($altrelay_test_state['hooks']['content_filtered_save_pre'] ?? array());
    }

    /**
     * Test that kses is hooked again when the save throws, and that the
     * post is recorded as failed instead of ending the run.
     */
    public function test_kses_is_restored_when_the_save_throws(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['kses_active'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['on_before_save'] = static function (): void {
            throw new \RuntimeException('A filter failed while the post was saved.');
        };

        $this->assertSame(1, $this->sync->sync_attachment(42, 'new alt')['failed']);
        $this->assertSame('A filter failed while the post was saved.', $altrelay_test_state['transients']['altrelay_sync_errors']['message']);

        $this->assertTrue($altrelay_test_state['kses_active']);
        $this->assertEmpty($altrelay_test_state['hooks']['content_save_pre'] ?? array());

        // The recursion guard is released too, so the next sync works.
        unset($altrelay_test_state['on_before_save']);
        $this->assertSame(1, $this->sync->sync_attachment(42, 'again')['updated']);
    }

    /**
     * Regression (fifth audit): a post whose save_post hook threw was
     * counted as failed, and offered to run again, although its new content
     * had been saved. It counts as updated, and the error is not AltRelay's
     * to report.
     */
    public function test_a_post_saved_before_a_hook_threw_counts_as_updated(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['on_save_post'] = static function (): void {
            throw new \RuntimeException('A save_post callback failed.');
        };

        $report = $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['failed']);
        $this->assertStringContainsString('alt="new alt"', $altrelay_test_state['post_fields'][10]['post_content']);
        $this->assertArrayNotHasKey('altrelay_sync_errors', $altrelay_test_state['transients'] ?? array());
        $this->assertSame(array(), AltRelay_Sync::images_to_retry());
    }

    /**
     * Test that a sync moves the modified date by default.
     */
    public function test_sync_updates_the_modified_date_by_default(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame('2030-01-01 00:00:00', $altrelay_test_state['updated_posts'][0]['post_modified']);
    }

    /**
     * Test that altrelay_update_post_modified can keep the modified date.
     */
    public function test_filter_keeps_the_modified_date(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $asked_for = null;
        $altrelay_test_state['filters']['altrelay_update_post_modified'] = array(
            static function (bool $update, int $post_id) use (&$asked_for): bool {
                $asked_for = $post_id;
                return false;
            },
        );
        $altrelay_test_state['posts'][10] = (object) array(
            'ID' => 10,
            'post_modified' => '2020-01-02 10:00:00',
            'post_modified_gmt' => '2020-01-02 09:00:00',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(10, $asked_for);
        $this->assertSame('2020-01-02 10:00:00', $altrelay_test_state['updated_posts'][0]['post_modified']);
        $this->assertEmpty($altrelay_test_state['hooks']['wp_insert_post_data'] ?? array(), 'The date filter must not stay hooked.');
    }

    /**
     * Test that keeping the date only applies to the post being synced.
     */
    public function test_kept_modified_date_does_not_leak_into_other_saves(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['filters']['altrelay_update_post_modified'] = array(static fn(): bool => false);
        $altrelay_test_state['posts'][10] = (object) array(
            'ID' => 10,
            'post_modified' => '2020-01-02 10:00:00',
            'post_modified_gmt' => '2020-01-02 09:00:00',
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['on_save_post'] = static function (): void {
            global $altrelay_test_state;
            unset($altrelay_test_state['on_save_post']);
            wp_update_post(array('ID' => 99, 'post_content' => 'other'));
        };

        $this->sync->sync_attachment(42, 'new alt');

        $by_id = array_column($altrelay_test_state['updated_posts'], 'post_modified', 'ID');

        $this->assertSame('2020-01-02 10:00:00', $by_id[10]);
        $this->assertSame('2030-01-01 00:00:00', $by_id[99]);
    }

    /**
     * Test that a post that cannot be loaded simply gets a new date.
     */
    public function test_keeping_the_date_of_a_missing_post_is_harmless(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['filters']['altrelay_update_post_modified'] = array(static fn(): bool => false);
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->assertSame(1, $this->sync->sync_attachment(42, 'new alt')['updated']);
    }

    /**
     * Test that a post that cannot be saved is counted, recorded and announced.
     *
     * Regression (audit, Sync engine): failures used to end silently.
     */
    public function test_failed_save_is_counted_and_recorded(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $altrelay_test_state['fail_update_post'] = true;
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );
        $altrelay_test_state['post_fields'][11] = array(
            'post_content' => '<img src="b.jpg" alt="old" class="wp-image-42" />',
        );

        $result = $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(array(), $result['post_ids']);

        $errors = $altrelay_test_state['transients']['altrelay_sync_errors'];

        $this->assertSame(2, $errors['count']);
        $this->assertSame(11, $errors['post_id']);
        $this->assertSame('Update failed.', $errors['message']);

        $failed = array_values(array_filter($altrelay_test_state['actions'], static fn(array $a): bool => 'altrelay_sync_failed' === $a['hook']));

        $this->assertCount(2, $failed);
        $this->assertSame(array(10, 'Update failed.'), $failed[0]['args']);

        // Nothing was updated, so nothing is logged as synced.
        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
    }

    /**
     * Test that a failed fill is recorded as well.
     */
    public function test_failed_fill_is_recorded(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['fail_update_post'] = true;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />',
        );

        $this->assertFalse($this->sync->fill_post(10, 42));
        $this->assertSame(10, $altrelay_test_state['transients']['altrelay_sync_errors']['post_id']);
    }

    /**
     * Test that a database error ends the run and is recorded.
     */
    public function test_database_error_is_recorded(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_error = 'Table wp_posts is marked as crashed';

        $result = $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(1, $result['failed']);
        $this->assertTrue($result['done']);
        $this->assertSame(1, $wpdb->query_count);

        $errors = $altrelay_test_state['transients']['altrelay_sync_errors'];

        $this->assertSame(0, $errors['post_id']);
        $this->assertSame('Table wp_posts is marked as crashed', $errors['message']);
    }

    /**
     * Regression (audit bug 9): runs that follow each other add up, so
     * "Last sync" does not only show the final batch.
     */
    public function test_last_sync_adds_up_recent_runs(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'post_count' => 5,
            'time' => time() - 30,
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(6, $altrelay_test_state['transients']['altrelay_last_sync']['post_count']);
    }

    /**
     * Test that an old run is not added to a new one.
     */
    public function test_last_sync_starts_over_after_a_pause(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['transients']['altrelay_last_sync'] = array(
            'post_count' => 5,
            'time' => time() - 3600,
        );
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertSame(1, $altrelay_test_state['transients']['altrelay_last_sync']['post_count']);
    }

    /**
     * Test that the recursion guard is shared between instances.
     *
     * The queue worker and WP-CLI sync through their own instance while
     * another one listens for alt text changes.
     */
    public function test_recursion_guard_is_shared_between_instances(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['as_actions'] = array();
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $listener = new AltRelay_Sync();
        $nested = null;

        // A plugin that copies alt text into the library while a post is saved.
        $altrelay_test_state['on_save_post'] = static function () use ($listener, &$nested): void {
            $listener->on_alt_meta_changed(1, 42, '_wp_attachment_image_alt', 'echo');
            $nested = $listener->sync_attachment(42, 'echo');
        };

        $this->sync->sync_attachment(42, 'new alt');

        $this->assertEmpty($altrelay_test_state['as_actions'], 'A change made during a sync must not queue another one.');
        $this->assertSame(0, $nested['updated']);
        $this->assertFalse($listener->fill_post(10, 42));
    }

    /**
     * Test stored_alt() and library_alt().
     */
    public function test_stored_and_library_alt(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => '  <b>Dog</b> on a beach ');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => array('not a string'));
        $altrelay_test_state['filters']['altrelay_alt_text'] = array(
            static fn(string $alt, int $id): string => $alt . ' (#' . $id . ')',
        );

        $this->assertSame('Dog on a beach', AltRelay_Sync::stored_alt(42));
        $this->assertSame('Dog on a beach (#42)', AltRelay_Sync::library_alt(42));
        $this->assertSame('', AltRelay_Sync::stored_alt(43));
        $this->assertSame('', AltRelay_Sync::library_alt(44), 'An image without alt text is not passed to the filter.');
    }

    /**
     * Test that sync_posts() writes every image of a post in one save.
     *
     * Regression (audit, WP-CLI): syncing image by image saved a post once
     * per image.
     */
    public function test_sync_posts_saves_each_post_once(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => 'Cat');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />'
                . '<img src="b.jpg" alt="" class="wp-image-43" />'
                . '<img src="c.jpg" alt="kept" class="wp-image-44" />'
                . '<img src="d.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
        );

        $result = $this->sync->sync_posts();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(array(10), $result['post_ids']);
        $this->assertCount(1, $altrelay_test_state['updated_posts']);
        $this->assertSame(
            '<img src="a.jpg" alt="Dog" class="wp-image-42" />'
                . '<img src="b.jpg" alt="Cat" class="wp-image-43" />'
                . '<img src="c.jpg" alt="kept" class="wp-image-44" />'
                . '<img src="d.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );

        // The meta of all three images was loaded with one query.
        $this->assertSame(array(array('type' => 'post', 'ids' => array(42, 43, 44))), $altrelay_test_state['meta_cache_primed']);

        // One action per image that changed.
        $synced = array_values(array_filter($altrelay_test_state['actions'], static fn(array $a): bool => 'altrelay_post_synced' === $a['hook']));

        $this->assertSame(array(array(10, 42, 'Dog'), array(10, 43, 'Cat')), array_column($synced, 'args'));
        $this->assertStringContainsString("post_content LIKE '%wp-image-%'", $wpdb->last_query);
        $this->assertStringNotContainsString('REGEXP', $wpdb->last_query);
    }

    /**
     * Test that sync_posts() leaves a post alone that is already in line.
     */
    public function test_sync_posts_skips_posts_in_sync(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<p>No images, only a mention of wp-image-42.</p><img src="a.jpg" alt="Dog" class="wp-image-42" />',
        );

        $result = $this->sync->sync_posts();

        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that a dry run of sync_posts() writes nothing.
     */
    public function test_sync_posts_dry_run(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10');

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />',
        );

        $result = $this->sync->sync_posts(true);

        $this->assertSame(array(10), $result['post_ids']);
        $this->assertEmpty($altrelay_test_state['updated_posts']);
        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
    }

    /**
     * Test that both sync entry points can be limited to post types, and
     * that a caller can only narrow the set AltRelay syncs.
     */
    public function test_post_types_can_be_narrowed_but_not_widened(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();

        $this->sync->sync_posts(false, 0, 0, 0, array('page'));
        $this->assertStringContainsString("post_type IN ('page')", $wpdb->last_query);

        $this->sync->sync_attachment(42, 'alt', false, 0, 0, 0, array('page', 'attachment', 'nav_menu_item'));
        $this->assertStringContainsString("post_type IN ('page')", $wpdb->last_query);

        $queries = $wpdb->query_count;

        $this->assertTrue($this->sync->sync_posts(false, 7, 0, 0, array('nav_menu_item'))['done']);
        $this->assertSame(7, $this->sync->sync_attachment(42, 'alt', false, 7, 0, 0, array('nav_menu_item'))['last_id']);
        $this->assertSame($queries, $wpdb->query_count, 'A type AltRelay does not sync must not be searched.');
    }

    /**
     * Test that unmarking removes the mark and brings the library alt back.
     */
    public function test_unmark_post_decorative_restores_library_alt(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
        );

        $this->assertTrue($this->sync->unmark_post_decorative(10, 42));
        $this->assertSame(
            '<img src="a.jpg" alt="Dog" class="wp-image-42" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
    }

    /**
     * Test that unmarking works for an image without alt text in the library.
     */
    public function test_unmark_post_decorative_without_library_alt(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" />',
        );

        $this->assertTrue($this->sync->unmark_post_decorative(10, 42));
        $this->assertSame(
            '<img src="a.jpg" alt="" class="wp-image-42" />',
            $altrelay_test_state['post_fields'][10]['post_content']
        );
    }

    /**
     * Test that unmarking an image that is not marked changes nothing, and
     * in particular does not fill it.
     */
    public function test_unmark_post_decorative_leaves_unmarked_images_alone(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Dog');
        $altrelay_test_state['post_fields'][10] = array(
            'post_content' => '<img src="a.jpg" alt="" class="wp-image-42" />',
        );

        $this->assertFalse($this->sync->unmark_post_decorative(10, 42));
        $this->assertEmpty($altrelay_test_state['updated_posts']);
    }

    /**
     * Test that a linked image is reported as refused.
     */
    public function test_mark_post_decorative_reports_refused_links(): void
    {
        global $altrelay_test_state;

        $content = '<a href="https://example.com/"><img src="a.jpg" alt="Dog" class="wp-image-42" /></a>';

        $altrelay_test_state['post_fields'][10] = array('post_content' => $content);

        $this->assertSame(array('changed' => false, 'refused' => 1), $this->sync->mark_post_decorative(10, 42));
        $this->assertSame($content, $altrelay_test_state['post_fields'][10]['post_content']);
    }


    /**
     * Regression (second audit, bug 5): the posts a user could not edit were
     * skipped, and nothing recorded that they still had the old alt text.
     */
    public function test_skipped_posts_are_recorded_with_their_image(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11');

        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        $altrelay_test_state['user_can_map'][3]['edit_post'][11] = false;

        $this->sync->sync_attachment(42, 'New', false, 0, 0, 3);

        $last = AltRelay_Sync::last_sync();

        $this->assertSame(1, $last['post_count']);
        $this->assertSame(1, $last['skipped_count']);
        $this->assertSame(array(42), $last['skipped_attachments']);

        // A dry run records nothing, and a malformed record reads as none.
        $altrelay_test_state['transients'] = array();
        $this->sync->sync_attachment(42, 'Newer', true, 0, 0, 3);
        $this->assertNull(AltRelay_Sync::last_sync());

        $altrelay_test_state['transients']['altrelay_last_sync'] = array('time' => 'soon', 'skipped_attachments' => array(5, 'x', -1));
        $this->assertNull(AltRelay_Sync::last_sync());

        $altrelay_test_state['transients']['altrelay_last_sync'] = array('time' => 1, 'skipped_attachments' => array(5, 'x', -1));
        $this->assertSame(array(5), AltRelay_Sync::last_sync()['skipped_attachments']);

        AltRelay_Sync::forget_skipped();
        $this->assertSame(array(), AltRelay_Sync::last_sync()['skipped_attachments']);

        $altrelay_test_state['transients'] = array();
        AltRelay_Sync::forget_skipped();
        $this->assertArrayNotHasKey('altrelay_last_sync', $altrelay_test_state['transients']);
    }

    /**
     * Regression (second audit, Sync engine): custom post statuses could
     * not be synced.
     */
    public function test_post_statuses_are_filterable(): void
    {
        global $altrelay_test_state, $wpdb;

        $this->assertSame(array('publish', 'draft', 'pending', 'private', 'future'), AltRelay_Sync::statuses());

        $altrelay_test_state['filters']['altrelay_post_statuses'] = array(
            static fn(array $statuses): array => array_merge($statuses, array('in-review', 'trash', 'auto-draft', 'inherit', '', 5, 'in-review')),
        );

        $this->assertSame(array('publish', 'draft', 'pending', 'private', 'future', 'in-review'), AltRelay_Sync::statuses());

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array();
        $this->sync->sync_attachment(42, 'New');

        $this->assertStringContainsString("post_status IN ('publish','draft','pending','private','future','in-review')", $wpdb->last_query);

        // Nothing usable falls back to the defaults.
        $altrelay_test_state['filters']['altrelay_post_statuses'] = array(static fn(): string => 'nonsense');
        $this->assertSame(AltRelay_Sync::STATUSES, AltRelay_Sync::statuses());
    }

    /**
     * Regression (third audit, bug 5): a post counted as skipped before
     * AltRelay checked whether it would change, and repeated changes added
     * the same posts up again.
     */
    public function test_skipped_posts_are_counted_once_and_only_when_they_would_change(): void
    {
        global $altrelay_test_state, $wpdb;

        $wpdb = new wpdb();
        $wpdb->stub_col_results = array('10', '11', '12');

        $altrelay_test_state['post_fields'][10] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        $altrelay_test_state['post_fields'][11] = array('post_content' => '<img src="a.jpg" alt="old" class="wp-image-42" />');
        // Already up to date: nothing to skip.
        $altrelay_test_state['post_fields'][12] = array('post_content' => '<img src="a.jpg" alt="New" class="wp-image-42" />');
        $altrelay_test_state['user_can_map'][3]['edit_post'] = array(11 => false, 12 => false);

        $this->sync->sync_attachment(42, 'New', false, 0, 0, 3);
        $this->sync->sync_attachment(42, 'New', false, 0, 0, 3);

        $last = AltRelay_Sync::last_sync();
        $this->assertSame(array(11), $last['skipped_posts']);
        $this->assertSame(1, $last['skipped_count'], 'Skipped twice, counted once.');

        // A later sync by someone who may edit it brings the post up to date.
        $this->sync->sync_attachment(42, 'New', false, 0, 0, 0);

        $last = AltRelay_Sync::last_sync();
        $this->assertSame(0, $last['skipped_count']);
        $this->assertSame(array(), $last['skipped_attachments']);
    }
}
