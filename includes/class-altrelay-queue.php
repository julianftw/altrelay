<?php
/**
 * AltRelay Queue.
 *
 * Moves alt text propagation out of the request that triggered it. A single
 * alt change can touch thousands of posts, and doing that inline means the
 * request times out partway through with no way to resume.
 *
 * Uses Action Scheduler when it is available (WooCommerce, or the standalone
 * package) and falls back to WP-Cron otherwise. Both process one batch per
 * job and queue the rest, so no single request does all the work.
 *
 * A job is [ attachment ID, resume cursor, acting user ID ]. The alt text is
 * not part of a job. It is read from the attachment when the job runs, which
 * has three consequences:
 *
 * - A job can never write an outdated value, so a newer change does not
 *   have to hunt down and cancel older jobs first.
 * - Two changes to the same image before the worker runs collapse into one
 *   job, because their arguments are identical.
 * - The length of the alt text cannot make a job too large to store.
 *
 * On WP-Cron the jobs are not cron events of their own. Every event lives in
 * the "cron" option, which WordPress loads on every request, so a bulk change
 * of a few hundred images would make every page load carry them. The jobs
 * live in an option of their own that is not autoloaded, and one cron event
 * runs a worker that works through them. Jobs queued during one request are
 * written to that option once, when the request ends, so a bulk change of a
 * thousand images costs one write, not a thousand. On a network they are
 * held per site, so a change made while switched to another site is queued
 * on that site.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Queue
 *
 * @since 1.0.0
 *
 * @phpstan-type CronQueue array{waiting: array<string, array{0: int, 1: int, 2: int}>, running: array<string, array{0: int, 1: int, 2: int, 3: int}>, tries: array<string, int>}
 */
class AltRelay_Queue {


	/**
	 * Action Scheduler hook name.
	 *
	 * @since 1.0.0
	 */
	public const ACTION_HOOK = 'altrelay_process_sync';

	/**
	 * WP-Cron hook of the worker that runs the queued jobs.
	 *
	 * @since 1.0.0
	 */
	public const CRON_HOOK = 'altrelay_cron_sync';

	/**
	 * Action Scheduler group name.
	 *
	 * @since 1.0.0
	 */
	public const GROUP = 'altrelay';

	/**
	 * Number of posts processed per background batch.
	 *
	 * @since 1.0.0
	 */
	public const BATCH_LIMIT = 100;

	/**
	 * Option that holds the WP-Cron jobs. Not autoloaded.
	 *
	 * @since 1.0.0
	 */
	public const QUEUE_OPTION = 'altrelay_queue';

	/**
	 * Option row that serves as a lock while the job list is changed.
	 *
	 * @since 1.0.0
	 */
	public const LOCK_OPTION = 'altrelay_queue_lock';

	/**
	 * Sites of a network checked for jobs left behind when a plugin that
	 * brought Action Scheduler is deactivated network-wide.
	 */
	private const NETWORK_SITES_WATCHED = 100;

	/**
	 * Seconds after which a lock is assumed to belong to a request that died.
	 *
	 * The lock is held for one read and one write of a single option, which
	 * takes milliseconds.
	 */
	private const LOCK_EXPIRES = 2;

	/**
	 * Seconds to wait for the lock before going ahead without it. Longer
	 * than LOCK_EXPIRES, so a lock left by a request that died is taken
	 * over rather than ignored.
	 */
	private const LOCK_WAIT = 3;

	/**
	 * The value of the lock this request holds, or '' when it holds none.
	 *
	 * @var string
	 */
	private static string $lock_token = '';

	/**
	 * Seconds after which a job that was started but never finished is
	 * assumed to belong to a request that died, and is queued again.
	 */
	private const ABANDONED_AFTER = 600;

	/**
	 * Seconds after which a worker run that died is followed by the next.
	 */
	private const RETRY_AFTER = 60;

	/**
	 * Most jobs held back in a request before they are written.
	 */
	private const BUFFER_LIMIT = 100;

	/**
	 * How often a job may be abandoned before it is given up.
	 *
	 * A job is abandoned when the request running it dies: a fatal error, a
	 * timeout, a lack of memory. Running it again would most likely die the
	 * same way, and keep doing so every ten minutes.
	 */
	private const MAX_TRIES = 3;

	/**
	 * WP-Cron jobs queued in this request and not written yet, by site and key.
	 *
	 * @var array<int, array<string, array{0: int, 1: int, 2: int}>>
	 */
	private static array $buffer = array();

