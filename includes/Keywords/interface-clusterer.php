<?php
/**
 * Clusterer contract (v1: embedding similarity; v2 candidate: SERP overlap).
 *
 * @package SCP
 */

namespace SCP\Keywords;

/**
 * Groups keywords into clusters to prevent keyword cannibalization.
 */
interface Clusterer {

	/**
	 * Cluster items by similarity.
	 *
	 * @param array $items Map of id => embedding vector (array of floats).
	 * @return array Map of id => cluster primary id (the representative row id).
	 */
	public function cluster( array $items ): array;
}
