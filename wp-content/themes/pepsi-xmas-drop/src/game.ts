// Game bundle — /game/ only (assets.php), loaded after site.ts. Everything that
// needs Three.js / GSAP lives here so the other screens never download it.

import gsap from 'gsap';
import * as THREE from 'three';

import { createScene }                   from './renderer/scene.js';
import { createTrack, createTree, LAYOUT, toWorldX, TREE_BOTTOM_Y, TREE_TRIANGLE } from './renderer/tree.js';
import { createPiece, loadPieceTextures, PIECE_SIZE, TRACK_SCALE } from './renderer/pieces.js';
import { baubleY, baubleZ, FRONT_Z }     from './renderer/baubles.js';
import { fitCameraToBackground, worldYAtPx } from './renderer/fit.js';
import { overlay }                       from './renderer/overlay.js';
import {
	isDropGameConfig, sliderX,
	type DropGameConfig, type DropResponse, type FailStyle, type PieceDef,
} from './game-engine/config.js';
import { createPfLoader }                from './ui/loader.js';
import { initDropRipple }                from './ui/drop-button.js';
import { navigateFade }                  from './ui/transitions.js';
import { sounds }                        from './audio/sounds.js';

// ── Bootstrap ─────────────────────────────────────────────────────────────────

const canvas   = document.getElementById( 'game-canvas' ) as HTMLCanvasElement | null;
const gamePage = document.querySelector<HTMLElement>( '[data-js="game-page"]' );
if ( canvas && gamePage ) {
	// bfcache belt-and-braces (mainly Safari): force a fresh server request so
	// flow-guard's tree_started check runs and bounces the player.
	window.addEventListener( 'pageshow', ( e: PageTransitionEvent ) => {
		if ( e.persisted ) {
			location.reload();
		}
	} );

	// page-game.php renders the loader in the HTML so it's on screen from first
	// paint; adopt it.
	const loader = createPfLoader( {
		el:     gamePage.querySelector<HTMLElement>( '[data-js="pf-loader"]' ) ?? undefined,
		label:  'LOADING',
		parent: gamePage,
	} );
	const done = (): void => loader.hide();

	init( canvas, gamePage ).then( done, done );
}

// ── Init ──────────────────────────────────────────────────────────────────────

