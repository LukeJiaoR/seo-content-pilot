<?php
/**
 * REST controller for single-article generation (S1).
 *
 * @package SCP
 */

namespace SCP\REST;

use SCP\Generation\Pipeline;
use SCP\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /scp/v1/generate — run the pipeline for one keyword.
 */
class Generation_Controller {

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
			'/generate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'keyword' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => fn( $v ) => is_string( $v ) && mb_strlen( trim( $v ) ) >= 2 && mb_strlen( $v ) <= 200,
						),
					),
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
	 * POST /generate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$keyword = trim( (string) $request->get_param( 'keyword' ) );

		if ( '' === $this->settings->get_key( 'openai' ) ) {
			return new WP_Error(
				'scp_no_key',
				__( 'OpenAI API key is not configured. Add it in Settings first.', 'seo-content-pilot' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = ( new Pipeline( $this->settings ) )->run_sync( $keyword );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'scp_generate_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		$result['edit_link'] = admin_url( 'post.php?post=' . (int) $result['post_id'] . '&action=edit' );
		return new WP_REST_Response( $result );
	}
}