	/**
	 * Whether the end of this request writes the held-back jobs.
	 *
	 * @var bool
	 */
	private static bool $flush_hooked = false;

	/**
	 * The sync engine that processes the batches.
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
	 * Register the queue hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::ACTION_HOOK, array( $this, 'process' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'run_cron_queue' ), 10, 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'watch_action_scheduler' ), 10, 2 );
	}

	/**
	 * Make sure AltRelay's jobs are not stranded when a plugin that brought
	 * Action Scheduler is deactivated.
	 *
	 * Action Scheduler ships inside other plugins, such as WooCommerce or
	 * an SEO plugin. Without them it no longer runs, and the jobs it holds
	 * for AltRelay would wait forever. A worker run is scheduled shortly, and
	 * that run takes the jobs over if Action Scheduler is really gone.
	 *
	 * A plugin deactivated for the whole network leaves every site's jobs
	 * behind, so each site where AltRelay runs and Action Scheduler's table
	 * holds an AltRelay job gets its run, up to NETWORK_SITES_WATCHED sites.
	 * The sites beyond that take their jobs over when the AltRelay dashboard
	 * is opened there, or with wp altrelay status.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $plugin       The plugin that was deactivated.
	 * @param mixed $network_wide Whether it was deactivated for the whole network.
	 * @return void
	 */
	public static function watch_action_scheduler( mixed $plugin = '', mixed $network_wide = false ): void {
		if ( defined( 'ALTRELAY_PLUGIN_BASENAME' ) && ALTRELAY_PLUGIN_BASENAME === $plugin ) {
			return;
		}

		if ( true !== $network_wide || ! is_multisite() ) {
			if ( self::has_action_scheduler() && self::action_scheduler_count( 'pending' ) > 0 ) {
				self::schedule_worker( time() + MINUTE_IN_SECONDS );
			}
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::NETWORK_SITES_WATCHED,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			try {
				if ( AltRelay_Sync::active_on_this_site() && self::table_holds_jobs() ) {
					self::schedule_worker( time() + MINUTE_IN_SECONDS );
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Whether the current site's Action Scheduler table holds a pending
	 * AltRelay job, asked of the table itself: on another site of the network
	 * Action Scheduler's own functions would look at the wrong tables.
	 *
	 * @return bool
	 */
	private static function table_holds_jobs(): bool {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$shown = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The table belongs to a plugin that is going away.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE hook = %s AND status = 'pending' LIMIT 1", $wpdb->prefix . 'actionscheduler_actions', self::ACTION_HOOK ) );

		$wpdb->suppress_errors( $shown );

		return null !== $found;
	}

	/**
	 * Take over the jobs Action Scheduler holds for AltRelay once it is no
	 * longer there to run them.
	 *
	 * The jobs are read from Action Scheduler's table, marked as canceled
	 * there one by one, and queued on WP-Cron. Marking a job is what claims
	 * it, so two requests never both take the same one. Does nothing while
	 * Action Scheduler is loaded, even when AltRelay is told not to use it:
	 * it still runs the jobs it holds.
	 *
	 * @since 1.0.0
	 *
	 * @return int The number of jobs taken over.
	 */
	public static function adopt_stranded_jobs(): int {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		$taken = 0;

		foreach ( self::stranded_jobs() as $action_id => $job ) {
			if ( self::release_stranded_job( $action_id ) ) {
				self::hold( $job[0], $job[1], $job[2] );
				++$taken;
			}
		}

		if ( $taken > 0 ) {
			self::flush();
		}

		return $taken;
	}

	/**
	 * Read AltRelay's pending jobs straight from Action Scheduler's table.
	 *
	 * @return array<int, array{0: int, 1: int, 2: int}> Jobs by action ID.
	 */
	private static function stranded_jobs(): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		// Most sites never had Action Scheduler; a missing table is not
		// worth an error in the log.
		$shown = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler is not loaded to ask.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE hook = %s AND status = 'pending' ORDER BY action_id LIMIT %d", $wpdb->prefix . 'actionscheduler_actions', self::ACTION_HOOK, self::BATCH_LIMIT ), ARRAY_A );

		$wpdb->suppress_errors( $shown );

		$jobs = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$args = ! empty( $row['extended_args'] ) ? $row['extended_args'] : ( $row['args'] ?? '' );
			$args = is_string( $args ) ? json_decode( $args, true ) : null;

			if ( ! is_array( $args ) ) {
				continue;
			}

			$args          = array_values( $args );
			$attachment_id = self::whole_number( $args[0] ?? null );
			$after_id      = self::whole_number( $args[1] ?? 0 );
			$user_id       = self::whole_number( $args[2] ?? 0 );
			$action_id     = self::whole_number( $row['action_id'] ?? null );

			if ( null !== $attachment_id && $attachment_id > 0 && null !== $after_id && null !== $user_id && null !== $action_id ) {
				$jobs[ $action_id ] = array( $attachment_id, $after_id, $user_id );
			}
		}

		return $jobs;
	}

	/**
	 * Mark one of AltRelay's pending jobs in Action Scheduler's table as
	 * canceled, or all of them.
	 *
	 * @param int $action_id The action ID, or 0 for every pending job of AltRelay.
	 * @return bool Whether a job was marked.
	 */
	private static function release_stranded_job( int $action_id ): bool {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$shown = $wpdb->suppress_errors( true );

		if ( $action_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler is not loaded to ask.
			$changed = $wpdb->query( (string) $wpdb->prepare( "UPDATE %i SET status = 'canceled' WHERE action_id = %d AND hook = %s AND status = 'pending'", $table, $action_id, self::ACTION_HOOK ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler is not loaded to ask.
			$changed = $wpdb->query( (string) $wpdb->prepare( "UPDATE %i SET status = 'canceled' WHERE hook = %s AND status = 'pending'", $table, self::ACTION_HOOK ) );
		}

		$wpdb->suppress_errors( $shown );

		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Whether Action Scheduler is available and ready to take jobs.
	 *
	 * Its functions exist as soon as the library is loaded, but its data
	 * store is only set up on "init". A job scheduled before that is lost,
	 * so until then the WP-Cron fallback is used. Current versions announce
	 * the moment with an action of their own; for older ones the end of
	 * "init" is the earliest point that is certain.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool {
		$available = function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_get_scheduled_actions' );

		if ( $available ) {
			$available = did_action( 'action_scheduler_init' ) > 0 || ( did_action( 'init' ) > 0 && ! doing_action( 'init' ) );
		}

		/**
		 * Filters whether AltRelay queues work through Action Scheduler.
		 *
		 * Return false to force the WP-Cron fallback even when Action
		 * Scheduler is installed. Returning true without Action Scheduler has
		 * no effect.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $use_action_scheduler Whether to use Action Scheduler. Default: whether it is available.
		 */
		return $available && (bool) apply_filters( 'altrelay_use_action_scheduler', true );
	}

	/**
	 * Queue a propagation run for an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $user_id       The user who made the change, or 0.
	 * @return void
	 */
	public static function enqueue( int $attachment_id, int $user_id = 0 ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		self::schedule( $attachment_id, 0, max( 0, $user_id ) );
	}

	/**
	 * Schedule one batch on whichever back end is available.
	 *
	 * A job that is already waiting with the same arguments is not queued
	 * again. Only waiting jobs count: one that is running has already read
	 * the alt text and may be past posts the new value still has to reach.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $after_id      Resume after this post ID.
	 * @param int $user_id       The user who made the change, or 0.
	 * @return void
	 */
	private static function schedule( int $attachment_id, int $after_id, int $user_id ): void {
		$args = array( $attachment_id, $after_id, $user_id );

		if ( self::has_action_scheduler() ) {
			// The same job already waits, or, for the next batch of a job, a
			// newer change to the image does: it covers every post anyway, as
			// on the WP-Cron queue.
			$covering = 0 === $after_id ? array( $args ) : array( $args, array( $attachment_id, 0, $user_id ) );

			foreach ( $covering as $covering_args ) {
				$waiting = as_get_scheduled_actions(
					array(
						'hook'     => self::ACTION_HOOK,
						'args'     => $covering_args,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					),
					'ids'
				);

				if ( ! empty( $waiting ) ) {
					return;
				}
			}

			// Action Scheduler returns 0 when it could not store the job.
			if ( as_enqueue_async_action( self::ACTION_HOOK, $args, self::GROUP ) > 0 ) {
				return;
			}
		}

		self::hold( $attachment_id, $after_id, $user_id );
	}

	/**
	 * Hold a WP-Cron job back until the request ends.
	 *
	 * A new change (cursor 0) replaces a job for the same image and user
	 * held back earlier, because it covers every post anyway.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $after_id      Resume after this post ID.
	 * @param int $user_id       The user who made the change, or 0.
	 * @return void
	 */
	private static function hold( int $attachment_id, int $after_id, int $user_id ): void {
		$key  = self::cron_key( $attachment_id, $user_id );
		$site = get_current_blog_id();

		if ( 0 === $after_id || ! isset( self::$buffer[ $site ][ $key ] ) ) {
			self::$buffer[ $site ][ $key ] = array( $attachment_id, $after_id, $user_id );
		}

		if ( ! self::$flush_hooked ) {
			// Early, so the jobs are written before anything else that runs
			// at the end of the request could fail.
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 0, 0 );
			self::$flush_hooked = true;
		}

		if ( count( self::$buffer[ $site ] ) >= self::BUFFER_LIMIT ) {
			self::flush();
		}
	}

	/**
	 * Write the WP-Cron jobs held back in this request, and make sure a
	 * worker run is scheduled for them.
	 *
	 * Runs on shutdown; everything that reads the queue calls it first.
	 * Jobs held while switched to another site of a network are written to
	 * that site's queue.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$sites        = self::$buffer;
		self::$buffer = array();
		$current      = get_current_blog_id();

		foreach ( $sites as $site => $jobs ) {
			$switch = $site !== $current && is_multisite();

			if ( $switch ) {
				switch_to_blog( $site );
			}

			try {
				self::write_jobs( $jobs );
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Add jobs to the WP-Cron job list of the current site, and make sure a
	 * worker run is scheduled for them.
	 *
	 * @param array<string, array{0: int, 1: int, 2: int}> $jobs The jobs, by key.
	 * @return void
	 */
	private static function write_jobs( array $jobs ): void {
		self::cron_change(
			static function ( array $queue ) use ( $jobs ): array {
				foreach ( $jobs as $key => $job ) {
					// A continuation never replaces a newer job that is waiting.
					if ( 0 === $job[1] || ! isset( $queue['waiting'][ $key ] ) ) {
						$queue['waiting'][ $key ] = $job;
					}

					// A new change starts over, with every try left.
					if ( 0 === $job[1] ) {
						unset( $queue['tries'][ $key ] );
					}
				}

				return $queue;
			}
		);

		self::wake_cron_worker();
	}

	/**
	 * Action Scheduler callback: process one batch and queue the rest.
	 *
	 * Each run handles a single batch, so a large site never needs one long
	 * request, and a run that dies only loses its own batch.
	 *
	 * The arguments come out of a database row, so they are checked rather
	 * than trusted. Anything that is not three whole numbers is dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $attachment_id The attachment post ID.
	 * @param mixed $after_id      Resume after this post ID.
	 * @param mixed $user_id       The user who made the change, or 0.
	 * @return void
	 *
	 * @throws Throwable What the batch threw, so Action Scheduler marks the job as failed.
	 */
	public function process( mixed $attachment_id = 0, mixed $after_id = 0, mixed $user_id = 0 ): void {
		$attachment_id = self::whole_number( $attachment_id );
		$after_id      = self::whole_number( $after_id );
		$user_id       = self::whole_number( $user_id );

		if ( null === $attachment_id || null === $after_id || null === $user_id ) {
			return;
		}

		try {
			$next = $this->run_batch( $attachment_id, $after_id, $user_id );
		} catch ( Throwable $error ) {
			// Action Scheduler marks the job as failed; the dashboard also
			// offers to run the image again.
			self::record_stopped( $attachment_id, $error->getMessage() );
			throw $error;
		}

		if ( $next > 0 ) {
			self::schedule( $attachment_id, $next, $user_id );
		}
	}

	/**
	 * WP-Cron callback: work through the queued jobs for a while.
	 *
	 * Jobs are taken one at a time, so a newer change that arrives meanwhile
	 * is picked up, and a request that dies loses at most the one job it was
	 * running, which is queued again after a while, up to MAX_TRIES times. A
	 * job that throws is recorded for the dashboard and dropped, and the run
	 * goes on with the next one. Before the first job, the next run is
	 * scheduled, so even a run that dies outright is followed by another.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_cron_queue(): void {
		$this->work_through_queue();
	}

	/**
	 * Run WP-Cron jobs now, for a while.
	 *
	 * What the worker does on each run; the dashboard's "Run now" button and
	 * wp altrelay run-queue call it directly.
	 *
	 * @since 1.0.0
	 *
	 * @param int $seconds Optional. How long to work. Default: the altrelay_cron_time_limit filter.
	 * @return int The number of jobs run.
	 */
	public function work_through_queue( int $seconds = -1 ): int {
		self::adopt_stranded_jobs();
		self::flush();

		$started = microtime( true );

		/**
		 * Filters how long one WP-Cron run works through AltRelay's queue.
		 *
		 * At least one job runs, whatever the limit.
		 *
		 * @since 1.0.0
		 *
		 * @param int $seconds Seconds per run. Default 20.
		 */
		$budget = $seconds >= 0 ? $seconds : max( 0, (int) apply_filters( 'altrelay_cron_time_limit', 20 ) );
		$ran    = 0;

		self::schedule_worker( time() + $budget + self::RETRY_AFTER );

		// The job that just ran, with where its next batch starts. Marking it
		// done and taking the next one is a single write of the job list.
		$finished = null;

		do {
			$job      = self::cron_step( $finished, true );
			$finished = null;

			if ( null === $job ) {
				break;
			}

			list( $attachment_id, $after_id, $user_id ) = $job;

			$next = 0;

			try {
				$next = $this->run_batch( $attachment_id, $after_id, $user_id );
			} catch ( Throwable $error ) {
				// Retrying would throw again. The other jobs still run.
				self::record_stopped( $attachment_id, $error->getMessage() );
			}

			$finished = array( $attachment_id, $user_id, $next );
			++$ran;
		} while ( microtime( true ) - $started < $budget );

		if ( null !== $finished ) {
			self::cron_step( $finished, false );
		}

		self::wake_cron_worker();

		return $ran;
	}

	/**
	 * Record that the background update of an image stopped before it
	 * reached every post, so the dashboard can offer to run it again.
	 *
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $reason        What went wrong.
	 * @return void
	 */
	private static function record_stopped( int $attachment_id, string $reason ): void {
		AltRelay_Sync::record_error(
			0,
			sprintf(
				/* translators: 1: attachment ID, 2: error message */
				__( 'The background update of image %1$d stopped: %2$s', 'altrelay' ),
				$attachment_id,
				$reason
			),
			$attachment_id
		);
	}

	/**
	 * Make sure the WP-Cron queue is moving, for the dashboard and WP-CLI.
	 *
	 * Takes over jobs that Action Scheduler holds but can no longer run,
	 * writes jobs held back in this request and schedules a worker run if
	 * jobs are waiting and none is, which happens when a run was lost.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function wake(): void {
		self::adopt_stranded_jobs();
		self::flush();
		self::wake_cron_worker();
	}

	/**
	 * How many jobs are waiting or running in the WP-Cron queue.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function cron_pending_count(): int {
		self::flush();

		$queue = self::cron_read();

		return count( $queue['waiting'] ) + count( $queue['running'] );
	}

	/**
	 * Sync one batch of posts for an attachment.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $after_id      Resume after this post ID.
	 * @param int $user_id       The user who made the change, or 0.
	 * @return int Where the next batch starts, or 0 when there is none.
	 */
	private function run_batch( int $attachment_id, int $after_id, int $user_id ): int {
		// The image was deleted while the job was waiting.
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return 0;
		}

		// The dashboard's "Mark decorative" runs through the same jobs.
		$marking = $this->sync->continue_marking( $attachment_id, $after_id, self::BATCH_LIMIT, $user_id );

		if ( null !== $marking ) {
			return $marking;
		}

		$result = $this->sync->sync_attachment(
			$attachment_id,
			AltRelay_Sync::stored_alt( $attachment_id ),
			false,
			$after_id,
			self::BATCH_LIMIT,
			$user_id
		);

		return $result['done'] ? 0 : max( 1, $result['last_id'] );
	}

	/**
	 * Read a job argument as a non-negative whole number.
	 *
	 * @param mixed $value Job argument.
	 * @return int|null The number, or null when the argument is anything else.
	 */
	private static function whole_number( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : null;
	}

	/**
	 * Count the propagation runs still waiting to execute.
	 *
	 * Surfaced on the dashboard so a stalled queue is visible instead of
	 * looking like "the plugin did nothing".
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function pending_count(): int {
		return self::action_scheduler_count( 'pending' ) + self::cron_pending_count();
	}

	/**
	 * Count the runs Action Scheduler gave up on.
	 *
	 * A run fails when it throws or when its request dies partway through.
	 * The WP-Cron queue keeps no record of failures; a job whose request
	 * died is queued again instead.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function failed_count(): int {
		return self::action_scheduler_count( 'failed' );
	}

	/**
	 * Name of the back end that takes new jobs.
	 *
	 * @since 1.0.0
	 *
	 * @return string 'action-scheduler' or 'wp-cron'.
	 */
	public static function backend(): string {
		return self::has_action_scheduler() ? 'action-scheduler' : 'wp-cron';
	}

	/**
	 * Count Action Scheduler runs in a given state with a single query.
	 *
	 * @param string $status Action Scheduler status.
	 * @return int
	 */
	private static function action_scheduler_count( string $status ): int {
		if ( ! self::has_action_scheduler() ) {
			return 0;
		}

		$query = array(
			'hook'   => self::ACTION_HOOK,
			'group'  => self::GROUP,
			'status' => $status,
		);

		if ( class_exists( 'ActionScheduler' ) ) {
			$result = ActionScheduler::store()->query_actions( $query, 'count' );

			return is_numeric( $result ) ? (int) $result : 0;
		}

		return count( as_get_scheduled_actions( array_merge( $query, array( 'per_page' => -1 ) ), 'ids' ) );
	}

	/**
	 * Cancel every pending run, for every attachment.
	 *
	 * Used on deactivation and uninstall. Without it the queued actions stay
	 * behind and fail later, because the hook they call is no longer
	 * registered once the plugin is inactive.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function cancel_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
			/** @var \wpdb $wpdb */
			global $wpdb;

			// While a network is being walked site by site, a site that Action
			// Scheduler never ran on has no tables for it. There is nothing to
			// cancel there, and no reason to log a database error about it.
			$switched = is_multisite() && ms_is_switched();
			$shown    = $switched ? $wpdb->suppress_errors( true ) : false;

			// The group must be left empty. as_unschedule_all_actions() only
			// takes the "cancel everything for this hook" shortcut when exactly
			// one of hook and group is set; passing both falls through to an
			// exact-args lookup that never matches our queued arguments.
			as_unschedule_all_actions( self::ACTION_HOOK, array(), '' );

			if ( $switched ) {
				$wpdb->suppress_errors( $shown );
			}
		} else {
			// Jobs left behind by an Action Scheduler that is gone would
			// run, and fail, if it ever came back.
			self::release_stranded_job( 0 );
		}

		unset( self::$buffer[ get_current_blog_id() ] );
		delete_option( self::QUEUE_OPTION );
		self::unlock( true );

		// Removes the worker event, and any event of this hook that still
		// carries arguments, which wp_clear_scheduled_hook() would keep.
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The WP-Cron job list
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Read the WP-Cron job list.
	 *
	 * "waiting" holds jobs nobody has started, keyed by attachment and user,
	 * so a change to an image that is already waiting adds nothing. "running"
	 * holds jobs a worker has taken, with the time it took them. "tries"
	 * counts how often a job was abandoned in a row.
	 *
	 * @return CronQueue
	 */
	private static function cron_read(): array {
		return self::cron_parse( get_option( self::QUEUE_OPTION, array() ) );
	}

	/**
	 * Read the WP-Cron job list from the database, past every cache.
	 *
	 * For changes under the lock. The options cache can be out of date even
	 * after its entry is deleted: when this request first saw no list, it
	 * noted the option as missing, and keeps believing so after another
	 * request has added it. A change based on that would drop the other
	 * request's jobs.
	 *
	 * @return CronQueue
	 */
	private static function cron_read_stored(): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read under the lock; a cache would defeat it.
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::QUEUE_OPTION ) );

