<?php
/**
 * Template Name: Photo Upload
 * Page flow step: photo
 *
 * Proof-of-purchase photo. Position in the journey comes from the BPM Page
 * Flow; submitting the BPM `photo` form marks the step and redirects to the
 * next flow step (see flow-guard.php). Overrides britvic-promo-base's
 * page-photo.php.
 */
defined( 'ABSPATH' ) || exit;

// Background is drawn by .page-photo (scaled with the stage, see
// _photo.scss), not by .site-wrapper — hence set_wrapper_style => false.
$ctx = pf_page_context( 'photo', [
	'assets'            => [ 'camera_image' ],
	'set_wrapper_style' => false,
] );

// Cross-competition resolver so the form renders even when
// get_current_competition_id() returns 0 on this static template.
$gf_id = function_exists( 'pf_resolve_gf_id' ) ? pf_resolve_gf_id( 'photo' ) : 0;

get_header();
?>

<main class="photo-page page-photo" data-js="photo-root" style="<?php echo esc_attr( sprintf( "--photo-bg: url('%s');", esc_url( $ctx['bg_deco_url'] ) ) ); ?>">
	<div class="page-photo__scene">

		<div class="upload-container page-photo__upload-container">

			<h2 class="upload-container__title page-photo__title">UPLOAD A PHOTO<br/>OF YOUR PEPSI MAX</h2>

			<div class="upload-container__camera page-photo__camera" data-js="photo-trigger" role="button" aria-label="Take or select a photo" tabindex="0">
				<?php if ( $ctx['camera_image_url'] !== '' ) : ?>
					<img class="upload-container__camera-icon page-photo__camera-icon" src="<?php echo esc_url( $ctx['camera_image_url'] ); ?>" alt="Camera" />
				<?php else : ?>
					<div class="upload-container__camera-icon page-photo__camera-icon page-photo__camera-icon--default">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 58.88 50.69" aria-hidden="true">
							<g fill="#fff">
								<path d="M0,12.99v34.54c0,1.74,1.42,3.16,3.15,3.16h52.58c1.73,0,3.15-1.42,3.15-3.16V12.99c0-1.74-1.42-3.15-3.15-3.15h-11.77c-1.96,0-3.71-1.23-4.38-3.06l-1.84-5.02c-.38-1.05-1.39-1.75-2.5-1.75h-11.6c-1.11,0-2.12.7-2.5,1.75l-1.84,5.02c-.67,1.83-2.42,3.06-4.38,3.06H3.15C1.42,9.84,0,11.24,0,12.99h0ZM29.44,13.24c8.79,0,15.93,7.16,15.93,15.94s-7.15,15.93-15.93,15.93-15.93-7.15-15.93-15.93,7.15-15.94,15.93-15.94Z"/>
								<path d="M29.44,42.55c7.38,0,13.37-6,13.37-13.37s-5.99-13.38-13.37-13.38c-7.38,0-13.37,6-13.37,13.38s5.99,13.37,13.37,13.37ZM29.44,18.75c5.75,0,10.42,4.68,10.42,10.43h-2.56c0-4.34-3.53-7.86-7.85-7.86v-2.56Z"/>
							</g>
						</svg>
					</div>
				<?php endif; ?>
				<div class="page-photo__preview" data-js="photo-preview"></div>
			</div>

			<input type="file" id="photoInput" data-js="photo-proxy-input" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true">

			<div class="pf-cta-dock">
				<button type="button" id="uploadButton" data-js="photo-upload-btn" class="pepsi-pill-button page-photo__upload-btn">Upload</button>
			</div>

			<?php if ( $gf_id > 0 ) : ?>
				<div id="gf-photo-form" data-js="photo-gf-wrap">
					<?php gravity_form( $gf_id, false, false, false, null, false ); ?>
				</div>
			<?php else : ?>
				<p class="page-photo__notice">Photo form not configured. Set the <strong>photo</strong> form ID in BPM &rarr; Brand Config &rarr; Form IDs.</p>
			<?php endif; ?>

		</div>

	</div>
</main>

<?php get_footer(); ?>
