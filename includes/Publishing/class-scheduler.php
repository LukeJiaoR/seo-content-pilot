<?php
/**
 * Daily-cap publish scheduler.
 *
 * Spreads generated posts across days: at most N posts per day inside a
 * time window, at a random minute (jitter avoids mechanical patterns).
 * Scheduling itself is delegated to WordPress via post_status=future.
 *
 * @package SCP
 */

namespace SCP\Publishing;

/**
 * Computes the next available publish slot.
 */
class Scheduler {

	/**
	 * Publish settings section.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param array $settings Full plugin settings array.
	 */
	public function __construct( array $settings ) {
		$this->config = $settings['publish'] ?? array();
	}

	/**
	 * Next free slot.
	 *
	 * @return array{local:string,gmt:string} MySQL datetimes (site tz / UTC).
	 */
	public function next_slot(): array {
		$tz   = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $tz );
		$cap  = max( 1, (int) ( $this->config['daily_cap'] ?? 2 ) );
		$from = $this->parse_time( (string) ( $this->config['window_start'] ?? '09:00' ), 9, 0 );
		$to   = $this->parse_time( (string) ( $this->config['window_end'] ?? '18:00' ), 18, 0 );

		if ( $to <= $from ) {
			$to = $from + 3600;
		}

		for ( $d = 0; $d < 60; $d++ ) {
			$day = $now->modify( "+{$d} days" );

			// Today's window already over? Skip to tomorrow.
			$day_end = $day->setTime( (int) ( $to / 3600 ), (int) ( ( $to % 3600 ) / 60 ) );
			if ( 0 === $d && $now >= $day_end ) {
				continue;
			}

			if ( $this->scheduled_count( $day->format( 'Y-m-d' ) ) >= $cap ) {
				continue;
			}

			$random_offset = wp_rand( 0, max( 0, $to - $from - 60 ) );
			$candidate     = $day->setTime( (int) ( $from / 3600 ), (int) ( ( $from % 3600 ) / 60 ) )
				->modify( "+{$random_offset} seconds" );

			// Same day but random time landed in the past: nudge into the near future.
			if ( $candidate <= $now ) {
				$candidate = $now->modify( '+' . wp_rand( 3, 90 ) . ' minutes' );
			}

			return array(
				'local' => $candidate->format( 'Y-m-d H:i:s' ),
				'gmt'   => $candidate->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			);
		}

		// Fallback: 61 days out, window start.
		$fallback = $now->modify( '+61 days' )->setTime( (int) ( $from / 3600 ), 0 );
		return array(
			'local' => $fallback->format( 'Y-m-d H:i:s' ),
			'gmt'   => $fallback->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Count of our already-scheduled future posts on a given day (site tz).
	 *
	 * @param string $day Y-m-d.
	 * @return int
	 */
	private function scheduled_count( string $day ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_scp_keyword'
				WHERE p.post_status = 'future'
				AND p.post_date >= %s
				AND p.post_date < %s",
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) . ' 00:00:00'
			)
		);
	}

	/**
	 * Parse "HH:MM" to seconds since midnight.
	 *
	 * @param string $value   Input.
	 * @param int    $def_h   Default hour.
	 * @param int    $def_m   Default minute.
	 * @return int Seconds since midnight.
	 */
	private function parse_time( string $value, int $def_h, int $def_m ): int {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $value ), $m ) ) {
			$h = min( 23, max( 0, (int) $m[1] ) );
			$i = min( 59, max( 0, (int) $m[2] ) );
			return $h * 3600 + $i * 60;
		}
		return $def_h * 3600 + $def_m * 60;
	}
}
