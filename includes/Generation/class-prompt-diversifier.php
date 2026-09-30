<?php
/**
 * Anti-homogeneity helpers: per-article template / hook / section-order
 * randomization. Picks a deterministic-but-varied style so that 100 articles
 * generated for the same site do not share an identical skeleton.
 *
 * The diversification is keyed by a seed derived from the keyword (or the
 * outline title) so retries of the same article produce the same style.
 *
 * @package SCP
 */

namespace SCP\Generation;

/**
 * Static utility — no state.
 */
final class Prompt_Diversifier {

	/**
	 * Article templates. Each one biases the LLM toward a different
	 * section structure so two articles on the same keyword don't ship with
	 * identical outlines.
	 *
	 * Each entry provides:
	 *   - id           : machine name
	 *   - description  : short instruction injected into the outline prompt
	 *   - sections     : recommended section roles (the model adapts wording)
	 *   - open_style   : how the first section should open
	 *   - few_shot     : one short example opening that demonstrates the voice
	 */
	const TEMPLATES = array(
		'comparison' => array(
			'id'          => 'comparison',
			'description' => 'A comparison / "best of" article. Lead with a quick pick, then unpack criteria, then a ranked or grouped list of options, then a candid section on trade-offs, then a short FAQ. Prefer tables for side-by-side facts.',
			'sections'    => array( 'quick_pick', 'criteria', 'ranked_list', 'tradeoffs', 'who_should_skip', 'faq' ),
			'open_style'  => 'name-the-winner',
		),
		'how_to'     => array(
			'id'          => 'how_to',
			'description' => 'A procedural how-to article. Lead with a one-line outcome statement, then prerequisites, then numbered steps with concrete details, then a troubleshooting / common-mistakes section, then FAQ.',
			'sections'    => array( 'outcome', 'prerequisites', 'steps', 'troubleshooting', 'checklist', 'faq' ),
			'open_style'  => 'state-the-outcome',
		),
		'explainer'  => array(
			'id'          => 'explainer',
			'description' => 'A concept-explainer article. Lead with a definition and why-it-matters, then a historical or contextual section, then a deep mechanism section, then real-world examples, then a "common misconceptions" section, then FAQ.',
			'sections'    => array( 'definition', 'why_it_matters', 'mechanism', 'examples', 'misconceptions', 'faq' ),
			'open_style'  => 'definition-first',
		),
		'listicle'   => array(
			'id'          => 'listicle',
			'description' => 'A curated listicle article. Lead with the framing (what is on the list and why), then each entry as its own H2 with a one-line summary first, then practical guidance on choosing between entries, then FAQ.',
			'sections'    => array( 'framing', 'entries', 'how_to_choose', 'skippable', 'faq' ),
			'open_style'  => 'frame-the-list',
		),
	);

	/**
	 * Opening-hook styles. The outline prompt asks the model to begin the
	 * first section in one of these voices; rotation prevents every article
	 * from opening with the same "Quick Answer" pattern.
	 */
	const HOOKS = array(
		'data_point'   => 'Open with one specific, verifiable fact (a number, a percentage, a year, a price). State it, then expand.',
		'anecdote'     => 'Open with a short, concrete scenario (1-2 sentences) that the reader can picture, then pivot to the broader point.',
		'contrarian'  => 'Open with a common belief that is partially wrong, then explain the nuance and what to do instead.',
		'definition'   => 'Open with a clean one-sentence definition, then immediately say why the term matters to the reader right now.',
		'reader_question' => 'Open by restating the question the reader typed into the search bar, then answer it directly in the first 2 sentences.',
	);

	/**
	 * Mapping of keyword-intent strings (from class-expander.php / DataForSEO)
	 * to a preferred template id. Falls back to round-robin when intent is
	 * missing or unknown.
	 */
	const INTENT_TO_TEMPLATE = array(
		'commercial'  => 'comparison',
		'transactional' => 'comparison',
		'informational' => 'explainer',
		'how-to'      => 'how_to',
		'how_to'      => 'how_to',
		'list'        => 'listicle',
		'listicle'    => 'listicle',
		'navigational' => 'explainer',
	);

