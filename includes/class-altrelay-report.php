<?php
/**
 * AltRelay Report.
 *
 * The read-only queries behind the dashboard and WP-CLI: coverage numbers,
 * usage counts and the two review lists.
 *
 * The numbers are kept in transients, so they are computed once and not on
 * every dashboard load, with or without a persistent object cache. The
 * transients are dropped as soon as something they depend on changes.
 *
 * The review lists are not counted up front. They are read page by page,
 * starting from a post ID, and each request looks at a bounded number of
 * posts. That keeps the dashboard fast on large sites and means a page is
 * never empty while later pages still hold rows.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Report
 *
 * @since 1.0.0
 */
class AltRelay_Report {


	/**
	 * Transient that holds the coverage numbers.
	 *
	 * @since 1.0.0
	 */
	public const STATS_TRANSIENT = 'altrelay_stats';

	/**
	 * Transient that holds usage counts, keyed by the list of images counted.
	 *
	 * @since 1.0.0
	 */
	public const USAGE_TRANSIENT = 'altrelay_usage';

	/**
	 * Rows per page in the review lists.
	 *
	 * @since 1.0.0
	 */
	public const REVIEW_PER_PAGE = 20;

	/**
	 * Posts loaded per query while reading a review list.
	 */
	private const SCAN_CHUNK = 100;

	/**
	 * Posts read per request at most while reading a review list.
	 */
	private const SCAN_LIMIT = 500;

	/**
	 * Posts with an image that one candidate query looks through, newest
	 * first. The tests that pick candidates cannot use an index, so without
	 * a window a list with few entries would be searched across every post
	 * of the site in one query.
	 */
	private const SCAN_WINDOW = 1000;

	/**
	 * Windows one page of a review list may look through. A page that
	 * reaches the limit says how far it got and offers the next page.
	 */
	private const SCAN_WINDOWS = 10;

	/**
	 * Pages of usage counts kept in the transient.
	 */
	private const USAGE_PAGES = 20;

	/**
	 * Statuses an image attachment can have while it is in the library.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_STATUSES = array( 'inherit', 'private' );

	/**
	 * Whether the usage transient was already dropped in this request.
	 *
	 * A bulk import saves thousands of posts; one delete is enough.
	 *
	 * @var bool
	 */
	private static bool $usage_flushed = false;

	/**
	 * Register the hooks that drop cached numbers when they go stale.
	 *
	 * These run on every request, not only in the admin, because posts and
	 * alt text are also saved through the REST API, WP-CLI and cron.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'added_post_meta', array( $this, 'on_meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_changed' ), 10, 3 );
		add_action( 'add_attachment', array( __CLASS__, 'flush_stats' ), 10, 0 );
		add_action( 'delete_attachment', array( __CLASS__, 'flush_stats' ), 10, 0 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
		add_action( 'wp_insert_post', array( __CLASS__, 'on_post_inserted' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleting' ), 10, 2 );
	}

	/**
	 * Drop the coverage numbers when an image's alt text changes.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $meta_id   Meta ID, or a list of IDs on delete. Unused.
	 * @param mixed $object_id Post ID. Unused.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_changed( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		if ( '_wp_attachment_image_alt' === $meta_key || AltRelay_Sync::DECORATIVE_META === $meta_key ) {
			self::flush_stats();
		}
	}

	/**
	 * Drop the usage counts when a saved post changes which images it uses.
	 *
	 * A busy site saves posts all the time, and most saves have nothing to
	 * do with images. A save changes a count only when it adds or removes an
	 * image, or changes the status or type of a post with images, and then
	 * only the counts of those images: the cached pages that hold them are
	 * dropped, the others are kept.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $post_id     Post ID. Unused.
	 * @param mixed $post_after  The post after the save.
	 * @param mixed $post_before The post before the save.
	 * @return void
	 */
	public static function on_post_updated( mixed $post_id, mixed $post_after, mixed $post_before ): void {
		unset( $post_id );

		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post || 'revision' === $post_after->post_type ) {
			return;
		}

		$before = self::image_ids( $post_before );
		$after  = self::image_ids( $post_after );

		if ( $post_after->post_status !== $post_before->post_status || $post_after->post_type !== $post_before->post_type ) {
			$affected = array_merge( $before, $after );
		} else {
			$affected = array_merge( array_diff( $before, $after ), array_diff( $after, $before ) );
		}

