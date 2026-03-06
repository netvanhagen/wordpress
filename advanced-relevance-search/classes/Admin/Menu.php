<?php
/**
 * Admin Menu Handler for Advanced Relevance Search
 */

namespace ARS\Admin;

use ARS\Core\Database;
use ARS\Core\Helpers;
use ARS\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	/**
	 * Register admin menu and pages
	 */
	public static function register_menu() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add menu pages
	 */
	public static function add_admin_menu() {
		add_menu_page(
			'Search Relevance',
			'Search Relevance',
			'manage_options',
			'ars-settings',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-search',
			65
		);

		add_submenu_page(
			'ars-settings',
			'Settings',
			'Settings',
			'manage_options',
			'ars-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'ars-settings',
			'Pinned Resultaten',
			'Pinned Resultaten',
			'manage_options',
			'ars-pinned',
			array( __CLASS__, 'render_pinned_page' )
		);

		add_submenu_page(
			'ars-settings',
			'Logging',
			'Logging',
			'manage_options',
			'ars-logging',
			array( __CLASS__, 'render_logging_page' )
		);
	}

	/**
	 * Register settings
	 */
	public static function register_settings() {
		add_action( 'admin_init', array( 'ARS\Core\Settings', 'register_settings' ) );
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page() {
		$last_index = get_option( 'ars_last_index_date', 'Nog nooit uitgevoerd' );
		$index_count = get_option( 'ars_last_index_count', '0' );
		?>
		<div class="wrap">
			<h1>Search Relevance - Instellingen</h1>

			<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; border-radius:4px;">
				<h2>Zoekindex Status</h2>
				<p><strong>Laatste indexatie:</strong> <?php echo esc_html( $last_index ); ?> | <strong>Items in index:</strong> <?php echo esc_html( $index_count ); ?></p>
				<div id="ars-progress-container" style="display:none; width:100%; background:#eee; height:24px; margin-bottom:15px; border-radius:12px; overflow:hidden; border:1px solid #ccc;">
					<div id="ars-progress-bar" style="width:0%; background:#2271b1; height:100%; transition: width 0.3s; color:white; text-align:center; line-height:24px; font-weight:bold;">0%</div>
				</div>
				<button type="button" id="ars-start-index" class="button button-primary">Start Indexatie</button>
				<button type="button" id="ars-clear-index" class="button button-secondary">Wis Index & Herindexeer</button>
			</div>

			<form method="post" action="options.php" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px;">
				<?php
				settings_fields( 'ars_settings_group' );
				do_settings_sections( 'ars-settings' );
				submit_button( 'Instellingen opslaan' );
				?>
			</form>

			<div style="background:#f0f6fb; border:1px solid #bbd3ef; padding:25px; margin-top:30px; border-radius:4px;">
				<h2 style="margin-top:0;">FAQ</h2>

				<h3>Hoe indexatie werkt</h3>
				<p>Indexatie draait handmatig in batches van 50 via Ajax. Bij wijzigingen klik je op Wis Index & Herindexeer.</p>

				<h3>Hoe weging werkt</h3>
				<p>De score is een optelsom van gewichten voor titel, inhoud, excerpt, categorie, tags en interne keywords. Exacte matches kunnen bonus geven.</p>

				<h3>Hoe pinned resultaten werken</h3>
				<p>Pinned koppelt een zoekterm aan een post ID en positioneert dit resultaat op positie 1, 2 of 3 in de resultatenlijst.</p>

				<h3>Hoe WPML wordt ondersteund</h3>
				<p>Index, pinned en synoniemen zijn taalgebonden. Er wordt gefilterd op actieve taal.</p>

				<h3>Hoe logging werkt</h3>
				<p>Logging slaat zoekterm, datum, taal, aantal resultaten en getoonde IDs op. Geen IP of persoonsgegevens.</p>

				<h3>Wat te doen bij onverwachte resultaten</h3>
				<p>Controleer of indexatie is uitgevoerd, of contenttypes kloppen en of stopwoorden en minimum woordlengte niet te streng staan.</p>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#ars-start-index').on('click', function() {
				const btn = $(this);
				btn.prop('disabled', true).text('Bezig...');
				$('#ars-progress-container').show();

				function runIndex(offset = 0) {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						dataType: 'json',
						data: { action: 'ars_index_batch', offset: offset, nonce: '<?php echo wp_create_nonce( 'ars_ajax_nonce' ); ?>' },
						success: function(res) {
							if(res && res.success) {
								if(res.data.done) {
									$('#ars-progress-bar').css('width', '100%').text('100% Voltooid');
									setTimeout(function(){ location.reload(); }, 800);
								} else {
									let total = parseInt(res.data.total || 0);
									let newOffset = offset + parseInt(res.data.imported || 0);
									let percent = (total > 0) ? Math.round((newOffset / total) * 100) : 0;
									$('#ars-progress-bar').css('width', percent + '%').text(percent + '%');
									runIndex(newOffset);
								}
							} else {
								alert('Er ging iets mis tijdens indexeren.');
								btn.prop('disabled', false).text('Start Indexatie');
							}
						},
						error: function() {
							alert('Serverfout tijdens indexeren.');
							btn.prop('disabled', false).text('Start Indexatie');
						}
					});
				}
				runIndex(0);
			});

			$('#ars-clear-index').on('click', function() {
				if(confirm('Weet je zeker dat je de index wilt wissen?')) {
					$.post(ajaxurl, { action: 'ars_clear_index', nonce: '<?php echo wp_create_nonce( 'ars_ajax_nonce' ); ?>' }, function() {
						location.reload();
					});
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Render pinned results page
	 */
	public static function render_pinned_page() {
		global $wpdb;
		$table_pinned = $wpdb->prefix . 'ars_pinned';
		$current_lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'all';
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_pinned WHERE (lang = %s OR lang = 'all') ORDER BY search_term ASC, position ASC", $current_lang ) );
		?>
		<div class="wrap">
			<h1>Pinned Zoekresultaten (<?php echo strtoupper( esc_html( $current_lang ) ); ?>)</h1>
			<div class="card" style="padding: 20px; margin-top: 20px; background:#fff; border:1px solid #ccd0d4;">
				<h3>Nieuwe koppeling pinnen</h3>
				<form id="ars-add-pinned-form">
					<input type="text" id="p_term" placeholder="Zoekwoord" required class="regular-text" style="width:250px;">
					<input type="number" id="p_id" placeholder="Post ID" required class="small-text">
					<select id="p_pos">
						<option value="1">Positie 1</option>
						<option value="2">Positie 2</option>
						<option value="3">Positie 3</option>
					</select>
					<?php wp_nonce_field( 'ars_pinned_nonce', 'pinned_nonce' ); ?>
					<button type="submit" class="button button-primary">Toevoegen</button>
				</form>
				<p style="margin-top:10px; color:#666;">Tip: gebruik normale schrijfwijze, de plugin normaliseert automatisch.</p>
			</div>
			<table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
				<thead><tr><th>Zoekterm</th><th>Post ID</th><th>Titel</th><th>Positie</th><th>Actie</th></tr></thead>
				<tbody>
					<?php if ( $items ) :
						foreach ( $items as $item ) :
							?>
					<tr>
						<td><strong><?php echo esc_html( $item->search_term ); ?></strong></td>
						<td><?php echo (int) $item->post_id; ?></td>
						<td><?php echo esc_html( get_the_title( $item->post_id ) ); ?></td>
						<td><?php echo (int) $item->position; ?></td>
						<td><button class="button ars-delete-pinned" data-id="<?php echo (int) $item->id; ?>">Verwijderen</button></td>
					</tr>
							<?php
						endforeach;
					else :
						?>
					<tr><td colspan="5">Geen pinned resultaten.</td></tr>
						<?php endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#ars-add-pinned-form').on('submit', function(e) {
				e.preventDefault();
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'ars_save_pinned',
						term: $('#p_term').val(),
						post_id: $('#p_id').val(),
						pos: $('#p_pos').val(),
						nonce: $('#pinned_nonce').val()
					},
					success: function() { location.reload(); }
				});
			});
			$('.ars-delete-pinned').on('click', function() {
				if(confirm('Wissen?')) {
					$.post(ajaxurl, { action: 'ars_delete_pinned', id: $(this).data('id'), nonce: $('#pinned_nonce').val() }, function() {
						location.reload();
					});
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Render logging page
	 */
	public static function render_logging_page() {
		$current_lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'all';
		$options = Settings::get_all();
		$days = isset( $options['log_retention'] ) ? $options['log_retention'] : 30;

		$top = Database::get_top_searches( $current_lang, 20 );
		$zero = Database::get_zero_result_searches( $current_lang, 20 );
		?>
		<div class="wrap">
			<h1>Zoek Analyse Dashboard</h1>

			<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px; display:flex; gap:10px; align-items:center;">
				<strong>Onderhoud:</strong>
				<button class="button" id="ars-prune-logs">Wis logs ouder dan <?php echo (int) $days; ?> dagen</button>
				<button class="button button-link-delete" id="ars-wipe-logs">Wis ALLE logs</button>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top:20px;">
				<div class="card" style="padding:20px;">
					<h3 style="margin-top:0;">Top 20 Zoektermen (<?php echo strtoupper( esc_html( $current_lang ) ); ?>)</h3>
					<table class="wp-list-table widefat" style="margin-top:10px;">
						<thead><tr><th>Zoekterm</th><th>Aantal</th></tr></thead>
						<tbody>
							<?php if ( $top ) :
								foreach ( $top as $item ) :
									?>
							<tr>
								<td><?php echo esc_html( $item->search_term ); ?></td>
								<td><?php echo (int) $item->c; ?></td>
							</tr>
									<?php
								endforeach;
							else :
								?>
							<tr><td colspan="2">Geen logs beschikbaar.</td></tr>
								<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="card" style="padding:20px;">
					<h3 style="margin-top:0;">Nul Resultaten (<?php echo strtoupper( esc_html( $current_lang ) ); ?>)</h3>
					<table class="wp-list-table widefat" style="margin-top:10px;">
						<thead><tr><th>Zoekterm</th><th>Aantal keer</th></tr></thead>
						<tbody>
							<?php if ( $zero ) :
								foreach ( $zero as $item ) :
									?>
							<tr style="background:#fef5f5;">
								<td><?php echo esc_html( $item->search_term ); ?></td>
								<td><?php echo (int) $item->c; ?></td>
							</tr>
									<?php
								endforeach;
							else :
								?>
							<tr><td colspan="2">Geen nul-resultaten.</td></tr>
								<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#ars-prune-logs').on('click', function() {
				if(confirm('Logs ouder dan <?php echo (int) $days; ?> dagen verwijderen?')) {
					$.post(ajaxurl, { action: 'ars_prune_logs', nonce: '<?php echo wp_create_nonce( 'ars_log_nonce' ); ?>' }, function() {
						location.reload();
					});
				}
			});
			$('#ars-wipe-logs').on('click', function() {
				if(confirm('ALLE logs verwijderen? Dit kan niet ongedaan gemaakt worden!')) {
					$.post(ajaxurl, { action: 'ars_clear_logs', nonce: '<?php echo wp_create_nonce( 'ars_log_nonce' ); ?>' }, function() {
						location.reload();
					});
				}
			});
		});
		</script>
		<?php
	}
}
