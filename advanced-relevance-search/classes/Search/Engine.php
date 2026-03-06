<?php
/**
 * Search Engine for Advanced Relevance Search
 */

namespace ARS\Search;

use ARS\Core\Database;
use ARS\Core\Helpers;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engine {

	/**
	 * Perform search with custom ranking algorithm
	 *
	 * @param string $search_term
	 * @return array Ranked post IDs
	 */
	public static function search( $search_term ) {
		$search_term = trim( (string) $search_term );
		if ( empty( $search_term ) ) {
			return array();
		}

		$current_lang = Helpers::get_current_lang();
		$normalized_term = Helpers::normalize_search_term( $search_term );

		// Extract search words
		$search_words = Helpers::extract_search_words( $search_term );
		if ( empty( $search_words ) ) {
			return array();
		}

		// Expand with synonyms
		$search_words = self::expand_with_synonyms( $search_words, $current_lang );

		// Get regular results from index
		$regular_ids = Database::search_index( $search_words, $current_lang );

		// Get  pinned results
		$pinned_result = Database::get_pinned_results( $normalized_term, $current_lang );

		// Merge pinned into results
		$final_order = self::merge_pinned_results( $regular_ids, $pinned_result );

		return $final_order;
	}

	/**
	 * Expand search words with synonyms
	 *
	 * @param array $words
	 * @param string $lang
	 * @return array Expanded word list
	 */
	private static function expand_with_synonyms( $words, $lang ) {
		$synonyms_text = Settings::get_synonyms( $lang );
		if ( empty( $synonyms_text ) ) {
			return array_values( array_unique( array_filter( $words ) ) );
		}

		$expanded_words = $words;
		$lines = preg_split( '/\r\n|\r|\n/', $synonyms_text );

		foreach ( $lines as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}

			$key = Helpers::normalize_search_term( $parts[0] );
			$values_raw = explode( ',', (string) $parts[1] );
			$values = array();
			foreach ( $values_raw as $val ) {
				$values[] = Helpers::normalize_search_term( $val );
			}
			$values = array_filter( array_map( 'trim', $values ) );

			// If keyword is searched, add its synonyms
			if ( $key !== '' && in_array( $key, $expanded_words, true ) ) {
				$expanded_words = array_merge( $expanded_words, $values );
			}

			// If synonym is searched, add the keyword
			foreach ( $values as $val ) {
				if ( $val !== '' && in_array( $val, $expanded_words, true ) ) {
					$expanded_words[] = $key;
				}
			}
		}

		return array_values( array_unique( array_filter( $expanded_words ) ) );
	}

	/**
	 * Merge pinned results into regular results at specified positions
	 *
	 * @param array $regular_ids
	 * @param array $pinned_result Array of ['post_id' => int, 'position' => int]
	 * @return array Final ranked post IDs
	 */
	private static function merge_pinned_results( $regular_ids, $pinned_result ) {
		if ( empty( $pinned_result ) ) {
			return $regular_ids;
		}

		// Remove pinned IDs from regular results
		$pinned_ids = wp_list_pluck( $pinned_result, 'post_id' );
		$regular_ids = array_values( array_diff( $regular_ids, $pinned_ids ) );

		// Insert pinned at specified positions
		foreach ( $pinned_result as $pinned ) {
			$post_id = (int) $pinned['post_id'];
			$position = (int) $pinned['position'];
			$index = $position - 1;

			if ( $index < 0 ) {
				$index = 0;
			}
			if ( $index > count( $regular_ids ) ) {
				$index = count( $regular_ids );
			}

			array_splice( $regular_ids, $index, 0, array( $post_id ) );
		}

		return $regular_ids;
	}
}
