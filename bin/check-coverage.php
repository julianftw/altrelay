<?php
/**
 * Fail the build when PHP line coverage drops below a threshold.
 *
 * Usage: php bin/check-coverage.php build/coverage/clover.xml 85
 *
 * @package AltRelay
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI build script, not plugin code.

$altrelay_file      = $argv[1] ?? 'build/coverage/clover.xml';
$altrelay_threshold = isset( $argv[2] ) ? (float) $argv[2] : 85.0;

if ( ! is_readable( $altrelay_file ) ) {
	fwrite( STDERR, "Coverage report not found: {$altrelay_file}\n" );
	exit( 1 );
}

$altrelay_xml = simplexml_load_string( (string) file_get_contents( $altrelay_file ) );

if ( false === $altrelay_xml || ! isset( $altrelay_xml->project->metrics ) ) {
	fwrite( STDERR, "Could not read project metrics from {$altrelay_file}\n" );
	exit( 1 );
}

$altrelay_metrics = $altrelay_xml->project->metrics;
$altrelay_total   = (int) $altrelay_metrics['statements'];
$altrelay_covered = (int) $altrelay_metrics['coveredstatements'];
$altrelay_percent = $altrelay_total > 0 ? ( $altrelay_covered / $altrelay_total ) * 100 : 0.0;

printf( "PHP line coverage: %.2f%% (%d of %d), threshold %.2f%%\n", $altrelay_percent, $altrelay_covered, $altrelay_total, $altrelay_threshold );

if ( $altrelay_percent < $altrelay_threshold ) {
	fwrite( STDERR, "Coverage is below the threshold.\n" );
	exit( 1 );
}
