<?php
/**
 * AltRelay – Alt Text Everywhere
 *
 * @package     AltRelay
 * @author      Julian Witznick / codequelle.dev
 * @copyright   2026 Julian Witznick
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: AltRelay – Alt Text Everywhere
 * Plugin URI:  https://codequelle.dev/altrelay
 * Description: Change image alt text once and have it update everywhere on your site — automatically.
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Author:      codequelle.dev
 * Author URI:  https://codequelle.dev
 * Text Domain: altrelay
 * Domain Path: /languages
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Another copy of AltRelay is already running, for example a second folder
 * with the same plugin, activated by mistake. Loading this one as well would
 * stop the site with a fatal error, so it stays out of the way and says so.
 */
if ( defined( 'ALTRELAY_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plugin folder */
						__( 'Two copies of AltRelay are active. The one in "%s" is not running. Deactivate one of them.', 'altrelay' ),
						basename( __DIR__ )
					)
				)
			);
		}
	);

	return;
}

/**
 * Plugin constants.
 */
define( 'ALTRELAY_VERSION', '1.0.0' );
define( 'ALTRELAY_PLUGIN_FILE', __FILE__ );
define( 'ALTRELAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALTRELAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALTRELAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ALTRELAY_MIN_WP', '6.7' );
define( 'ALTRELAY_MIN_PHP', '8.2' );

require_once ALTRELAY_PLUGIN_DIR . 'includes/altrelay-functions.php';