		if ( ! empty( $affected ) ) {
			self::flush_usage( $affected );
		}
	}

	/**
	 * Drop the usage counts when a new post with images is created.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $post_id Post ID. Unused.
	 * @param mixed $post    The post.
	 * @param mixed $update  Whether an existing post was updated; post_updated handles those.
	 * @return void
	 */
	public static function on_post_inserted( mixed $post_id, mixed $post, mixed $update ): void {
		unset( $post_id );

		if ( true !== $update && $post instanceof WP_Post && 'revision' !== $post->post_type && self::has_images( $post ) ) {
			self::flush_usage( self::image_ids( $post ) );
		}
	}

	/**
	 * Drop the usage counts when a post with images is deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $post_id Post ID. Unused.
	 * @param mixed $post    The post about to be deleted.
	 * @return void
	 */
	public static function on_post_deleting( mixed $post_id, mixed $post = null ): void {
		unset( $post_id );

		if ( $post instanceof WP_Post && 'revision' !== $post->post_type && self::has_images( $post ) ) {
			self::flush_usage( self::image_ids( $post ) );
		}
	}

	/**
	 * Whether a post's content holds a Media Library image.
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	private static function has_images( WP_Post $post ): bool {
		return false !== strpos( $post->post_content, 'wp-image-' );
	}

	/**
	 * The Media Library images a post's content uses.
	 *
	 * @param WP_Post $post The post.
	 * @return list<int> Attachment IDs.
	 */
	private static function image_ids( WP_Post $post ): array {
		if ( ! self::has_images( $post ) || ! preg_match_all( '/wp-image-(\d+)/', $post->post_content, $found ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $found[1] ) ) );
	}

	/**
	 * Drop the cached coverage numbers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function flush_stats(): void {
		delete_transient( self::STATS_TRANSIENT );
	}

	/**
	 * Drop cached usage counts.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $attachment_ids Optional. Only drop the cached pages that count these images. Default: all.
	 * @return void
	 */
	public static function flush_usage( array $attachment_ids = array() ): void {
		if ( self::$usage_flushed ) {
			return;
		}

		if ( empty( $attachment_ids ) ) {
			delete_transient( self::USAGE_TRANSIENT );
			self::$usage_flushed = true;
			return;
		}

		$pages = get_transient( self::USAGE_TRANSIENT );

		if ( ! is_array( $pages ) ) {
			return;
		}

		$affected = array_fill_keys( $attachment_ids, true );
		$kept     = array_filter(
			$pages,
			static fn( mixed $page ): bool => is_array( $page ) && empty( array_intersect_key( $page, $affected ) )
		);

		if ( count( $kept ) === count( $pages ) ) {
			return;
		}

		if ( empty( $kept ) ) {
			delete_transient( self::USAGE_TRANSIENT );
		} else {
			set_transient( self::USAGE_TRANSIENT, $kept, HOUR_IN_SECONDS );
		}
	}

	/**
	 * How many images the Media Library holds, how many have alt text, and
	 * how many have none on purpose because they were marked decorative
	 * wherever they are used. "Without alt" counts the rest.
	 *
	 * @since 1.0.0
	 *
	 * @return array{total: int, with_alt: int, without_alt: int, decorative: int}
	 */
	public static function get_alt_text_stats(): array {
		$cached = get_transient( self::STATS_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['total'], $cached['with_alt'], $cached['decorative'] ) && is_numeric( $cached['total'] ) && is_numeric( $cached['with_alt'] ) && is_numeric( $cached['decorative'] ) ) {
			return self::stats( (int) $cached['total'], (int) $cached['with_alt'], (int) $cached['decorative'] );
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient; no core API counts attachments by meta.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status IN (%s, %s) AND post_mime_type LIKE %s',
				$wpdb->posts,
				'attachment',
				self::ATTACHMENT_STATUSES[0],
				self::ATTACHMENT_STATUSES[1],
				'image/%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient.
		$with_alt = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT p.ID)
				FROM %i p
				INNER JOIN %i pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status IN (%s, %s)
				AND p.post_mime_type LIKE %s
				AND pm.meta_key = %s
				AND pm.meta_value != %s',
				$wpdb->posts,
				$wpdb->postmeta,
				'attachment',
				self::ATTACHMENT_STATUSES[0],
				self::ATTACHMENT_STATUSES[1],
				'image/%',
				'_wp_attachment_image_alt',
				''
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient.
		$decorative = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT p.ID)
				FROM %i p
				INNER JOIN %i dm ON p.ID = dm.post_id
				WHERE p.post_type = %s
				AND p.post_status IN (%s, %s)
				AND p.post_mime_type LIKE %s
				AND dm.meta_key = %s
				AND NOT EXISTS (
					SELECT 1 FROM %i pm
					WHERE pm.post_id = p.ID
					AND pm.meta_key = %s
					AND pm.meta_value != %s
				)',
				$wpdb->posts,
				$wpdb->postmeta,
				'attachment',
				self::ATTACHMENT_STATUSES[0],
				self::ATTACHMENT_STATUSES[1],
				'image/%',
				AltRelay_Sync::DECORATIVE_META,
				$wpdb->postmeta,
				'_wp_attachment_image_alt',
				''
			)
		);

		set_transient(
			self::STATS_TRANSIENT,
			array(
				'total'      => $total,
				'with_alt'   => $with_alt,
				'decorative' => $decorative,
			),
			HOUR_IN_SECONDS
		);

		return self::stats( $total, $with_alt, $decorative );
	}

	/**
	 * Build the stats array.
	 *
	 * "Without" is derived rather than counted separately, so a duplicated
	 * meta row can never make the numbers disagree.
	 *
	 * @param int $total      Number of images.
	 * @param int $with_alt   Number of images with alt text.
	 * @param int $decorative Number of images without alt text that are marked decorative wherever they are used.
	 * @return array{total: int, with_alt: int, without_alt: int, decorative: int}
	 */
	private static function stats( int $total, int $with_alt, int $decorative ): array {
		$with_alt   = max( 0, min( $total, $with_alt ) );
		$decorative = max( 0, min( $total - $with_alt, $decorative ) );

		return array(
			'total'       => $total,
			'with_alt'    => $with_alt,
			'without_alt' => max( 0, $total - $with_alt - $decorative ),
			'decorative'  => $decorative,
		);
	}

	/**
	 * Get the IDs of image attachments that have no alt text.
	 *
	 * Images marked decorative wherever they are used have none on purpose,
	 * and are listed separately.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $paged      Page number, or 0 for every image.
	 * @param int  $per_page   Images per page.
	 * @param bool $decorative Optional. List the images marked decorative wherever they are used instead. Default false.
	 * @return list<int> Newest first when paged, by ID otherwise.
	 */
	public static function get_missing_alt_ids( int $paged = 0, int $per_page = 50, bool $decorative = false ): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		// One fixed statement for both lists: the last condition compares
		// "has no record" with 1 for the images missing alt text, and with 0
		// for those recorded as decorative.
		$where = 'FROM %i p
			LEFT JOIN %i dm ON dm.post_id = p.ID AND dm.meta_key = %s
			WHERE p.post_type = %s
			AND p.post_status IN (%s, %s)
			AND p.post_mime_type LIKE %s
			AND NOT EXISTS (
				SELECT 1 FROM %i pm
				WHERE pm.post_id = p.ID
				AND pm.meta_key = %s
				AND pm.meta_value != %s
			)
			AND ( dm.meta_id IS NULL ) = %d';
		$args  = array( $wpdb->posts, $wpdb->postmeta, AltRelay_Sync::DECORATIVE_META, 'attachment', self::ATTACHMENT_STATUSES[0], self::ATTACHMENT_STATUSES[1], 'image/%', $wpdb->postmeta, '_wp_attachment_image_alt', '', $decorative ? 0 : 1 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where is the fixed string above and holds the placeholders; a paged list of a table the user is editing is not worth caching.
		if ( $paged > 0 ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID $where ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d",
					array_merge( $args, array( max( 1, $per_page ), ( $paged - 1 ) * max( 1, $per_page ) ) )
				)
			);
		} else {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID $where ORDER BY p.ID ASC", $args ) );
		}
		// phpcs:enable

		return self::to_ids( $rows );
	}

	/**
	 * Count, for each attachment, the posts that use it.
	 *
	 * One query for the whole page of images rather than one per image. The
	 * database does the counting, so no post content is loaded into PHP, and
	 * it matches on a digit boundary so wp-image-42 does not count
	 * wp-image-420.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $attachment_ids The attachment IDs.
	 * @return array<int, int> Map of attachment ID => number of posts.
	 */
	public static function get_usage_counts( array $attachment_ids ): array {
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$sync       = new AltRelay_Sync();
		$post_types = $sync->get_allowed_post_types();
		$result     = array_fill_keys( $attachment_ids, 0 );

		if ( empty( $post_types ) ) {
			return $result;
		}

		$key    = md5( implode( ',', $attachment_ids ) . '|' . implode( ',', $post_types ) );
		$cached = get_transient( self::USAGE_TRANSIENT );
		$pages  = is_array( $cached ) ? $cached : array();

		if ( isset( $pages[ $key ] ) && is_array( $pages[ $key ] ) ) {
			foreach ( $pages[ $key ] as $attachment_id => $count ) {
				if ( isset( $result[ $attachment_id ] ) && is_numeric( $count ) ) {
					$result[ $attachment_id ] = (int) $count;
				}
			}

			return $result;
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		// One counting column per image, in the order of $attachment_ids. A
		// plain substring test comes before each regular expression: it is
		// far cheaper, and rules out most posts for most images.
		$args = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$args[] = 'wp-image-' . $attachment_id;
			$args[] = 'wp-image-' . $attachment_id . '([^0-9]|$)';
		}

		$args = array_merge(
			$args,
			array( $wpdb->posts, '%' . $wpdb->esc_like( 'wp-image-' ) . '%', 'wp-image-(' . implode( '|', $attachment_ids ) . ')([^0-9]|$)' ),
			$post_types,
			AltRelay_Sync::statuses()
		);

		$select        = implode( ', ', array_fill( 0, count( $attachment_ids ), 'SUM(CASE WHEN LOCATE(%s, post_content) > 0 AND post_content REGEXP %s THEN 1 ELSE 0 END)' ) );
		$type_holders  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$state_holders = implode( ',', array_fill( 0, count( AltRelay_Sync::statuses() ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Cached in a transient; the interpolated parts are placeholder lists built from counts above.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT $select FROM %i
				WHERE post_content LIKE %s
				AND post_content REGEXP %s
				AND post_type IN ($type_holders)
				AND post_status IN ($state_holders)",
				$args
			),
			ARRAY_N
		);
		// phpcs:enable

		foreach ( $attachment_ids as $index => $attachment_id ) {
			$count = is_array( $row ) ? ( $row[ $index ] ?? 0 ) : 0;

			$result[ $attachment_id ] = is_numeric( $count ) ? (int) $count : 0;
		}

		// Keep the most recent pages only, so the transient stays small.
		$pages         = array_slice( $pages, -( self::USAGE_PAGES - 1 ), null, true );
		$pages[ $key ] = $result;

		set_transient( self::USAGE_TRANSIENT, $pages, HOUR_IN_SECONDS );

		self::$usage_flushed = false;

		return $result;
	}

	/**
	 * List images whose copy in a post has no alt text although the Media
	 * Library has one.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $before_id Only posts with an ID below this. 0 starts at the newest post.
	 * @param int    $per_page  Rows to collect before stopping.
	 * @param string $post_type Optional. Only posts of this type. Default: every type AltRelay syncs.
	 * @return array{rows: list<array{post_id: int, post_title: string, attachment_id: int, library_alt: string, copies: int, linked: int}>, next: int, scanned: int}
	 *         "next" is the value to pass as $before_id for the following page, or 0 when the list is complete.
	 */
	public static function get_empty_alt_review( int $before_id = 0, int $per_page = self::REVIEW_PER_PAGE, string $post_type = '' ): array {
		return self::read_review( 'empty', $before_id, $per_page, $post_type );
	}

	/**
	 * List images that are marked decorative in a post and can be unmarked.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $before_id Only posts with an ID below this. 0 starts at the newest post.
	 * @param int    $per_page  Rows to collect before stopping.
	 * @param string $post_type Optional. Only posts of this type. Default: every type AltRelay syncs.
	 * @return array{rows: list<array{post_id: int, post_title: string, attachment_id: int, library_alt: string, copies: int, linked: int}>, next: int, scanned: int}
	 *         "next" is the value to pass as $before_id for the following page, or 0 when the list is complete.
	 */
	public static function get_decorative_review( int $before_id = 0, int $per_page = self::REVIEW_PER_PAGE, string $post_type = '' ): array {
		return self::read_review( 'decorative', $before_id, $per_page, $post_type );
	}

	/**
	 * Read one page of a review list.
	 *
	 * Candidate posts come from cheap LIKE filters, newest first. Each is
	 * then parsed with the HTML API to find the exact images. Posts the
	 * current user cannot edit are passed over: they could not act on them,
	 * and their titles are not theirs to see.
	 *
	 * @param string $mode      'empty' or 'decorative'.
	 * @param int    $before_id Only posts with an ID below this. 0 starts at the newest post.
	 * @param int    $per_page  Rows to collect before stopping.
	 * @param string $post_type Only posts of this type, or '' for every type AltRelay syncs.
	 * @return array{rows: list<array{post_id: int, post_title: string, attachment_id: int, library_alt: string, copies: int, linked: int}>, next: int, scanned: int}
	 */
	private static function read_review( string $mode, int $before_id, int $per_page, string $post_type = '' ): array {
		$sync       = new AltRelay_Sync();
		$post_types = $sync->get_allowed_post_types();

		// A type AltRelay does not sync has nothing to review.
		if ( '' !== $post_type ) {
			$post_types = in_array( $post_type, $post_types, true ) ? array( $post_type ) : array();
		}

		$per_page  = max( 1, $per_page );
		$rows      = array();
		$cursor    = max( 0, $before_id );
		$scanned   = 0;
		$more      = ! empty( $post_types );
		$row_count = 0;

		$windows = 0;

		while ( $more && $row_count < $per_page && $scanned < self::SCAN_LIMIT ) {
			if ( $windows >= self::SCAN_WINDOWS ) {
				break;
			}

			list( $post_ids, $below ) = self::find_candidates( $mode, $post_types, $cursor );
			++$windows;

			if ( empty( $post_ids ) ) {
				// Nothing in this window; the next one starts below it.
				$more   = $below > 0;
				$cursor = $more ? $below : $cursor;
				continue;
			}

			self::prime( $post_ids );

			foreach ( $post_ids as $post_id ) {
				// Stop before consuming this post, so the next page starts with it.
				if ( $row_count >= $per_page || $scanned >= self::SCAN_LIMIT ) {
					break 2;
				}

				$cursor = $post_id;
				++$scanned;

				if ( is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
					continue;
				}

				$rows      = array_merge( $rows, self::rows_for_post( $mode, $post_id ) );
				$row_count = count( $rows );
			}

			// A short page of candidates ends the window it came from, and
			// the list when that window was the last.
			if ( count( $post_ids ) < self::SCAN_CHUNK ) {
				$more   = $below > 0;
				$cursor = $more ? $below : $cursor;
			}
		}

		return array(
			'rows'    => $rows,
			'next'    => $more ? $cursor : 0,
			'scanned' => $scanned,
		);
	}

	/**
	 * The review rows one post contributes.
	 *
	 * @param string $mode    'empty' or 'decorative'.
	 * @param int    $post_id The post ID.
	 * @return list<array{post_id: int, post_title: string, attachment_id: int, library_alt: string, copies: int, linked: int}>
	 */
	private static function rows_for_post( string $mode, int $post_id ): array {
		$content = AltRelay_Sync::raw_content( $post_id );

		/**
		 * Copies per attachment, as [copies, of which linked].
		 *
		 * @var array<int, array{0: int, 1: int}> $found
		 */
		$found = array();

		foreach ( AltRelay_Content::find_images( $content ) as $image ) {
			$wanted = 'decorative' === $mode
				? $image['decorative']
				: ! $image['decorative'] && ( null === $image['alt'] || '' === $image['alt'] );

			if ( ! $wanted ) {
				continue;
			}

			$attachment_id = $image['attachment_id'];
			$counts        = $found[ $attachment_id ] ?? array( 0, 0 );

			$found[ $attachment_id ] = array( $counts[0] + 1, $counts[1] + ( $image['linked'] ? 1 : 0 ) );
		}

		$rows = array();

		foreach ( $found as $attachment_id => $counts ) {
			$library_alt = AltRelay_Sync::stored_alt( $attachment_id );

			// Nothing to fill from.
			if ( 'empty' === $mode && '' === $library_alt ) {
				continue;
			}

			// Decorative through something AltRelay did not write and cannot undo.
			if ( 'decorative' === $mode && AltRelay_Content::unmark_decorative( $content, $attachment_id ) === $content ) {
				continue;
			}

			$rows[] = array(
				'post_id'       => $post_id,
				'post_title'    => (string) get_the_title( $post_id ),
				'attachment_id' => $attachment_id,
				'library_alt'   => $library_alt,
				'copies'        => $counts[0],
				'linked'        => $counts[1],
			);
		}

		return $rows;
	}

	/**
	 * Load a chunk of posts, and the meta of every image in them, in two queries.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	private static function prime( array $post_ids ): void {
		_prime_post_caches( $post_ids, false, false );

		$attachment_ids = array();

		foreach ( $post_ids as $post_id ) {
			if ( preg_match_all( '/wp-image-(\d+)/', AltRelay_Sync::raw_content( $post_id ), $found ) ) {
				foreach ( $found[1] as $attachment_id ) {
					$attachment_ids[ absint( $attachment_id ) ] = true;
				}
			}
		}

		if ( ! empty( $attachment_ids ) ) {
			update_meta_cache( 'post', array_keys( $attachment_ids ) );
		}
	}

	/**
	 * Find the next posts that may belong in a review list.
	 *
	 * For the empty-alt list a post qualifies when it has an explicit empty
	 * alt, a fixed or repeated Cover background (which carries no alt
	 * attribute at all), or more <img tags than alt= attributes, which means
	 * at least one image has no alt attribute.
	 *
	 * The search looks through one window: the SCAN_WINDOW newest posts with
	 * an image below $before_id, which the primary key finds without reading
	 * the rest of the table. The tests above run on that window only.
	 *
	 * @param string   $mode       'empty' or 'decorative'.
	 * @param string[] $post_types Post types to search.
	 * @param int      $before_id  Only posts with an ID below this, or 0.
	 * @return array{0: list<int>, 1: int} Post IDs, newest first, and where the next window starts,
	 *                                     or 0 when this window reached the oldest post.
	 */
	private static function find_candidates( string $mode, array $post_types, int $before_id ): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$like   = static fn( string $text ): string => '%' . $wpdb->esc_like( $text ) . '%';
		$before = $before_id > 0 ? $before_id : PHP_INT_MAX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin page query bounded by LIMIT; a list the user is working through must be current.
		$window = self::to_ids(
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_content LIKE %s'
					. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')'
					. ' AND post_status IN (' . implode( ',', array_fill( 0, count( AltRelay_Sync::statuses() ), '%s' ) ) . ')'
					. ' AND ID < %d ORDER BY ID DESC LIMIT %d',
					array_merge( array( $wpdb->posts, $like( 'wp-image-' ) ), $post_types, AltRelay_Sync::statuses(), array( $before, self::SCAN_WINDOW ) )
				)
			)
		);
		// phpcs:enable

		if ( empty( $window ) ) {
			return array( array(), 0 );
		}

		$floor = min( $window );
		$below = count( $window ) >= self::SCAN_WINDOW ? $floor : 0;
		$tail  = array_merge(
			$post_types,
			AltRelay_Sync::statuses(),
			array( $floor, $before, self::SCAN_CHUNK )
		);

		// One written-out statement per list rather than one put together from
		// variables: every part is a literal or a list of placeholders counted
		// on the spot, which a reader, and the WordPress Plugin Check, can
		// verify at a glance.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin page query bounded by LIMIT; a list the user is working through must be current.
		if ( 'decorative' === $mode ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_content LIKE %s'
					. ' AND ( post_content LIKE %s OR post_content LIKE %s )'
					. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')'
					. ' AND post_status IN (' . implode( ',', array_fill( 0, count( AltRelay_Sync::statuses() ), '%s' ) ) . ')'
					. ' AND ID >= %d AND ID < %d ORDER BY ID DESC LIMIT %d',
					array_merge(
						array(
							$wpdb->posts,
							$like( 'wp-image-' ),
							$like( AltRelay_Content::MARKER ),
							$like( '"' . AltRelay_Content::CORE_DECORATIVE_ATTRIBUTE . '":true' ),
						),
						$tail
					)
				)
			);
		} else {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_content LIKE %s'
					. ' AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s'
					. " OR LENGTH( REPLACE( post_content, '<img', '' ) ) < LENGTH( REPLACE( post_content, 'alt=', '' ) ) )"
					. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')'
					. ' AND post_status IN (' . implode( ',', array_fill( 0, count( AltRelay_Sync::statuses() ), '%s' ) ) . ')'
					. ' AND ID >= %d AND ID < %d ORDER BY ID DESC LIMIT %d',
					array_merge(
						array(
							$wpdb->posts,
							$like( 'wp-image-' ),
							$like( 'alt=""' ),
							$like( "alt=''" ),
							$like( 'has-parallax' ),
							$like( 'is-repeated' ),
						),
						$tail
					)
				)
			);
		}
		// phpcs:enable

		return array( self::to_ids( $rows ), $below );
	}

	/**
	 * Normalize a column of IDs.
	 *
	 * @param array<mixed> $rows Raw values.
	 * @return list<int>
	 */
	private static function to_ids( array $rows ): array {
		$ids = array();

		foreach ( $rows as $row ) {
			if ( is_scalar( $row ) ) {
				$id = absint( $row );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}
}
