<?php
/**
 * Plugin Name: SEO Landing Pages
 * Description: Create SEO-targeted landing pages
 * Version:     1.3.0
 * Text Domain: sslp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SSLP_VERSION', '1.3.0' );
define( 'SSLP_PLUGIN_FILE', __FILE__ );
define( 'SSLP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSLP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SSLP_CPT', 'sslp_page' ); // custom post type slug

require_once SSLP_PLUGIN_DIR . 'includes/class-post-type.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-meta-box.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-settings.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-url-manager.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-bulk-generator.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-seo-output.php';
require_once SSLP_PLUGIN_DIR . 'includes/class-shortcodes.php';

/**
 * Boot everything up.
 */
function sslp_init_plugin() {
	SSLP_Post_Type::instance();
	SSLP_Meta_Box::instance();
	SSLP_Settings::instance();
	SSLP_URL_Manager::instance();
	SSLP_Bulk_Generator::instance();
	SSLP_SEO_Output::instance();
	SSLP_Shortcodes::instance();
}
add_action( 'plugins_loaded', 'sslp_init_plugin' );

/**
 * Load the front-end stylesheet only on our CPT.
 */
function sslp_enqueue_assets() {
	if ( is_singular( SSLP_CPT ) ) {
		wp_enqueue_style( 'sslp-frontend', SSLP_PLUGIN_URL . 'assets/css/frontend.css', array(), SSLP_VERSION );
	}
}
add_action( 'wp_enqueue_scripts', 'sslp_enqueue_assets' );

/**
 * Activation: register CPT then flush rewrite rules so pretty permalinks work immediately.
 */
function sslp_activate() {
	SSLP_Post_Type::instance()->register_post_type();
	// Populate the URL-path meta used for fast page lookups (migration for
	// sites that already had pages from before this was introduced).
	SSLP_URL_Manager::instance()->recompute_all_paths();
	SSLP_URL_Manager::instance()->flush_rules();
}
register_activation_hook( __FILE__, 'sslp_activate' );

/**
 * Deactivation: flush rewrite rules to clean up.
 */
function sslp_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'sslp_deactivate' );
