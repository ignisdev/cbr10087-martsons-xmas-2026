<?php
/**
 * Template Name: Age Gate
 * Page flow step: age-gate
 *
 * DOB verification entry point. Clears all session data on load.
 * On success, AJAX call sets britvic_dob_entered and JS redirects to next step.
 *
 * @package britvic-promo-base
 */

// Reset all session state — this is always the start of a new journey
unset(
    $_SESSION['britvic_dob_entered'],
    $_SESSION['britvic_entry_data'],
    $_SESSION['britvic_prize'],
    $_SESSION['prize_result'],
    $_SESSION['prizeticket']
);

get_header();

$bg_deco = BPM_Brand_Config::get_bg_deco('age-gate');
$hero_image_size = bpm_img_size('hero_image', 'age-gate');
$hero_alignment = bpm_img_alignment('hero_image', 'age-gate');
$hero_margin = 'margin-left:auto;margin-right:auto;';
if ($hero_alignment === 'start') {
    $hero_margin = 'margin-left:0;margin-right:auto;';
} elseif ($hero_alignment === 'end') {
    $hero_margin = 'margin-left:auto;margin-right:0;';
}
?>

<main class="scene scene--age-gate"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php if (bpm_img_url('hero_image', 'age-gate')): ?>
    <img
        class="scene__hero"
        src="<?php echo esc_url(bpm_img_url('hero_image', 'age-gate')); ?>"
        alt=""
        <?php if ($hero_image_size !== ''): ?>
        style="width: <?php echo esc_attr($hero_image_size); ?>; max-width: none; height: auto; display: block; <?php echo esc_attr($hero_margin); ?>"
        <?php endif; ?>
    />
    <?php endif; ?>

    <?php if (bpm_img_url('headline_graphic', 'age-gate')): ?>
        <?php bpm_img('headline_graphic', 'age-gate', 'scene__headline-graphic', ''); ?>
    <?php else: ?>
        <h1 class="scene__headline"><?php the_title(); ?></h1>
    <?php endif; ?>

    <div class="date-of-birth-form">
        <p class="date-of-birth-form__title">Enter your date of birth to continue:</p>
        <div class="date-of-birth-form__errors" role="alert" aria-live="polite"></div>
        <div class="date-of-birth-form__form">
            <input id="dob"
                   type="date"
                   value="<?php echo esc_attr(date('Y-m-d')); ?>"
                   autocomplete="off"
                   aria-label="Date of birth" />
            <button id="dobCheck" type="button" class="btn">SUBMIT</button>
        </div>
    </div>

    <div class="scene__footer">
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<?php get_footer(); ?>
