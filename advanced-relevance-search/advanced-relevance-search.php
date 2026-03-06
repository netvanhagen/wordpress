<?php
/**
 * Plugin Name: Advanced Relevance Search
 * Description: Vervangt de standaard zoekfunctionaliteit door een eigen index gebaseerd relevantiesysteem.
 * Version: 1.2.0 (Refactored OOP)
 * Author: HP van Hagen
 * Text Domain: adv-rel-search
 *
 * This is a refactored version with proper OOP architecture.
 * All classes are namespaced under ARS\ and organized by responsibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'ARS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARS_PLUGIN_FILE', __FILE__ );
define( 'ARS_VERSION', '1.2.0' );

// Load main plugin class
require_once ARS_PATH . 'classes/Plugin.php';

/**
 * Initialize plugin on plugins_loaded hook
 */
function ars_init_plugin() {
	ARS\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'ars_init_plugin', 5 );