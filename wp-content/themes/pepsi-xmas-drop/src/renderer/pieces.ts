import * as THREE from 'three';

import type { ModelType } from '../game-engine/config.js';
import { BAUBLE_MAX_SIZE, baubleOutline, baubleSize } from './baubles.js';

// ── Piece sprites ─────────────────────────────────────────────────────────────
// Each piece / light strand is a flat plane textured with its image
// (src/images/baubles/web/<type>.webp, built by scripts/build-baubles.py) at
// the size it has in the finished tree — see baubles.ts.

/**
 * Pieces ride the track at this fraction of their seated size and grow to
 * full size as they fall: the largest baubles are too big to slide across the
 * sky above the tree without running off the stage.
 */
export const TRACK_SCALE = 0.6;

/** Largest piece dimension on the track (world units). */
export const PIECE_SIZE = BAUBLE_MAX_SIZE * TRACK_SCALE;

const textures: Partial<Record<ModelType, THREE.Texture>> = {};
/** Sprites still drawn as a silhouette, upgraded if their image arrives late. */
const waiting = new Map<ModelType, Set<THREE.Group>>();

function spriteMesh( type: ModelType, tex: THREE.Texture ): THREE.Mesh {
	const { w, h } = baubleSize( type );
	return new THREE.Mesh(
		new THREE.PlaneGeometry( w, h ),
		// toneMapped off: the artwork is final colour, not a lit surface.
		new THREE.MeshBasicMaterial( { map: tex, transparent: true, depthWrite: false, toneMapped: false, side: THREE.DoubleSide } ),
	);
}

/**
 * Preload the sprites (config.piece_images, keyed by type). A type whose image
 * fails is drawn as a plain white silhouette so the game still plays; one
 * still loading after `timeoutMs` starts as a silhouette too, and is swapped in
 * wherever it's drawn once it arrives.
 */
export async function loadPieceTextures( urls: Partial<Record<ModelType, string>>, timeoutMs = 6000 ): Promise<void> {
	const loader = new THREE.TextureLoader();
	const jobs   = ( Object.entries( urls ) as [ ModelType, string ][] )
		.filter( ( [ , url ] ) => typeof url === 'string' && url !== '' )
		.map( async ( [ type, url ] ) => {
			try {
				const tex = await loader.loadAsync( url );
				tex.colorSpace   = THREE.SRGBColorSpace;
				textures[ type ] = tex;
				for ( const g of waiting.get( type ) ?? [] ) {
					g.clear();
					g.add( spriteMesh( type, tex ) );
				}
				waiting.delete( type );
			} catch ( err ) {
				console.warn( `[pieces] ${ type } image failed — using silhouette fallback`, err );
			}
		} );
	await Promise.race( [
		Promise.all( jobs ),
		new Promise( ( r ) => setTimeout( r, timeoutMs ) ),
	] );
}

/** Build a fresh sprite for a piece / light strand, centred on its origin at seated size. */
export function createPiece( type: ModelType ): THREE.Group {
	const g   = new THREE.Group();
	const tex = textures[ type ];
	if ( tex ) {
		g.add( spriteMesh( type, tex ) );
	} else {
		g.add( new THREE.Mesh(
			new THREE.ShapeGeometry( new THREE.Shape( baubleOutline( type ) ) ),
			new THREE.MeshBasicMaterial( { color: 0xffffff, transparent: true, depthWrite: false, side: THREE.DoubleSide } ),
		) );
		if ( ! waiting.has( type ) ) waiting.set( type, new Set() );
		waiting.get( type )!.add( g );
	}
	return g;
}
