<?php
/**
 * Frontend UI and Features for Advanced Relevance Search
 */

namespace ARS\Frontend;

use ARS\Search\Engine;
use ARS\Core\Helpers;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UI {

	/**
	 * Register frontend features
	 */
	public static function register_frontend() {
		add_action( 'wp_footer', array( __CLASS__, 'inject_highlight_script' ) );
		add_shortcode( 'ars_filters', array( __CLASS__, 'render_filters_shortcode' ) );
	}

	/**
	 * Inject highlighting JavaScript
	 */
	public static function inject_highlight_script() {
		// Get search term
		$term = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		if ( empty( $term ) ) {
			$term = get_query_var( 's' );
		}

		if ( empty( $term ) ) {
			return;
		}

		// Check if highlighting enabled
		if ( ! Settings::is_enabled( 'highlight_enabled' ) ) {
			return;
		}

		$color = Settings::get( 'highlight_color', '#ffeb3b' );

		// Extract words
		$raw_words = explode( ' ', urldecode( $term ) );
		$js_words = array();
		foreach ( $raw_words as $w ) {
			$w = trim( $w );
			if ( strlen( $w ) > 1 ) {
				$js_words[] = $w;
			}
		}

		if ( empty( $js_words ) ) {
			return;
		}

		?>
		<script type="text/javascript">
		document.addEventListener("DOMContentLoaded", function() {
			var searchTerms = <?php echo wp_json_encode( $js_words ); ?>;
			var highlightColor = "<?php echo esc_js( $color ); ?>";

			function highlightText(node) {
				if (node.nodeType === 3) { // Text node
					var val = node.nodeValue;
					var parent = node.parentNode;

					// Skip script, style, and already highlighted
					if(parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE' || parent.classList.contains('ars-highlight')) return;

					var regex = new RegExp('(' + searchTerms.join('|') + ')', 'gi');

					if (regex.test(val)) {
						var span = document.createElement('span');
						span.innerHTML = val.replace(regex, '<span class="ars-highlight" style="background-color: ' + highlightColor + '; color:#000; padding:0 2px; border-radius:2px;">$1</span>');
						parent.replaceChild(span, node);
					}
				} else if (node.nodeType === 1 && node.childNodes && !/(script|style)/i.test(node.tagName)) {
					// Skip filter container
					if(!node.classList.contains('ars-filter-container')) {
						for (var i = 0; i < node.childNodes.length; i++) {
							highlightText(node.childNodes[i]);
						}
					}
				}
			}

			// Target content areas
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
	 * Render category filters shortcode [ars_filters]
	 *
	 * @return string HTML
	 */
	public static function render_filters_shortcode() {
		// Get search term
		$term = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		if ( empty( $term ) ) {
			$term = get_query_var( 's' );
		}

		if ( empty( $term ) ) {
			return '';
		}

		// Get all post IDs from search
		$all_post_ids = Engine::search( $term );
		if ( empty( $all_post_ids ) ) {
			return '';
		}

		// Count categories
		$cat_counts = array();

		foreach ( $all_post_ids as $post_id ) {
			$cats = get_the_category( $post_id );
			if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					if ( ! isset( $cat_counts[ $cat->term_id ] ) ) {
						$cat_counts[ $cat->term_id ] = array(
							'name' => $cat->name,
							'id' => $cat->term_id,
							'count' => 0,
						);
					}
					$cat_counts[ $cat->term_id ]['count']++;
				}
			}
		}

		if ( empty( $cat_counts ) ) {
			return '';
		}

		// Create HTML
		$html = '<div class="ars-filter-container" style="background:#f9f9f9; border:1px solid #ddd; padding:15px; border-radius:4px; margin:20px 0;">';
		$html .= '<h4 style="margin-top:0; font-weight:bold;">Filter op categorie:</h4>';
		$html .= '<ul style="list-style:none; padding:0; margin:0;">';

		foreach ( $cat_counts as $cat_id => $cat_data ) {
			$current_cat = isset( $_GET['ars_cat'] ) ? (int) $_GET['ars_cat'] : 0;
			$is_active = $current_cat === (int) $cat_id;
			$active_class = $is_active ? ' style="font-weight:bold; color:#2271b1;"' : '';

			// Build filter URL
			$filter_url = add_query_arg(
				array(
					's' => $term,
					'ars_cat' => $cat_id,
				),
				home_url( '/search/' )
			);
			if ( $is_active ) {
				$filter_url = add_query_arg( 's', $term, home_url( '/search/' ) );
			}

			$html .= '<li' . $active_class . '>';
			$html .= '<a href="' . esc_url( $filter_url ) . '">';
			$html .= esc_html( $cat_data['name'] ) . ' (' . (int) $cat_data['count'] . ')';
			$html .= '</a></li>';
		}

		$html .= '</ul></div>';

		return $html;
	}
}
