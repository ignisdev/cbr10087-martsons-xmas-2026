<?php
/**
 * Template Name: Non-Winner
 * Page flow step: non-winner
 *
 * Lose state. Clears prize session data.
 *
 * @package britvic-promo-base
 */

unset($_SESSION['britvic_prize'], $_SESSION['prize_result']);

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('non-winner');
?>

<main class="scene scene--non-winner"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <h2 class="scene__headline">Go on, one more drop.</h2>

    <p class="scene__copy">
        Its xmas after all.
    </p>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<?php get_footer(); ?>
