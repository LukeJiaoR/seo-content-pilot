<?php
/**
 * URL Inspection spot-checks (F9, engineering §7.4).
 *
 * Schedules an inspection 3 and 7 days after one of our posts goes live,
 * and backfills scp_publish_log.indexed_at when Google reports the URL
 * as indexed.
 *
 * @package SCP
 */

namespace SCP\GSC;

use SCP\Logger;
use SCP\Settings;

/**
 * Periodic "is it indexed yet?" checks via the URL Inspection API.
 */
class URL_Inspector {

	const HOOK  = 'scp_url_inspect';
	const GROUP = 'scp';

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
	 * Hook publish transitions + the inspection action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 20, 3 );
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	/**
	 * Schedule the +3d/+7d checks when our post goes live.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
			return;
		}
		if ( ! get_post_meta( $post->ID, '_scp_keyword', true ) ) {
			return;
		}
		foreach ( array( 3, 7 ) as $days ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $days * DAY_IN_SECONDS, self::HOOK, array( 'post_id' => $post->ID ), self::GROUP );
			} else {
				wp_schedule_single_event( time() + $days * DAY_IN_SECONDS, self::HOOK, array( $post->ID ) );
			}
		}
	}

	/**
	 * Run one inspection for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Skip when already confirmed indexed.
		if ( $this->indexed_at( $post_id ) ) {
			return;
		}

		$oauth = new OAuth( $this->settings );
		$site  = $oauth->selected_site();
		if ( '' === $site ) {
			return;
		}

		try {
			$result = ( new Client( $oauth ) )->inspect_url( $site, get_permalink( $post ) );
		} catch ( \Throwable $e ) {
			Logger::warning( 'URL inspection failed: ' . $e->getMessage(), 'gsc', array( 'post_id' => $post_id ) );
			return;
		}

		if ( $this->is_indexed( $result ) ) {
			$this->mark_indexed( $post_id );
			Logger::info( 'URL confirmed indexed', 'gsc', array( 'post_id' => $post_id ) );
		}
	}

	/**
	 * Whether the inspection result means "indexed".
	 *
	 * @param array $result Inspection API response.
	 * @return bool
	 */
	private function is_indexed( array $result ): bool {
		$status = (array) ( $result['inspectionResult']['indexStatusResult'] ?? array() );
		if ( 'PASS' === ( $status['verdict'] ?? '' ) ) {
			return true;
		}
		$coverage = mb_strtolower( (string) ( $status['coverageState'] ?? '' ) );
		return false !== strpos( $coverage, 'indexed' ) && false === strpos( $coverage, 'not indexed' );
	}

	/**
	 * Existing indexed_at for the post, if any.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function indexed_at( int $post_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT indexed_at FROM {$wpdb->prefix}scp_publish_log WHERE post_id = %d",
				$post_id
			)
		);
		return $value ?: null;
	}

	/**
	 * Backfill indexed_at.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function mark_indexed( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_publish_log',
			array( 'indexed_at' => current_time( 'mysql' ) ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
