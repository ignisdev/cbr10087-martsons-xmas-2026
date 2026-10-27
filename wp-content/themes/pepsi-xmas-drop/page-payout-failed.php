<?php
/**
 * Template Name: Payout Failed
 */
defined( 'ABSPATH' ) || exit;

$reason        = isset( $_GET['pf_reason'] ) ? sanitize_text_field( (string) $_GET['pf_reason'] ) : '';
$claim_token   = isset( $_GET['pf_claim'] )  ? sanitize_text_field( (string) $_GET['pf_claim'] )  : '';
$support_email = 'help@britvic.promo';
$retry_url     = $claim_token !== ''
	? esc_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) )
	: '';

// payout-failed is an outcome-only template, hidden from the BPM Page Flow
// Builder admin — so bg_deco_image can't be set against the payout-failed key
// directly. Fall back through pages that ARE in the admin and commonly carry
// the same brand-deco background. Prefer non-winner first (closest in tone to
// a failed payout), then the same chain prize-paid uses. Alt-route resolution
// still applies — the `_alt` variant wins on the alt subdomain via the same chain.
pf_page_context( 'payout-failed', [
	'multi_fallback' => [ 'non-winner', 'thanks', 'winner', 'how-to-play' ],
] );
get_header();
?>

<main class="prize-status-page prize-status-page--failed page-payout-failed" data-js="payout-failed-page">
	<section class="prize-status-card page-payout-failed__card">

		<?php if ( $reason === 'cop_mismatch' ) : ?>
			<h1 class="page-payout-failed__title">We Couldn’t<br/>Verify Your<br/>Bank Account</h1>
			<p>Please check that the name on your bank account matches the name you entered, and that your sort code and account number are correct.</p>
			<p>You can try again now.</p>
			<?php if ( $retry_url !== '' ) : ?>
				<p class="page-payout-failed__retry">
					<a class="pepsi-pill-button" href="<?php echo esc_url( $retry_url ); ?>">TRY AGAIN</a>
				</p>
			<?php endif; ?>
		<?php elseif ( $reason === 'verify' ) : ?>
			<h1 class="page-payout-failed__title">We Couldn’t<br/> Verify Your<br/> Identity</h1>
			<p>Unfortunately we were unable to complete your identity verification with Stripe after several attempts.</p>
			<p>Please contact our support team and quote your claim email — we’ll make sure you receive your prize.</p>
		<?php else : ?>
			<h1 class="page-payout-failed__title">We Couldn’t<br/>  Complete Your<br/>Payout</h1>
			<p>Please contact support and quote your claim email.</p>
		<?php endif; ?>

		<p class="prize-status-card__support page-payout-failed__support">
			<a href="mailto:<?php echo esc_attr( $support_email ); ?>">
				<?php echo esc_html( $support_email ); ?>
			</a>
		</p>

	</section>
</main>

<?php get_footer(); ?>
