import * as THREE from 'three';

// ── Fit the 3D tree to the background artwork ────────────────────────────────
// game_bg.png (829 × 1793 px) has a spot the tree must occupy exactly:
//
//   apex   632 px from the top
//   base   432 px from the bottom
//   base corners 28 px in from the left / right edges
//
// The stage element (_game.scss) has the artwork's exact aspect, and the
// backdrop lines game_bg's pixels (inside wide_bg.png) up with it exactly. fitCameraToBackground() maps the triangle to canvas pixels
// and sets the orthographic camera so the world-space triangle lands on it.
// (The cover maths below reduces to a plain scale when the box has the
// artwork's aspect, and still holds if a non-matching box is passed.)

export const BG = {
	width:          829,
	height:         1793,
	apexFromTop:    632,
	baseFromBottom: 432,
	inset:          28,
};

/** Triangle height ÷ base width in the artwork (729 / 773). */
export const BG_TRI_RATIO =
	( BG.height - BG.apexFromTop - BG.baseFromBottom ) / ( BG.width - 2 * BG.inset );

export interface TriangleWorld {
	halfW: number;   // world half-width of the triangle base
	baseY: number;   // world y of the base
}

export interface FitResult {
	/** Pixels per world unit. */
	scale:  number;
	/** Canvas-pixel y of the triangle apex (may be ≤ 0 on very short screens). */
	apexPx: number;
}

export function fitCameraToBackground(
	camera: THREE.OrthographicCamera,
	canvas: HTMLCanvasElement,
	page:   HTMLElement,   // the element that paints game_bg.png
	tri:    TriangleWorld,
): FitResult {
	const pw = page.clientWidth  || window.innerWidth;
	const ph = page.clientHeight || window.innerHeight;
	const cw = canvas.clientWidth  || pw;
	const ch = canvas.clientHeight || ph;

	// background-size: cover; background-position: center bottom
	const s       = Math.max( pw / BG.width, ph / BG.height );
	const imgTop  = ph - BG.height * s;

	const canvasTop = canvas.getBoundingClientRect().top - page.getBoundingClientRect().top;
	const apexPx    = imgTop + BG.apexFromTop * s - canvasTop;
	const basePx    = imgTop + ( BG.height - BG.baseFromBottom ) * s - canvasTop;
	const triWidth  = ( BG.width - 2 * BG.inset ) * s;

	const k     = triWidth / ( 2 * tri.halfW );   // px per world unit
	const halfW = cw / 2 / k;
	const halfH = ch / 2 / k;
	// Screen y of world Y is (cy + halfH − Y) · k; solve so the base lands on basePx.
	const cy    = tri.baseY + basePx / k - halfH;

	camera.left   = -halfW;
	camera.right  =  halfW;
	camera.top    =  halfH;
	camera.bottom = -halfH;
	camera.position.set( 0, cy, 30 );
	camera.updateProjectionMatrix();
	// Refresh matrixWorldInverse now, not on the next render — callers project
	// world points (e.g. the drop-button position) straight after a fit.
	camera.updateMatrixWorld();

	return { scale: k, apexPx };
}

/** World y at a canvas-pixel y, for the camera as last fitted. */
export function worldYAtPx( camera: THREE.OrthographicCamera, px: number, scale: number ): number {
	return camera.position.y + camera.top - px / scale;
}
