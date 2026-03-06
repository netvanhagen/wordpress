<?php
/**
 * Helper Utilities for Advanced Relevance Search
 */

namespace ARS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Helpers {

	/**
	 * Get current language for WPML or default
	 *
	 * @return string Language code
	 */
	public static function get_current_lang() {
		if ( has_filter( 'wpml_current_language' ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
			if ( is_string( $lang ) && $lang !== '' ) {
				return $lang;
			}
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return ICL_LANGUAGE_CODE;
		}
		return 'all';
	}

	/**
	 * Normalize search term for consistency
	 *
	 * @param string $term
	 * @return string Normalized term
	 */
	public static function normalize_search_term( $term ) {
		$term = (string) $term;
		$term = wp_unslash( $term );
		$term = trim( $term );
		$term = strtolower( $term );
		$term = preg_replace( '/\s+/', ' ', $term );
		return $term;
	}

	/**
	 * Check if post should be indexed
	 *
	 * @param int $post_id
	 * @return boolean
	 */
	public static function should_index_post( $post_id ) {
		$post = get_post( $post_id );

		// Get settings
		$settings = Settings::get_all();
		$post_types = isset( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		// Check post exists and is published
		if ( ! $post || ! in_array( $post->post_type, $post_types, true ) || $post->post_status !== 'publish' ) {
			return false;
		}

		// Check if excluded via meta
		$exclude = get_post_meta( $post_id, '_ars_exclude_index', true );
		if ( $exclude === '1' ) {
			return false;
		}

		// Check if excluded via category
		$exclude_cats_str = isset( $settings['exclude_categories'] ) ? $settings['exclude_categories'] : '';
		if ( ! empty( $exclude_cats_str ) ) {
			$exclude_cats = array_map( 'trim', explode( ',', $exclude_cats_str ) );
			if ( has_category( $exclude_cats, $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get WPML language for a post
	 *
	 * @param int $post_id
	 * @return string Language code
	 */
	public static function get_post_language( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'all';
		}

		if ( function_exists( 'wpml_get_language_information' ) ) {
			$lang_info = wpml_get_language_information( $post->ID );
			if ( ! is_wp_error( $lang_info ) ) {
				return $lang_info['language_code'];
			}
		}

		return 'all';
	}

	/**
	 * Extract words from search term based on min length and stop words
	 *
	 * @param string $term
	 * @return array Clean words
	 */
	public static function extract_search_words( $term ) {
		$settings = Settings::get_all();
		$min_len = isset( $settings['min_word_length'] ) ? (int) $settings['min_word_length'] : 3;
		$stop_words = array_map( 'trim', explode( ',', strtolower( isset( $settings['stop_words'] ) ? $settings['stop_words'] : '' ) ) );

		$normalized = self::normalize_search_term( $term );
		$raw_words = preg_split( '/\s+/', $normalized );
		$search_words = array();

		foreach ( $raw_words as $rw ) {
			$rw = trim( (string) $rw );
			if ( $rw === '' ) {
				continue;
			}

			if ( ( strlen( $rw ) >= $min_len && ! in_array( $rw, $stop_words, true ) ) || ( count( $raw_words ) === 1 && strlen( $rw ) > 0 ) ) {
				$search_words[] = $rw;
			}
		}

		if ( empty( $search_words ) && $normalized !== '' ) {
			$search_words = array( $normalized );
		}

		return array_values( array_unique( array_filter( $search_words ) ) );
	}
}
