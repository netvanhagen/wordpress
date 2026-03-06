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

function ars_perform_search( $search_term ) {
    global $wpdb;

    $table_index  = $wpdb->prefix . 'ars_index';
    $table_pinned = $wpdb->prefix . 'ars_pinned';

    $options      = get_option( 'ars_settings', array() );
    $current_lang = ars_get_current_lang();

    $clean_term       = trim( (string) $search_term );
    $clean_term_norm  = ars_normalize_search_term( $clean_term );

    /*
     * 1) Reguliere resultaten ophalen uit index
     */
    $min_len = isset($options['min_word_length']) ? (int)$options['min_word_length'] : 3;
    $stop_words = array_map('trim', explode(',', strtolower(isset($options['stop_words']) ? $options['stop_words'] : '')));

    $raw_words = preg_split('/\s+/', $clean_term_norm);
    $search_words = array();

    foreach($raw_words as $rw) {
        $rw = trim((string)$rw);
        if ($rw === '') continue;

        if ( (strlen($rw) >= $min_len && !in_array($rw, $stop_words, true)) || (count($raw_words) === 1 && strlen($rw) > 0) ) {
            $search_words[] = $rw;
        }
    }

    if (empty($search_words) && $clean_term_norm !== '') {
        $search_words = array($clean_term_norm);
    }

    $synonyms_text = isset($options['synonyms_' . $current_lang]) ? (string)$options['synonyms_' . $current_lang] : '';
    if ( !empty($synonyms_text) ) {
        $lines = preg_split('/\r\n|\r|\n/', $synonyms_text);
        foreach($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) < 2) continue;

            $key = ars_normalize_search_term($parts[0]);
            $values = array_map('ars_normalize_search_term', explode(',', (string)$parts[1]));
            $values = array_filter(array_map('trim', $values));

            if ($key !== '' && in_array($key, $search_words, true)) {
                $search_words = array_merge($search_words, $values);
            }
            foreach($values as $val) {
                if ($val !== '' && in_array($val, $search_words, true)) {
                    $search_words[] = $key;
                }
            }
        }
    }

    $search_words = array_values(array_unique(array_filter($search_words)));

    $regular_ids = array();

    if ( ! empty($search_words) ) {
        $allowed_types = isset($options['post_types']) ? (array)$options['post_types'] : array('post', 'page');
        $allowed_types = array_values(array_filter($allowed_types));
        if (empty($allowed_types)) $allowed_types = array('post', 'page');
        $types_sql = implode("','", array_map('esc_sql', $allowed_types));

        $w_body   = isset($options['weight_content']) ? (int)$options['weight_content'] : 50;
        $w_title  = isset($options['weight_title']) ? (int)$options['weight_title'] : 20;
        $w_excerpt= isset($options['weight_excerpt']) ? (int)$options['weight_excerpt'] : 10;
        $w_cat    = isset($options['weight_cat']) ? (int)$options['weight_cat'] : 10;
        $w_tag    = isset($options['weight_tag']) ? (int)$options['weight_tag'] : 5;
        $w_int    = isset($options['weight_internal']) ? (int)$options['weight_internal'] : 40;

        $b_phrase = isset($options['bonus_phrase']) ? (int)$options['bonus_phrase'] : 30;
        $b_title  = isset($options['bonus_title']) ? (int)$options['bonus_title'] : 40;

        $m_post   = isset($options['mult_post']) ? (float)$options['mult_post'] : 1.2;
        $m_page   = isset($options['mult_page']) ? (float)$options['mult_page'] : 1.0;

        $score_sql = array();
        foreach ($search_words as $w) {
            $like = '%' . $wpdb->esc_like($w) . '%';
            $score_sql[] = $wpdb->prepare(
                "(CASE WHEN title LIKE %s THEN $w_title ELSE 0 END +
                  CASE WHEN content_tokens LIKE %s THEN $w_body ELSE 0 END +
                  CASE WHEN excerpt LIKE %s THEN $w_excerpt ELSE 0 END +
                  CASE WHEN category_ids LIKE %s THEN $w_cat ELSE 0 END +
                  CASE WHEN tag_ids LIKE %s THEN $w_tag ELSE 0 END +
                  CASE WHEN internal_keywords LIKE %s THEN $w_int ELSE 0 END)",
                $like, $like, $like, $like, $like, $like
            );
        }

        $final_score_sql = implode(' + ', $score_sql);

        $phrase_bonus = $wpdb->prepare(
            "CASE WHEN title LIKE %s THEN $b_phrase ELSE 0 END",
            '%' . $wpdb->esc_like($clean_term_norm) . '%'
        );

        $title_bonus = $wpdb->prepare(
            "CASE WHEN LOWER(title) = %s THEN $b_title ELSE 0 END",
            $clean_term_norm
        );

        $mult_sql = "CASE WHEN post_type = 'post' THEN $m_post WHEN post_type = 'page' THEN $m_page ELSE 1.0 END";

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, (($final_score_sql + $phrase_bonus + $title_bonus) * $mult_sql) as score
             FROM $table_index
             WHERE (lang = %s OR lang = 'all')
             AND post_type IN ('$types_sql')
             HAVING score > 0
             ORDER BY score DESC
             LIMIT 200",
            $current_lang
        ));

        $regular_ids = array_map('intval', (array) wp_list_pluck($results, 'post_id'));
    }

    /*
     * 2) Pinned ophalen op basis van genormaliseerde term
     */
    $pinned_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, position
             FROM $table_pinned
             WHERE (lang = %s OR lang = 'all')
             AND LOWER(TRIM(search_term)) = %s
             ORDER BY position ASC, id ASC
             LIMIT 3",
            $current_lang,
            $clean_term_norm
        )
    );

    $pinned_ids = array();
    $pinned_positions = array();
    if ( ! empty($pinned_rows) ) {
        foreach ($pinned_rows as $r) {
            $pid = isset($r->post_id) ? (int)$r->post_id : 0;
            if ($pid <= 0) continue;

            $pos = isset($r->position) ? (int)$r->position : 1;
            if ($pos < 1) $pos = 1;
            if ($pos > 3) $pos = 3;

            $pinned_ids[] = $pid;
            $pinned_positions[] = $pos;
        }
    }

    /*
     * 3) Pinned op positie in reguliere lijst zetten
     */
    if ( ! empty($pinned_ids) ) {
        $regular_ids = array_values(array_diff($regular_ids, $pinned_ids));
    }

    $final_order = $regular_ids;

    if ( ! empty($pinned_ids) ) {
        for ($i=0; $i<count($pinned_ids); $i++) {
            $pid = (int) $pinned_ids[$i];
            $pos = (int) $pinned_positions[$i];

            $idx = $pos - 1;
            if ($idx < 0) $idx = 0;
            if ($idx > count($final_order)) $idx = count($final_order);

            array_splice($final_order, $idx, 0, array($pid));
        }
    }

    $final_order = array_values(array_unique(array_map('intval', $final_order)));

    if (empty($final_order)) return array();

    $clean_ids = implode(',', array_map('intval', $final_order));
    $valid = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ID IN ($clean_ids) AND post_status = 'publish'");

    $final = array();
    foreach ($final_order as $id) {
        if (in_array($id, $valid, true)) $final[] = (int)$id;
    }

    return $final;
}