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
	 */
	const STYLE_RULES = <<<'RULES'
Writing rules you must follow strictly:
- Never use these words or phrases: delve, tapestry, landscape (metaphorical), testament, realm, navigate (metaphorical), foster, moreover, furthermore, "in today's fast-paced world", "game-changer", "unlock", "elevate", "harness", "ever-evolving", "cutting-edge", "revolutionize".
- Vary paragraph length naturally between 2 and 6 sentences. Do not make every paragraph the same length.
- Do not end sections with hollow summary sentences ("In conclusion...", "Ultimately...").
- Do not use rhetorical questions as a crutch or obvious keyword stuffing.
- Write like a practitioner sharing specifics, not like marketing copy.
RULES;

	/**
	 * Outline prompt pair.
	 *
	 * @param array  $vars     Template variables.
	 * @param array  $settings Plugin settings.
	 * @return array{0:string,1:string} [system, user]
	 */
	public static function outline( array $vars, array $settings ): array {
		$system = 'You are an expert SEO content strategist. You output only valid JSON.'
			. "\n\n" . self::STYLE_RULES
			. self::geo( $settings );
		$user   = self::render( 'outline', $vars, $settings );
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
		$system = 'You are an expert SEO article writer producing clean HTML fragments.'
			. "\n\n" . self::STYLE_RULES
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
		$system = 'You are an SEO metadata specialist. You output only valid JSON.'
			. "\n\n" . self::STYLE_RULES;
		$user   = self::render( 'meta', $vars, $settings );
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
		$system = 'You are an SEO editor making minimal, surgical edits to HTML.'
			. "\n\n" . self::STYLE_RULES;
		$user   = sprintf(
			"Revise the following article HTML minimally so that:\n%s\n\nRules: keep the structure, headings and meaning; only adjust wording where needed; output the full revised HTML only.\n\nKeyword: \"%s\"\n\nHTML:\n%s",
			'- ' . implode( "\n- ", $failures ),
			$keyword,
			$content
		);
		return array( $system, $user );
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
