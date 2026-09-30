<?php
/**
 * Main plugin singleton.
 *
 * @package SCP
 */

namespace SCP;

/**
 * Wires together admin pages, assets and REST routes.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();
	}

	/**
	 * Settings accessor.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$admin_page = new Admin\Admin_Page();
		$assets     = new Admin\Assets();

		add_action( 'admin_menu', array( $admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );

		// Idempotent schema upgrades on existing installs.
		add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );

		// GSC OAuth callback handler + recurring task scheduling.
		$oauth = new GSC\OAuth( $this->settings );
		add_action( 'admin_init', array( $oauth, 'maybe_handle_callback' ) );

		$sync = new GSC\Sync( $this->settings );
		$sync->register();
		add_action( 'admin_init', array( $sync, 'ensure_scheduled' ) );

		$aio = new GEO\AIO_Detector( $this->settings );
		$aio->register();
		add_action( 'admin_init', array( $aio, 'ensure_scheduled' ) );

		$tracker = new GEO\Prompt_Tracker( $this->settings );
		$tracker->register();
		add_action( 'admin_init', array( $tracker, 'ensure_scheduled' ) );

		// IndexNow + URL inspection + llms.txt.
		( new Publishing\IndexNow() )->register();
		( new GSC\URL_Inspector( $this->settings ) )->register();
		( new GEO\LLMs_Txt( $this->settings ) )->register();

		// Async generation queue (Action Scheduler / WP-Cron fallback).
		( new Queue\Job_Queue( $this->settings ) )->register();

		// Background keyword expansion, one action per seed.
		add_action(
			'scp_expand_seed',
			function ( $seed ) {
				( new Keywords\Expander( $this->settings ) )->expand_seed( (string) $seed );
			}
		);

		add_action(
			'rest_api_init',
			function () {
				( new REST\Settings_Controller( $this->settings ) )->register_routes();
				( new REST\Generation_Controller( $this->settings ) )->register_routes();
				( new REST\Keywords_Controller( $this->settings ) )->register_routes();
				( new REST\Queue_Controller( $this->settings ) )->register_routes();
				( new REST\GSC_Controller( $this->settings ) )->register_routes();
				( new REST\GEO_Controller( $this->settings ) )->register_routes();
			}
		);
	}
}
