<?php defined( 'ABSPATH' ) || exit; ?>

<?php
$brand_logo_url = function_exists( 'pepsi_resolve_asset_url' )
	? pepsi_resolve_asset_url( 'brand_logo', null )
	: ( function_exists( 'bpm_img_url' ) ? bpm_img_url( 'brand_logo', null ) : '' );
$brand_logo_style = function_exists( 'pepsi_asset_style_attr' )
	? pepsi_asset_style_attr( 'brand_logo' )
	: '';
$is_landing_template = is_page_template( 'page-age-gate.php' );
$is_game_template = is_page_template( 'page-game.php' );
?>

	<?php if ( ! $is_landing_template && ! $is_game_template ) : ?>
		<div class="scene__footer">
			<?php if ( $brand_logo_url !== '' ) : ?>
				<img class="scene__partner-logo" src="<?php echo esc_url( $brand_logo_url ); ?>" alt="Brand logo" style="<?php echo esc_attr( $brand_logo_style ); ?>" />
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div><!-- /.site-wrapper -->
<?php wp_footer(); ?>
</body>
</html>
