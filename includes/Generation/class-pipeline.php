<?php
/**
 * Article generation orchestrator — step-based and resumable.
 *
 * Each step (outline -> sections -> finalize -> publish) executes as one
 * unit (< 90s), persists its artifacts into scp_jobs.payload, and advances
 * scp_jobs.step. The synchronous run_sync() path (single-article "Generate"
 * screen) simply loops run_step(); the async Job_Queue calls run_step()
 * once per Action Scheduler event and reschedules until done.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\Logger;
use SCP\Settings;
use SCP\LLM\LLM_Factory;
use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;
use SCP\Publishing\Publisher;

/**
 * outline -> sections -> finalize -> publish
 */
class Pipeline {

	const STEPS = array( 'outline', 'sections', 'finalize', 'image', 'publish' );

	/**
	 * Rough per-1M-token prices [input, output] for cost estimation.
	 */
	const MODEL_PRICES = array(
		'gpt-4o'       => array( 2.50, 10.00 ),
		'gpt-4o-mini'  => array( 0.15, 0.60 ),
		'gpt-4.1'      => array( 2.00, 8.00 ),
		'gpt-4.1-mini' => array( 0.40, 1.60 ),
	);

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
	 * Create a job row for a keyword.
	 *
	 * @param string   $keyword    Target keyword.
	 * @param int|null $keyword_id Optional scp_keywords id.
	 * @return int Job ID.
	 */
	public function create_job( string $keyword, ?int $keyword_id = null ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'scp_jobs',
			array(
				'keyword_id' => $keyword_id,
				'status'     => 'pending',
				'step'       => 'outline',
				'payload'    => wp_json_encode(
					array(
						'keyword'   => trim( $keyword ),
						'sections'  => array(),
						'_attempts' => 0,
					)
				),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Load a job row.
	 *
	 * @param int $job_id Job ID.
	 * @return object|null
	 */
	public function get_job( int $job_id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}scp_jobs WHERE id = %d",
				$job_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Whether a job reached a terminal state.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function is_finished( int $job_id ): bool {
		$job = $this->get_job( $job_id );
		return ! $job || in_array( $job->status, array( 'done', 'failed' ), true );
	}

	/**
	 * Current consecutive-failure counter for the job's active step.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public function get_attempts( int $job_id ): int {
		$job     = $this->get_job( $job_id );
		$payload = $job ? ( json_decode( (string) $job->payload, true ) ?: array() ) : array();
		return (int) ( $payload['_attempts'] ?? 0 );
	}

	/**
	 * Set the consecutive-failure counter.
	 *
	 * @param int $job_id   Job ID.
	 * @param int $attempts Attempt count.
	 * @return void
	 */
	public function set_attempts( int $job_id, int $attempts ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}
		$payload              = json_decode( (string) $job->payload, true ) ?: array();
		$payload['_attempts'] = $attempts;
		$this->save( $job_id, (string) $job->status, (string) $job->step, $payload, (int) $job->tokens_used );
	}

	/**
	 * Mark a job failed (used by Job_Queue after retries are exhausted).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $error  Error message.
	 * @return void
	 */
	public function fail_job( int $job_id, string $error ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}
		$payload = json_decode( (string) $job->payload, true ) ?: array();
		$this->job_fail( $job_id, $payload, $error, (int) $job->tokens_used );
	}

	/**
	 * Reset a failed job back to pending so it resumes from its last step.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function reset_for_retry( int $job_id ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}
		$payload              = json_decode( (string) $job->payload, true ) ?: array();
		$payload['_attempts'] = 0;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_jobs',
			array(
				'status'  => 'pending',
				'payload' => wp_json_encode( $payload ),
				'error'   => null,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Synchronous end-to-end run (single-article Generate screen).
	 *
	 * @param string   $keyword    Target keyword.
	 * @param int|null $keyword_id Optional scp_keywords id.
	 * @return array{job_id:int,post_id:int,title:string,word_count:int,tokens:int}
	 * @throws LLM_Exception|\RuntimeException On failure.
	 */
	public function run_sync( string $keyword, ?int $keyword_id = null ): array {
		// Long-running admin request: 8+ sequential LLM calls won't fit in 30s.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$job_id = $this->create_job( $keyword, $keyword_id );

		for ( $i = 0; $i < 40; $i++ ) {
			try {
				$this->run_step( $job_id );
			} catch ( \Throwable $e ) {
				$this->fail_job( $job_id, $e->getMessage() );
				Logger::error( $e->getMessage(), 'pipeline', array( 'job_id' => $job_id ) );
				throw $e;
			}

			$job = $this->get_job( $job_id );
			if ( 'done' === $job->status ) {
				$payload = json_decode( (string) $job->payload, true ) ?: array();
				return array(
					'job_id'     => $job_id,
					'post_id'    => (int) $job->post_id,
					'title'      => (string) ( $payload['outline']['title'] ?? $keyword ),
					'word_count' => str_word_count( wp_strip_all_tags( (string) ( $payload['content'] ?? '' ) ) ),
					'tokens'     => (int) $job->tokens_used,
				);
			}
			if ( 'failed' === $job->status ) {
				throw new LLM_Exception( (string) $job->error );
			}
		}

		throw new LLM_Exception( 'Pipeline exceeded the maximum step count.' );
	}