async function init( canvas: HTMLCanvasElement, gamePage: HTMLElement ): Promise<void> {
	const config             = window.PepsiXmasConfig ?? {};
	const congratulationsUrl = config.congratulations_url ?? '';
	const nonWinnerUrl       = config.non_winner_url      ?? '';
	const dailyLimitUrl      = config.daily_limit_url     ?? '';
	const restUrl            = config.rest_url            ?? '';
	const restNonce          = config.rest_nonce          ?? '';
	const pfNonce            = config.pf_nonce            ?? '';

	// ── Audio ──────────────────────────────────────────────────────────────────
	sounds.setUrls( config.sounds ?? {} );
	// No mute control on the game page, so clear any mute persisted from an
	// earlier build — otherwise that player would be silenced for good.
	sounds.setMuted( false );

	let ambienceKickedOff = false;
	const startAmbienceOnce = (): void => {
		if ( ambienceKickedOff ) return;
		if ( sounds.isMuted() )  return;
		// Don't claim success against a suspended context (iOS) — the gesture
		// handler re-invokes this once unlock() has resumed it.
		if ( ! sounds.isRunning() ) return;
		ambienceKickedOff = true;
		void sounds.playAmbience();
	};

	initSoundUnlock();

	// X-PF-Nonce is verified by pf_rest_permission_game on the server — proves
	// the request originated from a real /game/ render.
	const restHeaders = (): Record<string, string> => ( {
		'X-WP-Nonce':   restNonce,
		'X-PF-Nonce':   pfNonce,
		'Content-Type': 'application/json',
	} );

	// Debug hook — declared before wireDebugPanel(), which sets it.
	let handBackAtStar = false;   // PLAY FROM STAR: auto-run stops before the star

	// Bind the debug panel early so it works even if later setup stalls.
	wireDebugPanel();

	// Sprites + fonts download in parallel with /session/start rather than after
	// it — each is independently bounded, so a stall can't hang boot.
	const texturesReady = loadPieceTextures( config.piece_images ?? {} );
	const fontsReady    = document.fonts?.ready
		? Promise.race( [
			document.fonts.ready.catch( () => undefined ),
			new Promise( ( r ) => setTimeout( r, 1500 ) ),
		] )
		: Promise.resolve();

	// ── Server session ─────────────────────────────────────────────────────────
	// Window-level dedup: a second init pass must reuse the in-flight
	// /session/start rather than POST again (rate limit + daily-limit count).
	let sessionToken = '';
	let game: DropGameConfig | null = null;
	type SessionStartResult = { session_token?: string; game?: unknown; error?: string; redirect?: string; __status: number };
	const w = window as Window & { __pfSessionStart?: Promise<SessionStartResult> };
	if ( restUrl ) {
		try {
			if ( ! w.__pfSessionStart ) {
				w.__pfSessionStart = fetch( `${ restUrl }/session/start`, {
					method:  'POST',
					headers: restHeaders(),
				} ).then( async ( res ): Promise<SessionStartResult> => {
					const data = await res.json() as Partial<SessionStartResult>;
					return { ...data, __status: res.status };
				} );
			}
			const data = await w.__pfSessionStart;
			if ( data.error === 'tree_started' ) {
				// Already played this entry — re-entry goes through the age-gate.
				location.replace( data.redirect || '/' );
				return;
			}
			if ( data.error === 'daily_limit' && dailyLimitUrl ) {
				navigateFade( dailyLimitUrl );
				return;
			}
			sessionToken = data.session_token ?? '';
			if ( isDropGameConfig( data.game ) ) {
				game = data.game;
			}
		} catch {
			/* network/abort — handled below */
		}
	}

	if ( ! game || ! sessionToken ) {
		overlay.showCommentary( 'CONNECTION PROBLEM.\nPLEASE REFRESH TO TRY AGAIN.' );
		return;
	}
	const cfg: DropGameConfig = game;

	// Font preflight (started above) — bounded so a stalled font fetch can't hang boot.
	await fontsReady;

	// ── Scene ──────────────────────────────────────────────────────────────────
	// Camera is fitted so the tree sits exactly on the triangle drawn in
	// game_bg.png; the track goes in the sky between the header and the apex.
	// The stage is the fixed-aspect box everything scales with (see _game.scss);
	// designPx() converts 829-wide design px to its current CSS px.
	const stage    = gamePage.querySelector<HTMLElement>( '[data-js="game-stage"]' ) ?? gamePage;
	const designPx = ( n: number ): number => n * ( stage.clientWidth / 829 );

	let track: THREE.Object3D | null = null;
	let onRefit: () => void = () => undefined;
	const { scene, camera, renderer } = createScene( canvas, ( cam ) => {
		const fit     = fitCameraToBackground( cam, canvas, stage, TREE_TRIANGLE );
		const minPx   = ( PIECE_SIZE / 2 ) * fit.scale + designPx( 17 );   // keep the whole piece on screen
		const lanePx  = Math.max( fit.apexPx * 0.5, minPx );
		LAYOUT.LANE_Y = worldYAtPx( cam, lanePx, fit.scale );
		if ( track ) track.position.y = LAYOUT.LANE_Y;
		onRefit();
	} );
	await texturesReady;
	const tree = createTree( scene, cfg.pieces );
	track = createTrack( scene, config.track_image ?? '', cfg.x_max );
	if ( track ) track.position.y = LAYOUT.LANE_Y;

	// ── Drop button — centred, just below the tree ────────────────────────────
	// Positioned from the camera projection so it tracks the tree at every
	// viewport size (the canvas and overlay layers share the same box).
	const dropBtn = gamePage.querySelector<HTMLButtonElement>( '[data-js="drop-button"]' );
	const placeDropButton = (): void => {
		if ( ! dropBtn ) return;
		const v   = new THREE.Vector3( 0, TREE_BOTTOM_Y, 0 ).project( camera );
		// Just under the tree, but never pushed off the bottom of the screen.
		const top = Math.min(
			( -v.y * 0.5 + 0.5 ) * canvas.clientHeight + designPx( 26 ),
			canvas.clientHeight - dropBtn.offsetHeight - designPx( 26 ),
		);
		dropBtn.style.top    = `${ Math.round( top ) }px`;
		dropBtn.style.bottom = 'auto';

		// Tap-hint hand: sits at the button's lower right with the (up-left
		// pointing) fingertip on the logo. Hidden on the first drop.
		const hand = gamePage.querySelector<HTMLElement>( '.overlay__swipe-hint-wrap' );
		if ( hand ) {
			const size = dropBtn.offsetWidth;
			hand.style.top       = `${ Math.round( top + size * 0.5 ) }px`;
			hand.style.left      = `${ Math.round( canvas.clientWidth / 2 + size * 0.02 ) }px`;
			hand.style.transform = 'none';
		}
	};
	placeDropButton();
	const dropRipple = initDropRipple( gamePage.querySelector<HTMLElement>( '[data-js="drop-button-image"]' ) );

	renderer.render( scene, camera );
	await new Promise<void>( ( r ) => requestAnimationFrame( () => r() ) );

	// ── State ──────────────────────────────────────────────────────────────────
	type State = 'idle' | 'sliding' | 'dropping' | 'over';
	let state: State       = 'idle';
	let current: PieceDef | null = null;
	let currentMesh: THREE.Group | null = null;
	let spawnedAt          = 0;
	let forcedX: ( ( p: PieceDef ) => number ) | null = null;   // debug auto-run

	// Practice go: one full run before the real game, judged here in the
	// browser against the same served tolerances. It never calls /drop/attempt,
	// so it can't reserve a slot, set tree_started or write an audit row.
	let practice = true;
	let runGen   = 0;   // bumped when practice ends — stale callbacks from it bail out
	const practiceMeshes: THREE.Group[] = [];
	const skipBtn = gamePage.querySelector<HTMLButtonElement>( '[data-js="skip-practice"]' );

	// Re-fit on resize: move the button, and keep a sliding piece on the track.
	onRefit = () => {
		placeDropButton();
		if ( state === 'sliding' && currentMesh ) currentMesh.position.y = LAYOUT.LANE_Y;
	};

	const levelOf = ( p: PieceDef ) => cfg.levels.find( ( l ) => l.level === p.level ) ?? cfg.levels[ 0 ];

	const clock = new THREE.Clock();

	// ── Render loop ────────────────────────────────────────────────────────────
	gsap.ticker.fps( 60 );
	gsap.ticker.lagSmoothing( 500, 16 );
	gsap.ticker.add( () => {
		const t = clock.getElapsedTime();
		if ( state === 'sliding' && current && currentMesh ) {
			const lv = levelOf( current );
			const nx = sliderX( ( performance.now() - spawnedAt ) / 1000, lv.speed, cfg.x_max, current.index );
			currentMesh.position.x = toWorldX( nx );
			currentMesh.rotation.z = Math.sin( t * 3 ) * 0.08;
		}
		tree.update( t );
		renderer.render( scene, camera );
	} );

	// ── Piece lifecycle ────────────────────────────────────────────────────────

	function spawn( p: PieceDef ): void {
		current     = p;
		currentMesh = createPiece( p.type );
		// FRONT_Z: in front of the whole tree while on the track; it drops on its own layer.
		currentMesh.position.set( toWorldX( p.index % 2 === 1 ? -cfg.x_max : cfg.x_max ), LAYOUT.LANE_Y, FRONT_Z );
		currentMesh.scale.setScalar( 0.01 );
		scene.add( currentMesh );
		if ( practice ) practiceMeshes.push( currentMesh );
		gsap.to( currentMesh.scale, { x: TRACK_SCALE, y: TRACK_SCALE, z: TRACK_SCALE, duration: 0.25, ease: 'back.out(2)' } );
		tree.setTarget( p.index );
		overlay.setLevel( practice ? 'practice' : p.level );
		spawnedAt = performance.now();
		state     = 'sliding';
		if ( handBackAtStar && p.type === 'star' ) forcedX = null;   // PLAY FROM STAR: player drops the star
		if ( dropBtn ) {
			dropBtn.disabled = !! forcedX;
			dropBtn.classList.toggle( 'is-off', !! forcedX );
		}

		if ( forcedX ) {
			// Debug auto-run: drop after a short, human-ish beat.
			gsap.delayedCall( 0.45, () => handleTap() );
		}
	}

	function handleTap(): void {
		if ( state !== 'sliding' || ! current || ! currentMesh ) return;
		state = 'dropping';
		dropRipple.play();
		if ( dropBtn ) dropBtn.disabled = true;
		overlay.hideInstruction();

		const p    = current;
		const mesh = currentMesh;
		const lv   = levelOf( p );
		const nx   = forcedX
			? forcedX( p )
			: sliderX( ( performance.now() - spawnedAt ) / 1000, lv.speed, cfg.x_max, p.index );
		mesh.position.x = toWorldX( nx );
		mesh.rotation.z = 0;
		// Fall on the piece's own layer, so it passes behind the pieces that
		// sit in front of it and lands already layered — no pop on landing.
		mesh.position.z = baubleZ( p.type );

		const gen      = runGen;
		const verdict  = practice ? Promise.resolve( judgePractice( p, nx ) ) : postDrop( p.index, nx );
		const duration = 0.32 + 0.05 * ( 4 - p.row );
		const fellAt   = performance.now();
		const fallInfo = { dist: mesh.position.y - baubleY( p.type ), duration };
		// Grow from track size to seated size on the way down.
		gsap.to( mesh.scale, { x: 1, y: 1, z: 1, duration, ease: 'power2.in', overwrite: true } );
		const fall     = new Promise<void>( ( resolve ) => {
			gsap.to( mesh.position, {
				y:        baubleY( p.type ),
				duration,
				ease:     'power2.in',
				onComplete: () => resolve(),
			} );
		} );
		// A seat verdict that arrives mid-fall eases the piece onto its anchor
		// over the rest of the fall, so it touches down exactly in place. A miss
		// never moves sideways: it lands at drop_x (WIN-BLOCK anti-tell).
		void verdict.then( ( res ) => {
			const left = duration - ( performance.now() - fellAt ) / 1000;
			if ( res?.result !== 'landed' || left <= 0.02 ) return;
			gsap.to( mesh.position, { x: toWorldX( res.anchor_x ), duration: left, ease: 'power1.out' } );
		} );

		void Promise.all( [ verdict, fall ] ).then( ( [ res ] ) => {
			if ( gen === runGen ) resolveDrop( p, mesh, res, fallInfo );
		} );
	}

	// Practice verdict — the same rule as pf_decide_drop_outcome() for an
	// ordinary piece (seat within the level's tolerance, fall side follows the
	// overhang). The star is judged on its base tolerance like any other piece:
	// no slot, no assist, nothing sent to the server.
	function judgePractice( p: PieceDef, nx: number ): DropResponse {
		const signed  = nx - p.anchor_x;
		const seated  = Math.abs( signed ) <= levelOf( p ).tolerance;
		const styles: FailStyle[] = [ 'tip', 'roll', 'bounce' ];
		const side    = Math.abs( signed ) < 0.01 ? ( Math.random() < 0.5 ? 1 : -1 ) : ( signed > 0 ? 1 : -1 );
		return {
			piece:    p.index,
			result:   seated ? 'landed' : 'fell',
			land_x:   nx,
			anchor_x: p.anchor_x,
			fail:     seated ? null : { style: styles[ Math.floor( Math.random() * styles.length ) ], side },
			placed:   seated ? p.index : p.index - 1,
			run_over: ! seated || p.type === 'star',
			label:    '',
		};
	}

	async function postDrop( piece: number, dropX: number ): Promise<DropResponse | null> {
		const body = JSON.stringify( { session_token: sessionToken, piece, drop_x: dropX } );
		for ( const wait of [ 0, 600, 1200 ] ) {
			if ( wait ) await new Promise( ( r ) => setTimeout( r, wait ) );
			try {
				const res  = await fetch( `${ restUrl }/drop/attempt`, { method: 'POST', headers: restHeaders(), body } );
				const data = await res.json() as DropResponse;
				if ( res.ok && ! data.error ) return data;
				if ( res.status !== 429 && res.status < 500 ) return null;   // permanent refusal
			} catch { /* network — retry */ }
		}
		return null;
	}

	function resolveDrop( p: PieceDef, mesh: THREE.Group, res: DropResponse | null, fall: FallInfo ): void {
		const gen = runGen;
		if ( ! res ) {
			// Server unreachable/refused — end the run as a non-winner.
			animateFall( mesh, 'tip', Math.random() < 0.5 ? 1 : -1, p.row, fall );
			finish( false, 'CONNECTION LOST.\nPLEASE TRY AGAIN.' );
			return;
		}

		if ( res.result === 'landed' ) {
			sounds.playDrop();
			// Normally already on the anchor (eased there during the fall); a
			// verdict that arrived after touchdown slides it the last bit.
			const ax = toWorldX( res.anchor_x );
			if ( Math.abs( mesh.position.x - ax ) > 0.01 ) {
				gsap.to( mesh.position, { x: ax, duration: 0.12, ease: 'power2.out' } );
			} else {
				mesh.position.x = ax;
			}
			tree.fillSlot( p.index );

			if ( res.run_over ) {
				tree.showDecor( p.level );
				celebrate( mesh );
				if ( practice ) endPractice( true ); else finish( true, res.label );
				return;
			}
			const next = cfg.pieces.find( ( n ) => n.index === p.index + 1 );
			if ( ! next ) return;
			if ( next.level > p.level ) {
				state = 'idle';
				tree.showDecor( p.level );
				void overlay.showLevelBanner( next.level === 4 ? 'THE STAR!' : `LEVEL ${ next.level }` )
					.then( () => { if ( gen === runGen ) spawn( next ); } );
			} else {
				gsap.delayedCall( 0.25, () => { if ( gen === runGen ) spawn( next ); } );
			}
			return;
		}

		// Missed — touches down where it was dropped, then see animateFall().
		mesh.position.x = toWorldX( res.land_x );
		sounds.playDrop();
		animateFall( mesh, res.fail?.style ?? 'tip', res.fail?.side ?? 1, p.row, fall );
		if ( practice ) endPractice( false ); else finish( false, res.label );
	}

	// How far / how long the piece fell to its slot height (lets a miss carry on falling).
	type FallInfo = { dist: number; duration: number };

	// Spin rate (rad/s) and sideways drift (world units/s) per fail style. The
	// style comes from the server's shared pool, so honest misses and a
	// WIN-BLOCKed star draw from the same set of tumbles.
	const TUMBLE: Record<string, { spin: number; drift: number }> = {
		tip:    { spin: 3.0, drift: 0.9 },
		roll:   { spin: 6.0, drift: 1.6 },
		bounce: { spin: 4.5, drift: 2.4 },
	};

	// A miss always touches down at drop_x (WIN-BLOCK anti-tell — never shifted).
	// Bottom row: nothing below to fall past, so it stays where it landed,
	// leaning towards the side it overhangs. Rows above (star included): no
	// stop — it carries straight on down through the tree and off screen,
	// tumbling towards that side. The star shares this path with every honest
	// row 2–3 miss, so a blocked star looks the same as any other miss.
	function animateFall( mesh: THREE.Group, style: string, side: number, row: number, fall: FallInfo ): void {
		if ( row === 0 ) {
			gsap.timeline()
				.to( mesh.position, { y: '+=0.12', duration: 0.09, ease: 'power1.out', yoyo: true, repeat: 1 } )
				.to( mesh.rotation, { z: -side * 0.22, duration: 0.35, ease: 'back.out(2.5)' }, 0.1 );
			return;
		}

		const { spin, drift } = TUMBLE[ style ] ?? TUMBLE.tip;
		// The drop was power2.in (constant acceleration), so it reaches slot
		// height at 2·dist/duration. It keeps part of that speed — it has just
		// clipped the pieces below — then accelerates away under gravity.
		const v0 = 0.45 * ( 2 * Math.max( fall.dist, 0.1 ) / fall.duration );
		const g  = 22;
		const y0 = mesh.position.y;
		const x0 = mesh.position.x;
		const r0 = mesh.rotation.z;
		const h  = y0 - LAYOUT.FLOOR_Y;
		const tEnd  = ( -v0 + Math.sqrt( v0 * v0 + 2 * g * h ) ) / g;
		const proxy = { t: 0 };
		gsap.to( proxy, {
			t: tEnd, duration: tEnd, ease: 'none',
			onUpdate: () => {
				const t = proxy.t;
				mesh.position.y = y0 - v0 * t - 0.5 * g * t * t;
				mesh.position.x = x0 + side * drift * t;
				mesh.rotation.z = r0 - side * spin * t;
			},
			onComplete: () => { scene.remove( mesh ); },
		} );
	}

	// ── Practice → real game ──────────────────────────────────────────────────

	function endPractice( completed: boolean ): void {
		const gen = runGen;
		state = 'over';
		tree.setTarget( null );
		if ( dropBtn ) {
			dropBtn.disabled = true;
			dropBtn.classList.add( 'is-off' );
		}
		if ( completed ) sounds.playWin(); else sounds.playMiss();
		gsap.delayedCall( 0.6, () => {
			if ( gen !== runGen ) return;
			overlay.showCommentary( completed ? 'PRACTICE COMPLETE!\nNOW FOR REAL…' : 'THAT WAS JUST PRACTICE!\nNOW FOR REAL…' );
		} );
		gsap.delayedCall( 3.0, () => { if ( gen === runGen ) startRealGame(); } );
	}

	/** Clear the practice tree and start the real, server-judged run at piece 1. */
	function startRealGame(): void {
		if ( ! practice ) return;
		practice = false;
		runGen++;
		const gen = runGen;
		if ( skipBtn ) skipBtn.hidden = true;
		overlay.hideCommentary();
		for ( const m of practiceMeshes ) {
			gsap.killTweensOf( [ m.position, m.rotation, m.scale ] );
			scene.remove( m );
		}
		practiceMeshes.length = 0;
		current     = null;
		currentMesh = null;
		state       = 'idle';
		tree.reset();
		if ( dropBtn ) {
			dropBtn.disabled = true;
			dropBtn.classList.add( 'is-off' );
		}
		overlay.setLevel( 1 );
		void overlay.showLevelBanner( 'LEVEL 1' ).then( () => { if ( gen === runGen ) spawn( cfg.pieces[ 0 ] ); } );
	}

	function celebrate( star: THREE.Group ): void {
		gsap.to( star.rotation, { y: Math.PI * 2, duration: 1.2, ease: 'power2.inOut' } );

		// Confetti burst from the star.
		const n   = 160;
		const geo = new THREE.BufferGeometry();
		const pos = new Float32Array( n * 3 );
		const vel: THREE.Vector3[] = [];
		const col = new Float32Array( n * 3 );
		const palette = [ new THREE.Color( 0x0025ff ), new THREE.Color( 0xe2001a ), new THREE.Color( 0xffffff ), new THREE.Color( 0xffc629 ) ];
		for ( let i = 0; i < n; i++ ) {
			pos.set( [ star.position.x, star.position.y, FRONT_Z ], i * 3 );
			vel.push( new THREE.Vector3( ( Math.random() - 0.5 ) * 9, Math.random() * 9 + 2, 0 ) );
			const c = palette[ i % palette.length ];
			col.set( [ c.r, c.g, c.b ], i * 3 );
		}
		geo.setAttribute( 'position', new THREE.BufferAttribute( pos, 3 ) );
		geo.setAttribute( 'color', new THREE.BufferAttribute( col, 3 ) );
		const points = new THREE.Points( geo, new THREE.PointsMaterial( { size: 0.22, vertexColors: true } ) );
		scene.add( points );
		const proxy = { t: 0 };
		gsap.to( proxy, {
			t: 3, duration: 3, ease: 'none',
			onUpdate: () => {
				const a = geo.attributes.position as THREE.BufferAttribute;
				for ( let i = 0; i < n; i++ ) {
					const v = vel[ i ];
					a.setXYZ( i, star.position.x + v.x * proxy.t, star.position.y + v.y * proxy.t - 4.9 * proxy.t * proxy.t, FRONT_Z );
				}
				a.needsUpdate = true;
			},
			onComplete: () => { scene.remove( points ); },
		} );
	}

	// ── End of run → complete session → redirect ──────────────────────────────

	function finish( landedStar: boolean, label: string ): void {
		state = 'over';
		tree.setTarget( null );
		if ( dropBtn ) {
			dropBtn.disabled = true;
			dropBtn.classList.add( 'is-off' );
		}
		if ( landedStar ) sounds.playWin(); else sounds.playMiss();

		gsap.delayedCall( 0.6, () => overlay.showCommentary( label ) );
		gsap.delayedCall( landedStar ? 3.6 : 3.0, async () => {
			let completionToken = '';
			let isWinner        = false;
			try {
				const res  = await fetch( `${ restUrl }/session/complete`, {
					method:  'POST',
					headers: restHeaders(),
					body:    JSON.stringify( { session_token: sessionToken } ),
				} );
				const data = await res.json() as { completion_token?: string; is_winner?: boolean; error?: string };
				completionToken = data.completion_token ?? '';
				// Server is authoritative for is_winner.
				isWinner = ! data.error && ( data.is_winner ?? false );
			} catch { /* token empty → non-winner path below */ }

			if ( isWinner && congratulationsUrl && completionToken ) {
				navigateFade( `${ congratulationsUrl }?token=${ completionToken }` );
			} else if ( nonWinnerUrl ) {
				navigateFade( nonWinnerUrl );
			}
		} );
	}

	// ── Input: Drop button, tap anywhere on the game, or Space ────────────────

	dropBtn?.addEventListener( 'pointerdown', ( e: PointerEvent ) => {
		e.preventDefault();   // fire on press, not release — timing matters
		if ( forcedX ) return;
		handleTap();
	} );
	gamePage.addEventListener( 'pointerdown', ( e: PointerEvent ) => {
		if ( ( e.target as HTMLElement )?.closest( 'button, a' ) ) return;
		if ( forcedX ) return;
		handleTap();
	} );
	window.addEventListener( 'keydown', ( e: KeyboardEvent ) => {
		if ( e.code !== 'Space' || forcedX ) return;
		e.preventDefault();
		handleTap();
	} );
	skipBtn?.addEventListener( 'click', () => startRealGame() );

	if ( skipBtn ) skipBtn.hidden = false;
	spawn( cfg.pieces[ 0 ] );   // practice go first

	// ── Debug panel: scenario runs + drop-flow self-test ──────────────────────
	// CORE win-gating verification (see CLAUDE.md). Each run drops every piece
	// dead-centre, then places the star at a chosen offset. Slot state comes
	// from the real BPM schedule (the PF DEBUG header shows whether a prize is
	// live this minute) — combine with a live / empty minute to exercise
	// WIN-ASSIST and WIN-BLOCK.
	function wireDebugPanel(): void {
		const bindDebug = ( sel: string, fn: () => void ): void => {
			document.querySelector<HTMLButtonElement>( sel )?.addEventListener( 'click', fn );
		};

		// Star offset as a multiple of the star level's base tolerance. The
		// server's assist multiplier is 2.5×.
		const autoRun = ( starTolMult: number ): void => {
			if ( ! game || ( state === 'over' && ! practice ) ) return;
			const starTol = game.levels.find( ( l ) => l.level === 4 )?.tolerance ?? 0;
			forcedX = ( p ) => p.type === 'star' ? p.anchor_x + starTol * starTolMult : p.anchor_x;
			// Debug runs are always the real, server-judged game — skip practice;
			// piece 1 then auto-drops as soon as it spawns.
			if ( practice ) {
				startRealGame();
				return;
			}
			if ( state === 'sliding' ) handleTap();
		};

		// PERFECT RUN — star dead-centre. Live slot → win; no slot → star falls.
		bindDebug( '[data-js="pf-debug-perfect-run"]', () => autoRun( 0 ) );
		// STAR IN ASSIST BAND — 2× tolerance: only seats with a live slot.
		bindDebug( '[data-js="pf-debug-assist-run"]', () => autoRun( 2.0 ) );
		// WIDE STAR — 3.5× tolerance: must NEVER win, even with a live slot.
		bindDebug( '[data-js="pf-debug-wide-run"]',   () => autoRun( 3.5 ) );
		// PLAY FROM STAR — pieces 1–8 auto-drop dead-centre, then the player
		// drops the star for real (same server path as a normal game).
		bindDebug( '[data-js="pf-debug-play-star"]', () => {
			handBackAtStar = true;
			autoRun( 0 );
		} );

		bindDebug( '[data-js="pf-debug-selftest"]', async () => {
			const out = document.querySelector<HTMLElement>( '[data-js="pf-debug-selftest-out"]' );
			if ( out ) out.textContent = 'running…';
			try {
				const res  = await fetch( `${ restUrl }/debug/dropflow-test`, { headers: restHeaders() } );
				const data = await res.json() as {
					all_pass?: boolean;
					cases?: Array<{ name: string; slot: string; actual: string; pass: boolean }>;
				};
				if ( out ) {
					const head  = data.all_pass ? '✅ ALL PASS' : '❌ FAILURES';
					const lines = ( data.cases ?? [] ).map( ( c ) =>
						`${ c.pass ? '✅' : '❌' } [slot ${ c.slot }] ${ c.name } → ${ c.actual }` );
					out.textContent = [ head, ...lines ].join( '\n' );
				}
			} catch {
				if ( out ) out.textContent = 'self-test request failed (admins only)';
			}
		} );
	}

	// ── Audio: unlock + ambience start ────────────────────────────────────────
	// Primed path (Play clicked on /how-to-play/) tries immediately; the first
	// tap on /game/ is the fallback gesture.
	function initSoundUnlock(): void {
		let primed = false;
		try {
			primed = sessionStorage.getItem( 'pf_ambience_primed' ) === '1';
			if ( primed ) sessionStorage.removeItem( 'pf_ambience_primed' );
		} catch { /* sessionStorage unavailable */ }

		if ( primed ) {
			void sounds.unlock()
				.then( startAmbienceOnce )
				.catch( () => { ambienceKickedOff = false; } );
		}

		const removeHandler = (): void => {
			document.removeEventListener( 'pointerdown', handler );
			document.removeEventListener( 'touchstart',  handler );
		};
		const handler = (): void => {
			void sounds.unlock().then( () => {
				startAmbienceOnce();
				if ( ambienceKickedOff || sounds.isRunning() ) removeHandler();
			} );
		};
		document.addEventListener( 'pointerdown', handler );
		document.addEventListener( 'touchstart',  handler );
	}
}
