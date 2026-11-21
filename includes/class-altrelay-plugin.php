<?php
/**
 * AltRelay Plugin.
 *
 * Holds the one instance of each component, so the hooks they register can
 * be found and removed by other code.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Plugin
 *
 * @since 1.0.0
 */
final class AltRelay_Plugin {


	/**
	 * The components, by name, once they have been created.
	 *
	 * @var array<string, AltRelay_Sync|AltRelay_Queue|AltRelay_Report|AltRelay_Frontend|AltRelay_Admin>|null
	 */
	private static ?array $components = null;

	/**
	 * The plugin's components, created on first use.
	 *
	 * The sync engine is shared, so the queue worker and the dashboard run
	 * through the same instance that listens for alt text changes.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, AltRelay_Sync|AltRelay_Queue|AltRelay_Report|AltRelay_Frontend|AltRelay_Admin>
	 *         Keys: 'sync', 'queue', 'report', 'frontend' and, in the admin, 'admin'.
	 */
	public static function components(): array {
		if ( null === self::$components ) {
			$sync = new AltRelay_Sync();

			self::$components = array(
				'sync'     => $sync,
				'queue'    => new AltRelay_Queue( $sync ),
				'report'   => new AltRelay_Report(),
				'frontend' => new AltRelay_Frontend(),
			);

			if ( is_admin() ) {
				self::$components['admin'] = new AltRelay_Admin( $sync );
			}
		}

		return self::$components;
	}

	/**
	 * Forget the components, so the next call creates new ones.
	 *
	 * Hooks that were already registered stay registered; this exists for
	 * tests that need a fresh set.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$components = null;
	}
}
