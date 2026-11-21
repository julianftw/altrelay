<?php
/**
 * AltRelay WP-CLI commands.
 *
 * Audit coverage, repair posts from the Media Library, review empty alt
 * text and decorative images, and inspect the background queue from the
 * command line.
 *
 * Every command that changes posts honours WP-CLI's global --user: with it,
 * only posts that user may edit are touched; without it, all of them are.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep image alt text in sync between the Media Library and your content.
 *
 * @since 1.0.0
 */
class AltRelay_CLI {


	/**
	 * Report how many images are missing alt text.
	 *
	 * Images marked decorative wherever they are used, from the dashboard
	 * or with mark-image-decorative, have none on purpose. They are counted
	 * separately, not listed, and count as covered.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. "ids" prints the IDs of images without alt text, one per line, and nothing when there are none.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--ids]
	 * : Shortcut for --format=ids.
	 *
	 * ## EXAMPLES
	 *
	 *     # Coverage summary.
	 *     $ wp altrelay audit
	 *
	 *     # Number of images without alt text.
	 *     $ wp altrelay audit --format=count
	 *
	 *     # IDs of images without alt text.
	 *     $ wp altrelay audit --ids
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function audit( array $args, array $assoc_args ): void {
		$format = self::format( $assoc_args );

		if ( isset( $assoc_args['ids'] ) || 'ids' === $format ) {
			$ids = AltRelay_Report::get_missing_alt_ids();

			// Nothing at all when there is nothing: the output is meant for
			// other commands, as in wp media regenerate $(wp altrelay audit --ids).
			foreach ( $ids as $id ) {
				\WP_CLI::line( (string) $id );
			}

			return;
		}

		$stats = AltRelay_Report::get_alt_text_stats();

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) $stats['without_alt'] );
			return;
		}

		// An image left without alt text on purpose is not missing any.
		$coverage = $stats['total'] > 0
			? round( ( ( $stats['with_alt'] + $stats['decorative'] ) / $stats['total'] ) * 100, 1 ) . '%'
			: 'n/a';

		$rows = array(
			array(
				'total'      => $stats['total'],
				'with_alt'   => $stats['with_alt'],
				'without'    => $stats['without_alt'],
				'decorative' => $stats['decorative'],
				'coverage'   => $coverage,
			),
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'total', 'with_alt', 'without', 'decorative', 'coverage' ) );
	}

	/**
	 * Push Media Library alt text into the posts that use the images.
	 *
	 * Images marked decorative are never changed. Runs immediately rather than
	 * through the background queue. The altrelay_should_sync filter is not
	 * applied: it decides what happens on its own when alt text changes, and
	 * this command is an explicit request.
	 *
	 * With --all, every post is visited once and all of its images are
	 * written in a single save, so a post with ten images gets one revision
	 * instead of ten.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : One or more attachment IDs to sync.
	 *
	 * [--all]
	 * : Sync every image that has alt text.
	 *
	 * [--post-type=<types>]
	 * : Only update posts of these types. Separate several with commas.
	 *
	 * [--dry-run]
	 * : List the posts that would change without writing anything.
	 *
	 * [--batch=<size>]
	 * : Posts to process per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay sync 42
	 *     $ wp altrelay sync 42 43 44
	 *     $ wp altrelay sync --all --dry-run
	 *     $ wp altrelay sync --all --post-type=page,post
	 *     $ wp altrelay sync --all --user=admin
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function sync( array $args, array $assoc_args ): void {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$batch      = isset( $assoc_args['batch'] ) && is_scalar( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 0;
		$batch      = $batch > 0 ? $batch : AltRelay_Queue::BATCH_LIMIT;
		$post_types = $this->parse_post_types( $assoc_args );
		$sync       = new AltRelay_Sync();

		if ( isset( $assoc_args['all'] ) ) {
			$totals = $this->run_to_completion(
				static fn( int $after_id ): array => $sync->sync_posts( $dry_run, $after_id, $batch, 0, $post_types )
			);

			$this->list_dry_run( $totals['post_ids'], $dry_run );

			// Every post was reached: no image is left to try again.
			if ( ! $dry_run && empty( $post_types ) && 0 === $totals['failed'] && 0 === $totals['skipped'] ) {
				AltRelay_Sync::forget_images_to_retry();
			}

			$this->report_sync( $totals, $dry_run );
			return;
		}

		$ids = $this->parse_ids( $args );

		if ( empty( $ids ) ) {
			\WP_CLI::error( __( 'Please provide an attachment ID, or use --all.', 'altrelay' ) );
			return;
		}

		$totals   = self::empty_totals();
		$progress = count( $ids ) > 1
			? \WP_CLI\Utils\make_progress_bar( __( 'Syncing images', 'altrelay' ), count( $ids ) )
			: null;

		foreach ( $ids as $attachment_id ) {
			$result = $this->sync_one( $sync, $attachment_id, $dry_run, $batch, $post_types );

			$this->list_dry_run( $result['post_ids'], $dry_run );

			$totals['updated'] += $result['updated'];
			$totals['skipped'] += $result['skipped'];
			$totals['failed']  += $result['failed'];

			// Every post of the image was reached: nothing left to try again.
			if ( ! $dry_run && empty( $post_types ) && 0 === $result['failed'] && 0 === $result['skipped'] ) {
				AltRelay_Sync::forget_images_to_retry( array( $attachment_id ) );
			}

			// --quiet returns a NoOp object instead of a bar; nothing to draw then.
			if ( $progress instanceof \cli\progress\Bar ) {
				$progress->tick();
			}
		}

		if ( $progress instanceof \cli\progress\Bar ) {
			$progress->finish();
		}

		$this->report_sync( $totals, $dry_run );
	}

	/**
	 * Sync one attachment to completion.
	 *
	 * @param AltRelay_Sync $sync          The sync engine.
	 * @param int           $attachment_id The attachment ID.
	 * @param bool          $dry_run       Whether to write.
	 * @param int           $batch         Batch size.
	 * @param string[]      $post_types    Post types to limit the run to.
	 * @return array{updated: int, skipped: int, failed: int, post_ids: list<int>}
	 */
	private function sync_one( AltRelay_Sync $sync, int $attachment_id, bool $dry_run, int $batch, array $post_types ): array {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: %d: post ID */
					__( 'Post %d is not an attachment, skipping.', 'altrelay' ),
					$attachment_id
				)
			);
			return self::empty_totals();
		}

		$alt = AltRelay_Sync::stored_alt( $attachment_id );

		if ( '' === $alt ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment %d has no alt text, skipping. Add alt text in the Media Library first.', 'altrelay' ),
					$attachment_id
				)
			);
			return self::empty_totals();
		}

		return $this->run_to_completion(
			static fn( int $after_id ): array => $sync->sync_attachment( $attachment_id, $alt, $dry_run, $after_id, $batch, 0, $post_types )
		);
	}

	/**
	 * Call a batched sync until it reports that it is done.
	 *
	 * @param callable $batch Runs one batch after a post ID and returns its progress report.
	 *
	 * @phpstan-param callable(int): array{updated: int, skipped: int, failed: int, last_id: int, done: bool, post_ids: list<int>} $batch
	 *
	 * @return array{updated: int, skipped: int, failed: int, post_ids: list<int>}
	 */
	private function run_to_completion( callable $batch ): array {
		$totals   = self::empty_totals();
		$after_id = 0;

		do {
			$result = $batch( $after_id );

			$totals['updated'] += $result['updated'];
			$totals['skipped'] += $result['skipped'];
			$totals['failed']  += $result['failed'];
			$totals['post_ids'] = array_merge( $totals['post_ids'], $result['post_ids'] );
			$after_id           = $result['last_id'];
		} while ( ! $result['done'] );

		return $totals;
	}

	/**
	 * Empty totals for a sync run.
	 *
	 * @return array{updated: int, skipped: int, failed: int, post_ids: list<int>}
	 */
	private static function empty_totals(): array {
		return array(
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'post_ids' => array(),
		);
	}

	/**
	 * Print the posts a dry run would update.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param bool  $dry_run  Whether this is a dry run.
	 * @return void
	 */
	private function list_dry_run( array $post_ids, bool $dry_run ): void {
		if ( ! $dry_run ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			\WP_CLI::log(
				sprintf(
					/* translators: 1: post ID, 2: post title */
					__( 'Would update post %1$d ("%2$s").', 'altrelay' ),
					$post_id,
					get_the_title( $post_id )
				)
			);
		}
	}

	/**
	 * Print the summary of a sync run.
	 *
	 * A run in which posts could not be saved ends with an error, so the
	 * command exits with status 1 and scripts can tell.
	 *
	 * @param array{updated: int, skipped: int, failed: int, post_ids: list<int>} $totals  Totals of the run.
	 * @param bool                                                                $dry_run Whether this was a dry run.
	 * @return void
	 */
	private function report_sync( array $totals, bool $dry_run ): void {
		$summary = $dry_run
			? sprintf(
				/* translators: %d: number of posts */
				_n( 'Dry run: %d post would be updated.', 'Dry run: %d posts would be updated.', $totals['updated'], 'altrelay' ),
				$totals['updated']
			)
			: sprintf(
				/* translators: %d: number of posts */
				_n( 'Updated %d post.', 'Updated %d posts.', $totals['updated'], 'altrelay' ),
				$totals['updated']
			);

		if ( $totals['failed'] > 0 ) {
			\WP_CLI::log( $summary );
		} else {
			\WP_CLI::success( $summary );
		}

		if ( $totals['skipped'] > 0 ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post was skipped because the --user you ran as cannot edit it.',
						'%d posts were skipped because the --user you ran as cannot edit them.',
						$totals['skipped'],
						'altrelay'
					),
					$totals['skipped']
				)
			);
		}

		if ( $totals['failed'] > 0 ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post could not be saved. Run "wp altrelay status" for the last error.',
						'%d posts could not be saved. Run "wp altrelay status" for the last error.',
						$totals['failed'],
						'altrelay'
					),
					$totals['failed']
				)
			);
		}
	}

	/**
	 * List images whose copy in a post has no alt text although the Media Library has one.
	 *
	 * Empty alt without the decorative mark is treated as "not written yet"
	 * and filled on the next sync. Use this to find images that should be
	 * marked decorative first. With --user, only posts that user can edit
	 * are listed.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<types>]
	 * : Only list posts of these types. Separate several with commas.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay empty-alt
	 *     $ wp altrelay empty-alt --post-type=page
	 *     $ wp altrelay empty-alt --format=csv > review.csv
	 *
	 * @subcommand empty-alt
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function empty_alt( array $args, array $assoc_args ): void {
		$this->print_review(
			static fn( int $before_id, string $post_type ): array => AltRelay_Report::get_empty_alt_review( $before_id, 100, $post_type ),
			$this->parse_post_types( $assoc_args ),
			self::format( $assoc_args ),
			__( 'No empty alt text to review.', 'altrelay' )
		);
	}

	/**
	 * List images that are marked decorative and can be unmarked.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<types>]
	 * : Only list posts of these types. Separate several with commas.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay decorative
	 *     $ wp altrelay decorative --post-type=post --format=count
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function decorative( array $args, array $assoc_args ): void {
		$this->print_review(
			static fn( int $before_id, string $post_type ): array => AltRelay_Report::get_decorative_review( $before_id, 100, $post_type ),
			$this->parse_post_types( $assoc_args ),
			self::format( $assoc_args ),
			__( 'No images are marked decorative.', 'altrelay' )
		);
	}

	/**
	 * Read a review list to its end and print it.
	 *
	 * @param callable $read       Reads one page of the list before a post ID, for one post type or '' for all.
	 * @param string[] $post_types The post types to list, or none for every type AltRelay syncs.
	 * @param string   $format     Output format.
	 * @param string   $none       Message for an empty list.
	 *
	 * @phpstan-param callable(int, string): array{rows: list<array<string, int|string>>, next: int, scanned: int} $read
	 *
	 * @return void
	 */
	private function print_review( callable $read, array $post_types, string $format, string $none ): void {
		$rows = array();

		foreach ( empty( $post_types ) ? array( '' ) : $post_types as $post_type ) {
			$before_id = 0;

			do {
				$page      = $read( $before_id, $post_type );
				$rows      = array_merge( $rows, $page['rows'] );
				$before_id = $page['next'];
			} while ( $before_id > 0 );
		}

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $rows ) );
			return;
		}

		// Machine-readable formats always print valid output, even when empty,
		// so scripts parsing it never receive a sentence instead of "[]".
		if ( empty( $rows ) && 'table' === $format ) {
			\WP_CLI::success( $none );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'post_id', 'post_title', 'attachment_id', 'library_alt', 'copies', 'linked' ) );
	}

	/**
	 * Fill empty alt text in a post from the Media Library.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post to update.
	 *
	 * [<attachment-id>...]
	 * : Only these images. Defaults to every image in the post.
	 *
	 * [--dry-run]
	 * : Say whether the post would change, without saving it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay fill 12
	 *     $ wp altrelay fill 12 42
	 *     $ wp altrelay fill 12 --dry-run
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function fill( array $args, array $assoc_args ): void {
		$this->apply_to_post( $args, 'fill', isset( $assoc_args['dry-run'] ) );
	}

	/**
	 * Mark images in a post as decorative, so AltRelay leaves them alone.
	 *
	 * Image blocks get core's own decorative setting where WordPress has
	 * one; other blocks and classic images get the altrelay-decorative class.
	 * Either way the alt is emptied. An image that is the only content of a
	 * link is refused, because the link would be left without a name.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post to update.
	 *
	 * <attachment-id>...
	 * : The images to mark.
	 *
	 * [--dry-run]
	 * : Say whether the post would change, without saving it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay mark-decorative 12 42
	 *
	 * @subcommand mark-decorative
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function mark_decorative( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( __( 'Please provide a post ID and at least one attachment ID.', 'altrelay' ) );
			return;
		}

		$this->apply_to_post( $args, 'decorative', isset( $assoc_args['dry-run'] ) );
	}

	/**
	 * Remove the decorative mark from images in a post.
	 *
	 * The images get their Media Library alt text back.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post to update.
	 *
	 * <attachment-id>...
	 * : The images to unmark.
	 *
	 * [--dry-run]
	 * : Say whether the post would change, without saving it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay unmark-decorative 12 42
	 *
	 * @subcommand unmark-decorative
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function unmark_decorative( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( __( 'Please provide a post ID and at least one attachment ID.', 'altrelay' ) );
			return;
		}

		$this->apply_to_post( $args, 'unmark', isset( $assoc_args['dry-run'] ) );
	}

	/**
	 * Mark images without alt text decorative wherever they are used.
	 *
	 * For an image whose Media Library alt text is empty on purpose, as
	 * WordPress suggests for decorative images: every copy in a post is
	 * marked, and the image no longer counts as missing alt text. The same
	 * as "Mark decorative" in the dashboard's list of images without alt
	 * text, but run here and now instead of in the background.
	 *
	 * A copy that is the only content of a link is refused and keeps the
	 * image listed: it needs a description. With --user, only posts that
	 * user can edit are marked.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>...
	 * : The images to mark.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay mark-image-decorative 42
	 *     $ wp altrelay mark-image-decorative 42 43 --user=admin
	 *
	 * @subcommand mark-image-decorative
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function mark_image_decorative( array $args, array $assoc_args ): void {
		unset( $assoc_args ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature is fixed by WP-CLI.
		$ids = $this->parse_ids( $args );

		if ( empty( $ids ) ) {
			\WP_CLI::error( __( 'Please provide at least one attachment ID.', 'altrelay' ) );
			return;
		}

		$sync   = new AltRelay_Sync();
		$failed = 0;

		foreach ( $ids as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || '' !== AltRelay_Sync::stored_alt( $attachment_id ) ) {
				\WP_CLI::warning(
					sprintf(
						/* translators: %d: attachment ID */
						__( 'Attachment %d is not an image without alt text, skipping.', 'altrelay' ),
						$attachment_id
					)
				);
				continue;
			}

			$report  = $sync->mark_attachment_decorative( $attachment_id );
			$failed += $report['failed'];

			if ( $report['recorded'] ) {
				\WP_CLI::log(
					sprintf(
						/* translators: 1: attachment ID, 2: number of posts */
						_n( 'Image %1$d: marked decorative in %2$d post, and no longer listed as missing alt text.', 'Image %1$d: marked decorative in %2$d posts, and no longer listed as missing alt text.', $report['marked'], 'altrelay' ),
						$attachment_id,
						$report['marked']
					)
				);
				continue;
			}

			\WP_CLI::warning(
				sprintf(
					/* translators: 1: attachment ID, 2: posts marked, 3: linked copies refused, 4: posts the user cannot edit, 5: posts that could not be saved */
					_n(
						'Image %1$d: marked decorative in %2$d post, but not everywhere. Copies that are a link\'s only content: %3$d. Posts you cannot edit: %4$d. Posts that could not be saved: %5$d. It stays listed as missing alt text.',
						'Image %1$d: marked decorative in %2$d posts, but not everywhere. Copies that are a link\'s only content: %3$d. Posts you cannot edit: %4$d. Posts that could not be saved: %5$d. It stays listed as missing alt text.',
						$report['marked'],
						'altrelay'
					),
					$attachment_id,
					$report['marked'],
					$report['refused'],
					$report['skipped'],
					$report['failed']
				)
			);
		}

		if ( $failed > 0 ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %d: number of posts */
					_n( '%d post could not be saved. Run "wp altrelay status" for the last error.', '%d posts could not be saved. Run "wp altrelay status" for the last error.', $failed, 'altrelay' ),
					$failed
				)
			);
			return;
		}

		\WP_CLI::success( __( 'Done.', 'altrelay' ) );
	}

	/**
	 * List images that were marked decorative wherever they are used as
	 * missing alt text again.
	 *
	 * Only the record goes; the marks in the posts stay. Remove those with
	 * unmark-decorative. The same as "List as missing again" on the
	 * dashboard.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>...
	 * : The images to list again.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay list-image-again 42
	 *
	 * @subcommand list-image-again
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function list_image_again( array $args, array $assoc_args ): void {
		unset( $assoc_args ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature is fixed by WP-CLI.
		$ids = $this->parse_ids( $args );

		if ( empty( $ids ) ) {
			\WP_CLI::error( __( 'Please provide at least one attachment ID.', 'altrelay' ) );
			return;
		}

		$listed = 0;

		foreach ( $ids as $attachment_id ) {
			if ( AltRelay_Sync::forget_recorded_decorative( $attachment_id ) ) {
				++$listed;
				continue;
			}

			\WP_CLI::warning(
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Image %d was not marked decorative wherever it is used.', 'altrelay' ),
					$attachment_id
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of images */
				_n( '%d image is listed as missing alt text again.', '%d images are listed as missing alt text again.', $listed, 'altrelay' ),
				$listed
			)
		);
	}

	/**
	 * Show the state of the background queue and of the last sync.
	 *
	 * The retry_images field lists the images whose update stopped, or failed
	 * for some of their posts. Once the cause is fixed, `wp altrelay sync <ids>`
	 * runs them again.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay status
	 *     $ wp altrelay status --format=json
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		// A worker run that was lost would leave the queue standing still.
		AltRelay_Queue::wake();

		$last   = AltRelay_Sync::last_sync();
		$errors = get_transient( AltRelay_Sync::ERRORS_TRANSIENT );

		$rows = array(
			array(
				'backend'          => AltRelay_Queue::backend(),
				'pending'          => AltRelay_Queue::pending_count(),
				'failed_jobs'      => AltRelay_Queue::failed_count(),
				'review_first'     => AltRelay_Content::review_first() ? 'on' : 'off',
				'last_sync_posts'  => null === $last ? 0 : $last['post_count'],
				'skipped_posts'    => null === $last ? 0 : $last['skipped_count'],
				'last_sync_time'   => null === $last ? '' : gmdate( 'Y-m-d H:i:s', $last['time'] ),
				'failed_posts'     => is_array( $errors ) && isset( $errors['count'] ) && is_numeric( $errors['count'] ) ? (int) $errors['count'] : 0,
				'last_failed_post' => is_array( $errors ) && isset( $errors['post_id'] ) && is_numeric( $errors['post_id'] ) ? (int) $errors['post_id'] : 0,
				'last_error'       => is_array( $errors ) && isset( $errors['message'] ) && is_string( $errors['message'] ) ? $errors['message'] : '',
				'retry_images'     => implode( ' ', AltRelay_Sync::images_to_retry() ),
			),
		);

		\WP_CLI\Utils\format_items( self::format( $assoc_args ), $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Run the background jobs that wait in the WP-Cron queue, now.
	 *
	 * For sites where WP-Cron does not run, or to finish a large change
	 * without waiting. Jobs keep the user who made each change. Jobs queued
	 * with Action Scheduler are run with `wp action-scheduler run` instead.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Stop after about this many seconds. 0 runs until the queue is empty.
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp altrelay run-queue
	 *     $ wp altrelay run-queue --seconds=60
	 *
	 * @subcommand run-queue
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function run_queue( array $args, array $assoc_args ): void {
		// Action Scheduler runs its own queue; this command would find nothing.
		if ( AltRelay_Queue::has_action_scheduler() ) {
			$pending = AltRelay_Queue::pending_count();

			\WP_CLI::warning(
				sprintf(
					/* translators: %d: number of waiting updates */
					_n(
						'Action Scheduler runs AltRelay\'s updates on this site; %d is waiting. Run them with "wp action-scheduler run --hooks=altrelay_process_sync".',
						'Action Scheduler runs AltRelay\'s updates on this site; %d are waiting. Run them with "wp action-scheduler run --hooks=altrelay_process_sync".',
						$pending,
						'altrelay'
					),
					$pending
				)
			);

			if ( AltRelay_Queue::cron_pending_count() < 1 ) {
				return;
			}
		}

		$limit   = isset( $assoc_args['seconds'] ) && is_scalar( $assoc_args['seconds'] ) ? absint( $assoc_args['seconds'] ) : 0;
		$queue   = new AltRelay_Queue();
		$started = microtime( true );
		$ran     = 0;

		do {
			$batch = $queue->work_through_queue( $limit > 0 ? max( 1, $limit - (int) ( microtime( true ) - $started ) ) : 20 );
			$ran  += $batch;
		} while ( $batch > 0 && AltRelay_Queue::cron_pending_count() > 0 && ( 0 === $limit || microtime( true ) - $started < $limit ) );

		$left = AltRelay_Queue::cron_pending_count();

		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of jobs run, 2: number of jobs left */
				_n( 'Ran %1$d background update; %2$d left.', 'Ran %1$d background updates; %2$d left.', $ran, 'altrelay' ),
				$ran,
				$left
			)
		);
	}

	/**
	 * Run fill, mark-decorative or unmark-decorative on one post.
	 *
	 * @param array<int, string> $args      Positional args: post ID, then attachment IDs.
	 * @param string             $operation 'fill', 'decorative' or 'unmark'.
	 * @param bool               $dry_run   When true, nothing is saved.
	 * @return void
	 */
	private function apply_to_post( array $args, string $operation, bool $dry_run = false ): void {
		$post_id = absint( $args[0] ?? 0 );

		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			\WP_CLI::error( __( 'Please provide a valid post ID.', 'altrelay' ) );
			return;
		}

		// WP-CLI's --user sets the current user; without it nobody is logged in.
		if ( is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %d: post ID */
					__( 'The --user you ran as cannot edit post %d.', 'altrelay' ),
					$post_id
				)
			);
			return;
		}

		$attachment_ids = $this->parse_ids( array_slice( $args, 1 ) );

		if ( empty( $attachment_ids ) ) {
			$attachment_ids = array_values(
				array_unique(
					array_map(
						static fn( array $image ): int => $image['attachment_id'],
						AltRelay_Content::find_images( AltRelay_Sync::raw_content( $post_id ) )
					)
				)
			);
		}

		$sync    = new AltRelay_Sync();
		$changed = false;
		$refused = 0;
		$errors  = self::error_count();

		foreach ( $attachment_ids as $attachment_id ) {
			if ( 'decorative' === $operation ) {
				$marked     = $sync->mark_post_decorative( $post_id, $attachment_id, $dry_run );
				$did_change = $marked['changed'];
				$refused   += $marked['refused'];
			} elseif ( 'unmark' === $operation ) {
				$did_change = $sync->unmark_post_decorative( $post_id, $attachment_id, $dry_run );
			} else {
				$did_change = $sync->fill_post( $post_id, $attachment_id, $dry_run );
			}

			$changed = $changed || $did_change;
		}

		if ( $refused > 0 ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: %d: number of images */
					_n(
						'%d image was not marked because it is the only content of a link and needs alt text.',
						'%d images were not marked because each is the only content of a link and needs alt text.',
						$refused,
						'altrelay'
					),
					$refused
				)
			);
		}

		if ( self::error_count() > $errors ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %d: post ID */
					__( 'Post %d could not be saved. Run "wp altrelay status" for the error.', 'altrelay' ),
					$post_id
				)
			);
			return;
		}

		if ( ! $changed ) {
			\WP_CLI::success( __( 'Nothing to change.', 'altrelay' ) );
			return;
		}

		$messages = array(
			/* translators: %d: post ID */
			'fill'       => __( 'Filled alt text in post %d.', 'altrelay' ),
			/* translators: %d: post ID */
			'decorative' => __( 'Marked images as decorative in post %d.', 'altrelay' ),
			/* translators: %d: post ID */
			'unmark'     => __( 'Removed the decorative mark in post %d.', 'altrelay' ),
		);

		if ( $dry_run ) {
			$messages = array(
				/* translators: %d: post ID */
				'fill'       => __( 'Dry run: alt text would be filled in post %d.', 'altrelay' ),
				/* translators: %d: post ID */
				'decorative' => __( 'Dry run: images would be marked as decorative in post %d.', 'altrelay' ),
				/* translators: %d: post ID */
				'unmark'     => __( 'Dry run: the decorative mark would be removed in post %d.', 'altrelay' ),
			);
		}

		\WP_CLI::success( sprintf( $messages[ $operation ] ?? $messages['fill'], $post_id ) );
	}

	/**
	 * Read the --format argument.
	 *
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return string
	 */
	private static function format( array $assoc_args ): string {
		return isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
	}

	/**
	 * How many failed updates are on record.
	 *
	 * @return int
	 */
	private static function error_count(): int {
		$errors = get_transient( AltRelay_Sync::ERRORS_TRANSIENT );

		return is_array( $errors ) && isset( $errors['count'] ) && is_numeric( $errors['count'] ) ? (int) $errors['count'] : 0;
	}

	/**
	 * Read the --post-type argument.
	 *
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return list<string> Post type slugs, empty for "all".
	 */
	private function parse_post_types( array $assoc_args ): array {
		if ( ! isset( $assoc_args['post-type'] ) || ! is_string( $assoc_args['post-type'] ) ) {
			return array();
		}

		$sync    = new AltRelay_Sync();
		$allowed = $sync->get_allowed_post_types();
		$types   = array();

		foreach ( explode( ',', $assoc_args['post-type'] ) as $raw_type ) {
			$type = sanitize_key( trim( $raw_type ) );

			if ( '' === $type ) {
				continue;
			}

			if ( ! in_array( $type, $allowed, true ) ) {
				\WP_CLI::error(
					sprintf(
						/* translators: 1: post type, 2: comma-separated list of post types */
						__( 'AltRelay does not sync the post type "%1$s". Available: %2$s.', 'altrelay' ),
						$type,
						implode( ', ', $allowed )
					)
				);
				continue;
			}

			$types[] = $type;
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Parse positional IDs, failing on anything that is not a positive integer.
	 *
	 * @param array<int, string> $args Raw IDs.
	 * @return list<int>
	 */
	private function parse_ids( array $args ): array {
		$ids = array();

		foreach ( $args as $raw_id ) {
			$raw = trim( (string) $raw_id );
			$id  = ctype_digit( $raw ) ? absint( $raw ) : 0;

			if ( 0 === $id ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: the invalid value */
						__( '"%s" is not a valid ID.', 'altrelay' ),
						$raw_id
					)
				);
				continue;
			}

			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}
}
