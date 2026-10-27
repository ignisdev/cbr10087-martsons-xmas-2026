<?php
/**
 * Template Name: Xmas Drop Game
 *
 * Full-bleed canvas page. No header chrome — the game is the entire viewport.
 * The Three.js tree + drop loop mounts on #game-canvas (see src/main.ts).
 */
defined( 'ABSPATH' ) || exit;

// Game page doesn't use the site wrapper bg deco (full-bleed canvas) — pass
// `set_wrapper_style: false` so we don't overwrite whatever header.php sets.
$ctx = pf_page_context( 'game', [
	'set_wrapper_style' => false,
] );
$brand_logo_url       = $ctx['brand_logo_url'];
$partner_logo_url     = $ctx['partner_logo_url'];
$partner_logo_style   = $ctx['partner_logo_style'];
$images_uri           = get_template_directory_uri() . '/src/images';
// Drop button artwork — theme file (ships with the repo). Press bounce is in
// src/ui/drop-button.ts.
$drop_button_image_url = $images_uri . '/pepsi_logo_button.png';
$tap_hint_image_url   = $images_uri . '/hand.png';

// The on-page debug bar only shows when Settings → PF Debug is ticked — being
// a logged-in admin is not enough here, so admins see the real game UI by
// default. (pf_is_debug_panel_visible() still governs other admin surfaces.)
$pf_debug_active = defined( 'PF_DEBUG_OPTION' ) && (int) get_option( PF_DEBUG_OPTION, 0 ) === 1;

if ( $pf_debug_active && function_exists( 'pf_peek_current_minute_prize' ) ) {
	$pf_debug_competition_id = function_exists( 'pf_resolve_competition_id' )
		? pf_resolve_competition_id()
		: (int) get_option( 'pepsi_football_competition_id', 0 );
	$pf_debug_route          = function_exists( 'pf_current_route' ) ? pf_current_route() : 'main';
	$pf_debug_prize          = pf_peek_current_minute_prize( $pf_debug_competition_id, $pf_debug_route );
	$pf_debug_now            = current_time( 'mysql' );
	$pf_debug_window_min     = function_exists( 'pf_prize_window_minutes' )
		? pf_prize_window_minutes( $pf_debug_competition_id )
		: 10;
	$pf_debug_window_start   = date( 'H:i:s', strtotime( $pf_debug_now ) - $pf_debug_window_min * 60 );
	$pf_debug_window_end     = date( 'H:i:s', strtotime( $pf_debug_now ) );
	$pf_debug_tz             = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '?';
}

get_header();
?>

<?php if ( $pf_debug_active ) : ?>
	<div id="pf-debug-panel" class="pf-debug-panel" data-js="pf-debug-panel" role="status" aria-live="polite">
		<strong>PF DEBUG</strong>
		<span>now: <?php echo esc_html( $pf_debug_now ); ?> (<?php echo esc_html( $pf_debug_tz ); ?>)</span>
		<span>route: <?php echo esc_html( $pf_debug_route ); ?> · comp: <?php echo (int) $pf_debug_competition_id; ?></span>
		<span>window: <?php echo esc_html( $pf_debug_window_start ); ?>–<?php echo esc_html( $pf_debug_window_end ); ?> (<?php echo (int) $pf_debug_window_min; ?>min)</span>
		<?php if ( is_array( $pf_debug_prize ) ) : ?>
			<span class="pf-debug-panel__prize pf-debug-panel__prize--active">
				🏆 prize id=<?php echo (int) $pf_debug_prize['id']; ?> ·
				<?php echo esc_html( $pf_debug_prize['prize_type'] ?? '?' ); ?> ·
				<?php echo esc_html( $pf_debug_prize['prize_name'] ?? '?' ); ?> ·
				<?php echo esc_html( $pf_debug_prize['code'] ?? '' ); ?>
			</span>
		<?php else : ?>
			<span class="pf-debug-panel__prize pf-debug-panel__prize--none">no prize in this minute</span>
		<?php endif; ?>
		<span class="pf-debug-panel__actions">
			<button type="button" class="pf-debug-panel__shoot" data-js="pf-debug-perfect-run">PERFECT RUN</button>
			<button type="button" class="pf-debug-panel__shoot" data-js="pf-debug-assist-run">STAR IN ASSIST BAND</button>
			<button type="button" class="pf-debug-panel__shoot" data-js="pf-debug-wide-run">WIDE STAR (no win)</button>
			<button type="button" class="pf-debug-panel__shoot" data-js="pf-debug-play-star">PLAY FROM STAR</button>
			<button type="button" class="pf-debug-panel__shoot" data-js="pf-debug-selftest">RUN SELF-TEST</button>
		</span>
		<pre class="pf-debug-panel__result" data-js="pf-debug-selftest-out" aria-live="polite"></pre>
	</div>
