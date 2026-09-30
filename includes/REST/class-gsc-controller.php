<?php
/**
 * REST controller for GSC connection, sync, dashboard and opportunities.
 *
 * @package SCP
 */

namespace SCP\REST;

use SCP\GSC\Client;
use SCP\GSC\OAuth;
use SCP\GSC\Opportunities;
use SCP\GSC\Sync;
use SCP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * /scp/v1/gsc/*
 */
class GSC_Controller {

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
			'/gsc/status'        => array( \WP_REST_Server::READABLE, 'status' ),
			'/gsc/connect'       => array( \WP_REST_Server::READABLE, 'connect' ),
			'/gsc/sites'         => array( \WP_REST_Server::READABLE, 'sites' ),
			'/gsc/site'          => array( \WP_REST_Server::CREATABLE, 'save_site' ),
			'/gsc/disconnect'    => array( \WP_REST_Server::CREATABLE, 'disconnect' ),
			'/gsc/sync'          => array( \WP_REST_Server::CREATABLE, 'sync_now' ),
			'/gsc/overview'      => array( \WP_REST_Server::READABLE, 'overview' ),
			'/gsc/opportunities' => array( \WP_REST_Server::READABLE, 'opportunities' ),
		);
		foreach ( $routes as $path => list( $method, $callback ) ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					array(
						'methods'             => $method,
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
	 * GET /gsc/status.
	 *
	 * @return WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		$status               = ( new OAuth( $this->settings ) )->get_status();
		$status['last_sync']  = get_option( 'scp_gsc_last_sync', '' );
		$status['rows']       = $this->row_count();
		return new WP_REST_Response( $status );
	}

	/**
	 * GET /gsc/connect — build the consent URL (own OAuth).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect() {
		$url = ( new OAuth( $this->settings ) )->connect_url();
		if ( ! $url ) {
			return new WP_Error(
				'scp_no_client',
				__( 'Set your Google OAuth Client ID + Secret first (or install Site Kit).', 'seo-content-pilot' ),
				array( 'status' => 400 )
			);
		}
		return new WP_REST_Response( array( 'url' => $url ) );
	}

	/**
	 * GET /gsc/sites.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function sites() {
		try {
			$sites = ( new Client( new OAuth( $this->settings ) ) )->list_sites();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'scp_gsc_error', $e->getMessage(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response( array( 'sites' => $sites ) );
	}

	/**
	 * POST /gsc/site {site} — save property; kick off backfill on first selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_site( WP_REST_Request $request ): WP_REST_Response {
		$site  = sanitize_text_field( (string) $request->get_param( 'site' ) );
		$oauth = new OAuth( $this->settings );
		$had   = $oauth->selected_site();
		$oauth->set_site( $site );

		// First selection with no local data: pull 16 months of history.
		if ( '' !== $site && ( $had !== $site ) && 0 === $this->row_count() ) {
			( new Sync( $this->settings ) )->schedule_backfill();
		}
		return $this->status();
	}

	/**
	 * POST /gsc/disconnect.
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect(): WP_REST_Response {
		( new OAuth( $this->settings ) )->disconnect();
		return $this->status();
	}

	/**
	 * POST /gsc/sync — run the rolling 3-day sync right now (background).
	 *
	 * @return WP_REST_Response
	 */
	public function sync_now(): WP_REST_Response {
		\SCP\Queue\Job_Queue::schedule_action( Sync::HOOK );
		return new WP_REST_Response( array( 'scheduled' => true ) );
	}

	/**
	 * GET /gsc/overview?range=28d|3m — dashboard aggregates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function overview( WP_REST_Request $request ): WP_REST_Response {
		$range = '3m' === $request->get_param( 'range' ) ? 90 : 28;

		return new WP_REST_Response(
			array(
				'range'        => $range,
				'totals'       => $this->totals( $range ),
				'prev_totals'  => $this->totals( $range, $range ),
				'daily'        => $this->daily( $range ),
				'ai_pages'     => $this->ai_pages( $range ),
				'brand_daily'  => $this->brand_daily( $range ),
			)
		);
	}

	/**
	 * GET /gsc/opportunities.
	 *
	 * @return WP_REST_Response
	 */
	public function opportunities(): WP_REST_Response {
		return new WP_REST_Response( ( new Opportunities() )->mine() );
	}

	/**
	 * Row count in scp_gsc_daily.
	 *
	 * @return int
	 */
	private function row_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scp_gsc_daily" );
	}

	/**
	 * Totals for a window, optionally shifted back (previous period).
	 *
	 * @param int $days      Window length.
	 * @param int $shift_back Days to shift the window back.
	 * @return array
	 */
	private function totals( int $days, int $shift_back = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';
		$end   = gmdate( 'Y-m-d', strtotime( '-' . ( $shift_back + 1 ) . ' days' ) );
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $shift_back + $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(clicks),0) AS clicks,
						COALESCE(SUM(impressions),0) AS impressions,
						CASE WHEN SUM(impressions) > 0 THEN SUM(clicks)/SUM(impressions) ELSE 0 END AS ctr,
						CASE WHEN SUM(impressions) > 0 THEN SUM(position*impressions)/SUM(impressions) ELSE 0 END AS position
				FROM {$table} WHERE date >= %s AND date <= %s",
				$start,
				$end
			),
			ARRAY_A
		);
		return array(
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
			'impressions' => (int) ( $row['impressions'] ?? 0 ),
			'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
			'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
		);
	}

	/**
	 * Daily site-level series.
	 *
	 * @param int $days Window length.
	 * @return array
	 */
	private function daily( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date,
						SUM(clicks) AS clicks,
						SUM(impressions) AS impressions,
						CASE WHEN SUM(impressions) > 0 THEN SUM(clicks)/SUM(impressions) ELSE 0 END AS ctr,
						CASE WHEN SUM(impressions) > 0 THEN SUM(position*impressions)/SUM(impressions) ELSE 0 END AS position
				FROM {$table} WHERE date >= %s
				GROUP BY date ORDER BY date",
				$since
			),
			ARRAY_A
		);
	}

	/**
	 * Per-post metrics for AI-generated articles.
	 *
	 * @param int $days Window length.
	 * @return array
	 */
	private function ai_pages( int $days ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_scp_keyword' ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $post_ids as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions,
							CASE WHEN SUM(impressions) > 0 THEN SUM(clicks)/SUM(impressions) ELSE 0 END AS ctr,
							CASE WHEN SUM(impressions) > 0 THEN SUM(position*impressions)/SUM(impressions) ELSE 0 END AS position
					FROM {$table} WHERE page = %s AND date >= %s",
					$url,
					$since
				),
				ARRAY_A
			);
			if ( empty( $row ) || ( (int) $row['impressions'] ) === 0 ) {
				continue;
			}
			$out[] = array(
				'post_id'     => $post_id,
				'title'       => get_the_title( $post_id ),
				'url'         => $url,
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => round( (float) $row['ctr'], 4 ),
				'position'    => round( (float) $row['position'], 1 ),
			);
		}

		usort( $out, fn( $a, $b ) => $b['clicks'] <=> $a['clicks'] );
		return $out;
	}

	/**
	 * Brand query daily trend (§8.4: AI-citation lagging indicator).
	 *
	 * @param int $days Window length.
	 * @return array|null Null when no brand name configured.
	 */
	private function brand_daily( int $days ): ?array {
		$brand = trim( (string) ( $this->settings->all()['brand']['brand_name'] ?? '' ) );
		if ( '' === $brand ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, SUM(clicks) AS clicks, SUM(impressions) AS impressions
				FROM {$table}
				WHERE date >= %s AND LOWER(query) LIKE %s
				GROUP BY date ORDER BY date",
				$since,
				'%' . $wpdb->esc_like( mb_strtolower( $brand ) ) . '%'
			),
			ARRAY_A
		);
	}
}
