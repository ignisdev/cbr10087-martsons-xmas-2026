<?php
/**
 * Template Name: Stripe Verify
 *
 * Shown when Stripe has items in requirements.currently_due after onboarding
 * (typically an identity document). Renders an interstitial with a CTA that
 * sends the winner back into Stripe's hosted onboarding to complete verification.
 */
defined( 'ABSPATH' ) || exit;

if ( function_exists( 'pf_process_stripe_verify_page' ) ) {
	pf_process_stripe_verify_page();
}

$claim_token   = isset( $_GET['pf_claim'] ) ? sanitize_text_field( (string) $_GET['pf_claim'] ) : '';
$context       = function_exists( 'pf_get_stripe_claim_context' ) ? pf_get_stripe_claim_context( $claim_token ) : null;
$verify_link   = ( is_array( $context ) && ! empty( $context['verify_link'] ) ) ? esc_url( $context['verify_link'] ) : '';
$support_email = 'help@britvic.promo';

pf_page_context( 'stripe-verify' );
get_header();
?>

<main class="prize-status-page prize-status-page--verify page-stripe-verify">
	<section class="prize-status-card page-stripe-verify__card">

		<h1 class="page-stripe-verify__title">One more step <br/>to claim your prize</h1>

		<p>We need to verify your identity before we can send your Pepsi Xmas Drop cash prize. It only takes a moment, tap the button below to complete the final step.</p>

		<?php if ( $verify_link !== '' ) : ?>
			<a href="<?php echo $verify_link; ?>" class="pepsi-pill-button page-stripe-verify__cta">
				Complete verification
			</a>
		<?php else : ?>
			<p>We were unable to generate your verification link. Please contact support below.</p>
		<?php endif; ?>

		<?php
		$cancel_url = function_exists( 'pepsi_get_page_url_by_template' )
			? pepsi_get_page_url_by_template( 'page-age-gate.php' )
			: '';
		if ( $cancel_url === '' ) {
			$cancel_url = home_url( '/' );
		}
		$cancel_url = add_query_arg( 'pf_cancel', '1', $cancel_url );
		?>
		<a href="<?php echo esc_url( $cancel_url ); ?>" class="pepsi-pill-button page-stripe-verify__cancel">
			Cancel
		</a>

		<p class="prize-status-card__support page-stripe-verify__support">
			Having trouble?
			<a href="mailto:<?php echo esc_attr( $support_email ); ?>">
				<?php echo esc_html( $support_email ); ?>
			</a>
		</p>

	</section>
</main>

<?php get_footer(); ?>
