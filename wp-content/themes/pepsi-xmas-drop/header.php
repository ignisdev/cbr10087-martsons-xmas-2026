<?php defined( 'ABSPATH' ) || exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-W2HTDF4H');</script>
<!-- End Google Tag Manager -->

	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#001689">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-W2HTDF4H"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<div id="pf-page-overlay" aria-hidden="true"></div>
<?php
$pepsi_site_wrapper_style = '';
if ( isset( $GLOBALS['pepsi_site_wrapper_style'] ) && is_string( $GLOBALS['pepsi_site_wrapper_style'] ) ) {
	$pepsi_site_wrapper_style = trim( $GLOBALS['pepsi_site_wrapper_style'] );
}

$terms_page = get_page_by_path( 'terms' );
if ( ! $terms_page ) {
	$terms_page = get_page_by_path( 'terms-and-conditions' );
}
?>
<div class="terms-wrapper" id="termsModal" role="dialog" aria-modal="true" aria-label="Terms and Conditions" aria-hidden="true">
	<div class="terms-wrapper__inner">
		<button id="termsClose" class="terms-wrapper__close" type="button" aria-label="Close terms">&times;</button>
		<div class="terms-wrapper__content">
			<?php
			if ( $terms_page instanceof WP_Post ) {
				echo apply_filters( 'the_content', (string) $terms_page->post_content );
			} elseif ( function_exists( 'bpm_get_competition_terms' ) ) {
				$terms_content = (string) bpm_get_competition_terms();
				if ( trim( $terms_content ) !== '' ) {
					echo $terms_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<h1>Terms &amp; Conditions</h1><p>No terms and conditions have been set for this competition.</p>';
				}
			} else {
				echo '<h1>Terms &amp; Conditions</h1><p>No terms and conditions have been set for this competition.</p>';
			}
			?>
		</div>
	</div>
</div>
<div class="site-wrapper"<?php echo $pepsi_site_wrapper_style !== '' ? ' style="' . esc_attr( $pepsi_site_wrapper_style ) . '"' : ''; ?>>
<?php
// Shared site header — the partner-brands banner (BPM Brand Config → Brand Logo,
// theme fallback src/images/marstons/brands_banner.png). Skipped on /game/.
if ( function_exists( 'pf_site_chrome_enabled' ) && pf_site_chrome_enabled() ) :
	$pf_header_banner_url = pf_site_chrome_asset_url( 'brand_logo' );
	?>
	<header class="site-header">
		<?php if ( $pf_header_banner_url !== '' ) : ?>
			<img class="site-header__banner" src="<?php echo esc_url( $pf_header_banner_url ); ?>" alt="Pepsi and partner brands" />
		<?php endif; ?>
	</header>
<?php endif; ?>
