<?php
/**
 * AltRelay Admin.
 *
 * The dashboard under Media → AltRelay, its form handlers, the one-time
 * welcome notice and the plugin action link. The numbers and lists it shows
 * come from AltRelay_Report.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Admin
 *
 * @since 1.0.0
 */
class AltRelay_Admin {


	/**
	 * Option that shows the welcome notice until it is dismissed.
	 *
	 * @since 1.0.0
	 */
	public const WELCOME_OPTION = 'altrelay_show_welcome';

	/**
	 * Dashboard page slug.
	 *
	 * @since 1.0.0
	 */
	public const PAGE_SLUG = 'altrelay';

	/**
	 * Longest alt text the dashboard accepts, in characters.
	 *
	 * Alt text is meant to be a sentence or two. The limit is shown next to
	 * the fields and enforced on the server, so nothing is cut off silently.
	 *
	 * @since 1.0.0
	 */
	public const MAX_ALT_LENGTH = 500;

	/**
	 * Number of images per page in the missing-alt table.
	 */
	private const PER_PAGE = 50;

	/**
	 * Query arguments that carry the outcome of a form submission. WordPress
	 * removes them from the address bar once the page has loaded, so a
	 * reload does not show the same notice again.
	 *
	 * @var string[]
	 */
	private const RESULT_ARGS = array( 'altrelay_result', 'altrelay_count', 'altrelay_refused', 'altrelay_skipped' );

	/**
	 * Review operations, mapped to the result key shown after the redirect.
	 *
	 * @var array<string, string>
	 */
	private const OPERATIONS = array(
		'fill'       => 'filled',
		'decorative' => 'decorative',
		'unmark'     => 'unmarked',
	);

	/**
	 * The sync engine used by the form handlers.
	 *
	 * @var AltRelay_Sync
	 */
	private AltRelay_Sync $sync;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param AltRelay_Sync|null $sync The sync engine. Default: a new one.
	 */
	public function __construct( ?AltRelay_Sync $sync = null ) {
		$this->sync = $sync ?? new AltRelay_Sync();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_welcome_notice' ) );
		add_action( 'admin_post_altrelay_save_alts', array( $this, 'handle_save_alts' ) );
		add_action( 'admin_post_altrelay_review', array( $this, 'handle_review' ) );
		add_action( 'admin_post_altrelay_review_first', array( $this, 'handle_review_first' ) );
		add_action( 'admin_post_altrelay_clear_errors', array( $this, 'handle_clear_errors' ) );
		add_action( 'admin_post_altrelay_dismiss_welcome', array( $this, 'handle_dismiss_welcome' ) );
		add_action( 'admin_post_altrelay_resync', array( $this, 'handle_resync' ) );
		add_action( 'admin_post_altrelay_run_queue', array( $this, 'handle_run_queue' ) );
		add_action( 'admin_post_altrelay_mark_image', array( $this, 'handle_mark_image' ) );
		add_action( 'admin_post_altrelay_list_image_again', array( $this, 'handle_list_image_again' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		add_filter( 'plugin_action_links_' . ALTRELAY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Let WordPress drop the result arguments from the address bar.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $args Query arguments WordPress removes after a page loads.
	 * @return mixed
	 */
	public function removable_query_args( mixed $args ): mixed {
		return is_array( $args ) ? array_merge( $args, self::RESULT_ARGS ) : $args;
	}

	/**
	 * Register the dashboard as a Media submenu.
	 *
	 * A submenu keeps the admin sidebar uncluttered, which is what the plugin
	 * directory reviewers expect for a single-screen plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$hook = add_submenu_page(
			'upload.php',
			__( 'AltRelay Dashboard', 'altrelay' ),
			__( 'AltRelay', 'altrelay' ),
			'upload_files',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load_dashboard' ) );
		}
	}

	/**
	 * Do the dashboard's writes before the page is rendered.
	 *
	 * Rendering only reads. What opening the dashboard changes happens here,
	 * on the page's load hook: the welcome notice has done its job, and a
	 * queue that stopped is set going again. The latter writes only when
	 * something needs repair: a lost worker run, or jobs left behind by an
	 * Action Scheduler that is gone.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_dashboard(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// Reaching the dashboard is what the welcome notice asks for.
		if ( get_option( self::WELCOME_OPTION ) ) {
			delete_option( self::WELCOME_OPTION );
		}

		AltRelay_Queue::wake();
	}

	/**
	 * URL of the dashboard, optionally with query arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, int|string> $args Query arguments.
	 * @return string
	 */
	public static function dashboard_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'upload.php' ) );
	}

	/**
	 * Add a "Dashboard" link to the plugin's row on the Plugins screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function plugin_action_links( array $links ): array {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::dashboard_url() ),
			esc_html__( 'Dashboard', 'altrelay' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Show a one-time notice after activation.
	 *
	 * Replaces an activation redirect, which takes the user somewhere they
	 * did not ask to go. The notice points at the one thing worth doing
	 * before syncing starts: deciding about decorative images.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_welcome_notice(): void {
		if ( ! get_option( self::WELCOME_OPTION ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && ! in_array( $screen->id, array( 'plugins', 'dashboard', 'upload' ), true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=altrelay_dismiss_welcome' ), 'altrelay_dismiss_welcome' );

		printf(
			'<div class="notice notice-info altrelay-welcome"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a></p></div>',
			esc_html__( 'AltRelay is active. It treats an empty alt text as "not written yet" and fills it from the Media Library. If your site uses empty alt text on purpose for decorative images, review those first.', 'altrelay' ),
			esc_url( self::dashboard_url() ),
			esc_html__( 'Review images', 'altrelay' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'altrelay' )
		);
	}

	/**
	 * Dismiss the welcome notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_dismiss_welcome(): void {
		check_admin_referer( 'altrelay_dismiss_welcome' );
		self::require_capability( 'upload_files' );

		delete_option( self::WELCOME_OPTION );

		$referer = wp_get_referer();
		wp_safe_redirect( is_string( $referer ) && '' !== $referer ? $referer : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Stop the request unless the current user has a capability.
	 *
	 * @param string $capability The capability.
	 * @return void
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'altrelay' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Enqueue admin CSS on the plugin dashboard page only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'media_page_altrelay' !== $hook_suffix ) {
			return;
		}

		$css_file = ALTRELAY_PLUGIN_DIR . 'assets/css/admin.css';
		$js_file  = ALTRELAY_PLUGIN_DIR . 'assets/js/admin.js';

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'altrelay-admin',
				ALTRELAY_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				(string) filemtime( $css_file )
			);
		}

		// Asks before a button changes every post that uses an image.
		if ( file_exists( $js_file ) ) {
			wp_enqueue_script(
				'altrelay-admin',
				ALTRELAY_PLUGIN_URL . 'assets/js/admin.js',
				array(),
				(string) filemtime( $js_file ),
				true
			);
		}
	}

	/**
	 * Read a non-negative integer from the query string.
	 *
	 * @param string $name     Parameter name.
	 * @param int    $fallback Value when the parameter is absent.
	 * @return int
	 */
	private static function query_int( string $name, int $fallback ): int {
		// Read-only pagination parameter; nothing is written, so no nonce is needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? absint( $_GET[ $name ] ) : $fallback;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'altrelay' ) );
		}

		$paged = max( 1, self::query_int( 'paged', 1 ) );
		$stats = AltRelay_Report::get_alt_text_stats();

		?>
		<div class="wrap altrelay-dashboard">
			<h1><?php esc_html_e( 'AltRelay Dashboard', 'altrelay' ); ?></h1>

			<?php $this->render_result_notice(); ?>
			<?php $this->render_sync_notice( AltRelay_Sync::last_sync() ); ?>
			<?php $this->render_queue_notice(); ?>
			<?php $this->render_problem_notices(); ?>
			<?php $this->render_summary( $stats ); ?>
			<?php $this->render_review_first(); ?>
			<?php $this->render_tabs( $stats ); ?>
			<?php
			// One list per view: each list reads posts, and the content lists
			// parse them, so a large site does not pay for all three at once.
			switch ( $this->current_tab() ) {
				case 'content':
					$this->render_review_section( self::query_int( 'review_before', 0 ) );
					break;
				case 'decorative':
					$this->render_decorative_section( self::query_int( 'decorative_before', 0 ) );
					break;
				default:
					$this->render_missing_alt_table( $stats, $paged );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The list the dashboard shows: "library" for the images without alt
	 * text, "content" for the empty alt texts in posts, "decorative" for the
	 * images marked decorative.
	 *
	 * A link to a later page of a content list, from before the lists had
	 * tabs, still opens that list.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// Read-only view parameter; nothing is written, so no nonce is needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( isset( self::tab_labels()[ $tab ] ) ) {
			return $tab;
		}

		if ( self::query_int( 'review_before', 0 ) > 0 ) {
			return 'content';
		}

		return self::query_int( 'decorative_before', 0 ) > 0 ? 'decorative' : 'library';
	}

