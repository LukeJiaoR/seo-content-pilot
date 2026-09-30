<?php
/**
 * DataForSEO API client (keyword suggestions, related, volume, KD, balance).
 *
 * @package SCP
 */

namespace SCP\Keywords;

use SCP\Settings;

/**
 * Thin wrapper over the DataForSEO v3 REST API.
 */
class DataForSEO {

	const BASE = 'https://api.dataforseo.com/v3';

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
	 * Whether login/password are configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->settings->get_key( 'dataforseo_login' )
			&& '' !== $this->settings->get_key( 'dataforseo_password' );
	}

	/**
	 * Account balance (USD), null on failure.
	 *
	 * @return float|null
	 */
	public function balance(): ?float {
		if ( ! $this->is_configured() ) {
			return null;
		}
		try {
			$data = $this->request( 'GET', '/appendix/user_data' );
			$bal  = $data['tasks'][0]['result'][0]['money']['balance'] ?? null;
			return null === $bal ? null : (float) $bal;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Keyword suggestions (Labs), one seed.
	 *
	 * @param string $keyword Seed keyword.
	 * @param int    $limit   Max suggestions.
	 * @return array List of keyword strings.
	 */
	public function suggestions( string $keyword, int $limit = 100 ): array {
		$data = $this->request(
			'POST',
			'/dataforseo_labs/google/keyword_suggestions/live',
			array(
				array_merge(
					array(
						'keyword' => $keyword,
						'depth'   => 1,
						'limit'   => $limit,
					),
					$this->location()
				)
			)
		);
		return $this->extract_keyword_strings( $data );
	}

	/**
	 * Related keywords (Labs), one seed.
	 *
	 * @param string $keyword Seed keyword.
	 * @param int    $limit   Max keywords.
	 * @return array List of keyword strings.
	 */
	public function related( string $keyword, int $limit = 100 ): array {
		$data = $this->request(
			'POST',
			'/dataforseo_labs/google/related_keywords/live',
			array(
				array_merge(
					array(
						'keyword' => $keyword,
						'depth'   => 1,
						'limit'   => $limit,
					),
					$this->location()
				)
			)
		);
		return $this->extract_keyword_strings( $data );
	}

	/**
	 * Search volume + CPC for up to 1000 keywords (Google Ads source).
	 *
	 * @param array $keywords Keyword strings.
	 * @return array keyword => ['volume' => ?int, 'cpc' => ?float]
	 */
	public function search_volume( array $keywords ): array {
		$data = $this->request(
			'POST',
			'/keywords_data/google_ads/search_volume/live',
			array(
				array_merge(
					array( 'keywords' => array_values( $keywords ) ),
					$this->location()
				)
			)
		);

		$out = array();
		foreach ( (array) ( $data['tasks'][0]['result'] ?? array() ) as $item ) {
			if ( empty( $item['keyword'] ) ) {
				continue;
			}
			$out[ mb_strtolower( (string) $item['keyword'] ) ] = array(
				'volume' => isset( $item['search_volume'] ) ? (int) $item['search_volume'] : null,
				'cpc'    => isset( $item['cpc'] ) ? (float) $item['cpc'] : null,
			);
		}
		return $out;
	}

	/**
	 * Keyword difficulty for up to 1000 keywords (Labs).
	 *
	 * @param array $keywords Keyword strings.
	 * @return array keyword => float kd
	 */
	public function keyword_difficulty( array $keywords ): array {
		$data = $this->request(
			'POST',
			'/dataforseo_labs/google/bulk_keyword_difficulty/live',
			array(
				array_merge(
					array( 'keywords' => array_values( $keywords ) ),
					$this->location()
				)
			)
		);

		$out = array();
		foreach ( (array) ( $data['tasks'][0]['result'][0]['items'] ?? array() ) as $item ) {
			if ( empty( $item['keyword'] ) ) {
				continue;
			}
			$out[ mb_strtolower( (string) $item['keyword'] ) ] = (float) ( $item['keyword_difficulty'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Fetch the AI Overview block for a keyword from the live SERP.
	 *
	 * @param string $keyword Keyword.
	 * @return array{has_aio:bool,references:array} references: [{url,domain,title}]
	 */
	public function serp_aio( string $keyword ): array {
		$data = $this->request(
			'POST',
			'/serp/google/organic/live/advanced',
			array(
				array_merge(
					array(
						'keyword' => $keyword,
						'device'  => 'desktop',
						'depth'   => 10,
					),
					$this->location()
				)
			)
		);

		$has_aio    = false;
		$references = array();

		foreach ( (array) ( $data['tasks'][0]['result'][0]['items'] ?? array() ) as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			if ( false === strpos( $type, 'ai_overview' ) ) {
				continue;
			}
			$has_aio = true;
			foreach ( (array) ( $item['references'] ?? array() ) as $ref ) {
				$url = (string) ( $ref['url'] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				$references[] = array(
					'url'    => $url,
					'domain' => (string) ( $ref['domain'] ?? ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) ),
					'title'  => (string) ( $ref['title'] ?? ( $ref['source'] ?? '' ) ),
				);
			}
		}

		return array(
			'has_aio'    => $has_aio,
			'references' => $references,
		);
	}

	/**
	 * Location/language pair derived from the content language setting.
	 *
	 * @return array{location_code:int,language_code:string}
	 */
	private function location(): array {
		$lang   = $this->settings->all()['content']['language'] ?? 'en-US';
		$map    = array(
			'en-US' => array( 2840, 'en' ),
			'en-GB' => array( 2826, 'en' ),
			'en-AU' => array( 2036, 'en' ),
		);
		$pair   = $map[ $lang ] ?? $map['en-US'];
		return array(
			'location_code' => $pair[0],
			'language_code' => $pair[1],
		);
	}

	/**
	 * Extract keyword strings from Labs suggestion/related responses,
	 * tolerating their differing item shapes.
	 *
	 * @param array $data Decoded API response.
	 * @return array
	 */
	private function extract_keyword_strings( array $data ): array {
		$out = array();
		foreach ( (array) ( $data['tasks'][0]['result'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
				$kw = $item['keyword']
					?? $item['keyword_data']['keyword']
					?? null;
				if ( is_string( $kw ) && '' !== trim( $kw ) ) {
					$out[] = $kw;
				}
			}
		}
		return $out;
	}

	/**
	 * HTTP helper with basic-auth and status checks.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   API path.
	 * @param array  $body   POST body (array of task objects).
	 * @return array Decoded response.
	 * @throws \RuntimeException On transport or API-level failure.
	 */
	private function request( string $method, string $path, array $body = array() ): array {
		$login    = $this->settings->get_key( 'dataforseo_login' );
		$password = $this->settings->get_key( 'dataforseo_password' );

		$args = array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ),
				'Content-Type'  => 'application/json',
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = 'GET' === $method
			? wp_remote_get( self::BASE . $path, $args )
			: wp_remote_post( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'DataForSEO request failed: ' . $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			throw new \RuntimeException( "DataForSEO HTTP {$code}" );
		}

		$status = (int) ( $decoded['status_code'] ?? 20000 );
		if ( 20000 !== $status ) {
			throw new \RuntimeException(
				'DataForSEO error ' . $status . ': ' . (string) ( $decoded['status_message'] ?? '' )
			);
		}

		$task_status = (int) ( $decoded['tasks'][0]['status_code'] ?? 20000 );
		if ( 20000 !== $task_status ) {
			throw new \RuntimeException(
				'DataForSEO task error ' . $task_status . ': ' . (string) ( $decoded['tasks'][0]['status_message'] ?? '' )
			);
		}

		return $decoded;
	}
}
