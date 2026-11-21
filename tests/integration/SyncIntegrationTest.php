<?php
/**
 * Integration tests against a real WordPress install.
 *
 * These cover the paths that unit tests cannot: the real kses filter chain,
 * real post revisions, real SQL on a real database, core's own block parser
 * and serializer, and real Action Scheduler behaviour.
 *
 * @package AltRelay
 */

/**
 * Class SyncIntegrationTest
 */
class SyncIntegrationTest extends WP_UnitTestCase {


	/**
	 * Attachment ID used by the tests.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Create an attachment with a file, registered sizes and alt text.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->attachment_id = $this->create_image( 'test', 'Original alt' );

		// Setting the alt text queued a job; every test starts with an empty queue.
		AltRelay_Queue::cancel_all();
		delete_transient( AltRelay_Sync::LAST_SYNC_TRANSIENT );
		delete_transient( AltRelay_Sync::ERRORS_TRANSIENT );
	}

	/**
	 * Leave no queued work behind.
	 */
	public function tear_down(): void {
		remove_all_filters( 'altrelay_use_action_scheduler' );
		AltRelay_Queue::cancel_all();

		parent::tear_down();
	}

	/**
	 * Create an image attachment the way an upload leaves it: a file in a
	 * month folder, and metadata that lists a generated size.
	 *
	 * @param string $name File name without extension.
	 * @param string $alt  Alt text, or '' for none.
	 * @return int
	 */
	private function create_image( string $name, string $alt = '' ): int {
		$id = self::factory()->attachment->create_object(
			"2026/11/$name.jpg",
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);

		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 1200,
				'height' => 800,
				'file'   => "2026/11/$name.jpg",
				'sizes'  => array(
					'large' => array(
						'file'      => "$name-1024x683.jpg",
						'width'     => 1024,
						'height'    => 683,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * URL of a test image.
	 *
	 * @param string $name   File name without extension.
	 * @param string $suffix Size suffix, such as '-1024x683'.
	 * @return string
	 */
	private function url( string $name = 'test', string $suffix = '-1024x683' ): string {
		return wp_get_upload_dir()['baseurl'] . "/2026/11/$name$suffix.jpg";
	}

	/**
	 * A classic image tag of the test attachment.
	 *
	 * @param string|null $alt Alt text, or null for no alt attribute.
	 * @return string
	 */
	private function image_tag( ?string $alt ): string {
		return sprintf(
			'<img src="%s"%s class="wp-image-%d" />',
			$this->url(),
			null === $alt ? '' : ' alt="' . esc_attr( $alt ) . '"',
			$this->attachment_id
		);
	}

	/**
	 * Core Image block markup, as the editor saves it.
	 *
	 * @param string $alt        The alt in the markup.
	 * @param string $class_name Extra block class, or ''.
	 * @param string $before     Markup before the image, inside the figure.
	 * @param string $after      Markup after the image, inside the figure.
	 * @return string
	 */
	private function image_block( string $alt, string $class_name = '', string $before = '', string $after = '' ): string {
		$attrs   = '' === $class_name ? sprintf( '{"id":%d}', $this->attachment_id ) : sprintf( '{"id":%d,"className":"%s"}', $this->attachment_id, $class_name );
		$wrapper = trim( 'wp-block-image ' . $class_name );

		return sprintf(
			'<!-- wp:image %1$s --><figure class="%2$s">%6$s<img src="%5$s" alt="%3$s" class="wp-image-%4$d"/>%7$s</figure><!-- /wp:image -->',
			$attrs,
			$wrapper,
			esc_attr( $alt ),
			$this->attachment_id,
			$this->url(),
			$before,
			$after
		);
	}

	/**
	 * A post's content exactly as stored.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function content( int $post_id ): string {
		return (string) get_post_field( 'post_content', $post_id, 'raw' );
	}

	/**
	 * Store content without kses touching it, like an administrator would.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Extra post fields.
	 * @return int
	 */
	private function create_post( string $content, array $args = array() ): int {
		kses_remove_filters();
		$post_id = self::factory()->post->create( array_merge( array( 'post_content' => $content ), $args ) );
		kses_init();

		return $post_id;
	}

	/**
	 * Arguments of every job waiting in Action Scheduler.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function waiting_jobs(): array {
		$jobs    = array();
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => AltRelay_Queue::ACTION_HOOK,
				'group'    => AltRelay_Queue::GROUP,
				'status'   => 'pending',
				'per_page' => 500,
			)
		);

		foreach ( $actions as $action ) {
			$jobs[] = $action->get_args();
		}

		return $jobs;
	}

	/**
	 * Test that syncing rewrites the alt attribute in a post.
	 */
	public function test_sync_rewrites_alt_in_post(): void {
		$post_id = $this->create_post( $this->image_tag( 'old' ) );

		$sync   = new AltRelay_Sync();
		$result = $sync->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertStringContainsString( 'alt="New alt"', $this->content( $post_id ) );
	}

	/**
	 * Test that the rest of the post survives the save.
	 *
	 * This is the kses regression: without the exemption, the iframe would
	 * be stripped because the test user lacks unfiltered_html. The markup is
	 * stored with kses disabled, because kses would otherwise strip it on
	 * insert and the test would pass without proving anything.
	 */
	public function test_sync_preserves_markup_outside_the_image(): void {
		$post_id = $this->create_post( '<iframe src="https://example.com/embed"></iframe>' . $this->image_tag( 'old' ) );

		$this->assertStringContainsString(
			'<iframe',
			$this->content( $post_id ),
			'The fixture must store the iframe, otherwise this test proves nothing.'
		);

		// Confirm kses is armed, so the sync really has to get past it.
		$this->assertNotFalse(
			has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
			'kses must be active for this test to be meaningful.'
		);

		$sync = new AltRelay_Sync();
		$sync->sync_attachment( $this->attachment_id, 'New alt' );

		$saved = $this->content( $post_id );

		$this->assertStringContainsString( '<iframe', $saved );
		$this->assertStringContainsString( 'alt="New alt"', $saved );
		$this->assertNotFalse(
			has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
			'kses must be hooked again after the sync.'
		);
	}

	/**
	 * Test that the kses exemption covers the synced post and nothing else.
	 *
	 * Regression test (external audit, Security): the kses filters used to be
	 * removed for the whole save, so a post that another plugin saved from a
	 * save_post callback went in unfiltered. The revision core writes during
	 * the same save must keep the markup too, or restoring it would strip
	 * the post after all.
	 */
	public function test_kses_exemption_does_not_cover_other_saves(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$post_id = $this->create_post(
			'<iframe src="https://example.com/embed"></iframe>' . $this->image_tag( 'It\'s "old" & <odd>' ),
			array( 'post_author' => $author )
		);

		$side_post = 0;
		$on_save   = static function ( $saved_id ) use ( &$side_post, $post_id, $author ): void {
			if ( (int) $saved_id !== $post_id || 0 !== $side_post ) {
				return;
			}

			$side_post = (int) wp_insert_post(
				array(
					'post_status'  => 'draft',
					'post_author'  => $author,
					'post_title'   => 'Saved by another plugin',
					'post_content' => '<p>ok</p><iframe src="https://evil.example/"></iframe><script>alert(1)</script>',
				)
			);
		};

		add_action( 'save_post', $on_save );
		( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'It\'s "new" & <odd>' );
		remove_action( 'save_post', $on_save );

		$saved = $this->content( $post_id );

		$this->assertStringContainsString( '<iframe src="https://example.com/embed"></iframe>', $saved );
		// How the apostrophe is escaped (&#039; or &apos;) is WordPress's choice
		// and changed between versions; what the attribute decodes to is ours.
		$tags = new WP_HTML_Tag_Processor( $saved );
		$this->assertTrue( $tags->next_tag( 'img' ) );
		$this->assertSame( 'It\'s "new" & <odd>', $tags->get_attribute( 'alt' ) );
		$this->assertStringNotContainsString( '<odd>', $saved );

		$this->assertGreaterThan( 0, $side_post, 'The save_post callback must have run.' );
		$this->assertStringNotContainsString( '<iframe', $this->content( $side_post ) );
		$this->assertStringNotContainsString( '<script', $this->content( $side_post ) );

		$revisions = wp_get_post_revisions( $post_id );
		$latest    = reset( $revisions );

		$this->assertNotFalse( $latest, 'The save must have created a revision.' );
		$this->assertSame( $saved, $latest->post_content, 'The revision must hold exactly what the post holds.' );

		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/**
	 * Test that a sync without a user keeps the markup in every field.
	 *
	 * Regression (second audit, bug 1): wp_update_post() saves the title,
	 * the excerpt and the filtered content again, and kses_init_filters()
	 * hooks kses to all of them. Only the content was exempt, so a sync from
	 * cron, Action Scheduler or WP-CLI without --user turned
	 * "E = mc<sup>2</sup>" into "E = mc2" and dropped embeds from the excerpt.
	 */
	public function test_sync_without_a_user_keeps_title_and_excerpt_markup(): void {
		$title   = 'E = mc<sup>2</sup> and H<sub>2</sub>O';
		$excerpt = 'Watch: <iframe src="https://example.com/embed"></iframe>';

		$post_id = $this->create_post( $this->image_block( 'Old' ) );

		// Stored as an editor with unfiltered_html would store them.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'            => $title,
				'post_excerpt'          => $excerpt,
				'post_content_filtered' => '<iframe src="https://example.com/cached"></iframe>',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		// No user, as in cron: kses is hooked to every field.
		wp_set_current_user( 0 );
		kses_init_filters();

		try {
			$this->assertSame( 10, has_filter( 'title_save_pre', 'wp_filter_kses' ) );

			( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New' );
		} finally {
			kses_remove_filters();
		}

		$post = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertStringContainsString( 'alt="New"', $post->post_content );
		$this->assertSame( $title, $post->post_title );
		$this->assertSame( $excerpt, $post->post_excerpt );
		$this->assertSame( '<iframe src="https://example.com/cached"></iframe>', $post->post_content_filtered );

		$revisions = wp_get_post_revisions( $post_id );
		$latest    = reset( $revisions );

		$this->assertNotFalse( $latest );
		$this->assertSame( $title, $latest->post_title, 'The revision keeps the title too.' );
		$this->assertSame( $excerpt, $latest->post_excerpt );
	}

	/**
	 * Test that no save filter changes the rest of a synced post.
	 *
	 * Regression test (third audit, bugs 1 and 2): filters that run before
	 * kses changed the content, so it no longer matched the exempted string
	 * and kses filtered the whole post. WordPress 7.0 strips block custom
	 * CSS at priority 8 for anyone without edit_css; trim() runs on titles
	 * and convert_invalid_entities() on content. A stand-in for the CSS
	 * filter is added, so the test means the same on every version; on 7.0
	 * and newer core's own runs too.
	 */
	public function test_no_save_filter_changes_the_rest_of_a_synced_post(): void {
		global $wpdb;

		$content = '<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>Prices &#150; and more</p><!-- /wp:paragraph -->'
			. "\n\n" . $this->image_block( 'Old' )
			. "\n\n" . '<!-- wp:html --><iframe src="https://example.com/embed"></iframe><form action="/subscribe"><input type="email" name="e"></form><!-- /wp:html -->';
		$title   = '  E = mc<sup>2</sup>  ';

		$post_id = $this->create_post( 'placeholder' );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content, 'post_title' => $title ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$strip_css = static fn( $data ) => is_string( $data ) ? (string) preg_replace( '/,?\\\\?"css\\\\?":\\\\?"[^"\\\\]*\\\\?"/', '', $data ) : $data;
		add_filter( 'content_save_pre', $strip_css, 8 );

		// No user, as in cron: kses is hooked to every field.
		wp_set_current_user( 0 );
		kses_init_filters();

		try {
			( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New' );

			// Another post saved now still gets every filter.
			$other = wp_insert_post(
				array(
					'post_status'  => 'draft',
					'post_title'   => '  Side  ',
					'post_content' => wp_slash( '<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph --><iframe src="https://evil.example/"></iframe>' ),
				)
			);
		} finally {
			kses_remove_filters();
			remove_filter( 'content_save_pre', $strip_css, 8 );
		}

		$post = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( str_replace( 'alt="Old"', 'alt="New"', $content ), $post->post_content, 'Only the alt changed: custom CSS, the entity, the iframe and the form stay.' );
		$this->assertSame( $title, $post->post_title );

		$revisions = wp_get_post_revisions( $post_id );
		$latest    = reset( $revisions );
		$this->assertNotFalse( $latest );
		$this->assertSame( $post->post_content, $latest->post_content );

		$side = get_post( (int) $other );
		$this->assertInstanceOf( WP_Post::class, $side );
		$this->assertStringNotContainsString( '"css"', $side->post_content );
		$this->assertStringNotContainsString( '<iframe', $side->post_content );
		$this->assertSame( 'Side', $side->post_title );
	}

	/**
	 * Test that a failing WP-Cron job does not stop the jobs behind it.
	 *
	 * Regression test (third audit, bug 3): the worker run ended with the
	 * failing job, and the next one waited with no run scheduled.
	 */
	public function test_a_failing_cron_job_does_not_stop_the_queue(): void {
		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		$other_id = $this->create_image( 'other', 'Other alt' );
		$post_a   = $this->create_post( $this->image_block( 'Old' ) );
		$post_b   = $this->create_post( sprintf( '<img src="%s" alt="Old" class="wp-image-%d" />', $this->url( 'other', '' ), $other_id ) );

		// Throws before the post is written, so the post is not updated.
		$failing = $this->attachment_id;
		$break   = static function ( $data, $postarr ) use ( $post_a ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $post_a ) {
				throw new RuntimeException( 'A save filter broke.' );
			}

			return $data;
		};
		add_filter( 'wp_insert_post_data', $break, 10, 2 );

		update_post_meta( $failing, '_wp_attachment_image_alt', 'Breaks' );
		update_post_meta( $other_id, '_wp_attachment_image_alt', 'Other new' );

		$this->assertSame( 2, AltRelay_Queue::pending_count() );

		( new AltRelay_Queue() )->work_through_queue( 5 );
		remove_filter( 'wp_insert_post_data', $break, 10 );

		$this->assertSame( 0, AltRelay_Queue::pending_count() );
		$this->assertStringContainsString( 'alt="Other new"', $this->content( $post_b ) );

		$errors = get_transient( AltRelay_Sync::ERRORS_TRANSIENT );
		$this->assertIsArray( $errors );
		// Since the fourth audit the failing post is recorded on its own, and
		// its image is offered to run again.
		$this->assertSame( 'A save filter broke.', $errors['message'] );
		$this->assertSame( array( $failing ), AltRelay_Sync::images_to_retry() );
	}

	/**
	 * Test that post content is read as stored.
	 *
	 * Regression test (external audit, bug 3): get_post_field() defaults to
	 * the display context, which runs the post_content filter, and whatever
	 * a plugin added there was saved into the post.
	 */
	public function test_display_filters_are_never_saved(): void {
		$post_id = $this->create_post( $this->image_block( 'Old' ) );
		$inject  = static fn( $content ) => $content . '<!-- added by a display filter -->';

		add_filter( 'post_content', $inject );

		$sync = new AltRelay_Sync();
		$sync->sync_attachment( $this->attachment_id, 'New alt' );
		$sync->mark_post_decorative( $post_id, $this->attachment_id );
		$sync->unmark_post_decorative( $post_id, $this->attachment_id );
		$sync->fill_post( $post_id, $this->attachment_id );

		remove_filter( 'post_content', $inject );

		$this->assertStringNotContainsString( 'added by a display filter', $this->content( $post_id ) );
		$this->assertStringContainsString( 'alt="Original alt"', $this->content( $post_id ) );
	}

	/**
	 * Test that a revision is created for each synced post.
	 */
	public function test_sync_creates_a_revision(): void {
		$post_id = $this->create_post( $this->image_tag( 'old' ) );
		$before  = count( wp_get_post_revisions( $post_id ) );

		$sync = new AltRelay_Sync();
		$sync->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * Test that an image marked decorative is left alone.
	 */
	public function test_sync_preserves_decorative_image(): void {
		$post_id = $this->create_post( $this->image_block( '', 'altrelay-decorative' ) );

		$sync   = new AltRelay_Sync();
		$result = $sync->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertSame( 0, $result['updated'] );
		$this->assertStringContainsString( 'alt=""', $this->content( $post_id ) );
	}

	/**
	 * Test that a synced pattern is updated.
	 */
	public function test_sync_updates_synced_pattern(): void {
		$pattern_id = $this->create_post( $this->image_tag( 'old' ), array( 'post_type' => 'wp_block' ) );

		$sync   = new AltRelay_Sync();
		$result = $sync->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertSame( 1, $result['updated'] );
		$this->assertStringContainsString( 'alt="New alt"', $this->content( $pattern_id ) );
	}

	/**
	 * Test that wp-image-420 is not treated as wp-image-42.
	 */
	public function test_sync_ignores_longer_attachment_ids(): void {
		$content = sprintf(
			'<img src="%s" alt="keep" class="wp-image-%d0" />',
			$this->url(),
			$this->attachment_id
		);

		$post_id = $this->create_post( $content );

		$sync = new AltRelay_Sync();
		$sync->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertStringContainsString( 'alt="keep"', $this->content( $post_id ) );
	}

	/**
	 * Test that an image ID is only trusted when the file belongs to it.
	 *
	 * Regression test (external audit, bug 1): a block pasted from another
	 * site keeps that site's IDs. Its image used to pick up the alt text of
	 * whichever local attachment had the same ID.
	 */
	public function test_foreign_file_with_a_local_id_is_left_alone(): void {
		$foreign = sprintf(
			'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="https://other.example/wp-content/uploads/2019/03/stranger.jpg" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
			$this->attachment_id
		);
		$post_id = $this->create_post( $foreign );
		$sync    = new AltRelay_Sync();

		$this->assertStringNotContainsString( 'Original alt', apply_filters( 'the_content', $foreign ) );

		$sync->sync_attachment( $this->attachment_id, 'New alt' );
		$this->assertSame( $foreign, $this->content( $post_id ) );

		$this->assertFalse( $sync->fill_post( $post_id, $this->attachment_id ) );
		$this->assertFalse( $sync->mark_post_decorative( $post_id, $this->attachment_id )['changed'] );
		$this->assertSame( $foreign, $this->content( $post_id ) );
		$this->assertSame( array(), AltRelay_Report::get_empty_alt_review()['rows'] );
	}

	/**
	 * Test that AltRelay recognises every file WordPress generates for a real
	 * upload, and agrees with core about them.
	 */
	public function test_file_matching_agrees_with_core_for_a_real_upload(): void {
		$upload_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$meta      = wp_get_attachment_metadata( $upload_id );

		$this->assertIsArray( $meta );
		$this->assertNotEmpty( $meta['sizes'], 'The upload must have generated sizes.' );

		$urls = array( wp_get_attachment_url( $upload_id ) );

		foreach ( array_keys( $meta['sizes'] ) as $size ) {
			$urls[] = wp_get_attachment_image_src( $upload_id, $size )[0];
		}

		foreach ( array_unique( $urls ) as $url ) {
			$this->assertTrue( wp_image_file_matches_image_meta( $url, $meta, $upload_id ), "Core should match $url" );
			$this->assertTrue( AltRelay_Content::image_matches_attachment( $url, $upload_id ), "AltRelay should match $url" );
			$this->assertFalse( AltRelay_Content::image_matches_attachment( $url, $this->attachment_id ), "$url belongs to another attachment" );
		}

		wp_delete_attachment( $upload_id, true );
	}

	/**
	 * Test that a batched run covers every post, including batch boundaries.
	 *
	 * Regression test: the resume cursor was advanced before the limit check,
	 * so one post per batch was skipped and never synced.
	 */
	public function test_batched_sync_covers_every_post(): void {
		$post_ids = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$post_ids[] = $this->create_post( $this->image_tag( 'old' ) );
		}

		$sync     = new AltRelay_Sync();
		$after_id = 0;
		$rounds   = 0;

		do {
			$result   = $sync->sync_attachment( $this->attachment_id, 'New alt', false, $after_id, 2 );
			$after_id = $result['last_id'];
			++$rounds;
		} while ( ! $result['done'] && $rounds < 20 );

		foreach ( $post_ids as $post_id ) {
			$this->assertStringContainsString(
				'alt="New alt"',
				$this->content( $post_id ),
				sprintf( 'Post %d was skipped by batching.', $post_id )
			);
		}

		// Regression test (external audit, bug 9): the dashboard's "last
		// sync" adds the batches up instead of showing only the last one.
		$log = get_transient( AltRelay_Sync::LAST_SYNC_TRANSIENT );

		$this->assertIsArray( $log );
		$this->assertSame( 7, $log['post_count'] );
	}

	/**
	 * Test that sync_posts() writes all images of a post with one save.
	 *
	 * Regression test (external audit, WP-CLI): "sync --all" went image by
	 * image, so a post with several images was saved several times.
	 */
	public function test_sync_posts_saves_each_post_once(): void {
		$cat_id  = $this->create_image( 'cat', 'A cat' );
		$bare_id = $this->create_image( 'bare' );

		AltRelay_Queue::cancel_all();

		$content = $this->image_block( 'old' )
			. sprintf( '<p><img src="%s" alt="" class="wp-image-%d" /></p>', $this->url( 'cat' ), $cat_id )
			. sprintf( '<p><img src="%s" alt="kept" class="wp-image-%d" /></p>', $this->url( 'bare' ), $bare_id );

		$post_id = $this->create_post( $content );
		$page_id = $this->create_post( $this->image_block( 'old' ), array( 'post_type' => 'page' ) );
		$before  = count( wp_get_post_revisions( $post_id ) );
		$sync    = new AltRelay_Sync();

		$dry = $sync->sync_posts( true );

		$this->assertSame( 2, $dry['updated'] );
		$this->assertSame( $content, $this->content( $post_id ), 'A dry run writes nothing.' );

		$result = $sync->sync_posts( false, 0, 0, 0, array( 'post' ) );
		$saved  = $this->content( $post_id );

		$this->assertSame( array( $post_id ), $result['post_ids'], 'Only the requested post type is touched.' );
		$this->assertStringContainsString( 'alt="Original alt"', $saved );
		$this->assertStringContainsString( 'alt="A cat"', $saved );
		$this->assertStringContainsString( 'alt="kept"', $saved, 'An image without alt text in the library is left alone.' );
		$this->assertSame( 1, count( wp_get_post_revisions( $post_id ) ) - $before, 'One save, one revision.' );
		$this->assertStringContainsString( 'alt="old"', $this->content( $page_id ) );
	}

	/**
	 * Test that a second change to an image whose job is still waiting adds
	 * no job, and that the job writes the newest alt text.
	 *
	 * Regression test (external audit, bug 4): every change used to load the
	 * whole backlog to find jobs to cancel.
	 */
	public function test_queue_holds_one_job_per_image_and_reads_the_latest_alt(): void {
		if ( ! AltRelay_Queue::has_action_scheduler() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		$post_id = $this->create_post( $this->image_tag( 'old' ) );

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'first' );
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'second' );
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'third' );

		$jobs = $this->waiting_jobs();

		$this->assertCount( 1, $jobs, 'Three changes to one image are one job.' );
		$this->assertSame( array( $this->attachment_id, 0, 0 ), $jobs[0], 'A job is an image, a cursor and a user. No alt text.' );

		( new AltRelay_Queue() )->process( ...$jobs[0] );

		$this->assertStringContainsString( 'alt="third"', $this->content( $post_id ) );
	}

	/**
	 * Test that a very long alt text gets through the queue.
	 *
	 * Regression test (external audit, bug 10): Action Scheduler refuses
	 * arguments above about 8,000 characters, and the alt text used to be
	 * one of them.
	 */
	public function test_very_long_alt_text_is_queued_and_synced(): void {
		$post_id = $this->create_post( $this->image_tag( 'old' ) );
		$long    = trim( str_repeat( 'A long description. ', 600 ) );

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', $long );

		$this->assertSame( 1, AltRelay_Queue::pending_count() );

		( new AltRelay_Queue() )->process( $this->attachment_id, 0, 0 );

		$this->assertStringContainsString( 'alt="' . $long . '"', $this->content( $post_id ) );
	}

	/**
	 * Test that cancel_all really empties the queue.
	 *
	 * Regression test: as_unschedule_all_actions() only cancels by hook when
	 * exactly one of hook and group is set. Passing both falls through to an
	 * exact-args lookup, which never matches the queued arguments, so the
	 * queue was left untouched.
	 */
	public function test_cancel_all_empties_the_queue(): void {
		if ( ! AltRelay_Queue::has_action_scheduler() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		$other_id = $this->create_image( 'other' );

		AltRelay_Queue::cancel_all();

		AltRelay_Queue::enqueue( $this->attachment_id );
		AltRelay_Queue::enqueue( $other_id );

		$this->assertCount( 2, $this->waiting_jobs(), 'Both attachments should be queued.' );
		$this->assertSame( 2, AltRelay_Queue::pending_count() );
		$this->assertSame( 0, AltRelay_Queue::failed_count() );

		AltRelay_Queue::cancel_all();

		$this->assertCount( 0, $this->waiting_jobs(), 'cancel_all() should leave nothing queued.' );
	}

	/**
	 * Test that cancel_all also empties the WP-Cron queue.
	 *
	 * Regression test: wp_clear_scheduled_hook() without arguments only
	 * removes events scheduled without arguments. Every AltRelay event has
	 * arguments, so deactivation and uninstall left them all behind.
	 */
	public function test_cancel_all_empties_the_cron_queue(): void {
		add_filter( 'altrelay_use_action_scheduler', '__return_false' );

		$other_id = $this->create_image( 'other' );

		AltRelay_Queue::cancel_all();

		AltRelay_Queue::enqueue( $this->attachment_id, 1 );
		AltRelay_Queue::enqueue( $this->attachment_id, 1 );
		AltRelay_Queue::enqueue( $other_id, 1 );

		$this->assertSame( 2, AltRelay_Queue::pending_count(), 'One job per attachment, however often it is queued.' );
		$this->assertNotFalse( wp_next_scheduled( AltRelay_Queue::CRON_HOOK ) );

		AltRelay_Queue::cancel_all();

		$this->assertSame( 0, AltRelay_Queue::pending_count(), 'cancel_all() should leave nothing queued.' );
		$this->assertFalse( wp_next_scheduled( AltRelay_Queue::CRON_HOOK ) );
		$this->assertFalse( get_option( AltRelay_Queue::QUEUE_OPTION ) );
	}

	/**
	 * Test that a network-wide deactivation empties the queue of every site.
	 *
	 * Regression test (external audit, Bootstrap): only the current site was
	 * cleared.
	 */
	public function test_network_deactivation_clears_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network.' );
		}

		add_filter( 'altrelay_use_action_scheduler', '__return_false' );

		$site_ids = array( get_current_blog_id(), self::factory()->blog->create() );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			AltRelay_Queue::enqueue( 123 );
			$this->assertSame( 1, AltRelay_Queue::pending_count() );
			restore_current_blog();
		}

		altrelay_deactivate( true );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertSame( 0, AltRelay_Queue::pending_count(), "Site $site_id still has queued work." );
			restore_current_blog();
		}
	}

