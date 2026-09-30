<?php
/**
 * LLM-based search intent classification (batched).
 *
 * @package SCP
 */

namespace SCP\Keywords;

use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;

/**
 * Classifies keywords into informational / commercial / navigational / transactional.
 */
class Intent_Classifier {

	const INTENTS = array( 'informational', 'commercial', 'navigational', 'transactional' );

	/**
	 * LLM client.
	 *
	 * @var LLM_Client
	 */
	private $llm;

	/**
	 * Constructor.
	 *
	 * @param LLM_Client $llm LLM client.
	 */
	public function __construct( LLM_Client $llm ) {
		$this->llm = $llm;
	}

	/**
	 * Classify up to ~50 keywords in one call.
	 *
	 * @param array $keywords Keyword strings.
	 * @return array keyword => intent (only successfully classified entries).
	 * @throws LLM_Exception On API failure.
	 */
	public function classify( array $keywords ): array {
		$keywords = array_values( array_unique( array_filter( array_map( 'trim', $keywords ) ) ) );
		if ( empty( $keywords ) ) {
			return array();
		}

		$list   = implode( "\n", array_map( fn( $k ) => '- ' . $k, $keywords ) );
		$result = $this->llm->complete(
			array(
				array(
					'role'    => 'system',
					'content' => 'You classify search keywords by dominant search intent. Output only valid JSON.',
				),
				array(
					'role'    => 'user',
					'content' => "Classify each keyword as exactly one of: informational, commercial, navigational, transactional.\n"
						. "Rules: how-to/guide/question = informational; best/review/vs/pricing/buy = commercial; brand or site names = navigational; clear purchase/hire/signup action = transactional.\n"
						. "Return a single JSON object mapping each keyword (exact string) to its intent.\n\nKeywords:\n{$list}",
				),
			),
			array(
				'json'       => true,
				'max_tokens' => 2000,
				'temperature'=> 0,
			)
		);

		$decoded = json_decode( $result['text'], true );
		if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $result['text'], $m ) ) {
			$decoded = json_decode( $m[0], true );
		}
		if ( ! is_array( $decoded ) ) {
			throw new LLM_Exception( 'Failed to parse intent classification JSON.', 'openai' );
		}

		$out = array();
		foreach ( $decoded as $keyword => $intent ) {
			$intent = mb_strtolower( trim( (string) $intent ) );
			if ( in_array( $intent, self::INTENTS, true ) ) {
				$out[ mb_strtolower( trim( (string) $keyword ) ) ] = $intent;
			}
		}
		return $out;
	}
}
