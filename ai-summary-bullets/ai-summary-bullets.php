<?php
/**
 * Plugin Name: AI Summary Bullets
 * Plugin URI:  https://hpvh.nl/gratis-wordpress-plugins/gratis-wordpress-plugin-ai-summary-bullets/
 * Donate link: https://www.paypal.com/donate/?hosted_button_id=G93CUXTZM9CWA
 * Description: Generate a short "In this article:" summary with bullets using OpenAI in the post editor. Includes copy button, frame, cost/length monitoring and Post/Page selection.
 * Version: 1.3.0
 * Author: HP van Hagen
 * Author URI:  https://hpvh.nl
 * Text Domain: ai-summary-bullets
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class AISummaryBullets {
    const OPT_KEY = 'aisum_settings';
    const NONCE_ACTION = 'aisum_nonce_action';
    const NONCE_NAME   = 'aisum_nonce';
    const MENU_SLUG = 'hpvh';

    public function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_settings_page'], 20);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_prompt_reset']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes_dynamic']);
        add_action('wp_ajax_aisum_generate', [$this, 'ajax_generate']);
        add_action('wp_ajax_aisum_refresh_models', [$this, 'ajax_refresh_models']);
        add_action('admin_footer', [$this, 'inline_admin_js']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('ai-summary-bullets', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=ai-summary') . '">' . __('Settings', 'ai-summary-bullets') . '</a>';
        $donate_link = '<a href="https://www.paypal.com/donate/?hosted_button_id=G93CUXTZM9CWA" target="_blank" rel="noopener noreferrer">' . __('Donate', 'ai-summary-bullets') . '</a>';
        
        array_unshift($links, $settings_link);
        $links[] = $donate_link;

        return $links;
    }

    private function defaults() {
        $locale = get_locale();
        $is_dutch = str_starts_with($locale, 'nl');

        $dutch_prompt = "Je bent een ervaren webredacteur. Vat het onderstaande artikel kernachtig samen, specifiek voor gebruik bovenaan een webpagina. De samenvatting moet bestaan uit een HTML-introductieparagraaf gevolgd door een opsomming in bullets.\n\nSpecificaties:\nTaal: {{LANG}}\n\nIntroparagraaf:\nHTML-element: <p><strong>{{INTRO}}</strong></p>\n\nOpsomming:\nHTML-element: <ul> met exact {{BULLETS}} <li>-items\nElke <li> bevat 8–13 woorden\n\nInhoud: concreet, helder en informatief\nZoekwoorden: gebruik relevante keywords waar mogelijk op natuurlijke wijze\nFormatting: géén extra koppen, markdown of codeblokken\nOutput: alleen de HTML inner content van de samenvatting\n\nInputartikel:\n\"\"\"\n{{CONTENT}}\n\"\"\"";
        $english_prompt = "You are an experienced web editor. Summarize the article below concisely, specifically for use at the top of a webpage. The summary must consist of an HTML introductory paragraph followed by a bulleted list.\n\nSpecifications:\nLanguage: {{LANG}}\n\nIntroductory paragraph:\nHTML element: <p><strong>{{INTRO}}</strong></p>\n\nList:\nHTML element: <ul> with exactly {{BULLETS}} <li> items\nEach <li> should contain 8–13 words\n\nContent: concrete, clear, and informative\nKeywords: use relevant keywords naturally where possible\nFormatting: no extra headings, markdown, or code blocks\nOutput: only the inner HTML content of the summary\n\nInput article:\n\"\"\"\n{{CONTENT}}\n\"\"\"";

        return [
            'api_key'      => '',
            'intro'        => $is_dutch ? 'In dit artikel ontdek je:' : 'In this article you’ll discover:',
            'bullets'      => 4,
            'prompt'       => $is_dutch ? $dutch_prompt : $english_prompt,
            'model'        => 'gpt-4o-mini',
            'language'     => $is_dutch ? 'nl' : 'en',
            'models_cache' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'],
            'frame_enable' => 1,
            'pad_top'      => 12,
            'pad_right'    => 16,
            'pad_bottom'   => 12,
            'pad_left'     => 16,
            'max_chars'    => 12000,
            'show_costs'   => 0,
            'price_in'     => 0.00,
            'price_out'    => 0.00,
            'enable_posts' => 1,
            'enable_pages' => 0
        ];
    }

    private function get_settings() {
        $opts = get_option(self::OPT_KEY, []);
        return wp_parse_args($opts, $this->defaults());
    }

    public function add_settings_page() {
        $parent_slug = self::MENU_SLUG;
        $we_created_the_menu = false;

        global $menu;
        $parent_menu_exists = false;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === $parent_slug) {
                $parent_menu_exists = true;
                break;
            }
        }

        if (!$parent_menu_exists) {
            add_menu_page('HPvH.nl >', 'HPvH.nl >', 'manage_options', $parent_slug, '', 'dashicons-superhero-alt', 75);
            $we_created_the_menu = true;
        }

        add_submenu_page($parent_slug, 'AI Summary', 'AI Summary', 'manage_options', 'ai-summary', [$this, 'render_settings_page']);

        if ($we_created_the_menu) {
            remove_submenu_page($parent_slug, $parent_slug);
        }
    }

    public function handle_prompt_reset() {
        if (isset($_POST['yaa_reset_prompt'])) {
            if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
                return;
            }
            $defaults = $this->defaults();
            $_POST[self::OPT_KEY]['prompt'] = $defaults['prompt'];
            add_settings_error('aisum_notices', 'prompt_reset', __('The default prompt has been restored.', 'ai-summary-bullets'), 'updated');
        }
    }

    public function register_settings() {
        register_setting('aisum_group', self::OPT_KEY, function($input){
            $defs = $this->defaults();
            $out = [];
            $out['api_key']      = isset($input['api_key']) ? trim($input['api_key']) : '';
            $out['intro']        = isset($input['intro']) ? sanitize_text_field($input['intro']) : $defs['intro'];
            $out['bullets']      = max(1, intval($input['bullets'] ?? $defs['bullets']));
            $out['prompt']       = isset($input['prompt']) ? wp_kses_post($input['prompt']) : $defs['prompt'];
            $out['model']        = isset($input['model']) ? sanitize_text_field($input['model']) : $defs['model'];
            $out['language']     = isset($input['language']) ? sanitize_text_field($input['language']) : $defs['language'];
            $out['frame_enable'] = !empty($input['frame_enable']) ? 1 : 0;
            $out['pad_top']      = max(0, intval($input['pad_top'] ?? $defs['pad_top']));
            $out['pad_right']    = max(0, intval($input['pad_right'] ?? $defs['pad_right']));
            $out['pad_bottom']   = max(0, intval($input['pad_bottom'] ?? $defs['pad_bottom']));
            $out['pad_left']     = max(0, intval($input['pad_left'] ?? $defs['pad_left']));
            $out['max_chars']    = max(1000, intval($input['max_chars'] ?? $defs['max_chars']));
            $out['show_costs']   = !empty($input['show_costs']) ? 1 : 0;
            $out['price_in']     = is_numeric($input['price_in'] ?? null) ? (float)$input['price_in'] : $defs['price_in'];
            $out['price_out']    = is_numeric($input['price_out'] ?? null) ? (float)$input['price_out'] : $defs['price_out'];
            $out['enable_posts'] = !empty($input['enable_posts']) ? 1 : 0;
            $out['enable_pages'] = !empty($input['enable_pages']) ? 1 : 0;
            
            $prev = get_option(self::OPT_KEY, []);
            $out['models_cache'] = $prev['models_cache'] ?? $defs['models_cache'];
            return $out;
        });
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AI Summary Settings', 'ai-summary-bullets'); ?></h1>
            <?php settings_errors('aisum_notices'); ?>
            
            <div style="padding: 10px 15px; border: 1px solid #ccd0d4; background-color: #f6f7f7; margin-bottom: 20px;">
                <p style="margin: 0;"><?php echo esc_html__('Do you find this plugin useful? Please consider a small donation to support its development!', 'ai-summary-bullets'); ?>
                <a href="https://www.paypal.com/donate/?hosted_button_id=G93CUXTZM9CWA" class="button button-primary" target="_blank" rel="noopener noreferrer" style="margin-left: 10px;"><?php echo esc_html__('Donate via PayPal', 'ai-summary-bullets'); ?></a></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('aisum_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_key"><?php echo esc_html__('OpenAI API key', 'ai-summary-bullets'); ?></label></th>
                        <td><input type="password" id="api_key" name="<?php echo esc_attr(self::OPT_KEY); ?>[api_key]" value="<?php echo esc_attr($s['api_key']); ?>" class="regular-text" placeholder="sk-..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="intro"><?php echo esc_html__('Intro sentence', 'ai-summary-bullets'); ?></label></th>
                        <td><input type="text" id="intro" name="<?php echo esc_attr(self::OPT_KEY); ?>[intro]" value="<?php echo esc_attr($s['intro']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bullets"><?php echo esc_html__('Number of bullets', 'ai-summary-bullets'); ?></label></th>
                        <td><input type="number" id="bullets" name="<?php echo esc_attr(self::OPT_KEY); ?>[bullets]" value="<?php echo intval($s['bullets']); ?>" min="1" max="12"> <span class="description"><?php echo esc_html__('Default 4. Higher = more bullets.', 'ai-summary-bullets'); ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="language"><?php echo esc_html__('Language', 'ai-summary-bullets'); ?></label></th>
                        <td>
                            <select id="language" name="<?php echo esc_attr(self::OPT_KEY); ?>[language]">
                                <?php foreach (['nl'=>'Nederlands','en'=>'English','de'=>'Deutsch','fr'=>'Français','it'=>'Italiano','es'=>'Español'] as $code=>$label): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($s['language'],$code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="model"><?php echo esc_html__('Model', 'ai-summary-bullets'); ?></label></th>
                        <td>
                            <select id="model" name="<?php echo esc_attr(self::OPT_KEY); ?>[model]">
                                <?php foreach ($s['models_cache'] as $m): ?>
                                    <option value="<?php echo esc_attr($m); ?>" <?php selected($s['model'],$m); ?>><?php echo esc_html($m); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button" id="aisum-refresh-models"><?php echo esc_html__('Refresh models', 'ai-summary-bullets'); ?></button>
                            <span class="description"><?php echo esc_html__('Retrieves the latest models from OpenAI.', 'ai-summary-bullets'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Frame around summary', 'ai-summary-bullets'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[frame_enable]" value="1" <?php checked($s['frame_enable'],1); ?>> <?php echo esc_html__('Show frame (recommended)', 'ai-summary-bullets'); ?></label>
                            <div style="margin-top:8px">
                                <label style="display:inline-block;margin-right:8px;"><?php echo esc_html__('Padding top', 'ai-summary-bullets'); ?>: <input type="number" name="<?php echo esc_attr(self::OPT_KEY); ?>[pad_top]" value="<?php echo intval($s['pad_top']); ?>" min="0" style="width:70px"> px</label>
                                <label style="display:inline-block;margin-right:8px;"><?php echo esc_html__('right', 'ai-summary-bullets'); ?>: <input type="number" name="<?php echo esc_attr(self::OPT_KEY); ?>[pad_right]" value="<?php echo intval($s['pad_right']); ?>" min="0" style="width:70px"> px</label>
                                <label style="display:inline-block;margin-right:8px;"><?php echo esc_html__('bottom', 'ai-summary-bullets'); ?>: <input type="number" name="<?php echo esc_attr(self::OPT_KEY); ?>[pad_bottom]" value="<?php echo intval($s['pad_bottom']); ?>" min="0" style="width:70px"> px</label>
                                <label style="display:inline-block;"><?php echo esc_html__('left', 'ai-summary-bullets'); ?>: <input type="number" name="<?php echo esc_attr(self::OPT_KEY); ?>[pad_left]" value="<?php echo intval($s['pad_left']); ?>" min="0" style="width:70px"> px</label>
                            </div>
                            <p class="description"><?php echo esc_html__('Width automatically follows the width of your post content (width:100%).', 'ai-summary-bullets'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_chars"><?php echo esc_html__('Max. input (characters) to AI', 'ai-summary-bullets'); ?></label></th>
                        <td>
                            <input type="number" id="max_chars" name="<?php echo esc_attr(self::OPT_KEY); ?>[max_chars]" value="<?php echo intval($s['max_chars']); ?>" min="1000" step="500">
                            <p class="description"><?php echo esc_html__('Content that is too long is truncated at a word boundary to limit errors and costs.', 'ai-summary-bullets'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Cost estimation', 'ai-summary-bullets'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[show_costs]" value="1" <?php checked($s['show_costs'],1); ?>> <?php echo esc_html__('Show estimated and actual costs (fill in rates)', 'ai-summary-bullets'); ?></label>
                            <div style="margin-top:8px">
                                <label style="display:inline-block;margin-right:12px;"><?php echo esc_html__('Price prompt (per 1K tokens)', 'ai-summary-bullets'); ?>: <input type="number" step="0.0001" name="<?php echo esc_attr(self::OPT_KEY); ?>[price_in]" value="<?php echo esc_attr($s['price_in']); ?>" style="width:100px"></label>
                                <label style="display:inline-block;"><?php echo esc_html__('Price completion (per 1K tokens)', 'ai-summary-bullets'); ?>: <input type="number" step="0.0001" name="<?php echo esc_attr(self::OPT_KEY); ?>[price_out]" value="<?php echo esc_attr($s['price_out']); ?>" style="width:100px"></label>
                            </div>
                            <p class="description"><?php echo esc_html__('Leave empty or 0.00 if you do not want to show a cost indication.', 'ai-summary-bullets'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Where to allow summaries', 'ai-summary-bullets'); ?></th>
                        <td>
                            <label style="margin-right:16px;"><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enable_posts]" value="1" <?php checked($s['enable_posts'],1); ?>> <?php echo esc_html__('Posts', 'ai-summary-bullets'); ?></label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enable_pages]" value="1" <?php checked($s['enable_pages'],1); ?>> <?php echo esc_html__('Pages', 'ai-summary-bullets'); ?></label>
                            <p class="description"><?php echo esc_html__('Default is Posts only.', 'ai-summary-bullets'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="prompt"><?php echo esc_html__('Instruction / prompt', 'ai-summary-bullets'); ?></label></th>
                        <td>
                            <textarea id="prompt" name="<?php echo esc_attr(self::OPT_KEY); ?>[prompt]" class="large-text" rows="15"><?php echo esc_textarea($s['prompt']); ?></textarea>
                            <div class="description" style="margin-top: 10px;">
                                <p style="margin: 0 0 5px 0;"><strong><?php esc_html_e('Placeholder explanation:', 'ai-summary-bullets'); ?></strong></p>
                                <ul style="margin: 0; padding-left: 20px; font-size: 12px;">
                                    <li><code>{{LANG}}</code>: <?php esc_html_e("Is replaced by the chosen language (e.g., 'English').", 'ai-summary-bullets'); ?></li>
                                    <li><code>{{INTRO}}</code>: <?php esc_html_e("Is replaced by the 'Intro sentence' field above.", 'ai-summary-bullets'); ?></li>
                                    <li><code>{{BULLETS}}</code>: <?php esc_html_e("Is replaced by the 'Number of bullets' set above.", 'ai-summary-bullets'); ?></li>
                                    <li><code>{{CONTENT}}</code>: <?php esc_html_e("Is replaced by the (truncated) content of your post or page.", 'ai-summary-bullets'); ?></li>
                                </ul>
                            </div>
                            <input type="submit" name="yaa_reset_prompt" id="aisum_reset_prompt" class="button" value="<?php esc_attr_e('Restore Default Prompt', 'ai-summary-bullets'); ?>" formnovalidate="formnovalidate" style="margin-top: 10px;">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            </form>
        </div>
        <?php
    }

    public function add_meta_boxes_dynamic() {
        $s = $this->get_settings();
        $screens = [];
        if (!empty($s['enable_posts'])) $screens[] = 'post';
        if (!empty($s['enable_pages'])) $screens[] = 'page';
        if (empty($screens)) $screens = ['post'];

        foreach ($screens as $scr) {
            add_meta_box('aisum_box', __('AI Summary', 'ai-summary-bullets'), [$this, 'render_meta_box'], $scr, 'side', 'high');
        }
    }

    public function render_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <p>
            <button type="button" class="button button-primary" id="aisum-generate" data-post="<?php echo esc_attr($post->ID); ?>"><?php echo esc_html__('Generate summary', 'ai-summary-bullets'); ?></button>
            <button type="button" class="button" id="aisum-copy"><?php echo esc_html__('Copy', 'ai-summary-bullets'); ?></button>
        </p>
        <div id="aisum-status" style="margin:6px 0;"></div>
        <div id="aisum-metrics" style="margin:6px 0; font-size:12px; color:#555;"></div>
        <textarea id="aisum-output" style="width:100%;height:220px;" readonly placeholder="<?php echo esc_attr__('The (copyable) summary will appear here…', 'ai-summary-bullets'); ?>"></textarea>
        <p class="description"><?php echo esc_html__('Copy and paste this block at the top of your content.', 'ai-summary-bullets'); ?></p>
        <?php
    }

    private function mb_len($text, $encoding = 'UTF-8') {
        if (function_exists('mb_strlen')) return mb_strlen($text, $encoding);
        return strlen($text);
    }

    private function truncate_by_chars_word_safe($text, $max) {
        if ($this->mb_len($text) <= $max) return [$text, false];
        $text = substr($text, 0, $max);
        $lastSpace = strrpos($text, ' ');
        if ($lastSpace !== false) {
            $text = substr($text, 0, $lastSpace);
        }
        return [$text . '…', true];
    }
    
    private function estimate_tokens($text) {
        return (int) ceil($this->mb_len($text) / 3.8);
    }

    public function ajax_generate() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = intval($_POST['post_id'] ?? 0);
        $post    = get_post($post_id);
        if (!$post) wp_send_json_error('not_found', 404);

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('forbidden', 403);
        }

        $content = trim( wp_strip_all_tags( strip_shortcodes($post->post_content), true ) );
        if ($content === '') wp_send_json_error(['message'=>'empty'], 400);

        $s = $this->get_settings();
        if (empty($s['api_key'])) wp_send_json_error(['message'=>'no_api_key'], 400);

        list($content_limited, $truncated) = $this->truncate_by_chars_word_safe($content, intval($s['max_chars']));

        $prompt = str_replace(
            ['{{LANG}}','{{INTRO}}','{{BULLETS}}','{{CONTENT}}'],
            [$s['language'], $s['intro'], $s['bullets'], $content_limited],
            $s['prompt']
        );

        $body = [
            'model' => $s['model'],
            'messages' => [['role'=>'user','content'=>$prompt]],
            'temperature' => 0.4,
            'max_tokens'  => 500
        ];

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Content-Type'  => 'application/json', 'Authorization' => 'Bearer ' . $s['api_key']],
            'timeout' => 45,
            'body'    => wp_json_encode($body)
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message'=>$resp->get_error_message()], 500);
        }
        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200 || empty($json['choices'][0]['message']['content'])) {
            $error_message = $json['error']['message'] ?? 'bad_response';
            wp_send_json_error(['message'=>$error_message,'raw'=>$json], 500);
        }

        $inner_html = trim($json['choices'][0]['message']['content']);
        $final_html = wp_kses_post($inner_html);

        if (!empty($s['frame_enable'])) {
            $style = sprintf('border:1px solid #e5e7eb;padding:%dpx %dpx %dpx %dpx;border-radius:6px;margin:0 0 1rem 0;', intval($s['pad_top']), intval($s['pad_right']), intval($s['pad_bottom']), intval($s['pad_left']));
            $final_html = '<div class="aisum-summary-box" style="'.$style.'">'.$inner_html.'</div>';
        }
        
        $usage = $json['usage'] ?? null;
        $cost_actual = null;
        if (!empty($s['show_costs']) && ($s['price_in'] > 0 || $s['price_out'] > 0) && $usage) {
            $cost_actual = (($usage['prompt_tokens'] * $s['price_in']) / 1000) + (($usage['completion_tokens'] * $s['price_out']) / 1000);
        }

        wp_send_json_success([
            'html' => $final_html,
            'metrics' => [
                'chars_in' => $this->mb_len($content_limited),
                'tokens_in_est' => $this->estimate_tokens($content_limited),
                'truncated' => $truncated,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'cost_actual' => $cost_actual
            ]
        ]);
    }

    public function ajax_refresh_models() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $s = $this->get_settings();
        if (empty($s['api_key'])) wp_send_json_error(['message'=>'no_api_key'], 400);

        $resp = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $s['api_key']],
            'timeout' => 20
        ]);
        if (is_wp_error($resp)) wp_send_json_error(['message'=>$resp->get_error_message()], 500);

        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200 || empty($json['data'])) {
            wp_send_json_error(['message'=>'bad_response','raw'=>$json], 500);
        }

        $names = array_column($json['data'], 'id');
        $filtered = array_values(array_filter($names, fn($id) => preg_match('/^(gpt|gpt-4|gpt-4o|o\d|ft:)/', $id) && !str_contains($id, 'vision') && !str_contains($id, 'instruct')));
        sort($filtered);

        if (!empty($filtered)) {
            $s['models_cache'] = $filtered;
            update_option(self::OPT_KEY, $s);
        }

        wp_send_json_success(['models' => $filtered ?: $s['models_cache']]);
    }

    public function inline_admin_js() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['post', 'page', self::MENU_SLUG . '_page_ai-summary'], true)) return;

        $msgs = [
            'working'   => __('Generating summary…', 'ai-summary-bullets'),
            'done'      => __('Done.', 'ai-summary-bullets'),
            'err'       => __('Something went wrong. Please check your API key and try again.', 'ai-summary-bullets'),
            'copied'    => __('Copied!', 'ai-summary-bullets'),
            'copy'      => __('Copy', 'ai-summary-bullets'),
            'noContent' => __('There is no content yet. Please add content first and save.', 'ai-summary-bullets'),
            'truncated' => __('Note: content was truncated to max length.', 'ai-summary-bullets'),
            'metrics'   => __('Input: %1$s chars (~%2$s tokens).', 'ai-summary-bullets'),
            'metrics_usage' => __('Actual tokens — prompt: %1$s, completion: %2$s.', 'ai-summary-bullets'),
            'metrics_cost_actual' => __('Actual cost: %1$s', 'ai-summary-bullets'),
            'refreshing'=> __('Refreshing models…', 'ai-summary-bullets'),
            'models_fail' => __('Could not refresh models.', 'ai-summary-bullets')
        ];
        ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const AJAX_URL = '<?php echo admin_url('admin-ajax.php'); ?>';
    const NONCE = '<?php echo wp_create_nonce(self::NONCE_ACTION); ?>';
    const MSGS = <?php echo json_encode($msgs); ?>;

    function formatCurrency(n) {
        if (typeof n !== 'number' || isNaN(n)) return '';
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 5 }).format(n);
    }

    document.body.addEventListener('click', function(e) {
        if (e.target.id === 'aisum-generate') {
            const btn = e.target;
            const postId = btn.dataset.post;
            const statusEl = document.getElementById('aisum-status');
            const outEl = document.getElementById('aisum-output');
            const metricsEl = document.getElementById('aisum-metrics');

            statusEl.textContent = MSGS.working;
            outEl.value = '';
            metricsEl.innerHTML = '';
            btn.disabled = true;

            const data = new URLSearchParams({ action: 'aisum_generate', nonce: NONCE, post_id: postId });

            fetch(AJAX_URL, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        outEl.value = res.data.html || '';
                        statusEl.textContent = MSGS.done;
                        const m = res.data.metrics;
                        const parts = [];
                        if (m.truncated) parts.push(MSGS.truncated);
                        if (m.chars_in && m.tokens_in_est) parts.push(MSGS.metrics.replace('%1$s', m.chars_in).replace('%2$s', m.tokens_in_est));
                        if (m.prompt_tokens && m.completion_tokens) parts.push(MSGS.metrics_usage.replace('%1$s', m.prompt_tokens).replace('%2$s', m.completion_tokens));
                        if (m.cost_actual !== null) parts.push(MSGS.metrics_cost_actual.replace('%1$s', `<strong>${formatCurrency(m.cost_actual)}</strong>`));
                        metricsEl.innerHTML = parts.join('<br>');
                    } else {
                        const errorMsg = res.data?.message === 'empty' ? MSGS.noContent : (MSGS.err + '\n\nDetails: ' + (res.data?.message || 'unknown error'));
                        alert(errorMsg);
                        statusEl.textContent = '';
                    }
                })
                .catch(() => {
                    alert(MSGS.err);
                    statusEl.textContent = '';
                })
                .finally(() => {
                    btn.disabled = false;
                });
        }

        if (e.target.id === 'aisum-copy') {
            const outEl = document.getElementById('aisum-output');
            if (!outEl || !outEl.value) return;
            navigator.clipboard.writeText(outEl.value).then(() => {
                const originalText = e.target.textContent;
                e.target.textContent = MSGS.copied;
                setTimeout(() => e.target.textContent = originalText, 1500);
            });
        }

        if (e.target.id === 'aisum-refresh-models') {
            const btn = e.target;
            const orig = btn.textContent;
            btn.disabled = true;
            btn.textContent = MSGS.refreshing;

            const data = new URLSearchParams({ action: 'aisum_refresh_models', nonce: NONCE });

            fetch(AJAX_URL, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data.models) {
                        const sel = document.getElementById('model');
                        const currentVal = sel.value;
                        sel.innerHTML = '';
                        res.data.models.forEach(m => sel.add(new Option(m, m)));
                        sel.value = currentVal;
                        if (!sel.value && sel.options.length > 0) sel.selectedIndex = 0;
                    } else {
                        alert(MSGS.models_fail);
                    }
                })
                .catch(() => alert(MSGS.models_fail))
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = orig;
                });
        }
    });
});
</script>
        <?php
    }
}
new AISummaryBullets();