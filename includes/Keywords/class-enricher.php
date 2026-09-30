<?php
/**
 * Keyword enrichment pass: metrics -> embeddings -> cluster -> intent -> score.
 *
 * Runs ONLY on explicit user action (selected ids), per the cost-control
 * rule in engineering §6.2: never pay metrics/embedding costs for the
 * hundreds of raw suggestions that will never be used.
 *
 * @package SCP
 */

namespace SCP\Keywords;

use SCP\LLM\LLM_Factory;
use SCP\LLM\OpenAI_Client;
use SCP\Logger;
use SCP\Settings;

/**
 * Enriches selected keyword rows with paid data and derived fields.
 */
class Enricher {

	const METRICS_BATCH    = 100;
	const EMBEDDING_BATCH  = 500;
	const INTENT_BATCH     = 50;

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
	 * Run the full enrichment pipeline for selected keyword ids.
	 *
	 * @param array $ids Keyword ids (max ~200 recommended).
	 * @return array Summary of what happened.
	 */
	public function enrich( array $ids ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$summary = array(
			'rows'       => 0,
			'metrics'    => 0,
			'embeddings' => 0,
			'clustered'  => 0,
			'intents'    => 0,
			'scored'     => 0,
			'skipped'    => array(),
		);

		$rows = $this->load( $ids );
		$summary['rows'] = count( $rows );
		if ( empty( $rows ) ) {
			return $summary;
		}

		$dfs = new DataForSEO( $this->settings );
		if ( $dfs->is_configured() ) {
			$summary['metrics'] = $this->fill_metrics( $rows, $dfs );
		} else {
			$summary['skipped'][] = 'metrics (DataForSEO not configured)';
		}

		$llm = null;
		try {
			$llm = ( new LLM_Factory( $this->settings ) )->create();
		} catch ( \Throwable ) {
			$summary['skipped'][] = 'embeddings + intents (LLM not configured)';
		}

		if ( $llm instanceof OpenAI_Client ) {
			$summary['embeddings'] = $this->fill_embeddings( $rows, $llm );
			$summary['clustered']  = $this->cluster( $rows );
		} elseif ( $llm ) {
			$summary['skipped'][] = 'embeddings (provider has no embedding endpoint)';
		}

		if ( $llm ) {
			$summary['intents'] = $this->fill_intents( $rows, new Intent_Classifier( $llm ) );
		}

		$summary['scored'] = $this->rescore( $ids );

		Logger::info( 'Keywords enriched', 'enricher', $summary );
		return $summary;
	}

