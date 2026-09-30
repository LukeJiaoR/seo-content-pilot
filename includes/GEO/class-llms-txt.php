<?php
/**
 * /llms.txt dynamic endpoint (F12, engineering §8.3).
 *
 * @package SCP
 */

namespace SCP\GEO;

use SCP\Settings;

/**
 * Serves a curated llms.txt for AI crawlers when enabled.
 */
class LLMs_Txt {

	const QUERY_VAR = 'scp_llms_txt';

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
	 * Register rewrite + output hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_filter(
			'query_vars',
			function ( array $vars ) {
				$vars[] = self::QUERY_VAR;
				return $vars;
			}
		);
		add_action( 'template_redirect', array( $this, 'maybe_output' ) );
	}

	/**
	 * Output llms.txt when enabled.
	 *
	 * @return void
	 */
	public function maybe_output(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( empty( $this->settings->all()['geo']['llms_txt'] ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->build() );
		exit;
	}

	/**
	 * Build the llms.txt body: site intro + latest AI articles.
	 *
	 * @return string
	 */
	private function build(): string {
		global $wpdb;

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );
		$desc    = get_bloginfo( 'description' );
		if ( $desc ) {
			$lines[] = '> ' . $desc;
		}
		$lines[] = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 30",
				'_scp_keyword'
			)
		);

		if ( ! empty( $post_ids ) ) {
			$lines[] = '## Articles';
			foreach ( $post_ids as $post_id ) {
				$permalink = get_permalink( (int) $post_id );
				if ( ! $permalink || 'publish' !== get_post_status( (int) $post_id ) ) {
					continue;
				}
				$lines[] = '- [' . get_the_title( (int) $post_id ) . '](' . $permalink . ')';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
