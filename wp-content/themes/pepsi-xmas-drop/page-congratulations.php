<?php
/**
 * Template Name: Congratulations
 *
 * Shown after a winning run (star landed + within a prize time slot).
 * Requires a valid ?token= issued by session/complete.
 *
 * If the token is missing, expired, already used, or belongs to a non-winner
 * the page falls through to the sorry/better-luck state.
 *
 * GF setup required:
 *   — Field 10: enable "Allow field to be populated dynamically", parameter name: score
 *   — Field 3:  email address (used when claiming the BPM prize entry)
 */
defined( 'ABSPATH' ) || exit;

$token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
$data  = ( $token !== '' && function_exists( 'pf_peek_completion_token' ) )
	? pf_peek_completion_token( $token )
	: null;
$score         = (int) ( $data['score'] ?? 0 );
$score_display = (string) $score;
$prize_info    = function_exists( 'pf_get_congrats_prize_info' )
	? pf_get_congrats_prize_info()
	: [ 'is_winner' => false, 'prize_type' => '', 'prize_name' => '', 'score' => 0 ];
$prize_name = (string) $prize_info['prize_name'];   // human-readable (e.g. "T-shirt", "£5")
$prize_type = (string) $prize_info['prize_type'];   // 'cash_250' / 'cash_10' / 'cash' or 'merch'
$force_debug = function_exists( 'pf_get_forced_cash_debug_state' ) ? pf_get_forced_cash_debug_state() : null;

pf_page_context( 'winner', [
	'multi_fallback' => [ 'winner', 'congratulations' ],
] );

get_header();

$prize_phrase = function_exists( 'pf_format_prize_with_article' )
	? pf_format_prize_with_article( $prize_name )
	: ( $prize_name !== '' ? 'a ' . $prize_name : '' );
if ( $prize_phrase === '' ) {
	$prize_phrase = 'a prize';
}
?>

<main class="congrats-page page-congrats" data-js="congrats-page">

	<div class="congrats-content page-congrats__content">
		<p class="congrats-heading">CONGRATULATIONS</p>
		<p class="congrats-sub">You’ve won <?php echo esc_html( $prize_phrase ); ?> in the Pepsi<br/> Xmas Drop.</p>

		<div class="congrats-form page-congrats__form">
			<p class="congrats-form__intro">Please complete your details below so we can send you your prize.</p>
			<?php
			// Resolved from the BPM GF mapping (`winner_claim`) — the same id the
			// submission / Stripe hooks listen on, so render and handler can't drift.
			$claim_form_id = function_exists( 'pf_claim_form_id' ) ? (int) pf_claim_form_id() : 0;
			if ( $claim_form_id > 0 ) {
				echo do_shortcode( sprintf( '[gravityforms id="%d"]', $claim_form_id ) );
			} else {
				error_log( 'PF FLOW congratulations: no winner_claim form mapped in BPM; claim form not rendered.' );
			}

			// Forward the forced-cash id into the form POST. GF can drop the
			// query string from the form action depending on AJAX settings; this
			// hidden field makes sure pf_pre_submission still sees the id and
			// arms the claim transient. Inserted via JS so it lands inside the
			// rendered <form> element regardless of GF wrapper markup.
			$forced_force_id = isset( $_REQUEST['pf_force_cash_entry'] ) ? (int) $_REQUEST['pf_force_cash_entry'] : 0;
			if ( $forced_force_id > 0 ) :
				?>
				<script>
				(function () {
					var id = <?php echo (int) $forced_force_id; ?>;
					var attach = function () {
						document.querySelectorAll('form[id^="gform_"]').forEach(function (form) {
							if (form.querySelector('input[name="pf_force_cash_entry"]')) return;
							var input = document.createElement('input');
							input.type  = 'hidden';
							input.name  = 'pf_force_cash_entry';
							input.value = String(id);
							form.appendChild(input);
						});
					};
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', attach);
					} else {
						attach();
					}
					if (window.jQuery) {
						jQuery(document).on('gform_post_render', attach);
					}
				})();
				</script>
				<?php
			endif;
			?>
			<a href="#" id="termsOpen" class="terms-link">Terms &amp; Conditions</a>
		</div>

	</div>

</main>

<?php get_footer(); ?>
