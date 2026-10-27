import * as THREE from 'three';

// Three.js r155+ default — set explicitly so Safari iOS (which doesn't
// auto-manage GLB colour the way Chromium does) renders textures the same
// as Chrome / Firefox. Without this, pieces look slightly darker /
// over-saturated on iOS.
THREE.ColorManagement.enabled = true;

export interface SceneObjects {
	scene:    THREE.Scene;
	camera:   THREE.OrthographicCamera;
	renderer: THREE.WebGLRenderer;
}

/**
 * Creates the core Three.js scene, WebGL renderer, and an orthographic camera.
 * Framing is delegated to `frame` (called on create and on every resize), so
 * the caller can pin the tree to the background artwork — see fit.ts.
 */
export function createScene(
	canvas: HTMLCanvasElement,
	frame:  ( camera: THREE.OrthographicCamera ) => void,
): SceneObjects {
	// ── Renderer ───────────────────────────────────────────────────────────────
	const renderer = new THREE.WebGLRenderer( {
		canvas,
		antialias:             true,
		alpha:                 true,   // transparent — the CSS game_bg.png shows through
		powerPreference:       'high-performance',
		preserveDrawingBuffer: false,  // explicit — iOS sometimes flips this when omitted
	} );

	// Cap at 1.5× DPR (was 2×). iPhone Pro / iPad Pro report DPR=3, which means
	// the GPU rasterises 9× the pixels for no visible quality gain on a game
	// scene; capping evens out the per-frame budget across devices and shaves
	// ~30% of fragment-shader work on high-DPR phones.
	renderer.setPixelRatio( Math.min( window.devicePixelRatio, 1.5 ) );
	// Explicit sRGB output — Safari iOS doesn't auto-apply colour management
	// the way Chrome / Firefox do; without this, GLB textures look slightly
	// darker / saturated on Safari vs every other browser.
	renderer.outputColorSpace = THREE.SRGBColorSpace;
	// Pass `false` so setSize does NOT write inline px styles on the canvas —
	// CSS controls the display size. This also avoids the canvas being locked at
	// 0×0 if clientWidth/clientHeight haven't resolved yet when this script runs
	// (common when WordPress enqueues the bundle in the footer).
	const initW = canvas.clientWidth  || canvas.parentElement?.clientWidth  || window.innerWidth;
	const initH = canvas.clientHeight || canvas.parentElement?.clientHeight || window.innerHeight;
	renderer.setSize( initW, initH, false );
	renderer.shadowMap.enabled = false; // no shadows — keeps mobile GPU free
	renderer.toneMapping       = THREE.ACESFilmicToneMapping;

	// ── Scene ──────────────────────────────────────────────────────────────────
	const scene = new THREE.Scene();
	// No scene background: the canvas is transparent over the page's
	// game_bg.png (set in page-game.php / _game.scss).
	renderer.setClearColor( 0x000000, 0 );

	// ── Camera ─────────────────────────────────────────────────────────────────
	// Looks straight down −z; `frame` only moves it in y and sets the frustum.
	const camera = new THREE.OrthographicCamera( -1, 1, 1, -1, 0.1, 100 );
	camera.position.set( 0, 0, 30 );
	camera.lookAt( 0, 0, 0 );
	frame( camera );

	// ── Lighting ───────────────────────────────────────────────────────────────
	// Ambient only — no shadow maps, safe for all mobile GPUs
	const ambient = new THREE.AmbientLight( 0xffffff, 0.9 );
	scene.add( ambient );

	// Key light from upper-front-left gives the pieces their 3D shading
	const dirLight = new THREE.DirectionalLight( 0xffffff, 1.6 );
	dirLight.position.set( -6, 10, 14 );
	dirLight.castShadow = false;
	scene.add( dirLight );

	// ── Resize ─────────────────────────────────────────────────────────────────
	function syncSize(): void {
		const w = canvas.clientWidth  || window.innerWidth;
		const h = canvas.clientHeight || window.innerHeight;
		frame( camera );
		renderer.setSize( w, h, false );
	}

	window.addEventListener( 'resize', syncSize );

	// Guard: if initH came from the window.innerHeight fallback (canvas not yet
	// measured), re-sync once the browser has painted and CSS vars are resolved.
	if ( initH === window.innerHeight && canvas.clientHeight !== window.innerHeight ) {
		requestAnimationFrame( syncSize );
	}

	// ── WebGL context-loss recovery ───────────────────────────────────────────
	// iOS Safari aggressively reclaims WebGL contexts when memory pressure hits
	// (e.g. when the Photo Library / camera sheet opens, or when the tab is
	// backgrounded for a while). Without preventDefault on `webglcontextlost`
	// the canvas stays black permanently. On `webglcontextrestored`, the most
	// reliable recovery is a page reload — the server-side flow-guard,
	// tree_started pin, and session state all survive the reload and the player
	// lands wherever they should (age-gate, /game/, or /non-winner/ depending
	// on where they were).
	canvas.addEventListener( 'webglcontextlost', ( e ) => {
		e.preventDefault();
	}, false );
	canvas.addEventListener( 'webglcontextrestored', () => {
		location.reload();
	}, false );

	return { scene, camera, renderer };
}
