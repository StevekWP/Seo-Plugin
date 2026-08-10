<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page fields: Item, City, State, Phone override, Meta description override.
 */
class SSLP_Meta_Box {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_boxes' ) );
		add_action( 'save_post_' . SSLP_CPT, array( $this, 'save' ) );
	}

	public function add_boxes() {
		add_meta_box(
			'sslp_targeting',
			__( 'Page Targeting (Item / City)', 'sslp' ),
			array( $this, 'render_targeting_box' ),
			SSLP_CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'sslp_seo',
			__( 'Overrides (optional)', 'sslp' ),
			array( $this, 'render_seo_box' ),
			SSLP_CPT,
			'normal',
			'default'
		);
	}

	private function field( $post_id, $key, $label, $placeholder = '', $type = 'text' ) {
		$value = get_post_meta( $post_id, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br/>';
		if ( 'textarea' === $type ) {
			echo '<textarea style="width:100%;" rows="3" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="text" style="width:100%;" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
		}
		echo '</p>';
	}

	public function render_targeting_box( $post ) {
		wp_nonce_field( 'sslp_save_meta', 'sslp_meta_nonce' );
		echo '<p>' . esc_html__( 'These values fill in the {item}, {city}, {state}, {subcity} and {phone} placeholders used in your default content/title/meta-description templates (set under Landing Pages > Settings).', 'sslp' ) . '</p>';
		$this->field( $post->ID, '_sslp_item', __( 'Item / Service (e.g. Vintage Silver)', 'sslp' ), 'Vintage Silver' );
		$this->field( $post->ID, '_sslp_city', __( 'City', 'sslp' ), 'Las Vegas' );
		$this->field( $post->ID, '_sslp_state', __( 'State / Region', 'sslp' ), 'NV' );
		$this->field( $post->ID, '_sslp_subcity', __( 'Sub-City / Neighborhood (optional)', 'sslp' ), 'e.g. Summerlin — leave blank for a main city page' );
		$this->render_parent_field( $post );
		$this->field( $post->ID, '_sslp_phone', __( 'Phone (leave blank to use site default)', 'sslp' ), '(702) 555-0100' );
	}

	/**
	 * Custom Parent (city page) selector. Native "Page Attributes > Parent"
	 * only exists for hierarchical post types, and this CPT is intentionally
	 * non-hierarchical (see class-post-type.php) to keep the admin list
	 * screen fast, so sub-city pages get linked to their city page here
	 * instead - it works the same way, via post_parent.
	 */
	private function render_parent_field( $post ) {
		$current_parent = (int) $post->post_parent;

		$city_pages = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'exclude'        => array( $post->ID ),
			)
		);

		echo '<p><label for="sslp_parent_id"><strong>' . esc_html__( 'Parent (City Page)', 'sslp' ) . '</strong></label><br/>';
		echo '<select id="sslp_parent_id" name="sslp_parent_id" style="width:100%;">';
		echo '<option value="0">' . esc_html__( '— None (this is a main city page) —', 'sslp' ) . '</option>';
		foreach ( $city_pages as $page ) {
			echo '<option value="' . esc_attr( $page->ID ) . '" ' . selected( $current_parent, $page->ID, false ) . '>' . esc_html( $page->post_title ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Set a Sub-City above AND choose its city page here so the URL nests underneath it.', 'sslp' ) . '</p>';
		echo '</p>';
	}

	public function render_seo_box( $post ) {
		$this->field( $post->ID, '_sslp_meta_title', __( 'Meta Title override', 'sslp' ), 'Leave blank to use default pattern' );
		$this->field( $post->ID, '_sslp_meta_description', __( 'Meta Description override', 'sslp' ), 'Leave blank to use default pattern', 'textarea' );
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['sslp_meta_nonce'] ) || ! wp_verify_nonce( $_POST['sslp_meta_nonce'], 'sslp_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array( '_sslp_item', '_sslp_city', '_sslp_state', '_sslp_subcity', '_sslp_phone', '_sslp_meta_title' );
		foreach ( $fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		if ( isset( $_POST['_sslp_meta_description'] ) ) {
			update_post_meta( $post_id, '_sslp_meta_description', sanitize_textarea_field( wp_unslash( $_POST['_sslp_meta_description'] ) ) );
		}

		// Custom Parent (city page) selector - see render_parent_field().
		// Not hierarchical, so this drives post_parent instead of the
		// native (unavailable) Page Attributes parent dropdown.
		if ( isset( $_POST['sslp_parent_id'] ) ) {
			$new_parent = absint( $_POST['sslp_parent_id'] );
			if ( $new_parent === $post_id ) {
				$new_parent = 0; // A page can't be its own parent.
			}
			$post = get_post( $post_id );
			if ( $post && (int) $post->post_parent !== $new_parent ) {
				remove_action( 'save_post_' . SSLP_CPT, array( $this, 'save' ) );
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_parent' => $new_parent,
					)
				);
				add_action( 'save_post_' . SSLP_CPT, array( $this, 'save' ) );
			}
		}

		// Targeting metadata determines the canonical URL, so refresh just
		// this page's path (and its children's, if it's a city page) -
		// not every SSLP page on the site.
		if ( class_exists( 'SSLP_URL_Manager' ) ) {
			SSLP_URL_Manager::instance()->recompute_path_for_post( $post_id );
		}
	}
}
