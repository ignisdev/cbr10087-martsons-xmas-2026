<?php
/**
 * Template Name: Competition Closed
 * Page flow step: closed
 *
 * Shown when competition has ended.
 *
 * @package britvic-promo-base
 */
get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('closed');
?>

<main class="scene scene--closed"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <h1 class="scene__headline">This promotion has now closed.</h1>
    <p class="scene__copy">Thank you for your interest.</p>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
