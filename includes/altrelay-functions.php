<?php
/**
 * AltRelay's bootstrap: requirements, lifecycle hooks, components and the
 * functions other code may call.
 *
 * Loaded by altrelay.php once it has made sure that no other copy of the
 * plugin is running. Kept apart from that file because PHP declares a
 * file's functions as soon as it reads the file: a second copy would stop
 * with a fatal error before any check in the same file could run.
 *
 * @package AltRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this site meets the plugin's requirements.
 *
 * WordPress refuses to activate AltRelay on older versions, but the plugin can
 * still end up active there, for example after a database import or a core or
 * PHP downgrade. Its HTML rewriting relies on HTML API methods that older
 * versions lack, so it stays dormant instead of breaking every page that shows
 * an image.
 *
 * @since 1.0.0
 *
 * @param string|null $wp_version  WordPress version. Default: the running one.
 * @param string|null $php_version PHP version. Default: the running one.
 * @return bool
 */
function altrelay_meets_requirements( ?string $wp_version = null, ?string $php_version = null ): bool {
	$wp_version  = $wp_version ?? (string) get_bloginfo( 'version' );
	$php_version = $php_version ?? PHP_VERSION;

	// Like core's is_wp_version_compatible(): 6.7-RC1 counts as 6.7.
	$wp_version = explode( '-', $wp_version )[0];

	return version_compare( $wp_version, ALTRELAY_MIN_WP, '>=' )
		&& version_compare( $php_version, ALTRELAY_MIN_PHP, '>=' );
}

/**
 * Explain to administrators why AltRelay is not running.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required WordPress version, 2: required PHP version, 3: running WordPress version, 4: running PHP version */
				__( 'AltRelay is not running: it requires WordPress %1$s and PHP %2$s or newer. This site runs WordPress %3$s on PHP %4$s.', 'altrelay' ),
				ALTRELAY_MIN_WP,
				ALTRELAY_MIN_PHP,
				(string) get_bloginfo( 'version' ),
				PHP_VERSION
			)
		)
	);
}

/**
 * Deactivation hook.
 *
 * Cancels queued work, whose hook is no longer registered once the plugin is
 * inactive. A network-wide deactivation does so on every site.
 *
 * Registered before the requirements check, so a dormant install clears its
 * queue too. A dormant install has not loaded the queue class, and may be
 * dormant because PHP is too old to load it, so it is cleared with plain
 * function calls instead.
 *
 * @since 1.0.0
 *
 * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
 * @return void
 */
function altrelay_deactivate( bool $network_wide = false ): void {
	if ( ! $network_wide || ! is_multisite() ) {
		altrelay_clear_queue();
		return;
	}

	// A page of sites at a time, so a large network is never held in
	// memory at once.
	$offset = 0;

	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			altrelay_clear_queue();
			restore_current_blog();
		}

		$fetched = count( $site_ids );
		$offset += 100;
	} while ( 100 === $fetched );
}
register_deactivation_hook( ALTRELAY_PLUGIN_FILE, 'altrelay_deactivate' );

/**
 * Cancel every queued job of the current site.
 *
 * Uses the queue class when the plugin is running. When it is dormant, the
 * same work is done with calls that any supported PHP and WordPress version
 * understands: the names below are the queue class's constants.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_clear_queue(): void {
	if ( class_exists( 'AltRelay_Queue', false ) ) {
		AltRelay_Queue::cancel_all();
		return;
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'altrelay_process_sync', array(), '' );
	}

	delete_option( 'altrelay_queue' );
	delete_option( 'altrelay_queue_lock' );
	wp_unschedule_hook( 'altrelay_cron_sync' );
}

if ( ! altrelay_meets_requirements() ) {
	add_action( 'admin_notices', 'altrelay_requirements_notice' );
	return;
}

/**
 * Load plugin classes.
 */
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-content.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-sync.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-queue.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-report.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-frontend.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-admin.php';
require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ALTRELAY_PLUGIN_DIR . 'includes/class-altrelay-cli.php';
}

