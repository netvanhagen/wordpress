<?php
/**
 * Plugin Name: Advanced Relevance Search
 * Description: Vervangt de standaard zoekfunctionaliteit door een eigen index gebaseerd relevantiesysteem.
 * Version: 1.1.10
 * Author: HP van Hagen
 * Text Domain: adv-rel-search
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ARS_PATH', plugin_dir_path( __FILE__ ) );

// Laad alle componenten
require_once ARS_PATH . 'includes/admin-menus.php';
require_once ARS_PATH . 'includes/settings-api.php';
require_once ARS_PATH . 'includes/database.php';
require_once ARS_PATH . 'includes/indexer.php';
require_once ARS_PATH . 'includes/ajax-handler.php';
require_once ARS_PATH . 'includes/meta-boxes.php';
require_once ARS_PATH . 'includes/hooks.php';
require_once ARS_PATH . 'includes/search-engine.php';
require_once ARS_PATH . 'includes/query-interceptor.php';
require_once ARS_PATH . 'includes/frontend-ui.php';

// Activatie
register_activation_hook( __FILE__, 'ars_activate_plugin' );
function ars_activate_plugin() {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( 'Deze plugin werkt alleen op single site installaties.' );
	}
	ars_create_db_tables();
}

// Settings link op de plugins pagina
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ars_add_settings_link' );
function ars_add_settings_link( $links ) {
    $settings_link = '<a href="admin.php?page=ars-settings">' . __( 'Settings' ) . '</a>';
    array_push( $links, $settings_link );
    return $links;
}