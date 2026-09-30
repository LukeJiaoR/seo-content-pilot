<?php
/**
 * Async job runner: drives the pipeline one step per scheduled action.
 *
 * Uses Action Scheduler when available (bundled), with a plain WP-Cron
 * single-event fallback so the queue still works if the library is missing.
 *
 * @package SCP
 */

namespace SCP\Queue;

use SCP\Generation\Pipeline;
use SCP\Logger;
use SCP\Settings;

/**
 * Chains pipeline steps as individual scheduled actions with retry/backoff.
 */
class Job_Queue {

	const HOOK         = 'scp_run_job_step';
	const GROUP        = 'scp';
	const MAX_ATTEMPTS = 3;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the step handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	/**
	 * Schedule the next step action for a job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $delay  Seconds from now.
	 * @return void
	 */
	public function dispatch( int $job_id, int $delay = 0 ): void {
		$timestamp = time() + max( 0, $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::HOOK, array( 'job_id' => $job_id ), self::GROUP );
			return;
		}

		// Fallback: plain WP-Cron single event.
		wp_schedule_single_event( $timestamp, self::HOOK, array( $job_id ) );
	}

	/**
	 * Cancel pending actions for all plugin jobs (deactivation).
	 *
	 * @return void
	 */
	public static function cancel_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_unschedule_all_actions( 'scp_expand_seed', array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( 'scp_expand_seed' );
	}

	/**
	 * Schedule a generic one-off plugin action (used by keyword expansion).
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Action args.
	 * @return void
	 */
	public static function schedule_action( string $hook, array $args = array() ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( time(), $hook, array_values( $args ) );
	}

	/**
	 * Enqueue a fresh job (created by REST controller).
	 *
	 * @param string $keyword    Keyword text.
	 * @param int    $keyword_id scp_keywords id.
	 * @return int Job ID.
	 */
	public function enqueue_keyword( string $keyword, int $keyword_id ): int {
		$pipeline = new Pipeline( $this->settings );
		$job_id   = $pipeline->create_job( $keyword, $keyword_id );
		$this->dispatch( $job_id );
		return $job_id;
	}

	/**
	 * Action handler: run one step, then reschedule or retry/fail.
	 *
	 * @param int $job_id Job ID (positional arg from AS / WP-Cron).
	 * @return void
	 */
	public function handle( $job_id ): void {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$pipeline = new Pipeline( $this->settings );

		try {
			$pipeline->run_step( $job_id );
		} catch ( \Throwable $e ) {
			$attempts = $pipeline->get_attempts( $job_id ) + 1;
			$pipeline->set_attempts( $job_id, $attempts );

			if ( $attempts < self::MAX_ATTEMPTS ) {
				// Exponential backoff: 2min, 4min, 8min.
				$delay = (int) ( pow( 2, $attempts ) * 60 );
				Logger::warning(
					$e->getMessage(),
					'queue',
					array(
						'job_id'  => $job_id,
						'attempt' => $attempts,
						'retry'   => $delay,
					)
				);
				$this->dispatch( $job_id, $delay );
			} else {
				$pipeline->fail_job( $job_id, $e->getMessage() );
				Logger::error(
					$e->getMessage(),
					'queue',
					array(
						'job_id'  => $job_id,
						'attempt' => $attempts,
					)
				);
			}
			return;
		}

		if ( ! $pipeline->is_finished( $job_id ) ) {
			$this->dispatch( $job_id );
		}
	}

	/**
	 * Retry a failed job from its last successful step.
	 *
	 * @param int $job_id Job ID.
	 * @return bool False when the job is not in failed state.
	 */
	public function retry( int $job_id ): bool {
		$pipeline = new Pipeline( $this->settings );
		$job      = $pipeline->get_job( $job_id );
		if ( ! $job || 'failed' !== $job->status ) {
			return false;
		}
		$pipeline->reset_for_retry( $job_id );
		$this->dispatch( $job_id );
		return true;
	}
}
