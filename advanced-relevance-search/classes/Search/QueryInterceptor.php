<?php
/**
 * Query Interceptor for Advanced Relevance Search
 */

namespace ARS\Search;

use ARS\Core\Database;
use ARS\Core\Helpers;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueryInterceptor {

	/**
	 * Register hooks for query interception
	 */
	public static function register_hooks() {
		add_action( 'pre_get_posts', array( __CLASS__, 'intercept_search_query' ), 10 );
		add_filter( 'posts_search', array( __CLASS__, 'disable_native_search_sql' ), 20, 2 );
		add_filter( 'posts_orderby', array( __CLASS__, 'force_post_in_orderby' ), 999, 2 );
		add_filter( 'the_posts', array( __CLASS__, 'log_search_results' ), 999, 2 );
	}

	/**
	 * Intercept search query and replace with custom search
	 *
	 * @param \WP_Query $query
	 */
	public static function intercept_search_query( $query ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! ( $query instanceof \WP_Query ) ) {
			return;
		}

		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		// Skip Divi builder queries
		if ( $query->get( 'et_is_theme_builder_query' ) || $query->get( 'et_is_builder_preview' ) ) {
			return;
		}

		$search_term = $query->get( 's' );
		if ( ! is_string( $search_term ) || trim( $search_term ) === '' ) {
			return;
		}

		// Perform custom search
		$post_ids = Engine::search( $search_term );
		$post_ids = is_array( $post_ids ) ? array_values( array_filter( array_map( 'intval', $post_ids ) ) ) : array();

		// Set custom search flag
		$query->set( 'ars_use_custom_search', true );
		$query->set( 'ars_search_term_raw', $search_term );

		// Set results per page if not already set
		$per_page = Settings::get( 'results_per_page', 10 );
		if ( $per_page > 0 && ! $query->get( 'posts_per_page' ) ) {
			$query->set( 'posts_per_page', $per_page );
		}

		// Set post IDs
		if ( ! empty( $post_ids ) ) {
			$query->set( 'post__in', $post_ids );
			$query->set( 'orderby', 'post__in' );
		} else {
			// No results
			$query->set( 'post__in', array( 0 ) );
			$query->set( 'orderby', 'post__in' );
		}

		$query->set( 'ignore_sticky_posts', true );
	}

	/**
	 * Disable native WordPress search SQL when using custom search
	 *
	 * @param string $search
	 * @param \WP_Query $query
	 * @return string
	 */
	public static function disable_native_search_sql( $search, $query ) {
		if ( is_admin() ) {
			return $search;
		}

		if ( ! ( $query instanceof \WP_Query ) ) {
			return $search;
		}

		if ( ! $query->get( 'ars_use_custom_search' ) ) {
			return $search;
		}

		return '';
	}

	/**
	 * Force post__in ordering
	 *
	 * @param string $orderby
	 * @param \WP_Query $query
	 * @return string
	 */
	public static function force_post_in_orderby( $orderby, $query ) {
		if ( is_admin() ) {
			return $orderby;
		}

		if ( ! ( $query instanceof \WP_Query ) ) {
			return $orderby;
		}

		if ( ! $query->get( 'ars_use_custom_search' ) ) {
			return $orderby;
		}

		$ids = $query->get( 'post__in' );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return $orderby;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return $orderby;
		}

		global $wpdb;
		return "FIELD({$wpdb->posts}.ID," . implode( ',', $ids ) . ')';
	}

	/**
	 * Log search results (first page only)
	 *
	 * @param array $posts
	 * @param \WP_Query $query
	 * @return array
	 */
	public static function log_search_results( $posts, $query ) {
		if ( is_admin() ) {
			return $posts;
		}

		if ( ! ( $query instanceof \WP_Query ) ) {
			return $posts;
		}

		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return $posts;
		}

		if ( ! $query->get( 'ars_use_custom_search' ) ) {
			return $posts;
		}

		// Only log first page
		$paged = (int) max( 1, (int) $query->get( 'paged' ) );
		if ( $paged > 1 ) {
			return $posts;
		}

		// Check if logging enabled
		if ( ! Settings::is_enabled( 'logging_enabled' ) ) {
			return $posts;
		}

		static $did_log = false;
		if ( $did_log ) {
			return $posts;
		}
		$did_log = true;

		// Get search term
		$term = (string) $query->get( 'ars_search_term_raw' );
		if ( trim( $term ) === '' ) {
			return $posts;
		}

		// Extract shown post IDs
		$shown_ids = array();
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				if ( isset( $post->ID ) ) {
					$shown_ids[] = (int) $post->ID;
				}
			}
		}

		// Log search
		$lang = Helpers::get_current_lang();
		$result_count = is_array( $posts ) ? count( $posts ) : 0;

		Database::log_search( $term, $result_count, $shown_ids, $lang );

		return $posts;
	}
}
