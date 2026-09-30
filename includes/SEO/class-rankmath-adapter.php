<?php
/**
 * Rank Math meta writer.
 *
 * @package SCP
 */

namespace SCP\SEO;

/**
 * Writes meta fields when Rank Math is active.
 */
class RankMath_Adapter {

	/**
	 * Whether Rank Math is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Write title / description / focus keyword.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Meta title.
	 * @param string $desc    Meta description.
	 * @param string $keyword Focus keyword.
	 * @return void
	 */
	public static function write( int $post_id, string $title, string $desc, string $keyword ): void {
		if ( '' !== $title ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
		}
		if ( '' !== $desc ) {
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}
		if ( '' !== $keyword ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
		}
	}
}
