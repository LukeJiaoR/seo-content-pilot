<?php
/**
 * Search Console API client.
 *
 * @package SCP
 */

namespace SCP\GSC;

/**
 * Thin wrapper over the webmasters v3 API.
 */
class Client {

	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * OAuth service.
	 *
	 * @var OAuth
	 */
	private $oauth;

	/**
	 * Constructor.
	 *
	 * @param OAuth $oauth OAuth service.
	 */
	public function __construct( OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * List verified sites.
	 *
	 * @return array List of siteUrl strings.
	 * @throws \RuntimeException On API failure.
	 */
	public function list_sites(): array {
		$data = $this->request( 'GET', '/sites' );
		$out  = array();
		foreach ( (array) ( $data['siteEntry'] ?? array() ) as $entry ) {
			if ( ! empty( $entry['siteUrl'] ) ) {
				$out[] = (string) $entry['siteUrl'];
			}
		}
		return $out;
	}

	/**
	 * Query Search Analytics (query+page dimensions, one page of rows).
	 *
	 * @param string $site      Property URL.
	 * @param string $start     Y-m-d.
	 * @param string $end       Y-m-d.
	 * @param int    $start_row Pagination offset.
	 * @param int    $row_limit Page size.
	 * @return array Rows: [['keys'=>[query,page],'clicks'=>..,'impressions'=>..,'ctr'=>..,'position'=>..], ...]
	 * @throws \RuntimeException On API failure.
	 */
	public function query_analytics( string $site, string $start, string $end, int $start_row = 0, int $row_limit = 25000 ): array {
		$data = $this->request(
			'POST',
			'/sites/' . rawurlencode( $site ) . '/searchAnalytics/query',
			array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'query', 'page' ),
				'rowLimit'   => $row_limit,
				'startRow'   => $start_row,
			)
		);
		return (array) ( $data['rows'] ?? array() );
	}

	/**
	 * Inspect a URL's index status (URL Inspection API).
	 *
	 * @param string $site Property URL.
	 * @param string $url  Absolute URL to inspect.
	 * @return array inspectionResult payload.
	 * @throws \RuntimeException On API failure.
	 */
	public function inspect_url( string $site, string $url ): array {
		return $this->request(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => $site,
			)
		);
	}

	/**
	 * HTTP helper with bearer auth.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   API path (or absolute URL).
	 * @param array  $body   POST body.
	 * @return array Decoded response.
	 * @throws \RuntimeException On failure.
	 */
	private function request( string $method, string $path, array $body = array() ): array {
		$token = $this->oauth->get_access_token();
		if ( ! $token ) {
			throw new \RuntimeException( 'GSC is not connected (no usable access token).' );
		}

		$url = 0 === strpos( $path, 'http' ) ? $path : self::API_BASE . $path;

		$args = array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = 'GET' === $method
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'GSC request failed: ' . $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			$message = is_array( $decoded )
				? (string) ( $decoded['error']['message'] ?? wp_remote_retrieve_body( $response ) )
				: (string) wp_remote_retrieve_body( $response );
			throw new \RuntimeException( "GSC API error (HTTP {$code}): {$message}" );
		}

		return $decoded;
	}
}
