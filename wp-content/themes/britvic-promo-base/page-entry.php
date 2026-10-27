<?php
/**
 * Template Name: Entry
 * Page flow step: entry
 *
 * Name/email collection via Gravity Forms.
 * Requires age gate to have been completed.
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_dob_entered'])) {
    wp_redirect(home_url('/'));
    exit;
}

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('entry');
$gf_id = bpm_get_gf_id('entry');
?>

<main class="scene scene--entry"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php bpm_img('product_image', 'entry', 'scene__product', ''); ?>

    <?php if (bpm_img_url('headline_graphic', 'entry')): ?>
        <?php bpm_img('headline_graphic', 'entry', 'scene__headline-graphic', ''); ?>
    <?php else: ?>
        <h2 class="scene__headline">Enter for your chance to win</h2>
    <?php endif; ?>

    <div class="form-container">
        <?php if ($gf_id > 0): ?>
            <?php gravity_form($gf_id, false, false, false, null, false); ?>
        <?php else: ?>
            <p class="bpm-notice">Entry form not configured. Set the <strong>entry</strong> form ID in BPM &rarr; Brand Config &rarr; Form IDs.</p>
        <?php endif; ?>

        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
