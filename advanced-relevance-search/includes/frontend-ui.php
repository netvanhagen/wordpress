<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. JAVASCRIPT HIGHLIGHTING
 * Werkt in de browser na het laden, veilig voor Divi.
 */
add_action('wp_footer', 'ars_inject_highlight_script');

function ars_inject_highlight_script() {
    // Probeer zoekterm op te halen uit URL of Query
    $term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    if(empty($term)) $term = get_query_var('s');
    
    if(empty($term)) return;
    
    $options = get_option('ars_settings');
    if ( empty($options['highlight_enabled']) ) return;

    $color = !empty($options['highlight_color']) ? $options['highlight_color'] : '#ffeb3b';
    
    $raw_words = explode(' ', urldecode($term));
    $js_words = array();
    foreach($raw_words as $w) {
        $w = trim($w);
        if(strlen($w) > 1) $js_words[] = $w;
    }
    if(empty($js_words)) return;
    ?>
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        var searchTerms = <?php echo json_encode($js_words); ?>;
        var highlightColor = "<?php echo $color; ?>";
        
        function highlightText(node) {
            if (node.nodeType === 3) { // Text node
                var val = node.nodeValue;
                var parent = node.parentNode;
                
                // Veiligheid
                if(parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE' || parent.classList.contains('ars-highlight')) return;

                var regex = new RegExp('(' + searchTerms.join('|') + ')', 'gi');
                
                if (regex.test(val)) {
                    var span = document.createElement('span');
                    span.innerHTML = val.replace(regex, '<span class="ars-highlight" style="background-color: ' + highlightColor + '; color:#000; padding:0 2px; border-radius:2px;">$1</span>');
                    parent.replaceChild(span, node);
                }
            } else if (node.nodeType === 1 && node.childNodes && !/(script|style)/i.test(node.tagName)) {
                // Sla filter container over
                if(!node.classList.contains('ars-filter-container')) {
                    for (var i = 0; i < node.childNodes.length; i++) {
                        highlightText(node.childNodes[i]);
                    }
                }
            }
        }

        // Target specifieke Divi content areas
        var contentAreas = document.querySelectorAll('.et_pb_section, .et_pb_post, .entry-content, .et_pb_blog_extras, #main-content');
        if(contentAreas.length > 0) {
            contentAreas.forEach(function(area) { highlightText(area); });
        } else {
            highlightText(document.body);
        }
    });
    </script>
    <?php
}

/**
 * 2. CATEGORIE FILTER WIDGET (SHORTCODE)
 * Shortcode: [ars_filters]
 */
add_shortcode('ars_filters', 'ars_render_filters_shortcode');

function ars_render_filters_shortcode() {
    $term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    if(empty($term)) $term = get_query_var('s');
    
    if(empty($term)) return '';

    if ( !function_exists('ars_perform_search') ) return '';

    // Haal ALLE ID's op via onze engine
    $all_post_ids = ars_perform_search($term);

    if ( empty($all_post_ids) ) return '';

    $cat_counts = array();
    
    foreach ( $all_post_ids as $p_id ) {
        $cats = get_the_category( $p_id );
        if ( ! empty( $cats ) && ! is_wp_error($cats) ) {
            foreach ( $cats as $c ) {
                if ( ! isset( $cat_counts[$c->term_id] ) ) {
                    $cat_counts[$c->term_id] = array(
                        'name' => $c->name,
                        'id' => $c->term_id,
                        'count' => 0
                    );
                }
                $cat_counts[$c->term_id]['count']++;
            }
        }
    }

    if ( empty( $cat_counts ) ) return '';

    $current_cat = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
    
    $base_url = home_url('/');
    $params = $_GET;
    unset($params['cat']); 
    if(!isset($params['s'])) $params['s'] = $term;

    $output = '<div class="ars-filter-container" style="margin-bottom: 20px;">';
    $output .= '<span style="font-weight:bold; margin-right:10px;">Filter op categorie:</span>';
    
    $all_url = add_query_arg($params, $base_url);
    $active_style = 'background:#2271b1; color:#fff;';
    $inactive_style = 'background:#f0f0f1; color:#000;';
    
    $style = ($current_cat === 0) ? $active_style : $inactive_style;
    $output .= '<a href="'.esc_url($all_url).'" style="text-decoration:none; padding:5px 10px; border-radius:4px; margin-right:5px; display:inline-block; font-size:14px; '.$style.'">Alles</a>';

    foreach ( $cat_counts as $cat ) {
        $cat_params = $params;
        $cat_params['cat'] = $cat['id'];
        $cat_url = add_query_arg($cat_params, $base_url);
        
        $is_active = ($current_cat === $cat['id']);
        $style = $is_active ? $active_style : $inactive_style;
        
        $output .= '<a href="'.esc_url($cat_url).'" style="text-decoration:none; padding:5px 10px; border-radius:4px; margin-right:5px; display:inline-block; font-size:14px; '.$style.'">';
        $output .= esc_html( $cat['name'] ) . ' <span style="opacity:0.6; font-size:0.9em;">(' . $cat['count'] . ')</span>';
        $output .= '</a>';
    }
    
    $output .= '</div>';

    return $output;
}