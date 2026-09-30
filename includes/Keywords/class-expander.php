<?php
/**
 * Keyword expansion orchestrator (runs as a background action per seed).
 *
 * Sources (§6.1): Google Autocomplete, DataForSEO suggestions + related,
 * LLM semantic variants. GSC opportunity回流 arrives in S4 and reuses the
 * same scp_keywords insert path.
 *
 * @package SCP
 */

namespace SCP\Keywords;

use SCP\LLM\LLM_Factory;
use SCP\Logger;
use SCP\Settings;

/**
 * Expands one seed into a deduplicated pool of candidate keywords.
 */
class Expander {

	/**
	 * Max rows inserted per seed across all sources.
	 */
	const PER_SEED_CAP = 500;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Expand one seed and insert results into scp_keywords.
	 *
	 * @param string $seed Seed keyword.
	 * @return array{seed:string,inserted:int,by_source:array}
	 */
	public function expand_seed( string $seed ): array {
		$seed = mb_strtolower( trim( $seed ) );
		if ( '' === $seed ) {
			return array(
				'seed'      => $seed,
				'inserted'  => 0,
				'by_source' => array(),
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$by_source = array(
			'autocomplete' => array(),
			'dataforseo'   => array(),
			'llm'          => array(),
		);

		// a) Autocomplete (free).
		try {
			$by_source['autocomplete'] = ( new Autocomplete() )->expand_seed( $seed );
		} catch ( \Throwable $e ) {
			Logger::warning( 'Autocomplete expansion failed: ' . $e->getMessage(), 'expander', array( 'seed' => $seed ) );
		}

		// b) DataForSEO suggestions + related (paid, if configured).
		$dfs = new DataForSEO( $this->settings );
		if ( $dfs->is_configured() ) {
			try {
				$by_source['dataforseo'] = array_merge(
					$dfs->suggestions( $seed, 100 ),
					$dfs->related( $seed, 100 )
				);
			} catch ( \Throwable $e ) {
				Logger::warning( 'DataForSEO expansion failed: ' . $e->getMessage(), 'expander', array( 'seed' => $seed ) );
			}
		}

		// c) LLM semantic variants (cheap single call).
		try {
			$by_source['llm'] = $this->llm_variants( $seed );
		} catch ( \Throwable $e ) {
			Logger::warning( 'LLM expansion failed: ' . $e->getMessage(), 'expander', array( 'seed' => $seed ) );
		}

		$inserted = 0;
		foreach ( $by_source as $source => $keywords ) {
			$by_source[ $source ] = $this->normalize( $keywords );
			$inserted            += $this->insert( $by_source[ $source ], $source );
		}

		Logger::info(
			'Seed expanded',
			'expander',
			array(
				'seed'     => $seed,
				'inserted' => $inserted,
				'sources'  => array_map( 'count', $by_source ),
			)
		);

		return array(
			'seed'      => $seed,
			'inserted'  => $inserted,
			'by_source' => array_map( 'count', $by_source ),
		);
	}

	/**
	 * LLM semantic variants of the seed.
	 *
	 * @param string $seed Seed keyword.
	 * @return array Keyword strings.
	 */
	private function llm_variants( string $seed ): array {
		$llm    = ( new LLM_Factory( $this->settings ) )->create();
		$result = $llm->complete(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are an SEO keyword research assistant. Output only valid JSON.',
				),
				array(
					'role'    => 'user',
					'content' => "Generate 25 English long-tail keyword variations for the seed keyword \"{$seed}\". Cover different intents (how-to, comparison, best-of, pricing, alternatives) and adjacent subtopics. Rules: 3-8 words each, lowercase, no duplicates, no brand names, no keyword stuffing. Return a JSON array of strings only.",
				),
			),
			array(
				'json'       => true,
				'max_tokens' => 1200,
			)
		);

		$decoded = json_decode( $result['text'], true );
		if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $result['text'], $m ) ) {
			$decoded = json_decode( $m[0], true );
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// Tolerate {"keywords": [...]} wrappers.
		if ( isset( $decoded['keywords'] ) && is_array( $decoded['keywords'] ) ) {
			$decoded = $decoded['keywords'];
		}
		return array_filter( array_map( 'strval', $decoded ) );
	}

	/**
	 * Normalize a raw keyword list: lowercase, collapse whitespace, basic
	 * quality filters, dedupe, cap.
	 *
	 * @param array $keywords Raw keywords.
	 * @return array Clean unique keywords.
	 */
	private function normalize( array $keywords ): array {
		$out = array();
		foreach ( $keywords as $kw ) {
			$kw = mb_strtolower( trim( (string) $kw ) );
			$kw = preg_replace( '/\s+/', ' ', $kw );
			// Must be 3-200 chars and contain at least one letter.
			if ( mb_strlen( $kw ) < 3 || mb_strlen( $kw ) > 200 || ! preg_match( '/[a-z]/', $kw ) ) {
				continue;
			}
			$out[ $kw ] = true;
		}
		$list = array_keys( $out );
		return array_slice( $list, 0, self::PER_SEED_CAP );
	}

	/**
	 * Insert keywords, ignoring pool duplicates.
	 *
	 * @param array  $keywords Clean keywords.
	 * @param string $source   Source tag.
	 * @return int Rows actually inserted.
	 */
	private function insert( array $keywords, string $source ): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'scp_keywords';
		$inserted = 0;
		foreach ( $keywords as $kw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$table} (keyword, source, status, created_at) VALUES (%s, %s, 'new', %s)",
					$kw,
					$source,
					current_time( 'mysql' )
				)
			);
			if ( $result ) {
				$inserted += (int) $result;
			}
		}
		return $inserted;
	}
}
