<?php
/**
 * Simple DB logger.
 *
 * @package SCP
 */

namespace SCP;

/**
 * Writes log rows to the scp_logs table.
 */
class Logger {

	/**
	 * Info level log.
	 *
	 * @param string $message Message.
	 * @param string $context Component tag.
	 * @param array  $meta    Extra structured data.
	 * @return void
	 */
	public static function info( string $message, string $context = '', array $meta = array() ): void {
		self::write( 'info', $message, $context, $meta );
	}

	/**
	 * Warning level log.
	 *
	 * @param string $message Message.
	 * @param string $context Component tag.
	 * @param array  $meta    Extra structured data.
	 * @return void
	 */
	public static function warning( string $message, string $context = '', array $meta = array() ): void {
		self::write( 'warning', $message, $context, $meta );
	}

	/**
	 * Error level log.
	 *
	 * @param string $message Message.
	 * @param string $context Component tag.
	 * @param array  $meta    Extra structured data.
	 * @return void
	 */
	public static function error( string $message, string $context = '', array $meta = array() ): void {
		self::write( 'error', $message, $context, $meta );
	}

	/**
	 * Insert a log row. Never throws.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param string $context Component tag.
	 * @param array  $meta    Extra structured data.
	 * @return void
	 */
	private static function write( string $level, string $message, string $context, array $meta ): void {
		global $wpdb;

		// Never persist secrets.
		array_walk_recursive(
			$meta,
			function ( &$value, $key ) {
				if ( is_string( $key ) && preg_match( '/key|token|secret|password/i', $key ) ) {
					$value = '[redacted]';
				}
			}
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'scp_logs',
			array(
				'level'      => $level,
				'context'    => $context,
				'message'    => $message,
				'meta'       => $meta ? wp_json_encode( $meta ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
