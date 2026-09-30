<?php
/**
 * Opportunity mining over scp_gsc_daily (engineering §7.3).
 *
 * @package SCP
 */

namespace SCP\GSC;

/**
 * Finds striking-distance, low-CTR and unexpected queries.
 * Stateless: works purely off scp_gsc_daily + scp_keywords.
 */
class Opportunities {

	const WINDOW_DAYS          = 28;
	const SD_MIN_IMPRESSIONS   = 50;
	const MAX_ROWS             = 50;

	/**
	 * CTR benchmarks by average position (engineering §7.3).
	 */
	const CTR_BENCHMARKS = array(
		array( 1.5, 0.28 ),
		array( 2.5, 0.15 ),
		array( 3.5, 0.10 ),
		array( 5.0, 0.07 ),
		array( 7.0, 0.05 ),
		array( 10.0, 0.03 ),
	);

	/**
	 * Mine all three opportunity types.
	 *
	 * @return array{striking_distance:array,low_ctr:array,unexpected:array}
	 */
	public function mine(): array {
		$rows = $this->aggregate();
		$pool = $this->pool_queries();

		$striking = array();
		$low_ctr  = array();
		$unexpected = array();

		foreach ( $rows as $row ) {
			$pos   = (float) $row['position'];
			$imps  = (int) $row['impressions'];
			$click = (int) $row['clicks'];
			$ctr   = $imps > 0 ? $click / $imps : 0.0;

			$item = array(
				'query'       => $row['query'],
				'clicks'      => $click,
				'impressions' => $imps,
				'ctr'         => round( $ctr, 4 ),
				'position'    => round( $pos, 1 ),
				'page'        => (string) $row['top_page'],
				'in_pool'     => isset( $pool[ $row['query'] ] ),
			);

			if ( $pos > 10 && $pos <= 30 && $imps >= self::SD_MIN_IMPRESSIONS ) {
				$striking[] = $item;
				continue;
			}

			if ( $pos <= 10 && $ctr < $this->benchmark( $pos ) && $imps >= self::SD_MIN_IMPRESSIONS ) {
				$item['benchmark'] = $this->benchmark( $pos );
				$low_ctr[]         = $item;
				continue;
			}

			if ( $click > 0 && ! $item['in_pool'] && ! $this->is_ai_page( (string) $row['top_page'] ) ) {
				$unexpected[] = $item;
			}
		}

		usort( $striking, fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		usort( $low_ctr, fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		usort( $unexpected, fn( $a, $b ) => $b['clicks'] <=> $a['clicks'] );

		return array(
			'striking_distance' => array_slice( $striking, 0, self::MAX_ROWS ),
			'low_ctr'           => array_slice( $low_ctr, 0, self::MAX_ROWS ),
			'unexpected'        => array_slice( $unexpected, 0, self::MAX_ROWS ),
		);
	}

	/**
	 * CTR benchmark for a position.
	 *
	 * @param float $position Average position.
	 * @return float
	 */
	private function benchmark( float $position ): float {
		foreach ( self::CTR_BENCHMARKS as list( $max_pos, $ctr ) ) {
			if ( $position <= $max_pos ) {
				return $ctr;
			}
		}
		return 0.01;
	}

	/**
	 * Per-query aggregates over the last WINDOW_DAYS full days.
	 *
	 * @return array
	 */
	private function aggregate(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';
		$since = gmdate( 'Y-m-d', strtotime( '-' . self::WINDOW_DAYS . ' days' ) );

		// Position weighted by impressions; top page = page with most clicks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.query,
						SUM(t.clicks) AS clicks,
						SUM(t.impressions) AS impressions,
						CASE WHEN SUM(t.impressions) > 0
							THEN SUM(t.position * t.impressions) / SUM(t.impressions)
							ELSE 0 END AS position,
						(SELECT t2.page FROM {$table} t2
							WHERE t2.query = t.query AND t2.date >= %s
							GROUP BY t2.page
							ORDER BY SUM(t2.clicks) DESC, SUM(t2.impressions) DESC
							LIMIT 1) AS top_page
				FROM {$table} t
				WHERE t.date >= %s
				GROUP BY t.query
				HAVING SUM(t.impressions) > 0",
				$since,
				$since
			),
			ARRAY_A
		);
	}

	/**
	 * All keywords already in the pool (any status).
	 *
	 * @return array query => true
	 */
	private function pool_queries(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT keyword FROM {$wpdb->prefix}scp_keywords" );
		return array_fill_keys( array_map( 'strval', $rows ?: array() ), true );
	}

	/**
	 * Whether a URL belongs to an AI-generated post.
	 *
	 * @param string $url Page URL.
	 * @return bool
	 */
	private function is_ai_page( string $url ): bool {
		static $ai_urls = null;
		if ( null === $ai_urls ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
					'_scp_keyword'
				)
			);
			$ai_urls = array();
			foreach ( (array) $ids as $post_id ) {
				$permalink = get_permalink( (int) $post_id );
				if ( $permalink ) {
					$ai_urls[ untrailingslashit( $permalink ) ] = true;
				}
			}
		}
		return isset( $ai_urls[ untrailingslashit( $url ) ] );
	}
}
