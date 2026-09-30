<?php
/**
 * REST controller for plugin settings.
 *
 * @package SCP
 */

namespace SCP\REST;

use SCP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * GET/POST /scp/v1/settings — keys are write-only, never echoed back.
 */
class Settings_Controller {

	const NAMESPACE = 'scp/v1';

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
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Permission: admins only.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings          = $this->settings->all();
		$settings['keys']  = $this->settings->get_keys( false ); // masked booleans only.
		$settings['users'] = $this->author_options();
		$settings['categories'] = $this->category_options();
		return new WP_REST_Response( $settings );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$current = $this->settings->all();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'scp_bad_request', __( 'Invalid payload.', 'seo-content-pilot' ), array( 'status' => 400 ) );
		}

		// Validate custom prompt templates against the variable whitelist.
		if ( isset( $body['prompts'] ) && is_array( $body['prompts'] ) ) {
			$invalid = $this->validate_templates( $body['prompts'] );
			if ( $invalid ) {
				return new WP_Error(
					'scp_bad_template',
					sprintf(
						/* translators: %s: comma-separated variable names. */
						__( 'Unknown template variables: %s', 'seo-content-pilot' ),
						implode( ', ', $invalid )
					),
					array( 'status' => 400 )
				);
			}
		}

		// Merge known sections only.
		foreach ( array( 'llm', 'content', 'publish', 'prompts', 'brand', 'gsc', 'geo' ) as $section ) {
			if ( isset( $body[ $section ] ) && is_array( $body[ $section ] ) ) {
				$current[ $section ] = array_replace( $current[ $section ], $this->sanitize_section( $body[ $section ] ) );
			}
		}

		// Keys are handled separately (write-only).
		if ( isset( $body['keys'] ) && is_array( $body['keys'] ) ) {
			foreach ( $body['keys'] as $provider => $key ) {
				$provider = sanitize_key( (string) $provider );
				$key      = trim( (string) $key );
				if ( '' !== $key ) {
					$this->settings->set_key( $provider, $key );
				}
			}
		}

		$this->settings->save( $current );

		return $this->get_settings();
	}

	/**
	 * Find template variables outside the whitelist.
	 *
	 * @param array $templates Prompt overrides.
	 * @return array Offending variable names.
	 */
	private function validate_templates( array $templates ): array {
		$whitelist = Settings::template_variables();
		$invalid   = array();
		foreach ( $templates as $name => $template ) {
			if ( ! is_string( $template ) || '' === $template || ! isset( $whitelist[ $name ] ) ) {
				continue;
			}
			preg_match_all( '/\{([a-z_]+)\}/', $template, $m );
			foreach ( $m[1] as $var ) {
				if ( ! in_array( $var, $whitelist[ $name ], true ) ) {
					$invalid[] = $name . ':{' . $var . '}';
				}
			}
		}
		return array_unique( $invalid );
	}

	/**
	 * Recursively sanitize a settings section.
	 *
	 * @param array $section Raw input.
	 * @return array
	 */
	private function sanitize_section( array $section ): array {
		$clean = array();
		foreach ( $section as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_section( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}
		return $clean;
	}

	/**
	 * Users usable as post author.
	 *
	 * @return array
	 */
	private function author_options(): array {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 50,
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		return array_map(
			fn( $u ) => array(
				'value' => (int) $u->ID,
				'label' => $u->display_name,
			),
			$users
		);
	}

	/**
	 * Category options.
	 *
	 * @return array
	 */
	private function category_options(): array {
		$cats = get_categories(
			array(
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		return array_map(
			fn( $c ) => array(
				'value' => (int) $c->term_id,
				'label' => $c->name,
			),
			$cats
		);
	}
}
