<?php
/**
 * Template Name: Photo Upload
 * Page flow step: photo
 *
 * Optional proof-of-purchase photo step.
 * Uses Gravity Forms for the actual file upload — the camera UI wraps the
 * hidden GF file field. JS in Photo.js handles the preview.
 *
 * Requires: entry session
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_dob_entered']) && empty($_SESSION['britvic_entry_data'])) {
    wp_redirect(home_url('/'));
    exit;
}

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('photo');
$gf_id = bpm_get_gf_id('photo');
?>

<main class="scene scene--photo"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <div class="upload-container">

        <h2 class="upload-container__title">Upload your photo to continue</h2>

        <!-- Camera trigger UI -->
        <div class="upload-container__camera" id="takePhoto" role="button" aria-label="Take or select a photo" tabindex="0">
            <?php if (bpm_img_url('camera_image', 'photo')): ?>
                <?php bpm_img('camera_image', 'photo', 'upload-container__camera-icon', 'Camera'); ?>
            <?php else: ?>
                <div class="upload-container__camera-icon upload-container__camera-icon--default">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="80" height="80" fill="currentColor" aria-hidden="true">
                        <path d="M12 15.5A3.5 3.5 0 018.5 12 3.5 3.5 0 0112 8.5a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5M16.5 6L15 4.5h-6L7.5 6H5A2 2 0 003 8v11a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-2.5z"/>
                    </svg>
                </div>
            <?php endif; ?>
            <!-- Preview shown here by JS -->
            <div class="interactive-overlay viewport" id="interactive-overlay"></div>
            <div class="interactive-overlay-frame"></div>
        </div>

        <!-- Hidden file input — triggered by #takePhoto click -->
        <input type="file" id="photoInput" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true">

        <!-- Upload button — enabled by JS after file selected -->
        <button id="uploadButton" class="btn" disabled>Upload Photo</button>

        <?php if ($gf_id > 0): ?>
            <div style="display:none" id="gf-photo-form">
                <?php gravity_form($gf_id, false, false, false, null, false); ?>
            </div>
        <?php else: ?>
            <p class="bpm-notice">Photo form not configured. Set the <strong>photo</strong> form ID in BPM &rarr; Brand Config &rarr; Form IDs.</p>
        <?php endif; ?>

        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>

    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
