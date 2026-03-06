<?php
/**
 * Settings Management for Advanced Relevance Search
 */

namespace ARS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY = 'ars_settings';

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public static function get_all() {
		return get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Get single setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update setting
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return bool
	 */
	public static function update( $key, $value ) {
		$settings = self::get_all();
		$settings[ $key ] = $value;
		return update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Get post types to index
	 *
	 * @return array
	 */
	public static function get_post_types() {
		return self::get( 'post_types', array( 'post', 'page' ) );
	}

	/**
	 * Get weight settings
	 *
	 * @return array
	 */
	public static function get_weights() {
		return array(
			'content' => (int) self::get( 'weight_content', 50 ),
			'title' => (int) self::get( 'weight_title', 20 ),
			'excerpt' => (int) self::get( 'weight_excerpt', 10 ),
			'category' => (int) self::get( 'weight_cat', 10 ),
			'tag' => (int) self::get( 'weight_tag', 5 ),
			'internal' => (int) self::get( 'weight_internal', 40 ),
		);
	}

	/**
	 * Get bonus settings
	 *
	 * @return array
	 */
	public static function get_bonuses() {
		return array(
			'phrase' => (int) self::get( 'bonus_phrase', 30 ),
			'title' => (int) self::get( 'bonus_title', 40 ),
		);
	}

	/**
	 * Get multiplier settings
	 *
	 * @return array
	 */
	public static function get_multipliers() {
		return array(
			'post' => (float) self::get( 'mult_post', 1.2 ),
			'page' => (float) self::get( 'mult_page', 1.0 ),
		);
	}

	/**
	 * Get synonym settings for language
	 *
	 * @param string $lang Optional language code
	 * @return string
	 */
	public static function get_synonyms( $lang = null ) {
		if ( $lang === null ) {
			$lang = Helpers::get_current_lang();
		}
		return self::get( 'synonyms_' . $lang, '' );
	}

	/**
	 * Check if feature is enabled
	 *
	 * @param string $feature Feature name
	 * @return bool
	 */
	public static function is_enabled( $feature ) {
		$features = array( 'highlight_enabled', 'logging_enabled' );
		if ( ! in_array( $feature, $features, true ) ) {
			return false;
		}
		return ! empty( self::get( $feature ) );
	}

	/**
	 * Register settings for admin
	 */
	public static function register_settings() {
		register_setting( 'ars_settings_group', self::OPTION_KEY );

		// Algemeen
		add_settings_section( 'ars_section_general', 'Algemene Instellingen', '__return_false', 'ars-settings' );
		add_settings_field( 'post_types', 'Contenttypes in index', array( self::class, 'field_post_types' ), 'ars-settings', 'ars_section_general' );
		add_settings_field( 'exclude_categories', 'Sluit categorieën uit (ID\'s)', array( self::class, 'field_exclude_cats' ), 'ars-settings', 'ars_section_general' );
		add_settings_field( 'min_word_length', 'Minimum woordlengte', array( self::class, 'field_min_length' ), 'ars-settings', 'ars_section_general' );
		add_settings_field( 'stop_words', 'Stopwoordenlijst', array( self::class, 'field_stop_words' ), 'ars-settings', 'ars_section_general' );

		// Synoniemen
		add_settings_section( 'ars_section_synonyms', 'Synoniemen Beheer', '__return_false', 'ars-settings' );
		add_settings_field( 'synonyms', 'Handmatige synoniemen', array( self::class, 'field_synonyms' ), 'ars-settings', 'ars_section_synonyms' );

		// UI & Logging
		add_settings_section( 'ars_section_ui', 'Weergave & Analyse', '__return_false', 'ars-settings' );
		add_settings_field( 'results_per_page', 'Resultaten per pagina', array( self::class, 'field_per_page' ), 'ars-settings', 'ars_section_ui' );
		add_settings_field( 'highlight_enabled', 'Highlighting inschakelen', array( self::class, 'field_highlight_toggle' ), 'ars-settings', 'ars_section_ui' );
		add_settings_field( 'highlight_color', 'Highlight kleur', array( self::class, 'field_color' ), 'ars-settings', 'ars_section_ui' );
		add_settings_field( 'logging_enabled', 'Zoekopdrachten loggen', array( self::class, 'field_logging' ), 'ars-settings', 'ars_section_ui' );
		add_settings_field( 'log_retention', 'Bewaarperiode logs', array( self::class, 'field_retention' ), 'ars-settings', 'ars_section_ui' );

		// Weging & Multipliers
		add_settings_section( 'ars_section_weights', 'Relevantie & Wegingen', '__return_false', 'ars-settings' );
		$weights = self::get_weights();
		$bonuses = self::get_bonuses();
		$mults = self::get_multipliers();

		add_settings_field( 'w_content', 'Content Body Gewicht', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_content', 'def' => 50 ) );
		add_settings_field( 'w_title', 'Titel Gewicht', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_title', 'def' => 20 ) );
		add_settings_field( 'w_excerpt', 'Excerpt Gewicht', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_excerpt', 'def' => 10 ) );
		add_settings_field( 'w_cat', 'Categorie Match', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_cat', 'def' => 10 ) );
		add_settings_field( 'w_tag', 'Tags Match', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_tag', 'def' => 5 ) );
		add_settings_field( 'w_internal', 'Interne Keywords Boost', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'weight_internal', 'def' => 40 ) );
		add_settings_field( 'b_phrase', 'Exact Phrase Bonus', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'bonus_phrase', 'def' => 30 ) );
		add_settings_field( 'b_title', 'Exact Titel Bonus', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'bonus_title', 'def' => 40 ) );
		add_settings_field( 'm_post', 'Bericht Multiplier', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'mult_post', 'def' => 1.2, 'step' => 0.1 ) );
		add_settings_field( 'm_page', 'Pagina Multiplier', array( self::class, 'field_weight' ), 'ars-settings', 'ars_section_weights', array( 'id' => 'mult_page', 'def' => 1.0, 'step' => 0.1 ) );
	}

	// Form field callbacks
	public static function field_weight( $args ) {
		$val = self::get( $args['id'], $args['def'] );
		$step = isset( $args['step'] ) ? $args['step'] : 1;
		echo '<input type="number" step="' . $step . '" name="' . self::OPTION_KEY . '[' . $args['id'] . ']" value="' . $val . '" class="small-text">';
	}

	public static function field_post_types() {
		$selected = self::get_post_types();
		$pts = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $pts as $pt ) {
			$checked = in_array( $pt->name, $selected, true ) ? 'checked' : '';
			echo '<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="' . self::OPTION_KEY . '[post_types][]" value="' . $pt->name . '" ' . $checked . '> ' . $pt->label . '</label>';
		}
	}

	public static function field_exclude_cats() {
		$val = self::get( 'exclude_categories', '' );
		echo '<input type="text" name="' . self::OPTION_KEY . '[exclude_categories]" value="' . $val . '" class="regular-text">';
		echo '<p class="description">Vul comma-gescheiden categorie ID\'s in om uit te sluiten (bijv: 5, 12)</p>';
	}

	public static function field_min_length() {
		$val = self::get( 'min_word_length', 3 );
		echo '<input type="number" name="' . self::OPTION_KEY . '[min_word_length]" value="' . $val . '" class="small-text">';
	}

	public static function field_stop_words() {
		$val = self::get( 'stop_words', 'de, het, een, en, van, naar, op, in' );
		echo '<textarea name="' . self::OPTION_KEY . '[stop_words]" rows="2" class="large-text">' . esc_textarea( $val ) . '</textarea>';
	}

	public static function field_synonyms() {
		$lang = Helpers::get_current_lang();
		$val = self::get( 'synonyms_' . $lang, '' );
		echo '<textarea name="' . self::OPTION_KEY . '[synonyms_' . $lang . ']" rows="4" class="large-text" placeholder="voorbeeld: auto = wagen, voertuig">' . esc_textarea( $val ) . '</textarea>';
	}

	public static function field_per_page() {
		$val = self::get( 'results_per_page', 10 );
		echo '<input type="number" name="' . self::OPTION_KEY . '[results_per_page]" value="' . $val . '" class="small-text">';
	}

	public static function field_highlight_toggle() {
		$checked = ! empty( self::get( 'highlight_enabled' ) ) ? 'checked' : '';
		echo '<input type="checkbox" name="' . self::OPTION_KEY . '[highlight_enabled]" value="1" ' . $checked . '>';
	}

	public static function field_color() {
		$val = self::get( 'highlight_color', '#ffeb3b' );
		echo '<input type="color" name="' . self::OPTION_KEY . '[highlight_color]" value="' . $val . '">';
	}

	public static function field_logging() {
		$checked = ! empty( self::get( 'logging_enabled' ) ) ? 'checked' : '';
		echo '<input type="checkbox" name="' . self::OPTION_KEY . '[logging_enabled]" value="1" ' . $checked . '>';
	}

	public static function field_retention() {
		$val = self::get( 'log_retention', 30 );
		echo '<select name="' . self::OPTION_KEY . '[log_retention]">';
		echo '<option value="30" ' . selected( $val, 30, false ) . '>30 dagen</option>';
		echo '<option value="60" ' . selected( $val, 60, false ) . '>60 dagen</option>';
		echo '<option value="90" ' . selected( $val, 90, false ) . '>90 dagen</option>';
		echo '</select>';
	}
}
