<?php
/**
 * Template Name: Prize Paid
 */
defined( 'ABSPATH' ) || exit;

// prize-paid is hidden from the BPM Page Flow Builder admin (see
// includes/lib/flow-guard.php), so bg_deco_image can't be set against the
// prize-paid key directly. Fall back through pages that ARE in the admin and
// commonly carry the same brand-deco background. Alt-route resolution still
// applies — the `_alt` variant wins on the alt subdomain via the same chain.
pf_page_context( 'prize-paid', [
	'multi_fallback' => [ 'winner', 'thanks', 'non-winner', 'how-to-play' ],
] );

// Terminal page — once the player has seen this, the journey is over. Wipe
// the flow + player-identity session on shutdown (after the current response
// is committed) so a subsequent attempt to navigate back through the funnel
// finds nothing and is forced through the age-gate entry route. Combined
// with the claim-token destruction in pf_process_stripe_return_page, this
// closes the Back→Stripe→re-claim exploit at every layer.
if ( function_exists( 'pf_terminal_session_wipe' ) ) {
	add_action( 'shutdown', 'pf_terminal_session_wipe' );
}

get_header();
?>

<main class="congrats-page page-prize-paid" data-js="prize-paid-page">
	<div class="congrats-content page-prize-paid__content">
		<h1 class="congrats-sorry__heading" style="font-size:3rem;">CONGRATULATIONS!</h1>
		<p class="congrats-sorry__sub">YOUR PRIZE SHOULD BE WITH YOU WITHIN THE NEXT 24-48 HOURS.</p>
		<p class="congrats-sorry__sub">IF YOU HAVEN’T RECEIVED IT WITHIN 48 HOURS, PLEASE CONTACT US ON:</p>
		<p class="congrats-sorry__sub"><a href="mailto:help@britvic.promo">help@britvic.promo</a></p>
		<p class="congrats-sorry__sub">PEPSI</p>
	</div>
</main>

<?php get_footer(); ?>
