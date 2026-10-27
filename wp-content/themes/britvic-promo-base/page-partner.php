<?php
/**
 * Template Name: Partner Landing
 * Page flow step: partner
 *
 * Optional branded partner landing page (e.g. Cineworld, Greene King).
 * Shows partner branding before the main competition flow begins.
 *
 * @package britvic-promo-base
 */
get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('partner');
?>

<main class="scene scene--partner"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>
    <?php bpm_img('partner_logo', null, 'scene__partner-logo scene__partner-logo--hero', 'Partner logo'); ?>

    <?php bpm_img('hero_image', 'partner', 'scene__hero', ''); ?>

    <?php if (bpm_img_url('headline_graphic', 'partner')): ?>
        <?php bpm_img('headline_graphic', 'partner', 'scene__headline-graphic', ''); ?>
    <?php else: ?>
        <h1 class="scene__headline"><?php the_title(); ?></h1>
    <?php endif; ?>

    <a class="btn" href="<?php echo esc_url(bpm_next_step_url()); ?>">
        Enter Now
    </a>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<?php get_footer(); ?>
