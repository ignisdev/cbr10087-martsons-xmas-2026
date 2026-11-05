<?php
/**
 * Template Name: Age Gate
 */
defined( 'ABSPATH' ) || exit;

// Background is drawn by .page-age-gate itself (scaled with the stage, see
// _age-gate.scss), not by .site-wrapper — hence set_wrapper_style => false.
$ctx = pf_page_context( 'age-gate', [
	'assets'            => [ 'hero_image', 'headline_graphic' ],
	'set_wrapper_style' => false,
] );
$age_gate_bg_style = sprintf( "--age-gate-bg: url('%s');", esc_url( $ctx['bg_deco_url'] ) );
// Contact step (GF page 2) background — BPM page key `age-gate-contact`
// (registered in flow-guard.php). Unset → CSS falls back to --age-gate-bg.
$age_gate_contact_bg_url = function_exists( 'pepsi_resolve_asset_url' )
	? pepsi_resolve_asset_url( 'bg_deco_image', 'age-gate-contact' )
	: '';
if ( $age_gate_contact_bg_url !== '' ) {
	$age_gate_bg_style .= sprintf( " --age-gate-bg-contact: url('%s');", esc_url( $age_gate_contact_bg_url ) );
}

// Hero image — page-asset alignment shorthand (start/end/center → margin css).
$hero_alignment = $ctx['assets']['hero_image']['alignment'] ?? 'center';
$hero_margin    = 'margin-left:auto;margin-right:auto;';
if ( $hero_alignment === 'start' ) {
	$hero_margin = 'margin-left:0;margin-right:auto;';
} elseif ( $hero_alignment === 'end' ) {
	$hero_margin = 'margin-left:auto;margin-right:0;';
}
$hero_image_size = $ctx['assets']['hero_image']['size'] ?? '';

// Contact-step Pepsi logo — theme file (not a media upload) so it ships with the repo.
$pepsi_logo = get_template_directory_uri() . '/src/images/pepsi_logo_button.png';

// Prize-pool step (the GF page holding the `pf-prize-pool` HTML block): title
// graphic above the copy, Stripe logo below. Theme files, not media URLs, so
// they work on every environment without re-linking. Shown by ui/age-gate.ts.
$prize_pool_title_url = get_template_directory_uri() . '/src/images/marstons/prize_quilt.png';
$prize_pool_stripe_url = get_template_directory_uri() . '/src/images/marstons/stripe.png';

get_header();

// Age-gate GF form id — uses the cross-competition resolver so the form
// renders even when get_current_competition_id() returns 0.
$age_gate_form_id = function_exists( 'pepsi_get_age_gate_form_id' ) ? (int) pepsi_get_age_gate_form_id() : 0;
if ( $age_gate_form_id <= 0 && function_exists( 'pf_resolve_gf_id' ) ) {
	$age_gate_form_id = pf_resolve_gf_id( 'age_gate' );
	if ( $age_gate_form_id <= 0 ) {
		$age_gate_form_id = pf_resolve_gf_id( 'initial_entry' );
	}
	if ( $age_gate_form_id <= 0 ) {
		$age_gate_form_id = pf_resolve_gf_id( 'entry' );
	}
}

$gf_available           = function_exists( 'gravity_form' );
$can_render_age_gate    = false;
$_age_gate_form_markup  = '';
if ( $gf_available && $age_gate_form_id > 0 && class_exists( 'GFAPI' ) ) {
	$resolved_form = GFAPI::get_form( $age_gate_form_id );
	$can_render_age_gate = ! empty( $resolved_form ) && empty( $resolved_form['is_trash'] );
	if ( $can_render_age_gate ) {
		$_age_gate_form_markup = trim( (string) gravity_form( $age_gate_form_id, false, false, false, null, false, 1, false ) );
	}
}

