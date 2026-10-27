// Site bundle — loads on every page. Kept free of Three.js / the game so the
// age gate, how-to-play and form pages don't download the game; /game/ adds
// game.ts on top (assets.php). Modules shared with the game (transitions,
// sounds, loader) land in a common chunk, so each runs once per page.

import { initAgeGatePage }               from './ui/age-gate.js';
import { initPhotoPage }                 from './ui/photo.js';
import { initExitTrap }                  from './ui/exit-trap.js';
import { initPageTransitions }           from './ui/transitions.js';
// Date fields render as plain text inputs via a gform_field_input filter (see
// includes/lib/methods.php); the date-mask module keeps DD/MM/YYYY slashes in
// place as the user types.
import { initDateMask } from './ui/date-mask.js';
import { sounds } from './audio/sounds.js';

import './scss/style.scss';

// Back-button trap must arm BEFORE anything else can throw and abort the
// module — without it, Back from /prize-paid/ would reach the stale Stripe
// onboarding page (a cross-origin entry the server cannot intercept), and
// Back from /payout-failed/ would bfcache-restore /congratulations/ with the
// already-consumed claim form, letting it be re-POSTed.
initExitTrap();

initPageTransitions();
initDateMask();

// Site-wide cleanup of Gravity Forms' #gf_<id> anchor hash so it doesn't
// linger in shareable URLs after a form submit / page-advance.
const stripGfAnchor = (): void => {
	if ( /^#gf_\d+/.test( window.location.hash ) ) {
		history.replaceState( null, '', window.location.pathname + window.location.search );
	}
};
stripGfAnchor();
window.addEventListener( 'hashchange', stripGfAnchor );

initAgeGatePage();
initPhotoPage();
initHowToPlayAudioPrime();
warmGameAssets();

// ── How-to-play → warm /game/'s assets ───────────────────────────────────────
// assets.php lists what /game/ needs (bundle, loader bauble, backdrop, sprites)
// in warm_urls on how-to-play only. Fetch them at low priority once this page
// has loaded, so the HTTP cache has them by the time Play is tapped. Failures
// don't matter — /game/ just fetches normally.
function warmGameAssets(): void {
	const urls = window.PepsiXmasConfig?.warm_urls ?? [];
	if ( ! urls.length ) return;
	const warm = (): void => {
		for ( const url of urls ) {
			fetch( url, { credentials: 'same-origin', priority: 'low' } as RequestInit ).catch( () => undefined );
		}
	};
	if ( document.readyState === 'complete' ) warm();
	else window.addEventListener( 'load', warm, { once: true } );
}

// ── How-to-play → audio priming ──────────────────────────────────────────────
// Unlock the AudioContext inside the Play-button click on /how-to-play/ so the
// same-origin navigation carries sticky activation across to /game/.
function initHowToPlayAudioPrime(): void {
	const playBtn = document.querySelector<HTMLAnchorElement>(
		'[data-js="how-to-play-page"] .page-how-to-play__play-btn',
	);
	if ( ! playBtn ) return;

	const config = window.PepsiXmasConfig ?? {};
	sounds.setUrls( config.sounds ?? {} );

	playBtn.addEventListener( 'click', () => {
		if ( sounds.isMuted() ) return;
		void sounds.unlock().then( () => {
			try { sessionStorage.setItem( 'pf_ambience_primed', '1' ); } catch { /* */ }
		} );
	}, { capture: true } );
}
