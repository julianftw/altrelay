<?php
/**
 * AltRelay Sync Engine.
 *
 * Keeps the alt text in post content equal to the alt text in the Media
 * Library. When _wp_attachment_image_alt changes on an attachment, a
 * background job is queued that rewrites the alt attribute in every post
 * that contains that image.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Sync
 *
 * @since 1.0.0
 */
class AltRelay_Sync {


	/**
	 * Number of post IDs fetched per query while propagating.
	 *
	 * Keeps memory flat on sites where one image is reused in thousands of posts.
	 *
	 * @since 1.0.0
	 */
	public const BATCH_SIZE = 200;

	/**
	 * Post statuses whose content AltRelay keeps in sync.
	 *
	 * Revisions ('inherit') and trash are deliberately excluded.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Transient that holds the summary of the most recent sync activity.
	 *
	 * @since 1.0.0
	 */
	public const LAST_SYNC_TRANSIENT = 'altrelay_last_sync';

	/**
	 * Transient that holds posts AltRelay failed to update.
	 *
	 * @since 1.0.0
	 */
	public const ERRORS_TRANSIENT = 'altrelay_sync_errors';

	/**
	 * Attachment meta that records an image left without alt text on purpose:
	 * it was marked decorative wherever it is used. It only tells the
	 * dashboard not to list the image as missing alt text; the marks in the
	 * posts are what makes it decorative. Removed when the image is given
	 * alt text.
	 *
	 * @since 1.0.0
	 */
	public const DECORATIVE_META = '_altrelay_decorative';

	/**
	 * Attachment meta that holds the progress of marking an image decorative
	 * wherever it is used, which runs in the background: who asked, where
	 * the next batch starts, and what was found so far. Removed once every
	 * copy is marked; kept with what was left unmarked otherwise, so the
	 * dashboard can say why the image is still listed.
	 *
	 * @since 1.0.0
	 */
	public const MARKING_META = '_altrelay_marking';

	/**
	 * Post meta that remembers, per attachment, the alt text a background
	 * update replaced while the post was open in an editor. That editor
	 * still holds the old text and would save it back; see
	 * refresh_stale_alts().
	 *
	 * @since 1.0.0
	 */
	public const STALE_META = '_altrelay_stale_alts';

	/**
	 * Seconds after which a remembered old alt text is no longer put right.
	 * An editor left open longer is reloaded long before that in practice.
	 */
	private const STALE_KEEP = DAY_IN_SECONDS;

	/**
	 * Seconds within which consecutive runs count as one sync on the dashboard.
	 *
	 * One alt change can take several background batches, and saving a page
	 * of images queues one job per image. Reporting only the last of them
	 * would undercount what just happened.
	 */
	private const LOG_WINDOW = 600;

	/**
	 * Most images the record of skipped posts names.
	 *
	 * @var int
	 */
	private const SKIPPED_IMAGES_KEPT = 100;

	/**
	 * Most posts the record of skipped posts holds.
	 *
	 * @var int
	 */
	private const SKIPPED_POSTS_KEPT = 1000;

	/**
	 * Post types that store block markup and can embed an image.
	 *
	 * The wp_block (synced patterns), wp_template and wp_template_part types
	 * are all registered with 'public' => false, so they have to be listed
	 * explicitly.
	 *
	 * @var string[]
	 */
	private const EXTRA_POST_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part' );

	/**
	 * The post fields whose markup a save could change, each with the last
	 * filter sanitize_post() runs on it. The first is "pre_{$field}".
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private const SAVED_FIELDS = array(
		'post_title'            => 'title_save_pre',
		'post_content'          => 'content_save_pre',
		'post_excerpt'          => 'excerpt_save_pre',
		'post_content_filtered' => 'content_filtered_save_pre',
	);

	/**
	 * Recursion guard to prevent infinite loops.
	 *
	 * Static, so a save made by one instance (the queue worker, WP-CLI) is
	 * also seen by the instance that listens for alt text changes.
	 *
	 * @var bool
	 */
	private static bool $is_syncing = false;

	/**
	 * Whether AltRelay was activated on single sites of a network, rather than
	 * network-wide or loaded as a must-use plugin. Only then can it be
	 * inactive on a site that code switches to. Set when the hooks are
	 * registered, on the site the request is for.
	 *
	 * @var bool
	 */
	private static bool $activated_per_site = false;

	/**
	 * Attachments currently being deleted, keyed by ID.
	 *
	 * @var array<int, true>
	 */
	private array $deleting = array();

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		self::$activated_per_site = is_multisite() && ! self::network_active() && self::active_in_site_list();

