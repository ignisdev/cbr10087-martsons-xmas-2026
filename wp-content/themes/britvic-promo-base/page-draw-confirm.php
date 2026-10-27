<?php
/**
 * Template Name: Draw Entry Confirmation
 * Page flow step: draw-confirm
 *
 * Shown after successful prize draw entry.
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_entry_data'])) {
    wp_redirect(home_url('/'));
    exit;
}

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('draw-confirm');
?>

<main class="scene scene--draw-confirm"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <h1 class="scene__headline">You're entered!</h1>

    <p class="scene__copy">
        Good luck — we'll announce the winner soon.<br>
        Keep an eye on your email.
    </p>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<?php get_footer(); ?>
