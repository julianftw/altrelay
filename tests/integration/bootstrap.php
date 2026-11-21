<?php
/**
 * Integration test bootstrap.
 *
 * Runs the plugin against the WordPress test suite, which needs a MySQL or
 * MariaDB database. CONTRIBUTING.md shows how to install it and how CI runs
 * it; set WP_TESTS_DIR to where it lives.
 *
 * @package AltRelay
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$_tests_dir}.\n";
	echo "Install it with bin/install-wp-tests.sh, or set WP_TESTS_DIR. See CONTRIBUTING.md.\n";
	exit( 1 );
}

// The WordPress test suite needs the Polyfills library, and it looks for it
// through a constant rather than an environment variable.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load Action Scheduler when it is available.
 *
 * The queue tests need the real library, but it is an optional dependency, so
 * the suite still runs without it. Point ALTRELAY_ACTION_SCHEDULER_DIR at an
 * Action Scheduler checkout to exercise the Action Scheduler code path.
 *
 * @return void
 */
function altrelay_load_action_scheduler() {
	$dir = getenv( 'ALTRELAY_ACTION_SCHEDULER_DIR' );

	if ( ! $dir || ! file_exists( $dir . '/action-scheduler.php' ) ) {
		return;
	}

	require_once $dir . '/action-scheduler.php';
}
tests_add_filter( 'muplugins_loaded', 'altrelay_load_action_scheduler', 5 );

/**
 * Load the plugin as WordPress would.
 *
 * @return void
 */
function altrelay_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/altrelay.php';
}
tests_add_filter( 'muplugins_loaded', 'altrelay_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';