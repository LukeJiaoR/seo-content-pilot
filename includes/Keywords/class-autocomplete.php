<?php
/**
 * Google Autocomplete source (free, no key).
 *
 * @package SCP
 */

namespace SCP\Keywords;

use SCP\Logger;

/**
 * Polls suggestqueries.google.com with suffix/prefix permutations.
 */
class Autocomplete {

	const ENDPOINT = 'https://suggestqueries.google.com/complete/search';

	/**
	 * Question / modifier prefixes polled as "{prefix} {seed}".
	 */
	const PREFIXES = array(
		'how', 'how to', 'what', 'what is', 'why', 'when', 'where',
		'is', 'can', 'best', 'top', 'are',
	);

	/**
	 * Suffixes polled as "{seed} {suffix}".
	 */
	const SUFFIXES = array(
		'vs', 'for', 'with', 'without', 'near me', 'alternative',
		'review', 'cost', 'price', 'software', 'tools', 'services',
	);

	/**
	 * Expand one seed: seed + [a-z, 0-9] plus prefix/suffix templates.
	 *
	 * @param string $seed Seed keyword.
	 * @return array Unique suggestion strings (lowercased, unfiltered).
	 */
	public function expand_seed( string $seed ): array {
		$queries = array();

		foreach ( range( 'a', 'z' ) as $ch ) {
			$queries[] = $seed . ' ' . $ch;
		}
		foreach ( range( 0, 9 ) as $n ) {
			$queries[] = $seed . ' ' . $n;
		}
		foreach ( self::PREFIXES as $prefix ) {
			$queries[] = $prefix . ' ' . $seed;
		}
		foreach ( self::SUFFIXES as $suffix ) {
			$queries[] = $seed . ' ' . $suffix;
		}

		$out = array();
		foreach ( $queries as $q ) {
			try {
				foreach ( $this->fetch( $q ) as $suggestion ) {
					$out[] = $suggestion;
				}
			} catch ( \Throwable $e ) {
				Logger::warning( 'Autocomplete query failed: ' . $e->getMessage(), 'autocomplete', array( 'q' => $q ) );
			}
			usleep( 150000 ); // Be polite: ~6 req/s.
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Fetch suggestions for one query.
	 *
	 * @param string $query Query.
	 * @return array Suggestion strings.
	 * @throws \RuntimeException On HTTP/parse failure.
	 */
	public function fetch( string $query ): array {
		$url = add_query_arg(
			array(
				'client' => 'firefox',
				'hl'     => 'en',
				'q'      => $query,
			),
			self::ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Mozilla/5.0 (compatible; SEOContentPilot/0.1)',
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded[1] ) || ! is_array( $decoded[1] ) ) {
			return array();
		}

		return array_map( 'strval', $decoded[1] );
	}
}
