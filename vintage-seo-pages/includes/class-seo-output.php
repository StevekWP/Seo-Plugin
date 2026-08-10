<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces {item}/{city}/{state}/{phone}/{sitename} placeholders and
 * outputs meta title, meta description, and LocalBusiness schema.
 */
class SSLP_SEO_Output {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'document_title_parts', array( $this, 'filter_title' ) );
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 5 );
		add_action( 'wp_head', array( $this, 'output_schema' ), 20 );
		add_filter( 'the_content', array( $this, 'filter_content' ) );
	}

	/**
	 * Build the placeholder map for a given landing page post.
	 */
	public function get_placeholders( $post_id ) {
		$settings = SSLP_Settings::instance()->get_settings();
		$item     = get_post_meta( $post_id, '_sslp_item', true );
		$city     = get_post_meta( $post_id, '_sslp_city', true );
		$state    = get_post_meta( $post_id, '_sslp_state', true );
		$subcity  = get_post_meta( $post_id, '_sslp_subcity', true );
		$phone    = get_post_meta( $post_id, '_sslp_phone', true );

		// Sub-city pages inherit item/city/state/phone from their parent city
		// page when left blank, so you only have to fill those in once.
		$parent_id = wp_get_post_parent_id( $post_id );
		if ( $parent_id ) {
			if ( '' === $item ) {
				$item = get_post_meta( $parent_id, '_sslp_item', true );
			}
			if ( '' === $city ) {
				$city = get_post_meta( $parent_id, '_sslp_city', true );
			}
			if ( '' === $state ) {
				$state = get_post_meta( $parent_id, '_sslp_state', true );
			}
			if ( '' === $phone ) {
				$phone = get_post_meta( $parent_id, '_sslp_phone', true );
			}
		}

		return array(
			'{item}'     => $item,
			'{city}'     => $city,
			'{state}'    => $state,
			'{subcity}'  => $subcity,
			'{phone}'    => $phone ? $phone : $settings['default_phone'],
			'{sitename}' => get_bloginfo( 'name' ),
		);
	}

	public function replace( $text, $post_id ) {
		$map = $this->get_placeholders( $post_id );
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}

	/**
	 * Auto-fill the page content from the default template when the
	 * landing page's own editor was left empty. If the author typed
	 * content (with or without placeholders), that is used instead and
	 * placeholders inside it are still replaced.
	 */
	public function filter_content( $content ) {
		if ( ! is_singular( SSLP_CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_id = get_the_ID();

		if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
			$settings = SSLP_Settings::instance()->get_settings();
			$content  = $settings['content_template'];
		}

		return $this->replace( $content, $post_id );
	}

	public function filter_title( $title_parts ) {
		if ( is_singular( SSLP_CPT ) ) {
			$post_id  = get_the_ID();
			$override = get_post_meta( $post_id, '_sslp_meta_title', true );
			$settings = SSLP_Settings::instance()->get_settings();
			$pattern  = $override ? $override : $settings['title_pattern'];
			$title_parts['title'] = $this->replace( $pattern, $post_id );
			unset( $title_parts['site'] ); // pattern already includes {sitename} if desired.
		}
		return $title_parts;
	}

	public function output_meta_description() {
		if ( ! is_singular( SSLP_CPT ) ) {
			return;
		}
		$post_id  = get_the_ID();
		$override = get_post_meta( $post_id, '_sslp_meta_description', true );
		$settings = SSLP_Settings::instance()->get_settings();
		$pattern  = $override ? $override : $settings['description_pattern'];
		$desc     = $this->replace( $pattern, $post_id );
		echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $desc ) ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( wp_strip_all_tags( $desc ) ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '" />' . "\n";
	}

	public function output_schema() {
		if ( ! is_singular( SSLP_CPT ) ) {
			return;
		}
		$settings = SSLP_Settings::instance()->get_settings();
		if ( empty( $settings['enable_schema'] ) ) {
			return;
		}
		$post_id = get_the_ID();
		$map     = $this->get_placeholders( $post_id );

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'LocalBusiness',
			'name'        => $settings['business_name'] ? $settings['business_name'] : $map['{sitename}'],
			'description' => wp_strip_all_tags( $this->replace( $settings['description_pattern'], $post_id ) ),
			'url'         => get_permalink( $post_id ),
			'areaServed'  => trim( $map['{city}'] . ( $map['{state}'] ? ', ' . $map['{state}'] : '' ) ),
		);
		if ( ! empty( $map['{phone}'] ) ) {
			$schema['telephone'] = $map['{phone}'];
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}
}
