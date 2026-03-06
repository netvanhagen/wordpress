<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'ars_add_exclude_meta_box' );
function ars_add_exclude_meta_box() {
	$options = get_option( 'ars_settings' );
	$post_types = isset( $options['post_types'] ) ? $options['post_types'] : array( 'post', 'page' );

	foreach ( $post_types as $type ) {
		add_meta_box(
			'ars_exclude_meta',
			'Search Relevance',
			'ars_render_exclude_meta_box',
			$type,
			'side',
			'default'
		);
	}
}

function ars_render_exclude_meta_box( $post ) {
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

add_action( 'save_post', 'ars_save_meta_box_data' );
function ars_save_meta_box_data( $post_id ) {
	if ( ! isset( $_POST['ars_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ars_meta_nonce'], 'ars_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

	$exclude_value = isset( $_POST['ars_exclude_index'] ) ? '1' : '0';
	update_post_meta( $post_id, '_ars_exclude_index', $exclude_value );
	
	// Direct de index bijwerken nadat de meta is opgeslagen
	ars_index_single_post( $post_id );
}