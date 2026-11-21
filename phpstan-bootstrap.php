<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines runtime constants and declares optional third-party functions so
 * static analysis can resolve them without the packages being installed.
 *
 * @package AltRelay
 */

define( 'ALTRELAY_VERSION', '1.0.0' );
define( 'ALTRELAY_PLUGIN_FILE', __DIR__ . '/altrelay.php' );
define( 'ALTRELAY_PLUGIN_DIR', __DIR__ . '/' );
define( 'ALTRELAY_PLUGIN_URL', 'https://example.com/wp-content/plugins/altrelay/' );
define( 'ALTRELAY_PLUGIN_BASENAME', 'altrelay/altrelay.php' );

/**
 * Action Scheduler is an optional dependency, bundled by WooCommerce or
 * installed standalone. These declarations let PHPStan resolve the calls.
 *
 * @param string               $hook  Action hook.
 * @param array<int, mixed>    $args  Action arguments.
 * @param string               $group Action group.
 * @return int
 */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {}

/**
 * Action Scheduler is an optional dependency.
 *
 * @param string            $hook  Action hook.
 * @param array<int, mixed> $args  Action arguments.
 * @param string            $group Action group.
 * @return int
 */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): int {}

/**
 * Action Scheduler is an optional dependency.
 *
 * @param array<string, mixed> $query         Query arguments.
 * @param string               $return_format OBJECT, ARRAY_A or ids.
 * @return array<int, mixed>
 */
function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
	return array();
}

if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	/**
	 * Action Scheduler is an optional dependency. Mirrors the store method
	 * AltRelay uses to count pending actions.
	 */
	abstract class ActionScheduler_Store {


		/**
		 * Query actions.
		 *
		 * @param array<string, mixed> $query      Query arguments.
		 * @param string               $query_type 'select' or 'count'.
		 * @return string|array<int, mixed>
		 */
		abstract public function query_actions( array $query = array(), string $query_type = 'select' );
	}
}

if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Action Scheduler is an optional dependency.
	 */
	class ActionScheduler {


		/**
		 * The data store.
		 *
		 * @return ActionScheduler_Store
		 */
		public static function store() {
			throw new RuntimeException( 'Declaration only.' );
		}
	}
}
