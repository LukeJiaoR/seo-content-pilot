<?php
/**
 * Finalize step: meta fields, FAQ answers, keyword placement check.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\LLM\LLM_Client;
use SCP\LLM\LLM_Exception;

/**
 * Produces meta title/description/excerpt + FAQ answers, and enforces
 * basic keyword placement with at most one revision call.
 */
class SEO_Finalizer {

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
	 * Generate meta + FAQ answers.
	 *
	 * @param string $keyword  Target keyword.
	 * @param string $title    Article title.
	 * @param string $content  Assembled body HTML.
	 * @param array  $faq_qs   FAQ questions from the outline.
	 * @param array  $settings Plugin settings.
	 * @return array{meta:array,tokens:int}
	 * @throws LLM_Exception On API or parse failure.
	 */
	public function finalize( string $keyword, string $title, string $content, array $faq_qs, array $settings ): array {
		$excerpt = wp_strip_all_tags( $content );
		$excerpt = mb_substr( $excerpt, 0, 2000 );

		list( $system, $user ) = Prompt_Templates::meta(
			array(
				'keyword'           => $keyword,
				'language'          => $settings['content']['language'] ?? 'en-US',
				'tone'              => $settings['content']['tone'] ?? 'professional',
				'title'             => $title,
				'full_text_excerpt' => $excerpt,
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
				'max_tokens' => 1500,
			)
		);

		$meta = json_decode( $result['text'], true );
		if ( ! is_array( $meta ) ) {
			if ( preg_match( '/\{.*\}/s', $result['text'], $m ) ) {
				$meta = json_decode( $m[0], true );
			}
		}
		if ( ! is_array( $meta ) ) {
			throw new LLM_Exception( 'Failed to parse meta JSON from model output.', 'openai' );
		}

		// Guarantee FAQ coverage even if the model dropped questions.
		if ( empty( $meta['faq'] ) && ! empty( $faq_qs ) ) {
			$meta['faq'] = array_map(
				fn( $q ) => array(
					'question' => (string) $q,
					'answer'   => '',
				),
				$faq_qs
			);
		}

		return array(
			'meta'   => $meta,
			'tokens' => $result['prompt_tokens'] + $result['completion_tokens'],
		);
	}

	/**
	 * Check keyword placement; on failure run exactly one revision call.
	 *
	 * @param string $content Assembled HTML.
	 * @param string $keyword Target keyword.
	 * @return array{content:string,tokens:int,revised:bool}
	 */
	public function enforce_keyword_placement( string $content, string $keyword ): array {
		$failures = $this->placement_failures( $content, $keyword );
		if ( empty( $failures ) ) {
			return array(
				'content' => $content,
				'tokens'  => 0,
				'revised' => false,
			);
		}

		list( $system, $user ) = Prompt_Templates::revise( $content, $keyword, $failures );

		try {
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
				array( 'max_tokens' => 6000 )
			);

			$revised = trim( $result['text'] );
			$revised = preg_replace( '/^```(?:html)?\s*/i', '', $revised );
			$revised = preg_replace( '/\s*```$/', '', (string) $revised );

			// Only accept the revision if it still contains the section structure.
			if ( '' !== $revised && substr_count( $revised, '<h2' ) >= 2 ) {
				return array(
					'content' => $revised,
					'tokens'  => $result['prompt_tokens'] + $result['completion_tokens'],
					'revised' => true,
				);
			}
		} catch ( LLM_Exception $e ) {
			// Revision is best-effort; keep the original on failure.
		}

		return array(
			'content' => $content,
			'tokens'  => 0,
			'revised' => false,
		);
	}

	/**
	 * Placement rules: keyword in first 10% of text, in >=1 H2, in last 15%.
	 *
	 * @param string $content HTML.
	 * @param string $keyword Keyword.
	 * @return array Failed rule descriptions (empty = pass).
	 */
	private function placement_failures( string $content, string $keyword ): array {
		$failures = array();
		$kw       = mb_strtolower( trim( $keyword ) );
		if ( '' === $kw ) {
			return $failures;
		}

		$text = mb_strtolower( wp_strip_all_tags( $content ) );
		$len  = max( 1, mb_strlen( $text ) );

		$intro = mb_substr( $text, 0, (int) ( $len * 0.1 ) );
		if ( false === mb_strpos( $intro, $kw ) ) {
			$failures[] = "The keyword \"{$keyword}\" must appear within the opening paragraph.";
		}

		preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $h2s );
		$in_h2 = false;
		foreach ( (array) $h2s[1] as $h2 ) {
			if ( false !== mb_strpos( mb_strtolower( wp_strip_all_tags( $h2 ) ), $kw ) ) {
				$in_h2 = true;
				break;
			}
		}
		if ( ! $in_h2 ) {
			$failures[] = "The keyword \"{$keyword}\" must appear in at least one H2 heading.";
		}

		$outro = mb_substr( $text, (int) ( $len * 0.85 ) );
		if ( false === mb_strpos( $outro, $kw ) ) {
			$failures[] = "The keyword \"{$keyword}\" must appear near the end of the article.";
		}

		return $failures;
	}
}
