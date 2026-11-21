<?php
/**
 * SQLite smoke test, run inside WordPress Playground.
 *
 * Playground, WordPress Studio and some hosts run WordPress on SQLite,
 * through a driver that translates MySQL. AltRelay builds some queries by
 * hand: REGEXP, LOCATE, LIKE, INSERT IGNORE, LENGTH and REPLACE, a LEFT JOIN
 * compared with a number, and a lock row in the options table. This runs
 * each of them once against the real driver and checks the answers.
 *
 * The result is written to build/sqlite-smoke.txt in the plugin folder: one
 * line per check, and "ALL OK" at the end when every check passed. CI reads
 * that file; see .github/workflows/ci.yml.
 *
 * @package AltRelay
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- A test script that writes its own report.

$altrelay_smoke   = array();
$altrelay_created = array();

/**
 * Record one check.
 *
 * @param string $label  What was checked.
 * @param bool   $passed Whether it passed.
 * @param mixed  $detail What was found, shown when it failed.
 * @return void
 */
$altrelay_check = static function ( string $label, bool $passed, mixed $detail = null ) use ( &$altrelay_smoke ): void {
	$altrelay_smoke[] = ( $passed ? 'ok    ' : 'FAIL  ' ) . $label . ( $passed ? '' : ': ' . wp_json_encode( $detail ) );
};

try {
	global $wpdb;

	$altrelay_check( 'AltRelay is loaded', class_exists( 'AltRelay_Sync' ) );
	$altrelay_check( 'the database is SQLite', defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE, defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql' );

	wp_set_current_user( 1 );
	add_filter( 'altrelay_use_action_scheduler', '__return_false' );

	$upload = static function ( string $name ) use ( &$altrelay_created ): array {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $name,
				'post_status'    => 'inherit',
			)
		);

		update_post_meta( $id, '_wp_attached_file', '2026/11/' . $name . '.jpg' );
		$altrelay_created[] = $id;

		return array( $id, content_url( 'uploads/2026/11/' . $name . '.jpg' ) );
	};

	list( $image, $url )   = $upload( 'smoke' );
	list( $plain, $plain_url ) = $upload( 'smoke-plain' );

	$block   = static fn( int $id, string $src, string $alt ): string => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . $src . '" alt="' . $alt . '" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
	$post_id = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => 'Smoke',
			'post_content' => $block( $image, $url, '' ) . '<p><img src="' . $plain_url . '" class="wp-image-' . $plain . '" /></p>',
		)
	);
	$altrelay_created[] = $post_id;

	// LEFT JOIN compared with a number, NOT EXISTS, LIKE.
	AltRelay_Report::flush_stats();
	$stats = AltRelay_Report::get_alt_text_stats();
	$altrelay_check( 'coverage counts', $stats['total'] >= 2 && $stats['without_alt'] >= 2, $stats );
	$altrelay_check( 'images without alt text are listed', in_array( $image, AltRelay_Report::get_missing_alt_ids(), true ), AltRelay_Report::get_missing_alt_ids() );

	// LOCATE and REGEXP per image, in one query.
	AltRelay_Report::flush_usage();
	$counts = AltRelay_Report::get_usage_counts( array( $image, $plain ) );
	$altrelay_check( 'usage counts', 1 === $counts[ $image ] && 1 === $counts[ $plain ], $counts );

	// The alt text changes: a job is written under the lock (INSERT IGNORE).
	update_post_meta( $image, '_wp_attachment_image_alt', 'Smoke alt' );
	AltRelay_Queue::flush();
	$altrelay_check( 'a job is queued on WP-Cron', AltRelay_Queue::cron_pending_count() >= 1, AltRelay_Queue::cron_pending_count() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lock = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AltRelay_Queue::LOCK_OPTION ) );
	$altrelay_check( 'the lock is released', null === $lock, $lock );

	// The review window and its LENGTH/REPLACE test, before the job runs.
	$review = AltRelay_Report::get_empty_alt_review();
	$altrelay_check( 'the empty-alt review finds the post', in_array( $post_id, array_column( $review['rows'], 'post_id' ), true ), $review );

	// The job finds the post with REGEXP and writes the alt text.
	( new AltRelay_Queue() )->work_through_queue( 10 );
	$content = get_post_field( 'post_content', $post_id, 'raw' );
	$altrelay_check( 'the job wrote the alt text', str_contains( $content, 'alt="Smoke alt"' ), $content );
	$altrelay_check( 'the queue is empty', 0 === AltRelay_Queue::cron_pending_count(), AltRelay_Queue::cron_pending_count() );

	// Marked decorative wherever it is used, and listed so.
	$report = ( new AltRelay_Sync() )->mark_attachment_decorative( $plain );
	$altrelay_check( 'an image is marked decorative everywhere', $report['recorded'] && 1 === $report['marked'], $report );
	AltRelay_Report::flush_stats();
	$altrelay_check( 'the decorative record is counted', in_array( $plain, AltRelay_Report::get_missing_alt_ids( 1, 20, true ), true ), AltRelay_Report::get_missing_alt_ids( 1, 20, true ) );

	$decorative = AltRelay_Report::get_decorative_review();
	$altrelay_check( 'the decorative review finds the post', in_array( $post_id, array_column( $decorative['rows'], 'post_id' ), true ), $decorative );
} catch ( Throwable $error ) {
	$altrelay_smoke[] = 'FAIL  ' . get_class( $error ) . ': ' . $error->getMessage();
} finally {
	// Leave the site as it was.
	foreach ( $altrelay_created as $altrelay_id ) {
		wp_delete_post( $altrelay_id, true );
	}

	AltRelay_Report::flush_stats();
	AltRelay_Report::flush_usage();
}

$altrelay_failed = count( preg_grep( '/^FAIL/', $altrelay_smoke ) );
$altrelay_smoke[] = 0 === $altrelay_failed && count( $altrelay_smoke ) > 1 ? 'ALL OK' : $altrelay_failed . ' FAILED';

// Printed for WP-CLI, which can run this on a local SQLite site too.
if ( defined( 'WP_CLI' ) ) {
	echo implode( "\n", $altrelay_smoke ), "\n";
	return;
}

$altrelay_dir = dirname( __DIR__, 2 ) . '/build';

if ( ! is_dir( $altrelay_dir ) ) {
	mkdir( $altrelay_dir, 0777, true );
}

file_put_contents( $altrelay_dir . '/sqlite-smoke.txt', implode( "\n", $altrelay_smoke ) . "\n" );
