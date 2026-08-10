<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslp_directory item="Vintage Silver"] — lists links to every city page
 * for that item. Use this on a hub/parent page (e.g. "Where We Buy") to
 * internally link all your city pages, which helps them get indexed and
 * pass authority.
 *
 * [sslp_directory] with no attribute lists everything, grouped by item.
 */
class SSLP_Shortcodes {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'sslp_directory', array( $this, 'render_directory' ) );
	}

	public function render_directory( $atts ) {
		$atts = shortcode_atts( array( 'item' => '' ), $atts, 'sslp_directory' );

		// Only top-level pages are "city" pages - sub-city pages are nested
		// underneath their parent's entry instead of listed flat, so the
		// hub page mirrors the actual URL structure.
		$query_args = array(
			'post_type'      => SSLP_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_parent'    => 0,
		);
		if ( $atts['item'] ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_sslp_item',
					'value' => $atts['item'],
				),
			);
		}

		$query = new WP_Query( $query_args );
		if ( ! $query->have_posts() ) {
			return '';
		}

		$groups = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$item    = get_post_meta( $post_id, '_sslp_item', true );
			$item    = $item ? $item : __( 'Other', 'sslp' );

			$children = get_posts(
				array(
					'post_type'      => SSLP_CPT,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'post_parent'    => $post_id,
				)
			);
			$sub_pages = array();
			foreach ( $children as $child ) {
				$sub_pages[] = array(
					'title' => get_the_title( $child ),
					'url'   => get_permalink( $child ),
				);
			}

			$groups[ $item ][] = array(
				'title'     => get_the_title(),
				'url'       => get_permalink(),
				'sub_pages' => $sub_pages,
			);
		}
		wp_reset_postdata();

		ob_start();
		echo '<div class="sslp-directory">';
		foreach ( $groups as $item_name => $pages ) {
			if ( ! $atts['item'] ) {
				echo '<h3 class="sslp-directory-heading">' . esc_html( $item_name ) . '</h3>';
			}
			echo '<ul class="sslp-directory-list">';
			foreach ( $pages as $p ) {
				echo '<li><a href="' . esc_url( $p['url'] ) . '">' . esc_html( $p['title'] ) . '</a>';
				if ( ! empty( $p['sub_pages'] ) ) {
					echo '<ul class="sslp-directory-sublist">';
					foreach ( $p['sub_pages'] as $sp ) {
						echo '<li><a href="' . esc_url( $sp['url'] ) . '">' . esc_html( $sp['title'] ) . '</a></li>';
					}
					echo '</ul>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		return ob_get_clean();
	}
}
