<?php
/**
 * OpenAI chat completions client (BYOK).
 *
 * @package SCP
 */

namespace SCP\LLM;

/**
 * Talks to the OpenAI /v1/chat/completions endpoint with retry + backoff.
 */
class OpenAI_Client implements LLM_Client {

	const ENDPOINT   = 'https://api.openai.com/v1/chat/completions';
	const MAX_RETRIES = 3;

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Default model.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Chat completions endpoint (subclasses may override, e.g. Perplexity).
	 *
	 * @var string
	 */
	protected $endpoint = self::ENDPOINT;

	/**
	 * Provider id (subclasses override).
	 *
	 * @var string
	 */
	protected $provider = 'openai';

	/**
	 * Constructor.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $model   Default model.
	 */
	public function __construct( string $api_key, string $model = 'gpt-4o-mini' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( array $messages, array $opts = array() ): array {
		if ( '' === $this->api_key ) {
			throw new LLM_Exception( ucfirst( $this->provider ) . ' API key is not configured.', $this->provider );
		}

		$model       = $opts['model'] ?? $this->model;
		$max_tokens  = (int) ( $opts['max_tokens'] ?? 4096 );
		$temperature = (float) ( $opts['temperature'] ?? 0.7 );

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$attempt = 0;
		$delay   = 2;

		while ( true ) {
			$attempt++;
			$response = wp_remote_post(
				$this->endpoint,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= self::MAX_RETRIES ) {
					throw new LLM_Exception( ucfirst( $this->provider ) . ' request failed: ' . $response->get_error_message(), $this->provider );
				}
				$this->sleep( $delay );
				$delay *= 2;
				continue;
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$raw_body  = (string) wp_remote_retrieve_body( $response );
			$decoded   = json_decode( $raw_body, true );

			if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
				$text = $decoded['choices'][0]['message']['content'] ?? '';
				if ( '' === trim( (string) $text ) ) {
					throw new LLM_Exception( ucfirst( $this->provider ) . ' returned an empty completion.', $this->provider, $code );
				}
				return array(
					'text'              => (string) $text,
					'prompt_tokens'     => (int) ( $decoded['usage']['prompt_tokens'] ?? 0 ),
					'completion_tokens' => (int) ( $decoded['usage']['completion_tokens'] ?? 0 ),
					'model'             => $model,
				);
			}

			$message = $this->extract_error_message( $decoded, $raw_body );

			// Retry only on 429 / 5xx.
			if ( ( 429 === $code || $code >= 500 ) && $attempt < self::MAX_RETRIES ) {
				$this->sleep( $delay );
				$delay *= 2;
				continue;
			}

			throw new LLM_Exception(
				sprintf( '%s API error (HTTP %d): %s', ucfirst( $this->provider ), $code, $message ),
				$this->provider,
				$code
			);
		}
	}

	/**
	 * Generate embeddings (text-embedding-3-small by default).
	 *
	 * @param array  $inputs Input strings (batch).
	 * @param string $model  Embedding model.
	 * @return array List of float vectors, same order as inputs.
	 * @throws LLM_Exception On API failure.
	 */
	public function embed( array $inputs, string $model = 'text-embedding-3-small' ): array {
		if ( '' === $this->api_key ) {
			throw new LLM_Exception( 'OpenAI API key is not configured.', 'openai' );
		}

		$attempt = 0;
		$delay   = 2;

		while ( true ) {
			$attempt++;
			$response = wp_remote_post(
				'https://api.openai.com/v1/embeddings',
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model' => $model,
							'input' => array_values( $inputs ),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= self::MAX_RETRIES ) {
					throw new LLM_Exception( 'OpenAI embeddings request failed: ' . $response->get_error_message(), 'openai' );
				}
				$this->sleep( $delay );
				$delay *= 2;
				continue;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 && is_array( $decoded ) && isset( $decoded['data'] ) ) {
				$vectors = array();
				foreach ( $decoded['data'] as $row ) {
					$vectors[ (int) $row['index'] ] = array_map( 'floatval', (array) $row['embedding'] );
				}
				ksort( $vectors );
				return array_values( $vectors );
			}

			$message = $this->extract_error_message( is_array( $decoded ) ? $decoded : null, (string) wp_remote_retrieve_body( $response ) );
			if ( ( 429 === $code || $code >= 500 ) && $attempt < self::MAX_RETRIES ) {
				$this->sleep( $delay );
				$delay *= 2;
				continue;
			}
			throw new LLM_Exception( sprintf( 'OpenAI embeddings error (HTTP %d): %s', $code, $message ), 'openai', $code );
		}
	}

	/**
	 * Generate an image and return it as raw bytes (base64-decoded).
	 *
	 * @param string $prompt Image prompt.
	 * @param string $size   e.g. 1536x1024 (gpt-image-1) / 1792x1024 (dall-e-3).
	 * @param string $model  Image model.
	 * @return string Raw image bytes.
	 * @throws LLM_Exception On failure.
	 */
	public function generate_image( string $prompt, string $size = '1536x1024', string $model = 'gpt-image-1' ): string {
		if ( '' === $this->api_key ) {
			throw new LLM_Exception( 'OpenAI API key is not configured.', 'openai' );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'  => $model,
						'prompt' => $prompt,
						'n'      => 1,
						'size'   => $size,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new LLM_Exception( 'OpenAI image request failed: ' . $response->get_error_message(), 'openai' );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$b64 = $decoded['data'][0]['b64_json'] ?? null;
		if ( $code >= 200 && $code < 300 && is_string( $b64 ) && '' !== $b64 ) {
			$bytes = base64_decode( $b64, true );
			if ( false !== $bytes ) {
				return $bytes;
			}
		}

		// URL fallback (some models return a URL instead of b64).
		$url = $decoded['data'][0]['url'] ?? null;
		if ( is_string( $url ) && '' !== $url ) {
			$img = wp_remote_get( $url, array( 'timeout' => 60 ) );
			if ( ! is_wp_error( $img ) && 200 === (int) wp_remote_retrieve_response_code( $img ) ) {
				return (string) wp_remote_retrieve_body( $img );
			}
		}

		$message = $this->extract_error_message( is_array( $decoded ) ? $decoded : null, (string) wp_remote_retrieve_body( $response ) );
		throw new LLM_Exception( sprintf( 'OpenAI image error (HTTP %d): %s', $code, $message ), 'openai', $code );
	}

	/**
	 * Pull a readable error message out of an OpenAI error payload.
	 *
	 * @param array|null $decoded Decoded JSON body.
	 * @param string     $raw     Raw body.
	 * @return string
	 */
	private function extract_error_message( ?array $decoded, string $raw ): string {
		if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
			return (string) $decoded['error']['message'];
		}
		return mb_substr( $raw, 0, 300 );
	}

	/**
	 * Sleep helper (test-seam friendly).
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	protected function sleep( int $seconds ): void {
		sleep( $seconds );
	}
}
