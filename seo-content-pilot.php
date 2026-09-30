<?php
/**
 * Plugin Name:       SEO Content Pilot
 * Plugin URI:        https://example.com/seo-content-pilot
 * Description:       Keyword expansion -> AI article generation -> scheduled publishing -> GSC/GEO verification. A full-loop content growth tool for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            SEO Content Pilot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-content-pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCP_VERSION', '0.1.0' );
define( 'SCP_PLUGIN_FILE', __FILE__ );
define( 'SCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the SCP\ namespace.
 *
 * SCP\Admin\Admin_Page        -> includes/Admin/class-admin-page.php
 * SCP\LLM\LLM_Client (iface)  -> includes/LLM/interface-llm-client.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'SCP\\' ) !== 0 ) {
			return;
		}
		$relative   = substr( $class, 4 );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$slug       = strtolower( str_replace( '_', '-', $class_name ) );
		$dir        = SCP_PLUGIN_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );

		foreach ( array( 'class-', 'interface-', 'trait-' ) as $prefix ) {
			$file = $dir . $prefix . $slug . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

// Action Scheduler (bundled). It self-registers with other copies (e.g.
// WooCommerce) and only the newest version initializes.
$scp_action_scheduler = SCP_PLUGIN_DIR . 'libraries/action-scheduler/action-scheduler.php';
if ( file_exists( $scp_action_scheduler ) ) {
	require_once $scp_action_scheduler;
}

register_activation_hook( __FILE__, array( 'SCP\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCP\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		SCP\Plugin::instance()->init();
	}
);
