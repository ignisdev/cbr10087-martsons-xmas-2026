import gsap from 'gsap';
import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

import type { PieceDef } from '../game-engine/config.js';
import { createPiece, PIECE_SIZE } from './pieces.js';
import { baubleOutline, baubleY, DECOR, DECOR_TYPES, LAYER_Z, refToWorldX, refToWorldY, TREE_BASE_Y } from './baubles.js';
import { BG, BG_TRI_RATIO } from './fit.js';

// ── Tree layout (world units) ─────────────────────────────────────────────────
// Normalised x ∈ [-1, 1] maps to world x ∈ [-X_SCALE, X_SCALE]. Each piece's
// height, size and layer come from baubles.ts. LANE_Y (the track) is set at runtime
// by main.ts so the track sits in the sky between the header and the tree's apex.

export const LAYOUT = {
	X_SCALE:  5,
	LANE_Y:   7.6,
	FLOOR_Y: -24,   // where fallen pieces are removed (off-screen)
};

export const toWorldX = ( nx: number ): number => nx * LAYOUT.X_SCALE;

// Tree outline + slot geometry. The dashed triangle keeps the artwork's
// proportions (BG_TRI_RATIO) so fit.ts can line it up with game_bg.png. Each
// slot is the same dashed stroke round the bauble's own silhouette.
const BASE_HALF_W = 5.6;
const BASE_Y      = TREE_BASE_Y;

const TRIANGLE = {
	baseHalfW: BASE_HALF_W,
	baseY:     BASE_Y,
	apexY:     BASE_Y + 2 * BASE_HALF_W * BG_TRI_RATIO,
};

const OUTLINE = {
	colour:    0x0031ff,
	thickness: 0.035,
	dash:      0.24,
	gap:       0.16,
};

const SLOT = {
	fillOpacity: 0.25,   // 25% frosted-white fill, steady (no pulse)
};

/** Lowest point of the tree (triangle base) — the drop button sits below it. */
export const TREE_BOTTOM_Y = TRIANGLE.baseY;

/** World-space triangle, for fitting the camera to the background. */
export const TREE_TRIANGLE = { halfW: TRIANGLE.baseHalfW, baseY: TRIANGLE.baseY, apexY: TRIANGLE.apexY };

export interface Tree {
	group:     THREE.Group;
	/** Show the slot the current piece is aiming for (null hides it). */
	setTarget: ( piece: number | null ) => void;
	/** The piece has seated — hide its slot. */
	fillSlot:  ( piece: number ) => void;
	/** Pop in every light strand whose level is ≤ `level`. */
	showDecor: ( level: number ) => void;
	/** Back to an empty tree (after the practice go): no target, strands hidden. */
	reset:     () => void;
	/** Per-frame hook (no animation at present). */
	update:    ( t: number ) => void;
}

/**
 * Dashed outline of a closed polygon, built from thin quads so the stroke has
 * real thickness (WebGL lines are always 1 px). The dash pattern runs
 * continuously round the corners.
 */
function dashedOutline( points: THREE.Vector2[], material: THREE.Material ): THREE.Mesh {
	const { thickness, dash, gap } = OUTLINE;
	const pieces: THREE.BufferGeometry[] = [];
	let phase = 0;   // distance into the current dash+gap cycle

	for ( let i = 0; i < points.length; i++ ) {
		const a   = points[ i ];
		const b   = points[ ( i + 1 ) % points.length ];
		const len = a.distanceTo( b );
		const dir = b.clone().sub( a ).normalize();
		const ang = Math.atan2( dir.y, dir.x );
		let s = 0;
		while ( s < len ) {
			const inDash = phase < dash;
			const step   = Math.min( ( inDash ? dash : dash + gap ) - phase, len - s );
			if ( inDash && step > 0.001 ) {
				const g   = new THREE.PlaneGeometry( step, thickness );
				const mid = a.clone().add( dir.clone().multiplyScalar( s + step / 2 ) );
				g.rotateZ( ang );
				g.translate( mid.x, mid.y, 0 );
				pieces.push( g );
			}
			s     += step;
			phase  = ( phase + step ) % ( dash + gap );
		}
	}

	const merged = mergeGeometries( pieces );
	pieces.forEach( ( g ) => g.dispose() );
	return new THREE.Mesh( merged ?? new THREE.BufferGeometry(), material );
}

/**
 * The Pepsi Xmas Tree: a dashed #0031ff triangle (no fill), one placeholder
 * per anchor slot — the same dashed stroke round the bauble's own silhouette,
 * over a frosted-white fill of that shape — shown only while it's the
 * current piece's target, and the light strands, hidden until their level is
 * complete.
 */
