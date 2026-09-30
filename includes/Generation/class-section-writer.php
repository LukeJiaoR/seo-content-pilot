<?php
/**
 * Per-section body generation step.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;

/**
 * Writes one article section (HTML fragment) per LLM call.
 */
class Section_Writer {

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
	 * Write a single section.
	 *
	 * @param string $keyword        Target keyword.
	 * @param array  $section        Outline section node (heading, points).
	 * @param array  $outline        Full outline (context).
	 * @param string $prev_summary   What previous sections covered.
	 * @param int    $section_words  Target words for this section.
	 * @param array  $settings       Plugin settings.
	 * @return array{html:string,tokens:int}
	 * @throws LLM_Exception On API failure.
	 */
	public function write( string $keyword, array $section, array $outline, string $prev_summary, int $section_words, array $settings ): array {
		list( $system, $user ) = Prompt_Templates::section(
			array(
				'keyword'         => $keyword,
				'language'        => $settings['content']['language'] ?? 'en-US',
				'tone'            => $settings['content']['tone'] ?? 'professional',
				'title'           => $outline['title'] ?? $keyword,
				'heading'         => $section['heading'],
				'outline_summary' => $this->outline_summary( $outline ),
				'prev_summary'    => $prev_summary ?: '(nothing yet — this is the first section)',
				'section_words'   => max( 150, $section_words ),
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
				'max_tokens' => (int) max( 800, $section_words * 2 ),
			)
		);

		return array(
			'html'   => $this->clean_html( $result['text'] ),
			'tokens' => $result['prompt_tokens'] + $result['completion_tokens'],
		);
	}

	/**
	 * Compact outline listing for context.
	 *
	 * @param array $outline Outline.
	 * @return string
	 */
	private function outline_summary( array $outline ): string {
		$lines = array();
		foreach ( (array) ( $outline['sections'] ?? array() ) as $i => $section ) {
			$lines[] = ( $i + 1 ) . '. ' . ( $section['heading'] ?? '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Strip markdown fences / stray wrappers from model HTML output.
	 *
	 * @param string $text Raw output.
	 * @return string
	 */
	private function clean_html( string $text ): string {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:html)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		return trim( (string) $text );
	}
}
