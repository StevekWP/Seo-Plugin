<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "SEO Landing Page" custom post type.
 */
class SSLP_Post_Type {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'manage_' . SSLP_CPT . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . SSLP_CPT . '_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );
		add_filter( 'template_include', array( $this, 'load_template' ) );
	}

	public function register_post_type() {
		$options    = get_option( 'sslp_settings', array() );
		$base_slug  = ! empty( $options['url_slug'] ) ? sanitize_title( $options['url_slug'] ) : 'locations';

		$labels = array(
			'name'               => __( 'Learn', 'sslp' ),
			'singular_name'      => __( 'Learn', 'sslp' ),
			'add_new'            => __( 'Add New', 'sslp' ),
			'add_new_item'       => __( 'Add Landing Page', 'sslp' ),
			'edit_item'          => __( 'Edit Landing Page', 'sslp' ),
			'new_item'           => __( 'New Landing Page', 'sslp' ),
			'view_item'          => __( 'View Landing Page', 'sslp' ),
			'search_items'       => __( 'Search Landing Pages', 'sslp' ),
			'not_found'          => __( 'No landing pages found', 'sslp' ),
			'not_found_in_trash' => __( 'No landing pages found in Trash', 'sslp' ),
			'menu_name'          => __( 'Learn', 'sslp' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => true,
			'show_in_rest'  => true, // Gutenberg support.
			'menu_position' => 20,
			'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
			'has_archive'   => false,
			// Intentionally NOT hierarchical: WordPress's admin list screen
			// for hierarchical post types loads every single post of that
			// type in one query to build the parent/child tree (the same
			// reason the core "Pages" screen gets slow on large sites) -
			// with thousands of bulk-generated landing pages that blew past
			// max_execution_time just opening the admin list. Sub-city
			// pages still nest under a city page via the normal post_parent
			// field (set through our own "Parent" field in the meta box,
			// see class-meta-box.php) - hierarchical=false only changes how
			// wp-admin queries/displays the list, not whether post_parent
			// works.
			'hierarchical'  => false,
			'rewrite'       => array(
				'slug'       => $base_slug,
				'with_front' => false,
			),
		);

		register_post_type( SSLP_CPT, $args );
	}

	/**
	 * Admin list table: show Item / City / Published status at a glance.
	 */
	public function add_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sslp_item'] = __( 'Item', 'sslp' );
				$new['sslp_city'] = __( 'City', 'sslp' );
				$new['sslp_subcity'] = __( 'Sub-City', 'sslp' );
			}
		}
		return $new;
	}

	public function render_columns( $column, $post_id ) {
		if ( 'sslp_item' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_sslp_item', true ) );
		}
		if ( 'sslp_city' === $column ) {
			$city  = get_post_meta( $post_id, '_sslp_city', true );
			$state = get_post_meta( $post_id, '_sslp_state', true );
			echo esc_html( trim( $city . ( $state ? ', ' . $state : '' ) ) );
		}
		if ( 'sslp_subcity' === $column ) {
			$subcity = get_post_meta( $post_id, '_sslp_subcity', true );
			if ( $subcity ) {
				echo esc_html( $subcity );
			} else {
				echo '<span aria-hidden="true">—</span>';
			}
		}
	}

	/**
	 * Use the plugin's own template for single views unless the active theme
	 * provides a single-sslp_page.php of its own.
	 */
	public function load_template( $template ) {
		if ( is_singular( SSLP_CPT ) ) {
			$theme_template = locate_template( array( 'single-' . SSLP_CPT . '.php' ) );
			if ( $theme_template ) {
				return $theme_template;
			}
			return SSLP_PLUGIN_DIR . 'templates/single-sslp_page.php';
		}
		return $template;
	}
}
