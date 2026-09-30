<?php
/**
 * SEO plugin coordinator: writes meta to whichever SEO plugin is active.
 *
 * @package SCP
 */

namespace SCP\SEO;

/**
 * Fans meta out to Yoast / Rank Math adapters.
 */
class SEO_Meta {

	/**
	 * Write generated meta into the active SEO plugin(s).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $article Article payload with meta + keyword.
	 * @return void
	 */
	public static function write( int $post_id, array $article ): void {
		$title   = sanitize_text_field( (string) ( $article['meta']['meta_title'] ?? '' ) );
		$desc    = sanitize_textarea_field( (string) ( $article['meta']['meta_description'] ?? '' ) );
		$keyword = sanitize_text_field( (string) ( $article['keyword'] ?? '' ) );

		if ( Yoast_Adapter::is_active() ) {
			Yoast_Adapter::write( $post_id, $title, $desc, $keyword );
		}
		if ( RankMath_Adapter::is_active() ) {
			RankMath_Adapter::write( $post_id, $title, $desc, $keyword );
		}
	}
}