	/**
	 * Execute exactly one pipeline step and persist the checkpoint.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 * @throws LLM_Exception|\RuntimeException On failure (caller decides retry).
	 */
	public function run_step( int $job_id ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			throw new \RuntimeException( "Job {$job_id} not found." );
		}
		if ( in_array( $job->status, array( 'done', 'failed' ), true ) ) {
			return;
		}

		$payload = json_decode( (string) $job->payload, true ) ?: array();
		$keyword = (string) ( $payload['keyword'] ?? '' );
		$tokens  = (int) $job->tokens_used;
		$all     = $this->settings->all();

		if ( 'pending' === $job->status ) {
			$this->mark_keyword( $job, 'generating' );
		}

		switch ( $job->step ) {
			case 'outline':
				$llm              = $this->llm();
				$words            = $this->settings->target_word_count();
				$result           = ( new Outline_Generator( $llm ) )->generate( $keyword, $all, $words );
				$payload['outline'] = $result['outline'];
				$tokens          += $result['tokens'];
				$this->save( $job_id, 'running', 'sections', $payload, $tokens );
				break;

			case 'sections':
				$llm      = $this->llm();
				$outline  = (array) ( $payload['outline'] ?? array() );
				$sections = array_values( (array) ( $outline['sections'] ?? array() ) );
				$written  = (array) ( $payload['sections'] ?? array() );
				$index    = count( $written );

				if ( $index >= count( $sections ) ) {
					$this->save( $job_id, 'running', 'finalize', $payload, $tokens );
					break;
				}

				$words       = $this->settings->target_word_count();
				$per_section = (int) max( 150, round( $words / max( 1, count( $sections ) ) ) );
				$headings    = array_map(
					fn( $s ) => (string) ( $s['heading'] ?? '' ),
					array_slice( $sections, 0, $index )
				);
				$prev        = empty( $headings ) ? '' : 'Already written sections: ' . implode( '; ', $headings );

				$writer = new Section_Writer( $llm );
				$piece  = $writer->write( $keyword, (array) $sections[ $index ], $outline, $prev, $per_section, $all );

				$written[]                = $piece['html'];
				$payload['sections']      = $written;
				$tokens                  += $piece['tokens'];
				$next_step                = ( count( $written ) >= count( $sections ) ) ? 'finalize' : 'sections';
				$this->save( $job_id, 'running', $next_step, $payload, $tokens );
				break;

			case 'finalize':
				$llm       = $this->llm();
				$outline   = (array) ( $payload['outline'] ?? array() );
				$content   = implode( "\n\n", (array) ( $payload['sections'] ?? array() ) );
				$finalizer = new SEO_Finalizer( $llm );

				$meta            = $finalizer->finalize(
					$keyword,
					(string) ( $outline['title'] ?? $keyword ),
					$content,
					(array) ( $outline['faq'] ?? array() ),
					$all
				);
				$tokens         += $meta['tokens'];
				$payload['meta'] = $meta['meta'];

				$placement         = $finalizer->enforce_keyword_placement( $content, $keyword );
				$tokens           += $placement['tokens'];
				$payload['content'] = $placement['content'];

				$this->save( $job_id, 'running', 'image', $payload, $tokens );
				break;

			case 'image':
				$payload['attachment_id'] = null;
				if ( ! empty( $all['content']['featured_image'] ) ) {
					$outline = (array) ( $payload['outline'] ?? array() );
					try {
						$payload['attachment_id'] = ( new Featured_Image( $this->settings ) )->create(
							$keyword,
							(string) ( $outline['title'] ?? $keyword )
						);
					} catch ( \Throwable $e ) {
						// Image is a nice-to-have; never fail the article over it.
						Logger::warning( 'Featured image skipped: ' . $e->getMessage(), 'pipeline', array( 'job_id' => $job_id ) );
					}
				}
				$this->save( $job_id, 'running', 'publish', $payload, $tokens );
				break;

			case 'publish':
				$outline = (array) ( $payload['outline'] ?? array() );
				$post_id = ( new Publisher() )->create_post(
					array(
						'keyword'       => $keyword,
						'keyword_id'    => $job->keyword_id ? (int) $job->keyword_id : null,
						'title'         => (string) ( $outline['title'] ?? $keyword ),
						'slug'          => (string) ( $outline['slug'] ?? $keyword ),
						'content'       => (string) ( $payload['content'] ?? '' ),
						'meta'          => (array) ( $payload['meta'] ?? array() ),
						'attachment_id' => $payload['attachment_id'] ?? null,
					),
					$all
				);

				$post         = get_post( $post_id );
				$scheduled_at = $post ? $post->post_date : null;

				$this->mark_keyword( $job, 'published' );
				$this->job_complete( $job_id, $post_id, $payload, $tokens, $scheduled_at );

				Logger::info(
					'Article generated',
					'pipeline',
					array(
						'job_id'  => $job_id,
						'post_id' => $post_id,
						'keyword' => $keyword,
						'tokens'  => $tokens,
					)
				);
				break;

			default:
				throw new \RuntimeException( "Unknown pipeline step: {$job->step}" );
		}

