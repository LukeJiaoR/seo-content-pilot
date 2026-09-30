<?php
/**
 * Yoast SEO meta writer.
 *
 * @package SCP
 */

namespace SCP\SEO;

/**
 * Writes meta fields when Yoast SEO is active.
 */
class Yoast_Adapter {

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
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
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		}
		if ( '' !== $desc ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
		}
		if ( '' !== $keyword ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		}
	}
}
