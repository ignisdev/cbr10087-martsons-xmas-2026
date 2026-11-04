<?php defined( 'ABSPATH' ) || exit; ?>

<?php
// Shared site footer — T&C link (opens the #termsModal in header.php) and the
// drink-responsibly line (BPM Brand Config → Partner Logo, theme fallback
// src/images/marstons/drink_responsbility.png). Skipped on /game/, which
// renders its own footer inside the stage.
if ( function_exists( 'pf_site_chrome_enabled' ) && pf_site_chrome_enabled() ) :
	$pf_footer_line_url = pf_site_chrome_asset_url( 'partner_logo' );
	?>
	<footer class="site-footer">
		<a href="#" class="site-footer__terms" data-terms-open>Terms &amp; Conditions</a>
		<?php if ( $pf_footer_line_url !== '' ) : ?>
			<img class="site-footer__drinkaware" src="<?php echo esc_url( $pf_footer_line_url ); ?>" alt="Enjoy responsibly. bedrinkaware.co.uk" />
		<?php endif; ?>
	</footer>
<?php endif; ?>
</div><!-- /.site-wrapper -->
<?php wp_footer(); ?>
</body>
</html>