	/**
	 * Backfill volume/kd/cpc from DataForSEO for rows missing volume.
	 *
	 * @param array      $rows Keyword rows.
	 * @param DataForSEO $dfs  Client.
	 * @return int Rows updated.
	 */
	private function fill_metrics( array $rows, DataForSEO $dfs ): int {
		$pending = array_filter( $rows, fn( $r ) => null === $r['volume'] );
		if ( empty( $pending ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( array_chunk( $pending, self::METRICS_BATCH, true ) as $chunk ) {
			$keywords = array_column( $chunk, 'keyword' );
			try {
				$volumes = $dfs->search_volume( $keywords );
				$kds     = $dfs->keyword_difficulty( $keywords );
			} catch ( \Throwable $e ) {
				Logger::warning( 'Metrics backfill failed: ' . $e->getMessage(), 'enricher' );
				continue;
			}

			foreach ( $chunk as $id => $row ) {
				$kw     = $row['keyword'];
				$volume = $volumes[ $kw ]['volume'] ?? null;
				$cpc    = $volumes[ $kw ]['cpc'] ?? null;
				$kd     = $kds[ $kw ] ?? null;
				$this->update(
					$id,
					array(
						'volume' => $volume,
						'cpc'    => $cpc,
						'kd'     => $kd,
					)
				);
				$updated++;
			}
		}
		return $updated;
	}

	/**
	 * Compute embeddings for rows missing them; cache in the embedding column.
	 *
	 * @param array         $rows Keyword rows.
	 * @param OpenAI_Client $llm  OpenAI client.
	 * @return int Embeddings computed.
	 */
	private function fill_embeddings( array $rows, OpenAI_Client $llm ): int {
		$pending = array_filter( $rows, fn( $r ) => empty( $r['embedding'] ) );
		if ( empty( $pending ) ) {
			return 0;
		}

		$computed = 0;
		foreach ( array_chunk( $pending, self::EMBEDDING_BATCH, true ) as $chunk ) {
			$keywords = array_column( $chunk, 'keyword' );
			try {
				$vectors = $llm->embed( $keywords );
			} catch ( \Throwable $e ) {
				Logger::warning( 'Embedding failed: ' . $e->getMessage(), 'enricher' );
				continue;
			}
			$ids = array_keys( $chunk );
			foreach ( $vectors as $i => $vector ) {
				if ( isset( $ids[ $i ] ) ) {
					$this->update( $ids[ $i ], array( 'embedding' => wp_json_encode( $vector ) ) );
					$computed++;
				}
			}
		}
		return $computed;
	}

	/**
	 * Cluster rows that have embeddings. New assignments only touch the
	 * selected rows; cluster_id = primary (lowest-id) row id.
	 *
	 * @param array $rows Keyword rows (stale embedding cache re-read).
	 * @return int Rows assigned to a cluster.
	 */
	private function cluster( array $rows ): int {
		$fresh  = $this->load( array_keys( $rows ) );
		$items  = array();
		foreach ( $fresh as $id => $row ) {
			if ( ! empty( $row['embedding'] ) ) {
				$vector = json_decode( (string) $row['embedding'], true );
				if ( is_array( $vector ) ) {
					$items[ $id ] = $vector;
				}
			}
		}
		if ( count( $items ) < 2 ) {
			return 0;
		}

		$assignments = ( new Embedding_Clusterer() )->cluster( $items );
		foreach ( $assignments as $id => $primary_id ) {
			$this->update( (int) $id, array( 'cluster_id' => (int) $primary_id ) );
		}
		return count( $assignments );
	}

	/**
	 * Classify intent for rows missing it (LLM, batches of 50).
	 *
	 * @param array             $rows       Keyword rows.
	 * @param Intent_Classifier $classifier Classifier.
	 * @return int Rows classified.
	 */
	private function fill_intents( array $rows, Intent_Classifier $classifier ): int {
		$pending = array_filter( $rows, fn( $r ) => empty( $r['intent'] ) );
		if ( empty( $pending ) ) {
			return 0;
		}

		$done = 0;
		foreach ( array_chunk( $pending, self::INTENT_BATCH, true ) as $chunk ) {
			try {
				$map = $classifier->classify( array_column( $chunk, 'keyword' ) );
			} catch ( \Throwable $e ) {
				Logger::warning( 'Intent classification failed: ' . $e->getMessage(), 'enricher' );
				continue;
			}
			foreach ( $chunk as $id => $row ) {
				if ( isset( $map[ $row['keyword'] ] ) ) {
					$this->update( $id, array( 'intent' => $map[ $row['keyword'] ] ) );
					$done++;
				}
			}
		}
		return $done;
	}

	/**
	 * Recompute score for all selected rows (GSC-ranked boost included).
	 *
	 * @param array $ids Keyword ids.
	 * @return int Rows scored.
	 */
	private function rescore( array $ids ): int {
		$rows  = $this->load( $ids );
		$gsc   = Scorer::gsc_ranked_queries();
		$count = 0;
		foreach ( $rows as $id => $row ) {
			$score = Scorer::compute(
				null === $row['volume'] ? null : (int) $row['volume'],
				null === $row['kd'] ? null : (float) $row['kd'],
				isset( $gsc[ $row['keyword'] ] )
			);
			if ( null !== $score ) {
				$this->update( (int) $id, array( 'score' => $score ) );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Load rows by id.
	 *
	 * @param array $ids Keyword ids.
	 * @return array id => row assoc.
	 */
	private function load( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table        = $wpdb->prefix . 'scp_keywords';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, keyword, volume, kd, cpc, intent, embedding, cluster_id FROM {$table} WHERE id IN ({$placeholders})",
				...$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Update one row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value.
	 * @return void
	 */
	private function update( int $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'scp_keywords', $data, array( 'id' => $id ) );
	}
}
