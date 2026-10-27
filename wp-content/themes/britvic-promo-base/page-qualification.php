<?php
/**
 * Template Name: Qualification
 * Page flow step: qualification
 *
 * Two-scene page: Scene 1 = DOB check, Scene 2 = code/barcode entry.
 * Used by promotions that require a code on-pack in addition to age gate.
 * JS transitions from scene 1 to scene 2 after DOB passes.
 *
 * @package britvic-promo-base
 */
session_destroy();
session_start();

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('qualification');
$gf_id = bpm_get_gf_id('entry');
?>

<!-- Scene 1: Date of birth -->
<main class="scene scene--qualification-dob"
      id="scene-qual-dob"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php if (bpm_img_url('headline_graphic', 'qualification')): ?>
        <?php bpm_img('headline_graphic', 'qualification', 'scene__headline-graphic', ''); ?>
    <?php else: ?>
        <h1 class="scene__headline">Enter for your chance to win</h1>
    <?php endif; ?>

    <div class="date-of-birth-form">
        <p class="date-of-birth-form__title">Enter your date of birth to continue:</p>
        <div class="date-of-birth-form__errors" role="alert" aria-live="polite"></div>
        <div class="date-of-birth-form__form">
            <input id="dob" type="date" value="<?php echo esc_attr(date('Y-m-d')); ?>" autocomplete="off" aria-label="Date of birth" />
            <button id="dobCheck" type="button" class="btn">SUBMIT</button>
        </div>
    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>
</main>

<!-- Scene 2: Code / barcode entry (shown after DOB passes) -->
<main class="scene scene--qualification-code"
      id="scene-qual-code"
      style="display:none; background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;"
      aria-hidden="true">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <h2 class="scene__headline">Enter your code to play</h2>

    <div class="barcode-form form-container">
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
