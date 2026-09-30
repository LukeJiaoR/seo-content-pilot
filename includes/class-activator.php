<?php
/**
 * Activation / deactivation: creates tables and default options.
 *
 * @package SCP
 */

namespace SCP;

/**
 * Plugin activator.
 */
class Activator {

	/**
	 * DB schema version, bump when tables change.
	 */
	const DB_VERSION = '0.3.0';

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_defaults();
		update_option( 'scp_version', SCP_VERSION );
		update_option( 'scp_db_version', self::DB_VERSION );
		// IndexNow key file + llms.txt rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Idempotent schema upgrade (dbDelta is safe to re-run).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'scp_db_version', '0' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'scp_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Run on deactivation. Data is kept; scheduled work is cleared.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'scp_gsc_daily_sync' );
		wp_clear_scheduled_hook( 'scp_geo_weekly_check' );
		wp_clear_scheduled_hook( 'scp_gsc_backfill' );
		wp_clear_scheduled_hook( 'scp_aio_weekly_check' );
		wp_clear_scheduled_hook( 'scp_url_inspect' );
		Queue\Job_Queue::cancel_all();
	}

	/**
	 * Create custom tables via dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$prefix}scp_keywords (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(255) NOT NULL,
			source varchar(32) NOT NULL DEFAULT 'seed',
			cluster_id bigint(20) unsigned NULL,
			volume int(11) NULL,
			kd float NULL,
			cpc decimal(8,2) NULL,
			intent varchar(16) NULL,
			score float NULL,
			embedding longtext NULL,
			track_geo tinyint(1) NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY keyword (keyword(191)),
			KEY status (status),
			KEY cluster_id (cluster_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NULL,
			post_id bigint(20) unsigned NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			step varchar(32) NOT NULL DEFAULT 'outline',
			payload longtext NULL,
			error text NULL,
			scheduled_at datetime NULL,
			tokens_used int(11) NOT NULL DEFAULT 0,
			cost_estimate decimal(10,4) NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY keyword_id (keyword_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_gsc_daily (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			date date NOT NULL,
			query varchar(255) NOT NULL,
			page varchar(255) NOT NULL DEFAULT '',
			country varchar(8) NOT NULL DEFAULT 'all',
			device varchar(16) NOT NULL DEFAULT 'all',
			clicks int(11) NOT NULL DEFAULT 0,
			impressions int(11) NOT NULL DEFAULT 0,
			ctr float NOT NULL DEFAULT 0,
			position float NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_query_page (date,query(150),page(150),country,device),
			KEY date_query (date,query(150))
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_publish_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			keyword varchar(255) NOT NULL DEFAULT '',
			published_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			indexnow_submitted tinyint(1) NOT NULL DEFAULT 0,
			indexed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_geo_checks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NOT NULL,
			checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			engine varchar(16) NOT NULL,
			prompt_text text NULL,
			mentioned tinyint(1) NOT NULL DEFAULT 0,
			cited tinyint(1) NOT NULL DEFAULT 0,
			mention_position int(11) NULL,
			sentiment varchar(8) NULL,
			competitors_mentioned text NULL,
			raw_answer_hash char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY keyword_engine (keyword_id,engine),
			KEY checked_at (checked_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_aio_checks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NOT NULL,
			checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			has_aio tinyint(1) NOT NULL DEFAULT 0,
			cited tinyint(1) NOT NULL DEFAULT 0,
			cited_url varchar(255) NULL,
			competitors_cited text NULL,
			PRIMARY KEY  (id),
			KEY keyword_id (keyword_id),
			KEY checked_at (checked_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}scp_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(16) NOT NULL DEFAULT 'info',
			context varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Write default options if absent.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		if ( false === get_option( 'scp_settings' ) ) {
			add_option( 'scp_settings', Settings::defaults() );
		}
	}
}
