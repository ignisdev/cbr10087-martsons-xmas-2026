<?php
/**
 * Template Name: Thanks (post-claim)
 *
 * Unified thank-you page rendered after a successful prize claim.
 * Branches copy on prize_type — cash gets the "instant" message,
 * merch (or anything non-cash) gets the "28 days" message.
 *
 * Receives an unguessable ?pf_thanks= token from the claim redirect (not the
 * raw, enumerable GF entry id) which resolves server-side to the entry id used
 * to look up the exact prize won and personalise the copy.
 */
defined( 'ABSPATH' ) || exit;

$gf_entry_id = ( function_exists( 'pf_resolve_thanks_token' ) && isset( $_GET['pf_thanks'] ) )
	? pf_resolve_thanks_token( (string) $_GET['pf_thanks'] )
	: 0;

// Debug preview: ?pf_preview_prize_type=cash|merch bypasses the DB lookup.
// Only active when WP_DEBUG is on and not in production.
$_pf_preview = (
	defined( 'WP_DEBUG' ) && WP_DEBUG
	&& wp_get_environment_type() !== 'production'
	&& isset( $_GET['pf_preview_prize_type'] )
) ? sanitize_key( $_GET['pf_preview_prize_type'] ) : '';

if ( $_pf_preview !== '' ) {
	$prize_info = [
		'prize_type' => $_pf_preview,
		'prize_name' => $_pf_preview === 'cash' ? '£10' : 'Pepsi Xmas Prize',
	];
} else {
	$prize_info = function_exists( 'pf_get_claimed_prize_info_by_gf_entry' )
		? pf_get_claimed_prize_info_by_gf_entry( $gf_entry_id )
		: [ 'prize_type' => '', 'prize_name' => '' ];
}

$prize_type  = strtolower( (string) $prize_info['prize_type'] );
$prize_name  = (string) $prize_info['prize_name'];
$is_cash     = function_exists( 'pf_is_cash_prize_type' ) && pf_is_cash_prize_type( $prize_type );

pf_page_context( 'thanks', [
	'multi_fallback' => [ 'thanks', 'winner', 'non-winner' ],
] );

get_header();

$prize_phrase = function_exists( 'pf_format_prize_with_article' )
	? pf_format_prize_with_article( $prize_name )
	: $prize_name;
?>

<main class="congrats-page page-thanks page-thanks--<?php echo esc_attr( $is_cash ? 'cash' : 'merch' ); ?>" data-js="thanks-page">

	<div class="congrats-content page-thanks__content">

		<h1 class="congrats-sorry__heading">THANKS!</h1>

		<?php if ( $prize_phrase !== '' ) : ?>
			<p class="congrats-sorry__sub">Congratulations on winning <br/><?php echo esc_html( $prize_phrase ); ?>.</p>
		<?php else : ?>
			<p class="congrats-sorry__sub">Once again congratulations.</p>
		<?php endif; ?>

		<?php if ( $is_cash ) : ?>
		<p class="congrats-sorry__sub">YOUR PRIZE SHOULD BE WITH YOU WITHIN THE NEXT 24-48 HOURS.</p>
		<p class="congrats-sorry__sub">IF YOU HAVEN’T RECEIVED IT WITHIN 48 HOURS, PLEASE CONTACT US ON:</p>
		<p class="congrats-sorry__sub"><a href="mailto:help@britvic.promo">help@britvic.promo</a></p>

		<?php else : ?>
			<p class="congrats-sorry__sub">Your prize will be with you<br/> within 28 days.</p>
		<?php endif; ?>

		<p class="congrats-sorry__sub">PEPSI MAX.</p>

	</div>

</main>

<?php get_footer(); ?>
