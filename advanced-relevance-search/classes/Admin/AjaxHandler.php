<?php
/**
 * AJAX Handler for Advanced Relevance Search
 */

namespace ARS\Admin;

use ARS\Core\Database;
use ARS\Core\Helpers;
use ARS\Search\Indexer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxHandler {

	/**
	 * Register AJAX actions
	 */
	public static function register_ajax_handlers() {
		add_action( 'wp_ajax_ars_index_batch', array( __CLASS__, 'index_batch' ) );
		add_action( 'wp_ajax_ars_clear_index', array( __CLASS__, 'clear_index' ) );
		add_action( 'wp_ajax_ars_save_pinned', array( __CLASS__, 'save_pinned' ) );
		add_action( 'wp_ajax_ars_delete_pinned', array( __CLASS__, 'delete_pinned' ) );
		add_action( 'wp_ajax_ars_clear_logs', array( __CLASS__, 'clear_logs' ) );
		add_action( 'wp_ajax_ars_prune_logs', array( __CLASS__, 'prune_logs' ) );
	}

	/**
	 * Handle index batch AJAX request
	 */
	public static function index_batch() {
		check_ajax_referer( 'ars_ajax_nonce', 'nonce' );

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		$result = Indexer::index_batch( $offset, 50 );

		wp_send_json_success( $result );
	}

	/**
	 * Clear index AJAX handler
	 */
	public static function clear_index() {
		check_ajax_referer( 'ars_ajax_nonce', 'nonce' );

		Database::truncate_index();
		update_option( 'ars_last_index_count', 0 );
		update_option( 'ars_last_index_date', 'Nog niet uitgevoerd' );

		wp_send_json_success();
	}

	/**
	 * Save pinned result AJAX handler
	 */
	public static function save_pinned() {
		check_ajax_referer( 'ars_pinned_nonce', 'nonce' );

		$lang = Helpers::get_current_lang();
		$term_raw = isset( $_POST['term'] ) ? (string) $_POST['term'] : '';
		$term = Helpers::normalize_search_term( $term_raw );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$pos = isset( $_POST['pos'] ) ? (int) $_POST['pos'] : 1;

		// Validate
		if ( $pos < 1 ) {
			$pos = 1;
		}
		if ( $pos > 3 ) {
			$pos = 3;
		}

		if ( $term === '' || $post_id <= 0 ) {
			wp_send_json_error( 'Ongeldige data' );
		}

		// Add pinned
		Database::add_pinned( $term, $post_id, $pos, $lang );

		wp_send_json_success();
	}

	/**
	 * Delete pinned result AJAX handler
	 */
	public static function delete_pinned() {
		check_ajax_referer( 'ars_pinned_nonce', 'nonce' );

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( 'Invalid ID' );
		}

		Database::delete_pinned( $id );

		wp_send_json_success();
	}

	/**
	 * Clear all logs AJAX handler
	 */
	public static function clear_logs() {
		check_ajax_referer( 'ars_log_nonce', 'nonce' );

		Database::truncate_logs();

		wp_send_json_success();
	}

	/**
	 * Prune old logs AJAX handler
	 */
	public static function prune_logs() {
		check_ajax_referer( 'ars_log_nonce', 'nonce' );

		$options = \ARS\Core\Settings::get_all();
		$days = isset( $options['log_retention'] ) ? intval( $options['log_retention'] ) : 30;

		Database::prune_logs( $days );

		wp_send_json_success();
	}
}
