<?php
/**
 * GSC data sync: daily rolling sync + 16-month backfill in chunks.
 *
 * @package SCP
 */

namespace SCP\GSC;

use SCP\Logger;
use SCP\Settings;

/**
 * Pulls Search Analytics rows into scp_gsc_daily.
 */
class Sync {

	const HOOK           = 'scp_gsc_daily_sync';
	const BACKFILL_HOOK  = 'scp_gsc_backfill';
	const GROUP          = 'scp';
	const ROW_LIMIT      = 25000;
	const BACKFILL_MONTHS = 16;

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
	 * Hook handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_daily' ) );
		add_action( self::BACKFILL_HOOK, array( $this, 'handle_backfill' ) );
	}

	/**
	 * Ensure the daily recurring action exists (admin_init).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			// 03:00 site time, before the publishing window.
			$first = strtotime( 'tomorrow 03:00' );
			as_schedule_recurring_action( $first, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Schedule the 16-month backfill as monthly chunk actions.
	 *
	 * @return void
	 */
	public function schedule_backfill(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		for ( $m = 1; $m <= self::BACKFILL_MONTHS; $m++ ) {
			as_schedule_single_action(
				time() + $m * 120,
				self::BACKFILL_HOOK,
				array( 'months_ago' => $m ),
				self::GROUP
			);
		}
	}

	/**
	 * Daily handler: re-pull each of the last 3 days (GSC data latency).
	 *
	 * @return void
	 */
	public function handle_daily(): void {
		$site = $this->site();
		if ( '' === $site ) {
			return;
		}
		foreach ( array( 3, 2, 1 ) as $days_ago ) {
			$day = gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) );
			try {
				$rows = $this->sync_range( $site, $day, $day );
				Logger::info( "GSC daily sync {$day}: {$rows} rows", 'gsc' );
			} catch ( \Throwable $e ) {
				Logger::error( "GSC daily sync failed for {$day}: " . $e->getMessage(), 'gsc' );
			}
		}
		update_option( 'scp_gsc_last_sync', current_time( 'mysql' ), false );
	}

	/**
	 * Backfill handler: one calendar month per action, day by day so
	 * every row lands on its correct date.
	 *
	 * @param int $months_ago How many months back (1 = last month).
	 * @return void
	 */
	public function handle_backfill( $months_ago ): void {
		$months_ago = max( 1, (int) $months_ago );
		$site       = $this->site();
		if ( '' === $site ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$start = strtotime( gmdate( 'Y-m-01', strtotime( "-{$months_ago} months" ) ) );
		$days  = (int) gmdate( 't', $start );
		$total = 0;

		for ( $d = 0; $d < $days; $d++ ) {
			$day = gmdate( 'Y-m-d', strtotime( "+{$d} days", $start ) );
			try {
				$total += $this->sync_range( $site, $day, $day );
			} catch ( \Throwable $e ) {
				Logger::error( "GSC backfill failed for {$day}: " . $e->getMessage(), 'gsc' );
			}
		}
		Logger::info(
			"GSC backfill month {$months_ago}: {$total} rows",
			'gsc'
		);
	}

	/**
	 * Pull all rows for a date range (paginated) and upsert under $start
	 * as the row date — callers always pass single-day ranges.
	 *
	 * @param string $site  Property URL.
	 * @param string $start Y-m-d (row date).
	 * @param string $end   Y-m-d.
	 * @return int Rows upserted.
	 * @throws \RuntimeException On API failure.
	 */
	public function sync_range( string $site, string $start, string $end ): int {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$client    = new Client( new OAuth( $this->settings ) );
		$start_row = 0;
		$total     = 0;

		do {
			$rows = $client->query_analytics( $site, $start, $end, $start_row, self::ROW_LIMIT );
			$this->upsert( $rows, $start );
			$count     = count( $rows );
			$total    += $count;
			$start_row += $count;
		} while ( $count === self::ROW_LIMIT );

		return $total;
	}

	/**
	 * Upsert analytics rows under a single date.
	 *
	 * @param array  $rows API rows.
	 * @param string $date Row date (Y-m-d).
	 * @return void
	 */
	private function upsert( array $rows, string $date ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_gsc_daily';

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $row ) {
				$keys   = (array) ( $row['keys'] ?? array() );
				$query  = mb_substr( (string) ( $keys[0] ?? '' ), 0, 255 );
				$page   = mb_substr( (string) ( $keys[1] ?? '' ), 0, 255 );
				if ( '' === $query ) {
					continue;
				}
				$values[] = '(%s,%s,%s,%d,%d,%f,%f)';
				$params[] = $date;
				$params[] = $query;
				$params[] = $page;
				$params[] = (int) ( $row['clicks'] ?? 0 );
				$params[] = (int) ( $row['impressions'] ?? 0 );
				$params[] = (float) ( $row['ctr'] ?? 0 );
				$params[] = (float) ( $row['position'] ?? 0 );
			}
			if ( empty( $values ) ) {
				continue;
			}
			$sql = "INSERT INTO {$table} (date, query, page, clicks, impressions, ctr, position)
				VALUES " . implode( ',', $values ) . "
				ON DUPLICATE KEY UPDATE
					clicks = VALUES(clicks),
					impressions = VALUES(impressions),
					ctr = VALUES(ctr),
					position = VALUES(position)";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, ...$params ) );
		}
	}

	/**
	 * Selected property shortcut.
	 *
	 * @return string
	 */
	private function site(): string {
		return ( new OAuth( $this->settings ) )->selected_site();
	}
}