/**
 * Activation hook.
 *
 * Stores the plugin version, the default of the "review first" switch and
 * the one-time welcome notice. Nothing else happens on activation: AltRelay
 * never rewrites content until alt text actually changes.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_activate(): void {
	update_option( 'altrelay_version', ALTRELAY_VERSION );
	altrelay_add_default_options();
	add_option( AltRelay_Admin::WELCOME_OPTION, '1', '', false );
}
register_activation_hook( ALTRELAY_PLUGIN_FILE, 'altrelay_activate' );

/**
 * Create the options AltRelay reads on the frontend.
 *
 * The "review first" switch is read for every image with an empty alt. An
 * option that does not exist costs a database query each time it is read,
 * so it is created up front and autoloaded.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_add_default_options(): void {
	add_option( AltRelay_Content::REVIEW_FIRST_OPTION, '0', '', true );
}

/**
 * Run upgrade routines when the stored version is behind the code.
 *
 * Also covers sites where the activation hook never ran, such as a site
 * added to a network on which AltRelay is already active.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_maybe_upgrade(): void {
	$stored = get_option( 'altrelay_version' );

	if ( ALTRELAY_VERSION === $stored ) {
		return;
	}

	altrelay_add_default_options();

	/**
	 * Fires when the plugin version changes, so future releases can migrate.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $stored  The previously stored version, or false.
	 * @param string $current The current plugin version.
	 */
	do_action( 'altrelay_upgrade', $stored, ALTRELAY_VERSION );

	update_option( 'altrelay_version', ALTRELAY_VERSION );
}
add_action( 'plugins_loaded', 'altrelay_maybe_upgrade', 5 );

/**
 * One of the plugin's components.
 *
 * Other code needs the instances to unhook what AltRelay hooked, for example:
 *
 *     remove_filter( 'render_block', array( altrelay( 'frontend' ), 'fill_block' ), 10 );
 *
 * @since 1.0.0
 *
 * @param string $component 'sync', 'queue', 'report', 'frontend' or 'admin'.
 * @return object|null The component, or null when it is not loaded. The admin
 *                     component only exists in the admin.
 */
function altrelay( string $component ): ?object {
	return AltRelay_Plugin::components()[ $component ] ?? null;
}

/**
 * Initialize plugin components.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_init(): void {
	foreach ( AltRelay_Plugin::components() as $component ) {
		$component->register();
	}
}
add_action( 'plugins_loaded', 'altrelay_init' );

/**
 * Fill missing alt text in a piece of HTML from the Media Library.
 *
 * AltRelay does this by itself for everything WordPress renders through
 * blocks. Call this for HTML that is built some other way, for example by a
 * page builder that keeps its layout in post meta. Images need the
 * wp-image-N class WordPress gives them, and nothing is saved.
 *
 * @since 1.0.0
 *
 * @param string $html HTML that may contain images.
 * @return string The HTML with alt text filled in where it was missing.
 */
function altrelay_fill_alt_text( string $html ): string {
	$frontend = altrelay( 'frontend' );

	return $frontend instanceof AltRelay_Frontend ? $frontend->fill_html( $html ) : $html;
}

/**
 * Register WP-CLI commands.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_register_cli(): void {
	if ( ! class_exists( 'AltRelay_CLI' ) ) {
		return;
	}

	WP_CLI::add_command( 'altrelay', 'AltRelay_CLI' );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', 'altrelay_register_cli' );
}

/**
 * Enqueue block editor assets.
 *
 * @since 1.0.0
 *
 * @return void
 */
function altrelay_enqueue_editor_assets(): void {
	$asset_file = ALTRELAY_PLUGIN_DIR . 'assets/js/editor-sync.js';

	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	wp_enqueue_script(
		'altrelay-editor-sync',
		ALTRELAY_PLUGIN_URL . 'assets/js/editor-sync.js',
		array( 'wp-api-fetch', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-notices' ),
		(string) filemtime( $asset_file ),
		true
	);

	$settings = array(
		'marker'            => AltRelay_Content::MARKER,
		'emptyIsDecorative' => AltRelay_Content::empty_alt_is_decorative(),
		'coreDecorative'    => AltRelay_Content::core_image_has_decorative(),
	);

	wp_add_inline_script(
		'altrelay-editor-sync',
		'window.altrelayEditor = ' . wp_json_encode( $settings ) . ';',
		'before'
	);

	// Without this the editor UI stays English even when a translation for
	// the plugin is installed.
	wp_set_script_translations( 'altrelay-editor-sync', 'altrelay', ALTRELAY_PLUGIN_DIR . 'languages' );
}
add_action( 'enqueue_block_editor_assets', 'altrelay_enqueue_editor_assets' );
