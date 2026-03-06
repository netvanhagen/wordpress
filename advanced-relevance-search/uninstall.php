<?php
// Als de uninstall niet wordt aangeroepen vanuit WordPress, stop dan.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Verwijder de opties
delete_option( 'ars_settings' );

// 2. Definieer de tabellen
$table_index  = $wpdb->prefix . 'ars_index';
$table_pinned = $wpdb->prefix . 'ars_pinned';
$table_logs   = $wpdb->prefix . 'ars_logs';

// 3. Verwijder de tabellen uit de database
$wpdb->query( "DROP TABLE IF EXISTS $table_index" );
$wpdb->query( "DROP TABLE IF EXISTS $table_pinned" );
$wpdb->query( "DROP TABLE IF EXISTS $table_logs" );