		return self::cron_parse( is_string( $stored ) ? maybe_unserialize( $stored ) : array() );
	}

	/**
	 * Check a stored WP-Cron job list and bring it into shape.
	 *
	 * @param mixed $stored The stored value.
	 * @return CronQueue
	 */
	private static function cron_parse( mixed $stored ): array {
		$queue = array(
			'waiting' => array(),
			'running' => array(),
			'tries'   => array(),
		);

		if ( ! is_array( $stored ) ) {
			return $queue;
		}

		foreach ( isset( $stored['tries'] ) && is_array( $stored['tries'] ) ? $stored['tries'] : array() as $key => $tries ) {
			$tries = self::whole_number( $tries );

			if ( is_string( $key ) && null !== $tries && $tries > 0 ) {
				$queue['tries'][ $key ] = $tries;
			}
		}

		foreach ( array( 'waiting', 'running' ) as $list ) {
			$jobs = isset( $stored[ $list ] ) && is_array( $stored[ $list ] ) ? $stored[ $list ] : array();

			foreach ( $jobs as $job ) {
				if ( ! is_array( $job ) ) {
					continue;
				}

				$attachment_id = self::whole_number( $job[0] ?? null );
				$after_id      = self::whole_number( $job[1] ?? null );
				$user_id       = self::whole_number( $job[2] ?? null );

				if ( null === $attachment_id || null === $after_id || null === $user_id || 0 === $attachment_id ) {
					continue;
				}

				$key = self::cron_key( $attachment_id, $user_id );

				if ( 'waiting' === $list ) {
					$queue['waiting'][ $key ] = array( $attachment_id, $after_id, $user_id );
				} else {
					$queue['running'][ $key ] = array( $attachment_id, $after_id, $user_id, (int) self::whole_number( $job[3] ?? 0 ) );
				}
			}
		}

		return $queue;
	}

	/**
	 * The key of a job in the WP-Cron job list.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @param int $user_id       The user who made the change, or 0.
	 * @return string
	 */
	private static function cron_key( int $attachment_id, int $user_id ): string {
		return $attachment_id . ':' . $user_id;
	}

	/**
	 * Change the WP-Cron job list under the lock.
	 *
	 * @param callable $change Receives the list and returns it changed.
	 *
	 * @phpstan-param callable(CronQueue): CronQueue $change
	 *
	 * @return void
	 */
	private static function cron_change( callable $change ): void {
		// Going ahead without the lock risks losing a concurrent change;
		// giving up would lose this one for certain.
		$locked = self::lock();

		try {
			// Another request may have changed the list since this one cached it.
			wp_cache_delete( self::QUEUE_OPTION, 'options' );

			$queue = $change( self::cron_read_stored() );

			if ( empty( $queue['waiting'] ) && empty( $queue['running'] ) ) {
				delete_option( self::QUEUE_OPTION );
			} else {
				update_option( self::QUEUE_OPTION, $queue, false );
			}
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}
	}

	/**
	 * Mark a job as done and take the next one, in a single write.
	 *
	 * A finished job's next batch goes to the end of the list, so one large
	 * image does not hold up every other one. When a newer change to the
	 * same image is already waiting, it covers every post and the next batch
	 * is dropped.
	 *
	 * A job that was abandoned is queued again, until it has been abandoned
	 * MAX_TRIES times in a row. Then it is given up and recorded for the
	 * dashboard, which offers to run the image again.
	 *
	 * @param array{0: int, 1: int, 2: int}|null $finished The job that finished, as attachment ID, user ID and where its next batch starts (0 for none), or null.
	 * @param bool                               $claim    Whether to take the next waiting job.
	 * @return array{0: int, 1: int, 2: int}|null The job taken, or null.
	 */
	private static function cron_step( ?array $finished, bool $claim ): ?array {
		$claimed   = null;
		$abandoned = array();

		self::cron_change(
			static function ( array $queue ) use ( $finished, $claim, &$claimed, &$abandoned ): array {
				$now = time();

				if ( null !== $finished ) {
					list( $attachment_id, $user_id, $next ) = $finished;

					$key = self::cron_key( $attachment_id, $user_id );

					// It finished, so it is not dying where it did before.
					unset( $queue['running'][ $key ], $queue['tries'][ $key ] );

					if ( $next > 0 && ! isset( $queue['waiting'][ $key ] ) ) {
						$queue['waiting'][ $key ] = array( $attachment_id, $next, $user_id );
					}
				}

				if ( ! $claim ) {
					return $queue;
				}

				foreach ( $queue['running'] as $key => $running ) {
					if ( $now - $running[3] <= self::ABANDONED_AFTER ) {
						continue;
					}

					unset( $queue['running'][ $key ] );

					// A newer change to the image is waiting and starts afresh.
					if ( isset( $queue['waiting'][ $key ] ) ) {
						unset( $queue['tries'][ $key ] );
						continue;
					}

					$tries = ( $queue['tries'][ $key ] ?? 0 ) + 1;

					if ( $tries >= self::MAX_TRIES ) {
						unset( $queue['tries'][ $key ] );
						$abandoned[] = $running[0];
						continue;
					}

					$queue['tries'][ $key ]   = $tries;
					$queue['waiting'][ $key ] = array( $running[0], $running[1], $running[2] );
				}

				foreach ( $queue['waiting'] as $key => $waiting ) {
					// The same image and user is already being worked on: a
					// second worker would race it. It waits for the next run.
					if ( isset( $queue['running'][ $key ] ) ) {
						continue;
					}

					unset( $queue['waiting'][ $key ] );
					$queue['running'][ $key ] = array( $waiting[0], $waiting[1], $waiting[2], $now );
					$claimed                  = $waiting;
					break;
				}

				return $queue;
			}
		);

		foreach ( array_unique( $abandoned ) as $attachment_id ) {
			self::record_stopped(
				$attachment_id,
				sprintf(
					/* translators: %d: number of tries */
					_n(
						'the request running it ended without finishing, %d time. The server may have run out of time or memory, or hit a fatal error.',
						'the request running it ended without finishing, %d times in a row. The server may have run out of time or memory, or hit a fatal error.',
						self::MAX_TRIES,
						'altrelay'
					),
					self::MAX_TRIES
				)
			);
		}

		return $claimed;
	}

	/**
	 * Schedule the next worker run for when it can do something.
	 *
	 * Now, if a job is waiting that nobody is running. Otherwise, when the
	 * first running job counts as abandoned: a run before that would find
	 * nothing to do. No run at all once the list is empty.
	 *
	 * @return void
	 */
	private static function wake_cron_worker(): void {
		$queue     = self::cron_read();
		$when      = null;
		$claimable = false;

		foreach ( array_keys( $queue['waiting'] ) as $key ) {
			if ( ! isset( $queue['running'][ $key ] ) ) {
				$when      = time();
				$claimable = true;
				break;
			}
		}

		if ( null === $when ) {
			foreach ( $queue['running'] as $running ) {
				$due  = $running[3] + self::ABANDONED_AFTER + 1;
				$when = null === $when ? $due : min( $when, $due );
			}
		}

		if ( null === $when ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		// A run that is already due will do. Otherwise exactly then: an
		// earlier run, such as the one a run schedules for itself in case it
		// dies, would find nothing to do.
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next && ( $next === $when || ( $claimable && $next <= $when ) ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $when, self::CRON_HOOK );
	}

	/**
	 * Make sure the worker runs no later than a given time, keeping an
	 * earlier run if one is scheduled.
	 *
	 * @param int $when Unix time.
	 * @return void
	 */
	private static function schedule_worker( int $when ): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next && $next <= $when ) {
			return;
		}

		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}

		wp_schedule_single_event( $when, self::CRON_HOOK );
	}
	/**
	 * Take the lock on the WP-Cron job list.
	 *
	 * An option row serves as the lock: INSERT IGNORE either creates it or
	 * changes nothing, in one statement, so two requests cannot both take
	 * it. add_option() cannot be used, because it overwrites an existing
	 * row. WordPress's upgrader locks the same way.
	 *
	 * The row holds when it was taken and a random part, so each lock is
	 * told apart from the next. A lock that expired is removed only while
	 * it still holds the value that was found expired, and a request only
	 * releases its own lock. Two requests that find the same expired lock
	 * therefore cannot both end up holding one, and a request that was
	 * slower than LOCK_EXPIRES cannot release the lock of the request that
	 * took over from it.
	 *
	 * @return bool Whether the lock was taken.
	 */
	private static function lock(): bool {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$deadline = microtime( true ) + self::LOCK_WAIT;

		do {
			$token = sprintf( '%.4F:%d', microtime( true ), wp_rand() );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must not be cached.
			$taken = $wpdb->query( (string) $wpdb->prepare( "INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $wpdb->options, self::LOCK_OPTION, $token ) );

			if ( is_int( $taken ) && $taken > 0 ) {
				self::$lock_token = $token;
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must not be cached.
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) );
			$since = is_string( $found ) ? strtok( $found, ':' ) : false;

			// The request that held it died: take it over, unless another
			// request already did.
			if ( is_string( $found ) && is_numeric( $since ) && microtime( true ) - (float) $since > self::LOCK_EXPIRES ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must not be cached.
				$wpdb->delete(
					$wpdb->options,
					array(
						'option_name'  => self::LOCK_OPTION,
						'option_value' => $found,
					)
				);
				continue;
			}

			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Release the lock on the WP-Cron job list.
	 *
	 * @param bool $any Whether to remove the lock whoever holds it, as
	 *                  when the queue is emptied. Otherwise only the lock
	 *                  this request took is removed.
	 * @return void
	 */
	private static function unlock( bool $any = false ): void {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type hint.
		/** @var \wpdb $wpdb */
		global $wpdb;

		$where = array( 'option_name' => self::LOCK_OPTION );

		if ( ! $any ) {
			if ( '' === self::$lock_token ) {
				return;
			}

			$where['option_value'] = self::$lock_token;
		}

		self::$lock_token = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must not be cached.
		$wpdb->delete( $wpdb->options, $where );
	}
}
