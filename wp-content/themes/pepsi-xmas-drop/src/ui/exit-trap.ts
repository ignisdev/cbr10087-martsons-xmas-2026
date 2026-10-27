/**
 * Back-button trap for post-claim terminal pages.
 *
 *   /prize-paid/    — winner has been paid; previous history entry is the
 *                     single-use (now stale) Stripe onboarding page.
 *   /payout-failed/ — winner's CoP / verification failed; previous history
 *                     entry is /congratulations/ with the (already-consumed)
 *                     completion token still in the rendered form. A bfcache
 *                     restore there would let them re-POST the claim form.
 *   /thanks/        — post-claim terminal page (cash or merch). Previous
 *                     history entry is /congratulations/ with the consumed
 *                     claim form; Back must not return there.
 *
 * For any of these pages, pressing Back must NOT return to the previous entry —
 * send the player to /?pf_reset=1 which clears the session + flow server-side
 * and bounces them through the age-gate (the entry route).
 *
 * /payout-failed/ also has a legitimate forward path (the "TRY AGAIN" link
 * to /stripe-refresh/?pf_claim=…). That's a normal click navigation, not
 * Back, so it's unaffected by the popstate / pageshow:persisted trap.
 */
const RESET_URL = '/?pf_reset=1';

export function initExitTrap(): void {
	const page = document.querySelector(
		'[data-js="prize-paid-page"], [data-js="payout-failed-page"], [data-js="thanks-page"]'
	);
	if ( ! page ) return;

	// Seed a same-document trap entry so the first Back fires popstate.
	try { history.pushState( null, '', location.href ); } catch { /* ignore */ }

	const fire = (): void => {
		// replace() so neither the trap entry nor the stale previous entry
		// remain in history.
		location.replace( RESET_URL );
	};

	window.addEventListener( 'popstate', fire );

	// bfcache safety: some browsers restore the page from cache on Back
	// without firing popstate. `persisted` indicates a bfcache restore.
	window.addEventListener( 'pageshow', ( e: PageTransitionEvent ) => {
		if ( e.persisted ) fire();
	} );
}
