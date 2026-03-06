<?php
/**
 * Post Indexer for Advanced Relevance Search
 */

namespace ARS\Search;

use ARS\Core\Database;
use ARS\Core\Helpers;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Indexer {

	/**
	 * Index a single post
	 *
	 * @param int $post_id
	 */
	public static function index_post( $post_id ) {
		// Validate post should be indexed
		if ( ! Helpers::should_index_post( $post_id ) ) {
			self::remove_post( $post_id );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Get language
		$lang = Helpers::get_post_language( $post->ID );

		// Get categories and tags
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );

		// Extract internal keywords from post meta (if any custom field)
		$internal_keywords = self::extract_internal_keywords( $post->ID );

		// Remove old index entry
		Database::delete_from_index( $post->ID );

		// Insert new entry
		Database::insert_index( array(
			'post_id' => $post->ID,
			'post_type' => $post->post_type,
			'lang' => $lang,
			'title' => sanitize_text_field( strip_tags( $post->post_title ) ),
			'excerpt' => sanitize_text_field( strip_tags( $post->post_excerpt ) ),
			'content_tokens' => strip_tags( $post->post_content ),
			'category_ids' => implode( ',', $categories ),
			'tag_ids' => implode( ',', $tags ),
			'internal_keywords' => $internal_keywords,
			'updated_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Remove post from index
	 *
	 * @param int $post_id
	 */
	public static function remove_post( $post_id ) {
		Database::delete_from_index( $post_id );
	}

	/**
	 * Extract internal keywords from post meta
	 * Customizable for different post types/keywords
	 *
	 * @param int $post_id
	 * @return string Keywords string
	 */
	private static function extract_internal_keywords( $post_id ) {
		// Check for custom meta field '_ars_keywords'
		$keywords = get_post_meta( $post_id, '_ars_keywords', true );
		if ( ! empty( $keywords ) ) {
			return (string) $keywords;
		}

		// Could extend this to extract from other sources
		return '';
	}

	/**
	 * Reindex all posts in batch
	 *
	 * @param int $offset
	 * @param int $batch_size
	 * @return array ['imported' => int, 'total' => int, 'done' => bool]
	 */
	public static function index_batch( $offset = 0, $batch_size = 50 ) {
		$post_types = Settings::get_post_types();
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		// Get total count
		$count_query = new \WP_Query( array(
			'post_type' => $post_types,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		) );
		$total = (int) $count_query->found_posts;

		// Get batch
		$posts = get_posts( array(
			'post_type' => $post_types,
			'post_status' => 'publish',
			'posts_per_page' => $batch_size,
			'offset' => $offset,
			'orderby' => 'ID',
			'order' => 'ASC',
			'fields' => 'ids',
		) );

		if ( empty( $posts ) ) {
			// Batch complete
			update_option( 'ars_last_index_date', current_time( 'mysql' ) );
			update_option( 'ars_last_index_count', $total );
			return array(
				'imported' => 0,
				'total' => $total,
				'done' => true,
			);
		}

		// Index batch
		foreach ( $posts as $post_id ) {
			self::index_post( $post_id );
		}

		return array(
			'imported' => count( $posts ),
			'total' => $total,
			'done' => false,
		);
	}

	/**
	 * Clear and reindex everything
	 *
	 * @return int Total posts indexed
	 */
	public static function full_reindex() {
		Database::truncate_index();
		update_option( 'ars_last_index_count', 0 );
		update_option( 'ars_last_index_date', 'Nog niet uitgevoerd' );

		$post_types = Settings::get_post_types();
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$posts = get_posts( array(
			'post_type' => $post_types,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'fields' => 'ids',
		) );

		foreach ( $posts as $post_id ) {
			self::index_post( $post_id );
		}

		update_option( 'ars_last_index_date', current_time( 'mysql' ) );
		update_option( 'ars_last_index_count', count( $posts ) );

		return count( $posts );
	}
}
