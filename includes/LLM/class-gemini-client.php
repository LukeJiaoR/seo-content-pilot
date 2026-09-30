<?php
/**
 * Google Gemini client (BYOK, generateContent API).
 *
 * @package SCP
 */

namespace SCP\LLM;

/**
 * Talks to the Gemini v1beta generateContent endpoint.
 */
class Gemini_Client implements LLM_Client {

	const API_BASE    = 'https://generativelanguage.googleapis.com/v1beta/models';
	const MAX_RETRIES = 3;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Default model.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Gemini API key.
	 * @param string $model   Default model.
	 */
	public function __construct( string $api_key, string $model = 'gemini-2.0-flash' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_provider(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( array $messages, array $opts = array() ): array {
		if ( '' === $this->api_key ) {
			throw new LLM_Exception( 'Gemini API key is not configured.', 'gemini' );
		}

		$model = $opts['model'] ?? $this->model;
		$url   = self::API_BASE . '/' . rawurlencode( $model ) . ':generateContent';

		$system = '';
		$contents = array();
		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? 'user' );
			$content = (string) ( $message['content'] ?? '' );
			if ( 'system' === $role ) {
				$system .= ( '' === $system ? '' : "\n\n" ) . $content;
				continue;
			}
			$contents[] = array(
				'role'  => 'assistant' === $role ? 'model' : 'user',
				'parts' => array( array( 'text' => $content ) ),
			);
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => (float) ( $opts['temperature'] ?? 0.7 ),
				'maxOutputTokens' => (int) ( $opts['max_tokens'] ?? 4096 ),
			),
		);
		if ( '' !== $system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}
		if ( ! empty( $opts['json'] ) ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

		$attempt = 0;
		$delay   = 2;

		while ( true ) {
			$attempt++;
			$response = wp_remote_post(
				add_query_arg( 'key', $this->api_key, $url ),
				array(
					'timeout' => 60,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= self::MAX_RETRIES ) {
					throw new LLM_Exception( 'Gemini request failed: ' . $response->get_error_message(), 'gemini' );
				}
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
				$text = (string) ( $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '' );
				if ( '' === trim( $text ) ) {
					throw new LLM_Exception( 'Gemini returned an empty completion.', 'gemini', $code );
				}
				return array(
					'text'              => $text,
					'prompt_tokens'     => (int) ( $decoded['usageMetadata']['promptTokenCount'] ?? 0 ),
					'completion_tokens' => (int) ( $decoded['usageMetadata']['candidatesTokenCount'] ?? 0 ),
					'model'             => $model,
				);
			}

			$message = is_array( $decoded )
				? (string) ( $decoded['error']['message'] ?? wp_remote_retrieve_body( $response ) )
				: (string) wp_remote_retrieve_body( $response );

			if ( ( 429 === $code || $code >= 500 ) && $attempt < self::MAX_RETRIES ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}
			throw new LLM_Exception( sprintf( 'Gemini API error (HTTP %d): %s', $code, $message ), 'gemini', $code );
		}
	}
}
