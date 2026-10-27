<?php
/**
 * 404 — Page Not Found
 *
 * WordPress falls back to this template for any unmatched URL. Mirrors the
 * non-winner / daily-limit page layout so the styling stays consistent.
 * Includes a "Back to start" button that returns the visitor to the age
 * gate (the canonical entry point for this promo).
 */
defined( 'ABSPATH' ) || exit;

$bg_deco = function_exists( 'pepsi_resolve_bg_deco' )
	? pepsi_resolve_bg_deco( 'non-winner' )
	: [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
$bg_deco_url = function_exists( 'pepsi_resolve_asset_url' )
	? pepsi_resolve_asset_url( 'bg_deco_image', 'non-winner' )
	: ( function_exists( 'bpm_img_url' ) ? bpm_img_url( 'bg_deco_image', 'non-winner' ) : '' );

$GLOBALS['pepsi_site_wrapper_style'] = sprintf(
	"background-image: url('%s'); background-position: %s; background-size: %s;",
	esc_url( $bg_deco_url ),
	esc_attr( $bg_deco['position'] ?? '50% 100%' ),
	esc_attr( $bg_deco['size'] ?? '100%' )
);

$age_gate_url = function_exists( 'pepsi_get_page_url_by_template' )
	? pepsi_get_page_url_by_template( 'page-age-gate.php' )
	: '';
if ( $age_gate_url === '' ) {
	$age_gate_url = home_url( '/' );
}

get_header();
?>

<main class="congrats-page page-non-winner page-404" data-js="404-page">

	<div class="congrats-content page-non-winner__content">

		<h1 class="congrats-sorry__heading">404</h1>

		<p class="congrats-sorry__sub">We can't find<br/> that page.</p>

		<p class="congrats-sorry__sub">Let's get you back<br/> to where you started.</p>

		<a class="pepsi-pill-button page-non-winner__try-again" href="<?php echo esc_url( $age_gate_url ); ?>">HOME</a>

	</div>

</main>

<?php get_footer(); ?>
