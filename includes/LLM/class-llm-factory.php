<?php
/**
 * Builds LLM clients from stored settings.
 *
 * @package SCP
 */

namespace SCP\LLM;

use SCP\Settings;

/**
 * Creates provider clients based on the configured BYOK keys.
 */
class LLM_Factory {

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
	 * Create the client for the currently configured provider.
	 *
	 * @return LLM_Client
	 * @throws LLM_Exception When provider unsupported or key missing.
	 */
	public function create(): LLM_Client {
		$all      = $this->settings->all();
		$provider = $all['llm']['provider'] ?? 'openai';
		$model    = $all['llm']['model'] ?? 'gpt-4o-mini';
		return $this->create_for( $provider, $model );
	}

	/**
	 * Create a client for an explicit provider (used by GEO engines).
	 *
	 * @param string $provider Provider id: openai|gemini|perplexity.
	 * @param string $model    Optional model override.
	 * @return LLM_Client
	 * @throws LLM_Exception When provider unsupported or key missing.
	 */
	public function create_for( string $provider, string $model = '' ): LLM_Client {
		$key = $this->settings->get_key( $provider );

		switch ( $provider ) {
			case 'openai':
				return new OpenAI_Client( $key, $model ?: 'gpt-4o-mini' );
			case 'gemini':
				return new Gemini_Client( $key, $model ?: 'gemini-2.0-flash' );
			case 'perplexity':
				return new Perplexity_Client( $key, $model ?: 'sonar' );
			default:
				throw new LLM_Exception( "Provider '{$provider}' is not supported yet.", $provider );
		}
	}

	/**
	 * Providers that have a key configured (for GEO engine selection).
	 *
	 * @return array Provider ids.
	 */
	public function configured_providers(): array {
		$out = array();
		foreach ( array( 'openai', 'gemini', 'perplexity' ) as $provider ) {
			if ( '' !== $this->settings->get_key( $provider ) ) {
				$out[] = $provider;
			}
		}
		return $out;
	}
}