<?php endif; ?>

<main class="game-page page-game" data-js="game-page" aria-label="Pepsi Xmas Drop" style="<?php echo esc_attr( '--game-bg: url(' . pf_game_background_url() . ')' ); ?>">

	<!-- Game loader — in the HTML so it works before any JS has run, but
	     deferred: it only fades in if boot is still going after 0.4s (slow
	     network / cold cache). A warm boot finishes first and it never shows.
	     game.ts adopts it (createPfLoader). -->
	<div class="pf-loader pf-loader--deferred" data-js="pf-loader" aria-hidden="true">
		<div class="pf-loader__inner">
			<div class="pf-loader__ball" aria-hidden="true"><img class="pf-loader__image" src="<?php echo esc_url( pf_loader_image_url() ); ?>" alt="" decoding="async" fetchpriority="high"></div>
			<span class="pf-loader__label">LOADING</span>
			<span class="pf-loader__sublabel"></span>
		</div>
	</div>

	<!-- Backdrop: wide_bg.png (the game artwork extended sideways) scaled and
	     offset to line up exactly with the stage, so the letterbox gaps on
	     short / wide screens show more artwork instead of black. -->
	<div class="page-game__backdrop" aria-hidden="true"></div>

	<!-- Fixed-aspect stage (829 × 1793, the background artwork's size). Every
	     game element lives inside and scales with it, so nothing reflows or
	     overlaps at other viewport sizes — spare space is letterboxed. -->
	<div class="page-game__stage" data-js="game-stage">

	<header class="page-game__header" data-js="game-header">
		<img class="page-game__header-image" src="<?php echo esc_url( $images_uri . '/game_header.png' ); ?>" alt="Pepsi Xmas Drop" />
		<p class="page-game__level" aria-live="polite" data-js="hud-level">PRACTICE</p>
		<!-- Practice go runs first (client-only, never touches the server); this skips straight to the real game. -->
		<button type="button" class="page-game__skip-practice" data-js="skip-practice" hidden>SKIP<br/>PRACTICE</button>
	</header>

	<!-- Three.js mounts here -->
	<div id="game-canvas-container" class="game-canvas-container page-game__canvas-container" data-js="game-canvas-container">
		<canvas id="game-canvas" data-js="game-canvas" aria-label="Xmas Drop game canvas"></canvas>
	</div>

	<!-- DOM overlays — GSAP-animated, z-indexed above canvas -->
	<div id="game-overlays" class="game-overlays page-game__overlays" data-js="game-overlays" aria-live="polite">

		<div id="overlay-instruction" class="overlay overlay--instruction page-game__overlay page-game__overlay--instruction">
			<p class="overlay__text page-game__overlay-text">tap to drop the items<br/> into position to  build<br/> the pepsi xmas tree</p>
		</div>

		<button type="button" class="page-game__drop-btn is-off" data-js="drop-button" aria-label="Drop" disabled>
			<img class="page-game__drop-btn-image" data-js="drop-button-image" src="<?php echo esc_url( $drop_button_image_url ); ?>" alt="" />
		</button>

		<div class="overlay overlay--level page-game__overlay" data-js="level-banner" aria-hidden="true">
			<p class="overlay__text page-game__overlay-text" data-js="level-banner-text"></p>
		</div>

		<div id="overlay-commentary" class="overlay overlay--commentary page-game__overlay page-game__overlay--commentary" aria-hidden="true">
			<p class="overlay__text page-game__overlay-text" id="commentary-text"></p>
		</div>

		<div class="overlay__swipe-hint-wrap page-game__swipe-hint-wrap" aria-hidden="true">
			<img id="overlay-swipe-hint" class="overlay__swipe-hint overlay__swipe-hint--tap page-game__swipe-hint" src="<?php echo esc_url( $tap_hint_image_url ); ?>" alt="" aria-hidden="true" />
		</div>

		<?php if ( $partner_logo_url !== '' ) : ?>
			<div class="scene__footer page-game__footer">
				<img class="scene__partner-logo page-game__partner-logo" src="<?php echo esc_url( $partner_logo_url ); ?>" alt="Partner logo" style="<?php echo esc_attr( $partner_logo_style ); ?>" />
			</div>
		<?php endif; ?>

	</div><!-- /.game-overlays -->

	</div><!-- /.page-game__stage -->

</main>

<?php get_footer(); ?>
