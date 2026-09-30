<?php
/**
 * GEO content rules (F12, engineering §8.3): write-to-be-cited prompt fragment.
 *
 * @package SCP
 */

namespace SCP\GEO;

/**
 * Provides the "optimized for being cited by AI answers" prompt fragment.
 */
class GEO_Rules {

	const FRAGMENT = <<<'RULES'
Citation-optimization rules (GEO):
- Phrase as many H2 headings as natural in question form, and answer the question directly in the first 2-3 sentences under it — a self-contained "answer block" an AI could quote verbatim.
- Include at least one data table or a concrete statistics/steps list.
- Define the core term crisply once ("X is ...") before expanding on it.
- Keep every key claim specific and attributable (numbers, names, steps), never vague.
RULES;

	/**
	 * Prompt fragment when enabled in settings, else empty string.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	public static function fragment( array $settings ): string {
		if ( empty( $settings['geo']['content_rules'] ) ) {
			return '';
		}
		return self::FRAGMENT;
	}
}
