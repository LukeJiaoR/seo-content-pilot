<?php
/**
 * Settings storage with sodium-based encryption for API keys.
 *
 * @package SCP
 */

namespace SCP;

/**
 * Reads/writes `scp_settings` and encrypted `scp_llm_keys`.
 */
class Settings {

	const OPTION_SETTINGS = 'scp_settings';
	const OPTION_KEYS     = 'scp_llm_keys';

	/**
	 * Default settings structure.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'llm'      => array(
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'image_model' => 'gpt-image-1',
			),
			'content'  => array(
				'language'         => 'en-US',
				'length'           => 'medium', // short|medium|long
				'tone'             => 'professional',
				'author_id'        => 0,
				'default_category' => 0,
				'featured_image'   => true,
			),
			'publish'  => array(
				'mode'          => 'draft', // draft|publish|schedule (S1: draft only)
				'daily_cap'     => 2,
				'window_start'  => '09:00',
				'window_end'    => '18:00',
			),
			'prompts'  => array(
				'outline' => '',
				'section' => '',
				'meta'    => '',
			),
			'gsc'      => array(
				'client_id' => '',
				'site'      => '',
			),
			'brand'    => array(
				'brand_name'     => '',
				'brand_aliases'  => array(),
				'site_domain'    => '',
				'competitors'    => array(),
			),
			'geo'      => array(
				'track_cap'             => 50,
				// Changing these two templates breaks week-over-week comparability.
				'prompt_commercial'     => 'What are the best {keyword} tools/products?',
				'prompt_informational'  => 'How to {keyword}? Please recommend sources.',
				'content_rules'         => true,
				'author_box'            => true,
				'llms_txt'              => false,
			),
		);
	}

	/**
	 * Allowed template variables per prompt template (whitelist).
	 *
	 * @return array
	 */
	public static function template_variables(): array {
		return array(
			'outline' => array( 'keyword', 'language', 'tone', 'word_count', 'serp_titles', 'paa_questions', 'template_directive', 'hook_directive' ),
			'section' => array( 'keyword', 'language', 'tone', 'title', 'heading', 'outline_summary', 'prev_summary', 'section_words' ),
			'meta'    => array( 'keyword', 'language', 'tone', 'title', 'full_text_excerpt' ),
		);
	}

	/**
	 * Get merged settings (defaults + stored).
	 *
	 * @return array
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_replace_recursive( self::defaults(), $stored );
	}

	/**
	 * Persist settings (shallow-validated by caller).
	 *
	 * @param array $settings Full settings array.
	 * @return void
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION_SETTINGS, $settings, false );
	}

	/**
	 * Target word count for the configured article length.
	 *
	 * @return int
	 */
	public function target_word_count(): int {
		$length = $this->all()['content']['length'] ?? 'medium';
		return array(
			'short'  => 1000,
			'medium' => 2000,
			'long'   => 3200,
		)[ $length ] ?? 2000;
	}

	/**
	 * Store an API key for a provider (encrypted). Empty string clears it.
	 *
	 * @param string $provider openai|claude|gemini|perplexity|dataforseo_login|dataforseo_password.
	 * @param string $key      Plaintext key.
	 * @return void
	 */
	public function set_key( string $provider, string $key ): void {
		$keys = $this->get_keys( true );
		if ( '' === $key ) {
			unset( $keys[ $provider ] );
		} else {
			$keys[ $provider ] = $key;
		}
		$json = wp_json_encode( $keys );
		update_option( self::OPTION_KEYS, $this->encrypt( $json ), false );
	}

	/**
	 * Get decrypted keys.
	 *
	 * @param bool $raw When true, returns real values; otherwise masked indicators.
	 * @return array provider => key string (or bool "is set" when masked).
	 */
	public function get_keys( bool $raw = false ): array {
		$stored = get_option( self::OPTION_KEYS, '' );
		$keys   = array();
		if ( is_string( $stored ) && '' !== $stored ) {
			$json = $this->decrypt( $stored );
			if ( $json ) {
				$decoded = json_decode( $json, true );
				if ( is_array( $decoded ) ) {
					$keys = $decoded;
				}
			}
		}
		if ( $raw ) {
			return $keys;
		}
		$masked = array();
		foreach ( $keys as $provider => $value ) {
			$masked[ $provider ] = ( '' !== (string) $value );
		}
		return $masked;
	}

	/**
	 * Get one decrypted key.
	 *
	 * @param string $provider Provider id.
	 * @return string Empty when missing.
	 */
	public function get_key( string $provider ): string {
		$keys = $this->get_keys( true );
		return isset( $keys[ $provider ] ) ? (string) $keys[ $provider ] : '';
	}

	/**
	 * Encrypt with sodium secretbox. Key derived from wp-config constants
	 * (site-unique, never stored in DB).
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64 nonce+ciphertext.
	 */
	private function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct    = sodium_crypto_secretbox( $plaintext, $nonce, $this->encryption_key() );
		return base64_encode( $nonce . $ct );
	}

	/**
	 * Decrypt secretbox payload.
	 *
	 * @param string $encoded Base64 nonce+ciphertext.
	 * @return string|false Plaintext or false on failure.
	 */
	private function decrypt( string $encoded ) {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return sodium_crypto_secretbox_open( $ct, $nonce, $this->encryption_key() );
	}

	/**
	 * Derive a 32-byte key from wp-config salts.
	 *
	 * @return string
	 */
	private function encryption_key(): string {
		$material = ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'scp-fallback' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
