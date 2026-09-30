<?php
/**
 * Perplexity client (OpenAI-compatible API).
 *
 * @package SCP
 */

namespace SCP\LLM;

/**
 * Reuses the OpenAI client with the Perplexity endpoint.
 */
class Perplexity_Client extends OpenAI_Client {

	/**
	 * {@inheritDoc}
	 */
	protected $endpoint = 'https://api.perplexity.ai/chat/completions';

	/**
	 * {@inheritDoc}
	 */
	protected $provider = 'perplexity';

	/**
	 * Constructor.
	 *
	 * @param string $api_key Perplexity API key.
	 * @param string $model   Default model.
	 */
	public function __construct( string $api_key, string $model = 'sonar' ) {
		parent::__construct( $api_key, $model );
	}
}
