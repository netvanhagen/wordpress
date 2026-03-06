<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Bijwerken bij opslaan of wijzigen
add_action( 'save_post', 'ars_on_save_post', 20 ); // Prioriteit 20 zodat meta al is opgeslagen
function ars_on_save_post( $post_id ) {
	if ( wp_is_post_revision( $post_id ) ) return;
	ars_index_single_post( $post_id );
}

// Verwijderen uit index als bericht wordt weggegooid
add_action( 'before_delete_post', 'ars_on_delete_post' );
function ars_on_delete_post( $post_id ) {
	ars_remove_from_index( $post_id );
}

// Bijwerken als categorieën of tags worden aangepast
add_action( 'set_object_terms', 'ars_on_terms_change', 10, 4 );
function ars_on_terms_change( $object_id, $terms, $tt_ids, $taxonomy ) {
	ars_index_single_post( $object_id );
}