		add_action( 'updated_postmeta', array( $this, 'on_alt_meta_changed' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'on_alt_meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_alt_meta_deleted' ), 10, 4 );
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleting' ), 10, 1 );
		add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'refresh_stale_alts' ), 10, 2 );
	}

	/**
	 * Put the current alt text back into a post that an editor saves with
	 * the text a background update had replaced.
	 *
	 * An editor that was open while an image's Media Library alt text
	 * changed still shows the old text. AltRelay's script in the editor sees
	 * no change in that block, so it pushes nothing, and saving the post
	 * would bring the old text back. Each copy that still holds one of the
	 * texts the update replaced gets the current one instead. A copy whose
	 * text the editor changed to something else is left as typed, and the
	 * editor sends that to the Media Library as usual.
	 *
	 * The record goes away once a save holds none of the old texts, which
	 * happens when the editor is reloaded, and after STALE_KEEP.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data    Slashed post data about to be saved.
	 * @param mixed $postarr The data the save was asked for.
	 * @return mixed
	 */
	public function refresh_stale_alts( mixed $data, mixed $postarr ): mixed {
		if ( self::$is_syncing || ! is_array( $data ) || ! is_array( $postarr ) || empty( $postarr['ID'] ) || ! is_numeric( $postarr['ID'] ) || ! isset( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
			return $data;
		}

		$post_id = (int) $postarr['ID'];
		$stale   = self::stale_alts( $post_id );

		if ( empty( $stale ) ) {
			return $data;
		}

		$content = wp_unslash( $data['post_content'] );
		$kept    = array();

		foreach ( $stale as $attachment_id => $entry ) {
			$alt = self::library_alt( $attachment_id );

			if ( $entry['time'] < time() - self::STALE_KEEP || '' === $alt ) {
				continue;
			}

			$updated = AltRelay_Content::refresh_stale_alt( $content, $attachment_id, $alt, $entry['old'] );

			if ( $updated !== $content ) {
				$content                = $updated;
				$kept[ $attachment_id ] = $entry;
			}
		}

		if ( empty( $kept ) ) {
			delete_post_meta( $post_id, self::STALE_META );
		} elseif ( $kept !== $stale ) {
			update_post_meta( $post_id, self::STALE_META, $kept );
		}

		if ( ! empty( $kept ) ) {
			$data['post_content'] = wp_slash( $content );
		}

		return $data;
	}

	/**
	 * The old alt texts remembered for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array<int, array{old: list<string>, time: int}> By attachment ID.
	 */
	public static function stale_alts( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::STALE_META, true );
		$stale  = array();

		if ( ! is_array( $stored ) ) {
			return $stale;
		}

		foreach ( $stored as $attachment_id => $entry ) {
			if ( ! is_int( $attachment_id ) || ! is_array( $entry ) || ! isset( $entry['old'], $entry['time'] ) || ! is_array( $entry['old'] ) || ! is_numeric( $entry['time'] ) ) {
				continue;
			}

			$stale[ $attachment_id ] = array(
				'old'  => array_values( array_filter( $entry['old'], 'is_string' ) ),
				'time' => (int) $entry['time'],
			);
		}

		return $stale;
	}

	/**
	 * Remember the alt texts an update replaced in a post that is open in
	 * an editor.
	 *
	 * @param int                $post_id The post that was updated.
	 * @param string             $content Its content before the update.
	 * @param array<int, string> $written The alt text written, by attachment ID.
	 * @return void
	 */
	private static function remember_stale_alts( int $post_id, string $content, array $written ): void {
		$stale = self::stale_alts( $post_id );

		foreach ( AltRelay_Content::find_images( $content ) as $image ) {
			$attachment_id = $image['attachment_id'];
			$old           = $image['alt'];

			if ( ! isset( $written[ $attachment_id ] ) || $image['decorative'] || null === $old || $old === $written[ $attachment_id ] ) {
				continue;
			}

			// An empty alt that is left alone on purpose was not replaced.
			if ( '' === $old && AltRelay_Content::empty_alt_is_decorative( $attachment_id ) ) {
				continue;
			}

			$entry = $stale[ $attachment_id ] ?? array(
				'old'  => array(),
				'time' => 0,
			);

			if ( ! in_array( $old, $entry['old'], true ) ) {
				$entry['old'][] = $old;
			}

			$entry['time']           = time();
			$stale[ $attachment_id ] = $entry;
		}

		if ( ! empty( $stale ) ) {
			update_post_meta( $post_id, self::STALE_META, $stale );
		}
	}

	/**
	 * Whether a post is open in an editor right now: it holds an edit lock
	 * that the editor's heartbeat keeps fresh.
	 *
	 * WordPress's own wp_check_post_lock() is only loaded in the admin, and
	 * leaves out the current user, who may be the one with the editor open.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	private static function is_open_in_editor( int $post_id ): bool {
		$lock = get_post_meta( $post_id, '_edit_lock', true );

		if ( ! is_string( $lock ) || ! str_contains( $lock, ':' ) ) {
			return false;
		}

		list( $time, $user ) = explode( ':', $lock, 2 );

		/** This filter is documented in wp-admin/includes/post.php */
		$window = apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read the way core reads it.
		$window = is_numeric( $window ) ? (int) $window : 150;

		return (int) $user > 0 && (int) $time > time() - $window;
	}

	/**
	 * Remember that an attachment is being deleted.
	 *
	 * WordPress removes the attachment's meta after this action, which fires
	 * deleted_post_meta for the alt text. Treating that as "alt
	 * cleared" would queue a sitewide rewrite for an image that no longer
	 * exists, so it is skipped.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment being deleted.
	 * @return void
	 */
	public function on_attachment_deleting( int $attachment_id ): void {
		$this->deleting[ $attachment_id ] = true;
	}

	/**
	 * Callback for updated_postmeta and added_post_meta actions.
	 *
	 * Fires when _wp_attachment_image_alt is added or updated on an attachment.
	 * Queues the propagation of the new alt text.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $meta_id       ID of the metadata entry.
	 * @param int    $attachment_id Post ID (attachment).
	 * @param string $meta_key      Metadata key.
	 * @param mixed  $new_alt       New metadata value.
	 * @return void
	 */
	public function on_alt_meta_changed( int $meta_id, int $attachment_id, string $meta_key, mixed $new_alt ): void {
		if ( '_wp_attachment_image_alt' !== $meta_key ) {
			return;
		}

		// An image that is described is no longer left empty on purpose.
		if ( is_string( $new_alt ) && '' !== self::sanitize_alt( $new_alt ) ) {
			delete_post_meta( $attachment_id, self::DECORATIVE_META );
			delete_post_meta( $attachment_id, self::MARKING_META );
		}

		$this->maybe_schedule_sync( $attachment_id, is_string( $new_alt ) ? $new_alt : '' );
	}

	/**
	 * Callback for deleted_post_meta.
	 *
	 * Removing the alt meta is equivalent to clearing it, so the empty value is
	 * propagated to every post using the image.
	 *
	 * @since 1.0.0
	 *
	 * @param int[]  $meta_ids      Deleted metadata IDs.
	 * @param int    $attachment_id Post ID (attachment).
	 * @param string $meta_key      Metadata key.
	 * @param mixed  $meta_value    Deleted value. Unused; the new value is empty.
	 * @return void
	 */
	public function on_alt_meta_deleted( array $meta_ids, int $attachment_id, string $meta_key, mixed $meta_value ): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature is fixed by the hook.
		unset( $meta_value );

		if ( '_wp_attachment_image_alt' !== $meta_key || isset( $this->deleting[ $attachment_id ] ) ) {
			return;
		}

		$this->maybe_schedule_sync( $attachment_id, '' );
	}

	/**
	 * Validate the current context and hand the change to the queue.
	 *
	 * Only the attachment and the acting user are queued. The alt text itself
	 * is read when the job runs, so a job never carries a stale value and its
	 * size does not depend on the length of the text. The user travels with
	 * the job because the background worker runs without a logged-in user and
	 * must still only touch posts that user is allowed to edit.
	 *
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $new_alt       The new alt text, unescaped.
	 * @return void
	 */
	private function maybe_schedule_sync( int $attachment_id, string $new_alt ): void {
		if ( self::$is_syncing ) {
			return;
		}

		if ( ! $this->current_context_can_sync() || ! self::active_on_this_site() ) {
			return;
		}

		/**
		 * Filters whether AltRelay should propagate this alt text change.
		 *
		 * Return false to skip propagation for a specific attachment, for
		 * example when alt text is being imported from an external source.
		 *
		 * The filter decides what happens on its own when alt text changes.
		 * What someone asks for explicitly runs regardless: wp altrelay sync,
		 * the dashboard's Fill button and its "Update them now" button.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $should_sync   Whether to sync. Default true.
		 * @param int    $attachment_id The attachment post ID.
		 * @param string $new_alt       The new alt text.
		 */
		if ( ! apply_filters( 'altrelay_should_sync', true, $attachment_id, self::sanitize_alt( $new_alt ) ) ) {
			return;
		}

		AltRelay_Queue::enqueue( $attachment_id, get_current_user_id() );
	}

	/**
	 * Normalize alt text the same way on every code path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $alt Raw alt text.
	 * @return string
	 */
	public static function sanitize_alt( string $alt ): string {
		return sanitize_text_field( $alt );
	}

	/**
	 * The alt text stored for an attachment, normalized.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return string The alt text, or an empty string.
	 */
	public static function stored_alt( int $attachment_id ): string {
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return is_string( $alt ) && '' !== $alt ? self::sanitize_alt( $alt ) : '';
	}

	/**
	 * The alt text AltRelay writes for an attachment: the stored text after
	 * the altrelay_alt_text filter.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return string The alt text, or an empty string when the attachment has none.
	 */
	public static function library_alt( int $attachment_id ): string {
		$stored = self::stored_alt( $attachment_id );

		return '' === $stored ? '' : self::filter_alt( $stored, $attachment_id );
	}

	/**
	 * Run alt text through the altrelay_alt_text filter.
	 *
	 * @param string $alt           The alt text from the Media Library.
	 * @param int    $attachment_id The attachment post ID.
	 * @return string
	 */
	private static function filter_alt( string $alt, int $attachment_id ): string {
		/**
		 * Filters the alt text written into post content.
		 *
		 * Resolved once per attachment rather than once per post, so a filter
		 * with side effects does not run for every post that gets updated.
		 *
		 * @since 1.0.0
		 *
		 * @param string $alt           The alt text from the Media Library.
		 * @param int    $attachment_id The attachment post ID.
		 */
		return (string) apply_filters( 'altrelay_alt_text', $alt, $attachment_id );
	}

	/**
	 * Whether the current context is allowed to propagate alt text.
	 *
	 * Requests made by a logged-in user require the upload_files capability.
	 * Programmatic contexts (WP-CLI, cron, imports) have no current user and
	 * are allowed through so that bulk operations can sync.
	 *
	 * @return bool
	 */
	private function current_context_can_sync(): bool {
		if ( ! is_user_logged_in() ) {
			return true;
		}

		return current_user_can( 'upload_files' );
	}

	/**
	 * Whether AltRelay is active on the current site.
	 *
	 * Always, unless it was activated site by site and code has switched to
	 * another site of the network, one where it is not active. Nothing would
	 * run a job queued there.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function active_on_this_site(): bool {
		if ( ! self::$activated_per_site || ! ms_is_switched() ) {
			return true;
		}

		return self::network_active() || self::active_in_site_list();
	}

	/**
	 * Whether AltRelay is active on the whole network.
	 *
	 * @return bool
	 */
	private static function network_active(): bool {
		$network = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $network ) && isset( $network[ ALTRELAY_PLUGIN_BASENAME ] );
	}

	/**
	 * Whether the current site lists AltRelay among its active plugins.
	 *
	 * @return bool
	 */
	private static function active_in_site_list(): bool {
		return in_array( ALTRELAY_PLUGIN_BASENAME, (array) get_option( 'active_plugins', array() ), true );
	}

	/**
	 * Propagate a specific alt text value to every post using an attachment.
	 *
	 * Public entry point for the queue worker, WP-CLI and other programmatic
	 * callers that need to sync without going through the postmeta hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attachment_id The attachment post ID.
	 * @param string   $new_alt       The alt text to write.
	 * @param bool     $dry_run       When true, nothing is written.
	 * @param int      $after_id      Resume after this post ID.
	 * @param int      $limit         Maximum number of posts to process. 0 = all.
	 * @param int      $user_id       Only touch posts this user can edit. 0 = the current user, or no check without one.
	 * @param string[] $post_types    Only these post types. Empty = every type AltRelay syncs.
	 * @return array{updated: int, skipped: int, failed: int, last_id: int, done: bool, post_ids: list<int>} Progress report.
	 */
	public function sync_attachment( int $attachment_id, string $new_alt, bool $dry_run = false, int $after_id = 0, int $limit = 0, int $user_id = 0, array $post_types = array() ): array {
		if ( self::$is_syncing ) {
			return self::empty_report( $after_id );
		}

		$types = $this->resolve_post_types( $post_types );

		if ( empty( $types ) ) {
			return self::empty_report( $after_id );
		}

		$alt_text = self::filter_alt( $new_alt, $attachment_id );

		self::$is_syncing = true;

		try {
			return $this->run(
				fn( int $cursor ): ?array => $this->find_post_ids( 'wp-image-' . $attachment_id, 'wp-image-' . $attachment_id . '([^0-9]|$)', $cursor, $types ),
				static function ( string $content ) use ( $attachment_id, $alt_text ): array {
					$updated = AltRelay_Content::rewrite_attachment_alt( $content, $attachment_id, $alt_text );

					return array( $updated, $updated === $content ? array() : array( $attachment_id => $alt_text ) );
				},
				$dry_run,
				$after_id,
				$limit,
				$user_id,
				$types,
				$attachment_id
			);
		} finally {
			self::$is_syncing = false;
		}
	}

	/**
	 * Bring every post in line with the Media Library, one save per post.
	 *
	 * Syncing image by image saves a post with ten images ten times, with
	 * ten revisions. This pass visits each post once and writes every image
	 * that has alt text in the Media Library.
	 *
	 * @since 1.0.0
	 *
	 * @param bool     $dry_run    When true, nothing is written.
	 * @param int      $after_id   Resume after this post ID.
	 * @param int      $limit      Maximum number of posts to process. 0 = all.
	 * @param int      $user_id    Only touch posts this user can edit. 0 = the current user, or no check without one.
	 * @param string[] $post_types Only these post types. Empty = every type AltRelay syncs.
	 * @return array{updated: int, skipped: int, failed: int, last_id: int, done: bool, post_ids: list<int>} Progress report.
	 */
	public function sync_posts( bool $dry_run = false, int $after_id = 0, int $limit = 0, int $user_id = 0, array $post_types = array() ): array {
		if ( self::$is_syncing ) {
			return self::empty_report( $after_id );
		}

		$types = $this->resolve_post_types( $post_types );

		if ( empty( $types ) ) {
			return self::empty_report( $after_id );
		}

		/**
		 * Alt text per attachment, null for attachments without any.
		 *
		 * @var array<int, string|null> $alts
		 */
		$alts = array();

		$resolver = static function ( int $attachment_id ) use ( &$alts ): ?string {
			if ( ! array_key_exists( $attachment_id, $alts ) ) {
				$alt                    = self::library_alt( $attachment_id );
				$alts[ $attachment_id ] = '' === $alt ? null : $alt;
			}

			return $alts[ $attachment_id ];
		};

		self::$is_syncing = true;

		try {
			return $this->run(
				fn( int $cursor ): ?array => $this->find_post_ids( 'wp-image-', '', $cursor, $types ),
				static function ( string $content ) use ( $resolver, &$alts ): array {
					// One query for the meta of every image in the post
					// instead of one per image.
					if ( preg_match_all( '/wp-image-(\d+)/', $content, $found ) ) {
						$unknown = array_values( array_diff( array_unique( array_map( 'absint', $found[1] ) ), array_keys( $alts ) ) );

						if ( ! empty( $unknown ) ) {
							update_meta_cache( 'post', $unknown );
						}
					}

					$changed_ids = array();
					$updated     = AltRelay_Content::rewrite_alts( $content, $resolver, null, $changed_ids );
					$written     = array();

					foreach ( $changed_ids as $changed_id ) {
						$written[ $changed_id ] = (string) $resolver( $changed_id );
					}

					return array( $updated, $written );
				},
				$dry_run,
				$after_id,
				$limit,
				$user_id,
				$types
			);
		} finally {
			self::$is_syncing = false;
		}
	}

	/**
	 * Write the Media Library alt text into one post's copies of an image.
	 *
	 * Used by the dashboard review and WP-CLI. Fills empty alts even on sites
	 * that treat empty alt as decorative, because the user asked for it.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $post_id       The post to update.
	 * @param int  $attachment_id The attachment post ID.
	 * @param bool $dry_run       Optional. When true, nothing is saved. Default false.
	 * @return bool Whether the post changed, or would change.
	 */
	public function fill_post( int $post_id, int $attachment_id, bool $dry_run = false ): bool {
		$alt_text = self::library_alt( $attachment_id );

		if ( '' === $alt_text ) {
			return false;
		}

		return $this->update_post_markup(
			$post_id,
			static fn( string $content ): string => AltRelay_Content::rewrite_attachment_alt( $content, $attachment_id, $alt_text, false ),
			$dry_run
		);
	}

	/**
	 * Mark one post's copies of an image as decorative.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $post_id       The post to update.
	 * @param int  $attachment_id The attachment post ID.
	 * @param bool $dry_run       Optional. When true, nothing is saved. Default false.
	 * @return array{changed: bool, refused: int} Whether the post changed, and how many
	 *                                            linked copies were left alone.
	 */
	public function mark_post_decorative( int $post_id, int $attachment_id, bool $dry_run = false ): array {
		$refused = 0;

		$changed = $this->update_post_markup(
			$post_id,
			static function ( string $content ) use ( $attachment_id, &$refused ): string {
				return AltRelay_Content::mark_decorative( $content, $attachment_id, $refused );
			},
			$dry_run
		);

		return array(
			'changed' => $changed,
			'refused' => $refused,
		);
	}

	/**
	 * Mark an image decorative wherever it is used, for an image whose Media
	 * Library alt text is empty on purpose, in one go.
	 *
	 * WordPress suggests leaving an image's alt text empty in the Media
	 * Library when the image is decorative, but an empty field can also mean
	 * "not described yet". This turns the first meaning into marks: every
	 * copy of the image in a post the current user may edit is marked. When
	 * every copy is marked, or the image is not used anywhere yet, the image
	 * is recorded as decorative, and the dashboard no longer lists it as
	 * missing alt text. A copy that is the only content of a link is refused,
	 * as always, and keeps the image on that list: it needs a description.
	 *
	 * The dashboard does the same work in the background, with
	 * start_marking_decorative(); this is for code that may run as long as
	 * it needs, such as WP-CLI.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return array{marked: int, refused: int, skipped: int, failed: int, recorded: bool} Posts marked; linked copies refused;
	 *         posts with unmarked copies the user cannot edit; posts that could not be saved; whether the image was recorded.
	 */
	public function mark_attachment_decorative( int $attachment_id ): array {
		$user_id = get_current_user_id();
		$started = $this->start_marking_decorative( $attachment_id, $user_id, false );
		$report  = array(
			'marked'   => 0,
			'refused'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'recorded' => 'recorded' === $started,
		);

		if ( 'started' !== $started ) {
			return $report;
		}

		$state = $this->mark_batch( $attachment_id, 0, 0, $user_id );

		if ( null === $state ) {
			return $report;
		}

		return array(
			'marked'   => $state['marked'],
			'refused'  => $state['refused'],
			'skipped'  => $state['skipped'],
			'failed'   => $state['failed'],
			'recorded' => self::is_recorded_decorative( $attachment_id ),
		);
	}

	/**
	 * Start marking an image decorative wherever it is used.
	 *
	 * An image used nowhere is recorded at once. Otherwise the progress is
	 * kept on the image and, unless told otherwise, a background job is
	 * queued: the queue worker hands each of its batches to
	 * continue_marking(), so an image used in thousands of posts never needs
	 * one long request. Starting again, while a run is under way or after
	 * one left copies unmarked, starts over from the first post.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $attachment_id The attachment post ID.
	 * @param int  $user_id       The user who asked; only posts they can edit are marked.
	 * @param bool $queue         Whether to queue the work. Default true.
	 * @return string 'recorded' when the image is used nowhere and was recorded at once,
	 *                'started' when the work was started, '' when the image is not one without alt text.
	 */
	public function start_marking_decorative( int $attachment_id, int $user_id, bool $queue = true ): string {
		if ( 'attachment' !== get_post_type( $attachment_id ) || '' !== self::stored_alt( $attachment_id ) ) {
			return '';
		}

		$types = $this->resolve_post_types( array() );
		$first = empty( $types ) ? array() : $this->find_post_ids( 'wp-image-' . $attachment_id, 'wp-image-' . $attachment_id . '([^0-9]|$)', 0, $types );

		if ( array() === $first ) {
			delete_post_meta( $attachment_id, self::MARKING_META );
			update_post_meta( $attachment_id, self::DECORATIVE_META, '1' );
			return 'recorded';
		}

		update_post_meta(
			$attachment_id,
			self::MARKING_META,
			array(
				'user'    => max( 0, $user_id ),
				'next'    => 0,
				'marked'  => 0,
				'refused' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'done'    => false,
			)
		);

		if ( $queue ) {
			AltRelay_Queue::enqueue( $attachment_id, $user_id );
		}

		return 'started';
	}

	/**
	 * Where marking an image decorative stands.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return array{user: int, next: int, marked: int, refused: int, skipped: int, failed: int, done: bool}|null
	 *         Null when the image is not being marked, and no run left copies unmarked.
	 */
	public static function marking_state( int $attachment_id ): ?array {
		$stored = get_post_meta( $attachment_id, self::MARKING_META, true );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$state = array( 'done' => ! empty( $stored['done'] ) );

		foreach ( array( 'user', 'next', 'marked', 'refused', 'skipped', 'failed' ) as $key ) {
			$state[ $key ] = isset( $stored[ $key ] ) && is_numeric( $stored[ $key ] ) ? max( 0, (int) $stored[ $key ] ) : 0;
		}

		return array(
			'user'    => $state['user'],
			'next'    => $state['next'],
			'marked'  => $state['marked'],
			'refused' => $state['refused'],
			'skipped' => $state['skipped'],
			'failed'  => $state['failed'],
			'done'    => $state['done'],
		);
	}

	/**
	 * Mark the next batch of posts, when a job is the one that carries on
	 * marking an image decorative.
	 *
	 * Called by the queue worker for every job. A job counts only when it
	 * belongs to the user who asked and starts where the last batch ended,
	 * so a sync batch of the same image that was already under way goes on
	 * as a sync. When the image was given alt text meanwhile, marking stops
	 * and the job syncs the new text instead.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $after_id      Resume after this post ID.
	 * @param int $limit         Maximum number of posts to process.
	 * @param int $user_id       The user the job belongs to.
	 * @return int|null Where the next batch starts, 0 when marking is finished,
	 *                  or null when the job is not one that marks.
	 */
	public function continue_marking( int $attachment_id, int $after_id, int $limit, int $user_id ): ?int {
		$state = $this->mark_batch( $attachment_id, $after_id, $limit, $user_id );

		if ( null === $state ) {
			return null;
		}

		return $state['done'] ? 0 : $state['next'];
	}

	/**
	 * Mark one batch of an image's copies and keep count.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $after_id      Resume after this post ID.
	 * @param int $limit         Maximum number of posts to process. 0 = all.
	 * @param int $user_id       The user the work belongs to.
	 * @return array{user: int, next: int, marked: int, refused: int, skipped: int, failed: int, done: bool}|null
	 *         The progress after this batch, or null when there was nothing to mark for this user from there.
	 */
	private function mark_batch( int $attachment_id, int $after_id, int $limit, int $user_id ): ?array {
		$state = self::marking_state( $attachment_id );

		if ( null === $state || $state['done'] || $state['user'] !== $user_id || $state['next'] !== $after_id || self::$is_syncing ) {
			return null;
		}

		if ( '' !== self::stored_alt( $attachment_id ) ) {
			delete_post_meta( $attachment_id, self::MARKING_META );
			return null;
		}

		$types   = $this->resolve_post_types( array() );
		$refused = 0;
		$report  = self::empty_report( $after_id );

		if ( ! empty( $types ) ) {
			self::$is_syncing = true;

			try {
				$report = $this->run(
					fn( int $cursor ): ?array => $this->find_post_ids( 'wp-image-' . $attachment_id, 'wp-image-' . $attachment_id . '([^0-9]|$)', $cursor, $types ),
					static function ( string $content ) use ( $attachment_id, &$refused ): array {
						$linked   = 0;
						$marked   = AltRelay_Content::mark_decorative( $content, $attachment_id, $linked );
						$refused += $linked;

						return array( $marked, array() );
					},
					false,
					$after_id,
					$limit,
					$user_id,
					$types,
					$attachment_id
				);
			} finally {
				self::$is_syncing = false;
			}
		}

		$state['marked']  += $report['updated'];
		$state['refused'] += $refused;
		$state['skipped'] += $report['skipped'];
		$state['failed']  += $report['failed'];

		if ( ! $report['done'] ) {
			$state['next'] = max( 1, $report['last_id'] );
			update_post_meta( $attachment_id, self::MARKING_META, $state );

			return $state;
		}

		$state['next'] = 0;
		$state['done'] = true;

		if ( 0 === $state['refused'] + $state['skipped'] + $state['failed'] ) {
			delete_post_meta( $attachment_id, self::MARKING_META );
			update_post_meta( $attachment_id, self::DECORATIVE_META, '1' );
		} else {
			update_post_meta( $attachment_id, self::MARKING_META, $state );
		}

		return $state;
	}

	/**
	 * Whether an image is recorded as decorative wherever it is used.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return bool
	 */
	public static function is_recorded_decorative( int $attachment_id ): bool {
		$recorded = get_post_meta( $attachment_id, self::DECORATIVE_META, true );

		return is_string( $recorded ) && '' !== $recorded;
	}

	/**
	 * List an image as missing alt text again.
	 *
	 * Only the record is removed. The marks in the posts stay; the
	 * dashboard's list of decorative images removes them one by one.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return bool Whether the image was recorded as decorative.
	 */
	public static function forget_recorded_decorative( int $attachment_id ): bool {
		return delete_post_meta( $attachment_id, self::DECORATIVE_META );
	}

	/**
	 * List an image as missing alt text again once a post uses it without
	 * a mark.
	 *
	 * The record says every copy of the image is marked decorative. A copy
	 * added later, without the mark and without alt text, would otherwise
	 * stay unnoticed: the image returns to the dashboard's list, where
	 * "Mark decorative" marks the new copy too.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $post_id The post that was saved.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function on_post_saved( int $post_id, WP_Post $post ): void {
		if ( self::$is_syncing || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, $this->get_allowed_post_types(), true ) ) {
			return;
		}

		$content = (string) $post->post_content;

		if ( ! preg_match_all( '/wp-image-(\d+)/', $content, $found ) ) {
			return;
		}

		$recorded = array();
		$ids      = array_values( array_filter( array_unique( array_map( 'absint', $found[1] ) ) ) );

		// One query for the meta of every image, not one per image.
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $attachment_id ) {
			if ( self::is_recorded_decorative( $attachment_id ) ) {
				$recorded[ $attachment_id ] = true;
			}
		}

		if ( empty( $recorded ) ) {
			return;
		}

		foreach ( AltRelay_Content::find_images( $content ) as $image ) {
			if ( isset( $recorded[ $image['attachment_id'] ] ) && ! $image['decorative'] && ( null === $image['alt'] || '' === $image['alt'] ) ) {
				self::forget_recorded_decorative( $image['attachment_id'] );
				unset( $recorded[ $image['attachment_id'] ] );
			}
		}
	}

	/**
	 * Remove the decorative mark from one post's copies of an image and give
	 * them the Media Library alt text back.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $post_id       The post to update.
	 * @param int  $attachment_id The attachment post ID.
	 * @param bool $dry_run       Optional. When true, nothing is saved. Default false.
	 * @return bool Whether the post changed, or would change.
	 */
	public function unmark_post_decorative( int $post_id, int $attachment_id, bool $dry_run = false ): bool {
		$alt_text = self::library_alt( $attachment_id );

		return $this->update_post_markup(
			$post_id,
			static function ( string $content ) use ( $attachment_id, $alt_text ): string {
				$unmarked = AltRelay_Content::unmark_decorative( $content, $attachment_id );

				if ( $unmarked === $content || '' === $alt_text ) {
					return $unmarked;
				}

				return AltRelay_Content::rewrite_attachment_alt( $unmarked, $attachment_id, $alt_text, false );
			},
			$dry_run
		);
	}

	/**
	 * Apply a markup transformation to one post and save it if it changed.
	 *
	 * @param int                      $post_id   The post to update.
	 * @param callable(string): string $transform Transformation.
	 * @param bool                     $dry_run   When true, nothing is saved.
	 * @return bool Whether the post changed, or would change.
	 */
	private function update_post_markup( int $post_id, callable $transform, bool $dry_run = false ): bool {
		// The same posts a sync would reach: a revision, or a type or status
		// AltRelay is told to leave alone, is not changed through a crafted
		// request either.
		if ( self::$is_syncing || ! $this->is_syncable_post( $post_id ) ) {
			return false;
		}

		$content = self::raw_content( $post_id );

		if ( '' === $content ) {
			return false;
		}

		$updated = $transform( $content );

		if ( $updated === $content ) {
			return false;
		}

		if ( $dry_run ) {
			return true;
		}

		self::$is_syncing = true;

		try {
			$result = $this->save_post_content( $post_id, $updated );
		} finally {
			self::$is_syncing = false;
		}

		if ( is_wp_error( $result ) ) {
			self::record_error( $post_id, $result->get_error_message() );
			return false;
		}

		return true;
	}

	/**
	 * Whether a post is one AltRelay syncs: an allowed post type in an
	 * allowed status.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	private function is_syncable_post( int $post_id ): bool {
		$type   = get_post_type( $post_id );
		$status = get_post_status( $post_id );

		return is_string( $type ) && is_string( $status )
			&& in_array( $type, $this->get_allowed_post_types(), true )
			&& in_array( $status, self::statuses(), true );
	}

	/**
	 * A post's content exactly as stored.
	 *
	 * The 'raw' context matters: the default one runs the content through
	 * display filters, and the output of those would then be saved.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return string
	 */
	public static function raw_content( int $post_id ): string {
		$content = get_post_field( 'post_content', $post_id, 'raw' );

		return is_string( $content ) ? $content : '';
	}

	/**
	 * An empty progress report.
	 *
	 * @param int $after_id The cursor to report.
	 * @return array{updated: int, skipped: int, failed: int, last_id: int, done: bool, post_ids: list<int>}
	 */
	private static function empty_report( int $after_id ): array {
		return array(
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'last_id'  => $after_id,
			'done'     => true,
			'post_ids' => array(),
		);
	}

	/**
	 * Page through posts and rewrite each one.
	 *
	 * @param callable $find     Returns the next page of post IDs after a cursor, or null when the query failed.
	 * @param callable $rewrite  Returns the new content and the alt text written per attachment.
	 * @param bool     $dry_run  When true, nothing is written.
	 * @param int      $after_id Resume after this post ID.
	 * @param int      $limit    Maximum number of posts to process. 0 = all.
	 * @param int      $user_id  Only touch posts this user can edit. 0 = no specific user.
	 * @param string[] $types    Post types that may be written to.
	 * @param int      $attachment_id The image being synced, or 0 for a sync of every image.
	 *
	 * @phpstan-param callable(int): (list<int>|null)                           $find
	 * @phpstan-param callable(string): array{0: string, 1: array<int, string>} $rewrite
	 *
	 * @return array{updated: int, skipped: int, failed: int, last_id: int, done: bool, post_ids: list<int>} Progress report.
	 */
	private function run( callable $find, callable $rewrite, bool $dry_run, int $after_id, int $limit, int $user_id, array $types, int $attachment_id = 0 ): array {
		$report     = self::empty_report( $after_id );
		$processed  = 0;
		$batch_full = true;
		$skipped    = array();

		// Page through matches by ID so a single image reused in thousands of
		// posts does not load every post into memory at once.
		while ( $batch_full ) {
			$post_ids = $find( $report['last_id'] );

			if ( null === $post_ids ) {
				++$report['failed'];
				break;
			}

			$batch_full = ( self::BATCH_SIZE === count( $post_ids ) );

			foreach ( $post_ids as $post_id ) {
				// Stop before consuming this ID so the next batch resumes at it.
				// Advancing the cursor first would skip one post per batch.
				if ( $limit > 0 && $processed >= $limit ) {
					$report['done'] = false;
					break 2;
				}

				$report['last_id'] = $post_id;
				++$processed;

				// Defence in depth: the query already filters by type, but a
				// post type can change between the query and the write.
				if ( ! in_array( get_post_type( $post_id ), $types, true ) ) {
					continue;
				}

				$result = $this->sync_post( $post_id, $rewrite, $dry_run, $user_id, $attachment_id );

				if ( 'updated' === $result ) {
					++$report['updated'];
					$report['post_ids'][] = $post_id;
				} elseif ( 'skipped' === $result ) {
					++$report['skipped'];
					$skipped[] = $post_id;
				} elseif ( 'failed' === $result ) {
					++$report['failed'];
				}
			}
		}

		if ( ( $report['updated'] > 0 || $report['skipped'] > 0 ) && ! $dry_run ) {
			$this->log_sync_event( $report['post_ids'], $skipped, $attachment_id );
		}

		return $report;
	}

	/**
	 * Whether the acting user may edit a post.
	 *
	 * @param int $post_id The post ID.
	 * @param int $user_id The user who made the change, or 0.
	 * @return bool
	 */
	private function can_edit( int $post_id, int $user_id ): bool {
		if ( $user_id > 0 ) {
			return user_can( $user_id, 'edit_post', $post_id );
		}

		if ( is_user_logged_in() ) {
			return current_user_can( 'edit_post', $post_id );
		}

		// No user at all: WP-CLI without --user, cron, imports.
		return true;
	}

	/**
	 * Rewrite the alt text of one post.
	 *
	 * @param int      $post_id The post to update.
	 * @param callable $rewrite Returns the new content and the alt text written per attachment.
	 * @param bool     $dry_run When true, nothing is written.
	 * @param int      $user_id The user who made the change, or 0.
	 * @param int      $attachment_id The image being synced, or 0 for a sync of every image.
	 *
	 * @phpstan-param callable(string): array{0: string, 1: array<int, string>} $rewrite
	 *
	 * @return string 'updated', 'skipped', 'failed' or 'unchanged'.
	 */
	private function sync_post( int $post_id, callable $rewrite, bool $dry_run, int $user_id, int $attachment_id = 0 ): string {
		$content = self::raw_content( $post_id );

		if ( '' === $content ) {
			return 'unchanged';
		}

		list( $updated_content, $written ) = $rewrite( $content );

		if ( $updated_content === $content ) {
			return 'unchanged';
		}

		// Checked only now, so a post counts as skipped only when it would
		// have changed: it is the one that keeps the old alt text.
		if ( ! $this->can_edit( $post_id, $user_id ) ) {
			return 'skipped';
		}

		if ( $dry_run ) {
			return 'updated';
		}

		// Another request, a second image's job or a "Run now", may have
		// saved the post since it was read. Its change is kept by starting
		// again from what is stored now.
		clean_post_cache( $post_id );
		$stored = self::raw_content( $post_id );

		if ( $stored !== $content && '' !== $stored ) {
			$content                           = $stored;
			list( $updated_content, $written ) = $rewrite( $content );

			if ( $updated_content === $content ) {
				return 'unchanged';
			}
		}

		$open = ! empty( $written ) && self::is_open_in_editor( $post_id );

		// Code that runs while a post is saved, on save_post for example, can
		// throw. That is this post's problem: the image's other posts are
		// still updated, and the dashboard offers to try this one again.
		try {
			try {
				$result = $this->save_post_content( $post_id, $updated_content );
			} catch ( Throwable $error ) {
				// Code that runs once the post is saved threw. When the new
				// content is stored all the same, the post was updated.
				clean_post_cache( $post_id );

				if ( self::raw_content( $post_id ) !== $updated_content ) {
					self::record_error( $post_id, $error->getMessage(), $attachment_id );
					return 'failed';
				}

				$result = true;
			}

			if ( is_wp_error( $result ) ) {
				self::record_error( $post_id, $result->get_error_message(), $attachment_id );
				return 'failed';
			}

			// The editor that has the post open still shows the old text.
			if ( $open ) {
				self::remember_stale_alts( $post_id, $content, $written );
			}

			foreach ( $written as $written_id => $alt_text ) {
				/**
				 * Fires after AltRelay rewrote a post's alt text.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $post_id       The post that was updated.
				 * @param int    $attachment_id The attachment whose alt text was synced.
				 * @param string $alt_text      The alt text that was written.
				 */
				do_action( 'altrelay_post_synced', $post_id, $written_id, $alt_text );
			}
		} catch ( Throwable $error ) {
			self::record_error( $post_id, $error->getMessage(), $attachment_id );
			return 'failed';
		}

		return 'updated';
	}

	/**
	 * Save rewritten content without letting save filters change the rest of
	 * the post.
	 *
	 * The wp_update_post() call loads the whole post and saves every field
	 * again, and sanitize_post() runs each one through "pre_{$field}" and
	 * "{$field}_save_pre". Those filters are meant for what a person submits,
	 * not for what is already stored, and several change markup: kses for
	 * anyone without unfiltered_html (cron, Action Scheduler, imports, WP-CLI
	 * run as such a --user, and every user but Super Admins on multisite),
	 * the removal of block custom CSS for anyone without edit_css (WordPress
	 * 7.0 and newer), trim() on titles and convert_invalid_entities() on
	 * content. Re-saving a post through them would strip iframes, forms and
	 * custom CSS from the content, <sup> from the title, and embeds from the
	 * excerpt.
	 *
	 * So every filter stays in place and runs as usual, and AltRelay's save
	 * hands each field back unchanged: the value is noted at the lowest
	 * priority of the first filter and put back at the highest priority of
	 * the last, but only when it is exactly the value being saved. That is
	 * what the post already holds, apart from the content's alt attributes,
	 * marker class and decorative role, which the HTML API escapes. Anything
	 * else saved in the same request, for example by code on save_post, is
	 * filtered as usual. The same strings pass through the revision
	 * WordPress saves next, so the revision matches the post.
	 *
	 * @param int    $post_id The post to update.
	 * @param string $content The new post content.
	 * @return true|WP_Error
	 */
	private function save_post_content( int $post_id, string $content ): bool|WP_Error {
		$slashed = wp_slash( $content );

		/**
		 * For every field, whether each value now passing through its filters
		 * is the one being saved. A list, because a save inside a filter
		 * would start a second pass before the first one ends.
		 *
		 * @var array<string, list<bool>> $marks
		 */
		$marks = array();
		$hooks = array();

		foreach ( self::SAVED_FIELDS as $field => $last_filter ) {
			// wp_update_post() slashes the stored fields before it saves them
			// again, so that is the form in which they reach the filters.
			$exempt = 'post_content' === $field ? $slashed : wp_slash( self::raw_field( $post_id, $field ) );

			$note = static function ( mixed $data ) use ( $field, $exempt, &$marks ): mixed {
				$marks[ $field ][] = $data === $exempt;

				return $data;
			};

			$restore = static function ( mixed $data ) use ( $field, $exempt, &$marks ): mixed {
				$ours = ! empty( $marks[ $field ] ) && array_pop( $marks[ $field ] );

				return $ours ? $exempt : $data;
			};

			add_filter( 'pre_' . $field, $note, PHP_INT_MIN );
			add_filter( $last_filter, $restore, PHP_INT_MAX );

			$hooks[] = array( 'pre_' . $field, $note, PHP_INT_MIN );
			$hooks[] = array( $last_filter, $restore, PHP_INT_MAX );
		}

		$keep_dates = $this->keep_modified_date( $post_id );

		try {
			// wp_update_post() keeps revisions, fires save_post, and refreshes
			// the object cache. The is_syncing guard prevents the resulting
			// hooks from re-entering this class.
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $slashed,
				),
				true
			);
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook[0], $hook[1], $hook[2] );
			}

			if ( null !== $keep_dates ) {
				remove_filter( 'wp_insert_post_data', $keep_dates, 99 );
			}
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * The post statuses AltRelay syncs and reviews.
	 *
	 * Trashed posts, auto-drafts and revisions are never touched. Sites with
	 * statuses of their own can add them.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public static function statuses(): array {
		/**
		 * Filters the post statuses whose content AltRelay syncs and lists for review.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $statuses Post statuses. Default: publish, draft, pending, private, future.
		 */
		$statuses = self::usable_statuses( apply_filters( 'altrelay_post_statuses', self::STATUSES ) );

		return empty( $statuses ) ? self::STATUSES : $statuses;
	}

	/**
	 * Keep the statuses from a filter that AltRelay may write to.
	 *
	 * A filter can return anything. Trash, auto-drafts and the status of
	 * revisions and attachments are never synced.
	 *
	 * @param mixed $statuses What the filter returned.
	 * @return list<string>
	 */
	private static function usable_statuses( mixed $statuses ): array {
		$usable = array();

		foreach ( is_array( $statuses ) ? $statuses : array() as $status ) {
			if ( is_string( $status ) && '' !== $status && ! in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) && ! in_array( $status, $usable, true ) ) {
				$usable[] = $status;
			}
		}

		return $usable;
	}

	/**
	 * A post field as stored, without display filters.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $field   The field, for example 'post_title'.
	 * @return string
	 */
	private static function raw_field( int $post_id, string $field ): string {
		$value = get_post_field( $field, $post_id, 'raw' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Keep a post's modified date through the next save, if a filter asks for it.
	 *
	 * @param int $post_id The post about to be saved.
	 * @return (callable(mixed, mixed): mixed)|null The hooked callback, to be removed after the save.
	 */
	private function keep_modified_date( int $post_id ): ?callable {
		/**
		 * Filters whether a sync updates the post's modified date.
		 *
		 * By default a synced post gets a new modified date, like after any
		 * other edit. Return false to keep the previous date, for example
		 * when "last updated" is shown to visitors or feeds a sitemap.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $update  Whether to update post_modified. Default true.
		 * @param int  $post_id The post being saved.
		 */
		if ( apply_filters( 'altrelay_update_post_modified', true, $post_id ) ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( null === $post ) {
			return null;
		}

		$modified     = $post->post_modified;
		$modified_gmt = $post->post_modified_gmt;

		$keep_dates = static function ( mixed $data, mixed $postarr ) use ( $post_id, $modified, $modified_gmt ): mixed {
			if ( is_array( $data ) && is_array( $postarr ) && isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) && (int) $postarr['ID'] === $post_id ) {
				$data['post_modified']     = $modified;
				$data['post_modified_gmt'] = $modified_gmt;
			}

			return $data;
		};

		add_filter( 'wp_insert_post_data', $keep_dates, 99, 2 );

		return $keep_dates;
	}

	/**
	 * Find IDs of posts whose content contains a string.
	 *
	 * The LIKE clause is a cheap prefilter so the database only evaluates the
	 * REGEXP on rows that can match. The pattern uses a digit boundary so
	 * that wp-image-42 does not match wp-image-420.
	 *
	 * @param string   $needle     Text the content must contain.
	 * @param string   $pattern    Regular expression the content must match, or an empty string.
	 * @param int      $after_id   Only return posts with an ID greater than this.
	 * @param string[] $post_types Post types to search.
	 * @return list<int>|null Matching post IDs, ordered by ID, or null when the query failed.
	 */
	private function find_post_ids( string $needle, string $pattern, int $after_id, array $post_types ): ?array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$like     = '%' . $wpdb->esc_like( $needle ) . '%';
		$statuses = self::statuses();
		$tail     = array_merge( $post_types, $statuses, array( $after_id, self::BATCH_SIZE ) );

		// The statement is written out once per case rather than put together
		// from variables. Every part of it is then either a literal or a list
		// of placeholders counted on the spot, which a reader, and the
		// WordPress Plugin Check, can verify at a glance.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The posts to rewrite are looked up page by page and must be current.
		if ( '' === $pattern ) {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_content LIKE %s'
					. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')'
					. ' AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
					. ' AND ID > %d ORDER BY ID ASC LIMIT %d',
					array_merge( array( $wpdb->posts, $like ), $tail )
				)
			);
		} else {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_content LIKE %s AND post_content REGEXP %s'
					. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')'
					. ' AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
					. ' AND ID > %d ORDER BY ID ASC LIMIT %d',
					array_merge( array( $wpdb->posts, $like, $pattern ), $tail )
				)
			);
		}
		// phpcs:enable

		if ( '' !== $wpdb->last_error ) {
			self::record_error( 0, $wpdb->last_error );
			return null;
		}

		$ids = array();
		foreach ( $post_ids as $post_id ) {
			if ( ! is_scalar( $post_id ) ) {
				continue;
			}

			$id = absint( $post_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Post types AltRelay is allowed to write to.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Sanitized post type slugs.
	 */
	public function get_allowed_post_types(): array {
		/**
		 * Filters which post types AltRelay searches for embedded images.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types Post types to include.
		 */
		$post_types = apply_filters( 'altrelay_post_types', $this->get_syncable_post_types() );

		return array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
	}

	/**
	 * Narrow the allowed post types to the ones a caller asked for.
	 *
	 * A requested type AltRelay does not sync is dropped rather than added,
	 * so a caller can never widen the set.
	 *
	 * @param string[] $requested Requested post types. Empty = all allowed types.
	 * @return string[]
	 */
	private function resolve_post_types( array $requested ): array {
		$allowed = $this->get_allowed_post_types();

		if ( empty( $requested ) ) {
			return $allowed;
		}

		return array_values( array_intersect( $allowed, array_map( 'sanitize_key', $requested ) ) );
	}

	/**
	 * Post types that can contain synced images.
	 *
	 * Includes the non-public block post types, because synced patterns and
	 * templates are one of the most common ways an image gets reused.
	 *
	 * @return string[]
	 */
	private function get_syncable_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ) );

		// Attachments are handled separately and never contain post content.
		unset( $post_types['attachment'] );

		foreach ( self::EXTRA_POST_TYPES as $extra_type ) {
			if ( post_type_exists( $extra_type ) ) {
				$post_types[ $extra_type ] = $extra_type;
			}
		}

		return array_values( $post_types );
	}

	/**
	 * Record what the last sync did, for the dashboard and WP-CLI.
	 *
	 * One change of alt text is synced in several batches, often in several
	 * requests. Runs within a few minutes of each other are added up, so the
	 * dashboard reports the whole change, not its last batch.
	 *
	 * Posts the acting user could not edit, and that therefore kept their
	 * old alt text, are remembered by ID together with the images they
	 * belong to, until a later sync updates them. So a post skipped twice
	 * counts once, and the dashboard can offer someone who can edit them a
	 * way to bring them up to date.
	 *
	 * @param int[] $updated       Posts updated by this run.
	 * @param int[] $skipped       Posts this run would have changed but could not.
	 * @param int   $attachment_id The image being synced, or 0 for a sync of every image.
	 *
	 * @phpstan-param list<int> $updated
	 * @phpstan-param list<int> $skipped
	 *
	 * @return void
	 */
	private function log_sync_event( array $updated, array $skipped, int $attachment_id = 0 ): void {
		$now      = time();
		$previous = self::last_sync();
		$count    = count( $updated );
		$posts    = $skipped;
		$images   = $attachment_id > 0 && ! empty( $skipped ) ? array( $attachment_id ) : array();

		if ( null !== $previous ) {
			if ( $now - $previous['time'] < self::LOG_WINDOW ) {
				$count += $previous['post_count'];
			}

			$posts  = array_merge( $previous['skipped_posts'], $posts );
			$images = array_merge( $previous['skipped_attachments'], $images );
		}

		// A post this run updated is no longer behind.
		$posts  = array_slice( array_values( array_diff( array_unique( $posts ), $updated ) ), 0, self::SKIPPED_POSTS_KEPT );
		$images = empty( $posts ) ? array() : array_slice( array_values( array_unique( $images ) ), 0, self::SKIPPED_IMAGES_KEPT );

		set_transient(
			self::LAST_SYNC_TRANSIENT,
			array(
				'post_count'          => $count,
				'skipped_count'       => count( $posts ),
				'skipped_posts'       => $posts,
				'skipped_attachments' => $images,
				'time'                => $now,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * What the last sync did, read defensively.
	 *
	 * @since 1.0.0
	 *
	 * @return array{post_count: int, skipped_count: int, skipped_posts: list<int>, skipped_attachments: list<int>, time: int}|null
	 */
	public static function last_sync(): ?array {
		$last = get_transient( self::LAST_SYNC_TRANSIENT );

		if ( ! is_array( $last ) || ! isset( $last['time'] ) || ! is_numeric( $last['time'] ) ) {
			return null;
		}

		$posts = self::id_list( $last['skipped_posts'] ?? array() );

		return array(
			'post_count'          => isset( $last['post_count'] ) && is_numeric( $last['post_count'] ) ? max( 0, (int) $last['post_count'] ) : 0,
			'skipped_count'       => count( $posts ),
			'skipped_posts'       => $posts,
			'skipped_attachments' => self::id_list( $last['skipped_attachments'] ?? array() ),
			'time'                => (int) $last['time'],
		);
	}

	/**
	 * Read a stored list of IDs.
	 *
	 * @param mixed $ids What was stored.
	 * @return list<int>
	 */
	private static function id_list( mixed $ids ): array {
		$list = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$list[] = (int) $id;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Forget the posts the last sync had to skip.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function forget_skipped(): void {
		$last = self::last_sync();

		if ( null === $last ) {
			return;
		}

		$last['skipped_count']       = 0;
		$last['skipped_posts']       = array();
		$last['skipped_attachments'] = array();

		set_transient( self::LAST_SYNC_TRANSIENT, $last, DAY_IN_SECONDS );
	}

	/**
	 * Remember that a post could not be updated, for the dashboard.
	 *
	 * The images whose update did not reach every post are remembered too,
	 * so the dashboard and wp altrelay status can offer to run them again.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id       The post that failed, or 0 when a query or a whole job failed.
	 * @param string $message       What went wrong.
	 * @param int    $attachment_id The image whose update it was, or 0 when not known.
	 * @return void
	 */
	public static function record_error( int $post_id, string $message, int $attachment_id = 0 ): void {
		$previous = get_transient( self::ERRORS_TRANSIENT );
		$count    = is_array( $previous ) && isset( $previous['count'] ) && is_numeric( $previous['count'] ) ? (int) $previous['count'] : 0;
		$images   = self::images_to_retry();

		if ( $attachment_id > 0 ) {
			$images = array_slice( array_values( array_unique( array_merge( array( $attachment_id ), $images ) ) ), 0, self::SKIPPED_IMAGES_KEPT );
		}

		set_transient(
			self::ERRORS_TRANSIENT,
			array(
				'count'   => $count + 1,
				'post_id' => $post_id,
				'message' => $message,
				'time'    => time(),
				'images'  => $images,
			),
			WEEK_IN_SECONDS
		);

		/**
		 * Fires when AltRelay could not update a post.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id The post that failed, or 0 when a database query failed.
		 * @param string $message The error message.
		 */
		do_action( 'altrelay_sync_failed', $post_id, $message );
	}

	/**
	 * The images whose update stopped or failed for some posts, and could be
	 * run again.
	 *
	 * @since 1.0.0
	 *
	 * @return list<int>
	 */
	public static function images_to_retry(): array {
		$errors = get_transient( self::ERRORS_TRANSIENT );

		return is_array( $errors ) ? self::id_list( $errors['images'] ?? array() ) : array();
	}

	/**
	 * Forget images to run again, once they are queued or have been synced.
	 *
	 * The error count and message stay until they are cleared.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $attachment_ids The images to forget. Default: all of them.
	 * @return void
	 */
	public static function forget_images_to_retry( array $attachment_ids = array() ): void {
		$errors = get_transient( self::ERRORS_TRANSIENT );

		if ( ! is_array( $errors ) || empty( $errors['images'] ) ) {
			return;
		}

		$errors['images'] = empty( $attachment_ids ) ? array() : array_values( array_diff( self::images_to_retry(), $attachment_ids ) );

		set_transient( self::ERRORS_TRANSIENT, $errors, WEEK_IN_SECONDS );
	}
}
