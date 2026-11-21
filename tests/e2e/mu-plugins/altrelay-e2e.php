<?php
/**
 * Plugin Name: AltRelay end-to-end test helper
 * Description: Test-only. Runs AltRelay's background queue on request and reports its state, so the end-to-end tests do not depend on WP-Cron's loopback request. Loaded by wp-env only; never part of the plugin.
 *
 * @package AltRelay
 */

add_action(
	'wp_ajax_altrelay-e2e-tick',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'AltRelay_Queue' ) ) {
			wp_send_json_error( null, 403 );
		}

		$queue = function_exists( 'altrelay' ) ? altrelay( 'queue' ) : null;

		if ( $queue instanceof AltRelay_Queue ) {
			$queue->run_cron_queue();
		}

		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			ActionScheduler_QueueRunner::instance()->run( 'AltRelay E2E' );
		}

		wp_send_json_success(
			array(
				'backend' => AltRelay_Queue::backend(),
				'pending' => AltRelay_Queue::pending_count(),
				'cron'    => get_option( AltRelay_Queue::QUEUE_OPTION ),
				'lock'    => get_transient( 'doing_cron' ),
			)
		);
	}
);
