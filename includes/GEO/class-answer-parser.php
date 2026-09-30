<?php
/**
 * Structured parser for LLM answers (engineering §8.1).
 *
 * Answers from ChatGPT/Gemini/Perplexity vary too much for regex — one
 * extra structured LLM call (JSON mode) normalizes them into
 * mentioned / cited / position / sentiment / competitors.
 *
 * @package SCP
 */

namespace SCP\GEO;

use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;

/**
 * Turns a free-form AI answer into tracking metrics.
 */
class Answer_Parser {

	/**
	 * Parsing LLM (default provider — cheap model).
	 *
	 * @var LLM_Client
	 */
	private $llm;

	/**
	 * Constructor.
	 *
	 * @param LLM_Client $llm Parsing LLM client.
	 */
	public function __construct( LLM_Client $llm ) {
		$this->llm = $llm;
	}

	/**
	 * Parse one answer.
	 *
	 * @param string $answer      Raw answer text.
	 * @param array  $brand       Brand settings (brand_name, brand_aliases, site_domain, competitors).
	 * @param string $site_domain Fallback domain when brand.site_domain is empty.
	 * @return array{mentioned:bool,cited:bool,mention_position:?int,sentiment:string,competitors:array}
	 * @throws LLM_Exception On parse failure.
	 */
	public function parse( string $answer, array $brand, string $site_domain ): array {
		$aliases = array_filter(
			array_merge(
				array( (string) ( $brand['brand_name'] ?? '' ) ),
				(array) ( $brand['brand_aliases'] ?? array() )
			)
		);
		$domain       = (string) ( $brand['site_domain'] ?? '' ) ?: $site_domain;
		$competitors  = array_filter( array_map( 'strval', (array) ( $brand['competitors'] ?? array() ) ) );

		$system = 'You analyze AI answers for brand visibility tracking. Output only valid JSON.';
		$user   = sprintf(
			"Analyze the following AI answer.\n\nOur brand names/aliases: %s\nOur domain: %s\nCompetitor domains/brands: %s\n\nReturn JSON with exactly these fields:\n{\n  \"mentioned\": boolean,  // our brand name or domain appears anywhere\n  \"cited\": boolean,      // our domain is given as a source/link/reference\n  \"mention_position\": number|null,  // if the answer is a ranked list and we appear, our 1-based position; else null\n  \"sentiment\": \"positive\"|\"neutral\"|\"negative\",  // tone towards our brand when mentioned; \"neutral\" when not mentioned\n  \"competitors_mentioned\": string[]  // subset of the competitor list that appears\n}\n\nAnswer to analyze:\n\"\"\"\n%s\n\"\"\"",
			implode( ', ', $aliases ) ?: '(none configured)',
			$domain ?: '(unknown)',
			implode( ', ', $competitors ) ?: '(none)',
			mb_substr( $answer, 0, 6000 )
		);

		$result = $this->llm->complete(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			array(
				'json'        => true,
				'max_tokens'  => 400,
				'temperature' => 0,
			)
		);

		$decoded = json_decode( $result['text'], true );
		if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $result['text'], $m ) ) {
			$decoded = json_decode( $m[0], true );
		}
		if ( ! is_array( $decoded ) ) {
			throw new LLM_Exception( 'Failed to parse answer-analysis JSON.', $this->llm->get_provider() );
		}

		$sentiment = (string) ( $decoded['sentiment'] ?? 'neutral' );
		if ( ! in_array( $sentiment, array( 'positive', 'neutral', 'negative' ), true ) ) {
			$sentiment = 'neutral';
		}

		return array(
			'mentioned'   => (bool) ( $decoded['mentioned'] ?? false ),
			'cited'       => (bool) ( $decoded['cited'] ?? false ),
			'position'    => isset( $decoded['mention_position'] ) && is_numeric( $decoded['mention_position'] )
				? (int) $decoded['mention_position']
				: null,
			'sentiment'   => $sentiment,
			'competitors' => array_values( array_filter( array_map( 'strval', (array) ( $decoded['competitors_mentioned'] ?? array() ) ) ) ),
		);
	}
}
