<?php
/**
 * Plugin Name: WP Search Relevance
 * Description: Vervangt WordPress zoeken met een eigen index-gebaseerde relevantiezoeker met WPML, logging en categorie-filter shortcode.
 * Version: 2.3.5
 * Author: HP van Hagen
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-search-relevance
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Search_Relevance_Plugin
{
    private $request_search_logged = false;
    private $related_click_handled = false;
    const OPTION_KEY = 'wpsr_settings';
    const OPTION_INDEX_META = 'wpsr_index_meta';
    const OPTION_QUEUE = 'wpsr_index_queue';
    const OPTION_INDEX_VERSION = 'wpsr_index_version';
    const OPTION_RELATED_CACHE_VERSION = 'wpsr_related_cache_version';
    const META_EXCLUDE = '_wpsr_exclude_from_index';

    private $defaults = [
        'content_types' => ['post', 'page'],
        'weights' => [
            'content' => 50,
            'title' => 20,
            'excerpt' => 10,
            'tags' => 25,
            'categories' => 5,
            'exact_phrase_bonus' => 30,
            'exact_title_bonus' => 40,
            'post_multiplier' => 1.2,
            'page_multiplier' => 1.0,
        ],
        'stopwords' => "de\nhet\neen\nof\nvoor\nop\nin\nmet",
        'min_word_length' => 3,
        'results_per_page' => 10,
        'result_mode' => 'pagination',
        'logging_enabled' => 1,
        'logging_exclude_admin_users' => 0,
        'retention_days' => 30,
        'exclude_categories' => [],
        'show_popular_on_no_results' => 0,
        'popular_page_ids' => [],
        'synonyms' => [],
        'related_module_enabled' => 0,
        'related_title' => 'Relevante artikelen:',
        'related_margin_top' => '0',
        'related_margin_bottom' => '1rem',
        'related_show_bullets' => 1,
        'related_post_types' => ['post'],
        'related_context_single' => 1,
        'related_context_archives' => 0,
        'related_context_search' => 0,
        'related_context_homepage' => 0,
        'related_exclude_posts' => [],
        'related_click_logging_enabled' => 0,
        'related_click_exclude_admin_users' => 0,
        'related_limit' => 2,
        'related_tags_weight' => 60,
        'related_content_weight' => 40,
        'related_phrase_bonus' => 25,
        'related_ilj_priority' => 'high',
        'related_min_score' => 50,
        'related_min_shared_tags' => 1,
        'related_featured_enabled' => 0,
        'related_featured_text' => 'Bekijk onze aanbieding:',
        'related_featured_post_id' => 0,
    ];

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_notices', [$this, 'multisite_notice']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post', [$this, 'save_post_meta'], 10, 2);
        add_action('save_post', [$this, 'maybe_reindex_on_save'], 20, 3);
        add_action('transition_post_status', [$this, 'maybe_reindex_on_status_transition'], 10, 3);
        add_action('deleted_post', [$this, 'maybe_remove_from_index_on_delete'], 10, 1);
        add_action('trashed_post', [$this, 'maybe_remove_from_index_on_delete'], 10, 1);

        add_action('wp_ajax_wpsr_prepare_index', [$this, 'ajax_prepare_index']);
        add_action('wp_ajax_wpsr_index_batch', [$this, 'ajax_index_batch']);
        add_action('wp_ajax_wpsr_clear_index', [$this, 'ajax_clear_index']);
        add_action('wp_ajax_wpsr_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_wpsr_clear_old_logs', [$this, 'ajax_clear_old_logs']);
        add_action('wp_ajax_wpsr_clear_related_click_logs', [$this, 'ajax_clear_related_click_logs']);

        add_action('pre_get_posts', [$this, 'intercept_search_query']);
        add_shortcode('wpsr_category_filter', [$this, 'render_category_filter_shortcode']);
        add_shortcode('wpsr_related_articles', [$this, 'render_related_shortcode']);

        add_action('init', [$this, 'handle_related_click_redirect'], 1);
        add_action('template_redirect', [$this, 'handle_related_click_redirect'], 1);
        add_filter('redirect_canonical', [$this, 'bypass_canonical_for_related_clicks'], 10, 2);
        add_action('admin_init', [$this, 'ensure_related_clicks_table']);

        add_action('wp', [$this, 'log_search']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
    }


    public function ensure_related_clicks_table()
    {
        global $wpdb;
        $table = $this->related_clicks_table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL,
            lang VARCHAR(20) NOT NULL DEFAULT '',
            clicked_at DATETIME NOT NULL,
            is_human TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id),
            KEY clicked_at (clicked_at),
            KEY is_human (is_human)
        ) {$charset_collate};");
    }

    public function activate()
    {
        if (is_multisite()) {
            deactivate_plugins(plugin_basename(__FILE__));
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $index_table = $this->index_table();
        $logs_table = $this->logs_table();
        $related_clicks_table = $this->related_clicks_table();

        dbDelta("CREATE TABLE `{$index_table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            lang VARCHAR(12) NOT NULL DEFAULT 'default',
            post_type VARCHAR(32) NOT NULL,
            post_title LONGTEXT NULL,
            post_excerpt LONGTEXT NULL,
            post_content LONGTEXT NULL,
            tags LONGTEXT NULL,
            categories LONGTEXT NULL,
            ilj_keywords LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_lang (post_id,lang)
        ) {$charset};");

        dbDelta("CREATE TABLE `{$logs_table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            search_term TEXT NOT NULL,
            lang VARCHAR(12) NOT NULL DEFAULT 'default',
            result_count INT UNSIGNED NOT NULL DEFAULT 0,
            result_ids LONGTEXT NULL,
            searched_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};");


        dbDelta("CREATE TABLE `{$related_clicks_table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL,
            lang VARCHAR(12) NOT NULL DEFAULT 'default',
            clicked_at DATETIME NOT NULL,
            is_human TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id),
            KEY is_human (is_human)
        ) {$charset};");

        add_option(self::OPTION_KEY, $this->defaults);
        add_option(self::OPTION_RELATED_CACHE_VERSION, 1);
        update_option(self::OPTION_INDEX_VERSION, '1');
    }

    public function deactivate()
    {
        delete_option(self::OPTION_QUEUE);
    }

    public function multisite_notice()
    {
        if (is_multisite() && current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>WP Search Relevance ondersteunt alleen single-site installaties.</p></div>';
        }
    }

    public function register_admin_menu()
    {
        add_menu_page('WP Search Relevance', 'WP Search Relevance', 'manage_options', 'wpsr-settings', [$this, 'render_settings_page'], 'dashicons-search', 58);
        add_submenu_page('wpsr-settings', 'Settings', 'Settings', 'manage_options', 'wpsr-settings', [$this, 'render_settings_page']);
        add_submenu_page('wpsr-settings', 'Logging', 'Logging', 'manage_options', 'wpsr-logging', [$this, 'render_logging_page']);
    }

    public function register_settings()
    {
        register_setting('wpsr_settings_group', self::OPTION_KEY, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : [];
        $settings = $this->get_settings();

        if (isset($input['content_types']) && is_array($input['content_types'])) {
            $settings['content_types'] = array_map('sanitize_key', $input['content_types']);
        }

        if (isset($input['weights']) && is_array($input['weights'])) {
            $settings['weights']['content'] = isset($input['weights']['content']) ? (int) $input['weights']['content'] : (int) $settings['weights']['content'];
            $settings['weights']['title'] = isset($input['weights']['title']) ? (int) $input['weights']['title'] : (int) $settings['weights']['title'];
            $settings['weights']['excerpt'] = isset($input['weights']['excerpt']) ? (int) $input['weights']['excerpt'] : (int) $settings['weights']['excerpt'];
            $settings['weights']['tags'] = isset($input['weights']['tags']) ? (int) $input['weights']['tags'] : (int) $settings['weights']['tags'];
            $settings['weights']['categories'] = isset($input['weights']['categories']) ? (int) $input['weights']['categories'] : (int) $settings['weights']['categories'];
            $settings['weights']['exact_phrase_bonus'] = isset($input['weights']['exact_phrase_bonus']) ? (int) $input['weights']['exact_phrase_bonus'] : (int) $settings['weights']['exact_phrase_bonus'];
            $settings['weights']['exact_title_bonus'] = isset($input['weights']['exact_title_bonus']) ? (int) $input['weights']['exact_title_bonus'] : (int) $settings['weights']['exact_title_bonus'];
            $settings['weights']['post_multiplier'] = isset($input['weights']['post_multiplier']) ? (float) $input['weights']['post_multiplier'] : (float) $settings['weights']['post_multiplier'];
            $settings['weights']['page_multiplier'] = isset($input['weights']['page_multiplier']) ? (float) $input['weights']['page_multiplier'] : (float) $settings['weights']['page_multiplier'];
        }

        if (isset($input['stopwords'])) {
            $settings['stopwords'] = sanitize_textarea_field($input['stopwords']);
        }
        if (isset($input['min_word_length'])) {
            $settings['min_word_length'] = max(1, (int) $input['min_word_length']);
        }
        if (isset($input['results_per_page'])) {
            $settings['results_per_page'] = max(1, (int) $input['results_per_page']);
        }
        if (isset($input['result_mode'])) {
            $settings['result_mode'] = in_array($input['result_mode'], ['pagination', 'infinite'], true) ? $input['result_mode'] : $settings['result_mode'];
        }

        if (array_key_exists('logging_enabled', $input)) {
            $settings['logging_enabled'] = empty($input['logging_enabled']) ? 0 : 1;
        }
        if (array_key_exists('logging_exclude_admin_users', $input)) {
            $settings['logging_exclude_admin_users'] = empty($input['logging_exclude_admin_users']) ? 0 : 1;
        }
        if (isset($input['retention_days'])) {
            $settings['retention_days'] = in_array((int) $input['retention_days'], [30, 60, 90], true) ? (int) $input['retention_days'] : (int) $settings['retention_days'];
        }

        if (isset($input['exclude_categories']) && is_array($input['exclude_categories'])) {
            $settings['exclude_categories'] = array_map('intval', $input['exclude_categories']);
        }
        if (array_key_exists('show_popular_on_no_results', $input)) {
            $settings['show_popular_on_no_results'] = empty($input['show_popular_on_no_results']) ? 0 : 1;
        }
        if (isset($input['popular_page_ids'])) {
            $settings['popular_page_ids'] = $this->sanitize_comma_separated_ids($input['popular_page_ids']);
        }

        if (array_key_exists('related_module_enabled', $input)) {
            $settings['related_module_enabled'] = empty($input['related_module_enabled']) ? 0 : 1;
        }
        if (isset($input['related_title'])) {
            $settings['related_title'] = sanitize_text_field($input['related_title']);
        }
        if (isset($input['related_margin_top'])) {
            $settings['related_margin_top'] = sanitize_text_field($input['related_margin_top']);
        }
        if (isset($input['related_margin_bottom'])) {
            $settings['related_margin_bottom'] = sanitize_text_field($input['related_margin_bottom']);
        }
        if (array_key_exists('related_show_bullets', $input)) {
            $settings['related_show_bullets'] = empty($input['related_show_bullets']) ? 0 : 1;
        }
        if (isset($input['related_post_types']) && is_array($input['related_post_types'])) {
            $settings['related_post_types'] = array_map('sanitize_key', $input['related_post_types']);
        }
        if (array_key_exists('related_context_single', $input)) {
            $settings['related_context_single'] = empty($input['related_context_single']) ? 0 : 1;
        }
        if (array_key_exists('related_context_archives', $input)) {
            $settings['related_context_archives'] = empty($input['related_context_archives']) ? 0 : 1;
        }
        if (array_key_exists('related_context_search', $input)) {
            $settings['related_context_search'] = empty($input['related_context_search']) ? 0 : 1;
        }
        if (array_key_exists('related_context_homepage', $input)) {
            $settings['related_context_homepage'] = empty($input['related_context_homepage']) ? 0 : 1;
        }
        if (isset($input['related_exclude_posts'])) {
            $settings['related_exclude_posts'] = $this->sanitize_comma_separated_ids($input['related_exclude_posts']);
        }
        if (array_key_exists('related_click_logging_enabled', $input)) {
            $settings['related_click_logging_enabled'] = empty($input['related_click_logging_enabled']) ? 0 : 1;
        }
        if (array_key_exists('related_click_exclude_admin_users', $input)) {
            $settings['related_click_exclude_admin_users'] = empty($input['related_click_exclude_admin_users']) ? 0 : 1;
        }
        if (isset($input['related_limit'])) {
            $settings['related_limit'] = max(1, (int) $input['related_limit']);
        }

        if (isset($input['related_tags_weight'])) {
            $settings['related_tags_weight'] = max(0, (int) $input['related_tags_weight']);
        }
        if (isset($input['related_content_weight'])) {
            $settings['related_content_weight'] = max(0, (int) $input['related_content_weight']);
        }
        if (isset($input['related_phrase_bonus'])) {
            $settings['related_phrase_bonus'] = max(0, (int) $input['related_phrase_bonus']);
        }
        if (isset($input['related_ilj_priority'])) {
            $settings['related_ilj_priority'] = in_array($input['related_ilj_priority'], ['off', 'normal', 'high'], true) ? $input['related_ilj_priority'] : 'high';
        }
        if (isset($input['related_min_score'])) {
            $settings['related_min_score'] = max(0, (int) $input['related_min_score']);
        }
        if (isset($input['related_min_shared_tags'])) {
            $settings['related_min_shared_tags'] = max(0, (int) $input['related_min_shared_tags']);
        }
        if (array_key_exists('related_featured_enabled', $input)) {
            $settings['related_featured_enabled'] = empty($input['related_featured_enabled']) ? 0 : 1;
        }
        if (isset($input['related_featured_text'])) {
            $settings['related_featured_text'] = sanitize_text_field($input['related_featured_text']);
        }
        if (isset($input['related_featured_post_id'])) {
            $settings['related_featured_post_id'] = max(0, (int) $input['related_featured_post_id']);
        }

        $related_cache_fields = [
            'related_module_enabled', 'related_limit', 'related_tags_weight', 'related_content_weight',
            'related_phrase_bonus', 'related_ilj_priority', 'related_min_score', 'related_min_shared_tags',
            'related_post_types', 'related_exclude_posts', 'exclude_categories',
            'stopwords', 'min_word_length', 'content_types'
        ];
        foreach ($related_cache_fields as $cache_field) {
            if (array_key_exists($cache_field, $input)) {
                $this->bump_related_cache_version();
                break;
            }
        }

        $lang = $this->current_language();
        if (isset($input['synonyms_current'])) {
            $settings['synonyms'][$lang] = sanitize_textarea_field($input['synonyms_current']);
        }

        return $settings;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $meta = get_option(self::OPTION_INDEX_META, []);
        $post_types = get_post_types(['public' => true], 'objects');
        $categories = get_categories(['hide_empty' => false]);
        $lang = $this->current_language();
        $active_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'search';
        if (!in_array($active_tab, ['search', 'related'], true)) {
            $active_tab = 'search';
        }
        ?>
        <div class="wrap">
            <h1>WP Search Relevance - Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpsr-settings&tab=search')); ?>" class="nav-tab <?php echo $active_tab === 'search' ? 'nav-tab-active' : ''; ?>">Zoekresultaten</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpsr-settings&tab=related')); ?>" class="nav-tab <?php echo $active_tab === 'related' ? 'nav-tab-active' : ''; ?>">Relevante artikelen</a>
            </h2>
            <form method="post" action="options.php">
                <?php settings_fields('wpsr_settings_group'); ?>
                <?php if ($active_tab === 'search') : ?>
                <h2>Indexatie</h2>
                <p>Selecteer contenttypes:</p>
                <?php foreach ($post_types as $post_type) : ?>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[content_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $settings['content_types'], true)); ?>> <?php echo esc_html($post_type->label); ?></label><br>
                <?php endforeach; ?>

                <h2>Wegingen</h2>
                <?php foreach ($settings['weights'] as $key => $value) : ?>
                    <label><?php echo esc_html($key); ?>: <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[weights][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $value); ?>"></label><br>
                <?php endforeach; ?>

                <h2>Zoek instellingen</h2>
                <label>Stopwoorden<br><textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[stopwords]" rows="4" cols="60"><?php echo esc_textarea($settings['stopwords']); ?></textarea></label><br>
                <label>Minimum woordlengte <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[min_word_length]" value="<?php echo esc_attr((string) $settings['min_word_length']); ?>"></label><br>
                <label>Resultaten per pagina <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[results_per_page]" value="<?php echo esc_attr((string) $settings['results_per_page']); ?>"></label><br>
                <label>Resultaatmode
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[result_mode]">
                        <option value="pagination" <?php selected($settings['result_mode'], 'pagination'); ?>>Paginering</option>
                        <option value="infinite" <?php selected($settings['result_mode'], 'infinite'); ?>>Infinite scroll</option>
                    </select>
                </label>

                <h2>Logging</h2>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[logging_enabled]" value="1" <?php checked((int) $settings['logging_enabled'], 1); ?>> Logging inschakelen</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[logging_exclude_admin_users]" value="1" <?php checked((int) ($settings['logging_exclude_admin_users'] ?? 0), 1); ?>> Kliks/zoeklogs van ingelogde admins niet meetellen</label><br>
                <label>Retentie
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[retention_days]">
                        <option value="30" <?php selected((int) $settings['retention_days'], 30); ?>>30 dagen</option>
                        <option value="60" <?php selected((int) $settings['retention_days'], 60); ?>>60 dagen</option>
                        <option value="90" <?php selected((int) $settings['retention_days'], 90); ?>>90 dagen</option>
                    </select>
                </label>

                <h2>Uitsluiten</h2>
                <?php foreach ($categories as $category) : ?>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_categories][]" value="<?php echo (int) $category->term_id; ?>" <?php checked(in_array((int) $category->term_id, $settings['exclude_categories'], true)); ?>> <?php echo esc_html($category->name); ?></label><br>
                <?php endforeach; ?>

                <h2>No results</h2>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_popular_on_no_results]" value="1" <?php checked((int) $settings['show_popular_on_no_results'], 1); ?>> Toon populaire pagina's</label><br>
                <label>Populaire pagina IDs (komma-gescheiden): <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[popular_page_ids]" value="<?php echo esc_attr(implode(',', $settings['popular_page_ids'])); ?>"></label>

                <h2>Synoniemen (taal: <?php echo esc_html($lang); ?>)</h2>
                <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[synonyms_current]" rows="4" cols="60"><?php echo esc_textarea($settings['synonyms'][$lang] ?? ''); ?></textarea>


                <?php endif; ?>

                <?php if ($active_tab === 'related') : ?>
                <h2>Relevante artikelen module</h2>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_module_enabled]" value="1" <?php checked((int) $settings['related_module_enabled'], 1); ?>> Module inschakelen</label><br>
                <label>Titeltekst <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_title]" value="<?php echo esc_attr((string) $settings['related_title']); ?>"></label><br>
                <label>Margin boven <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_margin_top]" value="<?php echo esc_attr((string) $settings['related_margin_top']); ?>"></label><br>
                <label>Margin onder <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_margin_bottom]" value="<?php echo esc_attr((string) $settings['related_margin_bottom']); ?>"></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_show_bullets]" value="1" <?php checked((int) $settings['related_show_bullets'], 1); ?>> Bullets tonen</label><br>
                <p>Actieve posttypes:</p>
                <?php foreach ($post_types as $post_type) : ?>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $settings['related_post_types'], true)); ?>> <?php echo esc_html($post_type->label); ?></label><br>
                <?php endforeach; ?>
                <p>Actief op context:</p>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_context_single]" value="1" <?php checked((int) $settings['related_context_single'], 1); ?>> single</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_context_archives]" value="1" <?php checked((int) $settings['related_context_archives'], 1); ?>> archives</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_context_search]" value="1" <?php checked((int) $settings['related_context_search'], 1); ?>> search</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_context_homepage]" value="1" <?php checked((int) $settings['related_context_homepage'], 1); ?>> homepage</label><br>
                <label>Uitgesloten post/page IDs (komma-gescheiden): <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_exclude_posts]" value="<?php echo esc_attr(implode(',', $settings['related_exclude_posts'])); ?>"></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_click_logging_enabled]" value="1" <?php checked((int) $settings['related_click_logging_enabled'], 1); ?>> Klik logging inschakelen (anoniem)</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_click_exclude_admin_users]" value="1" <?php checked((int) ($settings['related_click_exclude_admin_users'] ?? 0), 1); ?>> Kliks van ingelogde admins niet meetellen</label><br>
                <label>Aantal relevante artikelen <input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_limit]" value="<?php echo esc_attr((string) ($settings['related_limit'] ?? 2)); ?>"></label><br>

                <h3>Relevantie instellingen – Artikel suggesties</h3>
                <label>Tags gewicht <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_tags_weight]" value="<?php echo esc_attr((string) ($settings['related_tags_weight'] ?? 60)); ?>"></label><br>
                <label>Content gewicht <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_content_weight]" value="<?php echo esc_attr((string) ($settings['related_content_weight'] ?? 40)); ?>"></label><br>
                <label>Phrase bonus <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_phrase_bonus]" value="<?php echo esc_attr((string) ($settings['related_phrase_bonus'] ?? 25)); ?>"></label><br>
                <label>Internal Link Juicer prioriteit
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_ilj_priority]">
                        <option value="off" <?php selected(($settings['related_ilj_priority'] ?? 'high'), 'off'); ?>>Uit</option>
                        <option value="normal" <?php selected(($settings['related_ilj_priority'] ?? 'high'), 'normal'); ?>>Normale boost</option>
                        <option value="high" <?php selected(($settings['related_ilj_priority'] ?? 'high'), 'high'); ?>>Hoge prioriteit</option>
                    </select>
                </label><br>
                <label>Minimum relevantie score <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_min_score]" value="<?php echo esc_attr((string) ($settings['related_min_score'] ?? 50)); ?>"></label><br>
                <label>Minimaal gedeelde tags vereist <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_min_shared_tags]" value="<?php echo esc_attr((string) ($settings['related_min_shared_tags'] ?? 1)); ?>"></label><br>

                <h3>Extra artikel onder de aandacht</h3>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_featured_enabled]" value="1" <?php checked((int) ($settings['related_featured_enabled'] ?? 0), 1); ?>> Extra artikel tonen onder relevante artikelen</label><br>
                <label>Tekstblok <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_featured_text]" value="<?php echo esc_attr((string) ($settings['related_featured_text'] ?? 'Bekijk onze aanbieding:')); ?>"></label><br>
                <label>Artikel ID <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_KEY); ?>[related_featured_post_id]" value="<?php echo esc_attr((string) ($settings['related_featured_post_id'] ?? 0)); ?>"></label><br>

                <?php endif; ?>

                <?php submit_button(); ?>
            </form>

            <h2>Index beheer</h2>
            <?php wp_nonce_field('wpsr_index_nonce', 'wpsr_index_nonce_field'); ?>
            <button class="button button-primary" id="wpsr-start-index">Start indexatie</button>
            <button class="button" id="wpsr-clear-index">Wis index en herindexeer</button>
            <div id="wpsr-progress" style="margin-top:10px;width:400px;max-width:100%;background:#eee;height:18px;position:relative;"><span style="display:block;height:18px;width:0;background:#2271b1;"></span></div>
            <p id="wpsr-status"></p>
            <p>
                Laatste indexatie: <?php echo esc_html($meta['last_indexed'] ?? '-'); ?><br>
                Index versie: <?php echo esc_html(get_option(self::OPTION_INDEX_VERSION, '1')); ?><br>
                Geïndexeerd per type: <?php echo esc_html(wp_json_encode($meta['counts'] ?? [])); ?>
            </p>

            <h2>FAQ</h2>
            <ul>
                <li><strong>Indexatie starten/herindexeren:</strong> gebruik de knoppen hierboven; batchgrootte is 50 via AJAX.</li>
                <li><strong>Wegingen aanpassen:</strong> wijzig de waardes in sectie Wegingen en sla op.</li>
                <li><strong>WPML:</strong> index, synoniemen en logging worden per taal opgeslagen.</li>
                <li><strong>Logging/retentie:</strong> stel retentie in op 30, 60 of 90 dagen en beheer in Logging menu.</li>
                <li><strong>Admin logging uitsluiten:</strong> je kunt per tab instellen dat kliks/logs van ingelogde admins niet meetellen.</li>
                <li><strong>Shortcode categorie filter:</strong> gebruik <code>[wpsr_category_filter]</code> op zoekresultaatpagina.</li>

                <li><strong>Relevante module aan/uit:</strong> schakel in of uit in de settings.</li>
                <li><strong>Plaatsing:</strong> gebruik shortcode <code>[wpsr_related_articles]</code> op de gewenste plek in de content.</li>
                <li><strong>WPML gedrag:</strong> suggesties komen alleen uit de actieve taal.</li>
                <li><strong>Waarom soms geen blok:</strong> er worden alleen relevante resultaten getoond, anders geen output.</li>
                <li><strong>Klik logging:</strong> slaat datum/taal/bron/doel op zonder IP of persoonsgegevens.</li>
                <li><strong>Relevantie artikel suggesties:</strong> stel tags/content gewichten, ILJ-prioriteit, minimumscore en minimale tag-overlap in onder tab Relevante artikelen.</li>
                <li><strong>Extra artikel:</strong> zet een extra uitgelicht artikel aan/uit en kies tekst + artikel-ID in de tab Relevante artikelen.</li>

                <li><strong>Fallback bij lege index:</strong> plugin valt terug op standaard WP zoekquery.</li>
            </ul>
        </div>
        <script>
            (function($){
                function processBatches(total, done){
                    $.post(ajaxurl, {action:'wpsr_index_batch', nonce: $('#wpsr_index_nonce_field').val()}, function(resp){
                        if (!resp.success){ $('#wpsr-status').text(resp.data || 'Fout tijdens batch'); return; }
                        done = resp.data.done;
                        var pct = total ? Math.round((done/total)*100) : 100;
                        $('#wpsr-progress span').css('width', pct + '%');
                        $('#wpsr-status').text('Geïndexeerd: ' + done + ' / ' + total);
                        if (!resp.data.finished) { processBatches(total, done); }
                        else { $('#wpsr-status').text('Indexatie voltooid ('+done+' items)'); }
                    });
                }
                $('#wpsr-start-index').on('click', function(e){
                    e.preventDefault();
                    $.post(ajaxurl, {action:'wpsr_prepare_index', nonce: $('#wpsr_index_nonce_field').val()}, function(resp){
                        if (!resp.success){ $('#wpsr-status').text(resp.data || 'Fout bij voorbereiden'); return; }
                        $('#wpsr-progress span').css('width', '0%');
                        processBatches(resp.data.total, 0);
                    });
                });
                $('#wpsr-clear-index').on('click', function(e){
                    e.preventDefault();
                    $.post(ajaxurl, {action:'wpsr_clear_index', nonce: $('#wpsr_index_nonce_field').val()}, function(resp){
                        if (!resp.success){ $('#wpsr-status').text(resp.data || 'Fout bij wissen index'); return; }
                        $('#wpsr-start-index').trigger('click');
                    });
                });
            })(jQuery);
        </script>
        <?php
    }

    public function render_logging_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $this->logs_table();
        $top = $wpdb->get_results("SELECT search_term, COUNT(*) AS cnt FROM `{$table}` GROUP BY search_term ORDER BY cnt DESC LIMIT 10", ARRAY_A);
        $no_results = $wpdb->get_results("SELECT search_term, COUNT(*) AS cnt FROM `{$table}` WHERE result_count = 0 GROUP BY search_term ORDER BY cnt DESC LIMIT 10", ARRAY_A);
        $per_lang = $wpdb->get_results("SELECT lang, COUNT(*) AS cnt FROM `{$table}` GROUP BY lang ORDER BY cnt DESC", ARRAY_A);
        $rel_table = $this->related_clicks_table();
        $clicks_target = $wpdb->get_results("SELECT target_post_id, COUNT(*) AS cnt FROM `{$rel_table}` WHERE is_human = 1 GROUP BY target_post_id ORDER BY cnt DESC LIMIT 10", ARRAY_A);
        $clicks_source = $wpdb->get_results("SELECT source_post_id, COUNT(*) AS cnt FROM `{$rel_table}` WHERE is_human = 1 GROUP BY source_post_id ORDER BY cnt DESC LIMIT 10", ARRAY_A);
        $click_pairs = $wpdb->get_results("SELECT source_post_id, target_post_id, COUNT(*) AS cnt FROM `{$rel_table}` WHERE is_human = 1 GROUP BY source_post_id, target_post_id ORDER BY cnt DESC LIMIT 10", ARRAY_A);
        $clicks_target = is_array($clicks_target) ? $clicks_target : [];
        $clicks_source = is_array($clicks_source) ? $clicks_source : [];
        $click_pairs = is_array($click_pairs) ? $click_pairs : [];
        ?>
        <div class="wrap">
            <h1>WP Search Relevance - Logging</h1>
            <?php wp_nonce_field('wpsr_logs_nonce', 'wpsr_logs_nonce_field'); ?>
            <button class="button" id="wpsr-clear-logs">Wis alle logs</button>
            <button class="button" id="wpsr-clear-old-logs">Wis logs ouder dan retentieperiode</button>
            <button class="button" id="wpsr-clear-related-clicks">Wis klik logs relevante artikelen</button>

            <h2>Top zoektermen</h2>
            <ul><?php foreach ($top as $row) { echo '<li>' . esc_html($row['search_term']) . ' (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

            <h2>Zoektermen zonder resultaten</h2>
            <ul><?php foreach ($no_results as $row) { echo '<li>' . esc_html($row['search_term']) . ' (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

            <h2>Resultaten per taal</h2>
            <ul><?php foreach ($per_lang as $row) { echo '<li>' . esc_html($row['lang']) . ' (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

            <h2>Kliks per doel artikel (relevante module)</h2>
            <ul><?php foreach ($clicks_target as $row) { echo '<li>' . esc_html(get_the_title((int) $row['target_post_id'])) . ' (#' . (int) $row['target_post_id'] . ') (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

            <h2>Kliks per bron artikel (relevante module)</h2>
            <ul><?php foreach ($clicks_source as $row) { echo '<li>' . esc_html(get_the_title((int) $row['source_post_id'])) . ' (#' . (int) $row['source_post_id'] . ') (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

            <h2>Top doorgestuurde combinaties</h2>
            <ul><?php foreach ($click_pairs as $row) { echo '<li>Bron #' . (int) $row['source_post_id'] . ' → Doel #' . (int) $row['target_post_id'] . ' (' . (int) $row['cnt'] . ')</li>'; } ?></ul>

        </div>
        <script>
            (function($){
                $('#wpsr-clear-logs').on('click', function(){
                    $.post(ajaxurl, {action:'wpsr_clear_logs', nonce: $('#wpsr_logs_nonce_field').val()}, function(){ location.reload(); });
                });
                $('#wpsr-clear-old-logs').on('click', function(){
                    $.post(ajaxurl, {action:'wpsr_clear_old_logs', nonce: $('#wpsr_logs_nonce_field').val()}, function(){ location.reload(); });
                });
                $('#wpsr-clear-related-clicks').on('click', function(){
                    $.post(ajaxurl, {action:'wpsr_clear_related_click_logs', nonce: $('#wpsr_logs_nonce_field').val()}, function(){ location.reload(); });
                });
            })(jQuery);
        </script>
        <?php
    }

    public function register_meta_box()
    {
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_meta_box('wpsr_exclude', 'WP Search Relevance', [$this, 'render_meta_box'], $post_type, 'side');
        }
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('wpsr_meta_nonce', 'wpsr_meta_nonce_field');
        $value = (int) get_post_meta($post->ID, self::META_EXCLUDE, true);
        echo '<label><input type="checkbox" name="wpsr_exclude_from_index" value="1" ' . checked($value, 1, false) . '> Uitsluiten van zoekindex</label>';
    }

    public function save_post_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Verify nonce for security
        $nonce = isset($_POST['wpsr_meta_nonce_field']) ? sanitize_text_field(wp_unslash($_POST['wpsr_meta_nonce_field'])) : '';
        if (!wp_verify_nonce($nonce, 'wpsr_meta_nonce')) {
            return;
        }

        // Sanitize checkbox input
        $exclude = isset($_POST['wpsr_exclude_from_index']) ? (int) sanitize_text_field(wp_unslash($_POST['wpsr_exclude_from_index'])) : 0;
        $exclude = ($exclude === 1) ? 1 : 0;
        update_post_meta($post_id, self::META_EXCLUDE, $exclude);
    }


    public function maybe_reindex_on_save($post_id, $post, $update)
    {
        if ($post instanceof WP_Post) {
            $post_obj = $post;
        } else {
            $post_obj = get_post($post_id);
        }

        if (!$post_obj) {
            return;
        }

        if ($post_obj->post_type === 'revision' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ((string) $post_obj->post_status === 'publish') {
            $this->index_single_post((int) $post_id);
        }
    }

    public function maybe_reindex_on_status_transition($new_status, $old_status, $post)
    {
        if (!($post instanceof WP_Post)) {
            return;
        }

        if ($post->post_type === 'revision') {
            return;
        }

        if ($new_status === 'publish') {
            $this->index_single_post((int) $post->ID);
            return;
        }

        if ($old_status === 'publish' && $new_status !== 'publish') {
            $this->remove_post_from_index((int) $post->ID);
        }
    }

    public function maybe_remove_from_index_on_delete($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->remove_post_from_index($post_id);
    }

    public function ajax_prepare_index()
    {
        $this->guard_ajax('wpsr_index_nonce');
        $settings = $this->get_settings();

        $query = new WP_Query([
            'post_type' => $settings['content_types'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        update_option(self::OPTION_QUEUE, [
            'ids' => $query->posts,
            'done' => 0,
            'total' => count($query->posts),
        ]);

        $this->bump_related_cache_version();

        wp_send_json_success(['total' => count($query->posts)]);
    }

    public function ajax_index_batch()
    {
        $this->guard_ajax('wpsr_index_nonce');
        $queue = get_option(self::OPTION_QUEUE, ['ids' => [], 'done' => 0, 'total' => 0]);
        $batch = array_splice($queue['ids'], 0, 50);

        foreach ($batch as $post_id) {
            $this->index_single_post((int) $post_id);
            $queue['done']++;
        }

        $queue['ids'] = array_values($queue['ids']);
        update_option(self::OPTION_QUEUE, $queue);

        $finished = empty($queue['ids']);
        if ($finished) {
            $this->update_index_meta();
            $this->bump_related_cache_version();
        }

        wp_send_json_success([
            'done' => (int) $queue['done'],
            'finished' => $finished,
        ]);
    }

    public function ajax_clear_index()
    {
        $this->guard_ajax('wpsr_index_nonce');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$this->index_table()}`");
        $this->bump_related_cache_version();
        wp_send_json_success(true);
    }

    public function ajax_clear_logs()
    {
        $this->guard_ajax('wpsr_logs_nonce');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$this->logs_table()} `");
        wp_send_json_success(true);
    }

    public function ajax_clear_old_logs()
    {
        $this->guard_ajax('wpsr_logs_nonce');
        global $wpdb;
        $settings = $this->get_settings();
        $date = gmdate('Y-m-d H:i:s', strtotime('-' . (int) $settings['retention_days'] . ' days'));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$this->logs_table()}` WHERE searched_at < %s", $date));
        wp_send_json_success(true);
    }

    public function ajax_clear_related_click_logs()
    {
        $this->guard_ajax('wpsr_logs_nonce');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$this->related_clicks_table()}`");
        wp_send_json_success(true);
    }

    public function intercept_search_query($query)
    {
        if (is_admin() || !($query instanceof WP_Query) || !$query->is_search()) {
            return;
        }

        if ((int) $query->get('suppress_filters') === 1) {
            return;
        }

        $term = (string) $query->get('s');
        if (trim($term) === '') {
            return;
        }

        $ids = $this->find_ranked_posts($term);
        if (empty($ids)) {
            return;
        }

        $settings = $this->get_settings();
        $query->set('post__in', $ids);
        $query->set('orderby', 'post__in');
        $query->set('ignore_sticky_posts', true);
        $query->set('post_type', $settings['content_types']);
        $query->set('wpsr_ranked', 1);

        // Ensure main search page uses configured page size; keep custom secondary queries untouched.
        if ($query->is_main_query()) {
            $query->set('posts_per_page', max(1, (int) $settings['results_per_page']));
        } elseif ((int) $query->get('posts_per_page') <= 0) {
            $query->set('posts_per_page', (int) $settings['results_per_page']);
        }

        $cat_filter = isset($_GET['wpsr_cat']) ? (int) $_GET['wpsr_cat'] : 0;
        $skip_cat_filter = (int) $query->get('wpsr_skip_cat_filter') === 1;
        if ($cat_filter > 0 && !$skip_cat_filter) {
            // Strictly filter within the ranked result set so behavior is consistent across themes/builders.
            $filtered_ids = [];
            foreach ($ids as $ranked_id) {
                if (has_term($cat_filter, 'category', (int) $ranked_id)) {
                    $filtered_ids[] = (int) $ranked_id;
                }
            }

            $query->set('post__in', !empty($filtered_ids) ? $filtered_ids : [0]);
            $query->set('orderby', 'post__in');
            $query->set('category__in', []);
            $query->set('cat', '');
        }
    }

    private function find_ranked_posts($term, $strict = true)
    {
        global $wpdb;
        $settings = $this->get_settings();
        $lang = $this->current_language();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$this->index_table()}` WHERE lang = %s", $lang), ARRAY_A);

        if (empty($rows)) {
            return [];
        }

        $tokens = $this->tokenize($term, $settings['stopwords'], (int) $settings['min_word_length']);
        $tokens = $this->correct_query_tokens($tokens, $rows);
        $phrase = $this->strtolower(trim($term));
        $normalized_phrase = $this->normalize_text($phrase);
        $expanded_tokens = $this->expand_tokens($tokens);
        $query_tokens = array_values(array_unique(array_merge($tokens, $expanded_tokens)));

        if (empty($query_tokens) && $normalized_phrase === '') {
            return [];
        }

        $token_doc_frequency = [];
        $doc_count = count($rows);

        foreach ($query_tokens as $token) {
            $token_doc_frequency[$token] = 0;
        }

        foreach ($rows as $row) {
            $doc_text = $this->strtolower(
                (string) $row['post_title'] . ' ' .
                (string) $row['post_excerpt'] . ' ' .
                (string) $row['post_content'] . ' ' .
                (string) $row['tags'] . ' ' .
                (string) $row['categories']
            );

            foreach ($query_tokens as $token) {
                if ($token !== '' && $this->strpos($doc_text, $token) !== false) {
                    $token_doc_frequency[$token]++;
                }
            }
        }

        $scores = [];

        foreach ($rows as $row) {
            $score = 0;
            $haystacks = [
                'content' => $this->strtolower((string) $row['post_content']),
                'title' => $this->strtolower((string) $row['post_title']),
                'excerpt' => $this->strtolower((string) $row['post_excerpt']),
                'tags' => $this->strtolower((string) $row['tags']),
                'categories' => $this->strtolower((string) $row['categories']),
            ];

            $matched_original_tokens = [];
            foreach ($query_tokens as $token) {
                if ($token === '') {
                    continue;
                }

                $tf = 0;
                $tf += substr_count($haystacks['title'], $token) * 3.0;
                $tf += substr_count($haystacks['excerpt'], $token) * 2.0;
                $tf += substr_count($haystacks['content'], $token) * 1.0;
                $tf += substr_count($haystacks['tags'], $token) * 2.5;
                $tf += substr_count($haystacks['categories'], $token) * 1.2;

                if ($tf <= 0) {
                    continue;
                }

                $df = max(1, (int) ($token_doc_frequency[$token] ?? 1));
                $idf = log(1 + (($doc_count + 1) / $df));
                $score += log(1 + $tf) * 100 * $idf;

                if (in_array($token, $tokens, true)) {
                    $matched_original_tokens[$token] = true;
                }
            }

            $coverage = 0.0;
            if (!empty($tokens)) {
                $coverage = count($matched_original_tokens) / count($tokens);
                $score += 350 * $coverage;
                if ($coverage < 1) {
                    $score -= (1 - $coverage) * 140;
                } else {
                    $score += 260;
                }
            }

            $phrase_in_content = $phrase !== '' && $this->strpos($haystacks['content'], $phrase) !== false;
            $phrase_in_title = $phrase !== '' && $this->strpos($haystacks['title'], $phrase) !== false;
            $phrase_in_excerpt = $phrase !== '' && $this->strpos($haystacks['excerpt'], $phrase) !== false;
            $phrase_in_tags = $phrase !== '' && $this->strpos($haystacks['tags'], $phrase) !== false;
            $phrase_in_categories = $phrase !== '' && $this->strpos($haystacks['categories'], $phrase) !== false;
            $direct_phrase_match = $phrase_in_content || $phrase_in_title || $phrase_in_excerpt || $phrase_in_tags || $phrase_in_categories;

            if ($phrase_in_content) {
                $score += (float) $settings['weights']['exact_phrase_bonus'] + 120;
            }

            if ($phrase_in_title) {
                $score += (float) $settings['weights']['exact_title_bonus'] + 200;
            }

            if ($phrase !== '' && trim($haystacks['title']) === $phrase) {
                $score += (float) $settings['weights']['exact_title_bonus'] + 350;
            }

            if (!empty($tokens) && $this->matches_ordered_tokens($haystacks['title'], $tokens)) {
                $score += (float) $settings['weights']['exact_phrase_bonus'] + 140;
            }

            if (!empty($tokens) && $this->matches_ordered_tokens($haystacks['content'], $tokens)) {
                $score += (float) $settings['weights']['exact_phrase_bonus'] + 70;
            }

            $normalized_title = $this->normalize_text($haystacks['title']);
            if ($normalized_phrase !== '' && $normalized_title !== '') {
                $title_similarity = $this->string_similarity($normalized_phrase, $normalized_title);
                $score += 280 * $title_similarity;
            }

            if (!empty($tokens)) {
                $title_coverage = $this->token_coverage($normalized_title, $tokens);
                $score += 260 * $title_coverage;
            }

            if ($row['post_type'] === 'post') {
                $score *= (float) $settings['weights']['post_multiplier'];
            } elseif ($row['post_type'] === 'page') {
                $score *= (float) $settings['weights']['page_multiplier'];
            }

            $ilj_keywords = $this->strtolower((string) $row['ilj_keywords']);
            $ilj_priority = 0;
            if ($phrase !== '' && $ilj_keywords !== '' && $this->strpos($ilj_keywords, $phrase) !== false) {
                $ilj_priority = 1;
                $score += 100000;
            }

            $token_count = count($tokens);
            if ($token_count >= 4) {
                $required_token_matches = max(2, (int) ceil($token_count * 0.6));
                $min_coverage = 0.6;
            } elseif ($token_count >= 2) {
                $required_token_matches = 2;
                $min_coverage = 0.5;
            } else {
                $required_token_matches = 1;
                $min_coverage = 0.25;
            }
            $matched_token_count = count($matched_original_tokens);
            if ($strict) {
                if (!empty($tokens) && !$direct_phrase_match && $ilj_priority === 0 && $matched_token_count < $required_token_matches) {
                    continue;
                }
                if (!empty($tokens) && !$direct_phrase_match && $ilj_priority === 0 && $coverage < $min_coverage) {
                    continue;
                }
            }

            if ($score > 0) {
                $scores[(int) $row['post_id']] = [
                    'score' => $score,
                    'date' => get_post_field('post_date', (int) $row['post_id']),
                    'ilj' => $ilj_priority,
                ];
            }
        }

        uasort($scores, function ($a, $b) {
            if ($a['ilj'] !== $b['ilj']) {
                return $b['ilj'] <=> $a['ilj'];
            }
            if ($a['score'] === $b['score']) {
                return strcmp((string) $b['date'], (string) $a['date']);
            }
            return $b['score'] <=> $a['score'];
        });

        return array_keys($scores);
    }

    private function tokenize($term, $stopwords, $min_len)
    {
        $stop = array_filter(array_map('trim', explode("\n", $this->strtolower((string) $stopwords))));
        $parts = preg_split('/\s+/u', $this->strtolower($term));
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B.,;:!?\"'()[]{}");
            if ($this->strlen($part) < $min_len || in_array($part, $stop, true)) {
                continue;
            }
            $out[] = $part;
        }
        return array_values(array_unique($out));
    }

    private function index_single_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        $settings = $this->get_settings();
        if (!in_array($post->post_type, $settings['content_types'], true)) {
            return;
        }

        if ((int) get_post_meta($post_id, self::META_EXCLUDE, true) === 1) {
            return;
        }

        $post_cat_ids = wp_get_post_categories($post_id);
        if (!empty(array_intersect($post_cat_ids, $settings['exclude_categories']))) {
            return;
        }

        global $wpdb;
        $lang = $this->post_language($post_id);
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $cats = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);

        $ilj_keywords = $this->get_ilj_keywords_for_post($post_id);

        $wpdb->replace(
            $this->index_table(),
            [
                'post_id' => $post_id,
                'lang' => $lang,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'post_excerpt' => $post->post_excerpt,
                'post_content' => wp_strip_all_tags($post->post_content),
                'tags' => implode(' ', $tags),
                'categories' => implode(' ', $cats),
                'ilj_keywords' => implode(' ', $ilj_keywords),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->bump_related_cache_version();
        $this->update_index_meta();
    }


    private function remove_post_from_index($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        global $wpdb;
        $wpdb->delete(
            $this->index_table(),
            ['post_id' => $post_id],
            ['%d']
        );

        $this->bump_related_cache_version();
        $this->update_index_meta();
    }

    private function get_ilj_keywords_for_post($post_id)
    {
        global $wpdb;
        if (!defined('ILJ_PLUGIN_FILE') && !class_exists('ILJ\Database\Linkindex')) {
            return [];
        }

        $possible_tables = [
            $wpdb->prefix . 'ilj_keywordmap',
            $wpdb->prefix . 'internal_link_juicer_keywords',
        ];

        foreach ($possible_tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                $results = $wpdb->get_col($wpdb->prepare("SELECT keyword FROM {$table} WHERE target_id = %d", $post_id));
                return array_map('sanitize_text_field', $results);
            }
        }

        return [];
    }

    public function render_category_filter_shortcode()
    {
        if (!is_search()) {
            return '';
        }

        $search_term = get_search_query();
        if ($search_term === '') {
            return '';
        }

        $current_cat = isset($_GET['wpsr_cat']) ? (int) $_GET['wpsr_cat'] : 0;

        // Always build category counts from the full ranked search result set,
        // not only from the currently paged posts.
        $source_post_ids = array_values(array_map('intval', $this->find_ranked_posts($search_term)));

        if (empty($source_post_ids)) {
            global $wp_query;
            $source_post_ids = array_values(array_map('intval', wp_list_pluck((array) ($wp_query->posts ?? []), 'ID')));
        }

        if (empty($source_post_ids)) {
            return '';
        }

        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => true,
            'object_ids' => $source_post_ids,
        ]);

        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $base_url = get_search_link($search_term);

        $out = '<style>.el-dbe-filterable-categories .el-dbe-post-categories{display:flex;flex-wrap:wrap;gap:10px;margin:0;padding:0;list-style:none}.el-dbe-filterable-categories .el-dbe-post-categories li{margin:0}.el-dbe-filterable-categories .el-dbe-post-categories a{display:inline-block;padding:7px 12px;border:1px solid #d9d9df;border-radius:999px;background:#fff;color:#444;text-decoration:none;line-height:1.2;transition:all .2s ease}.el-dbe-filterable-categories .el-dbe-post-categories a:hover{background:#f6f7fb;border-color:#c9ced8;color:#222}.el-dbe-filterable-categories .el-dbe-post-categories a.el-dbe-active-category{background:#eef2ff;border-color:#b7c5ff;color:#1d2a57;font-weight:600}</style>';
        $out .= '<div class="el-dbe-filterable-categories" data-hamburger-filter="off">';
        $out .= '<ul class="el-dbe-post-categories">';

        $all_class = $current_cat > 0 ? '' : 'el-dbe-active-category';
        $out .= '<li><a href="' . esc_url($base_url) . '" class="' . esc_attr($all_class) . '" data-term-id="-1">Alles</a></li>';

        foreach ($terms as $term) {
            $url = add_query_arg([
                'wpsr_cat' => $term->term_id,
            ], $base_url);
            $active_class = $current_cat === (int) $term->term_id ? 'el-dbe-active-category' : '';
            $out .= '<li><a href="' . esc_url($url) . '" class="' . esc_attr($active_class) . '" data-term-id="' . (int) $term->term_id . '">' . esc_html($term->name) . '</a></li>';
        }

        $out .= '</ul>';
        $out .= '</div>';
        $out .= '<script>(function(){var root=document.currentScript&&document.currentScript.previousElementSibling;if(!root){return;}root.addEventListener("click",function(e){var link=e.target&&e.target.closest("a[data-term-id]");if(!link){return;}e.preventDefault();e.stopPropagation();if(typeof e.stopImmediatePropagation==="function"){e.stopImmediatePropagation();}window.location.href=link.href;},true);})();</script>';

        return $out;
    }


    public function render_related_shortcode($atts = [])
    {
        if (empty($this->get_settings()['related_module_enabled'])) {
            return '';
        }

        $atts = shortcode_atts(['post_id' => 0], (array) $atts, 'wpsr_related_articles');
        $post_id = (int) $atts['post_id'];
        if ($post_id <= 0) {
            $post_id = (int) get_the_ID();
        }
        if ($post_id <= 0) {
            return '';
        }

        return $this->build_related_module_html($post_id);
    }

    public function bypass_canonical_for_related_clicks($redirect_url, $requested_url)
    {
        if (isset($_GET['wpsr_rel_target'], $_GET['wpsr_rel_source'], $_GET['wpsr_rel_nonce'])) {
            return false;
        }

        return $redirect_url;
    }

    public function handle_related_click_redirect()
    {
        if ($this->related_click_handled) {
            return;
        }

        if (!isset($_GET['wpsr_rel_target'], $_GET['wpsr_rel_source'], $_GET['wpsr_rel_nonce'])) {
            return;
        }

        // Sanitize and validate URL parameters
        $target = isset($_GET['wpsr_rel_target']) ? (int) sanitize_text_field(wp_unslash($_GET['wpsr_rel_target'])) : 0;
        $source = isset($_GET['wpsr_rel_source']) ? (int) sanitize_text_field(wp_unslash($_GET['wpsr_rel_source'])) : 0;
        $nonce = isset($_GET['wpsr_rel_nonce']) ? sanitize_text_field(wp_unslash($_GET['wpsr_rel_nonce'])) : '';
        $nonce_ok = $target > 0 && $source > 0 && wp_verify_nonce($nonce, 'wpsr_rel_click_' . $source . '_' . $target);
        if (!$nonce_ok) {
            return;
        }

        $this->related_click_handled = true;

        $settings = $this->get_settings();
        if (!empty($settings['related_click_exclude_admin_users']) && is_user_logged_in() && current_user_can('manage_options')) {
            $url = get_permalink($target);
            if ($url) {
                wp_safe_redirect($url);
                exit;
            }
            return;
        }

        if (!empty($settings['related_click_logging_enabled']) && $this->is_probably_human_click()) {
            global $wpdb;
            $wpdb->insert(
                $this->related_clicks_table(),
                [
                    'source_post_id' => $source,
                    'target_post_id' => $target,
                    'lang' => $this->current_language(),
                    'clicked_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s']
            );
        }

        $url = get_permalink($target);
        if ($url) {
            wp_safe_redirect($url);
            exit;
        }
    }


    private function is_probably_human_request()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($ua === '') {
            return false;
        }

        $bot_pattern = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|skypeuripreview|whatsapp|telegrambot|headless|python|curl|wget|node-fetch|axios|lighthouse|monitor|uptime|preview/i';
        if (preg_match($bot_pattern, $ua)) {
            return false;
        }

        $purpose = isset($_SERVER['HTTP_PURPOSE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_PURPOSE']))) : '';
        $sec_purpose = isset($_SERVER['HTTP_SEC_PURPOSE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_PURPOSE']))) : '';
        if ($purpose === 'prefetch' || $sec_purpose === 'prefetch' || $sec_purpose === 'prerender') {
            return false;
        }

        return true;
    }

    private function is_probably_human_click()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($ua === '') {
            return false;
        }

        $bot_pattern = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|skypeuripreview|whatsapp|telegrambot|headless|python|curl|wget|node-fetch|axios|lighthouse|monitor|uptime|preview/i';
        if (preg_match($bot_pattern, $ua)) {
            return false;
        }

        $purpose = isset($_SERVER['HTTP_PURPOSE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_PURPOSE']))) : '';
        $sec_purpose = isset($_SERVER['HTTP_SEC_PURPOSE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_PURPOSE']))) : '';
        if ($purpose === 'prefetch' || $sec_purpose === 'prefetch' || $sec_purpose === 'prerender') {
            return false;
        }

        return true;
    }

    private function should_show_related_module($post_id)
    {
        $settings = $this->get_settings();
        if (empty($settings['related_module_enabled'])) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }

        if (!in_array($post->post_type, (array) $settings['related_post_types'], true)) {
            return false;
        }

        if (in_array((int) $post_id, array_map('intval', (array) $settings['related_exclude_posts']), true)) {
            return false;
        }

        if (is_singular() && empty($settings['related_context_single'])) {
            return false;
        }
        if (is_archive() && empty($settings['related_context_archives'])) {
            return false;
        }
        if (is_search() && empty($settings['related_context_search'])) {
            return false;
        }
        if (is_home() && empty($settings['related_context_homepage'])) {
            return false;
        }

        return true;
    }

    private function build_related_module_html($post_id)
    {
        if (!$this->should_show_related_module($post_id)) {
            return '';
        }

        $settings = $this->get_settings();
        $limit = max(1, (int) ($settings['related_limit'] ?? 2));
        $related_ids = $this->get_related_post_ids($post_id, $limit);

        $featured_post_id = (int) ($settings['related_featured_post_id'] ?? 0);
        $featured_enabled = !empty($settings['related_featured_enabled']) && $featured_post_id > 0;
        $featured_post = $featured_enabled ? get_post($featured_post_id) : null;
        if (!$featured_post || $featured_post->post_status !== 'publish') {
            $featured_enabled = false;
        }

        if (empty($related_ids) && !$featured_enabled) {
            return '';
        }
        $style = 'border: 1px solid #e5e7eb; padding: 12px 16px 12px 16px; border-radius: 6px; margin: ' .
            sanitize_text_field((string) $settings['related_margin_top']) . ' 0 ' .
            sanitize_text_field((string) $settings['related_margin_bottom']) . ' 0;';
        $style = apply_filters('wpsr_related_box_style', $style, $post_id, $related_ids, $settings);

        $ul_style = empty($settings['related_show_bullets']) ? ' style="list-style:none;padding-left:0;margin:8px 0 0 0;"' : '';

        $html = '<div class="aisum-summary-box" style="' . esc_attr($style) . '">';
        if (!empty($related_ids)) {
            $html .= '<strong>' . esc_html((string) $settings['related_title']) . '</strong>';
            $html .= '<ul' . $ul_style . '>';

            foreach ($related_ids as $rel_id) {
            $url = get_permalink($rel_id);
            if (!empty($settings['related_click_logging_enabled'])) {
                $url = add_query_arg([
                    'wpsr_rel_target' => (int) $rel_id,
                    'wpsr_rel_source' => (int) $post_id,
                    'wpsr_rel_nonce' => wp_create_nonce('wpsr_rel_click_' . (int) $post_id . '_' . (int) $rel_id),
                ], $url);
            }

            $html .= '<li><a href="' . esc_url($url) . '">' . esc_html(get_the_title($rel_id)) . '</a></li>';
        }

            $html .= '</ul>';
        }

        if ($featured_enabled) {
            $featured_url = get_permalink($featured_post_id);
            if (!empty($settings['related_click_logging_enabled'])) {
                $featured_url = add_query_arg([
                    'wpsr_rel_target' => (int) $featured_post_id,
                    'wpsr_rel_source' => (int) $post_id,
                    'wpsr_rel_nonce' => wp_create_nonce('wpsr_rel_click_' . (int) $post_id . '_' . (int) $featured_post_id),
                ], $featured_url);
            }

            $html .= '<strong>' . esc_html((string) ($settings['related_featured_text'] ?? 'Bekijk onze aanbieding:')) . '</strong>';
            $html .= '<ul' . $ul_style . '>';
            $html .= '<li><a href="' . esc_url($featured_url) . '">' . esc_html(get_the_title($featured_post_id)) . '</a></li>';
            $html .= '</ul>';
        }

        $html .= '</div>';

        return (string) apply_filters('wpsr_related_box_html', $html, $post_id, $related_ids, $settings);
    }

    private function get_related_post_ids($post_id, $limit = 2)
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $settings = $this->get_settings();
        $lang = $this->current_language();
        $cache_key = 'wpsr_related_' . (int) get_option(self::OPTION_RELATED_CACHE_VERSION, 1) . '_' . (int) $post_id . '_' . sanitize_key($lang) . '_' . (int) $limit;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $source_tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $source_cats = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
        $source_ilj = $this->get_ilj_keywords_for_post($post_id);
        $source_plain_content = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
        $source_content_tokens = $this->tokenize(
            $source_plain_content,
            $settings['stopwords'],
            (int) $settings['min_word_length']
        );

        $query_parts = [
            (string) $post->post_title,
            (string) $post->post_excerpt,
            implode(' ', (array) $source_tags),
            implode(' ', (array) $source_ilj),
            implode(' ', array_slice($source_content_tokens, 0, 40)),
        ];

        $ranked_ids = $this->find_ranked_posts(implode(' ', array_filter($query_parts)), false);
        if (empty($ranked_ids)) {
            set_transient($cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $source_query_tokens = $this->tokenize(
            implode(' ', array_slice($source_content_tokens, 0, 25)),
            $settings['stopwords'],
            (int) $settings['min_word_length']
        );
        if (empty($source_query_tokens)) {
            $source_query_tokens = $this->tokenize(
                implode(' ', array_filter([
                    (string) $post->post_title,
                    (string) $post->post_excerpt,
                    implode(' ', (array) $source_tags),
                    implode(' ', (array) $source_ilj),
                ])),
                $settings['stopwords'],
                (int) $settings['min_word_length']
            );
        }

        $tags_weight = (int) ($settings['related_tags_weight'] ?? 60);
        $content_weight = (int) ($settings['related_content_weight'] ?? 40);
        $phrase_bonus = (int) ($settings['related_phrase_bonus'] ?? 25);
        $ilj_priority = (string) ($settings['related_ilj_priority'] ?? 'high');
        $min_score = (int) ($settings['related_min_score'] ?? 50);
        $min_shared_tags = (int) ($settings['related_min_shared_tags'] ?? 1);
        $effective_min_shared_tags = !empty((array) $source_tags) ? $min_shared_tags : 0;

        $scored = [];
        foreach ($ranked_ids as $candidate_id) {
            $candidate_id = (int) $candidate_id;
            if ($candidate_id === (int) $post_id) {
                continue;
            }

            $candidate = get_post($candidate_id);
            if (!$candidate || $candidate->post_status !== 'publish') {
                continue;
            }
            if (!in_array($candidate->post_type, (array) $settings['related_post_types'], true)) {
                continue;
            }
            if ($this->post_language($candidate_id) !== $lang) {
                continue;
            }

            $cat_ids = wp_get_post_categories($candidate_id);
            if (!empty(array_intersect($cat_ids, (array) $settings['exclude_categories']))) {
                continue;
            }

            $candidate_tags = wp_get_post_terms($candidate_id, 'post_tag', ['fields' => 'names']);
            $candidate_ilj = $this->get_ilj_keywords_for_post($candidate_id);
            $candidate_text = $this->normalize_text(
                (string) $candidate->post_title . ' ' .
                (string) $candidate->post_excerpt . ' ' .
                wp_strip_all_tags(strip_shortcodes((string) $candidate->post_content)) . ' ' .
                implode(' ', (array) $candidate_tags)
            );

            $tag_overlap = count(array_intersect(array_map('strtolower', (array) $source_tags), array_map('strtolower', (array) $candidate_tags)));
            if ($tag_overlap < $effective_min_shared_tags) {
                continue;
            }

            $content_overlap = $this->token_coverage($candidate_text, $source_query_tokens);
            $ilj_overlap = count(array_intersect(array_map('strtolower', (array) $source_ilj), array_map('strtolower', (array) $candidate_ilj)));

            $score = ($tag_overlap * $tags_weight) + ($content_overlap * $content_weight);
            if ($this->matches_ordered_tokens($candidate_text, $source_query_tokens)) {
                $score += $phrase_bonus;
            }

            if ($ilj_priority === 'normal' && $ilj_overlap > 0) {
                $score += 80;
            }

            if ($score < $min_score) {
                continue;
            }

            $primary_category = !empty($cat_ids) ? (int) $cat_ids[0] : 0;
            $scored[] = [
                'id' => $candidate_id,
                'score' => (float) $score,
                'date' => (string) get_post_field('post_date', $candidate_id),
                'ilj' => $ilj_overlap,
                'primary_cat' => $primary_category,
            ];
        }

        if (empty($scored)) {
            $fallback = [];
            foreach ($ranked_ids as $candidate_id) {
                $candidate_id = (int) $candidate_id;
                if ($candidate_id === (int) $post_id) {
                    continue;
                }

                $candidate = get_post($candidate_id);
                if (!$candidate || $candidate->post_status !== 'publish') {
                    continue;
                }
                if (!in_array($candidate->post_type, (array) $settings['related_post_types'], true)) {
                    continue;
                }
                if ($this->post_language($candidate_id) !== $lang) {
                    continue;
                }

                $cat_ids = wp_get_post_categories($candidate_id);
                if (!empty(array_intersect($cat_ids, (array) $settings['exclude_categories']))) {
                    continue;
                }

                $candidate_tags = wp_get_post_terms($candidate_id, 'post_tag', ['fields' => 'names']);
                $tag_overlap = count(array_intersect(array_map('strtolower', (array) $source_tags), array_map('strtolower', (array) $candidate_tags)));
                $cat_overlap = count(array_intersect(array_map('strtolower', (array) $source_cats), array_map('strtolower', wp_get_post_terms($candidate_id, 'category', ['fields' => 'names']))));
                $fallback_score = ($tag_overlap * max(1, (int) $tags_weight)) + ($cat_overlap * 10);

                $fallback[] = [
                    'id' => $candidate_id,
                    'score' => (float) $fallback_score,
                    'date' => (string) get_post_field('post_date', $candidate_id),
                    'ilj' => 0,
                    'primary_cat' => !empty($cat_ids) ? (int) $cat_ids[0] : 0,
                ];
            }

            if (empty($fallback)) {
                set_transient($cache_key, [], HOUR_IN_SECONDS);
                return [];
            }

            usort($fallback, function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    return strcmp($b['date'], $a['date']);
                }
                return $b['score'] <=> $a['score'];
            });

            $scored = $fallback;
        }

        usort($scored, function ($a, $b) use ($ilj_priority) {
            if ($ilj_priority === 'high' && $a['ilj'] !== $b['ilj']) {
                return $b['ilj'] <=> $a['ilj'];
            }
            if ($a['score'] === $b['score']) {
                return strcmp($b['date'], $a['date']);
            }
            return $b['score'] <=> $a['score'];
        });


        $result = array_slice(array_map(function ($r) {
            return (int) $r['id'];
        }, $scored), 0, (int) $limit);

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    public function add_plugin_action_links($links)
    {
        if (!current_user_can('manage_options')) {
            return $links;
        }

        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wpsr-settings')) . '">Settings</a>';
        $logging_link = '<a href="' . esc_url(admin_url('admin.php?page=wpsr-logging')) . '">Logging</a>';

        array_unshift($links, $logging_link);
        array_unshift($links, $settings_link);

        return $links;
    }

    public function log_search()
    {
        if (is_admin() || !is_search() || $this->request_search_logged) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['logging_enabled'])) {
            return;
        }
        if (!$this->is_probably_human_request()) {
            return;
        }
        if (!empty($settings['logging_exclude_admin_users']) && is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }

        $term = trim((string) get_search_query());
        if ($term === '') {
            return;
        }

        $result_ids = $this->find_ranked_posts($term);
        if (empty($result_ids)) {
            global $wp_query;
            $result_ids = wp_list_pluck((array) ($wp_query->posts ?? []), 'ID');
        }

        global $wpdb;
        $wpdb->insert(
            $this->logs_table(),
            [
                'search_term' => $term,
                'lang' => $this->current_language(),
                'result_count' => count((array) $result_ids),
                'result_ids' => implode(',', array_map('intval', (array) $result_ids)),
                'searched_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        $this->request_search_logged = true;
    }

    private function update_index_meta()
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT post_type, COUNT(*) AS cnt FROM `{$this->index_table()}` GROUP BY post_type", ARRAY_A);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['post_type']] = (int) $row['cnt'];
        }

        update_option(self::OPTION_INDEX_META, [
            'last_indexed' => current_time('mysql'),
            'counts' => $counts,
        ]);
    }

    private function guard_ajax($action)
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen rechten.');
        }

        // Rate limiting: max 10 requests per minute per user
        $user_id = get_current_user_id();
        $rate_limit_key = 'wpsr_rate_limit_' . $user_id;
        $current_minute = floor(time() / 60);

        $rate_data = get_transient($rate_limit_key);
        if (!$rate_data) {
            $rate_data = ['minute' => $current_minute, 'count' => 0];
        }

        // Reset counter if we're in a new minute
        if ($rate_data['minute'] !== $current_minute) {
            $rate_data = ['minute' => $current_minute, 'count' => 0];
        }

        // Check rate limit
        if ($rate_data['count'] >= 10) {
            wp_send_json_error('Te veel requests. Probeer over een minuut opnieuw.');
        }

        // Increment counter
        $rate_data['count']++;
        set_transient($rate_limit_key, $rate_data, 120); // Store for 2 minutes

        // Use check_ajax_referer which is WordPress best-practice for AJAX endpoints
        check_ajax_referer($action, 'nonce', true);
    }



    private function correct_query_tokens($tokens, $rows)
    {
        if (empty($tokens) || !function_exists('levenshtein')) {
            return array_values(array_unique(array_filter((array) $tokens)));
        }

        $vocab = [];
        foreach ((array) $rows as $row) {
            $pool = $this->normalize_text(
                (string) ($row['post_title'] ?? '') . ' ' .
                (string) ($row['post_excerpt'] ?? '') . ' ' .
                (string) ($row['tags'] ?? '') . ' ' .
                (string) ($row['categories'] ?? '')
            );
            foreach (explode(' ', $pool) as $word) {
                $word = trim((string) $word);
                if ($this->strlen($word) < 4) {
                    continue;
                }
                $vocab[$word] = true;
            }
        }

        if (empty($vocab)) {
            return array_values(array_unique(array_filter((array) $tokens)));
        }

        $vocab_words = array_keys($vocab);
        $corrected = [];
        foreach ((array) $tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            if (isset($vocab[$token]) || $this->strlen($token) < 5) {
                $corrected[] = $token;
                continue;
            }

            $best = $token;
            $best_dist = 99;
            $token_len = $this->strlen($token);
            foreach ($vocab_words as $candidate) {
                $len = $this->strlen($candidate);
                if (abs($len - $token_len) > 1) {
                    continue;
                }
                if (substr($candidate, 0, 1) !== substr($token, 0, 1)) {
                    continue;
                }

                $dist = levenshtein($token, $candidate);
                $max_dist = $token_len >= 8 ? 2 : 1;
                if ($dist <= $max_dist && $dist < $best_dist) {
                    $best = $candidate;
                    $best_dist = $dist;
                    if ($dist === 1) {
                        break;
                    }
                }
            }

            $corrected[] = $best;
        }

        return array_values(array_unique($corrected));
    }

    private function expand_tokens($tokens)
    {
        $expanded = [];
        foreach ((array) $tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            $expanded[$token] = true;
            foreach (['eren', 'en', 'ing', 'ig', 'te', 'de', 's', 'e'] as $suffix) {
                if ($this->strlen($token) > $this->strlen($suffix) + 3 && substr($token, -$this->strlen($suffix)) === $suffix) {
                    $expanded[substr($token, 0, -$this->strlen($suffix))] = true;
                }
            }
        }

        return array_keys($expanded);
    }

    private function normalize_text($text)
    {
        $text = $this->strtolower((string) $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim((string) $text);
    }

    private function token_coverage($text, $tokens)
    {
        $tokens = array_values(array_filter((array) $tokens));
        if (empty($tokens)) {
            return 0;
        }

        $hits = 0;
        foreach ($tokens as $token) {
            if ($token !== '' && $this->strpos((string) $text, (string) $token) !== false) {
                $hits++;
            }
        }

        return $hits / count($tokens);
    }

    private function string_similarity($a, $b)
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($a === '' || $b === '') {
            return 0;
        }

        similar_text($a, $b, $percent);
        return ((float) $percent) / 100;
    }

    private function matches_ordered_tokens($text, $tokens)
    {
        $tokens = array_values(array_filter(array_map('trim', (array) $tokens)));
        if (empty($tokens)) {
            return false;
        }

        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = preg_quote($token, '/');
        }

        $pattern = '/' . implode('(?:\W+\w+){0,3}\W+', $parts) . '/u';
        return (bool) preg_match($pattern, (string) $text);
    }

    private function strtolower($value)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower((string) $value);
        }

        return strtolower((string) $value);
    }

    private function strpos($haystack, $needle)
    {
        if (function_exists('mb_strpos')) {
            return mb_strpos((string) $haystack, (string) $needle);
        }

        return strpos((string) $haystack, (string) $needle);
    }

    private function strlen($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string) $value);
        }

        return strlen((string) $value);
    }

    private function current_language()
    {
        if (function_exists('apply_filters')) {
            $lang = apply_filters('wpml_current_language', null);
            if (!empty($lang)) {
                return sanitize_key($lang);
            }
        }
        return 'default';
    }

    private function post_language($post_id)
    {
        if (function_exists('apply_filters')) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (is_array($details) && !empty($details['language_code'])) {
                return sanitize_key($details['language_code']);
            }
        }
        return 'default';
    }


    private function bump_related_cache_version()
    {
        $version = (int) get_option(self::OPTION_RELATED_CACHE_VERSION, 1);
        update_option(self::OPTION_RELATED_CACHE_VERSION, $version + 1);
    }

    private function get_settings()
    {
        $settings = get_option(self::OPTION_KEY, []);
        return wp_parse_args($settings, $this->defaults);
    }

    /**
     * Sanitize comma-separated ID list into array of integers.
     * Handles both string and array input.
     *
     * @param mixed $input Comma-separated string or array
     * @return array Array of positive integers
     */
    private function sanitize_comma_separated_ids($input)
    {
        if (empty($input)) {
            return [];
        }
        $input_str = is_array($input) ? implode(',', $input) : (string) $input;
        return array_filter(array_map('intval', explode(',', $input_str)));
    }

    private function index_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpsr_index';
    }

    private function logs_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpsr_logs';
    }

    private function related_clicks_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpsr_related_clicks';
    }
}

new WP_Search_Relevance_Plugin();
