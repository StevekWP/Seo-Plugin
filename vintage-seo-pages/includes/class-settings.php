<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen: business info + default title / meta description / content templates
 * + URL structure patterns for city pages and sub-city (neighborhood) pages.
 * Placeholders supported everywhere: {item} {city} {state} {subcity} {phone} {sitename}
 */
class SSLP_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_sslp_settings', array( $this, 'settings_updated' ), 10, 3 );
	}

	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SSLP_CPT,
			__( 'SEO Landing Pages Settings', 'sslp' ),
			__( 'Settings', 'sslp' ),
			'manage_options',
			'sslp-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'sslp_settings_group', 'sslp_settings', array( $this, 'sanitize' ) );
	}

	/**
	 * Sanitize a slug PATTERN (not a finished slug). We keep the placeholder
	 * tokens intact (they contain "{" "}") and only strip genuinely unsafe
	 * characters, since sanitize_title() would otherwise destroy the braces.
	 */
	private function sanitize_pattern( $pattern, $fallback ) {
		$pattern = isset( $pattern ) ? wp_strip_all_tags( (string) $pattern ) : '';
		$pattern = trim( preg_replace( '/\s+/', ' ', $pattern ) );
		return '' !== $pattern ? $pattern : $fallback;
	}

	public function sanitize( $input ) {
		$defaults = $this->defaults();
		$out      = array();

		$out['business_name']     = sanitize_text_field( $input['business_name'] ?? '' );
		$out['primary_item']      = sanitize_text_field( $input['primary_item'] ?? '' );
		$out['default_phone']     = sanitize_text_field( $input['default_phone'] ?? '' );
		$out['url_slug']          = sanitize_title( $input['url_slug'] ?? $defaults['url_slug'] );

		$out['slug_pattern']         = $this->sanitize_pattern( $input['slug_pattern'] ?? '', $defaults['slug_pattern'] );
		$out['subcity_slug_pattern'] = $this->sanitize_pattern( $input['subcity_slug_pattern'] ?? '', $defaults['subcity_slug_pattern'] );

		$out['title_pattern']       = sanitize_text_field( $input['title_pattern'] ?? $defaults['title_pattern'] );
		$out['post_title_pattern']  = sanitize_text_field( $input['post_title_pattern'] ?? $defaults['post_title_pattern'] );
		$out['subcity_post_title_pattern'] = sanitize_text_field( $input['subcity_post_title_pattern'] ?? $defaults['subcity_post_title_pattern'] );
		$out['description_pattern'] = sanitize_textarea_field( $input['description_pattern'] ?? $defaults['description_pattern'] );
		$out['content_template']    = wp_kses_post( $input['content_template'] ?? '' );
		$out['enable_schema']       = ! empty( $input['enable_schema'] ) ? 1 : 0;
		return $out;
	}

	public function defaults() {
		return array(
			'business_name'       => get_bloginfo( 'name' ),
			'primary_item'        => '',
			'default_phone'       => '',
			'url_slug'            => 'locations',

			// URL structure. {item}/{city}/{state}/{subcity} are run through
			// sanitize_title() together, so spaces, punctuation and case are
			// normalized automatically - just arrange the tokens how you want
			// them to appear in the URL.
			'slug_pattern'         => '{state}/{city}-{item}',
			'subcity_slug_pattern' => '{state}/{city}/{subcity}-{item}',

			'title_pattern'              => '{item} Buyer in {city}, {state} | {sitename}',
			'post_title_pattern'         => '{item} Buyer in {city}, {state}',
			'subcity_post_title_pattern' => '{item} Buyer in {subcity}, {city}, {state}',
			'description_pattern' => 'Looking to sell {item} in {city}, {state}? We buy {item} locally with fast, fair, same-day offers. Call {phone} for a free quote.',
			'content_template'    => "<h2>We Buy {item} in {city}, {state}</h2>\n<p>If you have {item} to sell in {city}, our local buyers offer fair, same-day cash offers with no obligation. We've helped sellers throughout the {city} area turn their items into cash quickly and safely.</p>\n<h3>Why Sell Your {item} to Us?</h3>\n<ul>\n<li>Free, no-obligation evaluation</li>\n<li>Fair market pricing</li>\n<li>Fast, same-day payment</li>\n<li>Serving {city}, {state} and surrounding areas</li>\n</ul>\n<h3>Get a Quote Today</h3>\n<p>Call us at {phone} or fill out the form below to get started.</p>",
			'enable_schema'       => 1,
		);
	}

	public function get_settings() {
		$saved = get_option( 'sslp_settings', array() );
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Turn a pattern like "{item}-{state}-{city}" plus a placeholder map into
	 * a real, safe slug ("vintage-silver-nv-las-vegas"). Used for both the
	 * main city pages and sub-city (child) pages so the URL structure is
	 * fully driven by the patterns set in Settings.
	 */
	public static function build_slug( $pattern, $placeholders ) {
		$path = str_replace( array_keys( $placeholders ), array_values( $placeholders ), (string) $pattern );
		$segments = preg_split( '#/+?#', trim( $path, '/' ) );
		$clean = array();
		foreach ( $segments as $segment ) {
			$segment = sanitize_title( $segment );
			if ( '' !== $segment ) {
				$clean[] = $segment;
			}
		}
		return implode( '/', $clean );
	}

	public function settings_updated( $old_value, $value, $option ) {
		if ( ! class_exists( 'SSLP_URL_Manager' ) ) {
			return;
		}
		if ( $old_value !== $value ) {
			SSLP_URL_Manager::flush_after_settings_change();
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Landing Pages — Settings', 'sslp' ); ?></h1>
			<p><?php esc_html_e( 'These patterns apply to every landing page unless a page has its own override. Available placeholders: {item} {city} {state} {subcity} {phone} {sitename}', 'sslp' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'sslp_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="business_name"><?php esc_html_e( 'Business Name', 'sslp' ); ?></label></th>
						<td><input type="text" class="regular-text" name="sslp_settings[business_name]" value="<?php echo esc_attr( $s['business_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="primary_item"><?php esc_html_e( 'Primary Title {item}', 'sslp' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="sslp_settings[primary_item]" value="<?php echo esc_attr( $s['primary_item'] ); ?>" placeholder="e.g. Vintage Silver, Gold Coins, HVAC Repair" />
						</td>
					</tr>
					<tr>
						<th><label for="default_phone"><?php esc_html_e( 'Default Phone Number', 'sslp' ); ?></label></th>
						<td><input type="text" class="regular-text" name="sslp_settings[default_phone]" value="<?php echo esc_attr( $s['default_phone'] ); ?>" /></td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-bottom:0;"><?php esc_html_e( 'URL Structure', 'sslp' ); ?></h2></th>
					</tr>
					<tr>
						<th><label for="url_slug"><?php esc_html_e( 'URL Base Slug', 'sslp' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="sslp_settings[url_slug]" value="<?php echo esc_attr( $s['url_slug'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The first path segment for every landing page, e.g. "locations".', 'sslp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="slug_pattern"><?php esc_html_e( 'City Page URL Pattern', 'sslp' ); ?></label></th>
						<td>
							<code>/<?php echo esc_html( $s['url_slug'] ); ?>/</code>
							<input type="text" class="regular-text" id="slug_pattern" name="sslp_settings[slug_pattern]" value="<?php echo esc_attr( $s['slug_pattern'] ); ?>" />
							<code>/</code>
							<p class="description">
								<?php
								printf(
									/* translators: %s: example resulting slug */
									esc_html__( 'Controls the full URL path after the base slug for each city page. You can use "/" to create multiple URL segments. Placeholders: {item} {city} {state} {phone} {sitename}. Example with the default pattern: %s', 'sslp' ),
									'<code>' . esc_html( self::build_slug( $s['slug_pattern'], array( '{item}' => 'Vintage Silver', '{city}' => 'Las Vegas', '{state}' => 'NV' ) ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="subcity_slug_pattern"><?php esc_html_e( 'Sub-City URL Pattern', 'sslp' ); ?></label></th>
						<td>
							<code>/<?php echo esc_html( $s['url_slug'] ); ?>/</code>
							<input type="text" class="regular-text" id="subcity_slug_pattern" name="sslp_settings[subcity_slug_pattern]" value="<?php echo esc_attr( $s['subcity_slug_pattern'] ); ?>" />
							<code>/</code>
							<p class="description">
								<?php
								printf(
									/* translators: %s: example resulting slug */
									esc_html__( 'Controls the full URL path after the base slug for sub-city pages. You can use any configured placeholders, including {state}, {city}, {subcity} and {item}. Example: %s', 'sslp' ),
									'<code>' . esc_html( self::build_slug( $s['subcity_slug_pattern'], array( '{subcity}' => 'Summerlin' ) ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-bottom:0;"><?php esc_html_e( 'Content Patterns', 'sslp' ); ?></h2></th>
					</tr>
					<tr>
						<th><label for="post_title_pattern"><?php esc_html_e( 'City Page Title Pattern (H1 / post title)', 'sslp' ); ?></label></th>
						<td>
							<input type="text" class="large-text" name="sslp_settings[post_title_pattern]" value="<?php echo esc_attr( $s['post_title_pattern'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="subcity_post_title_pattern"><?php esc_html_e( 'Sub-City Page Title Pattern (H1 / post title)', 'sslp' ); ?></label></th>
						<td>
							<input type="text" class="large-text" name="sslp_settings[subcity_post_title_pattern]" value="<?php echo esc_attr( $s['subcity_post_title_pattern'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="title_pattern"><?php esc_html_e( 'Default Meta Title Pattern (browser tab / search result)', 'sslp' ); ?></label></th>
						<td><input type="text" class="large-text" name="sslp_settings[title_pattern]" value="<?php echo esc_attr( $s['title_pattern'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="description_pattern"><?php esc_html_e( 'Default Meta Description Pattern', 'sslp' ); ?></label></th>
						<td><textarea class="large-text" rows="3" name="sslp_settings[description_pattern]"><?php echo esc_textarea( $s['description_pattern'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="content_template"><?php esc_html_e( 'Default Page Content Template', 'sslp' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								$s['content_template'],
								'sslp_content_template_editor',
								array(
									'textarea_name' => 'sslp_settings[content_template]',
									'textarea_rows' => 14,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Used automatically when a landing page\'s own content editor is left empty. Works for sub-city pages too - {subcity} will resolve, {city}/{state} fall back to the parent city.', 'sslp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schema Markup', 'sslp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sslp_settings[enable_schema]" value="1" <?php checked( $s['enable_schema'], 1 ); ?> />
								<?php esc_html_e( 'Output LocalBusiness structured data (JSON-LD) on each landing page', 'sslp' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off if an SEO plugin (Yoast, RankMath, etc.) already outputs schema for these pages, to avoid duplicates.', 'sslp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
