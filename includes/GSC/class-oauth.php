<?php
/**
 * GSC OAuth: own OAuth flow + Site Kit token reuse (MVP path).
 *
 * Token resolution order:
 *  1. Own token (scp keys store, encrypted) — refreshable unattended.
 *  2. Site Kit's stored access token — used while valid; cannot be
 *     refreshed by us (Site Kit owns the client credentials), so cron
 *     syncs may need the admin to visit wp-admin occasionally.
 *
 * @package SCP
 */

namespace SCP\GSC;

use SCP\Logger;
use SCP\Settings;

/**
 * Manages Google OAuth tokens for the Search Console API.
 */
class OAuth {

	const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const SCOPE         = 'https://www.googleapis.com/auth/webmasters.readonly';

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
	 * Redirect URI registered in the user's GCP console.
	 *
	 * @return string
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin.php?page=seo-content-pilot' );
	}

	/**
	 * Status summary for the settings UI.
	 *
	 * @return array
	 */
	public function get_status(): array {
		$own     = $this->own_token();
		$sitekit = $this->sitekit_token();

		$via = null;
		if ( ! empty( $own['refresh_token'] ) ) {
			$via = 'own';
		} elseif ( $sitekit ) {
			$via = 'sitekit';
		}

		return array(
			'connected'         => null !== $via || ! empty( $own['access_token'] ),
			'via'               => $via,
			'site'              => $this->selected_site(),
			'expires_at'        => $own['expires_at'] ?? ( $sitekit['expires_at'] ?? 0 ),
			'has_client_config' => '' !== ( $this->settings->all()['gsc']['client_id'] ?? '' )
				&& '' !== $this->settings->get_key( 'gsc_client_secret' ),
			'sitekit_detected'  => null !== $sitekit || $this->sitekit_active(),
			'redirect_uri'      => $this->redirect_uri(),
		);
	}

	/**
	 * Build the Google consent URL (own OAuth flow).
	 *
	 * @return string|null Null when client config missing.
	 */
	public function connect_url(): ?string {
		$client_id = $this->settings->all()['gsc']['client_id'] ?? '';
		if ( '' === $client_id || '' === $this->settings->get_key( 'gsc_client_secret' ) ) {
			return null;
		}

		$state = wp_generate_password( 32, false );
		set_transient( 'scp_gsc_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $this->redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Handle the OAuth callback on our admin page. Call on admin_init.
	 *
	 * @return void
	 */
	public function maybe_handle_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'seo-content-pilot' !== $_GET['page'] || ! isset( $_GET['scp_gsc_callback'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		$expect = get_transient( 'scp_gsc_state_' . get_current_user_id() );
		delete_transient( 'scp_gsc_state_' . get_current_user_id() );

		if ( '' === $code || ! $expect || ! hash_equals( (string) $expect, $state ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seo-content-pilot&gsc_error=state#/settings' ) );
			exit;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->settings->all()['gsc']['client_id'] ?? '',
					'client_secret' => $this->settings->get_key( 'gsc_client_secret' ),
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$decoded = ! is_wp_error( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;

		if ( empty( $decoded['access_token'] ) ) {
			Logger::error( 'GSC token exchange failed', 'gsc', array( 'response' => $decoded ) );
			wp_safe_redirect( admin_url( 'admin.php?page=seo-content-pilot&gsc_error=exchange#/settings' ) );
			exit;
		}

		$this->save_own_token(
			array(
				'access_token'  => (string) $decoded['access_token'],
				'refresh_token' => (string) ( $decoded['refresh_token'] ?? '' ),
				'expires_at'    => time() + (int) ( $decoded['expires_in'] ?? 3600 ) - 60,
				'scope'         => (string) ( $decoded['scope'] ?? '' ),
			)
		);

		Logger::info( 'GSC connected (own OAuth)', 'gsc' );
		wp_safe_redirect( admin_url( 'admin.php?page=seo-content-pilot&gsc_connected=1#/settings' ) );
		exit;
	}

	/**
	 * Resolve a usable access token, refreshing the own token when needed.
	 *
	 * @return string|null
	 */
	public function get_access_token(): ?string {
		$own = $this->own_token();

		if ( ! empty( $own['access_token'] ) ) {
			if ( (int) ( $own['expires_at'] ?? 0 ) > time() + 60 ) {
				return (string) $own['access_token'];
			}
			if ( ! empty( $own['refresh_token'] ) ) {
				$refreshed = $this->refresh( (string) $own['refresh_token'] );
				if ( $refreshed ) {
					return (string) $refreshed['access_token'];
				}
			}
		}

		$sitekit = $this->sitekit_token();
		if ( $sitekit && (int) ( $sitekit['expires_at'] ?? 0 ) > time() + 60 ) {
			return (string) $sitekit['access_token'];
		}

		return null;
	}

	/**
	 * Currently selected GSC property (own setting, else Site Kit's).
	 *
	 * @return string
	 */
	public function selected_site(): string {
		$site = (string) ( $this->settings->all()['gsc']['site'] ?? '' );
		if ( '' !== $site ) {
			return $site;
		}
		$sk = get_option( 'googlesitekit_search-console_settings' );
		if ( is_array( $sk ) && ! empty( $sk['propertyID'] ) ) {
			return (string) $sk['propertyID'];
		}
		return '';
	}

	/**
	 * Persist the selected site.
	 *
	 * @param string $site Property URL (sc-domain:… or https://…).
	 * @return void
	 */
	public function set_site( string $site ): void {
		$all                = $this->settings->all();
		$all['gsc']['site'] = sanitize_text_field( $site );
		$this->settings->save( $all );
	}

	/**
	 * Remove own token + site selection (Site Kit data untouched).
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->settings->set_key( 'gsc_token', '' );
		$all                = $this->settings->all();
		$all['gsc']['site'] = '';
		$this->settings->save( $all );
	}

	/**
	 * Own token from the encrypted keys store.
	 *
	 * @return array Empty array when absent.
	 */
	private function own_token(): array {
		$json = $this->settings->get_key( 'gsc_token' );
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist own token (merge to keep an existing refresh_token).
	 *
	 * @param array $token Token fields.
	 * @return void
	 */
	private function save_own_token( array $token ): void {
		$existing = $this->own_token();
		if ( empty( $token['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$token['refresh_token'] = $existing['refresh_token'];
		}
		$this->settings->set_key( 'gsc_token', wp_json_encode( $token ) );
	}

	/**
	 * Refresh the own token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @return array|null New token payload or null.
	 */
	private function refresh( string $refresh_token ): ?array {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'refresh_token' => $refresh_token,
					'client_id'     => $this->settings->all()['gsc']['client_id'] ?? '',
					'client_secret' => $this->settings->get_key( 'gsc_client_secret' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$decoded = ! is_wp_error( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		if ( empty( $decoded['access_token'] ) ) {
			Logger::warning( 'GSC token refresh failed', 'gsc', array( 'response' => $decoded ) );
			return null;
		}

		$token = array(
			'access_token'  => (string) $decoded['access_token'],
			'refresh_token' => $refresh_token,
			'expires_at'    => time() + (int) ( $decoded['expires_in'] ?? 3600 ) - 60,
			'scope'         => (string) ( $decoded['scope'] ?? '' ),
		);
		$this->save_own_token( $token );
		return $token;
	}

	/**
	 * Whether Site Kit is active.
	 *
	 * @return bool
	 */
	private function sitekit_active(): bool {
		return defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'Google\\Site_Kit\\Plugin' );
	}

	/**
	 * Read Site Kit's stored OAuth token (any admin user), normalized to
	 * {access_token, expires_at}. Null when unavailable.
	 *
	 * @return array|null
	 */
	private function sitekit_token(): ?array {
		if ( ! $this->sitekit_active() ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 5",
				'googlesitekit_oauth_token',
				'%access_token%'
			)
		);
		foreach ( (array) $rows as $row ) {
			$t = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $t ) || empty( $t['access_token'] ) ) {
				continue;
			}
			if ( false === strpos( (string) ( $t['scope'] ?? '' ), 'webmasters' ) ) {
				continue;
			}
			$expires_at = ! empty( $t['expires'] )
				? (int) $t['expires']
				: ( (int) ( $t['created'] ?? 0 ) + (int) ( $t['expires_in'] ?? 3600 ) );
			return array(
				'access_token' => (string) $t['access_token'],
				'expires_at'   => $expires_at,
			);
		}
		return null;
	}
}