// Current step, worked out server-side so the first paint is already right —
// otherwise the step classes only land once ui/age-gate.ts runs and the
// docked NEXT / SUBMIT visibly jump into place. Same rule as the JS:
// page 1 = DOB; a page holding an HTML block (`pf-prize-pool`) = prize pool;
// any other page = name/email. GF marks every non-current page display:none.
$age_gate_step = 'dob';
if ( $_age_gate_form_markup !== '' && preg_match_all(
	"/<div id='gform_page_" . (int) $age_gate_form_id . "_(\d+)'[^>]*>/",
	$_age_gate_form_markup,
	$ag_pages,
	PREG_SET_ORDER
) ) {
	$ag_current_page = 1;
	foreach ( $ag_pages as $ag_page ) {
		if ( strpos( $ag_page[0], 'display:none' ) === false ) {
			$ag_current_page = (int) $ag_page[1];
			break;
		}
	}
	if ( $ag_current_page > 1 ) {
		$age_gate_step = 'contact';
		foreach ( (array) ( $resolved_form['fields'] ?? [] ) as $ag_field ) {
			if ( (int) $ag_field->pageNumber === $ag_current_page
				&& ( $ag_field->type === 'html' || strpos( (string) $ag_field->cssClass, 'pf-prize-pool' ) !== false ) ) {
				$age_gate_step = 'prize';
				break;
			}
		}
	}
}
$ag_is_dob     = $age_gate_step === 'dob';
$ag_is_prize   = $age_gate_step === 'prize';
$ag_is_contact = $age_gate_step === 'contact';
$ag_hidden     = static function ( bool $show ): string {
	return $show ? '' : ' u-hidden';
};
$ag_form_classes = $ag_is_dob ? 'is-step-dob' : 'is-step-contact' . ( $ag_is_prize ? ' is-step-prize' : '' );
?>
<main class="age-gate page-age-gate" data-js="age-gate-root" data-form-id="<?php echo (int) $age_gate_form_id; ?>" style="<?php echo esc_attr( $age_gate_bg_style ); ?>">
	<div class="scene scene--age-gate page-age-gate__scene">
		<h1 class="u-sr-only"><?php the_title(); ?></h1>

		<img
			class="scene__hero page-age-gate__hero"
			src="<?php echo esc_url( $ctx['hero_image_url'] ); ?>"
			alt=""
			<?php if ( $hero_image_size !== '' ) : ?>
			style="width: <?php echo esc_attr( $hero_image_size ); ?>; max-width:none; height:auto; display:block; <?php echo esc_attr( $hero_margin ); ?>"
			<?php endif; ?>
		/>

		<div class="age-gate-headline-wrap page-age-gate__headline-wrap<?php echo esc_attr( $ag_hidden( $ag_is_dob ) ); ?>" data-js="age-gate-headline">
			<?php if ( $ctx['headline_graphic_url'] !== '' ) : ?>
				<img class="scene__headline-graphic page-age-gate__headline-graphic" src="<?php echo esc_url( $ctx['headline_graphic_url'] ); ?>" alt="" style="<?php echo esc_attr( $ctx['headline_graphic_style'] ); ?>" />
			<?php endif; ?>
		</div>

		<div class="date-of-birth-form page-age-gate__form <?php echo esc_attr( $ag_form_classes ); ?>" data-js="age-gate-form-wrap">
			<p class="date-of-birth-form__title page-age-gate__title page-age-gate__title--dob<?php echo esc_attr( $ag_hidden( $ag_is_dob ) ); ?>" data-js="age-gate-title-dob">ENTER YOUR DATE OF BIRTH <br/>FOR YOUR CHANCE TO WIN WITH PEPSI</p>
			<p class="date-of-birth-form__title page-age-gate__title page-age-gate__title--contact<?php echo esc_attr( $ag_hidden( $ag_is_contact ) ); ?>" data-js="age-gate-title-contact">ENTER YOUR NAME AND EMAIL <br/>  FOR YOUR CHANCE TO WIN</p>
			<img class="page-age-gate__prize-title<?php echo esc_attr( $ag_hidden( $ag_is_prize ) ); ?>" data-js="age-gate-prize-title" src="<?php echo esc_url( $prize_pool_title_url ); ?>" alt="Prize pool" />
			<div class="date-of-birth-form__errors" role="alert" aria-live="polite"></div>
			<div class="date-of-birth-form__form page-age-gate__form-body">
				<?php if ( $can_render_age_gate && $_age_gate_form_markup !== '' ) : ?>
					<?php echo $_age_gate_form_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<p class="date-of-birth-form__errors">Age Gate form is not configured. Set it in BPM → Form Mapping.</p>
				<?php endif; ?>
			</div>
			<img class="page-age-gate__prize-stripe<?php echo esc_attr( $ag_hidden( $ag_is_prize ) ); ?>" data-js="age-gate-prize-stripe" src="<?php echo esc_url( $prize_pool_stripe_url ); ?>" alt="Payments by Stripe" />
		</div>
	</div>
</main>
<?php get_footer(); ?>
