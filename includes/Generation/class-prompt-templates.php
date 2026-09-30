<?php
/**
 * Prompt template loader + anti-AI-flavor directives.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\Settings;

/**
 * Builds [system, user] message pairs from file-based templates,
 * applying user overrides from settings with a variable whitelist.
 */
class Prompt_Templates {

	/**
	 * Common "de-AI-flavor" rules appended to every system prompt.
	 *
	 * Banned words/phrases are merged from a static base + a longer
	 * supplemental list below. They are intentionally specific; vague bans
	 * ("be natural") don't change model output.
	 */
	const STYLE_RULES = <<<'RULES'
Writing rules you must follow strictly:
- Never use these words or phrases: delve, tapestry, landscape (metaphorical), testament, realm, navigate (metaphorical), foster, moreover, furthermore, "in today's fast-paced world", "game-changer", "unlock", "elevate", "harness", "ever-evolving", "cutting-edge", "revolutionize", "in conclusion", "ultimately", "to sum up", "as we have seen", "as mentioned earlier", "in this section", "in this article", "let's dive in", "let's explore", "delve into", "it's worth noting", "it's important to note", "navigating the", "robust solution", "seamless integration", "leverage" (as a verb), "unleash", "supercharge", "in the realm of", "in the world of", "embark on a journey", "vital", "crucial" (used as filler), "comprehensive guide", "everything you need to know".
- Vary paragraph length naturally. {paragraph_profile}
- Do not end sections with hollow summary sentences ("In conclusion...", "Ultimately...").
- Do not use rhetorical questions as a crutch. At most one per article.
- Do not stuff the keyword. Use it or a close variant at most 2-3 times per section.
- Write like a practitioner sharing specifics (numbers, dates, named tools, prices, step counts), not like marketing copy.
- Vary sentence openers: do not start 3+ consecutive sentences with the same word.
- Prefer concrete over abstract. "Costs $14/mo and syncs in 90 seconds" beats "Affordable and easy to use".
RULES;

	/**
	 * Supplemental banned words that the model should avoid, beyond the
	 * short list embedded in STYLE_RULES. The full list is injected into
	 * the section-writing prompt because section calls are where voice
	 * drift actually surfaces (the outline is a single short JSON call).
	 *
	 * This list was assembled from common GPT/Claude filler phrases observed
	 * across repeated runs; extend freely.
	 */
	const BANNED_PHRASES_SUPPLEMENTAL = array(
		'dive deep', 'dive deeper', 'dive into', 'delve into',
		'when it comes to', 'as far as', 'is concerned',
		'that being said', 'having said that', 'with that in mind',
		'all things considered', 'at the end of the day',
		'in a nutshell', 'to put it simply', 'to put it differently',
		'move the needle', 'move the ball', 'raise the bar',
		'paradigm shift', 'synergy', 'synergies', 'holistic',
		'best-in-class', 'world-class', 'next-generation', 'bleeding-edge',
		'state-of-the-art', 'industry-leading', 'one-stop shop',
		'streamline', 'optimize' . ' (without a concrete target)',
		'circle back', 'touch base', 'low-hanging fruit',
		'deep dive', 'in this guide', 'in this post', 'in this tutorial',
		'without further ado', 'strap in', 'buckle up',
		'reasons why', 'things to consider', 'things to keep in mind',
	);

	/**
	 * Assemble the banned-words block for the section prompt, including the
	 * supplemental list. Returned as a single string ready for injection.
	 *
	 * @return string
	 */
	private static function banned_phrase_block(): string {
		$list = implode( ', ', self::BANNED_PHRASES_SUPPLEMENTAL );
		return "\n\nAdditional phrases to avoid: {$list}.";
	}

	/**
	 * Outline prompt pair.
	 *
	 * @param array  $vars     Template variables.
	 * @param array  $settings Plugin settings.
	 * @return array{0:string,1:string} [system, user]
	 */
	public static function outline( array $vars, array $settings ): array {
		$seed     = (string) ( $vars['keyword'] ?? '' );
		$intent   = (string) ( $vars['intent'] ?? '' );
		$template = Prompt_Diversifier::choose_template( $intent, $seed );
		$hook     = Prompt_Diversifier::choose_hook( $seed );
		$profile  = Prompt_Diversifier::paragraph_profile( $seed );

		$system = self::resolve_style_rules( array( 'paragraph_profile' => $profile ) )
			. self::geo( $settings );

		$user_vars          = $vars;
		$user_vars['template_directive'] = Prompt_Diversifier::template_directive( $template );
		$user_vars['hook_directive']     = Prompt_Diversifier::hook_directive( $hook );

		$user   = self::render( 'outline', $user_vars, $settings );
		return array( $system, $user );
	}

