<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Indexeert een specifiek bericht op basis van ID.
 */
function ars_index_single_post( $post_id ) {
	global $wpdb;
	$table_index = $wpdb->prefix . 'ars_index';
	$options     = get_option( 'ars_settings' );
	$post_types  = isset( $options['post_types'] ) ? $options['post_types'] : array( 'post', 'page' );

	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, $post_types ) || $post->post_status !== 'publish' ) {
		$wpdb->delete( $table_index, array( 'post_id' => $post_id ) );
		return;
	}

	// 1. Check of 'Uitsluiten van index' is aangevinkt (Meta checkbox)
	$exclude = get_post_meta( $post_id, '_ars_exclude_index', true );
	if ( $exclude === '1' ) {
		$wpdb->delete( $table_index, array( 'post_id' => $post_id ) );
		return;
	}

	// 2. Check of bericht in een uitgesloten categorie zit (Globale settings)
	$exclude_cats_str = isset($options['exclude_categories']) ? $options['exclude_categories'] : '';
	if ( !empty($exclude_cats_str) ) {
		$exclude_cats = array_map('trim', explode(',', $exclude_cats_str));
		if ( has_category( $exclude_cats, $post_id ) ) {
			$wpdb->delete( $table_index, array( 'post_id' => $post_id ) );
			return;
		}
	}

	// WPML Taal check
	$lang = 'all';
	if ( function_exists( 'wpml_get_language_information' ) ) {
		$lang_info = wpml_get_language_information( $post->ID );
		if ( ! is_wp_error( $lang_info ) ) {
			$lang = $lang_info['language_code'];
		}
	}

	$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
	$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );

	$wpdb->delete( $table_index, array( 'post_id' => $post->ID ) );
	$wpdb->insert( $table_index, array(
		'post_id'           => $post->ID,
		'post_type'         => $post->post_type,
		'lang'              => $lang,
		'title'             => strip_tags( $post->post_title ),
		'excerpt'           => strip_tags( $post->post_excerpt ),
		'content_tokens'    => strip_tags( $post->post_content ),
		'category_ids'      => implode( ',', $categories ),
		'tag_ids'           => implode( ',', $tags ),
		'updated_at'        => current_time( 'mysql' ),
	) );
}

/**
 * Verwijdert een bericht uit de index.
 */
function ars_remove_from_index( $post_id ) {
	global $wpdb;
	$table_index = $wpdb->prefix . 'ars_index';
	$wpdb->delete( $table_index, array( 'post_id' => $post_id ) );
}