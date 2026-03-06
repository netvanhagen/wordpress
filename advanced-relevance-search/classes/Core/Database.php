<?php
/**
 * Database Management for Advanced Relevance Search
 */

namespace ARS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	// Table name constants
	const TABLE_INDEX = 'ars_index';
	const TABLE_PINNED = 'ars_pinned';
	const TABLE_LOGS = 'ars_logs';

	/**
	 * Get table name with prefix
	 *
	 * @param string $table Table constant
	 * @return string Full table name
	 */
	private static function get_table( $table ) {
		global $wpdb;
		$table_map = array(
			self::TABLE_INDEX => $wpdb->prefix . self::TABLE_INDEX,
			self::TABLE_PINNED => $wpdb->prefix . self::TABLE_PINNED,
			self::TABLE_LOGS => $wpdb->prefix . self::TABLE_LOGS,
		);
		return isset( $table_map[ $table ] ) ? $table_map[ $table ] : '';
	}

	/**
	 * Create all database tables
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Index Tabel
		$table_index = self::get_table( self::TABLE_INDEX );
		$sql_index = "CREATE TABLE $table_index (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL,
			post_type varchar(20) NOT NULL,
			lang varchar(10) DEFAULT 'all' NOT NULL,
			title text NOT NULL,
			excerpt text,
			content_tokens longtext NOT NULL,
			category_ids varchar(255),
			tag_ids varchar(255),
			internal_keywords text,
			score_multiplier float DEFAULT 1.0,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY lang (lang),
			KEY post_type (post_type)
		) $charset_collate;";

		// 2. Pinned Tabel
		$table_pinned = self::get_table( self::TABLE_PINNED );
		$sql_pinned = "CREATE TABLE $table_pinned (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			lang varchar(10) DEFAULT 'all' NOT NULL,
			search_term varchar(255) NOT NULL,
			post_id bigint(20) NOT NULL,
			position int(1) NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY search_term (search_term),
			KEY lang (lang)
		) $charset_collate;";

		// 3. Logs Tabel
		$table_logs = self::get_table( self::TABLE_LOGS );
		$sql_logs = "CREATE TABLE $table_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			lang varchar(10) DEFAULT 'all' NOT NULL,
			search_term varchar(255) NOT NULL,
			log_date datetime DEFAULT CURRENT_TIMESTAMP,
			result_count int NOT NULL DEFAULT 0,
			result_ids text,
			PRIMARY KEY  (id),
			KEY search_term (search_term),
			KEY lang (lang),
			KEY log_date (log_date)
		) $charset_collate;";

		dbDelta( $sql_index );
		dbDelta( $sql_pinned );
		dbDelta( $sql_logs );
	}

	/**
	 * Insert post into index
	 *
	 * @param array $data Data to insert
	 * @return int|false Insert ID or false
	 */
	public static function insert_index( $data ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );
		return $wpdb->insert( $table, $data );
	}

	/**
	 * Delete post from index
	 *
	 * @param int $post_id
	 * @return int|false Number of deleted rows
	 */
	public static function delete_from_index( $post_id ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );
		return $wpdb->delete( $table, array( 'post_id' => $post_id ) );
	}

	/**
	 * Get index entry by post ID
	 *
	 * @param int $post_id
	 * @return object|null
	 */
	public static function get_index_entry( $post_id ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Search index with rankings
	 *
	 * @param array $words Words to search
	 * @param string $lang Language
	 * @return array Post IDs ranked by score
	 */
	public static function search_index( $words, $lang = 'all' ) {
		if ( empty( $words ) ) {
			return array();
		}

		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );
		$weights = Settings::get_weights();
		$bonuses = Settings::get_bonuses();
		$mults = Settings::get_multipliers();

		// Build score calculation SQL
		$score_parts = array();
		foreach ( $words as $word ) {
			$like = '%' . $wpdb->esc_like( $word ) . '%';
			$score_parts[] = $wpdb->prepare(
				"(CASE WHEN title LIKE %s THEN %d ELSE 0 END +
				  CASE WHEN content_tokens LIKE %s THEN %d ELSE 0 END +
				  CASE WHEN excerpt LIKE %s THEN %d ELSE 0 END +
				  CASE WHEN category_ids LIKE %s THEN %d ELSE 0 END +
				  CASE WHEN tag_ids LIKE %s THEN %d ELSE 0 END +
				  CASE WHEN internal_keywords LIKE %s THEN %d ELSE 0 END)",
				$like, $weights['title'],
				$like, $weights['content'],
				$like, $weights['excerpt'],
				$like, $weights['category'],
				$like, $weights['tag'],
				$like, $weights['internal']
			);
		}

		$final_score_sql = implode( ' + ', $score_parts );
		$mult_sql = "CASE WHEN post_type = 'post' THEN {$mults['post']} WHEN post_type = 'page' THEN {$mults['page']} ELSE 1.0 END";

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, (($final_score_sql) * $mult_sql) as score
			 FROM $table
			 WHERE (lang = %s OR lang = 'all')
			 HAVING score > 0
			 ORDER BY score DESC
			 LIMIT 200",
			$lang
		) );

		return array_map( 'intval', wp_list_pluck( $results, 'post_id' ) );
	}

	/**
	 * Get pinned results for search term
	 *
	 * @param string $term Normalized term
	 * @param string $lang Language
	 * @return array Post IDs with positions
	 */
	public static function get_pinned_results( $term, $lang = 'all' ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_PINNED );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, position FROM $table
			 WHERE (lang = %s OR lang = 'all')
			 AND LOWER(TRIM(search_term)) = %s
			 ORDER BY position ASC, id ASC
			 LIMIT 3",
			$lang,
			$term
		) );

		$result = array();
		foreach ( (array) $rows as $row ) {
			$pid = isset( $row->post_id ) ? (int) $row->post_id : 0;
			$pos = isset( $row->position ) ? (int) $row->position : 1;
			if ( $pos < 1 ) {
				$pos = 1;
			}
			if ( $pos > 3 ) {
				$pos = 3;
			}
			if ( $pid > 0 ) {
				$result[] = array(
					'post_id' => $pid,
					'position' => $pos,
				);
			}
		}

		return $result;
	}

	/**
	 * Add pinned result
	 *
	 * @param string $term
	 * @param int $post_id
	 * @param int $position
	 * @param string $lang
	 * @return bool
	 */
	public static function add_pinned( $term, $post_id, $position, $lang = 'all' ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_PINNED );

		// Remove existing at this position
		$wpdb->delete( $table, array(
			'lang' => $lang,
			'search_term' => $term,
			'position' => $position,
		) );

		return $wpdb->insert( $table, array(
			'lang' => $lang,
			'search_term' => sanitize_text_field( $term ),
			'post_id' => (int) $post_id,
			'position' => (int) $position,
			'updated_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Remove pinned result
	 *
	 * @param int $id Pinned ID
	 * @return int|false
	 */
	public static function delete_pinned( $id ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_PINNED );
		return $wpdb->delete( $table, array( 'id' => intval( $id ) ) );
	}

	/**
	 * Insert search log
	 *
	 * @param string $term
	 * @param int $result_count
	 * @param array $result_ids
	 * @param string $lang
	 * @return int|false
	 */
	public static function log_search( $term, $result_count, $result_ids, $lang = 'all' ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_LOGS );

		return $wpdb->insert( $table, array(
			'lang' => $lang,
			'search_term' => sanitize_text_field( $term ),
			'log_date' => current_time( 'mysql' ),
			'result_count' => (int) $result_count,
			'result_ids' => ! empty( $result_ids ) ? wp_json_encode( $result_ids ) : '',
		) );
	}

	/**
	 * Get search logs
	 *
	 * @param string $lang
	 * @param int $limit
	 * @return array
	 */
	public static function get_top_searches( $lang = 'all', $limit = 20 ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_LOGS );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT search_term, COUNT(*) as c FROM $table
			 WHERE lang = %s
			 GROUP BY search_term
			 ORDER BY c DESC
			 LIMIT %d",
			$lang,
			$limit
		) );
	}

	/**
	 * Get zero-result searches
	 *
	 * @param string $lang
	 * @param int $limit
	 * @return array
	 */
	public static function get_zero_result_searches( $lang = 'all', $limit = 20 ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_LOGS );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT search_term, COUNT(*) as c FROM $table
			 WHERE lang = %s AND result_count = 0
			 GROUP BY search_term
			 ORDER BY c DESC
			 LIMIT %d",
			$lang,
			$limit
		) );
	}

	/**
	 * Truncate index table
	 */
	public static function truncate_index() {
		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );
		$wpdb->query( "TRUNCATE TABLE $table" );
	}

	/**
	 * Truncate logs table
	 */
	public static function truncate_logs() {
		global $wpdb;
		$table = self::get_table( self::TABLE_LOGS );
		$wpdb->query( "TRUNCATE TABLE $table" );
	}

	/**
	 * Prune old logs
	 *
	 * @param int $days Older than X days
	 */
	public static function prune_logs( $days = 30 ) {
		global $wpdb;
		$table = self::get_table( self::TABLE_LOGS );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE log_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}

	/**
	 * Get index statistics
	 *
	 * @return array
	 */
	public static function get_index_stats() {
		global $wpdb;
		$table = self::get_table( self::TABLE_INDEX );

		return array(
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
			'by_type' => $wpdb->get_results( "SELECT post_type, COUNT(*) as count FROM $table GROUP BY post_type" ),
		);
	}
}
