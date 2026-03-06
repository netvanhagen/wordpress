<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', 'ars_register_settings' );

function ars_register_settings() {
    register_setting( 'ars_settings_group', 'ars_settings' );

    // Algemeen
    add_settings_section( 'ars_section_general', 'Algemene Instellingen', '__return_false', 'ars-settings' );
    add_settings_field( 'post_types', 'Contenttypes in index', 'ars_field_post_types_callback', 'ars-settings', 'ars_section_general' );
    add_settings_field( 'exclude_categories', 'Sluit categorieën uit (ID\'s)', 'ars_field_exclude_cats_callback', 'ars-settings', 'ars_section_general' );
    add_settings_field( 'min_word_length', 'Minimum woordlengte', 'ars_field_min_length_callback', 'ars-settings', 'ars_section_general' );
    add_settings_field( 'stop_words', 'Stopwoordenlijst', 'ars_field_stop_words_callback', 'ars-settings', 'ars_section_general' );
    
    // Synoniemen
    add_settings_section( 'ars_section_synonyms', 'Synoniemen Beheer', '__return_false', 'ars-settings' );
    add_settings_field( 'synonyms', 'Handmatige synoniemen', 'ars_field_synonyms_callback', 'ars-settings', 'ars_section_synonyms' );

    // UI & Logging
    add_settings_section( 'ars_section_ui', 'Weergave & Analyse', '__return_false', 'ars-settings' );
    add_settings_field( 'results_per_page', 'Resultaten per pagina', 'ars_field_per_page_callback', 'ars-settings', 'ars_section_ui' );
    add_settings_field( 'highlight_enabled', 'Highlighting inschakelen', 'ars_field_highlight_toggle_callback', 'ars-settings', 'ars_section_ui' );
    add_settings_field( 'highlight_color', 'Highlight kleur', 'ars_field_color_callback', 'ars-settings', 'ars_section_ui' );
    add_settings_field( 'logging_enabled', 'Zoekopdrachten loggen', 'ars_field_logging_callback', 'ars-settings', 'ars_section_ui' );
    add_settings_field( 'log_retention', 'Bewaarperiode logs', 'ars_field_retention_callback', 'ars-settings', 'ars_section_ui' );

    // Weging & Multipliers
    add_settings_section( 'ars_section_weights', 'Relevantie & Wegingen', '__return_false', 'ars-settings' );
    add_settings_field( 'w_content', 'Content Body Gewicht', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_content', 'def' => 50) );
    add_settings_field( 'w_title', 'Titel Gewicht', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_title', 'def' => 20) );
    add_settings_field( 'w_excerpt', 'Excerpt Gewicht', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_excerpt', 'def' => 10) );
    add_settings_field( 'w_cat', 'Categorie Match', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_cat', 'def' => 10) );
    add_settings_field( 'w_tag', 'Tags Match', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_tag', 'def' => 5) );
    add_settings_field( 'w_internal', 'Interne Keywords Boost', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'weight_internal', 'def' => 40) );
    add_settings_field( 'b_phrase', 'Exact Phrase Bonus', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'bonus_phrase', 'def' => 30) );
    add_settings_field( 'b_title', 'Exact Titel Bonus', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'bonus_title', 'def' => 40) );
    add_settings_field( 'm_post', 'Bericht Multiplier', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'mult_post', 'def' => 1.2, 'step' => 0.1) );
    add_settings_field( 'm_page', 'Pagina Multiplier', 'ars_weight_callback', 'ars-settings', 'ars_section_weights', array('id' => 'mult_page', 'def' => 1.0, 'step' => 0.1) );
}

function ars_weight_callback($args) {
    $options = get_option('ars_settings', array());
    $val = isset($options[$args['id']]) ? $options[$args['id']] : $args['def'];
    $step = isset($args['step']) ? $args['step'] : 1;
    echo '<input type="number" step="'.$step.'" name="ars_settings['.$args['id'].']" value="'.$val.'" class="small-text">';
}

function ars_field_post_types_callback() {
    $options = get_option('ars_settings', array());
    $selected = isset($options['post_types']) ? (array)$options['post_types'] : array('post', 'page');
    $pts = get_post_types(array('public'=>true), 'objects');
    foreach($pts as $pt) {
        $checked = in_array($pt->name, $selected) ? 'checked' : '';
        echo '<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="ars_settings[post_types][]" value="'.$pt->name.'" '.$checked.'> '.$pt->label.'</label>';
    }
}

function ars_field_exclude_cats_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['exclude_categories']) ? $options['exclude_categories'] : '';
    echo '<input type="text" name="ars_settings[exclude_categories]" value="'.$val.'" class="regular-text">';
    echo '<p class="description">Vul comma-gescheiden categorie ID\'s in om uit te sluiten (bijv: 5, 12)</p>';
}

function ars_field_min_length_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['min_word_length']) ? $options['min_word_length'] : 3;
    echo '<input type="number" name="ars_settings[min_word_length]" value="'.$val.'" class="small-text">';
}

function ars_field_stop_words_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['stop_words']) ? $options['stop_words'] : 'de, het, een, en, van, naar, op, in';
    echo '<textarea name="ars_settings[stop_words]" rows="2" class="large-text">'.esc_textarea($val).'</textarea>';
}

function ars_field_synonyms_callback() {
    $options = get_option('ars_settings', array());
    $lang = defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'all';
    $val = isset($options['synonyms_'.$lang]) ? $options['synonyms_'.$lang] : '';
    echo '<textarea name="ars_settings[synonyms_'.$lang.']" rows="4" class="large-text" placeholder="voorbeeld: auto = wagen, voertuig">'.esc_textarea($val).'</textarea>';
}

function ars_field_per_page_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['results_per_page']) ? $options['results_per_page'] : 10;
    echo '<input type="number" name="ars_settings[results_per_page]" value="'.$val.'" class="small-text">';
}

function ars_field_highlight_toggle_callback() {
    $options = get_option('ars_settings', array());
    $checked = !empty($options['highlight_enabled']) ? 'checked' : '';
    echo '<input type="checkbox" name="ars_settings[highlight_enabled]" value="1" '.$checked.'>';
}

function ars_field_color_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['highlight_color']) ? $options['highlight_color'] : '#ffeb3b';
    echo '<input type="color" name="ars_settings[highlight_color]" value="'.$val.'">';
}

function ars_field_logging_callback() {
    $options = get_option('ars_settings', array());
    $checked = !empty($options['logging_enabled']) ? 'checked' : '';
    echo '<input type="checkbox" name="ars_settings[logging_enabled]" value="1" '.$checked.'>';
}

function ars_field_retention_callback() {
    $options = get_option('ars_settings', array());
    $val = isset($options['log_retention']) ? $options['log_retention'] : 30;
    echo '<select name="ars_settings[log_retention]"><option value="30" '.selected($val,30,false).'>30 dagen</option><option value="60" '.selected($val,60,false).'>60 dagen</option><option value="90" '.selected($val,90,false).'>90 dagen</option></select>';
}