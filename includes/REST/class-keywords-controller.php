<?php
/**
 * REST controller for the keyword pool.
 *
 * @package SCP
 */

namespace SCP\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * GET/POST/DELETE /scp/v1/keywords + /keywords/expand + /keywords/metrics + /keywords/providers
 */
class Keywords_Controller {

	const NAMESPACE = 'scp/v1';

	/**
	 * Settings service.
	 *
	 * @var \SCP\Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param \SCP\Settings $settings Settings service.
	 */
	public function __construct( \SCP\Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/keywords',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_keywords' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_keywords' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_keywords' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keywords/expand',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'expand' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keywords/metrics',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'metrics' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keywords/providers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'providers' ),
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
	 * GET /keywords?status=&per_page=&page=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_keywords( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table    = $wpdb->prefix . 'scp_keywords';
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$per_page = min( 200, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

		$where  = '1=1';
		$params = array();
		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params ) );

		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, keyword, source, cluster_id, volume, kd, cpc, intent, score, status, created_at FROM {$table} WHERE {$where} ORDER BY score IS NULL, score DESC, id DESC LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);

		return new WP_REST_Response(
			array(
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'items'    => $rows ?: array(),
			)
		);
	}

	/**
	 * POST /keywords {keywords, source?, enqueue?}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_keywords( WP_REST_Request $request ) {
		$raw     = $request->get_param( 'keywords' );
		$source  = sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'seed' ) );
		$enqueue = (bool) $request->get_param( 'enqueue' );
		if ( ! in_array( $source, array( 'seed', 'gsc' ), true ) ) {
			$source = 'seed';
		}

		if ( is_string( $raw ) ) {
			$keywords = preg_split( '/[\r\n,]+/', $raw );
		} elseif ( is_array( $raw ) ) {
			$keywords = $raw;
		} else {
			return new WP_Error( 'scp_bad_request', __( 'Provide keywords as text or array.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		$keywords = array_values(
			array_unique(
				array_filter(
					array_map(
						fn( $k ) => mb_strtolower( trim( (string) $k ) ),
						$keywords
					),
					fn( $k ) => mb_strlen( $k ) >= 2 && mb_strlen( $k ) <= 200
				)
			)
		);

		if ( empty( $keywords ) ) {
			return new WP_Error( 'scp_bad_request', __( 'No valid keywords provided.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'scp_keywords';
		$inserted = 0;

		foreach ( $keywords as $keyword ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$table} (keyword, source, status, created_at) VALUES (%s, %s, 'new', %s)",
					$keyword,
					$source,
					current_time( 'mysql' )
				)
			);
			if ( $result ) {
				$inserted += (int) $result;
			}
		}

		$response = array(
			'received'   => count( $keywords ),
			'inserted'   => $inserted,
			'duplicates' => count( $keywords ) - $inserted,
		);

		// One-click "opportunity → queue": enqueue right away.
		if ( $enqueue ) {
			$placeholders = implode( ',', array_fill( 0, count( $keywords ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE keyword IN ({$placeholders}) AND status = 'new'",
					...$keywords
				)
			);

			$queue    = new \SCP\Queue\Job_Queue( $this->settings );
			$enqueued = 0;
			foreach ( (array) $ids as $id ) {
				$id = (int) $id;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, array( 'status' => 'queued' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$kw = $wpdb->get_var( $wpdb->prepare( "SELECT keyword FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$queue->enqueue_keyword( (string) $kw, $id );
				$enqueued++;
			}
			$response['enqueued'] = $enqueued;
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * POST /keywords/expand {seeds: "a\nb" | ["a","b"]} — schedule one
	 * background expansion action per seed (max 20).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function expand( WP_REST_Request $request ) {
		$raw = $request->get_param( 'seeds' );

		if ( is_string( $raw ) ) {
			$seeds = preg_split( '/[\r\n,]+/', $raw );
		} elseif ( is_array( $raw ) ) {
			$seeds = $raw;
		} else {
			return new WP_Error( 'scp_bad_request', __( 'Provide seeds as text or array.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		$seeds = array_values(
			array_unique(
				array_filter(
					array_map(
						fn( $s ) => mb_strtolower( trim( (string) $s ) ),
						$seeds
					),
					fn( $s ) => mb_strlen( $s ) >= 2 && mb_strlen( $s ) <= 100
				)
			)
		);

		if ( empty( $seeds ) ) {
			return new WP_Error( 'scp_bad_request', __( 'No valid seeds provided.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}
		if ( count( $seeds ) > 20 ) {
			return new WP_Error( 'scp_too_many', __( 'Max 20 seeds per run.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		// Keep the seeds themselves in the pool.
		global $wpdb;
		$table = $wpdb->prefix . 'scp_keywords';
		foreach ( $seeds as $seed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$table} (keyword, source, status, created_at) VALUES (%s, 'seed', 'new', %s)",
					$seed,
					current_time( 'mysql' )
				)
			);
			\SCP\Queue\Job_Queue::schedule_action( 'scp_expand_seed', array( 'seed' => $seed ) );
		}

		return new WP_REST_Response(
			array(
				'scheduled' => count( $seeds ),
				'seeds'     => $seeds,
			)
		);
	}

	/**
	 * POST /keywords/metrics {ids: [...]} — paid enrichment for selected rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function metrics( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'scp_bad_request', __( 'ids is required.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 200 ) {
			return new WP_Error( 'scp_too_many', __( 'Max 200 keywords per metrics run.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		try {
			$summary = ( new \SCP\Keywords\Enricher( $this->settings ) )->enrich( $ids );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'scp_enrich_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		return new WP_REST_Response( $summary );
	}

	/**
	 * GET /keywords/providers — which expansion sources are available.
	 *
	 * @return WP_REST_Response
	 */
	public function providers(): WP_REST_Response {
		$dfs = new \SCP\Keywords\DataForSEO( $this->settings );
		return new WP_REST_Response(
			array(
				'autocomplete' => true,
				'llm'          => '' !== $this->settings->get_key( 'openai' ),
				'dataforseo'   => array(
					'configured' => $dfs->is_configured(),
					'balance'    => $dfs->balance(),
				),
			)
		);
	}

	/**
	 * DELETE /keywords {ids: [1,2,3]} — only rows not currently generating.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_keywords( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) );
		if ( empty( $ids ) ) {
			return new WP_REST_Response( array( 'deleted' => 0 ) );
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'scp_keywords';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE id IN ({$placeholders}) AND status NOT IN ('generating')",
				...$ids
			)
		);

		return new WP_REST_Response( array( 'deleted' => $deleted ) );
	}
}
