<?php
/**
 * Template Name: Non-Winner
 *
 * Shown when a piece fell off the tree, or the tree was completed outside a
 * winning time slot.  No token is required.
 */
defined( 'ABSPATH' ) || exit;

pf_page_context( 'non-winner' );

// Terminal page — the player has consumed this run. Wipe the flow +
// player-identity session on shutdown (after this response is committed) so
// any subsequent navigation (Back → /game/, refresh /non-winner/, etc.) is
// bounced through the age-gate entry route. The `played` flag from
// pf_api_session_start already blocks /game/ refresh; this wipe nukes the
// outcome flag too so even /non-winner/ itself can't be refreshed.
if ( function_exists( 'pf_terminal_session_wipe' ) ) {
	add_action( 'shutdown', 'pf_terminal_session_wipe' );
}

get_header();
?>

<main class="congrats-page page-non-winner" data-js="non-winner-page">

	<div class="congrats-content congrats-content-sorry page-non-winner__content">

		<h1 class="congrats-sorry__heading congrats-sorry__heading--graphic">
			<img src="<?php echo esc_url( get_template_directory_uri() . '/src/images/marstons/sorry.png' ); ?>" alt="Sorry!" />
		</h1>

		<p class="congrats-sorry__sub">you didn't win this time. Buy<br/>another drink to try again. <br/>You can try up to 3 times each day.</p>
		<div class="pf-cta-dock">
			<a class="pepsi-pill-button page-non-winner__try-again" href="<?php echo esc_url( home_url( '/' ) ); ?>">Buy another Drink</a>
		</div>

	</div>

</main>

<?php get_footer(); ?>