		// A step succeeded — reset the consecutive-failure counter.
		if ( 0 !== (int) ( $payload['_attempts'] ?? 0 ) ) {
			$this->set_attempts( $job_id, 0 );
		}
	}

	/**
	 * Build the configured LLM client.
	 *
	 * @return LLM_Client
	 */
	private function llm(): LLM_Client {
		return ( new LLM_Factory( $this->settings ) )->create();
	}

	/**
	 * Update the keyword pool row status when the job has a keyword_id.
	 *
	 * @param object $job    Job row.
	 * @param string $status New keyword status.
	 * @return void
	 */
	private function mark_keyword( object $job, string $status ): void {
		if ( empty( $job->keyword_id ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_keywords',
			array( 'status' => $status ),
			array( 'id' => (int) $job->keyword_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Persist a step checkpoint.
	 *
	 * @param int    $job_id  Job ID.
	 * @param string $status  Status.
	 * @param string $step    Next step.
	 * @param array  $payload Artifacts.
	 * @param int    $tokens  Tokens used so far.
	 * @return void
	 */
	private function save( int $job_id, string $status, string $step, array $payload, int $tokens ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_jobs',
			array(
				'status'      => $status,
				'step'        => $step,
				'payload'     => wp_json_encode( $payload ),
				'tokens_used' => $tokens,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark job done.
	 *
	 * @param int         $job_id       Job ID.
	 * @param int         $post_id      Created post.
	 * @param array       $payload      Artifacts.
	 * @param int         $tokens       Tokens used.
	 * @param string|null $scheduled_at Post date of the created post.
	 * @return void
	 */
	private function job_complete( int $job_id, int $post_id, array $payload, int $tokens, ?string $scheduled_at ): void {
		global $wpdb;
		$model = $this->settings->all()['llm']['model'] ?? '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_jobs',
			array(
				'status'        => 'done',
				'step'          => 'publish',
				'post_id'       => $post_id,
				'payload'       => wp_json_encode( $payload ),
				'tokens_used'   => $tokens,
				'cost_estimate' => $this->estimate_cost( $tokens, (string) $model ),
				'scheduled_at'  => $scheduled_at,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%d', '%s', '%d', '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark job failed.
	 *
	 * @param int    $job_id  Job ID.
	 * @param array  $payload Artifacts.
	 * @param string $error   Error message.
	 * @param int    $tokens  Tokens used.
	 * @return void
	 */
	private function job_fail( int $job_id, array $payload, string $error, int $tokens ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_jobs',
			array(
				'status'      => 'failed',
				'payload'     => wp_json_encode( $payload ),
				'error'       => $error,
				'tokens_used' => $tokens,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		// Return the keyword to the pool so it can be re-queued.
		$job = $this->get_job( $job_id );
		if ( $job && ! empty( $job->keyword_id ) ) {
			$this->mark_keyword( $job, 'new' );
		}
	}

	/**
	 * Rough cost estimate for known models.
	 *
	 * @param int    $tokens Total tokens.
	 * @param string $model  Model id.
	 * @return float|null USD estimate or null when unknown.
	 */
	private function estimate_cost( int $tokens, string $model ): ?float {
		if ( ! isset( self::MODEL_PRICES[ $model ] ) ) {
			return null;
		}
		$avg = ( self::MODEL_PRICES[ $model ][0] + self::MODEL_PRICES[ $model ][1] ) / 2;
		return round( $tokens / 1000000 * $avg, 4 );
	}
}
