<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and registers fully configurable URLs for SSLP pages.
 *
 * Unlike WordPress's normal hierarchical CPT URLs, SSLP URLs are generated
 * from the URL patterns in Settings. This allows patterns containing multiple
 * path segments, for example:
 *   City:     {state}/{city}-{item}
 *   Sub-city: {state}/{city}/{subcity}-{item}
 *
 * Existing posts automatically use the current pattern because their
 * permalink is calculated from their saved targeting metadata.
 */
class SSLP_URL_Manager {

	private static $instance = null;
	private static $bulk_mode = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const PATH_META_KEY = '_sslp_full_path';

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'resolve_path_query' ) );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 20, 4 );
		add_action( 'save_post_' . SSLP_CPT, array( $this, 'on_save_post' ), 30, 3 );
		add_action( 'template_redirect', array( $this, 'redirect_old_permalink' ), 1 );
	}

	public static function set_bulk_mode( $enabled ) {
		self::$bulk_mode = (bool) $enabled;
	}

	private function settings() {
		return SSLP_Settings::instance()->get_settings();
	}

	/**
	 * Resolve all placeholders for a page. Sub-city values inherit from the
	 * parent city page when they are not stored on the child.
	 */
	public function get_placeholders( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || SSLP_CPT !== $post->post_type ) {
			return array();
		}

		$parent_id = wp_get_post_parent_id( $post_id );

		$item    = get_post_meta( $post_id, '_sslp_item', true );
		$city    = get_post_meta( $post_id, '_sslp_city', true );
		$state   = get_post_meta( $post_id, '_sslp_state', true );
		$phone   = get_post_meta( $post_id, '_sslp_phone', true );
		$subcity = get_post_meta( $post_id, '_sslp_subcity', true );

		if ( $parent_id ) {
			$item  = $item  !== '' ? $item  : get_post_meta( $parent_id, '_sslp_item', true );
			$city  = $city  !== '' ? $city  : get_post_meta( $parent_id, '_sslp_city', true );
			$state = $state !== '' ? $state : get_post_meta( $parent_id, '_sslp_state', true );
			$phone = $phone !== '' ? $phone : get_post_meta( $parent_id, '_sslp_phone', true );
		}

		if ( $phone === '' ) {
			$phone = $this->settings()['default_phone'];
		}

		return array(
			'{item}'     => $item,
			'{city}'     => $city,
			'{state}'    => $state,
			'{subcity}'  => $subcity,
			'{phone}'    => $phone,
			'{sitename}' => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Convert a configured pattern into a URL path.
	 * Slashes are preserved as path separators; each segment is sanitized.
	 */
	public function build_path( $pattern, $placeholders ) {
		$path = str_replace( array_keys( $placeholders ), array_values( $placeholders ), (string) $pattern );
		$segments = preg_split( '#/+?#', trim( $path, '/' ) );
		$clean = array();

		foreach ( $segments as $segment ) {
			$segment = sanitize_title( $segment );
			if ( $segment !== '' ) {
				$clean[] = $segment;
			}
		}

		return implode( '/', $clean );
	}

	public function get_path( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || SSLP_CPT !== $post->post_type ) {
			return '';
		}

		$s = $this->settings();
		$placeholders = $this->get_placeholders( $post_id );
		$parent_id = wp_get_post_parent_id( $post_id );

		$pattern = $parent_id
			? $s['subcity_slug_pattern']
			: $s['slug_pattern'];

		return $this->build_path( $pattern, $placeholders );
	}

	public function get_permalink( $post_id ) {
		$path = $this->get_path( $post_id );
		if ( '' === $path ) {
			return get_permalink( $post_id );
		}

		$base = sanitize_title( $this->settings()['url_slug'] );
		$url  = trim( $base . '/' . $path, '/' );

		return home_url( user_trailingslashit( $url ) );
	}

	public function filter_permalink( $post_link, $post, $leavename, $sample ) {
		if ( SSLP_CPT !== $post->post_type ) {
			return $post_link;
		}

		$custom = $this->get_permalink( $post->ID );
		return $custom ? $custom : $post_link;
	}

	/**
	 * Register ONE generic rewrite rule that captures everything after the
	 * base slug. The actual post is resolved in resolve_path_query() via an
	 * indexed postmeta lookup, not by enumerating every SSLP page here.
	 *
	 * IMPORTANT: this used to build one exact-match add_rewrite_rule() call
	 * per existing page, from a get_posts( posts_per_page => -1 ) query, and
	 * that whole loop ran on EVERY request because it was hooked to 'init'.
	 * On a site with many bulk-generated pages that meant every front-end
	 * and admin request re-queried and re-processed every single page,
	 * which is what was blowing past max_execution_time. A single generic
	 * rule plus a targeted meta lookup on actual matches fixes that.
	 */
	public function register_rewrite_rules() {
		$base = sanitize_title( $this->settings()['url_slug'] );
		if ( '' === $base ) {
			return;
		}

		add_rewrite_rule(
			'^' . preg_quote( $base, '#' ) . '/(.+?)/?$',
			'index.php?post_type=' . SSLP_CPT . '&sslp_path=$matches[1]',
			'top'
		);
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'sslp_path';
		return $vars;
	}

	/**
	 * Turn a matched "sslp_path" query var into the actual post via a single
	 * indexed meta lookup, instead of scanning every SSLP page per request.
	 */
	public function resolve_path_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$path = $query->get( 'sslp_path' );
		if ( ! $path ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'private', 'future' ),
				'meta_key'       => self::PATH_META_KEY,
				'meta_value'     => untrailingslashit( $path ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			$query->set_404();
			return;
		}

		$query->set( 'p', $posts[0] );
		$query->set( 'post_type', SSLP_CPT );
		$query->set( 'name', '' );
		$query->set( 'sslp_path', '' );
	}

	/**
	 * Compute and persist a page's current full path as postmeta so
	 * resolve_path_query() can find it with one indexed lookup. Called
	 * whenever a page (or its parent) is saved or bulk-created.
	 */
	public function compute_and_store_path( $post_id ) {
		$path = $this->get_path( $post_id );
		if ( '' !== $path ) {
			update_post_meta( $post_id, self::PATH_META_KEY, $path );
		} else {
			delete_post_meta( $post_id, self::PATH_META_KEY );
		}
		return $path;
	}

	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->recompute_path_for_post( $post_id );
		if ( ! self::$bulk_mode ) {
			$this->flush_rules();
		}
	}

	public function flush_rules() {
		$this->register_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Recompute the path for one post, plus its direct children if it's a
	 * top-level (city) page - since sub-city pages inherit item/city/state
	 * from the parent, a parent edit can change their computed paths too.
	 * Scoped to just this page's own children, not every SSLP page on the
	 * site, so a single post save stays cheap regardless of site size.
	 */
	public function recompute_path_for_post( $post_id ) {
		$this->compute_and_store_path( $post_id );

		if ( wp_get_post_parent_id( $post_id ) ) {
			return; // Sub-city pages have no children of their own.
		}

		$child_ids = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post_parent'    => $post_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $child_ids as $child_id ) {
			$this->compute_and_store_path( $child_id );
		}
	}

	/**
	 * Settings changes call this so existing pages immediately receive the
	 * new configured URL structure. This is the one place it's still
	 * correct to loop over every page - it happens once, when an admin
	 * actually changes the URL patterns, not on every request or every
	 * individual post save.
	 */
	public static function flush_after_settings_change() {
		$instance = self::instance();
		$instance->recompute_all_paths();
		$instance->flush_rules();
	}

	/**
	 * Recompute and store the full path for every SSLP page. Only called
	 * from an explicit admin action (settings save, end of a bulk-generate
	 * batch) - never from a per-request hook.
	 */
	public function recompute_all_paths() {
		$posts = get_posts(
			array(
				'post_type'      => SSLP_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return;
		}

		update_meta_cache( 'post', $posts );

		$parent_ids = array();
		foreach ( $posts as $post_id ) {
			$parent_id = wp_get_post_parent_id( $post_id );
			if ( $parent_id ) {
				$parent_ids[] = $parent_id;
			}
		}
		if ( ! empty( $parent_ids ) ) {
			update_meta_cache( 'post', array_unique( $parent_ids ) );
		}

		foreach ( $posts as $post_id ) {
			$this->compute_and_store_path( $post_id );
		}
	}

	/**
	 * If an old URL still resolves through WordPress's normal CPT rewrite,
	 * send it to the newly configured canonical URL.
	 */
	public function redirect_old_permalink() {
		if ( ! is_singular( SSLP_CPT ) || is_admin() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$canonical = $this->get_permalink( $post_id );
		$current   = home_url( add_query_arg( array(), wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );

		$current_path   = wp_parse_url( $current, PHP_URL_PATH );
		$canonical_path = wp_parse_url( $canonical, PHP_URL_PATH );

		if ( $current_path && $canonical_path && untrailingslashit( $current_path ) !== untrailingslashit( $canonical_path ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}
}
