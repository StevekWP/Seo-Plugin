<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk-create landing pages:
 *  - City pages: one Item x a list of cities.
 *  - Sub-City pages: children of an existing city page, one per
 *    neighborhood/district, nested in the URL under that city page.
 *
 * Generation runs as a series of small AJAX chunks (SSLP_CHUNK_SIZE lines
 * per request) driven by JS on the admin screen, with a progress bar. This
 * means a single HTTP request never has to insert more than a few dozen
 * posts, so it can't run into PHP's max_execution_time even on very large
 * batches (thousands of lines) or on hosts with a locked-down time limit.
 */
class SSLP_Bulk_Generator {

	const CHUNK_SIZE = 40;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		add_action( 'wp_ajax_sslp_bulk_generate_chunk', array( $this, 'ajax_generate_chunk' ) );
		add_action( 'wp_ajax_sslp_bulk_generate_subcities_chunk', array( $this, 'ajax_generate_subcity_chunk' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SSLP_CPT,
			__( 'Bulk Generate Pages', 'sslp' ),
			__( 'Bulk Generate', 'sslp' ),
			'edit_posts',
			'sslp-bulk-generate',
			array( $this, 'render_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . SSLP_CPT,
			__( 'Bulk Generate Sub-Cities', 'sslp' ),
			__( 'Bulk Generate Sub-Cities', 'sslp' ),
			'edit_posts',
			'sslp-bulk-generate-subcities',
			array( $this, 'render_subcity_page' )
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Shared progress-bar UI + JS driver
	 * ------------------------------------------------------------------ */

	/**
	 * Prints the progress bar markup and the JS that walks the textarea
	 * lines in chunks, POSTing each chunk to admin-ajax.php in sequence
	 * (awaiting each response) so duplicate-checking against previously
	 * inserted rows in the SAME batch stays accurate.
	 */
	private function render_progress_script( $form_id, $textarea_id, $ajax_action, $nonce_action, $extra_fields_js, $result_notice_text ) {
		$ajax_url = esc_url_raw( admin_url( 'admin-ajax.php' ) );
		$nonce    = wp_create_nonce( $nonce_action );
		$chunk    = self::CHUNK_SIZE;
		?>
		<div id="<?php echo esc_attr( $form_id ); ?>-progress" style="display:none; max-width:600px; margin:16px 0;">
			<div style="background:#dcdcde; border-radius:3px; overflow:hidden; height:20px;">
				<div class="sslp-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width .2s;"></div>
			</div>
			<p class="sslp-progress-text"><?php esc_html_e( 'Starting…', 'sslp' ); ?></p>
		</div>
		<script>
		(function() {
			var form = document.getElementById( <?php echo wp_json_encode( $form_id ); ?> );
			if ( ! form ) { return; }
			var progressWrap = document.getElementById( <?php echo wp_json_encode( $form_id . '-progress' ); ?> );
			var progressBar  = progressWrap.querySelector( '.sslp-progress-bar' );
			var progressText = progressWrap.querySelector( '.sslp-progress-text' );
			var chunkSize    = <?php echo (int) $chunk; ?>;

			form.addEventListener( 'submit', function( e ) {
				e.preventDefault();

				var textarea = document.getElementById( <?php echo wp_json_encode( $textarea_id ); ?> );
				var lines = textarea.value.split( /\r?\n/ ).map( function( l ) { return l.trim(); } ).filter( function( l ) { return l.length > 0; } );

				if ( lines.length === 0 ) {
					textarea.reportValidity();
					return;
				}

				var submitBtn = form.querySelector( 'input[type=submit], button[type=submit]' );
				if ( submitBtn ) { submitBtn.disabled = true; }
				progressWrap.style.display = 'block';

				var totalCreated = 0;
				var totalProcessed = 0;
				var chunks = [];
				for ( var i = 0; i < lines.length; i += chunkSize ) {
					chunks.push( lines.slice( i, i + chunkSize ) );
				}

				function runNext( index ) {
					if ( index >= chunks.length ) {
						progressText.textContent = <?php echo wp_json_encode( $result_notice_text ); ?>.replace( '%d', totalCreated );
						var url = new URL( window.location.href );
						url.searchParams.set( 'sslp_created', totalCreated );
						window.location.href = url.toString();
						return;
					}

					var body = new URLSearchParams();
					body.set( 'action', <?php echo wp_json_encode( $ajax_action ); ?> );
					body.set( 'nonce', <?php echo wp_json_encode( $nonce ); ?> );
					body.set( 'lines', JSON.stringify( chunks[ index ] ) );
					body.set( 'is_last', ( index === chunks.length - 1 ) ? '1' : '0' );
					<?php echo $extra_fields_js; // phpcs:ignore -- controlled, escaped JS fragment built below. ?>

					fetch( <?php echo wp_json_encode( $ajax_url ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} )
						.then( function( r ) { return r.json(); } )
						.then( function( json ) {
							if ( ! json.success ) {
								throw new Error( ( json.data && json.data.message ) ? json.data.message : 'Request failed' );
							}
							totalCreated += json.data.created;
							totalProcessed += chunks[ index ].length;
							var pct = Math.round( ( totalProcessed / lines.length ) * 100 );
							progressBar.style.width = pct + '%';
							progressText.textContent = totalProcessed + ' / ' + lines.length + ' processed, ' + totalCreated + ' created…';
							runNext( index + 1 );
						} )
						.catch( function( err ) {
							progressText.textContent = 'Error: ' + err.message + ' (stopped after ' + totalCreated + ' created — you can safely re-run, duplicates will be skipped if that option is checked).';
							if ( submitBtn ) { submitBtn.disabled = false; }
						} );
				}

				runNext( 0 );
			} );
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 *  City pages
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$result   = isset( $_GET['sslp_created'] ) ? intval( $_GET['sslp_created'] ) : null;
		$settings = SSLP_Settings::instance()->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Generate Landing Pages', 'sslp' ); ?></h1>

			<?php if ( null !== $result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
					<?php
					printf(
						/* translators: %d number of pages created */
						esc_html__( '%d landing page(s) created.', 'sslp' ),
						$result
					);
					?>
					</p>
				</div>
			<?php endif; ?>

			<p>
			<?php
			printf(
				/* translators: 1: page title pattern from Settings, 2: example URL */
				esc_html__( 'Enter one item/service and a list of cities. One landing page will be created per city, titled using the pattern set in Settings ("%1$s"), with a URL like %2$s.', 'sslp' ),
				esc_html( $settings['post_title_pattern'] ),
				esc_html( '/' . $settings['url_slug'] . '/' . SSLP_Settings::build_slug( $settings['slug_pattern'], array( '{item}' => 'Vintage Silver', '{city}' => 'Las Vegas', '{state}' => 'NV' ) ) . '/' )
			);
			?>
			<?php esc_html_e( 'Need neighborhood-level pages under a city? Use Bulk Generate Sub-Cities instead, once the city page exists.', 'sslp' ); ?>
			<?php esc_html_e( 'Large lists are processed in small batches automatically, so there is no need to split them yourself.', 'sslp' ); ?>
			</p>

			<form method="post" id="sslp-bulk-city-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sslp_bulk_generate', 'sslp_bulk_nonce' ); ?>
				<input type="hidden" name="action" value="sslp_bulk_generate" />

				<table class="form-table">
					<tr>
						<th><label for="sslp_bulk_item"><?php esc_html_e( 'Item / Service', 'sslp' ); ?></label></th>
						<td>
							<input type="text" required class="regular-text" id="sslp_bulk_item" name="sslp_bulk_item" value="<?php echo esc_attr( $settings['primary_item'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Vintage Silver', 'sslp' ); ?>" />
							<p class="description"><?php esc_html_e( 'Pre-filled from Settings > Primary Item / Service. Change it here for a one-off batch without affecting your site default.', 'sslp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="sslp_bulk_cities"><?php esc_html_e( 'Cities', 'sslp' ); ?></label></th>
						<td>
							<textarea required id="sslp_bulk_cities" name="sslp_bulk_cities" rows="12" class="large-text" placeholder="Las Vegas, NV&#10;Henderson, NV&#10;Reno, NV"></textarea>
							<p class="description"><?php esc_html_e( 'One city per line, formatted "City, State" (state is optional).', 'sslp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Publish Status', 'sslp' ); ?></th>
						<td>
							<select id="sslp_bulk_status" name="sslp_bulk_status">
								<option value="draft"><?php esc_html_e( 'Draft (review before publishing)', 'sslp' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Publish immediately', 'sslp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Skip Duplicates', 'sslp' ); ?></th>
						<td>
							<label><input type="checkbox" id="sslp_bulk_skip_dupes" name="sslp_bulk_skip_dupes" value="1" checked="checked" /> <?php esc_html_e( 'Skip a city if a page with the same URL already exists', 'sslp' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Generate Pages', 'sslp' ) ); ?>
			</form>

			<?php
			$this->render_progress_script(
				'sslp-bulk-city-form',
				'sslp_bulk_cities',
				'sslp_bulk_generate_chunk',
				'sslp_bulk_generate_ajax',
				"body.set( 'item', document.getElementById( 'sslp_bulk_item' ).value );
					body.set( 'status', document.getElementById( 'sslp_bulk_status' ).value );
					body.set( 'skip_dupes', document.getElementById( 'sslp_bulk_skip_dupes' ).checked ? '1' : '0' );",
				__( '%d landing page(s) created.', 'sslp' )
			);
			?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: creates one chunk's worth of city pages.
	 */
	public function ajax_generate_chunk() {
		check_ajax_referer( 'sslp_bulk_generate_ajax', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'sslp' ) ), 403 );
		}

		$item       = sanitize_text_field( wp_unslash( $_POST['item'] ?? '' ) );
		$status     = ( 'publish' === ( $_POST['status'] ?? '' ) ) ? 'publish' : 'draft';
		$skip_dupes = ! empty( $_POST['skip_dupes'] ) && '1' === $_POST['skip_dupes'];
		$is_last    = ! empty( $_POST['is_last'] ) && '1' === $_POST['is_last'];

		$lines_json = wp_unslash( $_POST['lines'] ?? '[]' );
		$lines      = json_decode( $lines_json, true );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$settings = SSLP_Settings::instance()->get_settings();
		if ( class_exists( 'SSLP_URL_Manager' ) ) {
			SSLP_URL_Manager::set_bulk_mode( true );
		}

		$existing_lookup = array();
		if ( $skip_dupes ) {
			$existing_lookup = $this->get_existing_city_combos();
		}

		$created = 0;

		foreach ( $lines as $line ) {
			$line = sanitize_text_field( (string) $line );
			$city  = $line;
			$state = '';
			if ( strpos( $line, ',' ) !== false ) {
				list( $city, $state ) = array_map( 'trim', explode( ',', $line, 2 ) );
			}
			if ( '' === $city ) {
				continue;
			}

			$placeholders = array(
				'{item}'     => $item,
				'{city}'     => $city,
				'{state}'    => $state,
				'{phone}'    => $settings['default_phone'],
				'{sitename}' => get_bloginfo( 'name' ),
			);
			$title = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $settings['post_title_pattern'] );
			$title = trim( preg_replace( '/\s*,\s*(?=,|$)/', '', $title ) );
			$slug  = SSLP_Settings::build_slug( $settings['slug_pattern'], $placeholders );

			if ( '' === $slug ) {
				continue;
			}

			if ( $skip_dupes ) {
				$combo_key = $item . '|' . $city . '|' . $state;
				if ( isset( $existing_lookup[ $combo_key ] ) ) {
					continue;
				}
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => SSLP_CPT,
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => $status,
					'post_content' => '',
				),
				true
			);

			if ( ! is_wp_error( $post_id ) && $post_id ) {
				update_post_meta( $post_id, '_sslp_item', $item );
				update_post_meta( $post_id, '_sslp_city', $city );
				update_post_meta( $post_id, '_sslp_state', $state );
				if ( class_exists( 'SSLP_URL_Manager' ) ) {
					SSLP_URL_Manager::instance()->compute_and_store_path( $post_id );
				}
				$created++;
			}
		}

		if ( $is_last && class_exists( 'SSLP_URL_Manager' ) ) {
			SSLP_URL_Manager::set_bulk_mode( false );
			SSLP_URL_Manager::instance()->flush_rules();
		}

		wp_send_json_success( array( 'created' => $created ) );
	}

	/**
	 * Fetch every existing top-level (city) page's item/city/state combo in a
	 * single pass, keyed as "item|city|state" => true, so the bulk loop can
	 * do an in-memory lookup instead of one DB query per submitted line.
	 */
	private function get_existing_city_combos() {
		$ids = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $ids );

		$combos = array();
		foreach ( $ids as $post_id ) {
			$key = get_post_meta( $post_id, '_sslp_item', true ) . '|' .
				get_post_meta( $post_id, '_sslp_city', true ) . '|' .
				get_post_meta( $post_id, '_sslp_state', true );
			$combos[ $key ] = true;
		}

		return $combos;
	}

	/* ------------------------------------------------------------------ *
	 *  Sub-city (neighborhood) pages - children of an existing city page
	 * ------------------------------------------------------------------ */

	private function get_city_pages() {
		return get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				// Only top-level pages can be a "city" parent.
				'post_parent'    => 0,
			)
		);
	}

	public function render_subcity_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$result       = isset( $_GET['sslp_created'] ) ? intval( $_GET['sslp_created'] ) : null;
		$settings     = SSLP_Settings::instance()->get_settings();
		$city_pages   = $this->get_city_pages();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Generate Sub-City Pages', 'sslp' ); ?></h1>

			<?php if ( null !== $result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
					<?php
					printf(
						/* translators: %d number of pages created */
						esc_html__( '%d sub-city page(s) created.', 'sslp' ),
						$result
					);
					?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $city_pages ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: link to the city bulk generator */
						wp_kses(
							__( 'You need at least one city page first. Create some on the <a href="%s">Bulk Generate</a> screen, then come back here.', 'sslp' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'edit.php?post_type=' . SSLP_CPT . '&page=sslp-bulk-generate' ) )
					);
					?>
				</p>
			<?php else : ?>

			<p>
			<?php
			printf(
				/* translators: 1: sub-city title pattern, 2: example URL */
				esc_html__( 'Pick an existing city page and a list of neighborhoods/districts. One page is created per line, as a child of that city page, titled using the pattern "%1$s", with a URL like %2$s.', 'sslp' ),
				esc_html( $settings['subcity_post_title_pattern'] ),
				esc_html( '/' . $settings['url_slug'] . '/city-page-slug/' . SSLP_Settings::build_slug( $settings['subcity_slug_pattern'], array( '{subcity}' => 'Summerlin' ) ) . '/' )
			);
			?>
			<?php esc_html_e( 'Large lists are processed in small batches automatically, so there is no need to split them yourself.', 'sslp' ); ?>
			</p>

			<form method="post" id="sslp-bulk-subcity-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sslp_bulk_generate_subcities', 'sslp_bulk_subcity_nonce' ); ?>
				<input type="hidden" name="action" value="sslp_bulk_generate_subcities" />

				<table class="form-table">
					<tr>
						<th><label for="sslp_bulk_parent"><?php esc_html_e( 'City Page (parent)', 'sslp' ); ?></label></th>
						<td>
							<select required id="sslp_bulk_parent" name="sslp_bulk_parent">
								<option value=""><?php esc_html_e( '— Select a city page —', 'sslp' ); ?></option>
								<?php foreach ( $city_pages as $page ) : ?>
									<?php
									$city  = get_post_meta( $page->ID, '_sslp_city', true );
									$state = get_post_meta( $page->ID, '_sslp_state', true );
									$label = $page->post_title;
									if ( $city ) {
										$label .= ' — ' . trim( $city . ( $state ? ', ' . $state : '' ) );
									}
									?>
									<option value="<?php echo esc_attr( $page->ID ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="sslp_bulk_subcities"><?php esc_html_e( 'Sub-Cities / Neighborhoods', 'sslp' ); ?></label></th>
						<td>
							<textarea required id="sslp_bulk_subcities" name="sslp_bulk_subcities" rows="12" class="large-text" placeholder="Summerlin&#10;Henderson East&#10;Spring Valley"></textarea>
							<p class="description"><?php esc_html_e( 'One neighborhood/district per line.', 'sslp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Publish Status', 'sslp' ); ?></th>
						<td>
							<select id="sslp_bulk_subcity_status" name="sslp_bulk_status">
								<option value="draft"><?php esc_html_e( 'Draft (review before publishing)', 'sslp' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Publish immediately', 'sslp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Skip Duplicates', 'sslp' ); ?></th>
						<td>
							<label><input type="checkbox" id="sslp_bulk_subcity_skip_dupes" name="sslp_bulk_skip_dupes" value="1" checked="checked" /> <?php esc_html_e( 'Skip a sub-city if a child page with the same URL already exists under this parent', 'sslp' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Generate Sub-City Pages', 'sslp' ) ); ?>
			</form>

			<?php
			$this->render_progress_script(
				'sslp-bulk-subcity-form',
				'sslp_bulk_subcities',
				'sslp_bulk_generate_subcities_chunk',
				'sslp_bulk_generate_subcities_ajax',
				"body.set( 'parent_id', document.getElementById( 'sslp_bulk_parent' ).value );
					body.set( 'status', document.getElementById( 'sslp_bulk_subcity_status' ).value );
					body.set( 'skip_dupes', document.getElementById( 'sslp_bulk_subcity_skip_dupes' ).checked ? '1' : '0' );",
				__( '%d sub-city page(s) created.', 'sslp' )
			);
			?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: creates one chunk's worth of sub-city pages.
	 */
	public function ajax_generate_subcity_chunk() {
		check_ajax_referer( 'sslp_bulk_generate_subcities_ajax', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'sslp' ) ), 403 );
		}

		$parent_id  = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : 0;
		$status     = ( 'publish' === ( $_POST['status'] ?? '' ) ) ? 'publish' : 'draft';
		$skip_dupes = ! empty( $_POST['skip_dupes'] ) && '1' === $_POST['skip_dupes'];
		$is_last    = ! empty( $_POST['is_last'] ) && '1' === $_POST['is_last'];

		$parent = $parent_id ? get_post( $parent_id ) : null;
		if ( ! $parent || SSLP_CPT !== $parent->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid city page.', 'sslp' ) ), 400 );
		}

		$lines_json = wp_unslash( $_POST['lines'] ?? '[]' );
		$lines      = json_decode( $lines_json, true );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$settings = SSLP_Settings::instance()->get_settings();
		if ( class_exists( 'SSLP_URL_Manager' ) ) {
			SSLP_URL_Manager::set_bulk_mode( true );
		}

		$item  = get_post_meta( $parent_id, '_sslp_item', true );
		$city  = get_post_meta( $parent_id, '_sslp_city', true );
		$state = get_post_meta( $parent_id, '_sslp_state', true );
		$phone = get_post_meta( $parent_id, '_sslp_phone', true );

		$existing_subcities = array();
		if ( $skip_dupes ) {
			$existing_subcities = $this->get_existing_subcity_names( $parent_id );
		}

		$created = 0;

		foreach ( $lines as $subcity ) {
			$subcity = sanitize_text_field( (string) $subcity );
			if ( '' === $subcity ) {
				continue;
			}

			$placeholders = array(
				'{item}'     => $item,
				'{city}'     => $city,
				'{state}'    => $state,
				'{subcity}'  => $subcity,
				'{phone}'    => $phone,
				'{sitename}' => get_bloginfo( 'name' ),
			);
			$title = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $settings['subcity_post_title_pattern'] );
			$title = trim( preg_replace( '/\s*,\s*(?=,|$)/', '', $title ) );
			$slug  = SSLP_Settings::build_slug( $settings['subcity_slug_pattern'], $placeholders );

			if ( '' === $slug ) {
				continue;
			}

			if ( $skip_dupes && isset( $existing_subcities[ $subcity ] ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => SSLP_CPT,
					'post_parent'  => $parent_id,
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => $status,
					'post_content' => '',
				),
				true
			);

			if ( ! is_wp_error( $post_id ) && $post_id ) {
				update_post_meta( $post_id, '_sslp_subcity', $subcity );
				if ( class_exists( 'SSLP_URL_Manager' ) ) {
					SSLP_URL_Manager::instance()->compute_and_store_path( $post_id );
				}
				$created++;
			}
		}

		if ( $is_last && class_exists( 'SSLP_URL_Manager' ) ) {
			SSLP_URL_Manager::set_bulk_mode( false );
			SSLP_URL_Manager::instance()->flush_rules();
		}

		wp_send_json_success( array( 'created' => $created ) );
	}

	/**
	 * Fetch every existing sub-city name under a given parent city page in a
	 * single query, keyed by name, for an in-memory dupe check.
	 */
	private function get_existing_subcity_names( $parent_id ) {
		$ids = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $ids );

		$names = array();
		foreach ( $ids as $post_id ) {
			$name = get_post_meta( $post_id, '_sslp_subcity', true );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		return $names;
	}
}
