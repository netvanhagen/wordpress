<?php
// Beveiliging
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maakt de benodigde database tabellen aan.
 */
function ars_create_db_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	// Tabel namen met WordPress prefix (meestal wp_)
	$table_index  = $wpdb->prefix . 'ars_index';
	$table_pinned = $wpdb->prefix . 'ars_pinned';
	$table_logs   = $wpdb->prefix . 'ars_logs';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// 1. Index Tabel
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
	$sql_logs = "CREATE TABLE $table_logs (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		lang varchar(10) DEFAULT 'all' NOT NULL,
		search_term varchar(255) NOT NULL,
		log_date datetime DEFAULT CURRENT_TIMESTAMP,
		result_ids text,
		result_count int(11) DEFAULT 0,
		PRIMARY KEY  (id),
		KEY search_term (search_term),
		KEY lang (lang)
	) $charset_collate;";

	dbDelta( $sql_index );
	dbDelta( $sql_pinned );
	dbDelta( $sql_logs );
}