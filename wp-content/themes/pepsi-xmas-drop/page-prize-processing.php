<?php
/**
 * Template Name: Prize Processing
 *
 * Opens the Stripe v2 onboarding URL in a new tab and polls the payout-status
 * endpoint until the webhook confirms the payout is done, then auto-redirects
 * to prize-paid — no user interaction required.
 *
 * Stripe v2 shows a terminal "you're ready to go" page with no auto-redirect
 * and no continue button. By opening Stripe in a new tab and keeping the
 * winner on this polling page we can detect webhook confirmation and redirect
 * them automatically once the payout posts (typically within 30 seconds).
 */
defined( 'ABSPATH' ) || exit;

$claim_token    = isset( $_GET['pf_claim'] ) ? sanitize_text_field( (string) $_GET['pf_claim'] ) : '';
$context        = $claim_token !== '' && function_exists( 'pf_get_stripe_claim_context' )
	? pf_get_stripe_claim_context( $claim_token )
	: null;
$stripe_url     = is_array( $context ) ? (string) ( $context['stripe_onboarding_url'] ?? '' ) : '';
$prize_paid_url = function_exists( 'pf_prize_page_url' ) ? pf_prize_page_url( 'prize-paid' ) : home_url( '/prize-paid/' );
$failed_url     = function_exists( 'pf_prize_page_url' )
	? pf_prize_page_url( 'payout-failed', $claim_token !== '' ? [ 'pf_claim' => $claim_token ] : [] )
	: home_url( '/payout-failed/' );
$status_url     = rest_url( 'pepsi-xmas/v1/payout-status' ) . '?pf_claim=' . rawurlencode( $claim_token );

// If no valid claim, bail straight to payout-failed — unless admin is previewing.
if ( $claim_token === '' || ! is_array( $context ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		$stripe_url = ''; // preview mode — no real Stripe URL
	} else {
		if ( function_exists( 'pf_safe_redirect_exit' ) ) {
			pf_safe_redirect_exit( $failed_url );
		} else {
			wp_safe_redirect( $failed_url );
			exit;
		}
	}
}

if ( function_exists( 'pf_page_context' ) ) {
	pf_page_context( 'prize-processing', [
		'multi_fallback' => [ 'winner', 'thanks', 'non-winner', 'how-to-play' ],
	] );
}

get_header();
?>

<main class="congrats-page page-prize-processing" data-js="prize-processing-page">
	<div class="congrats-content page-prize-processing__content">

		<h1 class="congrats-sorry__heading">ALMOST THERE!</h1>

		<div id="pf-step-enter" <?php echo $stripe_url === '' ? 'style="display:none"' : ''; ?>>
			<p class="congrats-sorry__sub">
				TAP BELOW TO ENTER YOUR BANK DETAILS.<br>
				ONCE YOU'RE DONE WE'LL CONFIRM YOUR PAYMENT AUTOMATICALLY.
			</p>
			<p>
				<button id="pf-open-stripe" class="pepsi-pill-button">
					ENTER BANK DETAILS
				</button>
			</p>
		</div>

		<div id="pf-step-waiting" style="display:none">
			<p class="congrats-sorry__sub" id="pf-processing-status">
				CONFIRMING YOUR PAYMENT&hellip;
			</p>
			<p id="pf-reopen-wrap" style="display:none">
				<a href="<?php echo esc_url( $stripe_url ); ?>" target="_blank" rel="noopener"
				   class="pepsi-pill-button" id="pf-reopen-btn">
					REOPEN BANK DETAILS
				</a>
			</p>
		</div>

	</div>
</main>

<script>
(function () {
	var stripeUrl   = <?php echo wp_json_encode( $stripe_url ); ?>;
	var statusUrl   = <?php echo wp_json_encode( $status_url ); ?>;
	var prizePaidUrl = <?php echo wp_json_encode( $prize_paid_url ); ?>;
	var failedUrl   = <?php echo wp_json_encode( $failed_url ); ?>;

	var stepEnter  = document.getElementById('pf-step-enter');
	var stepWait   = document.getElementById('pf-step-waiting');
	var statusEl   = document.getElementById('pf-processing-status');
	var reopenEl   = document.getElementById('pf-reopen-wrap');
	var openBtn    = document.getElementById('pf-open-stripe');

	var POLL_INTERVAL_MS = 4000;
	var REOPEN_AFTER_MS  = 20000;
	var MAX_POLLS        = 45;
	var pollCount        = 0;
	var pollTimer        = null;
	var done             = false;
	var stripeTab        = null;

	function setStatus(msg) {
		if (statusEl) statusEl.textContent = msg;
	}

	function finish(success) {
		if (done) return;
		done = true;
		if (pollTimer) clearTimeout(pollTimer);
		try { if (stripeTab && !stripeTab.closed) stripeTab.close(); } catch (e) {}
		if (success) {
			setStatus('PAYMENT CONFIRMED — REDIRECTING…');
			window.location.href = prizePaidUrl;
		} else {
			setStatus('SOMETHING WENT WRONG — REDIRECTING…');
			window.location.href = failedUrl;
		}
	}

	function poll() {
		if (done) return;
		pollCount++;
		if (pollCount > MAX_POLLS) {
			setStatus('THIS IS TAKING LONGER THAN EXPECTED. PLEASE CHECK YOUR EMAIL OR CONTACT HELP@BRITVIC.PROMO.');
			return;
		}
		fetch(statusUrl, { method: 'GET', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.status === 'complete') { finish(true); }
				else if (data.status === 'failed') { finish(false); }
				else { pollTimer = setTimeout(poll, POLL_INTERVAL_MS); }
			})
			.catch(function () { pollTimer = setTimeout(poll, POLL_INTERVAL_MS); });
	}

	function startPolling() {
		if (stepEnter) stepEnter.style.display = 'none';
		if (stepWait)  stepWait.style.display  = '';
		// Show reopen link after 20 s in case they closed the Stripe tab.
		if (reopenEl) {
			setTimeout(function () {
				if (!done) reopenEl.style.display = '';
			}, REOPEN_AFTER_MS);
		}
		pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
	}

	// Button click opens Stripe in a new tab (user gesture = never blocked).
	if (openBtn && stripeUrl) {
		openBtn.addEventListener('click', function () {
			stripeTab = window.open(stripeUrl, '_blank');
			startPolling();
		});
	}
})();
</script>

<?php get_footer(); ?>