export function createTree( scene: THREE.Scene, pieces: PieceDef[] ): Tree {
	const group = new THREE.Group();
	scene.add( group );

	// Triangle + slot placeholders sit behind every seated piece.
	const back = new THREE.Group();
	back.position.z = -2;
	group.add( back );

	const stroke = new THREE.MeshBasicMaterial( { color: OUTLINE.colour } );
	const { baseHalfW: hw, baseY, apexY } = TRIANGLE;
	back.add( dashedOutline( [
		new THREE.Vector2( -hw, baseY ),
		new THREE.Vector2( 0, apexY ),
		new THREE.Vector2( hw, baseY ),
	], stroke ) );
	const fill   = new THREE.MeshBasicMaterial( { color: 0xffffff, transparent: true, opacity: SLOT.fillOpacity, depthWrite: false } );
	const slots  = new Map<number, THREE.Group>();
	for ( const p of pieces ) {
		const outline = baubleOutline( p.type );
		const slot    = new THREE.Group();
		slot.add( new THREE.Mesh( new THREE.ShapeGeometry( new THREE.Shape( outline ) ), fill ) );
		const ring = dashedOutline( outline, stroke );
		ring.position.z = 0.01;
		slot.add( ring );
		slot.position.set( toWorldX( p.anchor_x ), baubleY( p.type ), 0.05 );
		slot.visible = false;
		back.add( slot );
		slots.set( p.index, slot );
	}

	// Light strands: decoration only, popped in as levels are completed.
	const decor = DECOR_TYPES.map( ( type ) => {
		const d    = DECOR[ type ];
		const mesh = createPiece( type );
		mesh.position.set( refToWorldX( d.x ), refToWorldY( d.cy ), d.layer * LAYER_Z );
		mesh.visible = d.level <= 0;
		group.add( mesh );
		return { mesh, level: d.level };
	} );

	let target: number | null = null;

	const setTarget = ( piece: number | null ): void => {
		if ( target !== null ) {
			const prev = slots.get( target );
			if ( prev ) prev.visible = false;
		}
		target = piece;
		const next = piece !== null ? slots.get( piece ) : undefined;
		if ( next ) next.visible = true;
	};

	return {
		group,
		setTarget,
		showDecor( level ) {
			for ( const d of decor ) {
				if ( d.mesh.visible || d.level > level ) continue;
				d.mesh.visible = true;
				gsap.fromTo( d.mesh.scale, { x: 0.01, y: 0.01, z: 0.01 }, { x: 1, y: 1, z: 1, duration: 0.45, ease: 'back.out(2)' } );
			}
		},
		fillSlot( piece ) {
			if ( target === piece ) setTarget( null );
		},
		reset() {
			setTarget( null );
			for ( const d of decor ) {
				if ( d.level <= 0 ) continue;
				gsap.killTweensOf( d.mesh.scale );
				d.mesh.visible = false;
			}
		},
		update() {
			// The target slot's fill is steady — nothing to animate.
		},
	};
}

// Direction arrows either end of the track: solid white triangles (◀ left,
// ▶ right, matching the dashed track art) showing the piece slides both ways.
// The track is cropped short of the stage edges so each arrow sits clear.
const TRACK_ARROW = {
	w:      0.3,    // triangle depth (world units)
	h:      0.36,   // triangle height
	gap:    0.14,   // clear space between the track end and the arrow
	margin: 0.1,    // keep clear of the stage edge
};

/** A solid right-pointing triangle "▶" centred on its origin. */
function arrowGeometry(): THREE.ShapeGeometry {
	const { w, h } = TRACK_ARROW;
	const s = new THREE.Shape();
	s.moveTo( -w / 2, h / 2 );
	s.lineTo( w / 2, 0 );
	s.lineTo( -w / 2, -h / 2 );
	s.closePath();
	return new THREE.ShapeGeometry( s );
}

/**
 * The track the pieces slide along: `bauble_track.png` on a flat strip centred
 * on LANE_Y, with a ◀ / ▶ arrow at each end. The full slider travel plus half
 * a piece is wider than the stage, so the strip is cropped to leave room for
 * the arrows; it still spans every piece centre (±xMax), and pieces are
 * centred on their image (see pieces.ts), so the line runs through the middle
 * of every piece.
 */
export function createTrack( scene: THREE.Scene, imageUrl: string, xMax: number ): THREE.Object3D | null {
	if ( ! imageUrl ) return null;
	const fullWidth = 2 * toWorldX( xMax ) + PIECE_SIZE;
	const height    = fullWidth * ( 16 / 758 );   // bauble_track.png aspect, at full length

	// Stage half-width in world units: the triangle's, scaled out by the
	// artwork's side insets. Arrows sit inside it; the track stops short of them.
	const stageHalfW = TRIANGLE.baseHalfW * BG.width / ( BG.width - 2 * BG.inset );
	const arrowX     = stageHalfW - TRACK_ARROW.margin - TRACK_ARROW.w / 2;
	const width      = Math.min( fullWidth, 2 * ( arrowX - TRACK_ARROW.w / 2 - TRACK_ARROW.gap ) );

	const mat    = new THREE.MeshBasicMaterial( { transparent: true, depthWrite: false } );
	const strip  = new THREE.Mesh( new THREE.PlaneGeometry( width, height ), mat );
	strip.position.set( 0, LAYOUT.LANE_Y, -1 );
	strip.visible = false;
	scene.add( strip );

	// Children of the strip so they follow LANE_Y and only show once the
	// track art has loaded.
	const arrowMat = new THREE.MeshBasicMaterial( { color: 0xffffff, transparent: true, opacity: 0.9, depthWrite: false } );
	const arrowGeo = arrowGeometry();
	for ( const side of [ -1, 1 ] ) {
		const arrow = new THREE.Mesh( arrowGeo, arrowMat );
		arrow.position.set( side * arrowX, 0, 0.01 );
		if ( side < 0 ) arrow.rotation.z = Math.PI;   // ◀ on the left
		strip.add( arrow );
	}

	new THREE.TextureLoader().load( imageUrl, ( tex ) => {
		tex.colorSpace = THREE.SRGBColorSpace;
		// Crop (not squash) the art to the shorter strip so the dash spacing
		// is unchanged: show the centre width/fullWidth of the image.
		const ratio    = width / fullWidth;
		tex.repeat.set( ratio, 1 );
		tex.offset.set( ( 1 - ratio ) / 2, 0 );
		mat.map        = tex;
		mat.needsUpdate = true;
		strip.visible  = true;
	} );
	return strip;
}
