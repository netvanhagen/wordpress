<?php
/**
 * Meta Box Handler for Advanced Relevance Search
 */

namespace ARS\Admin;

use ARS\Search\Indexer;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaBox {

	/**
	 * Register meta box
	 */
	public static function register_metabox() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_exclude_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box_data' ), 30 );
	}

	/**
	 * Add exclude meta box to post types
	 */
	public static function add_exclude_meta_box() {
		$post_types = Settings::get_post_types();
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		foreach ( $post_types as $type ) {
			add_meta_box(
				'ars_exclude_meta',
				'Search Relevance',
				array( __CLASS__, 'render_exclude_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box
	 *
	 * @param \WP_Post $post
	 */
	public static function render_exclude_meta_box( $post ) {
		$value = get_post_meta( $post->ID, '_ars_exclude_index', true );
		wp_nonce_field( 'ars_save_meta', 'ars_meta_nonce' );
		?>
		<label>
			<input type="checkbox" name="ars_exclude_index" value="1" <?php checked( $value, '1' ); ?>>
			Uitsluiten van zoekindex
		</label>
		<p class="description">Vink dit aan om dit item te verbergen in de zoekresultaten.</p>
		<?php
	}

	/**
	 * Save meta box data
	 *
	 * @param int $post_id
	 */
	public static function save_meta_box_data( $post_id ) {
		if ( ! isset( $_POST['ars_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ars_meta_nonce'], 'ars_save_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$exclude_value = isset( $_POST['ars_exclude_index'] ) ? '1' : '0';
		update_post_meta( $post_id, '_ars_exclude_index', $exclude_value );

		// Reindex post
		Indexer::index_post( $post_id );
	}
}
