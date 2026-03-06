<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpsr_index");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpsr_logs");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpsr_related_clicks");

delete_option('wpsr_settings');
delete_option('wpsr_index_meta');
delete_option('wpsr_index_queue');
delete_option('wpsr_index_version');

delete_option('wpsr_related_cache_version');
