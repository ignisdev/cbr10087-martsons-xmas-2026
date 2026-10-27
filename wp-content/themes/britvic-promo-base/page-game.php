<?php
/**
 * Template Name: Spin Wheel Game
 * Page flow step: game
 *
 * Interactive spin wheel. Prize data from session is passed to JS.
 * JS module: Game.js handles the GSAP spin animation and win/lose reveal.
 * Requires: entry session
 *
 * @package britvic-promo-base
 */

if (empty($_SESSION['britvic_dob_entered']) && empty($_SESSION['britvic_entry_data'])) {
    wp_redirect(home_url('/'));
    exit;
}

// Prize data from BPM session — defaults to loser if unset
$prize_data = $_SESSION['britvic_prize'] ?? ['prize' => 'loser'];
$bg_deco_game = BPM_Brand_Config::get_bg_deco('game');
$bg_deco_winner = BPM_Brand_Config::get_bg_deco('winner');
$bg_deco_non_winner = BPM_Brand_Config::get_bg_deco('non-winner');

get_header();
?>

<!-- Pass prize result to JS -->
<script>
window.prizeData = <?php echo wp_json_encode($prize_data); ?>;
</script>

<!-- Scene: Spin wheel -->
<main class="scene scene--game"
      id="scene-game"
      style="background-image: url('<?php echo esc_url($bg_deco_game['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco_game['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco_game['size'] ?? '100%'); ?>;">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <div class="game-wheel">
        <div class="game-wheel__container">
            <div class="game-wheel__spinner__wrapper" id="wheelSpinner">
                <?php if (bpm_img_url('wheel_image', 'game')): ?>
                    <?php bpm_img('wheel_image', 'game', 'game-wheel__image', 'Prize wheel'); ?>
                <?php else: ?>
                    <div class="game-wheel__placeholder" aria-label="Prize wheel">
                        <span>Spin</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="game-wheel__pointer" aria-hidden="true"></div>
        </div>

        <button class="btn spin-button" id="spinButton" type="button">
            SPIN TO WIN
        </button>
    </div>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
        <button id="termsOpen" class="terms-button" type="button">Terms &amp; Conditions</button>
    </div>

</main>

<!-- Scene: Winner overlay (hidden, revealed by JS after spin) -->
<main class="scene scene--game-winner"
      id="scene-game-winner"
      style="display:none; background-image: url('<?php echo esc_url($bg_deco_winner['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco_winner['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco_winner['size'] ?? '100%'); ?>;"
      aria-hidden="true">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <?php bpm_img('win_graphic', 'winner', 'scene__win-graphic', 'Congratulations!'); ?>

    <h2 class="scene__headline">Congratulations!</h2>
    <p class="scene__subheadline">You're a winner!</p>

    <a class="btn" id="claim-button" href="<?php echo esc_url(bpm_next_step_url()); ?>">
        Claim Your Prize
    </a>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<!-- Scene: Loser overlay (hidden, revealed by JS after spin) -->
<main class="scene scene--game-loser"
      id="scene-game-loser"
      style="display:none; background-image: url('<?php echo esc_url($bg_deco_non_winner['url'] ?? ''); ?>'); background-position: <?php echo esc_attr($bg_deco_non_winner['position'] ?? '50% 100%'); ?>; background-size: <?php echo esc_attr($bg_deco_non_winner['size'] ?? '100%'); ?>;"
      aria-hidden="true">

    <?php bpm_img('brand_logo', null, 'scene__brand-logo', 'Brand logo'); ?>

    <h2 class="scene__headline">Sorry, not this time.</h2>
    <p class="scene__subheadline">Better luck next time!</p>

    <a class="btn btn--secondary" href="<?php echo esc_url(bpm_next_step_url()); ?>">
        Continue
    </a>

    <div class="scene__footer">
        <?php bpm_img('partner_logo', null, 'scene__partner-logo', 'Partner logo'); ?>
    </div>

</main>

<?php get_footer(); ?>
