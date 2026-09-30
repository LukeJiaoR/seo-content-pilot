<?php
/**
 * Enqueues the built React bundle on the plugin screen.
 *
 * @package SCP
 */

namespace SCP\Admin;

/**
 * Asset loader for the React SPA.
 */
class Assets {

	/**
	 * Enqueue on our admin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . Admin_Page::SLUG !== $hook ) {
			return;
		}

		$asset_file = SCP_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => SCP_VERSION,
			);

		wp_enqueue_script(
			'scp-admin',
			SCP_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style = SCP_PLUGIN_DIR . 'build/style-index.css';
		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				'scp-admin',
				SCP_PLUGIN_URL . 'build/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_localize_script(
			'scp-admin',
			'scpAdmin',
			array(
				'root'      => esc_url_raw( rest_url( 'scp/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'editBase'  => admin_url( 'post.php' ),
			)
		);
	}
}
