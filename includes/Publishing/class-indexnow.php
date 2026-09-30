<?php
/**
 * IndexNow submission (Bing/Yandex instant indexing).
 *
 * Serves the required key verification file at /{key}.txt via a rewrite
 * rule, and submits our post URLs when they transition to publish.
 *
 * @package SCP
 */

namespace SCP\Publishing;

use SCP\Logger;

/**
 * IndexNow client + key file endpoint.
 */
class IndexNow {

	const ENDPOINT  = 'https://api.indexnow.org/indexnow';
	const QUERY_VAR = 'scp_indexnow_key';

	/**
	 * Register rewrite + publish hook. Call on init/plugins_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		add_rewrite_rule(
			'^(scp[a-z0-9]{29})\.txt$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_key' ) );
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 10, 3 );
	}

	/**
	 * Whitelist the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve "{key}.txt" containing the key itself.
	 *
	 * @return void
	 */
	public function maybe_serve_key(): void {
		$key = get_query_var( self::QUERY_VAR );
		if ( ! $key ) {
			return;
		}
		if ( ! hash_equals( $this->key(), (string) $key ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->key() );
		exit;
	}

	/**
	 * Submit the permalink when one of our posts goes live.
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
		$ok = $this->submit( array( get_permalink( $post ) ) );
		if ( $ok ) {
			$this->mark_submitted( $post->ID );
		}
	}

	/**
	 * Submit URLs to the IndexNow endpoint.
	 *
	 * @param array $urls Absolute URLs.
	 * @return bool Success.
	 */
	public function submit( array $urls ): bool {
		$urls = array_filter( array_map( 'strval', $urls ) );
		if ( empty( $urls ) ) {
			return false;
		}

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$key      = $this->key();
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => array_values( $urls ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::warning( 'IndexNow failed: ' . $response->get_error_message(), 'indexnow' );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 200, 202 ), true ) ) {
			Logger::warning( "IndexNow HTTP {$code}", 'indexnow' );
			return false;
		}
		Logger::info( 'IndexNow submitted', 'indexnow', array( 'urls' => count( $urls ) ) );
		return true;
	}

	/**
	 * Get (or lazily create) the IndexNow key: "scp" + 29 alnum = 32 chars,
	 * matching the rewrite rule pattern.
	 *
	 * @return string
	 */
	private function key(): string {
		$key = (string) get_option( 'scp_indexnow_key', '' );
		if ( ! preg_match( '/^scp[a-z0-9]{29}$/', $key ) ) {
			$key = 'scp' . strtolower( wp_generate_password( 29, false, false ) );
			update_option( 'scp_indexnow_key', $key, false );
		}
		return $key;
	}

	/**
	 * Flag the publish_log row as submitted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function mark_submitted( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'scp_publish_log',
			array( 'indexnow_submitted' => 1 ),
			array( 'post_id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}
