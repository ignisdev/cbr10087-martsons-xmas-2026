<?php
/**
 * Template Name: Daily Play Limit
 *
 * Shown when a player tries to start a 4th game in a single day. The /session/start
 * REST endpoint returns 429 + { error: 'daily_limit' } once pf_get_daily_play_count()
 * is at PF_DAILY_PLAY_LIMIT for the player's email, and main.ts redirects here.
 *
 * Reuses the non-winner bg deco so the daily-limit page visually matches the
 * "sorry" surface — that's why the page key passed below is 'non-winner'.
 */
defined( 'ABSPATH' ) || exit;

pf_page_context( 'non-winner' );
get_header();
?>

<main class="congrats-page page-non-winner page-daily-limit" data-js="daily-limit-page">

	<div class="congrats-content page-non-winner__content">

		<h1 class="daily-limit__heading">THAT'S IT<br/> FOR TODAY!</h1>

		<p class="congrats-sorry__sub">You've used all <?php echo (int) PF_DAILY_PLAY_LIMIT; ?> of<br/> your shots for today.</p>

		<p class="congrats-sorry__sub">Come back tomorrow<br/> for another chance to win.</p>

	</div>

</main>

<?php get_footer(); ?>
