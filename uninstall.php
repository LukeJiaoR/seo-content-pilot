<?php
/**
 * Uninstall handler: drops plugin tables and deletes options.
 *
 * @package SCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	'scp_keywords',
	'scp_jobs',
	'scp_gsc_daily',
	'scp_publish_log',
	'scp_geo_checks',
	'scp_aio_checks',
	'scp_logs',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

$options = array(
	'scp_settings',
	'scp_llm_keys',
	'scp_gsc_token',
	'scp_version',
	'scp_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
