<?php
/**
 * REST controller for GEO (AI visibility) data and prompt tracking.
 *
 * @package SCP
 */

namespace SCP\REST;

use SCP\GEO\AIO_Detector;
use SCP\GEO\Prompt_Tracker;
use SCP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * /scp/v1/geo/*
 */
class GEO_Controller {

	const NAMESPACE = 'scp/v1';

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
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$routes = array(
			'/geo/overview'  => array( \WP_REST_Server::READABLE, 'overview' ),
			'/geo/aio/run'   => array( \WP_REST_Server::CREATABLE, 'run_aio' ),
			'/geo/tracking'  => array( 'GET_POST', 'tracking' ),
			'/geo/checks'    => array( \WP_REST_Server::READABLE, 'checks' ),
			'/geo/run'       => array( \WP_REST_Server::CREATABLE, 'run_tracking' ),
		);
		foreach ( $routes as $path => list( $method, $callback ) ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					array(
						'methods'             => 'GET_POST' === $method ? array( 'GET', 'POST' ) : $method,
						'callback'            => array( $this, $callback ),
						'permission_callback' => array( $this, 'can_manage' ),
					),
				)
			);
		}
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
	 * GET /geo/overview — AIO citation stats + prompt tracking aggregates.
	 *
	 * @return WP_REST_Response
	 */
	public function overview(): WP_REST_Response {
		$tracker = new Prompt_Tracker( $this->settings );
		return new WP_REST_Response(
			array(
				'aio'             => ( new AIO_Detector( $this->settings ) )->summary(),
				'prompt_tracking' => $tracker->summary(),
				'engines'         => $tracker->available_engines(),
				'tracked_count'   => $this->tracked_count(),
				'track_cap'       => (int) ( $this->settings->all()['geo']['track_cap'] ?? 50 ),
			)
		);
	}

	/**
	 * POST /geo/aio/run {keyword_ids?: []} — manual AIO check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_aio( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'intval', (array) $request->get_param( 'keyword_ids' ) ) );
		try {
			$stats = ( new AIO_Detector( $this->settings ) )->run( $ids, 20 );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'scp_aio_failed', $e->getMessage(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response( $stats );
	}

	/**
	 * GET /geo/tracking — published keywords with track flags.
	 * POST /geo/tracking {keyword_ids: [], track: bool} — set flags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tracking( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_keywords';

		if ( $request->get_method() === 'POST' ) {
			$ids   = array_filter( array_map( 'intval', (array) $request->get_param( 'keyword_ids' ) ) );
			$track = (bool) $request->get_param( 'track' );
			if ( empty( $ids ) ) {
				return new WP_Error( 'scp_bad_request', __( 'keyword_ids is required.', 'seo-content-pilot' ), array( 'status' => 400 ) );
			}

			if ( $track ) {
				$cap     = (int) ( $this->settings->all()['geo']['track_cap'] ?? 50 );
				$current = $this->tracked_count();
				if ( $current + count( $ids ) > $cap ) {
					return new WP_Error(
						'scp_cap',
						sprintf(
							/* translators: %d: tracking cap. */
							__( 'Tracking cap is %d keywords. Untrack some first or raise the cap in Settings.', 'seo-content-pilot' ),
							$cap
						),
						array( 'status' => 400 )
					);
				}
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET track_geo = %d WHERE id IN ({$placeholders})",
					...[ $track ? 1 : 0, ...$ids ]
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = (array) $wpdb->get_results(
			"SELECT id, keyword, intent, status, track_geo FROM {$table}
			WHERE status = 'published' OR track_geo = 1
			ORDER BY track_geo DESC, id DESC LIMIT 200",
			ARRAY_A
		);

		return new WP_REST_Response(
			array(
				'items'         => $items,
				'tracked_count' => $this->tracked_count(),
				'track_cap'     => (int) ( $this->settings->all()['geo']['track_cap'] ?? 50 ),
			)
		);
	}

	/**
	 * GET /geo/checks?engine=&limit= — recent prompt tracking rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function checks( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table  = $wpdb->prefix . 'scp_geo_checks';
		$engine = sanitize_key( (string) $request->get_param( 'engine' ) );
		$limit  = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		$where  = '1=1';
		$params = array();
		if ( '' !== $engine ) {
			$where   .= ' AND c.engine = %s';
			$params[] = $engine;
		}
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT c.checked_at, c.engine, k.keyword, c.mentioned, c.cited,
						c.mention_position, c.sentiment, c.competitors_mentioned
				FROM {$table} c
				LEFT JOIN {$wpdb->prefix}scp_keywords k ON k.id = c.keyword_id
				WHERE {$where}
				ORDER BY c.checked_at DESC LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		return new WP_REST_Response( array( 'items' => $rows ) );
	}

	/**
	 * POST /geo/run {keyword_ids?: [], engines?: []} — manual tracking run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_tracking( WP_REST_Request $request ) {
		$ids     = array_filter( array_map( 'intval', (array) $request->get_param( 'keyword_ids' ) ) );
		$engines = array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'engines' ) ) );

		try {
			$stats = ( new Prompt_Tracker( $this->settings ) )->run( $ids, $engines );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'scp_geo_failed', $e->getMessage(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response( $stats );
	}

	/**
	 * Count of tracked keywords.
	 *
	 * @return int
	 */
	private function tracked_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scp_keywords WHERE track_geo = 1" );
	}
}