	/**
	 * Choose a template id based on intent + keyword seed.
	 *
	 * @param string      $intent  Optional intent string.
	 * @param string|null $seed    Optional seed (keyword). Deterministic.
	 * @return string One of the TEMPLATES keys.
	 */
	public static function choose_template( string $intent = '', ?string $seed = null ): string {
		$normalized = strtolower( trim( $intent ) );
		if ( '' !== $normalized && isset( self::INTENT_TO_TEMPLATE[ $normalized ] ) ) {
			return self::INTENT_TO_TEMPLATE[ $normalized ];
		}
		// Round-robin by hash so that consecutive jobs without an intent
		// still get a varied template, but retries of the same seed are stable.
		$ids     = array_keys( self::TEMPLATES );
		$buckets = count( $ids );
		$index   = abs( self::hash32( (string) $seed ) ) % $buckets;
		return $ids[ $index ];
	}

	/**
	 * Choose a hook style. Deterministic per seed.
	 *
	 * @param string|null $seed Seed.
	 * @return string Hook id (key of self::HOOKS).
	 */
	public static function choose_hook( ?string $seed = null ): string {
		$ids     = array_keys( self::HOOKS );
		$buckets = count( $ids );
		$index   = abs( self::hash32( (string) $seed . ':hook' ) ) % $buckets;
		return $ids[ $index ];
	}

	/**
	 * Return a sentence-length profile for this seed so consecutive articles
	 * vary between "tight 1-3 sentence paragraphs" and "longer 4-6 sentence
	 * paragraphs". The model is told to honor the range in STYLE_RULES.
	 *
	 * @param string|null $seed Seed.
	 * @return string Profile instruction (e.g. "Aim for short 1-3 sentence
	 *                paragraphs with the occasional 5-sentence paragraph.").
	 */
	public static function paragraph_profile( ?string $seed = null ): string {
		$profiles = array(
			'Most paragraphs should be 2-3 sentences. Use a single 4-5 sentence paragraph every 4-5 paragraphs for rhythm.',
			'Mix it up: alternate 1-sentence punchy paragraphs with 4-5 sentence explainer paragraphs. Do not write 3-sentence paragraphs exclusively.',
			'Aim for short 1-2 sentence paragraphs with the occasional 5-6 sentence paragraph. No two adjacent paragraphs the same length.',
			'Long-flow default: 4-6 sentences per paragraph is fine, but every third paragraph should be 1-2 sentences for breathing room.',
		);
		$index = abs( self::hash32( (string) $seed . ':p' ) ) % count( $profiles );
		return $profiles[ $index ];
	}

	/**
	 * Build the template directive to be injected into the outline prompt.
	 *
	 * @param string $template_id Template id.
	 * @return string
	 */
	public static function template_directive( string $template_id ): string {
		if ( ! isset( self::TEMPLATES[ $template_id ] ) ) {
			$template_id = 'explainer';
		}
		$tpl = self::TEMPLATES[ $template_id ];
		return sprintf(
			"Article template (use this skeleton, do not invent a different one):\n%s\nRecommended section roles in order: %s\nFirst section opening style: %s",
			$tpl['description'],
			implode( ' -> ', $tpl['sections'] ),
			$tpl['open_style']
		);
	}

	/**
	 * Build the hook directive for the outline prompt.
	 *
	 * @param string $hook_id Hook id.
	 * @return string
	 */
	public static function hook_directive( string $hook_id ): string {
		if ( ! isset( self::HOOKS[ $hook_id ] ) ) {
			$hook_id = 'reader_question';
		}
		return 'Opening hook style: ' . self::HOOKS[ $hook_id ];
	}

	/**
	 * Deterministic 32-bit hash of a string, FNV-1a-ish. Avoids the overhead
	 * of hash() for short keys.
	 *
	 * @param string $s String.
	 * @return int
	 */
	private static function hash32( string $s ): int {
		$h = 0x811c9dc5;
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$h ^= ord( $s[ $i ] );
			$h = ( $h * 0x01000193 ) & 0xffffffff;
		}
		return $h;
	}
}