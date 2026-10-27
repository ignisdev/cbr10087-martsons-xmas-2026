<?php
/**
 * Template Name: Winner
 * Page flow step: winner
 *
 * Win state — shows prize information and claim form.
 * Requires prize session data.
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_prize']) && empty($_SESSION['prizeticket'])) {
    wp_redirect(home_url('/'));
    exit;
}

$prize   = $_SESSION['britvic_prize']   ?? $_SESSION['prizeticket'] ?? [];
$prize_name  = $prize['prize']  ?? $prize['name']  ?? '';
$prize_value = $prize['value']  ?? '';

get_header();
$bg_deco = BPM_Brand_Config::get_bg_deco('winner');
$gf_id = bpm_get_gf_id('winner_claim');
?>

<main class="scene scene--winner"
      style="background-image: url('<?php echo esc_url($bg_deco['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php bpm_img('win_graphic', 'winner', 'scene__win-graphic', 'You won!'); ?>

    <h1 class="scene__headline">Congratulations!</h1>

    <?php if ($prize_name): ?>
        <p class="scene__prize-name"><?php echo esc_html($prize_name); ?></p>
    <?php endif; ?>

    <?php if ($prize_value): ?>
        <p class="scene__prize-value">Worth: £<?php echo esc_html($prize_value); ?></p>
    <?php endif; ?>

    <div class="form-container">
        <?php if ($gf_id > 0): ?>
            <?php gravity_form($gf_id, false, false, false, null, false); ?>
        <?php else: ?>
            <p class="bpm-notice">Winner claim form not configured. Set the <strong>winner_claim</strong> form ID in BPM &rarr; Brand Config &rarr; Form IDs.</p>
        <?php endif; ?>
    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<?php get_footer(); ?>
