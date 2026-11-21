<?php
/**
 * AltRelay uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * @package AltRelay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

if ( ! function_exists( 'altrelay_uninstall_site' ) ) {
	/**
	 * Remove AltRelay data for the current site.
	 *
	 * AltRelay has no custom tables. It leaves behind a version option, the
	 * "review first" switch, a welcome-notice flag, a few short-lived
	 * transients and a mark on images recorded as decorative, all of which
	 * are removed here. Post content that was
	 * already synced, and the decorative marks in it, are intentionally left
	 * alone: deleting a plugin must never rewrite a site's content.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function altrelay_uninstall_site(): void {
		delete_option( 'altrelay_version' );
		delete_option( 'altrelay_show_welcome' );
		delete_option( 'altrelay_review_first' );
		delete_option( 'altrelay_queue' );
		delete_option( 'altrelay_queue_lock' );
		delete_transient( 'altrelay_last_sync' );
		delete_transient( 'altrelay_sync_errors' );
		delete_transient( 'altrelay_stats' );
		delete_transient( 'altrelay_usage' );
		delete_post_meta_by_key( '_altrelay_decorative' );
		delete_post_meta_by_key( '_altrelay_marking' );
		delete_post_meta_by_key( '_altrelay_stale_alts' );

		// Deactivation already clears the queue, but a plugin deleted without
		// being deactivated first would leave actions pointing at a hook that
		// no longer exists.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
			/** @var \wpdb $wpdb */
			global $wpdb;

			// On a network, a site that Action Scheduler never ran on has no
			// tables for it; that is not worth a database error in the log.
			$switched = is_multisite() && ms_is_switched();
			$shown    = $switched ? $wpdb->suppress_errors( true ) : false;

			// Empty group on purpose: passing both hook and group makes Action
			// Scheduler fall back to an exact-args lookup that matches nothing.
			as_unschedule_all_actions( 'altrelay_process_sync', array(), '' );

			if ( $switched ) {
				$wpdb->suppress_errors( $shown );
			}
		}

		wp_unschedule_hook( 'altrelay_cron_sync' );
	}
}

if ( is_multisite() ) {
	// A page of sites at a time, so a large network is never held in
	// memory at once.
	$altrelay_offset = 0;

	do {
		$altrelay_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $altrelay_offset,
			)
		);

		foreach ( $altrelay_site_ids as $altrelay_site_id ) {
			switch_to_blog( (int) $altrelay_site_id );
			altrelay_uninstall_site();
			restore_current_blog();
		}

		$altrelay_fetched = count( $altrelay_site_ids );
		$altrelay_offset += 100;
	} while ( 100 === $altrelay_fetched );
} else {
	altrelay_uninstall_site();
}
