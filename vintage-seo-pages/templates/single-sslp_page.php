<?php
/**
 * Default single template for SEO Landing Pages.
 * A theme can override this by adding its own single-sslp_page.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="sslp-main" class="sslp-landing-page">
	<div class="sslp-container">
		<?php
		while ( have_posts() ) :
			the_post();
			$post_id = get_the_ID();
			$city    = get_post_meta( $post_id, '_sslp_city', true );
			$state   = get_post_meta( $post_id, '_sslp_state', true );
			$subcity = get_post_meta( $post_id, '_sslp_subcity', true );
			$phone   = get_post_meta( $post_id, '_sslp_phone', true );
			$parent_id = wp_get_post_parent_id( $post_id );
			if ( $parent_id ) {
				$city  = '' !== $city ? $city : get_post_meta( $parent_id, '_sslp_city', true );
				$state = '' !== $state ? $state : get_post_meta( $parent_id, '_sslp_state', true );
				$phone = '' !== $phone ? $phone : get_post_meta( $parent_id, '_sslp_phone', true );
			}
			if ( '' === $phone ) {
				$settings = SSLP_Settings::instance()->get_settings();
				$phone    = $settings['default_phone'];
			}
			?>
			<article <?php post_class( 'sslp-article' ); ?>>

				<?php if ( $parent_id && $subcity ) : ?>
					<p class="sslp-breadcrumb">
						<a href="<?php echo esc_url( get_permalink( $parent_id ) ); ?>"><?php echo esc_html( get_the_title( $parent_id ) ); ?></a>
						<span aria-hidden="true"> &raquo; </span><?php echo esc_html( $subcity ); ?>
					</p>
				<?php endif; ?>

				<header class="sslp-header">
					<h1 class="sslp-title"><?php the_title(); ?></h1>
					<?php if ( $city || $subcity ) : ?>
						<p class="sslp-location-badge">
							<?php
							$badge = $subcity ? $subcity . ( $city ? ', ' . $city : '' ) : $city;
							echo esc_html( trim( $badge . ( $state ? ', ' . $state : '' ) ) );
							?>
						</p>
					<?php endif; ?>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<div class="sslp-featured-image"><?php the_post_thumbnail( 'large' ); ?></div>
				<?php endif; ?>

				<div class="sslp-content">
					<?php the_content(); ?>
				</div>

				<?php if ( $phone ) : ?>
					<div class="sslp-cta">
						<a class="sslp-cta-button" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>">
							<?php echo esc_html__( 'Call Now:', 'sslp' ) . ' ' . esc_html( $phone ); ?>
						</a>
					</div>
				<?php endif; ?>

			</article>
		<?php endwhile; ?>
	</div>
</main>

<?php
get_footer();
