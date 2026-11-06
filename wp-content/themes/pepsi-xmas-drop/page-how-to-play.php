<?php
/**
 * Template Name: How to play
 * Page flow step: how-to-play
 */
defined( 'ABSPATH' ) || exit;

if ( have_posts() ) {
	the_post();
}

$ctx = pf_page_context( 'how-to-play', [
	'assets'         => [ 'header_graphic', 'headline_graphic', 'hero_image' ],
	'multi_fallback' => [ 'how-to-play', 'how_to_play' ],
] );

$instructions_html = trim( (string) get_the_content() );
if ( $instructions_html !== '' ) {
	$instructions_html = apply_filters( 'the_content', $instructions_html );
}

// Play → the next step in the BPM Page Flow (normally /game/).
$game_page_url = function_exists( 'pf_flow_next_url' ) ? pf_flow_next_url( 'how-to-play' ) : '';
if ( $game_page_url === '' ) {
	$game_page_url = home_url( '/game/' );
}

get_header();
?>

<style>
/* Intro panel is shown by default via the SCSS `.page-how-to-play__panel--intro`
   rule; the instructions panel stays hidden (base panel CSS sets opacity: 0 /
   visibility: hidden) until the JS below swaps `is-visible` onto it after 3 s.
   No fallback rules for `:not(.has-how-to-play-transition)` — having them
   caused the instructions panel to flash on first paint before JS ran. */

.page-how-to-play__play-btn {
	margin-top: 16px;
	width: 98px;
	min-width: 98px;
	max-width: 98px;
	padding: 0;
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}
</style>

<main class="page-how-to-play" data-js="how-to-play-page">
	<div class="scene scene--how-to-play page-how-to-play__scene">

		<section class="page-how-to-play__panel page-how-to-play__panel--intro" data-how-to-play-panel="1">
			<?php if ( $ctx['header_graphic_url'] ) : ?>
				<img class="page-how-to-play__graphic page-how-to-play__graphic--intro" src="<?php echo esc_url( $ctx['header_graphic_url'] ); ?>" alt="" style="<?php echo esc_attr( $ctx['header_graphic_style'] ); ?>" />
			<?php elseif ( $ctx['headline_graphic_url'] ) : ?>
				<img class="page-how-to-play__graphic page-how-to-play__graphic--intro" src="<?php echo esc_url( $ctx['headline_graphic_url'] ); ?>" alt="" style="<?php echo esc_attr( $ctx['headline_graphic_style'] ); ?>" />
			<?php else : ?>
				<h1 class="scene__headline page-how-to-play__headline">WIN THE<br/>XMAS DROP</h1>
			<?php endif; ?>
		</section>

		<section class="page-how-to-play__panel page-how-to-play__panel--instructions" data-how-to-play-panel="2" aria-hidden="true">
			<?php if ( $ctx['hero_image_url'] ) : ?>
				<img class="page-how-to-play__graphic page-how-to-play__graphic--instructions" src="<?php echo esc_url( $ctx['hero_image_url'] ); ?>" alt="" style="<?php echo esc_attr( $ctx['hero_image_style'] ); ?>" />
			<?php endif; ?>

			<div class="page-how-to-play__instructions">
				<?php if ( $instructions_html !== '' ) : ?>
					<?php echo $instructions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<?php
					// Numbered steps: big blue number (col 1, one size for all),
					// white text (col 2). Each entry is one line of the copy.
					$how_to_play_steps = [
						[ 'BUILD THE MARSTONS XMAS', 'TREE ACROSS 4 LEVELS', 'LAND THE STAR ON TOP TO WIN.' ],
						[ 'TAP THE MARSTONS BUTTON TO', 'DROP EACH MOVING DECORATION', 'INTO ITS MATCHING SPACE.', 'TIME YOUR TAP CAREFULLY!' ],
						[ 'EACH LEVEL GETS FASTER. IF A', 'DECORATION DOESN’T LAND IN THE', 'RIGHT SPACE, IT’S GAME OVER.', 'GOOD LUCK!' ],
					];
					?>
					<ol class="page-how-to-play__steps">
						<?php foreach ( $how_to_play_steps as $i => $lines ) : ?>
							<li class="page-how-to-play__step">
								<span class="page-how-to-play__step-num" aria-hidden="true"><?php echo (int) $i + 1; ?></span>
								<p class="page-how-to-play__step-text"><?php echo implode( '<br/>', array_map( 'esc_html', $lines ) ); ?></p>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
			<div class="pf-cta-dock">
				<a class="pepsi-pill-button page-how-to-play__play-btn" href="<?php echo esc_url( $game_page_url ); ?>">Play</a>
			</div>

		</section>

	</div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var root = document.querySelector('[data-js="how-to-play-page"]');
	var sceneOne = document.querySelector('[data-how-to-play-panel="1"]');
	var sceneTwo = document.querySelector('[data-how-to-play-panel="2"]');

	if (!root || !sceneOne || !sceneTwo) {
		return;
	}

	root.classList.add('has-how-to-play-transition');
	sceneOne.classList.remove('is-hidden');
	sceneTwo.classList.remove('is-visible');
	sceneOne.setAttribute('aria-hidden', 'false');
	sceneTwo.setAttribute('aria-hidden', 'true');

	window.setTimeout(function () {
		sceneOne.classList.add('is-hidden');
		sceneOne.setAttribute('aria-hidden', 'true');
		sceneTwo.classList.add('is-visible');
		sceneTwo.setAttribute('aria-hidden', 'false');
	}, 3000);
});
</script>

<?php get_footer(); ?>
