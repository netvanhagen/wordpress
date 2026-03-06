<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'pre_get_posts', 'ars_intercept_search_query', 10 );
add_filter( 'posts_search', 'ars_disable_native_search_sql', 20, 2 );
add_filter( 'posts_orderby', 'ars_force_post__in_orderby', 999, 2 );
add_filter( 'the_posts', 'ars_log_after_real_posts', 999, 2 );

function ars_disable_native_search_sql( $search, $query ) {
    if ( is_admin() ) return $search;
    if ( ! ( $query instanceof WP_Query ) ) return $search;
    if ( $query->get( 'ars_use_custom_search' ) ) return '';
    return $search;
}

function ars_force_post__in_orderby( $orderby, $query ) {
    if ( is_admin() ) return $orderby;
    if ( ! ( $query instanceof WP_Query ) ) return $orderby;
    if ( ! $query->get( 'ars_use_custom_search' ) ) return $orderby;

    $ids = $query->get( 'post__in' );
    if ( empty( $ids ) || ! is_array( $ids ) ) return $orderby;

    $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
    if ( empty( $ids ) ) return $orderby;

    global $wpdb;
    return "FIELD({$wpdb->posts}.ID," . implode( ',', $ids ) . ")";
}

function ars_intercept_search_query( $query ) {
    if ( is_admin() ) return;
    if ( ! ( $query instanceof WP_Query ) ) return;

    if ( ! $query->is_main_query() || ! $query->is_search() ) return;

    if ( $query->get( 'et_is_theme_builder_query' ) ) return;
    if ( $query->get( 'et_is_builder_preview' ) ) return;

    $search_term = $query->get( 's' );
    if ( ! is_string( $search_term ) || trim( $search_term ) === '' ) return;

    if ( ! function_exists('ars_perform_search') ) return;

    $options = get_option('ars_settings', array());
    $per_page = isset($options['results_per_page']) ? intval($options['results_per_page']) : 10;
    if ($per_page > 0 && ! $query->get('posts_per_page') ) {
        $query->set('posts_per_page', $per_page);
    }

    $post_ids = ars_perform_search( $search_term );
    $post_ids = is_array($post_ids) ? array_values(array_filter(array_map('intval', $post_ids))) : array();

    $query->set( 'ars_use_custom_search', true );
    $query->set( 'ars_search_term_raw', $search_term );

    if ( ! empty( $post_ids ) ) {
        $query->set( 'post__in', $post_ids );
        $query->set( 'orderby', 'post__in' );
        $query->set( 'ignore_sticky_posts', true );
    } else {
        $query->set( 'post__in', array( 0 ) );
        $query->set( 'orderby', 'post__in' );
        $query->set( 'ignore_sticky_posts', true );
    }
}

function ars_log_after_real_posts( $posts, $query ) {
    if ( is_admin() ) return $posts;
    if ( ! ( $query instanceof WP_Query ) ) return $posts;
    if ( ! $query->is_main_query() || ! $query->is_search() ) return $posts;
    if ( ! $query->get( 'ars_use_custom_search' ) ) return $posts;

    static $did = false;
    if ( $did ) return $posts;

    $options = get_option( 'ars_settings', array() );
    if ( empty( $options['logging_enabled'] ) ) return $posts;

    $paged = (int) max( 1, (int) $query->get( 'paged' ) );
    if ( $paged > 1 ) return $posts;

    $term = (string) $query->get( 'ars_search_term_raw' );
    if ( trim($term) === '' ) return $posts;

    $shown_ids = array();
    if ( is_array($posts) ) {
        foreach ( $posts as $p ) {
            if ( isset($p->ID) ) $shown_ids[] = (int)$p->ID;
        }
    }

    $result_count = is_array($posts) ? count($posts) : 0;

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'ars_logs', array(
        'lang' => (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'all'),
        'search_term' => sanitize_text_field($term),
        'log_date' => current_time('mysql'),
        'result_ids' => !empty($shown_ids) ? wp_json_encode($shown_ids) : '',
        'result_count' => (int)$result_count
    ));

    $did = true;

    return $posts;
}