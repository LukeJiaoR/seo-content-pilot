<?php
/**
 * Google AI Overview citation detection (F10, engineering §8.2).
 *
 * Weekly background check over published keywords: does the SERP show an
 * AI Overview, and does it cite our domain (or a competitor)?
 *
 * @package SCP
 */

namespace SCP\GEO;

use SCP\Keywords\DataForSEO;
use SCP\Logger;
use SCP\Settings;

/**
 * Detects AIO presence and domain citations; stores scp_aio_checks rows.
 */
class AIO_Detector {

	const HOOK       = 'scp_aio_weekly_check';
	const GROUP      = 'scp';
	const WEEKLY_CAP = 50;

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
	 * Hook the weekly task.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_weekly' ) );
	}

	/**
	 * Ensure the weekly recurring action exists (admin_init).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 3600, WEEK_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Weekly handler: check up to WEEKLY_CAP published keywords.
	 *
	 * @return void
	 */
	public function handle_weekly(): void {
		$this->run( array(), self::WEEKLY_CAP );
	}

	/**
	 * Run the check.
	 *
	 * @param array $keyword_ids Specific ids (empty = all published, capped).
	 * @param int   $cap         Max keywords this run.
	 * @return array{checked:int,with_aio:int,cited:int}
	 */
	public function run( array $keyword_ids = array(), int $cap = self::WEEKLY_CAP ): array {
		$dfs = new DataForSEO( $this->settings );
		if ( ! $dfs->is_configured() ) {
			Logger::warning( 'AIO check skipped: DataForSEO not configured', 'geo' );
			return array(
				'checked'  => 0,
				'with_aio' => 0,
				'cited'    => 0,
			);
		}

		$keywords = $this->published_keywords( $keyword_ids, $cap );
		$stats    = array(
			'checked'  => 0,
			'with_aio' => 0,
			'cited'    => 0,
		);

		foreach ( $keywords as $row ) {
			try {
				$result = $dfs->serp_aio( $row['keyword'] );
			} catch ( \Throwable $e ) {
				Logger::warning( 'AIO SERP failed: ' . $e->getMessage(), 'geo', array( 'keyword' => $row['keyword'] ) );
				continue;
			}

			$match = $this->match_domains( $result['references'] );
			$this->record( (int) $row['id'], $result['has_aio'], $match );

			$stats['checked']++;
			if ( $result['has_aio'] ) {
				$stats['with_aio']++;
			}
			if ( $match['cited'] ) {
				$stats['cited']++;
			}
		}

		Logger::info( 'AIO check completed', 'geo', $stats );
		return $stats;
	}

	/**
	 * Aggregate stats for the dashboard.
	 *
	 * @return array
	 */
	public function summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_aio_checks';

		// Latest check per keyword.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = (array) $wpdb->get_results(
			"SELECT c.has_aio, c.cited
			FROM {$table} c
			INNER JOIN (
				SELECT keyword_id, MAX(checked_at) AS max_at
				FROM {$table} GROUP BY keyword_id
			) latest ON latest.keyword_id = c.keyword_id AND latest.max_at = c.checked_at",
			ARRAY_A
		);

		$total   = count( $latest );
		$has_aio = count( array_filter( $latest, fn( $r ) => (int) $r['has_aio'] ) );
		$cited   = count( array_filter( $latest, fn( $r ) => (int) $r['cited'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = (array) $wpdb->get_results(
			"SELECT c.checked_at, k.keyword, c.has_aio, c.cited, c.cited_url
			FROM {$table} c
			LEFT JOIN {$wpdb->prefix}scp_keywords k ON k.id = c.keyword_id
			ORDER BY c.checked_at DESC LIMIT 10",
			ARRAY_A
		);

		return array(
			'keywords_checked' => $total,
			'with_aio'         => $has_aio,
			'cited'            => $cited,
			// Citation rate is computed only over keywords that HAD an AIO (sample base).
			'citation_rate'    => $has_aio > 0 ? round( $cited / $has_aio, 3 ) : null,
			'recent'           => $recent,
		);
	}

	/**
	 * Keywords eligible for checking.
	 *
	 * @param array $ids Explicit ids (empty = all published).
	 * @param int   $cap Cap.
	 * @return array
	 */
	private function published_keywords( array $ids, int $cap ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_keywords';
		if ( ! empty( $ids ) ) {
			$ids          = array_filter( array_map( 'intval', $ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, keyword FROM {$table} WHERE id IN ({$placeholders}) LIMIT %d",
					...[ ...$ids, $cap ]
				),
				ARRAY_A
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, keyword FROM {$table} WHERE status = 'published' ORDER BY id DESC LIMIT %d",
				$cap
			),
			ARRAY_A
		);
	}

	/**
	 * Match SERP references against our domain and competitor list.
	 *
	 * @param array $references Reference arrays from DataForSEO.
	 * @return array{cited:bool,cited_url:?string,competitors:array}
	 */
	private function match_domains( array $references ): array {
		$brand   = $this->settings->all()['brand'] ?? array();
		$own     = $this->own_domain( $brand );
		$rivals  = array_filter( array_map( 'strval', (array) ( $brand['competitors'] ?? array() ) ) );

		$cited     = false;
		$cited_url = null;
		$hit       = array();

		foreach ( $references as $ref ) {
			$domain = mb_strtolower( (string) ( $ref['domain'] ?? '' ) );
			if ( '' === $domain ) {
				continue;
			}
			if ( $own && $this->domain_matches( $domain, $own ) ) {
				$cited     = true;
				$cited_url = (string) ( $ref['url'] ?? '' );
			}
			foreach ( $rivals as $rival ) {
				if ( $this->domain_matches( $domain, mb_strtolower( trim( $rival ) ) ) ) {
					$hit[] = $rival;
				}
			}
		}

		return array(
			'cited'       => $cited,
			'cited_url'   => $cited_url,
			'competitors' => array_values( array_unique( $hit ) ),
		);
	}

	/**
	 * Own domain: settings override, else the site's host.
	 *
	 * @param array $brand Brand settings.
	 * @return string
	 */
	private function own_domain( array $brand ): string {
		$domain = trim( (string) ( $brand['site_domain'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return mb_strtolower( preg_replace( '/^www\./', '', $domain ) );
	}

	/**
	 * Domain match allowing subdomains of the target.
	 *
	 * @param string $domain Candidate domain.
	 * @param string $target Target domain.
	 * @return bool
	 */
	private function domain_matches( string $domain, string $target ): bool {
		$domain = preg_replace( '/^www\./', '', $domain );
		$target = preg_replace( '/^www\./', '', $target );
		return $domain === $target || str_ends_with( $domain, '.' . $target );
	}

	/**
	 * Persist one check row.
	 *
	 * @param int   $keyword_id Keyword id.
	 * @param bool  $has_aio    Whether an AIO block was present.
	 * @param array $match      Match result from match_domains().
	 * @return void
	 */
	private function record( int $keyword_id, bool $has_aio, array $match ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'scp_aio_checks',
			array(
				'keyword_id'        => $keyword_id,
				'checked_at'        => current_time( 'mysql' ),
				'has_aio'           => $has_aio ? 1 : 0,
				'cited'             => $match['cited'] ? 1 : 0,
				'cited_url'         => $match['cited_url'],
				'competitors_cited' => $match['competitors'] ? wp_json_encode( $match['competitors'] ) : null,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);
	}
}
