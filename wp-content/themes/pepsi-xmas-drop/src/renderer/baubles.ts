import * as THREE from 'three';

import type { DecorType, ModelType, PieceType } from '../game-engine/config.js';
import { BAUBLE_OUTLINES } from './bauble-outlines.js';

// ── Bauble layout ─────────────────────────────────────────────────────────────
// The tree is built from nine uneven, overlapping baubles plus four light
// strands, so each one is placed individually, in key-visual px:
//
//   cy     centre y of the piece's image
//   layer  paint order, 0 = furthest back
//   scale  optional size multiplier for this one sprite (default 1)
//
// The source images are crops from one master composition, so each sprite is
// its source width × REF.srcScale (which sizes the whole tree together) ×
// its own `scale`.
//
// A drop piece's centre x is NOT here: it is the server's anchor_x (see
// pf_drop_pieces() in includes/lib/drop-engine.php), measured in the same
// units. Everything in this file is cosmetic.

export const REF = {
	centreX:  455,     // key-visual x at world x = 0 (= PF_DROP_REF_CENTRE_X)
	baseY:    940,     // key-visual y that sits on the tree's base line
	unit:     0.0115,  // world units per key-visual px (= PF_DROP_REF_UNIT × X_SCALE)
	srcScale: 0.2,     // key-visual px per source-image px
};

/** World y of the tree's base line (the bottom edge of the dashed triangle). */
export const TREE_BASE_Y = -6.7;

/** Depth per layer — later layers sit in front. */
export const LAYER_Z = 0.1;
/** Depth of a piece riding the track / falling — in front of the whole tree. */
export const FRONT_Z = 2;

export interface Placement {
	cy:     number;
	layer:  number;
	scale?: number;   // size multiplier on top of REF.srcScale (default 1)
}

export interface DecorPlacement extends Placement {
	x:     number;   // decor isn't dropped, so its x lives here, not on the server
	level: number;   // appears once this level is complete (0 = from the start)
}

export const BAUBLES: Record<PieceType, Placement> = {
	cracker:   { cy: 786, layer: 12 },
	present:   { cy: 787, layer: 10 },
	bauble:    { cy: 800, layer: 11 },
	mouth:     { cy: 557, layer: 4 },
	heart:     { cy: 622, layer: 6 },
	snowman:   { cy: 556, layer: 7 },
	snowglobe: { cy: 354, layer: 2 },
	gift:      { cy: 402, layer: 5 },
	star:      { cy: 135, layer: 0 },
};

export const DECOR: Record<DecorType, DecorPlacement> = {
	lights1:   { x: 139, cy: 727, layer: 8, level: 2, scale: 1.16 },
	lights2:   { x: 768, cy: 803, layer: 9, level: 3 },
	lights3:   { x: 526, cy: 327, layer: 1, level: 1 },
	lights4:   { x: 306, cy: 491, layer: 3, level: 1 },
};

export const PIECE_TYPES = Object.keys( BAUBLES ) as PieceType[];
export const DECOR_TYPES = Object.keys( DECOR ) as DecorType[];

const def = ( type: PieceType ) => BAUBLES[ type ] ?? BAUBLES.bauble;

/** A sprite's own size multiplier (`scale` in BAUBLES / DECOR, default 1). */
export const itemScale = ( type: ModelType ): number =>
	( BAUBLES as Partial<Record<ModelType, Placement>> )[ type ]?.scale
	?? ( DECOR as Partial<Record<ModelType, Placement>> )[ type ]?.scale
	?? 1;

// Key-visual px ↔ world units.
export const refToWorldX = ( x: number ): number => ( x - REF.centreX ) * REF.unit;
export const refToWorldY = ( cy: number ): number => TREE_BASE_Y + ( REF.baseY - cy ) * REF.unit;
export const worldToRefX = ( wx: number ): number => wx / REF.unit + REF.centreX;
export const worldToRefY = ( wy: number ): number => REF.baseY - ( wy - TREE_BASE_Y ) / REF.unit;

/** World size of a piece / light strand at full (seated) scale. */
export function baubleSize( type: ModelType ): { w: number; h: number } {
	const w = ( BAUBLE_OUTLINES[ type ]?.width ?? 1600 ) * REF.srcScale * itemScale( type ) * REF.unit;
	return { w, h: w * ( BAUBLE_OUTLINES[ type ]?.aspect ?? 1 ) };
}

/** World y of a seated piece's centre. */
export const baubleY = ( type: PieceType ): number => refToWorldY( def( type ).cy );

/** World z of a seated piece — later layers sit in front. */
export const baubleZ = ( type: PieceType ): number => def( type ).layer * LAYER_Z;

/** The piece's silhouette in world units, centred on its origin. */
export function baubleOutline( type: ModelType ): THREE.Vector2[] {
	const { w }  = baubleSize( type );
	const pts    = BAUBLE_OUTLINES[ type ]?.points ?? [];
	const out: THREE.Vector2[] = [];
	for ( let i = 0; i + 1 < pts.length; i += 2 ) {
		out.push( new THREE.Vector2( pts[ i ] * w, pts[ i + 1 ] * w ) );
	}
	return out;
}

/** Largest width / height of any drop piece, for sizing the track. */
export const BAUBLE_MAX_SIZE = Math.max(
	...PIECE_TYPES.map( ( t ) => Math.max( baubleSize( t ).w, baubleSize( t ).h ) ),
);
