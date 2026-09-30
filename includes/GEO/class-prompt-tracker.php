<?php
/**
 * GEO Prompt Tracking (F11, engineering §8.1).
 *
 * Weekly task: ask ChatGPT/Gemini/Perplexity the SAME fixed prompt per
 * tracked keyword, then parse each answer into mention/citation metrics.
 * Trend over weeks — never judge from a single check (§5.5).
 *
 * @package SCP
 */

namespace SCP\GEO;

use SCP\LLM\LLM_Factory;
use SCP\Logger;
use SCP\Settings;

/**
 * Tracks brand visibility across AI answer engines.
 */
class Prompt_Tracker {

	const HOOK      = 'scp_geo_weekly_check';
	const GROUP     = 'scp';
	const RUN_CAP   = 10; // Keywords per manual run.

	/**
	 * Engine id => LLM provider id.
	 */
	const ENGINES = array(
		'chatgpt'    => 'openai',
		'gemini'     => 'gemini',
		'perplexity' => 'perplexity',
	);

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
	 * Hook the weekly task.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_weekly' ) );
	}

	/**
	 * Ensure the weekly recurring action exists (admin_init).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 7200, WEEK_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Weekly handler: all tracked keywords (capped) × configured engines.
	 *
	 * @return void
	 */
	public function handle_weekly(): void {
		$cap = (int) ( $this->settings->all()['geo']['track_cap'] ?? 50 );
		$this->run( array(), array(), max( 1, $cap ) );
	}

	/**
	 * Engines available (key configured).
	 *
	 * @return array Engine ids.
	 */
	public function available_engines(): array {
		$factory    = new LLM_Factory( $this->settings );
		$configured = $factory->configured_providers();
		$out        = array();
		foreach ( self::ENGINES as $engine => $provider ) {
			if ( in_array( $provider, $configured, true ) ) {
				$out[] = $engine;
			}
		}
		return $out;
	}

	/**
	 * Run checks.
	 *
	 * @param array $keyword_ids Specific ids (empty = tracked keywords).
	 * @param array $engines     Engine subset (empty = all configured).
	 * @param int   $cap         Max keywords.
	 * @return array{checked:int,mentioned:int,cited:int,skipped:array}
	 */
	public function run( array $keyword_ids = array(), array $engines = array(), int $cap = self::RUN_CAP ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$engines = empty( $engines )
			? $this->available_engines()
			: array_values( array_intersect( $engines, $this->available_engines() ) );

		$stats = array(
			'checked'   => 0,
			'mentioned' => 0,
			'cited'     => 0,
			'skipped'   => array(),
		);

		if ( empty( $engines ) ) {
			$stats['skipped'][] = 'no engine configured';
			return $stats;
		}

		$keywords = $this->keywords( $keyword_ids, $cap );
		if ( empty( $keywords ) ) {
			$stats['skipped'][] = 'no tracked keywords';
			return $stats;
		}

		$factory = new LLM_Factory( $this->settings );
		$parser  = null;
		$brand   = (array) ( $this->settings->all()['brand'] ?? array() );
		$domain  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $keywords as $row ) {
			$prompt = $this->prompt_for( $row );
			foreach ( $engines as $engine ) {
				try {
					$client = $factory->create_for( self::ENGINES[ $engine ] );
					$answer = $client->complete(
						array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						array( 'max_tokens' => 1200 )
					);

					if ( null === $parser ) {
						$parser = new Answer_Parser( $factory->create() );
					}
					$parsed = $parser->parse( $answer['text'], $brand, $domain );

					$this->record( (int) $row['id'], $engine, $prompt, $answer['text'], $parsed );

					$stats['checked']++;
					if ( $parsed['mentioned'] ) {
						$stats['mentioned']++;
					}
					if ( $parsed['cited'] ) {
						$stats['cited']++;
					}
				} catch ( \Throwable $e ) {
					Logger::warning(
						'GEO check failed: ' . $e->getMessage(),
						'geo',
						array(
							'keyword' => $row['keyword'],
							'engine'  => $engine,
						)
					);
				}
			}
		}

		Logger::info( 'GEO prompt tracking completed', 'geo', $stats );
		return $stats;
	}

	/**
	 * Fixed prompt for a keyword, by intent (settings-overridable).
	 *
	 * @param array $row Keyword row (keyword, intent).
	 * @return string
	 */
	private function prompt_for( array $row ): string {
		$geo      = $this->settings->all()['geo'] ?? array();
		$template = 'commercial' === ( $row['intent'] ?? '' )
			? (string) ( $geo['prompt_commercial'] ?? '' )
			: (string) ( $geo['prompt_informational'] ?? '' );
		if ( '' === $template ) {
			$template = 'How to {keyword}? Please recommend sources.';
		}
		return strtr( $template, array( '{keyword}' => (string) $row['keyword'] ) );
	}

	/**
	 * Keywords to check.
	 *
	 * @param array $ids Explicit ids (empty = track_geo=1).
	 * @param int   $cap Cap.
	 * @return array
	 */
	private function keywords( array $ids, int $cap ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_keywords';
		if ( ! empty( $ids ) ) {
			$ids          = array_filter( array_map( 'intval', $ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, keyword, intent FROM {$table} WHERE id IN ({$placeholders}) LIMIT %d",
					...[ ...$ids, $cap ]
				),
				ARRAY_A
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, keyword, intent FROM {$table} WHERE track_geo = 1 ORDER BY id DESC LIMIT %d",
				$cap
			),
			ARRAY_A
		);
	}

	/**
	 * Persist one check row.
	 *
	 * @param int    $keyword_id Keyword id.
	 * @param string $engine     Engine id.
	 * @param string $prompt     Prompt used (fixed template text).
	 * @param string $answer     Raw answer (hashed only).
	 * @param array  $parsed     Parsed metrics.
	 * @return void
	 */
	private function record( int $keyword_id, string $engine, string $prompt, string $answer, array $parsed ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'scp_geo_checks',
			array(
				'keyword_id'           => $keyword_id,
				'checked_at'           => current_time( 'mysql' ),
				'engine'               => $engine,
				'prompt_text'          => $prompt,
				'mentioned'            => $parsed['mentioned'] ? 1 : 0,
				'cited'                => $parsed['cited'] ? 1 : 0,
				'mention_position'     => $parsed['position'],
				'sentiment'            => $parsed['sentiment'],
				'competitors_mentioned' => $parsed['competitors'] ? wp_json_encode( $parsed['competitors'] ) : null,
				'raw_answer_hash'      => hash( 'sha256', $answer ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Dashboard aggregates: per-engine rates over 28d + weekly trend.
	 *
	 * @return array
	 */
	public function summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scp_geo_checks';
		$since = gmdate( 'Y-m-d', strtotime( '-28 days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$engines = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT engine,
						COUNT(*) AS checks,
						SUM(mentioned) AS mentioned,
						SUM(cited) AS cited,
						AVG(mention_position) AS avg_position
				FROM {$table} WHERE checked_at >= %s
				GROUP BY engine",
				$since . ' 00:00:00'
			),
			ARRAY_A
		);

		$by_engine = array();
		foreach ( $engines as $row ) {
			$checks                 = max( 1, (int) $row['checks'] );
			$by_engine[ $row['engine'] ] = array(
				'checks'         => (int) $row['checks'],
				'mention_rate'   => round( (int) $row['mentioned'] / $checks, 3 ),
				'citation_rate'  => round( (int) $row['cited'] / $checks, 3 ),
				'avg_position'   => null === $row['avg_position'] ? null : round( (float) $row['avg_position'], 1 ),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$weekly = (array) $wpdb->get_results(
			"SELECT YEARWEEK(checked_at, 1) AS yw,
					engine,
					COUNT(*) AS checks,
					SUM(mentioned) AS mentioned,
					SUM(cited) AS cited
			FROM {$table}
			GROUP BY yw, engine
			ORDER BY yw DESC
			LIMIT 36",
			ARRAY_A
		);

		return array(
			'engines' => $by_engine,
			'weekly'  => $weekly,
		);
	}
}