	/**
	 * Section prompt pair.
	 *
	 * @param array  $vars     Template variables.
	 * @param array  $settings Plugin settings.
	 * @return array{0:string,1:string} [system, user]
	 */
	public static function section( array $vars, array $settings ): array {
		$seed    = (string) ( $vars['keyword'] ?? '' );
		$profile = Prompt_Diversifier::paragraph_profile( $seed );

		$system = self::resolve_style_rules( array( 'paragraph_profile' => $profile ) )
			. self::banned_phrase_block()
			. self::geo( $settings );

		$user   = self::render( 'section', $vars, $settings );
		return array( $system, $user );
	}

	/**
	 * Meta prompt pair.
	 *
	 * @param array  $vars     Template variables.
	 * @param array  $settings Plugin settings.
	 * @return array{0:string,1:string} [system, user]
	 */
	public static function meta( array $vars, array $settings ): array {
		$seed    = (string) ( $vars['keyword'] ?? '' );
		$profile = Prompt_Diversifier::paragraph_profile( $seed );
		$system  = self::resolve_style_rules( array( 'paragraph_profile' => $profile ) );
		$user    = self::render( 'meta', $vars, $settings );
		return array( $system, $user );
	}

	/**
	 * Revision prompt pair (used when keyword placement check fails).
	 *
	 * @param string $content  Current HTML.
	 * @param string $keyword  Target keyword.
	 * @param array  $failures Failed placement rules.
	 * @return array{0:string,1:string} [system, user]
	 */
	public static function revise( string $content, string $keyword, array $failures ): array {
		$profile = Prompt_Diversifier::paragraph_profile( $keyword );
		$system  = self::resolve_style_rules( array( 'paragraph_profile' => $profile ) )
			. self::banned_phrase_block();
		$user    = sprintf(
			"Revise the following article HTML minimally so that:\n%s\n\nRules: keep the structure, headings and meaning; only adjust wording where needed; output the full revised HTML only.\n\nKeyword: \"%s\"\n\nHTML:\n%s",
			'- ' . implode( "\n- ", $failures ),
			$keyword,
			$content
		);
		return array( $system, $user );
	}

	/**
	 * Resolve STYLE_RULES placeholders against the runtime variable set.
	 *
	 * The static STYLE_RULES string contains `{paragraph_profile}` which
	 * must be filled in per article so consecutive articles get a varied
	 * paragraph-length target.
	 *
	 * @param array $vars Variables to substitute into STYLE_RULES.
	 * @return string
	 */
	private static function resolve_style_rules( array $vars ): string {
		$system_head = 'You are an expert SEO content strategist. You output only valid JSON.';
		if ( false !== strpos( self::STYLE_RULES, '{paragraph_profile}' ) ) {
			$resolved = strtr( self::STYLE_RULES, array(
				'{paragraph_profile}' => (string) ( $vars['paragraph_profile'] ?? '' ),
			) );
		} else {
			$resolved = self::STYLE_RULES;
		}
		return $system_head . "\n\n" . $resolved;
	}

	/**
	 * GEO citation-optimization fragment (§8.3), appended when enabled.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private static function geo( array $settings ): string {
		$fragment = \SCP\GEO\GEO_Rules::fragment( $settings );
		return '' === $fragment ? '' : "\n\n" . $fragment;
	}

	/**
	 * Render a named template: user override from settings, else file default.
	 *
	 * @param string $name     Template name (outline|section|meta).
	 * @param array  $vars     Variables.
	 * @param array  $settings Plugin settings.
	 * @return string
	 */
	private static function render( string $name, array $vars, array $settings ): string {
		$template = '';
		if ( ! empty( $settings['prompts'][ $name ] ) ) {
			$template = (string) $settings['prompts'][ $name ];
		} else {
			$file = __DIR__ . '/Prompts/' . $name . '.php';
			if ( file_exists( $file ) ) {
				$template = require $file;
			}
		}
		return self::interpolate( (string) $template, $vars, Settings::template_variables()[ $name ] ?? array() );
	}

	/**
	 * Interpolate whitelisted {variables}.
	 *
	 * @param string $template  Template string.
	 * @param array  $vars      Values.
	 * @param array  $whitelist Allowed variable names.
	 * @return string
	 */
	private static function interpolate( string $template, array $vars, array $whitelist ): string {
		$replacements = array();
		foreach ( $whitelist as $var ) {
			$value = $vars[ $var ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( "\n", array_map( 'strval', $value ) );
			}
			$replacements[ '{' . $var . '}' ] = (string) $value;
		}
		return strtr( $template, $replacements );
	}
}
