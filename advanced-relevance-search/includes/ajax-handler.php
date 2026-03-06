<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'ars_get_current_lang' ) ) {
function ars_get_current_lang() {
    if ( has_filter( 'wpml_current_language' ) ) {
        $lang = apply_filters( 'wpml_current_language', null );
        if ( is_string( $lang ) && $lang !== '' ) return $lang;
    }
    if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) return ICL_LANGUAGE_CODE;
    return 'all';
}
}

if ( ! function_exists( 'ars_normalize_search_term' ) ) {
function ars_normalize_search_term( $term ) {
    $term = (string) $term;
    $term = wp_unslash( $term );
    $term = trim( $term );
    $term = strtolower( $term );
    $term = preg_replace( '/\s+/', ' ', $term );
    return $term;
}
}

add_action( 'wp_ajax_ars_index_batch', 'ars_index_batch' );
function ars_index_batch() {
    check_ajax_referer( 'ars_ajax_nonce', 'nonce' );

    $options = get_option( 'ars_settings', array() );
    $post_types = isset( $options['post_types'] ) ? (array)$options['post_types'] : array('post', 'page');

    $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

    $count_query = new WP_Query(array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));
    $total = (int) $count_query->found_posts;

    $posts = get_posts(array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => 50,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids'
    ));

    if ( empty( $posts ) ) {
        update_option('ars_last_index_date', current_time('mysql'));
        update_option('ars_last_index_count', $total);
        wp_send_json_success( array( 'imported' => 0, 'done' => true, 'total' => $total ) );
    }

    foreach ( $posts as $p_id ) {
        ars_index_single_post( $p_id );
    }

    wp_send_json_success( array( 'imported' => count( $posts ), 'done' => false, 'total' => $total ) );
}

add_action( 'wp_ajax_ars_clear_index', 'ars_clear_index' );
function ars_clear_index() {
    check_ajax_referer( 'ars_ajax_nonce', 'nonce' );
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ars_index" );
    update_option('ars_last_index_count', 0);
    update_option('ars_last_index_date', 'Nog niet uitgevoerd');
    wp_send_json_success();
}

add_action( 'wp_ajax_ars_save_pinned', 'ars_save_pinned' );
function ars_save_pinned() {
    check_ajax_referer( 'ars_pinned_nonce', 'nonce' );
    global $wpdb;

    $lang = ars_get_current_lang();

    $term_raw = isset($_POST['term']) ? (string) $_POST['term'] : '';
    $term = ars_normalize_search_term( $term_raw );

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $pos = isset($_POST['pos']) ? (int) $_POST['pos'] : 1;

    if ( $pos < 1 ) $pos = 1;
    if ( $pos > 3 ) $pos = 3;

    if ( $term === '' || $post_id <= 0 ) {
        wp_send_json_error( 'Ongeldige data' );
    }

    $table = $wpdb->prefix . 'ars_pinned';

    $wpdb->delete($table, array(
        'lang' => $lang,
        'search_term' => $term,
        'position' => $pos
    ));

    $wpdb->insert($table, array(
        'lang' => $lang,
        'search_term' => sanitize_text_field($term),
        'post_id' => $post_id,
        'position' => $pos,
        'updated_at' => current_time('mysql')
    ));

    wp_send_json_success();
}

add_action( 'wp_ajax_ars_delete_pinned', 'ars_delete_pinned' );
function ars_delete_pinned() {
    check_ajax_referer( 'ars_pinned_nonce', 'nonce' );
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'ars_pinned', array('id' => intval($_POST['id'])));
    wp_send_json_success();
}

add_action( 'wp_ajax_ars_clear_logs', 'ars_clear_logs' );
function ars_clear_logs() {
    check_ajax_referer( 'ars_log_nonce', 'nonce' );
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ars_logs" );
    wp_send_json_success();
}

add_action( 'wp_ajax_ars_prune_logs', 'ars_prune_logs' );
function ars_prune_logs() {
    check_ajax_referer( 'ars_log_nonce', 'nonce' );
    global $wpdb;
    $options = get_option( 'ars_settings', array() );
    $days = isset($options['log_retention']) ? intval($options['log_retention']) : 30;

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}ars_logs WHERE log_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
    wp_send_json_success();
}