<?php
/**
 * Keyword priority scoring.
 *
 * @package SCP
 */

namespace SCP\Keywords;

/**
 * score = volume / max(kd, 1) x (GSC-ranked ? 1.5 : 1)
 */
class Scorer {

	const GSC_BOOST = 1.5;

	/**
	 * Compute the priority score. Null when volume is unknown.
	 *
	 * @param int|null   $volume     Monthly search volume.
	 * @param float|null $kd         Keyword difficulty (0-100).
	 * @param bool       $gsc_ranked Whether the site already ranks for it (GSC).
	 * @return float|null
	 */
	public static function compute( ?int $volume, ?float $kd, bool $gsc_ranked ): ?float {
		if ( null === $volume ) {
			return null;
		}
		$score = $volume / max( (float) ( $kd ?? 1 ), 1.0 );
		if ( $gsc_ranked ) {
			$score *= self::GSC_BOOST;
		}
		return round( $score, 2 );
	}

	/**
	 * Load the set of queries the site already ranks for (from GSC sync).
	 * Empty until the GSC module (S4) has synced — zero cost then.
	 *
	 * @return array Map of query => true.
	 */
	public static function gsc_ranked_queries(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT `query` FROM {$wpdb->prefix}scp_gsc_daily" );
		return array_fill_keys( array_map( 'strval', $rows ?: array() ), true );
	}
}
