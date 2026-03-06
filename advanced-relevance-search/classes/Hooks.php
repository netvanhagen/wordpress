<?php
/**
 * WordPress Hooks Registration
 */

namespace ARS;

use ARS\Search\Indexer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hooks {

	/**
	 * Register all WordPress hooks
	 */
	public static function register_hooks() {
		// Post save/update
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20 );

		// Post delete
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );

		// Term changes
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms_change' ), 10, 4 );

		// Plugin activation/deactivation
		register_activation_hook( ARS_PLUGIN_FILE, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( ARS_PLUGIN_FILE, array( __CLASS__, 'on_deactivate' ) );

		// Settings link in plugins page
		add_filter( 'plugin_action_links_' . plugin_basename( ARS_PLUGIN_FILE ), array( __CLASS__, 'add_settings_link' ) );
	}

	/**
	 * Handle post save
	 *
	 * @param int $post_id
	 */
	public static function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		Indexer::index_post( $post_id );
	}

	/**
	 * Handle post delete
	 *
	 * @param int $post_id
	 */
	public static function on_delete_post( $post_id ) {
		Indexer::remove_post( $post_id );
	}

	/**
	 * Handle term changes
	 *
	 * @param int $object_id
	 * @param array $terms
	 * @param array $tt_ids
	 * @param string $taxonomy
	 */
	public static function on_terms_change( $object_id, $terms, $tt_ids, $taxonomy ) {
		Indexer::index_post( $object_id );
	}

	/**
	 * Handle plugin activation
	 */
	public static function on_activate() {
		if ( is_multisite() ) {
			deactivate_plugins( plugin_basename( ARS_PLUGIN_FILE ) );
			wp_die( 'Deze plugin werkt alleen op single site installaties.' );
		}

		// Create database tables
		\ARS\Core\Database::create_tables();
	}

	/**
	 * Handle plugin deactivation
	 */
	public static function on_deactivate() {
		// Cleanup if needed
	}

	/**
	 * Add settings link to plugins page
	 *
	 * @param array $links
	 * @return array
	 */
	public static function add_settings_link( $links ) {
		$settings_link = '<a href="admin.php?page=ars-settings">' . __( 'Settings' ) . '</a>';
		array_push( $links, $settings_link );
		return $links;
	}
}
