<?php
/**
 * Admin menu page: React app mount point.
 *
 * @package SCP
 */

namespace SCP\Admin;

/**
 * Registers the top-level menu and renders the mount div.
 */
class Admin_Page {

	const SLUG = 'seo-content-pilot';

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'SEO Content Pilot', 'seo-content-pilot' ),
			__( 'SEO Pilot', 'seo-content-pilot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-line',
			58
		);
	}

	/**
	 * Render mount point (or build instructions when assets are missing).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'seo-content-pilot' ) );
		}

		$build = SCP_PLUGIN_DIR . 'build/index.js';
		if ( ! file_exists( $build ) ) {
			echo '<div class="wrap"><h1>SEO Content Pilot</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Admin assets are not built yet. Run inside the plugin directory:', 'seo-content-pilot' );
			echo '</p><p><code>npm install && npm run build</code></p></div></div>';
			return;
		}

		echo '<div class="wrap"><div id="scp-root"></div></div>';
	}
}
