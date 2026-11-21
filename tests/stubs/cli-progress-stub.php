<?php
/**
 * Stub for the php-cli-tools progress bar that WP-CLI returns from
 * WP_CLI\Utils\make_progress_bar().
 *
 * @package AltRelay
 */

namespace cli\progress;

/**
 * Records ticks so tests can assert progress was reported.
 */
class Bar {

	/**
	 * Number of ticks.
	 *
	 * @var int
	 */
	public int $ticks = 0;

	/**
	 * Whether finish() was called.
	 *
	 * @var bool
	 */
	public bool $finished = false;

	/**
	 * Advance the bar.
	 *
	 * @param int $increment Steps.
	 * @return void
	 */
	public function tick( int $increment = 1 ): void {
		$this->ticks += $increment;
	}

	/**
	 * Finish the bar.
	 *
	 * @return void
	 */
	public function finish(): void {
		$this->finished = true;
	}
}
