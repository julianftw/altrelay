<?php
/**
 * Stub for WP_CLI\Utils, which is a namespace of free functions in WP-CLI.
 *
 * @package AltRelay
 */

namespace WP_CLI\Utils;

/**
 * Render a list of items in the requested format.
 *
 * @param string                           $format Output format.
 * @param array<int, array<string, mixed>> $items  Rows.
 * @param string[]                         $fields Fields to render.
 * @return void
 */
function format_items( string $format, array $items, array $fields ): void {
	global $altrelay_test_state;
	$altrelay_test_state['cli_formatted'][] = array(
		'format' => $format,
		'items'  => $items,
		'fields' => $fields,
	);
}

/**
 * Create a progress bar.
 *
 * @param string $message Label.
 * @param int    $count   Total steps.
 * @return \cli\progress\Bar
 */
function make_progress_bar( string $message, int $count ): \cli\progress\Bar {
	global $altrelay_test_state;

	$bar = new \cli\progress\Bar();

	$altrelay_test_state['progress_bars'][] = array(
		'message' => $message,
		'count'   => $count,
		'bar'     => $bar,
	);

	return $bar;
}