	/**
	 * Regression (fourth audit): an alt text changed while switched to
	 * another site was queued on the site the request started on.
	 */
	public function test_a_change_while_switched_is_queued_on_that_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network.' );
		}

		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		wp_set_current_user( 0 );

		$main  = get_current_blog_id();
		$other = self::factory()->blog->create();

		// As if AltRelay were activated on the main site only.
		$per_site = new ReflectionProperty( AltRelay_Sync::class, 'activated_per_site' );
		$per_site->setValue( null, true );

		switch_to_blog( $other );
		$image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );

		try {
			// Not active on that site: nothing would run a job there.
			update_post_meta( $image, '_wp_attachment_image_alt', 'Ignored' );
			AltRelay_Queue::flush();
			$this->assertFalse( get_option( AltRelay_Queue::QUEUE_OPTION ) );

			update_option( 'active_plugins', array( ALTRELAY_PLUGIN_BASENAME ) );
			update_post_meta( $image, '_wp_attachment_image_alt', 'On the other site' );
		} finally {
			$per_site->setValue( null, false );
			restore_current_blog();
		}

		// The request ends on the main site.
		$this->assertSame( $main, get_current_blog_id() );
		AltRelay_Queue::flush();

		$main_queue = get_option( AltRelay_Queue::QUEUE_OPTION );
		$this->assertArrayNotHasKey( $image . ':0', is_array( $main_queue ) ? $main_queue['waiting'] : array() );

		switch_to_blog( $other );
		$other_queue = get_option( AltRelay_Queue::QUEUE_OPTION );
		$scheduled   = wp_next_scheduled( AltRelay_Queue::CRON_HOOK );
		restore_current_blog();

		$this->assertIsArray( $other_queue );
		$this->assertSame( array( $image, 0, 0 ), $other_queue['waiting'][ $image . ':0' ] );
		$this->assertNotFalse( $scheduled, 'The worker is scheduled on that site.' );
	}

	/**
	 * Regression (fourth audit): one post that throws on save stopped the
	 * rest of the image's posts.
	 */
	public function test_a_post_that_throws_does_not_stop_the_others(): void {
		$posts = array(
			$this->create_post( $this->image_block( 'Old' ) ),
			$this->create_post( $this->image_block( 'Old' ) ),
			$this->create_post( $this->image_block( 'Old' ) ),
		);

		// Throws before the post is written, so the post is not updated.
		$breaks = static function ( $data, $postarr ) use ( $posts ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $posts[1] ) {
				throw new RuntimeException( 'A save filter broke.' );
			}

			return $data;
		};
		add_filter( 'wp_insert_post_data', $breaks, 10, 2 );

		$report = ( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New' );

		remove_filter( 'wp_insert_post_data', $breaks, 10 );

		$this->assertSame( 1, $report['failed'] );
		$this->assertStringContainsString( 'alt="New"', $this->content( $posts[0] ) );
		$this->assertStringNotContainsString( 'alt="New"', $this->content( $posts[1] ) );
		$this->assertStringContainsString( 'alt="New"', $this->content( $posts[2] ) );
		$this->assertSame( array( $this->attachment_id ), AltRelay_Sync::images_to_retry() );
	}

	/**
	 * Regression (fifth audit): a post whose save_post hook threw was counted
	 * as failed, although WordPress had already stored its new content.
	 */
	public function test_a_post_saved_before_a_hook_threw_counts_as_updated(): void {
		$post_id = $this->create_post( $this->image_block( 'Old' ) );

		$breaks = static function (): void {
			throw new RuntimeException( 'A save_post callback broke.' );
		};
		add_action( 'save_post', $breaks );

		$report = ( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New' );

		remove_action( 'save_post', $breaks );

		$this->assertSame( array( 1, 0 ), array( $report['updated'], $report['failed'] ) );
		$this->assertStringContainsString( 'alt="New"', $this->content( $post_id ) );
		$this->assertSame( array(), AltRelay_Sync::images_to_retry() );
	}

	/**
	 * An image whose alt text was emptied in the Media Library because it is
	 * decorative can be marked decorative wherever it is used, and then no
	 * longer counts as missing alt text.
	 */
	public function test_an_image_marked_decorative_everywhere_leaves_the_missing_list(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_post_meta( $this->attachment_id, '_wp_attachment_image_alt' );

		$posts = array( $this->create_post( $this->image_block( '' ) ), $this->create_post( $this->image_tag( '' ) ) );

		AltRelay_Report::flush_stats();
		$this->assertContains( $this->attachment_id, AltRelay_Report::get_missing_alt_ids() );
		$before = AltRelay_Report::get_alt_text_stats();

		$report = ( new AltRelay_Sync() )->mark_attachment_decorative( $this->attachment_id );

		$this->assertTrue( $report['recorded'] );
		$this->assertSame( 2, $report['marked'] );

		// AltRelay's class, or core's own setting on an Image block from WordPress 7.1.
		foreach ( $posts as $post_id ) {
			$this->assertMatchesRegularExpression( '/altrelay-decorative|"isDecorative":true/', $this->content( $post_id ) );
		}

		$this->assertNotContains( $this->attachment_id, AltRelay_Report::get_missing_alt_ids() );
		$this->assertContains( $this->attachment_id, AltRelay_Report::get_missing_alt_ids( 1, 20, true ) );

		$after = AltRelay_Report::get_alt_text_stats();
		$this->assertSame( $before['without_alt'] - 1, $after['without_alt'] );
		$this->assertSame( $before['decorative'] + 1, $after['decorative'] );

		// Described after all: listed with the other images again, while the
		// posts keep their decorative marks.
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'A description after all' );

		$this->assertFalse( AltRelay_Sync::is_recorded_decorative( $this->attachment_id ) );
		$this->assertSame( $before['decorative'], AltRelay_Report::get_alt_text_stats()['decorative'] );
		$this->assertMatchesRegularExpression( '/altrelay-decorative|"isDecorative":true/', $this->content( $posts[0] ) );
	}

	/**
	 * Regression (fifth audit): the dashboard's "Mark decorative" did all its
	 * work in one request. It is queued now, and the record follows new
	 * uses: a post saved later with an unmarked copy lists the image again.
	 */
	public function test_marking_decorative_runs_in_the_background_and_follows_new_uses(): void {
		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		delete_post_meta( $this->attachment_id, '_wp_attachment_image_alt' );

		$posts = array( $this->create_post( $this->image_block( '' ) ), $this->create_post( $this->image_tag( '' ) ) );

		$this->assertSame( 'started', ( new AltRelay_Sync() )->start_marking_decorative( $this->attachment_id, $user_id ) );
		AltRelay_Queue::flush();

		$this->assertSame( 1, AltRelay_Queue::pending_count() );
		$this->assertDoesNotMatchRegularExpression( '/altrelay-decorative|"isDecorative":true/', $this->content( $posts[1] ), 'Nothing is marked in the request itself.' );

		( new AltRelay_Queue() )->work_through_queue( 10 );

		foreach ( $posts as $post_id ) {
			$this->assertMatchesRegularExpression( '/altrelay-decorative|"isDecorative":true/', $this->content( $post_id ) );
		}

		$this->assertTrue( AltRelay_Sync::is_recorded_decorative( $this->attachment_id ) );
		$this->assertNull( AltRelay_Sync::marking_state( $this->attachment_id ) );
		$this->assertSame( 0, AltRelay_Queue::pending_count() );

		// A new copy without the mark.
		$this->create_post( $this->image_tag( '' ) );

		$this->assertFalse( AltRelay_Sync::is_recorded_decorative( $this->attachment_id ) );
		$this->assertContains( $this->attachment_id, AltRelay_Report::get_missing_alt_ids() );

		AltRelay_Queue::cancel_all();
	}

	/**
	 * Regression (sixth audit): an editor that had the post open while the
	 * image's alt text changed saved the old text back.
	 */
	public function test_a_save_from_an_editor_that_was_open_keeps_the_new_alt(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$old     = $this->image_block( 'Original alt' );
		$post_id = $this->create_post( $old );

		// The editor is open: its heartbeat keeps the lock fresh.
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );

		( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'Changed alt' );

		$this->assertStringContainsString( 'alt="Changed alt"', $this->content( $post_id ) );
		$this->assertArrayHasKey( $this->attachment_id, AltRelay_Sync::stale_alts( $post_id ) );

		// The library text is the one the post should keep.
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Changed alt' );

		// The editor saves what it still shows.
		wp_set_current_user( $user_id );
		kses_remove_filters();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $old,
			)
		);
		kses_init();

		$this->assertStringContainsString( 'alt="Changed alt"', $this->content( $post_id ) );
		$this->assertStringNotContainsString( 'Original alt', $this->content( $post_id ) );
	}

	/**
	 * Test that the frontend fills a missing alt through the real the_content.
	 */
	public function test_frontend_fills_missing_alt(): void {
		$this->assertStringContainsString( 'alt="Original alt"', apply_filters( 'the_content', $this->image_tag( null ) ) );
	}

	/**
	 * Test that the frontend fills the alt="" the block editor saves for an
	 * image inserted without alt text.
	 */
	public function test_frontend_fills_editor_empty_alt(): void {
		$this->assertStringContainsString( 'alt="Original alt"', apply_filters( 'the_content', $this->image_block( '' ) ) );
	}

	/**
	 * Test that the frontend leaves an image marked decorative alone.
	 */
	public function test_frontend_preserves_decorative_image(): void {
		$rendered = apply_filters( 'the_content', $this->image_block( '', 'altrelay-decorative' ) );

		$this->assertStringContainsString( 'alt=""', $rendered );
		$this->assertStringNotContainsString( 'Original alt', $rendered );
	}

	/**
	 * Test that AltRelay's class on a block around an image makes the image
	 * decorative on the frontend too.
	 *
	 * Regression test (second audit, bug 3): blocks are rendered one at a
	 * time, so the Image block never saw the Group's class, and its alt was
	 * filled although the sync and the review treated it as decorative.
	 */
	public function test_frontend_respects_a_marked_block_around_the_image(): void {
		$group = '<!-- wp:group {"className":"altrelay-decorative","layout":{"type":"constrained"}} --><div class="wp-block-group altrelay-decorative"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. $this->image_block( '' )
			. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';

		$rendered = apply_filters( 'the_content', $group . $this->image_block( '' ) );

		$this->assertSame( 1, substr_count( $rendered, 'alt="Original alt"' ), 'Only the image outside the Group is filled.' );
		$this->assertSame( 1, substr_count( $rendered, 'alt=""' ) );
	}

	/**
	 * Test the ways the frontend fallback can be held back: the "review
	 * first" switch for existing empty alts, and a filter for all of it.
	 */
	public function test_frontend_fallback_can_be_held_back(): void {
		update_option( AltRelay_Content::REVIEW_FIRST_OPTION, '1' );

		$this->assertStringNotContainsString( 'Original alt', apply_filters( 'the_content', $this->image_block( '' ) ) );
		$this->assertStringContainsString( 'alt="Original alt"', apply_filters( 'the_content', $this->image_tag( null ) ), 'A missing alt attribute is never deliberate.' );

		update_option( AltRelay_Content::REVIEW_FIRST_OPTION, '0' );
		add_filter( 'altrelay_frontend_fallback', '__return_false' );

		$this->assertStringNotContainsString( 'Original alt', apply_filters( 'the_content', $this->image_tag( null ) ) );

		remove_filter( 'altrelay_frontend_fallback', '__return_false' );

		$this->assertStringContainsString( 'alt="Original alt"', altrelay_fill_alt_text( $this->image_tag( null ) ) );
	}

	/**
	 * Test that the editor's empty alt is filled by the sync.
	 */
	public function test_sync_fills_editor_empty_alt(): void {
		$post_id = $this->create_post( $this->image_block( '' ) );

		( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New alt' );

		$this->assertStringContainsString( 'alt="New alt"', $this->content( $post_id ) );
	}

	/**
	 * Test that clearing and then re-setting the library alt reaches posts.
	 *
	 * Regression test: once cleared, posts used to be stuck on alt="" forever.
	 */
	public function test_clear_then_reset_reaches_posts(): void {
		$post_id = $this->create_post( $this->image_block( 'Old' ) );
		$sync    = new AltRelay_Sync();

		$sync->sync_attachment( $this->attachment_id, '' );
		$this->assertStringContainsString( 'alt=""', $this->content( $post_id ) );

		$sync->sync_attachment( $this->attachment_id, 'New' );
		$this->assertStringContainsString( 'alt="New"', $this->content( $post_id ) );
	}

	/**
	 * Test that a synced Cover block round-trips through core's own parser and
	 * serializer unchanged, which is what the editor's validation relies on.
	 */
	public function test_cover_rewrite_matches_core_serializer(): void {
		$content = sprintf(
			'<!-- wp:cover {"url":"%2$s","id":%1$d,"alt":"Old","dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-%1$d" alt="Old" src="%2$s" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->',
			$this->attachment_id,
			$this->url( 'test', '' )
		);
		$post_id = $this->create_post( $content );

		// The backslash: newer WordPress versions write it as \u005c.
		( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'A "new" <alt> & more, C:\\photos' );

		$saved  = $this->content( $post_id );
		$blocks = parse_blocks( $saved );

		$this->assertSame( 'A "new" <alt> & more, C:\\photos', $blocks[0]['attrs']['alt'] );
		$this->assertSame( $saved, serialize_blocks( $blocks ), 'Core must serialize the block exactly as AltRelay wrote it.' );
		$this->assertStringContainsString( 'alt="A &quot;new&quot; &lt;alt&gt; &amp; more, C:\\photos"', $saved );
	}

	/**
	 * Test a Cover block with a fixed background, which has no <img>: the
	 * editor saves a <div> with role="img" and aria-label.
	 */
	public function test_fixed_cover_background_stays_in_step_with_its_comment(): void {
		$content = sprintf(
			'<!-- wp:cover {"url":"%2$s","id":%1$d,"alt":"Old","hasParallax":true,"dimRatio":50} --><div class="wp-block-cover has-parallax"><div role="img" aria-label="Old" class="wp-block-cover__image-background wp-image-%1$d has-parallax" style="background-position:50%% 50%%;background-image:url(%2$s)"></div><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->',
			$this->attachment_id,
			$this->url( 'test', '' )
		);
		$post_id = $this->create_post( $content );
		$sync    = new AltRelay_Sync();

		$sync->sync_attachment( $this->attachment_id, 'New' );

		$saved  = $this->content( $post_id );
		$blocks = parse_blocks( $saved );

		$this->assertSame( 'New', $blocks[0]['attrs']['alt'] );
		$this->assertStringContainsString( 'role="img" aria-label="New"', $saved );
		$this->assertSame( $saved, serialize_blocks( $blocks ) );

		$sync->sync_attachment( $this->attachment_id, '' );

		$saved  = $this->content( $post_id );
		$blocks = parse_blocks( $saved );

		$this->assertArrayNotHasKey( 'alt', $blocks[0]['attrs'] );
		$this->assertStringNotContainsString( 'aria-label', $saved );
		$this->assertStringNotContainsString( 'role="img"', $saved );
	}

	/**
	 * Test that a Cover inside a group that carries the decorative mark
	 * keeps a comment that matches its image.
	 *
	 * Regression test (external audit, bug 11): the comment got the new alt
	 * while the image, decorative through the group, kept the old one.
	 */
	public function test_cover_inside_marked_group_is_left_whole(): void {
		$content = sprintf(
			'<!-- wp:group {"className":"altrelay-decorative"} --><div class="wp-block-group altrelay-decorative"><!-- wp:cover {"url":"%2$s","id":%1$d,"alt":"Old","dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-%1$d" alt="Old" src="%2$s" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover --></div><!-- /wp:group -->',
			$this->attachment_id,
			$this->url( 'test', '' )
		);
		$post_id = $this->create_post( $content );

		$result = ( new AltRelay_Sync() )->sync_attachment( $this->attachment_id, 'New' );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( $content, $this->content( $post_id ) );
	}

	/**
	 * Test that marking decorative produces blocks core serializes identically,
	 * and that unmarking brings the original block back.
	 */
	public function test_mark_and_unmark_decorative_match_core_serializer(): void {
		$original = $this->image_block( 'Original alt', 'is-style-rounded' );
		$post_id  = $this->create_post( $original );
		$sync     = new AltRelay_Sync();

		$this->assertSame(
			array(
				'changed' => true,
				'refused' => 0,
			),
			$sync->mark_post_decorative( $post_id, $this->attachment_id )
		);

		$saved  = $this->content( $post_id );
		$blocks = parse_blocks( $saved );

		if ( AltRelay_Content::core_image_has_decorative() ) {
			// WordPress 7.1+: core's own setting, which the editor shows as a ticked checkbox.
			$this->assertTrue( $blocks[0]['attrs']['isDecorative'] );
			$this->assertSame( 'is-style-rounded', $blocks[0]['attrs']['className'] );
			$this->assertStringContainsString( 'role="none"', $saved );
		} else {
			$this->assertSame( 'is-style-rounded altrelay-decorative', $blocks[0]['attrs']['className'] );
			$this->assertArrayNotHasKey( 'isDecorative', $blocks[0]['attrs'] );
		}
		$this->assertSame( $saved, serialize_blocks( $blocks ) );
		$this->assertStringContainsString( 'alt=""', $saved );

		// The sync now leaves it alone, and the decorative list shows it.
		$sync->sync_attachment( $this->attachment_id, 'Original alt' );
		$this->assertSame( $saved, $this->content( $post_id ) );
		$this->assertSame( array( $post_id ), wp_list_pluck( AltRelay_Report::get_decorative_review()['rows'], 'post_id' ) );

		// Unmarking gives the original block back, byte for byte.
		$this->assertTrue( $sync->unmark_post_decorative( $post_id, $this->attachment_id ) );
		$this->assertSame( $original, $this->content( $post_id ) );
		$this->assertSame( array(), AltRelay_Report::get_decorative_review()['rows'] );
	}

	/**
	 * Test that an image which is the only content of a link is refused.
	 *
	 * Regression test (external audit, bug 7): marking it left a link with
	 * no name for screen reader users.
	 */
	public function test_mark_decorative_refuses_a_linked_image(): void {
		$linked  = $this->image_block( 'Dog', '', '<a href="https://example.com/dogs">', '</a><figcaption class="wp-element-caption">Rex</figcaption>' );
		$post_id = $this->create_post( $linked );
		$sync    = new AltRelay_Sync();

		$this->assertSame(
			array(
				'changed' => false,
				'refused' => 1,
			),
			$sync->mark_post_decorative( $post_id, $this->attachment_id )
		);
		$this->assertSame( $linked, $this->content( $post_id ) );

		// With text of its own, the link has a name, and the caption stays.
		$named   = $this->image_block( 'Dog', '', '<a href="https://example.com/dogs">', ' All about Rex</a><figcaption class="wp-element-caption">Rex</figcaption>' );
		$post_id = $this->create_post( $named );

		$this->assertTrue( $sync->mark_post_decorative( $post_id, $this->attachment_id )['changed'] );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Rex</figcaption>', $this->content( $post_id ) );
		$this->assertStringContainsString( 'alt=""', $this->content( $post_id ) );
	}

	/**
	 * Test that a void block does not reach into the block after it.
	 *
	 * Regression test (external audit, bug 8): marking a void Image block
	 * wiped the alt of the next, unrelated image.
	 */
	public function test_mark_decorative_on_a_void_block_touches_nothing_else(): void {
		$cat_id  = $this->create_image( 'cat', 'A cat' );
		$content = sprintf( '<!-- wp:image {"id":%d} /-->', $this->attachment_id ) . "\n\n"
			. sprintf(
				'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="%2$s" alt="A cat" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
				$cat_id,
				$this->url( 'cat' )
			);
		$post_id = $this->create_post( $content );

		$this->assertFalse( ( new AltRelay_Sync() )->mark_post_decorative( $post_id, $this->attachment_id )['changed'] );
		$this->assertSame( $content, $this->content( $post_id ) );
	}

	/**
	 * Test that a filter can keep a synced post's modified date.
	 */
	public function test_modified_date_can_be_kept(): void {
		global $wpdb;

		$post_id = $this->create_post( $this->image_tag( 'old' ) );

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2020-01-02 10:00:00',
				'post_modified_gmt' => '2020-01-02 10:00:00',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		$sync = new AltRelay_Sync();

		add_filter( 'altrelay_update_post_modified', '__return_false' );
		$sync->sync_attachment( $this->attachment_id, 'Kept' );
		remove_filter( 'altrelay_update_post_modified', '__return_false' );

		$this->assertStringContainsString( 'alt="Kept"', $this->content( $post_id ) );
		$this->assertSame( '2020-01-02 10:00:00', get_post( $post_id )->post_modified );
		$this->assertSame( '2020-01-02 10:00:00', get_post( $post_id )->post_modified_gmt );

		// Without the filter the date moves, so nothing stayed hooked.
		$sync->sync_attachment( $this->attachment_id, 'Moved' );

		$this->assertNotSame( '2020-01-02 10:00:00', get_post( $post_id )->post_modified );
	}

	/**
	 * Test that a queued change only reaches posts the acting user can edit.
	 *
	 * Regression test: an Author's alt change used to rewrite Administrators'
	 * posts, because the background worker had no user to check.
	 */
	public function test_queued_sync_respects_the_acting_user(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$admin_post  = $this->create_post( $this->image_block( 'Admin' ), array( 'post_author' => $admin ) );
		$author_post = $this->create_post( $this->image_block( 'Author' ), array( 'post_author' => $author ) );

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Changed by author' );
		wp_set_current_user( 0 );

		$queue = new AltRelay_Queue();
		$queue->process( $this->attachment_id, 0, $author );

		$this->assertStringContainsString( 'alt="Admin"', $this->content( $admin_post ) );
		$this->assertStringContainsString( 'alt="Changed by author"', $this->content( $author_post ) );

		// Regression test (second audit, bug 5): the skipped post is on record.
		$last = AltRelay_Sync::last_sync();

		$this->assertNotNull( $last );
		$this->assertSame( 1, $last['skipped_count'] );
		$this->assertSame( array( $this->attachment_id ), $last['skipped_attachments'] );
	}

	/**
	 * Test that the hook path queues the acting user's ID.
	 */
	public function test_meta_change_queues_acting_user(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Edited' );

		AltRelay_Queue::flush();
		$queue = get_option( AltRelay_Queue::QUEUE_OPTION );

		$this->assertIsArray( $queue );
		$this->assertSame( array( $this->attachment_id, 0, $editor ), $queue['waiting'][ $this->attachment_id . ':' . $editor ] );
		$this->assertNotFalse( wp_next_scheduled( AltRelay_Queue::CRON_HOOK ) );
	}

	/**
	 * Test that a job for an image that was deleted meanwhile does nothing.
	 */
	public function test_job_for_a_deleted_image_is_dropped(): void {
		$post_id = $this->create_post( $this->image_tag( 'old' ) );

		wp_delete_attachment( $this->attachment_id, true );

		$this->assertSame( 0, AltRelay_Queue::pending_count(), 'Deleting an image must not queue a sitewide clear.' );

		( new AltRelay_Queue() )->process( $this->attachment_id, 0, 0 );

		$this->assertStringContainsString( 'alt="old"', $this->content( $post_id ) );
	}

	/**
	 * Test that the WP-Cron fallback handles one batch per run and chains.
	 */
	public function test_cron_fallback_chains_batches(): void {
		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		self::factory()->post->create_many(
			AltRelay_Queue::BATCH_LIMIT + 1,
			array( 'post_content' => $this->image_block( 'Old' ) )
		);

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'New' );

		$this->assertSame( 1, AltRelay_Queue::pending_count() );

		// No time at all: one batch per run.
		add_filter( 'altrelay_cron_time_limit', '__return_zero' );
		( new AltRelay_Queue() )->run_cron_queue();

		$this->assertSame( 1, AltRelay_Queue::pending_count(), 'The remaining post must be queued as a new batch.' );
		$this->assertNotFalse( wp_next_scheduled( AltRelay_Queue::CRON_HOOK ), 'And the next run scheduled.' );

		remove_filter( 'altrelay_cron_time_limit', '__return_zero' );
		( new AltRelay_Queue() )->run_cron_queue();

		$this->assertSame( 0, AltRelay_Queue::pending_count() );
		$this->assertFalse( get_option( AltRelay_Queue::QUEUE_OPTION ), 'The job list is removed once it is empty.' );
	}

	/**
	 * Test that the WP-Cron jobs stay out of the autoloaded "cron" option.
	 *
	 * Regression test (second audit, Performance): every job used to be a
	 * cron event of its own, and 300 changed images added 36 KB to an
	 * option WordPress loads on every request.
	 */
	public function test_cron_jobs_stay_out_of_the_autoloaded_options(): void {
		global $wpdb;

		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		for ( $i = 0; $i < 50; $i++ ) {
			AltRelay_Queue::enqueue( $this->create_image( 'bulk-' . $i ), 1 );
		}

		$this->assertSame( 50, AltRelay_Queue::pending_count() );

		$events = 0;
		foreach ( _get_cron_array() as $hooks ) {
			$events += count( $hooks[ AltRelay_Queue::CRON_HOOK ] ?? array() );
		}
		$this->assertSame( 1, $events, 'One worker event for all jobs.' );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", AltRelay_Queue::QUEUE_OPTION ) );
		$this->assertContains( $autoload, array( 'off', 'no' ), 'The job list is not autoloaded.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AltRelay_Queue::LOCK_OPTION ) ), 'No lock is left behind.' );

		AltRelay_Queue::cancel_all();
	}

	/**
	 * Test the lock against the real options table: INSERT IGNORE takes it
	 * once, a second attempt waits, and an expired one is taken over.
	 */
	public function test_cron_queue_lock_is_exclusive(): void {
		global $wpdb;

		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		// Another request holds a lock that expired long ago.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => AltRelay_Queue::LOCK_OPTION,
				'option_value' => (string) ( time() - 3600 ),
				'autoload'     => 'off',
			)
		);

		AltRelay_Queue::enqueue( $this->attachment_id, 1 );
		$started = microtime( true );
		AltRelay_Queue::flush();

		$this->assertLessThan( 2, microtime( true ) - $started, 'An expired lock is taken over at once.' );
		$this->assertSame( 1, AltRelay_Queue::pending_count() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AltRelay_Queue::LOCK_OPTION ) ) );

		AltRelay_Queue::cancel_all();
	}
	/**
	 * Test the empty-alt review against a real database.
	 *
	 * Regression tests (external audit, bugs 5 and 6, and the dashboard
	 * section): private titles shown to users who cannot edit the post,
	 * decorative posts crowding real rows off the first page, and images
	 * without any alt attribute not being found at all.
	 */
	public function test_review_lists_what_needs_review_and_nothing_else(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$private = $this->create_post(
			$this->image_block( '' ),
			array(
				'post_author' => $admin,
				'post_status' => 'private',
				'post_title'  => 'Confidential plans',
			)
		);
		$no_attr = $this->create_post(
			$this->image_tag( null ) . sprintf( '<img src="%s" alt="Described" />', $this->url( 'other' ) ),
			array( 'post_author' => $author )
		);
		$linked  = $this->create_post(
			$this->image_block( '', '', '<a href="https://example.com/">', '</a>' ),
			array( 'post_author' => $author )
		);

		// Newer posts whose only copy is already decorative.
		for ( $i = 0; $i < 25; $i++ ) {
			$this->create_post( $this->image_block( '', 'altrelay-decorative' ), array( 'post_author' => $author ) );
		}

		wp_set_current_user( $author );

		$review = AltRelay_Report::get_empty_alt_review();
		$rows   = array_column( $review['rows'], null, 'post_id' );

		$this->assertSame( array( $linked, $no_attr ), array_keys( $rows ), 'Newest first; the private post and the decorative posts are not listed.' );
		$this->assertNotContains( 'Confidential plans', wp_list_pluck( $review['rows'], 'post_title' ) );
		$this->assertSame( 'Original alt', $rows[ $no_attr ]['library_alt'] );
		$this->assertSame( 1, $rows[ $linked ]['linked'] );
		$this->assertSame( 0, $rows[ $no_attr ]['linked'] );
		$this->assertSame( 0, $review['next'] );

		wp_set_current_user( $admin );

		$this->assertContains( $private, wp_list_pluck( AltRelay_Report::get_empty_alt_review()['rows'], 'post_id' ) );

		// Paging: one row per page, each page starting below the last post read.
		$first  = AltRelay_Report::get_empty_alt_review( 0, 1 );
		$second = AltRelay_Report::get_empty_alt_review( $first['next'], 1 );

		$this->assertSame( array( $linked ), wp_list_pluck( $first['rows'], 'post_id' ) );
		$this->assertSame( array( $no_attr ), wp_list_pluck( $second['rows'], 'post_id' ) );
	}

	/**
	 * Test the dashboard numbers against a real database: they are cached,
	 * and the cache is dropped by the hooks that make it stale.
	 */
	public function test_numbers_are_cached_and_refreshed(): void {
		$bare_id = $this->create_image( 'bare' );

		AltRelay_Report::flush_stats();

		$stats = AltRelay_Report::get_alt_text_stats();

		$this->assertSame( 2, $stats['total'] );
		$this->assertSame( 1, $stats['with_alt'] );
		$this->assertSame( array( $bare_id ), AltRelay_Report::get_missing_alt_ids() );
		$this->assertSame( array( $bare_id ), AltRelay_Report::get_missing_alt_ids( 1, 50 ) );
		$this->assertIsArray( get_transient( AltRelay_Report::STATS_TRANSIENT ) );

		update_post_meta( $bare_id, '_wp_attachment_image_alt', 'Now described' );

		$this->assertSame( 2, AltRelay_Report::get_alt_text_stats()['with_alt'] );

		$this->create_image( 'another' );

		$this->assertSame( 3, AltRelay_Report::get_alt_text_stats()['total'] );

		// Usage: counted per image, by the database.
		$this->create_post( $this->image_block( 'x' ) . $this->image_tag( 'y' ) );
		$this->create_post( $this->image_block( 'x' ) );
		$this->create_post( sprintf( '<img src="%s" alt="" class="wp-image-%d0" />', $this->url(), $this->attachment_id ) );

		$usage = AltRelay_Report::get_usage_counts( array( $this->attachment_id, $bare_id ) );

		$this->assertSame( 2, $usage[ $this->attachment_id ], 'Once per post, and not for a longer ID.' );
		$this->assertSame( 0, $usage[ $bare_id ] );

		// A new post drops the cached counts.
		$this->create_post( sprintf( '<img src="%s" alt="" class="wp-image-%d" />', $this->url( 'bare' ), $bare_id ) );

		$this->assertSame( 1, AltRelay_Report::get_usage_counts( array( $this->attachment_id, $bare_id ) )[ $bare_id ] );
	}

	/**
	 * Test that the components are exposed and are the instances that were hooked.
	 */
	public function test_components_can_be_unhooked(): void {
		$frontend = altrelay( 'frontend' );

		$this->assertInstanceOf( AltRelay_Frontend::class, $frontend );
		$this->assertSame( 10, has_filter( 'render_block', array( $frontend, 'fill_block' ) ) );

		remove_filter( 'render_block', array( $frontend, 'fill_block' ), 10 );

		$this->assertStringNotContainsString( 'Original alt', apply_filters( 'the_content', $this->image_block( '' ) ) );

		add_filter( 'render_block', array( $frontend, 'fill_block' ), 10, 2 );
	}

	/**
	 * Fill every empty or missing alt with the Media Library alt of the
	 * attachment the class names, as the alt text plugins tested do.
	 *
	 * @param string $html Content or a single image tag.
	 * @return string
	 */
	public static function fill_like_others( string $html ): string {
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/',
			static function ( array $tag ): string {
				if ( preg_match( '/\salt="[^"]+"/', $tag[0] ) || ! preg_match( '/wp-image-(\d+)/', $tag[0], $id ) ) {
					return $tag[0];
				}

				$alt = (string) get_post_meta( (int) $id[1], '_wp_attachment_image_alt', true );

				return (string) preg_replace( '/^<img/', '<img alt="' . esc_attr( $alt ) . '"', (string) preg_replace( '/\salt="[^"]*"/', '', $tag[0] ) );
			},
			$html
		);
	}

	/**
	 * Regression (compatibility testing): plugins that fill alt text on
	 * display, on the_content (AltText.ai, Auto Alt Text, Rank Math, BIALTY)
	 * or on each image tag (SEOPress), wrote text into decorative images and
	 * the wrong attachment's text into foreign files.
	 */
	public function test_alt_text_other_plugins_add_on_display_is_undone(): void {
		$foreign = sprintf( '<img src="https://other.example/wp-content/uploads/2019/03/stranger.jpg" alt="" class="wp-image-%d" />', $this->attachment_id );
		$content = $this->image_block( '', 'altrelay-decorative' )
			. '<!-- wp:html -->' . $foreign . '<!-- /wp:html -->'
			. '<!-- wp:group {"className":"altrelay-decorative"} --><div class="wp-block-group altrelay-decorative">' . $this->image_block( '' ) . '</div><!-- /wp:group -->'
			. $this->image_block( '' );

		foreach ( array( 'the_content' => 999, 'wp_content_img_tag' => 10 ) as $hook => $priority ) {
			add_filter( $hook, array( self::class, 'fill_like_others' ), $priority );
			$rendered = apply_filters( 'the_content', $content );
			remove_filter( $hook, array( self::class, 'fill_like_others' ), $priority );

			$this->assertSame( 1, substr_count( $rendered, 'Original alt' ), "$hook: only the plain image is described." );
			$this->assertSame( 3, substr_count( $rendered, 'alt=""' ), "$hook: the decorative and foreign images stay empty." );
		}
	}

	/**
	 * Test that images in block templates, which core runs through
	 * wp_content_img_tag outside the_content, are guarded too.
	 */
	public function test_alt_text_other_plugins_add_in_templates_is_undone(): void {
		add_filter( 'wp_content_img_tag', array( self::class, 'fill_like_others' ) );
		$rendered = wp_filter_content_tags( '<img src="' . $this->url() . '" alt="" role="none" class="wp-image-' . $this->attachment_id . '" />', 'template' );
		remove_filter( 'wp_content_img_tag', array( self::class, 'fill_like_others' ) );

		$this->assertStringContainsString( 'alt=""', $rendered );
		$this->assertStringNotContainsString( 'Original alt', $rendered );

		// A block theme runs the rendered content through the filter again,
		// as part of the template, where the marked block is not visible.
		add_filter( 'wp_content_img_tag', array( self::class, 'fill_like_others' ) );
		$content  = apply_filters( 'the_content', $this->image_block( '', 'altrelay-decorative' ) );
		$template = wp_filter_content_tags( $content, 'template' );
		remove_filter( 'wp_content_img_tag', array( self::class, 'fill_like_others' ) );

		$this->assertStringNotContainsString( 'Original alt', $template );
	}

	/**
	 * Create Action Scheduler's actions table as a plugin that brought it
	 * left it behind, with the columns AltRelay reads.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string}> $rows Hook, status and arguments of each action.
	 * @return string The table name.
	 */
	private function leftover_action_scheduler_table( array $rows ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE IF NOT EXISTS $table ( action_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, hook varchar(191) NOT NULL, status varchar(20) NOT NULL, args varchar(191), extended_args varchar(8000), PRIMARY KEY (action_id) )" );

		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'hook'          => $row[0],
					'status'        => $row[1],
					'args'          => strlen( $row[2] ) > 191 ? md5( $row[2] ) : $row[2],
					'extended_args' => strlen( $row[2] ) > 191 ? $row[2] : null,
				)
			);
		}

		return $table;
	}

	/**
	 * Regression (compatibility testing): with the plugin that brought
	 * Action Scheduler deactivated, AltRelay's jobs waited in its table
	 * forever and the dashboard showed none. They are taken over by WP-Cron.
	 */
	public function test_jobs_left_in_action_scheduler_are_taken_over(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Only applies without Action Scheduler.' );
		}

		global $wpdb;

		$post_id = $this->create_post( $this->image_block( 'Old' ) );
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Changed while away' );
		AltRelay_Queue::cancel_all();

		$table = $this->leftover_action_scheduler_table(
			array(
				array( AltRelay_Queue::ACTION_HOOK, 'pending', wp_json_encode( array( $this->attachment_id, 0, 0 ) ) ),
				array( AltRelay_Queue::ACTION_HOOK, 'complete', wp_json_encode( array( 999, 0, 0 ) ) ),
				array( 'another_plugin_hook', 'pending', wp_json_encode( array( 1 ) ) ),
				array( AltRelay_Queue::ACTION_HOOK, 'pending', 'not json' ),
			)
		);

		AltRelay_Queue::wake();

		$this->assertSame( 1, AltRelay_Queue::pending_count() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$statuses = $wpdb->get_col( "SELECT status FROM $table ORDER BY action_id" );
		$this->assertSame( array( 'canceled', 'complete', 'pending', 'pending' ), $statuses, 'Only the job taken over is released.' );

		( new AltRelay_Queue() )->work_through_queue( 5 );

		$this->assertStringContainsString( 'alt="Changed while away"', $this->content( $post_id ) );

		// Deactivation releases what could not be taken over.
		AltRelay_Queue::cancel_all();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'canceled', 'complete', 'pending', 'canceled' ), $wpdb->get_col( "SELECT status FROM $table ORDER BY action_id" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS $table" );
	}

	/**
	 * Test that a site without Action Scheduler's table takes nothing over
	 * and logs no database error.
	 */
	public function test_taking_jobs_over_without_the_table_is_quiet(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Only applies without Action Scheduler.' );
		}

		global $wpdb;

		// A prefix no table has. Dropping the table would not do: the test
		// framework only drops temporary tables, and a run with Action
		// Scheduler may have created the real one in the same database.
		$prefix       = $wpdb->prefix;
		$wpdb->prefix = 'altrelay_none_';
		$suppressed   = $wpdb->suppress_errors;

		$taken = AltRelay_Queue::adopt_stranded_jobs();
		$error = $wpdb->last_error;

		$wpdb->prefix = $prefix;

		$this->assertSame( 0, $taken );
		$this->assertStringContainsString( 'altrelay_none_actionscheduler_actions', $error, 'The query failed, quietly.' );
		$this->assertSame( $suppressed, $wpdb->suppress_errors, 'Error reporting is back as it was.' );
	}

	/**
	 * Regression (compatibility testing): a request that found no job list
	 * kept believing so after another request had added one, and replaced
	 * the other request's jobs with its own.
	 */
	public function test_a_stale_options_cache_does_not_lose_queued_jobs(): void {
		add_filter( 'altrelay_use_action_scheduler', '__return_false' );
		AltRelay_Queue::cancel_all();

		// This request looks, and caches that there is no list.
		$this->assertFalse( get_option( AltRelay_Queue::QUEUE_OPTION ) );

		// Another request adds one, straight to the database.
		global $wpdb;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => AltRelay_Queue::QUEUE_OPTION,
				'option_value' => maybe_serialize( array( 'waiting' => array( '111:0' => array( 111, 0, 0 ) ), 'running' => array() ) ),
				'autoload'     => 'off',
			)
		);

		AltRelay_Queue::enqueue( $this->attachment_id );
		AltRelay_Queue::flush();

		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( AltRelay_Queue::QUEUE_OPTION, 'options' );
		$stored = get_option( AltRelay_Queue::QUEUE_OPTION );

		$this->assertSame( array( '111:0', $this->attachment_id . ':0' ), array_keys( $stored['waiting'] ) );
	}
}
