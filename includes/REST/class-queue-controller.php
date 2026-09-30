<?php
/**
 * REST controller for the generation queue.
 *
 * @package SCP
 */

namespace SCP\REST;

use SCP\Generation\Pipeline;
use SCP\Queue\Job_Queue;
use SCP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * /scp/v1/queue + /scp/v1/queue/{id}/retry + /scp/v1/jobs/{id}
 */
class Queue_Controller {

	const NAMESPACE = 'scp/v1';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Job queue.
	 *
	 * @var Job_Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->queue    = new Job_Queue( $settings );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/queue',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_queue' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enqueue' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/(?P<id>\d+)/retry',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Permission: admins only.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /queue — recent jobs + usage totals.
	 *
	 * @return WP_REST_Response
	 */
	public function list_queue(): WP_REST_Response {
		global $wpdb;
		$jobs_table     = $wpdb->prefix . 'scp_jobs';
		$keywords_table = $wpdb->prefix . 'scp_keywords';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT j.id, j.keyword_id, j.post_id, j.status, j.step, j.error,
					j.scheduled_at, j.tokens_used, j.cost_estimate, j.created_at, j.updated_at,
					k.keyword AS pool_keyword, j.payload
			FROM {$jobs_table} j
			LEFT JOIN {$keywords_table} k ON k.id = j.keyword_id
			ORDER BY j.id DESC LIMIT 50",
			ARRAY_A
		);

		$jobs = array_map(
			function ( array $row ) {
				$payload        = json_decode( (string) $row['payload'], true ) ?: array();
				$row['keyword'] = $row['pool_keyword'] ?? ( $payload['keyword'] ?? '' );
				$row['title']   = $payload['outline']['title'] ?? '';
				unset( $row['payload'], $row['pool_keyword'] );
				return $row;
			},
			$rows ?: array()
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$usage = $wpdb->get_row(
			"SELECT COALESCE(SUM(tokens_used),0) AS tokens,
					COALESCE(SUM(cost_estimate),0) AS cost,
					SUM(status='pending') AS pending,
					SUM(status='running') AS running,
					SUM(status='failed') AS failed,
					SUM(status='done') AS done
			FROM {$jobs_table}",
			ARRAY_A
		);

		return new WP_REST_Response(
			array(
				'jobs'  => $jobs,
				'usage' => $usage ?: array(),
			)
		);
	}

	/**
	 * POST /queue {keyword_ids: [...]} — create jobs and dispatch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enqueue( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'intval', (array) $request->get_param( 'keyword_ids' ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'scp_bad_request', __( 'keyword_ids is required.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		if ( '' === $this->settings->get_key( 'openai' ) ) {
			return new WP_Error(
				'scp_no_key',
				__( 'OpenAI API key is not configured. Add it in Settings first.', 'seo-content-pilot' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$keywords_table = $wpdb->prefix . 'scp_keywords';
		$placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, keyword, status FROM {$keywords_table} WHERE id IN ({$placeholders})",
				...$ids
			),
			ARRAY_A
		);

		$job_ids = array();
		$skipped = array();

		foreach ( $rows ?: array() as $row ) {
			if ( in_array( $row['status'], array( 'queued', 'generating', 'published' ), true ) ) {
				$skipped[] = (int) $row['id'];
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$keywords_table,
				array( 'status' => 'queued' ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			$job_ids[] = $this->queue->enqueue_keyword( (string) $row['keyword'], (int) $row['id'] );
		}

		return new WP_REST_Response(
			array(
				'enqueued' => count( $job_ids ),
				'job_ids'  => $job_ids,
				'skipped'  => $skipped,
			)
		);
	}

	/**
	 * POST /queue/{id}/retry — resume a failed job from its last step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );
		if ( ! $this->queue->retry( $job_id ) ) {
			return new WP_Error( 'scp_not_failed', __( 'Job is not in failed state.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'retried' => $job_id ) );
	}

	/**
	 * GET /jobs/{id} — job detail with artifact previews.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );
		$job    = ( new Pipeline( $this->settings ) )->get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'scp_not_found', __( 'Job not found.', 'seo-content-pilot' ), array( 'status' => 404 ) );
		}

		$payload = json_decode( (string) $job->payload, true ) ?: array();

		// Trim large fields to previews.
		if ( isset( $payload['content'] ) ) {
			$payload['content'] = mb_substr( (string) $payload['content'], 0, 500 ) . '…';
		}
		if ( isset( $payload['sections'] ) && is_array( $payload['sections'] ) ) {
			$payload['sections'] = array_map(
				fn( $s ) => mb_substr( wp_strip_all_tags( (string) $s ), 0, 120 ) . '…',
				$payload['sections']
			);
		}

		return new WP_REST_Response(
			array(
				'id'            => (int) $job->id,
				'keyword_id'    => $job->keyword_id ? (int) $job->keyword_id : null,
				'post_id'       => $job->post_id ? (int) $job->post_id : null,
				'status'        => $job->status,
				'step'          => $job->step,
				'error'         => $job->error,
				'scheduled_at'  => $job->scheduled_at,
				'tokens_used'   => (int) $job->tokens_used,
				'cost_estimate' => $job->cost_estimate,
				'payload'       => $payload,
			)
		);
	}
}
