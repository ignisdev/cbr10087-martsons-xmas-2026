<?php
/**
 * Template Name: Prize Draw Entry
 * Page flow step: draw-entry
 *
 * Full entry form for prize draw competitions.
 * Requires age gate session.
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_dob_entered'])) {
    wp_redirect(home_url('/'));
    exit;
}

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('draw-entry');
$gf_id = bpm_get_gf_id('draw_entry');
?>

<main class="scene scene--draw-entry"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php if (bpm_img_url('headline_graphic', 'draw-entry')): ?>
        <?php bpm_img('headline_graphic', 'draw-entry', 'scene__headline-graphic', ''); ?>
    <?php else: ?>
        <h2 class="scene__headline">Enter for your chance to win</h2>
    <?php endif; ?>

    <div class="form-container">
        <?php if ($gf_id > 0): ?>
            <?php gravity_form($gf_id, false, false, false, null, false); ?>
        <?php else: ?>
            <p class="bpm-notice">Draw entry form not configured. Set the <strong>draw_entry</strong> form ID in BPM &rarr; Brand Config &rarr; Form IDs.</p>
        <?php endif; ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
