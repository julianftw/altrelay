<?php
/**
 * Loader for the real WordPress HTML API.
 *
 * The plugin's HTML rewriting is the part most likely to corrupt content, so
 * the tests exercise it against WordPress' own WP_HTML_Tag_Processor rather
 * than a hand-written imitation. The classes in fixtures/html-api are
 * unmodified copies from the WordPress 6.7 branch.
 *
 * @package AltRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Shim.
	 *
	 * @param string $function_name Function name.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 * @return void
	 */
	function _doing_it_wrong( $function_name, $message, $version ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		trigger_error( esc_html( $function_name . ': ' . $message ), E_USER_WARNING );
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	/**
	 * Shim.
	 *
	 * @return string[]
	 */
	function wp_kses_uri_attributes() {
		return array( 'action', 'formaction', 'href', 'src', 'cite', 'background', 'poster', 'srcset', 'usemap', 'longdesc', 'xlink:href' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Shim.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

$altrelay_html_api_dir = __DIR__ . '/html-api';

require_once $altrelay_html_api_dir . '/class-wp-token-map.php';
require_once $altrelay_html_api_dir . '/html5-named-character-references.php';
require_once $altrelay_html_api_dir . '/class-wp-html-span.php';
require_once $altrelay_html_api_dir . '/class-wp-html-text-replacement.php';
require_once $altrelay_html_api_dir . '/class-wp-html-attribute-token.php';
require_once $altrelay_html_api_dir . '/class-wp-html-decoder.php';
require_once $altrelay_html_api_dir . '/class-wp-html-tag-processor.php';