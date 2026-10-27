<?php
/**
 * Template Name: Thank You
 * Page flow step: thanks
 *
 * Final confirmation. Clears all session data.
 *
 * @package britvic-promo-base
 */

// Clean session after successful journey
unset(
    $_SESSION['britvic_dob_entered'],
    $_SESSION['britvic_entry_data'],
    $_SESSION['britvic_prize'],
    $_SESSION['prize_result'],
    $_SESSION['prizeticket'],
    $_SESSION['britvic_file_url']
);

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('thanks');
?>

<main class="scene scene--thanks"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php if (bpm_img_url('thanks_graphic', 'thanks')): ?>
        <?php bpm_img('thanks_graphic', 'thanks', 'scene__thanks-graphic', ''); ?>
    <?php else: ?>
        <h1 class="scene__headline">Thank you!</h1>
    <?php endif; ?>

    <p class="scene__copy">
        We've sent your prize details to your email.<br>
        Please check your junk folder if you don't see it within a few minutes.
    </p>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
