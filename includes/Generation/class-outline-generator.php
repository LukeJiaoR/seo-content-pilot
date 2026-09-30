<?php
/**
 * Outline generation step.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;

/**
 * Produces the article outline JSON (title, slug, sections, FAQ questions).
 */
class Outline_Generator {

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
	 * Generate an outline.
	 *
	 * @param string $keyword  Target keyword.
	 * @param array  $settings Plugin settings.
	 * @param int    $words    Target total word count.
	 * @return array{outline:array,tokens:int} Parsed outline + tokens used.
	 * @throws LLM_Exception On API or parse failure.
	 */
	public function generate( string $keyword, array $settings, int $words ): array {
		list( $system, $user ) = Prompt_Templates::outline(
			array(
				'keyword'       => $keyword,
				'language'      => $settings['content']['language'] ?? 'en-US',
				'tone'          => $settings['content']['tone'] ?? 'professional',
				'word_count'    => $words,
				'serp_titles'   => '',
				'paa_questions' => '',
			),
			$settings
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
				'json'       => true,
				'max_tokens' => 2000,
			)
		);

		$outline = $this->parse_json( $result['text'] );
		$this->validate( $outline );

		return array(
			'outline' => $outline,
			'tokens'  => $result['prompt_tokens'] + $result['completion_tokens'],
		);
	}

	/**
	 * Parse JSON with a markdown-fence fallback.
	 *
	 * @param string $text Raw model output.
	 * @return array
	 * @throws LLM_Exception When unparseable.
	 */
	private function parse_json( string $text ): array {
		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$decoded = json_decode( $m[0], true );
			}
		}
		if ( ! is_array( $decoded ) ) {
			throw new LLM_Exception( 'Failed to parse outline JSON from model output.', 'openai' );
		}
		return $decoded;
	}

	/**
	 * Validate outline structure.
	 *
	 * @param array $outline Parsed outline.
	 * @return void
	 * @throws LLM_Exception When structure invalid.
	 */
	private function validate( array $outline ): void {
		if ( empty( $outline['title'] ) || empty( $outline['sections'] ) || ! is_array( $outline['sections'] ) ) {
			throw new LLM_Exception( 'Outline JSON is missing title or sections.', 'openai' );
		}
		foreach ( $outline['sections'] as $section ) {
			if ( empty( $section['heading'] ) ) {
				throw new LLM_Exception( 'Outline section is missing a heading.', 'openai' );
			}
		}
	}
}
