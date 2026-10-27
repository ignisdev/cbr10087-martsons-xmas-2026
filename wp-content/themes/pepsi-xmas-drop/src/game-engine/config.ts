// Game config shapes. The authoritative values (piece order, anchors, speeds,
// tolerances) live server-side in includes/lib/drop-engine.php and arrive in
// the /session/start response — the client never hard-codes them.

export type PieceType = 'cracker' | 'present' | 'bauble' | 'mouth' | 'heart' | 'snowman' | 'snowglobe' | 'gift' | 'star';

/** Decorative light strands — drawn in the tree, never dropped, never sent to the server. */
export type DecorType = 'lights1' | 'lights2' | 'lights3' | 'lights4';

/** Anything with a sprite: drop pieces + light strands. */
export type ModelType = PieceType | DecorType;

export interface PieceDef {
	index:    number;     // 1-based drop order
	type:     PieceType;
	row:      number;     // 0 = bottom row … 3 = star
	level:    number;     // 1–4
	anchor_x: number;     // normalised [-1, 1]
}

export interface LevelDef {
	level:     number;
	speed:     number;    // normalised units / second of slider travel
	tolerance: number;    // max normalised landing offset that still seats
}

export interface DropGameConfig {
	x_max:  number;
	levels: LevelDef[];
	pieces: PieceDef[];
}

export type FailStyle = 'tip' | 'roll' | 'bounce';

export interface DropResponse {
	piece:    number;
	result:   'landed' | 'fell';
	land_x:   number;
	anchor_x: number;
	fail:     { style: FailStyle; side: 1 | -1 } | null;
	placed:   number;
	run_over: boolean;
	label:    string;
	error?:   string;
}

export function isDropGameConfig( v: unknown ): v is DropGameConfig {
	const c = v as DropGameConfig | null;
	return !! c
		&& typeof c.x_max === 'number'
		&& Array.isArray( c.levels ) && c.levels.length > 0
		&& Array.isArray( c.pieces ) && c.pieces.length > 0;
}

/**
 * Slider position at `elapsed` seconds after a piece spawns: a triangle wave
 * bouncing between ±xMax at `speed` units/s. Odd pieces start on the left
 * heading right, even pieces start on the right heading left — fixed per
 * piece, so only the player's timing changes between games.
 */
export function sliderX( elapsed: number, speed: number, xMax: number, pieceIndex: number ): number {
	const span   = 2 * xMax;
	const travel = ( elapsed * speed ) % ( 2 * span );
	const pos    = travel <= span ? travel : 2 * span - travel;   // 0 → span → 0
	const x      = -xMax + pos;
	return pieceIndex % 2 === 1 ? x : -x;
}
