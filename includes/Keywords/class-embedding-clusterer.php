<?php
/**
 * Greedy cosine-similarity clustering over OpenAI embeddings.
 *
 * @package SCP
 */

namespace SCP\Keywords;

/**
 * Items with cosine similarity >= threshold join the same cluster.
 */
class Embedding_Clusterer implements Clusterer {

	/**
	 * Similarity threshold from the engineering spec.
	 */
	const THRESHOLD = 0.82;

	/**
	 * {@inheritDoc}
	 *
	 * Greedy pass: iterate in id order, assign to the first centroid with
	 * similarity above the threshold, otherwise become a new centroid.
	 * The centroid (primary) is therefore always the lowest id in a cluster.
	 */
	public function cluster( array $items ): array {
		$assignments = array();
		$centroids   = array(); // primary_id => vector.

		foreach ( $items as $id => $vector ) {
			$best_id    = null;
			$best_score = 0.0;

			foreach ( $centroids as $primary_id => $centroid ) {
				$sim = $this->cosine( $vector, $centroid );
				if ( $sim > $best_score ) {
					$best_score = $sim;
					$best_id    = $primary_id;
				}
			}

			if ( null !== $best_id && $best_score >= self::THRESHOLD ) {
				$assignments[ $id ] = $best_id;
			} else {
				$centroids[ $id ]   = $vector;
				$assignments[ $id ] = $id;
			}
		}

		return $assignments;
	}

	/**
	 * Cosine similarity between two vectors.
	 *
	 * @param array $a Vector A.
	 * @param array $b Vector B.
	 * @return float
	 */
	private function cosine( array $a, array $b ): float {
		$dot  = 0.0;
		$norm = 0.0;
		foreach ( $a as $i => $av ) {
			$bv   = $b[ $i ] ?? 0.0;
			$dot += $av * $bv;
			$norm += $bv * $bv;
		}
		$norm_a = sqrt( array_sum( array_map( fn( $x ) => $x * $x, $a ) ) );
		$norm_b = sqrt( $norm );
		if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
			return 0.0;
		}
		return $dot / ( $norm_a * $norm_b );
	}
}