	/**
	 * The dashboard's lists, by tab.
	 *
	 * @return array<string, string>
	 */
	private static function tab_labels(): array {
		return array(
			'library'    => __( 'Images without alt text', 'altrelay' ),
			'content'    => __( 'Empty alt text in your content', 'altrelay' ),
			'decorative' => __( 'Images marked decorative', 'altrelay' ),
		);
	}

	/**
	 * Render the tabs that switch between the dashboard's lists.
	 *
	 * @param array{total: int, with_alt: int, without_alt: int, decorative: int} $stats The alt text stats.
	 * @return void
	 */
	private function render_tabs( array $stats ): void {
		if ( 0 === $stats['total'] ) {
			return;
		}

		$current = $this->current_tab();

		?>
		<nav class="nav-tab-wrapper altrelay-tabs" aria-label="<?php esc_attr_e( 'AltRelay lists', 'altrelay' ); ?>">
			<?php foreach ( self::tab_labels() as $tab => $label ) : ?>
				<?php
				$args = 'library' === $tab ? array() : array_merge( array( 'tab' => $tab ), $this->type_args() );
				$text = 'library' === $tab && $stats['without_alt'] > 0
					/* translators: 1: tab name, 2: number of images */
					? sprintf( __( '%1$s (%2$s)', 'altrelay' ), $label, number_format_i18n( $stats['without_alt'] ) )
					: $label;
				?>
				<a href="<?php echo esc_url( self::dashboard_url( $args ) ); ?>" class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $text ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Show the outcome of the last form submission.
	 *
	 * @return void
	 */
	private function render_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status set by our own redirect.
		$result  = isset( $_GET['altrelay_result'] ) && is_string( $_GET['altrelay_result'] ) ? sanitize_key( wp_unslash( $_GET['altrelay_result'] ) ) : '';
		$count   = self::query_int( 'altrelay_count', 0 );
		$refused = self::query_int( 'altrelay_refused', 0 );

		if ( $refused > 0 ) {
			printf(
				'<div class="notice notice-warning is-dismissible altrelay-result" tabindex="-1"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of images */
						_n(
							'%s image was not marked because it is the only content of a link. Without alt text, the link would have no name for screen reader users. Fill its alt text, or remove the link first.',
							'%s images were not marked because each is the only content of a link. Without alt text, the links would have no name for screen reader users. Fill their alt text, or remove the links first.',
							$refused,
							'altrelay'
						),
						number_format_i18n( $refused )
					)
				)
			);
		}

		$plain = array(
			'review_first_on'  => __( 'Review first is on. AltRelay leaves every empty alt text alone until you fill it or turn this off.', 'altrelay' ),
			'review_first_off' => __( 'Review first is off. AltRelay fills empty alt text from the Media Library again.', 'altrelay' ),
			'errors_cleared'   => __( 'The list of failed updates was cleared.', 'altrelay' ),
			'queue_running'    => __( 'Nothing was left to start: WP-Cron is already running the waiting updates.', 'altrelay' ),
			'image_unused'     => __( 'The image is not used in any post yet. It is recorded as decorative and no longer listed as missing alt text.', 'altrelay' ),
			'image_started'    => __( 'AltRelay is marking the image decorative in every post that uses it, in the background. The image leaves this list once every copy is marked.', 'altrelay' ),
			'image_listed'     => __( 'The image is listed as missing alt text again. Where it is marked decorative in posts, it stays so; the list of images marked decorative below removes those marks.', 'altrelay' ),
		);

		if ( isset( $plain[ $result ] ) ) {
			printf( '<div class="notice notice-success is-dismissible altrelay-result" tabindex="-1"><p>%s</p></div>', esc_html( $plain[ $result ] ) );
			return;
		}

		$counted = array(
			/* translators: %s: number of images */
			'saved'      => _n( 'Saved alt text for %s image. Posts using it are being updated in the background.', 'Saved alt text for %s images. Posts using them are being updated in the background.', $count, 'altrelay' ),
			/* translators: %s: number of posts */
			'filled'     => _n( 'Filled alt text in %s post.', 'Filled alt text in %s posts.', $count, 'altrelay' ),
			/* translators: %s: number of posts */
			'decorative' => _n( 'Marked images as decorative in %s post.', 'Marked images as decorative in %s posts.', $count, 'altrelay' ),
			/* translators: %s: number of posts */
			'unmarked'   => _n( 'Removed the decorative mark in %s post.', 'Removed the decorative mark in %s posts.', $count, 'altrelay' ),
			/* translators: %s: number of images */
			'resync'     => _n( 'Syncing %s image again in the background.', 'Syncing %s images again in the background.', $count, 'altrelay' ),
			/* translators: %s: number of background updates */
			'ran_queue'  => _n( 'Ran %s background update.', 'Ran %s background updates.', $count, 'altrelay' ),
		);

		if ( isset( $counted[ $result ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible altrelay-result" tabindex="-1"><p>%s</p></div>',
				esc_html( sprintf( $counted[ $result ], number_format_i18n( $count ) ) )
			);
			$this->render_not_editable_notice();
			return;
		}

		$this->render_not_editable_notice();

		if ( 'none' === $result && 0 === $refused && 0 === self::query_int( 'altrelay_skipped', 0 ) ) {
			printf( '<div class="notice notice-warning is-dismissible altrelay-result" tabindex="-1"><p>%s</p></div>', esc_html__( 'Nothing was changed. Select at least one item, or check that you can edit it.', 'altrelay' ) );
		}
	}

	/**
	 * Say how many posts could not be changed because the user may not edit them.
	 *
	 * @return void
	 */
	private function render_not_editable_notice(): void {
		$skipped = self::query_int( 'altrelay_skipped', 0 );

		if ( $skipped < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible altrelay-result" tabindex="-1"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of posts */
					_n(
						'%s post was left alone, because you cannot edit it. An editor or administrator can change it.',
						'%s posts were left alone, because you cannot edit them. An editor or administrator can change them.',
						$skipped,
						'altrelay'
					),
					number_format_i18n( $skipped )
				)
			)
		);
	}

	/**
	 * Render the summary of recent sync activity.
	 *
	 * Posts the person who changed the alt text could not edit keep their
	 * old alt text. They are reported here, with a way for someone who can
	 * edit other people's posts to bring them up to date.
	 *
	 * @param array{post_count: int, skipped_count: int, skipped_attachments: list<int>, time: int}|null $last_sync What the last sync did.
	 * @return void
	 */
	private function render_sync_notice( ?array $last_sync ): void {
		if ( null === $last_sync || $last_sync['time'] < 1 ) {
			return;
		}

		$ago = human_time_diff( $last_sync['time'], time() );

		if ( $last_sync['post_count'] > 0 ) {
			printf(
				'<div class="notice notice-success altrelay-sync-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of posts updated, 2: human-readable time difference */
						_n(
							'Last sync: %1$s post updated %2$s ago.',
							'Last sync: %1$s posts updated %2$s ago.',
							$last_sync['post_count'],
							'altrelay'
						),
						number_format_i18n( $last_sync['post_count'] ),
						$ago
					)
				)
			);
		}

		if ( $last_sync['skipped_count'] < 1 ) {
			return;
		}

		?>
		<div class="notice notice-warning altrelay-skipped-notice">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of posts, 2: human-readable time difference */
						_n(
							'%1$s post was not updated %2$s ago, because the person who changed the alt text cannot edit it. It still shows the old alt text.',
							'%1$s posts were not updated %2$s ago, because the person who changed the alt text cannot edit them. They still show the old alt text.',
							$last_sync['skipped_count'],
							'altrelay'
						),
						number_format_i18n( $last_sync['skipped_count'] ),
						$ago
					)
				);
				?>
			</p>
			<?php if ( current_user_can( 'edit_others_posts' ) && ! empty( $last_sync['skipped_attachments'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="altrelay_resync" />
					<?php wp_nonce_field( 'altrelay_resync' ); ?>
					<p><button type="submit" class="button"><?php esc_html_e( 'Update them now', 'altrelay' ); ?></button></p>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'An editor or administrator can update them from this page.', 'altrelay' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a notice when propagation work is still waiting to run.
	 *
	 * Without this, a site whose WP-Cron is disabled (or whose loopback
	 * request is blocked) looks like the plugin silently did nothing.
	 *
	 * @return void
	 */
	private function render_queue_notice(): void {
		$pending = AltRelay_Queue::pending_count();

		if ( $pending < 1 ) {
			return;
		}

		?>
		<div class="notice notice-info altrelay-queue-notice">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of queued sync jobs */
						_n(
							'%s alt text update is waiting to run in the background.',
							'%s alt text updates are waiting to run in the background.',
							$pending,
							'altrelay'
						),
						number_format_i18n( $pending )
					)
				);
				?>
			</p>
			<?php if ( AltRelay_Queue::cron_pending_count() > 0 && current_user_can( 'edit_others_posts' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="altrelay_run_queue" />
					<?php wp_nonce_field( 'altrelay_run_queue' ); ?>
					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Run them now', 'altrelay' ); ?></button>
						<span class="description"><?php esc_html_e( 'Useful when WP-Cron does not run on this site. Each click works for up to 20 seconds.', 'altrelay' ); ?></span>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render notices for work that did not go through.
	 *
	 * A background job that failed and a post that could not be saved would
	 * otherwise be invisible: the image simply keeps its old alt text.
	 *
	 * @return void
	 */
	private function render_problem_notices(): void {
		$failed_jobs = AltRelay_Queue::failed_count();

		if ( $failed_jobs > 0 ) {
			printf(
				'<div class="notice notice-error altrelay-failed-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of failed background jobs */
						_n(
							'%s background update failed. Open Tools → Scheduled Actions and filter by "Failed" to see why.',
							'%s background updates failed. Open Tools → Scheduled Actions and filter by "Failed" to see why.',
							$failed_jobs,
							'altrelay'
						),
						number_format_i18n( $failed_jobs )
					)
				)
			);
		}

		$errors = get_transient( AltRelay_Sync::ERRORS_TRANSIENT );

		if ( ! is_array( $errors ) || empty( $errors['count'] ) || ! is_numeric( $errors['count'] ) ) {
			return;
		}

		$count   = (int) $errors['count'];
		$post_id = isset( $errors['post_id'] ) && is_numeric( $errors['post_id'] ) ? (int) $errors['post_id'] : 0;
		$message = isset( $errors['message'] ) && is_string( $errors['message'] ) ? $errors['message'] : '';

		// Only name the post to someone who may see it, and only show the
		// error itself, which can be a raw database message, to administrators.
		$can_see_post  = $post_id > 0 && current_user_can( 'edit_post', $post_id );
		$can_see_error = current_user_can( 'manage_options' );

		if ( $can_see_post && $can_see_error ) {
			/* translators: 1: post title, 2: error message */
			$where = sprintf( __( 'Most recent: "%1$s" (%2$s).', 'altrelay' ), get_the_title( $post_id ), $message );
		} elseif ( $can_see_post ) {
			/* translators: %s: post title */
			$where = sprintf( __( 'Most recent: "%s". An administrator can see the error message on this page.', 'altrelay' ), get_the_title( $post_id ) );
		} elseif ( $can_see_error ) {
			/* translators: %s: error message */
			$where = sprintf( __( 'Most recent error: %s.', 'altrelay' ), $message );
		} else {
			$where = __( 'An administrator can see the error message on this page.', 'altrelay' );
		}

		?>
		<div class="notice notice-error altrelay-errors-notice">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of failed updates, 2: where the most recent one happened, a sentence of its own */
						_n( 'AltRelay could not update %1$s post. %2$s', 'AltRelay could not update %1$s posts. %2$s', $count, 'altrelay' ),
						number_format_i18n( $count ),
						$where
					)
				);
				?>
			</p>
			<?php if ( ! empty( AltRelay_Sync::images_to_retry() ) ) : ?>
				<p><?php esc_html_e( 'The other posts using those images were updated. Once the cause is fixed, the images can be run again; posts already up to date are left as they are.', 'altrelay' ); ?></p>
				<?php if ( current_user_can( 'edit_others_posts' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="altrelay_resync" />
						<?php wp_nonce_field( 'altrelay_resync' ); ?>
						<p><button type="submit" class="button"><?php esc_html_e( 'Try those images again', 'altrelay' ); ?></button></p>
					</form>
				<?php else : ?>
					<p><?php esc_html_e( 'An editor or administrator can run them again from this page.', 'altrelay' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $can_see_error ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="altrelay_clear_errors" />
					<?php wp_nonce_field( 'altrelay_clear_errors' ); ?>
					<p><button type="submit" class="button"><?php esc_html_e( 'Clear this message', 'altrelay' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the summary bar showing alt text coverage.
	 *
	 * @param array{total: int, with_alt: int, without_alt: int, decorative: int} $stats The alt text stats.
	 * @return void
	 */
	private function render_summary( array $stats ): void {
		?>
		<div class="altrelay-summary">
			<p class="altrelay-summary__text">
				<?php
				if ( 0 === $stats['total'] ) {
					esc_html_e( 'No images found in the Media Library yet.', 'altrelay' );
				} else {
					// Two sentences, each in the plural form of its own number.
					printf(
						/* translators: 1: images with alt text, 2: total images; the plural form follows the total */
						esc_html( _n( '%1$s of %2$s image has alt text.', '%1$s of %2$s images have alt text.', $stats['total'], 'altrelay' ) ),
						esc_html( number_format_i18n( $stats['with_alt'] ) ),
						esc_html( number_format_i18n( $stats['total'] ) )
					);

					if ( $stats['decorative'] > 0 ) {
						echo ' ';
						printf(
							/* translators: %s: number of images marked decorative wherever they are used */
							esc_html( _n( '%s more image is marked decorative wherever it is used.', '%s more images are marked decorative wherever they are used.', $stats['decorative'], 'altrelay' ) ),
							esc_html( number_format_i18n( $stats['decorative'] ) )
						);
					}
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the "review first" switch.
	 *
	 * @return void
	 */
	private function render_review_first(): void {
		$is_on = AltRelay_Content::review_first();

		?>
		<div class="altrelay-panel altrelay-review-first">
			<h2><?php esc_html_e( 'Review first', 'altrelay' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					$is_on
						? __( 'Review first is on. AltRelay leaves every empty alt text alone, on your site and when a description changes. Go through the list below, fill what should be described and mark what is decorative, then turn this off.', 'altrelay' )
						: __( 'Review first is off. AltRelay treats an empty alt text as "not written yet" and fills it from the Media Library. Turn this on if your site uses empty alt text on purpose and you want to mark those images as decorative before anything is filled.', 'altrelay' )
				);
				?>
			</p>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="altrelay_review_first" />
					<input type="hidden" name="review_first" value="<?php echo esc_attr( $is_on ? '0' : '1' ); ?>" />
					<?php wp_nonce_field( 'altrelay_review_first' ); ?>
					<button type="submit" class="button">
						<?php echo esc_html( $is_on ? __( 'Turn review first off', 'altrelay' ) : __( 'Turn review first on', 'altrelay' ) ); ?>
					</button>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Only an administrator can change this setting.', 'altrelay' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the table of images without alt text, with inline editing.
	 *
	 * @param array{total: int, with_alt: int, without_alt: int, decorative: int} $stats Coverage numbers.
	 * @param int                                                                 $paged Current page number.
	 * @return void
	 */
	private function render_missing_alt_table( array $stats, int $paged ): void {
		if ( 0 === $stats['total'] ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Images without alt text in the Media Library', 'altrelay' ); ?></h2>
		<?php

		if ( 0 === $stats['without_alt'] ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Every image has alt text or is marked decorative. Nothing to do!', 'altrelay' )
			);
			$this->render_recorded_decorative( $stats['decorative'] );
			return;
		}

		$total_pages = max( 1, (int) ceil( $stats['without_alt'] / self::PER_PAGE ) );
		$image_ids   = $paged > $total_pages ? array() : AltRelay_Report::get_missing_alt_ids( $paged, self::PER_PAGE );

		if ( empty( $image_ids ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'There are no images on this page. Use the pagination below to go back.', 'altrelay' )
			);
			$this->render_pagination( $paged, $total_pages );
			return;
		}

		// One query for the whole page instead of one per image.
		$usage_map = AltRelay_Report::get_usage_counts( $image_ids );

		?>
		<p id="altrelay-alt-hint">
			<?php
			printf(
				/* translators: %s: maximum number of characters */
				esc_html__( 'Type a description and save. Every post that uses the image is updated in the background. A description can be up to %s characters long.', 'altrelay' ),
				esc_html( number_format_i18n( self::MAX_ALT_LENGTH ) )
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'WordPress suggests leaving the alt text empty for a decorative image, so an empty field can mean either "not described yet" or "decorative". For a decorative image, use Mark decorative: AltRelay marks the image decorative in every post that uses it, and the image leaves this list. It returns here if it is ever given alt text.', 'altrelay' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="altrelay_save_alts" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
			<?php wp_nonce_field( 'altrelay_save_alts' ); ?>
			<table class="wp-list-table widefat fixed striped altrelay-missing-table">
				<caption class="screen-reader-text">
					<?php esc_html_e( 'Images in the Media Library that have no alt text.', 'altrelay' ); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col" class="altrelay-col-thumb"><?php esc_html_e( 'Thumbnail', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-filename"><?php esc_html_e( 'Filename', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-usage"><?php esc_html_e( 'Used in', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-alt"><?php esc_html_e( 'Alt text', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-action"><?php esc_html_e( 'Action', 'altrelay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $image_ids as $image_id ) : ?>
						<?php
						$file_path   = get_attached_file( $image_id );
						$filename    = $file_path ? basename( $file_path ) : __( '(unknown)', 'altrelay' );
						$input_id    = 'altrelay-alt-' . $image_id;
						$usage_count = $usage_map[ $image_id ] ?? 0;
						$edit_link   = get_edit_post_link( $image_id );
						$marking     = AltRelay_Sync::marking_state( $image_id );
						?>
						<tr>
							<td class="altrelay-col-thumb">
								<?php $this->render_thumbnail( $image_id ); ?>
							</td>
							<td class="altrelay-col-filename">
								<?php echo esc_html( $filename ); ?>
							</td>
							<td class="altrelay-col-usage">
								<?php
								printf(
									/* translators: %s: number of posts */
									esc_html( _n( '%s post', '%s posts', $usage_count, 'altrelay' ) ),
									esc_html( number_format_i18n( $usage_count ) )
								);
								?>
							</td>
							<td class="altrelay-col-alt">
								<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>">
									<?php
									printf(
										/* translators: %s: image filename */
										esc_html__( 'Alt text for %s', 'altrelay' ),
										esc_html( $filename )
									);
									?>
								</label>
								<input type="text" class="altrelay-alt-input" id="<?php echo esc_attr( $input_id ); ?>" name="alt[<?php echo esc_attr( (string) $image_id ); ?>]" value="" maxlength="<?php echo esc_attr( (string) self::MAX_ALT_LENGTH ); ?>" aria-describedby="altrelay-alt-hint" />
								<?php $this->render_marking_state( $marking ); ?>
							</td>
							<td class="altrelay-col-action">
								<div class="altrelay-actions">
									<?php if ( $edit_link ) : ?>
										<?php /* translators: %s: image filename */ ?>
										<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small" aria-label="<?php echo esc_attr( sprintf( __( 'Edit %s in the Media Library', 'altrelay' ), $filename ) ); ?>">
											<?php esc_html_e( 'Edit', 'altrelay' ); ?>
										</a>
									<?php endif; ?>
									<?php /* The button belongs to the form below the table, so Enter in an alt text field still saves the alt text. */ ?>
									<button type="submit" form="altrelay-mark-image" name="attachment" value="<?php echo esc_attr( (string) $image_id ); ?>" class="button button-small" data-altrelay-confirm="<?php echo esc_attr( self::mark_image_question( $filename, $usage_count ) ); ?>">
										<span aria-hidden="true"><?php esc_html_e( 'Mark decorative', 'altrelay' ); ?></span>
										<?php /* translators: %s: image filename. The button's name for screen readers; start it with the words of the "Mark decorative" button, so voice control finds it. */ ?>
										<span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Mark decorative: %s, wherever it is used', 'altrelay' ), $filename ) ); ?></span>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save alt text', 'altrelay' ); ?></button>
			</p>
		</form>
		<form id="altrelay-mark-image" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="altrelay_mark_image" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
			<?php wp_nonce_field( 'altrelay_mark_image' ); ?>
		</form>
		<?php
		$this->render_pagination( $paged, $total_pages );
		$this->render_recorded_decorative( $stats['decorative'] );
	}

	/**
	 * Render the images marked decorative wherever they are used, with a way
	 * to list each one as missing alt text again.
	 *
	 * @param int $count How many there are.
	 * @return void
	 */
	private function render_recorded_decorative( int $count ): void {
		if ( $count < 1 ) {
			return;
		}

		$image_ids = AltRelay_Report::get_missing_alt_ids( 1, self::PER_PAGE, true );

		?>
		<details class="altrelay-recorded-decorative">
			<summary>
				<?php
				printf(
					/* translators: %s: number of images */
					esc_html( _n( '%s image has no alt text on purpose: it is marked decorative wherever it is used.', '%s images have no alt text on purpose: they are marked decorative wherever they are used.', $count, 'altrelay' ) ),
					esc_html( number_format_i18n( $count ) )
				);
				?>
			</summary>
			<?php if ( $count > count( $image_ids ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: number of images shown */
						esc_html__( 'The %s most recent are shown.', 'altrelay' ),
						esc_html( number_format_i18n( count( $image_ids ) ) )
					);
					?>
				</p>
			<?php endif; ?>
			<ul>
				<?php foreach ( $image_ids as $image_id ) : ?>
					<?php
					$file_path = get_attached_file( $image_id );
					$filename  = $file_path ? basename( $file_path ) : __( '(unknown)', 'altrelay' );
					?>
					<li>
						<?php $this->render_thumbnail( $image_id, $filename ); ?>
						<span class="altrelay-recorded-name"><?php echo esc_html( $filename ); ?></span>
						<button type="submit" form="altrelay-list-image-again" name="attachment" value="<?php echo esc_attr( (string) $image_id ); ?>" class="button button-small">
							<span aria-hidden="true"><?php esc_html_e( 'List as missing again', 'altrelay' ); ?></span>
							<?php /* translators: %s: image filename. The button's name for screen readers; start it with the words of the "List as missing again" button, so voice control finds it. */ ?>
							<span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'List as missing again: %s', 'altrelay' ), $filename ) ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<form id="altrelay-list-image-again" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="altrelay_list_image_again" />
			<?php wp_nonce_field( 'altrelay_list_image_again' ); ?>
		</form>
		<?php
	}

	/**
	 * Print an image's thumbnail, linked to the full image.
	 *
	 * A 60 pixel thumbnail is not enough to judge whether an image is
	 * decorative, so it opens the full-size file. The link shows only the
	 * thumbnail, so the thumbnail's alt text names it: which image it opens,
	 * which tells the links of a list apart.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $filename      The image's file name. Default: read from the attachment.
	 * @return void
	 */
	private function render_thumbnail( int $attachment_id, string $filename = '' ): void {
		$full_url = wp_get_attachment_url( $attachment_id );

		if ( ! is_string( $full_url ) || '' === $full_url ) {
			echo wp_kses_post( wp_get_attachment_image( $attachment_id, array( 60, 60 ), true, array( 'alt' => '' ) ) );
			return;
		}

		if ( '' === $filename ) {
			$file_path = get_attached_file( $attachment_id );
			$filename  = $file_path ? basename( $file_path ) : '';
		}

		/* translators: %s: image filename */
		$name = '' === $filename ? __( 'View the full image', 'altrelay' ) : sprintf( __( 'View the full image of %s', 'altrelay' ), $filename );

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $full_url ),
			wp_kses_post( wp_get_attachment_image( $attachment_id, array( 60, 60 ), true, array( 'alt' => $name ) ) )
		);
	}

	/**
	 * The question asked before an image is marked decorative everywhere.
	 *
	 * @param string $filename    The image's file name.
	 * @param int    $usage_count How many posts use it.
	 * @return string
	 */
	private static function mark_image_question( string $filename, int $usage_count ): string {
		return sprintf(
			/* translators: 1: image filename, 2: number of posts */
			_n(
				'Mark %1$s decorative in the %2$s post that uses it? Its copy gets empty alt text, and screen readers skip it.',
				'Mark %1$s decorative in all %2$s posts that use it? Every copy gets empty alt text, and screen readers skip them.',
				max( 1, $usage_count ),
				'altrelay'
			),
			$filename,
			number_format_i18n( max( 1, $usage_count ) )
		);
	}

	/**
	 * Print where marking an image decorative everywhere stands, below its
	 * alt text field.
	 *
	 * @param array{user: int, next: int, marked: int, refused: int, skipped: int, failed: int, done: bool}|null $state The progress.
	 * @return void
	 */
	private function render_marking_state( ?array $state ): void {
		if ( null === $state ) {
			return;
		}

		if ( ! $state['done'] ) {
			printf( '<p class="description altrelay-marking-note">%s</p>', esc_html__( 'Being marked decorative in the background.', 'altrelay' ) );
			return;
		}

		$notes = array(
			$state['marked'] < 1 ? __( 'Not marked decorative everywhere yet:', 'altrelay' ) : sprintf(
				/* translators: %s: number of posts */
				_n( 'Marked decorative in %s post, but not everywhere yet:', 'Marked decorative in %s posts, but not everywhere yet:', $state['marked'], 'altrelay' ),
				number_format_i18n( $state['marked'] )
			),
		);

		if ( $state['refused'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of copies */
				_n( '%s copy is the only content of a link and needs alt text.', '%s copies are the only content of a link and need alt text.', $state['refused'], 'altrelay' ),
				number_format_i18n( $state['refused'] )
			);
		}

		if ( $state['skipped'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of posts */
				_n( '%s post could not be edited by the person who asked. An editor or administrator can mark the image there.', '%s posts could not be edited by the person who asked. An editor or administrator can mark the image there.', $state['skipped'], 'altrelay' ),
				number_format_i18n( $state['skipped'] )
			);
		}

		if ( $state['failed'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of posts */
				_n( '%s post could not be saved.', '%s posts could not be saved.', $state['failed'], 'altrelay' ),
				number_format_i18n( $state['failed'] )
			);
		}

		echo '<ul class="altrelay-marking-note">';

		foreach ( $notes as $note ) {
			printf( '<li>%s</li>', esc_html( $note ) );
		}

		echo '</ul>';
	}

	/**
	 * Print a post's title with links to edit and view it.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $title   The post title, already defaulted.
	 * @return void
	 */
	private function render_post_cell( int $post_id, string $title ): void {
		$edit = get_edit_post_link( $post_id );

		if ( $edit ) {
			printf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit ), esc_html( $title ) );
		} else {
			printf( '<strong>%s</strong>', esc_html( $title ) );
		}

		$view = self::view_link( $post_id );

		if ( '' !== $view ) {
			printf(
				'<div class="altrelay-row-links"><a href="%1$s"><span aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a></div>',
				esc_url( $view ),
				esc_html__( 'View in context', 'altrelay' ),
				/* translators: %s: post title. The link's name for screen readers; start it with the words of the "View in context" link, so voice control finds it. */
				esc_html( sprintf( __( 'View in context: %s', 'altrelay' ), $title ) )
			);
		}
	}

	/**
	 * Where a post can be looked at: its page, or its preview while unpublished.
	 *
	 * @param int $post_id The post ID.
	 * @return string URL, or an empty string for posts without a page of their own.
	 */
	private static function view_link( int $post_id ): string {
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || ! is_post_type_viewable( $post_type ) ) {
			return '';
		}

		$link = 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : get_preview_post_link( $post_id );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Render the review of empty alt text inside post content.
	 *
	 * @param int $before_id Where this page of the list starts, or 0.
	 * @return void
	 */
	private function render_review_section( int $before_id ): void {
		$review = AltRelay_Report::get_empty_alt_review( $before_id, AltRelay_Report::REVIEW_PER_PAGE, $this->review_type() );

		?>
		<h2 id="altrelay-review"><?php esc_html_e( 'Empty alt text in your content', 'altrelay' ); ?></h2>
		<p class="altrelay-review-intro">
			<?php
			echo esc_html(
				AltRelay_Content::review_first()
					? __( 'These images have a description in the Media Library, but their copy in a post has no alt text. While review first is on, AltRelay leaves them alone. Fill the ones that should be described, and mark the purely decorative ones so AltRelay keeps leaving them alone.', 'altrelay' )
					: __( 'These images have a description in the Media Library, but their copy in a post has no alt text. AltRelay treats that as "not written yet": it fills these on your site and the next time the description changes. If an image is purely decorative, mark it so AltRelay leaves it alone.', 'altrelay' )
			);
			?>
		</p>
		<?php
		$this->render_type_filter();

		if ( empty( $review['rows'] ) ) {
			$this->render_empty_review( $before_id, $review, __( 'No empty alt text to review.', 'altrelay' ), 'review_before', '#altrelay-review' );
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="altrelay_review" />
			<input type="hidden" name="review_before" value="<?php echo esc_attr( (string) $before_id ); ?>" />
			<input type="hidden" name="altrelay_type" value="<?php echo esc_attr( $this->review_type() ); ?>" />
			<?php wp_nonce_field( 'altrelay_review' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="altrelay-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action for selected images', 'altrelay' ); ?></label>
					<select name="altrelay_bulk_action" id="altrelay-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'altrelay' ); ?></option>
						<option value="fill"><?php esc_html_e( 'Fill from library', 'altrelay' ); ?></option>
						<option value="decorative"><?php esc_html_e( 'Mark decorative', 'altrelay' ); ?></option>
					</select>
					<button type="submit" class="button action" name="altrelay_apply_bulk" value="1"><?php esc_html_e( 'Apply', 'altrelay' ); ?></button>
				</div>
			</div>
			<?php $this->render_row_count( count( $review['rows'] ), $review['next'] > 0 ); ?>
			<table class="wp-list-table widefat fixed striped altrelay-review-table">
				<caption class="screen-reader-text">
					<?php esc_html_e( 'Images without alt text in posts, whose Media Library entry has alt text.', 'altrelay' ); ?>
				</caption>
				<thead>
					<tr>
						<td class="manage-column check-column">
							<input type="checkbox" id="altrelay-select-all" class="altrelay-select-all" />
							<label for="altrelay-select-all" class="screen-reader-text"><?php esc_html_e( 'Select all', 'altrelay' ); ?></label>
						</td>
						<th scope="col" class="altrelay-col-thumb"><?php esc_html_e( 'Image', 'altrelay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post', 'altrelay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Media Library alt text', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-review-action"><?php esc_html_e( 'Action', 'altrelay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $review['rows'] as $row ) : ?>
						<?php
						$item       = $row['post_id'] . ':' . $row['attachment_id'];
						$check_id   = 'altrelay-item-' . $row['post_id'] . '-' . $row['attachment_id'];
						$title      = '' !== $row['post_title'] ? $row['post_title'] : __( '(no title)', 'altrelay' );
						$all_linked = $row['linked'] >= $row['copies'];
						?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="items[]" id="<?php echo esc_attr( $check_id ); ?>" value="<?php echo esc_attr( $item ); ?>" />
								<label for="<?php echo esc_attr( $check_id ); ?>" class="screen-reader-text">
									<?php
									printf(
										/* translators: 1: image alt text, 2: post title */
										esc_html__( 'Select "%1$s" in "%2$s"', 'altrelay' ),
										esc_html( $row['library_alt'] ),
										esc_html( $title )
									);
									?>
								</label>
							</th>
							<td class="altrelay-col-thumb">
								<?php $this->render_thumbnail( $row['attachment_id'] ); ?>
							</td>
							<td>
								<?php $this->render_post_cell( $row['post_id'], $title ); ?>
							</td>
							<td>
								<?php echo esc_html( $row['library_alt'] ); ?>
								<?php if ( $row['linked'] > 0 ) : ?>
									<p class="description altrelay-linked-note">
										<?php esc_html_e( 'This image is the only content of a link, so its alt text names the link. It needs alt text and cannot be marked decorative.', 'altrelay' ); ?>
									</p>
								<?php endif; ?>
							</td>
							<td class="altrelay-col-review-action">
								<?php /* translators: %s: post title */ ?>
								<button type="submit" class="button button-small" name="altrelay_single" value="<?php echo esc_attr( 'fill:' . $item ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Fill alt text in "%s"', 'altrelay' ), $title ) ); ?>">
									<?php esc_html_e( 'Fill', 'altrelay' ); ?>
								</button>
								<?php if ( ! $all_linked ) : ?>
									<?php /* translators: %s: post title */ ?>
									<button type="submit" class="button button-small" name="altrelay_single" value="<?php echo esc_attr( 'decorative:' . $item ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Mark decorative in "%s"', 'altrelay' ), $title ) ); ?>">
										<?php esc_html_e( 'Mark decorative', 'altrelay' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
		$this->render_list_navigation( $before_id, $review['next'], 'review_before', '#altrelay-review', __( 'Review pages', 'altrelay' ) );
	}

	/**
	 * The post type the review lists are narrowed to, or '' for all.
	 *
	 * @return string
	 */
	private function review_type(): string {
		// Read-only filter parameter; nothing is written, so no nonce is needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['altrelay_type'] ) && is_string( $_GET['altrelay_type'] ) ? sanitize_key( wp_unslash( $_GET['altrelay_type'] ) ) : '';

		return in_array( $type, $this->sync->get_allowed_post_types(), true ) ? $type : '';
	}

	/**
	 * The query argument that keeps the post-type filter, if one is set.
	 *
	 * @return array<string, string>
	 */
	private function type_args(): array {
		$type = $this->review_type();

		return '' === $type ? array() : array( 'altrelay_type' => $type );
	}

	/**
	 * The query arguments that keep the current list and its filter.
	 *
	 * @return array<string, string>
	 */
	private function list_args(): array {
		$tab = $this->current_tab();

		return array_merge( 'library' === $tab ? array() : array( 'tab' => $tab ), $this->type_args() );
	}

	/**
	 * The post type a review form was submitted with, or ''.
	 *
	 * @return string
	 */
	private static function posted_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the handler verified its nonce.
		return isset( $_POST['altrelay_type'] ) && is_string( $_POST['altrelay_type'] ) ? sanitize_key( wp_unslash( $_POST['altrelay_type'] ) ) : '';
	}

	/**
	 * Render the post-type filter for the review lists.
	 *
	 * Only shown when AltRelay syncs more than one post type.
	 *
	 * @return void
	 */
	private function render_type_filter(): void {
		$types = $this->sync->get_allowed_post_types();

		if ( count( $types ) < 2 ) {
			return;
		}

		$current = $this->review_type();

		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="altrelay-type-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $this->current_tab() ); ?>" />
			<label for="altrelay-type"><?php esc_html_e( 'Show images in', 'altrelay' ); ?></label>
			<select name="altrelay_type" id="altrelay-type">
				<option value=""><?php esc_html_e( 'All content types', 'altrelay' ); ?></option>
				<?php foreach ( $types as $type ) : ?>
					<?php
					$object = get_post_type_object( $type );
					$label  = $object instanceof WP_Post_Type && is_string( $object->labels->name ?? null ) ? $object->labels->name : $type;
					?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $current, $type ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'altrelay' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Say how many rows a page of a review list shows.
	 *
	 * @param int  $rows Rows on this page.
	 * @param bool $more Whether further pages follow.
	 * @return void
	 */
	private function render_row_count( int $rows, bool $more ): void {
		$text = $more
			/* translators: %s: number of images */
			? _n( '%s image on this page. More follow on the next page.', '%s images on this page. More follow on the next page.', $rows, 'altrelay' )
			/* translators: %s: number of images */
			: _n( '%s image on this page.', '%s images on this page.', $rows, 'altrelay' );

		printf( '<p class="altrelay-row-count">%s</p>', esc_html( sprintf( $text, number_format_i18n( $rows ) ) ) );
	}

	/**
	 * Render the list of images marked decorative, with a way to unmark them.
	 *
	 * @param int $before_id Where this page of the list starts, or 0.
	 * @return void
	 */
	private function render_decorative_section( int $before_id ): void {
		$review = AltRelay_Report::get_decorative_review( $before_id, AltRelay_Report::REVIEW_PER_PAGE, $this->review_type() );

		?>
		<h2 id="altrelay-decorative"><?php esc_html_e( 'Images marked decorative', 'altrelay' ); ?></h2>
		<p class="altrelay-review-intro">
			<?php esc_html_e( 'Screen readers skip these images, and AltRelay never changes their alt text. Remove the mark if an image turned out to carry information; it then gets its Media Library description back.', 'altrelay' ); ?>
		</p>
		<?php
		$this->render_type_filter();

		if ( empty( $review['rows'] ) ) {
			$this->render_empty_review( $before_id, $review, __( 'No images are marked decorative.', 'altrelay' ), 'decorative_before', '#altrelay-decorative' );
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="altrelay_review" />
			<input type="hidden" name="decorative_before" value="<?php echo esc_attr( (string) $before_id ); ?>" />
			<input type="hidden" name="altrelay_type" value="<?php echo esc_attr( $this->review_type() ); ?>" />
			<?php wp_nonce_field( 'altrelay_review' ); ?>
			<?php $this->render_row_count( count( $review['rows'] ), $review['next'] > 0 ); ?>
			<table class="wp-list-table widefat fixed striped altrelay-decorative-table">
				<caption class="screen-reader-text">
					<?php esc_html_e( 'Images marked decorative in posts.', 'altrelay' ); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col" class="altrelay-col-thumb"><?php esc_html_e( 'Image', 'altrelay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post', 'altrelay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Media Library alt text', 'altrelay' ); ?></th>
						<th scope="col" class="altrelay-col-review-action"><?php esc_html_e( 'Action', 'altrelay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $review['rows'] as $row ) : ?>
						<?php
						$item  = $row['post_id'] . ':' . $row['attachment_id'];
						$title = '' !== $row['post_title'] ? $row['post_title'] : __( '(no title)', 'altrelay' );
						?>
						<tr>
							<td class="altrelay-col-thumb">
								<?php $this->render_thumbnail( $row['attachment_id'] ); ?>
							</td>
							<td>
								<?php $this->render_post_cell( $row['post_id'], $title ); ?>
							</td>
							<td>
								<?php
								if ( '' !== $row['library_alt'] ) {
									echo esc_html( $row['library_alt'] );
								} else {
									printf( '<span class="description">%s</span>', esc_html__( 'No alt text in the Media Library yet.', 'altrelay' ) );
								}
								?>
							</td>
							<td class="altrelay-col-review-action">
								<?php /* translators: %s: post title */ ?>
								<button type="submit" class="button button-small" name="altrelay_single" value="<?php echo esc_attr( 'unmark:' . $item ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove decorative mark in "%s"', 'altrelay' ), $title ) ); ?>">
									<?php esc_html_e( 'Remove decorative mark', 'altrelay' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
		$this->render_list_navigation( $before_id, $review['next'], 'decorative_before', '#altrelay-decorative', __( 'Decorative image pages', 'altrelay' ) );
	}

	/**
	 * Render the message for a review page without rows.
	 *
	 * A page can be empty while the list goes on: reading stops after a fixed
	 * number of posts, so the message says how far it got instead of
	 * claiming there is nothing left.
	 *
	 * @param int                                           $before_id Where this page started, or 0.
	 * @param array{next: int, scanned: int, rows: mixed[]} $review    The page that was read.
	 * @param string                                        $none      Message for a list that is empty altogether.
	 * @param string                                        $param     Query parameter of the list.
	 * @param string                                        $anchor    Fragment of the list.
	 * @return void
	 */
	private function render_empty_review( int $before_id, array $review, string $none, string $param, string $anchor ): void {
		if ( $review['next'] > 0 ) {
			$message = sprintf(
				/* translators: %s: number of posts */
				_n(
					'Checked %s more post and found nothing to show. There are more posts to check.',
					'Checked %s more posts and found nothing to show. There are more posts to check.',
					$review['scanned'],
					'altrelay'
				),
				number_format_i18n( $review['scanned'] )
			);
		} else {
			$message = 0 === $before_id ? $none : __( 'You have reached the end of the list.', 'altrelay' );
		}

		printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $message ) );

		$this->render_list_navigation( $before_id, $review['next'], $param, $anchor, __( 'List pages', 'altrelay' ) );
	}

	/**
	 * Render "back to start" and "next" links for a review list.
	 *
	 * The lists are read forward from a post ID, so there is no page count
	 * and no way to jump: only on from here, or back to the start.
	 *
	 * @param int    $before_id Where this page started, or 0 on the first page.
	 * @param int    $next      Where the next page starts, or 0 on the last page.
	 * @param string $param     Query parameter that holds the starting point.
	 * @param string $anchor    Fragment to scroll to.
	 * @param string $label     Accessible name of the navigation.
	 * @return void
	 */
	private function render_list_navigation( int $before_id, int $next, string $param, string $anchor, string $label ): void {
		if ( 0 === $before_id && 0 === $next ) {
			return;
		}

		?>
		<nav class="tablenav altrelay-list-nav" aria-label="<?php echo esc_attr( $label ); ?>">
			<div class="tablenav-pages">
				<?php if ( $before_id > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( self::dashboard_url( $this->list_args() ) . $anchor ); ?>"><?php esc_html_e( 'Back to the start', 'altrelay' ); ?></a>
				<?php endif; ?>
				<?php if ( $next > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( self::dashboard_url( array_merge( $this->list_args(), array( $param => $next ) ) ) . $anchor ); ?>"><?php esc_html_e( 'Next page', 'altrelay' ); ?></a>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Render numbered pagination for the missing-alt table.
	 *
	 * @param int $paged       Current page number.
	 * @param int $total_pages Total number of pages.
	 * @return void
	 */
	private function render_pagination( int $paged, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$prev_url = self::dashboard_url( array( 'paged' => max( 1, $paged - 1 ) ) );
		$next_url = self::dashboard_url( array( 'paged' => min( $total_pages, $paged + 1 ) ) );

		?>
		<nav class="tablenav" aria-label="<?php esc_attr_e( 'Image pages', 'altrelay' ); ?>">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: total number of pages */
						esc_html( _n( '%s page', '%s pages', $total_pages, 'altrelay' ) ),
						esc_html( number_format_i18n( $total_pages ) )
					);
					?>
				</span>
				<?php if ( $paged > 1 ) : ?>
					<a class="prev-page button" href="<?php echo esc_url( $prev_url ); ?>">
						<span aria-hidden="true">&lsaquo;</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'altrelay' ); ?></span>
					</a>
				<?php endif; ?>
				<span class="paging-input">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( '%1$s of %2$s', 'altrelay' ),
						esc_html( number_format_i18n( $paged ) ),
						esc_html( number_format_i18n( $total_pages ) )
					);
					?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( $next_url ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'altrelay' ); ?></span>
						<span aria-hidden="true">&rsaquo;</span>
					</a>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Save alt text typed into the dashboard table.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_alts(): void {
		check_admin_referer( 'altrelay_save_alts' );
		self::require_capability( 'upload_files' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
		$submitted = isset( $_POST['alt'] ) && is_array( $_POST['alt'] ) ? wp_unslash( $_POST['alt'] ) : array();
		$saved     = 0;

		foreach ( (array) $submitted as $raw_id => $raw_alt ) {
			$attachment_id = absint( $raw_id );
			$alt           = is_string( $raw_alt ) ? self::limit_alt( AltRelay_Sync::sanitize_alt( $raw_alt ) ) : '';

			if ( 0 === $attachment_id || '' === $alt ) {
				continue;
			}

			if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}

			// Goes through the normal meta API, so the sync hook queues
			// propagation. The meta API unslashes what it is given, so the
			// value is slashed first, or a backslash typed into the field is lost.
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			++$saved;
		}

		$this->redirect_with_result( $saved > 0 ? 'saved' : 'none', $saved, array( 'paged' => max( 1, self::posted_int( 'paged' ) ) ) );
	}

	/**
	 * Mark an image without alt text decorative wherever it is used.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_mark_image(): void {
		check_admin_referer( 'altrelay_mark_image' );
		self::require_capability( 'upload_files' );

		$attachment_id = self::posted_int( 'attachment' );
		$extra         = array( 'paged' => max( 1, self::posted_int( 'paged' ) ) );

		if ( $attachment_id < 1 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			$this->redirect_with_result( 'none', 0, $extra );
			return;
		}

		// The posts are marked in the background, batch by batch, so an image
		// used in thousands of posts never needs one long request.
		$started = $this->sync->start_marking_decorative( $attachment_id, get_current_user_id() );
		$results = array(
			'recorded' => 'image_unused',
			'started'  => 'image_started',
		);

		$this->redirect_with_result( $results[ $started ] ?? 'none', 0, $extra );
	}

	/**
	 * List an image that was marked decorative wherever it is used as
	 * missing alt text again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_list_image_again(): void {
		check_admin_referer( 'altrelay_list_image_again' );
		self::require_capability( 'upload_files' );

		$attachment_id = self::posted_int( 'attachment' );
		$listed        = $attachment_id > 0 && current_user_can( 'edit_post', $attachment_id ) && AltRelay_Sync::forget_recorded_decorative( $attachment_id );

		$this->redirect_with_result( $listed ? 'image_listed' : 'none', 0 );
	}

	/**
	 * Cut alt text to the length the dashboard accepts.
	 *
	 * The field has a maxlength, but that is only a hint to the browser.
	 *
	 * @since 1.0.0
	 *
	 * @param string $alt Sanitized alt text.
	 * @return string
	 */
	public static function limit_alt( string $alt ): string {
		return trim( mb_substr( $alt, 0, self::MAX_ALT_LENGTH ) );
	}

	/**
	 * Fill, mark or unmark the images selected in a review list.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_review(): void {
		check_admin_referer( 'altrelay_review' );
		self::require_capability( 'upload_files' );

		list( $operation, $items ) = self::read_review_request();

		$changed = 0;
		$refused = 0;
		$skipped = array();

		foreach ( $items as $item ) {
			list( $post_id, $attachment_id ) = $item;

			// The lists only show posts the user can edit, but a post's
			// permissions can change while the list is open.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$skipped[ $post_id ] = true;
				continue;
			}

			if ( 'decorative' === $operation ) {
				$marked     = $this->sync->mark_post_decorative( $post_id, $attachment_id );
				$did_change = $marked['changed'];
				$refused   += $marked['refused'];
			} elseif ( 'unmark' === $operation ) {
				$did_change = $this->sync->unmark_post_decorative( $post_id, $attachment_id );
			} else {
				$did_change = $this->sync->fill_post( $post_id, $attachment_id );
			}

			if ( $did_change ) {
				++$changed;
			}
		}

		$unmarking = 'unmark' === $operation;
		$extra     = $unmarking
			? array( 'decorative_before' => self::posted_int( 'decorative_before' ) )
			: array( 'review_before' => self::posted_int( 'review_before' ) );

		if ( $refused > 0 ) {
			$extra['altrelay_refused'] = $refused;
		}

		if ( ! empty( $skipped ) ) {
			$extra['altrelay_skipped'] = count( $skipped );
		}

		$type = self::posted_type();

		if ( '' !== $type ) {
			$extra['altrelay_type'] = $type;
		}

		$this->redirect_with_result(
			$changed > 0 && isset( self::OPERATIONS[ $operation ] ) ? self::OPERATIONS[ $operation ] : 'none',
			$changed,
			array_filter( $extra ),
			$unmarking ? '#altrelay-decorative' : '#altrelay-review'
		);
	}

	/**
	 * Turn the "review first" switch on or off.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_review_first(): void {
		check_admin_referer( 'altrelay_review_first' );
		self::require_capability( 'manage_options' );

		$turn_on = 1 === self::posted_int( 'review_first' );

		// Autoloaded: the frontend reads it for every image with an empty alt.
		update_option( AltRelay_Content::REVIEW_FIRST_OPTION, $turn_on ? '1' : '0', true );

		$this->redirect_with_result( $turn_on ? 'review_first_on' : 'review_first_off', 0 );
	}

	/**
	 * Clear the record of posts that could not be updated.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_clear_errors(): void {
		check_admin_referer( 'altrelay_clear_errors' );

		// Only someone who can see the error messages may dismiss them, so
		// failed updates reach an administrator.
		self::require_capability( 'manage_options' );

		delete_transient( AltRelay_Sync::ERRORS_TRANSIENT );

		$this->redirect_with_result( 'errors_cleared', 0 );
	}

	/**
	 * Run the waiting WP-Cron jobs now.
	 *
	 * For sites where WP-Cron does not run, or not often. The jobs keep the
	 * user who made each change, so running them does not change what they
	 * are allowed to update.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_run_queue(): void {
		check_admin_referer( 'altrelay_run_queue' );
		self::require_capability( 'edit_others_posts' );

		$ran = ( new AltRelay_Queue( $this->sync ) )->work_through_queue( 20 );

		// WP-Cron can take the last waiting jobs between page load and click.
		$this->redirect_with_result( $ran > 0 ? 'ran_queue' : 'queue_running', $ran );
	}

	/**
	 * Sync again the images whose last sync had to skip posts, and those
	 * whose update stopped or failed for some posts.
	 *
	 * The jobs run as the person who asks, so posts they can edit are
	 * brought up to date.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_resync(): void {
		check_admin_referer( 'altrelay_resync' );
		self::require_capability( 'edit_others_posts' );

		$last   = AltRelay_Sync::last_sync();
		$images = array_unique( array_merge( null === $last ? array() : $last['skipped_attachments'], AltRelay_Sync::images_to_retry() ) );
		$queued = 0;

		foreach ( $images as $attachment_id ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				AltRelay_Queue::enqueue( $attachment_id, get_current_user_id() );
				++$queued;
			}
		}

		AltRelay_Sync::forget_skipped();

		// The failures are being run again. Any that fail once more are
		// recorded anew, so the old message would only repeat them.
		if ( ! empty( AltRelay_Sync::images_to_retry() ) ) {
			delete_transient( AltRelay_Sync::ERRORS_TRANSIENT );
		}

		$this->redirect_with_result( $queued > 0 ? 'resync' : 'none', $queued );
	}

	/**
	 * Work out which review operation was requested, and on which items.
	 *
	 * A per-row button carries "operation:post:attachment"; the bulk form
	 * carries an operation and a list of "post:attachment" items.
	 *
	 * @return array{0: string, 1: list<array{0: int, 1: int}>}
	 */
	private static function read_review_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle_review().
		if ( isset( $_POST['altrelay_single'] ) && is_string( $_POST['altrelay_single'] ) ) {
			$parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['altrelay_single'] ) ) );

			if ( 3 === count( $parts ) && isset( self::OPERATIONS[ $parts[0] ] ) ) {
				$item = self::parse_item( $parts[1] . ':' . $parts[2] );

				return array( $parts[0], null === $item ? array() : array( $item ) );
			}

			return array( '', array() );
		}

		$operation = isset( $_POST['altrelay_bulk_action'] ) && is_string( $_POST['altrelay_bulk_action'] )
			? sanitize_key( wp_unslash( $_POST['altrelay_bulk_action'] ) )
			: '';

		if ( ! isset( self::OPERATIONS[ $operation ] ) ) {
			return array( '', array() );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each item is parsed into integers below.
		$raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		// phpcs:enable

		$items = array();
		foreach ( (array) $raw_items as $raw_item ) {
			$item = is_string( $raw_item ) ? self::parse_item( $raw_item ) : null;

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return array( $operation, $items );
	}

	/**
	 * Parse a "post:attachment" item.
	 *
	 * @param string $raw Raw item.
	 * @return array{0: int, 1: int}|null
	 */
	private static function parse_item( string $raw ): ?array {
		if ( ! preg_match( '/^(\d+):(\d+)$/', $raw, $match ) ) {
			return null;
		}

		$post_id       = absint( $match[1] );
		$attachment_id = absint( $match[2] );

		return $post_id > 0 && $attachment_id > 0 ? array( $post_id, $attachment_id ) : null;
	}

	/**
	 * Read a non-negative integer from the submitted form.
	 *
	 * @param string $name Field name.
	 * @return int
	 */
	private static function posted_int( string $name ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the handler verified its nonce.
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? absint( $_POST[ $name ] ) : 0;
	}

	/**
	 * Redirect back to the dashboard with a result notice.
	 *
	 * @param string                    $result Result key.
	 * @param int                       $count  Number of items affected.
	 * @param array<string, int|string> $extra  Extra query arguments.
	 * @param string                    $anchor Fragment to scroll to.
	 * @return void
	 */
	private function redirect_with_result( string $result, int $count, array $extra = array(), string $anchor = '' ): void {
		// Back to the list the form came from.
		$tabs = array(
			'#altrelay-review'     => 'content',
			'#altrelay-decorative' => 'decorative',
		);

		if ( isset( $tabs[ $anchor ] ) ) {
			$extra = array_merge( array( 'tab' => $tabs[ $anchor ] ), $extra );
		}

		$url = self::dashboard_url(
			array_merge(
				$extra,
				array(
					'altrelay_result' => $result,
					'altrelay_count'  => $count,
				)
			)
		);

		wp_safe_redirect( $url . $anchor );
		exit;
	}
}
