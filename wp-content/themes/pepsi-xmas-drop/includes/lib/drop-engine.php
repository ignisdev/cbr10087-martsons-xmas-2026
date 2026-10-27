<?php
/**
 * The Xmas Drop — pure game rules + win-gating decision.
 *
 * No WordPress, no DB, no session: everything here is deterministic apart from
 * the cosmetic fail-style pick, so the self-test harness can drive it directly
 * (and it can be exercised from the PHP CLI without booting WP).
 *
 * Model
 * ─────
 * The tree is 9 fixed anchor slots stacked 3-3-2-1, one per bauble in the key
 * visual. The baubles are uneven in size
 * and overlap, so every anchor is placed individually — there is no grid.
 * The four light strands are decoration only (client-side, never dropped).
 * Pieces are released in the SAME order every game from a slider that
 * ping-pongs across the top of the play area; the player taps to drop and the
 * piece falls straight down onto its anchor. Horizontal positions are
 * normalised to [-1, 1] across the play width.
 *
 *   landing offset = |drop_x − anchor_x|
 *   offset ≤ tolerance → piece seats (snaps onto the anchor)
 *   offset > tolerance → piece overlaps / tips and falls off → run over
 *
 * Levels (four, per the brief) are one layer each:
 *   L1 = cracker, present, bauble   L2 = mouth, heart, snowman
 *   L3 = snow globe, gift            L4 = star
 * Each level is faster with a tighter landing tolerance.
 *
 * Win-gating (CORE — see CLAUDE.md)
 * ─────────────────────────────────
 * The star (piece 9) is the "killer piece" and the ONLY drop that can win.
 * pf_decide_drop_outcome() is the single decision path.
 *
 *   WIN-ASSIST — slot reserved: the star's tolerance is widened by
 *                PF_KILLER_ASSIST_MULT, so a near-perfect drop seats and wins.
 *   WIN-BLOCK  — no slot: the star ALWAYS falls, however well it was dropped.
 *                Anti-tell: it lands exactly where it was dropped (never shifted
 *                sideways), and the fall animation comes from the same pool and
 *                the same side-selection rule as an honest miss.
 */

// ── Tunables ─────────────────────────────────────────────────────────────────

const PF_DROP_PIECE_COUNT     = 9;
const PF_DROP_KILLER_PIECE    = 9;     // the star
const PF_DROP_X_MAX           = 0.95;  // slider travel limit (normalised)
// Anchors are measured as x pixels in key-visual space (the units of
// src/renderer/baubles.ts), then normalised: ( ref_x − centre ) × unit. Must match REF in
// src/renderer/baubles.ts.
const PF_DROP_REF_CENTRE_X    = 455;
const PF_DROP_REF_UNIT        = 0.0023;
const PF_KILLER_ASSIST_MULT   = 2.5;   // star tolerance multiplier while a slot is reserved
const PF_DROP_MIN_INTERVAL_MS = 350;   // faster than any real fall animation → scripted

/** Fail animations. Honest misses and WIN-BLOCK draw from this SAME pool. */
const PF_DROP_FAIL_STYLES = [ 'tip', 'roll', 'bounce' ];

/**
 * Level config. Speeds are normalised units per second of slider travel;
 * tolerance is the max landing offset (normalised) that still seats.
 */
function pf_drop_levels(): array {
	$levels = [
		1 => [ 'rows' => [ 0 ], 'speed' => 0.90, 'tolerance' => 0.170 ],
		2 => [ 'rows' => [ 1 ], 'speed' => 1.30, 'tolerance' => 0.090 ],
		3 => [ 'rows' => [ 2 ], 'speed' => 1.80, 'tolerance' => 0.065 ],
		4 => [ 'rows' => [ 3 ], 'speed' => 2.40, 'tolerance' => 0.045 ],
	];
	return function_exists( 'apply_filters' ) ? (array) apply_filters( 'pf_drop_levels', $levels ) : $levels;
}

/**
 * The fixed 9-piece sequence, bottom row first, left to right. Each type maps
 * to a sprite in src/images/baubles/web/; the order never changes between
 * games. The value is the piece's centre x on the key visual (see
 * PF_DROP_REF_CENTRE_X) — its vertical position, size and layer are
 * cosmetic and live client-side in src/renderer/baubles.ts.
 */
function pf_drop_pieces(): array {
	static $pieces = null;
	if ( $pieces !== null ) {
		return $pieces;
	}
	$rows = [
		0 => [ 'cracker' => 274, 'present' => 497, 'bauble' => 692 ],
		1 => [ 'mouth' => 290, 'heart' => 459, 'snowman' => 654 ],
		2 => [ 'snowglobe' => 392, 'gift' => 549 ],
		3 => [ 'star' => 468 ],
	];
	$row_level = [];
	foreach ( pf_drop_levels() as $level => $cfg ) {
		foreach ( $cfg['rows'] as $r ) {
			$row_level[ $r ] = (int) $level;
		}
	}
	$pieces = [];
	$index  = 1;
	foreach ( $rows as $row => $types ) {
		foreach ( $types as $type => $ref_x ) {
			$pieces[ $index ] = [
				'index'    => $index,
				'type'     => $type,
				'row'      => $row,
				'level'    => $row_level[ $row ] ?? 4,
				'anchor_x' => round( ( $ref_x - PF_DROP_REF_CENTRE_X ) * PF_DROP_REF_UNIT, 4 ),
			];
			$index++;
		}
	}
	return $pieces;
}

/** Client-safe game config, sent at /session/start. */
function pf_drop_public_config(): array {
	$levels = [];
	foreach ( pf_drop_levels() as $n => $cfg ) {
		$levels[] = [
			'level'     => (int) $n,
			'speed'     => (float) $cfg['speed'],
			'tolerance' => (float) $cfg['tolerance'],
		];
	}
	return [
		'x_max'  => PF_DROP_X_MAX,
		'levels' => $levels,
		'pieces' => array_values( pf_drop_pieces() ),
	];
}

/**
 * Sequence guard for a drop request. Returns '' when the drop is allowed,
 * otherwise an error code. Pure — the endpoint feeds it session state.
 *
 * @param int  $placed     pieces already seated this run
 * @param bool $run_over   the run already ended (fail or star resolved)
 * @param int  $piece      requested piece index
 * @param int  $since_ms   ms since the previous drop (PHP_INT_MAX for the first)
 */
function pf_validate_drop_sequence( int $placed, bool $run_over, int $piece, int $since_ms ): string {
	if ( $run_over ) {
		return 'run_over';
	}
	if ( $piece < 1 || $piece > PF_DROP_PIECE_COUNT ) {
		return 'invalid_piece';
	}
	if ( $piece !== $placed + 1 ) {
		return 'out_of_order';
	}
	if ( $since_ms < PF_DROP_MIN_INTERVAL_MS ) {
		return 'too_fast';
	}
	return '';
}

/**
 * Pick the fall animation for a piece that doesn't seat. Side follows the
 * sign of the landing offset (it tips toward the side it overhangs); a
 * near-centre landing picks a side at random. Used for honest misses AND the
 * WIN-BLOCK star, so the two are indistinguishable.
 */
function pf_drop_fail_style( float $signed_offset ): array {
	$style = PF_DROP_FAIL_STYLES[ mt_rand( 0, count( PF_DROP_FAIL_STYLES ) - 1 ) ];
	if ( abs( $signed_offset ) < 0.01 ) {
		$side = mt_rand( 0, 1 ) ? 1 : -1;
	} else {
		$side = $signed_offset > 0 ? 1 : -1;
	}
	return [ 'style' => $style, 'side' => $side ];
}

/**
 * THE decision. Pure — no DB. Given a piece, where it was dropped, and
 * whether a prize slot is reserved (only meaningful for the killer piece),
 * returns:
 *
 *   result   'landed' | 'fell'            (what the client animates)
 *   outcome  'placed'   — normal piece seated, run continues
 *            'winner'   — star seated with a slot → WIN
 *            'near_miss'— star would have seated at base tolerance, no slot (WIN-BLOCK)
 *            'miss'     — anything else that fell
 *   land_x   where the piece touches down (always drop_x — never shifted)
 *   fail     [style, side] when result = 'fell'
 */
function pf_decide_drop_outcome( int $piece, float $drop_x, bool $slot_available ): array {
	$pieces = pf_drop_pieces();
	$levels = pf_drop_levels();
	$p      = $pieces[ $piece ] ?? null;
	if ( $p === null ) {
		return [ 'result' => 'fell', 'outcome' => 'miss', 'reason' => 'invalid_piece', 'land_x' => $drop_x, 'offset' => INF, 'fail' => pf_drop_fail_style( 0.0 ) ];
	}

	$drop_x    = max( -PF_DROP_X_MAX, min( PF_DROP_X_MAX, $drop_x ) );
	$signed    = $drop_x - (float) $p['anchor_x'];
	$offset    = abs( $signed );
	$tolerance = (float) $levels[ $p['level'] ]['tolerance'];
	$is_killer = ( $piece === PF_DROP_KILLER_PIECE );

	if ( ! $is_killer ) {
		$seated = $offset <= $tolerance;
		return [
			'result'    => $seated ? 'landed' : 'fell',
			'outcome'   => $seated ? 'placed' : 'miss',
			'reason'    => $seated ? 'within_tolerance' : 'outside_tolerance',
			'land_x'    => $drop_x,
			'offset'    => $offset,
			'tolerance' => $tolerance,
			'fail'      => $seated ? null : pf_drop_fail_style( $signed ),
		];
	}

	// Killer piece (the star).
	if ( $slot_available ) {
		$assist_tol = $tolerance * PF_KILLER_ASSIST_MULT;
		if ( $offset <= $assist_tol ) {
			// Scenario 1 — WIN-ASSIST: slot live, drop within the widened band.
			return [
				'result'    => 'landed',
				'outcome'   => 'winner',
				'reason'    => $offset <= $tolerance ? 'star_clean_slot' : 'star_assist_slot',
				'land_x'    => $drop_x,
				'offset'    => $offset,
				'tolerance' => $assist_tol,
				'fail'      => null,
			];
		}
		return [
			'result'    => 'fell',
			'outcome'   => 'miss',
			'reason'    => 'star_outside_assist',
			'land_x'    => $drop_x,
			'offset'    => $offset,
			'tolerance' => $assist_tol,
			'fail'      => pf_drop_fail_style( $signed ),
		];
	}

	// Scenario 2 — WIN-BLOCK: no slot, the star never seats.
	return [
		'result'    => 'fell',
		'outcome'   => $offset <= $tolerance ? 'near_miss' : 'miss',
		'reason'    => $offset <= $tolerance ? 'star_blocked_no_slot' : 'star_outside_tolerance',
		'land_x'    => $drop_x,
		'offset'    => $offset,
		'tolerance' => $tolerance,
		'fail'      => pf_drop_fail_style( $signed ),
	];
}

// ── Pure self-test ───────────────────────────────────────────────────────────

/**
 * Deterministic assertions for the drop rules + CORE win-gating. Returns
 * [ 'all_pass' => bool, 'cases' => [...] ]. The WP-dependent checks (access
 * control, winner dedupe) are appended by pf_dropflow_self_test() in api.php.
 */
function pf_drop_engine_self_test(): array {
	$pieces = pf_drop_pieces();
	$levels = pf_drop_levels();
	$star   = $pieces[ PF_DROP_KILLER_PIECE ];
	$s_tol  = (float) $levels[ $star['level'] ]['tolerance'];
	$sx     = (float) $star['anchor_x'];
	$p1     = $pieces[1];
	$p1_tol = (float) $levels[ $p1['level'] ]['tolerance'];

	$cases = [];
	$add   = function ( string $name, string $slot, string $expected, string $actual, bool $pass, string $reason = '' ) use ( &$cases ) {
		$cases[] = compact( 'name', 'slot', 'expected', 'actual', 'pass', 'reason' );
	};

	$run = function ( int $piece, float $x, bool $slot ) {
		return pf_decide_drop_outcome( $piece, $x, $slot );
	};

	// Star — the two CORE scenarios.
	$d = $run( PF_DROP_KILLER_PIECE, $sx, true );
	$add( 'Star dead-centre + slot ON → winner', 'on', 'landed/winner', "{$d['result']}/{$d['outcome']}", $d['result'] === 'landed' && $d['outcome'] === 'winner', $d['reason'] );

	$d = $run( PF_DROP_KILLER_PIECE, $sx, false );
	$add( 'Star dead-centre + slot OFF → win blocked (falls)', 'off', 'fell/near_miss', "{$d['result']}/{$d['outcome']}", $d['result'] === 'fell' && $d['outcome'] === 'near_miss', $d['reason'] );

	$assist_x = $sx + $s_tol * ( PF_KILLER_ASSIST_MULT - 0.5 );
	$d = $run( PF_DROP_KILLER_PIECE, $assist_x, true );
	$add( 'Star in assist band + slot ON → winner (assist)', 'on', 'landed/winner', "{$d['result']}/{$d['outcome']}", $d['result'] === 'landed' && $d['outcome'] === 'winner', $d['reason'] );

	$d = $run( PF_DROP_KILLER_PIECE, $assist_x, false );
	$add( 'Star in assist band + slot OFF → falls (miss)', 'off', 'fell/miss', "{$d['result']}/{$d['outcome']}", $d['result'] === 'fell' && $d['outcome'] === 'miss', $d['reason'] );

	$wide_x = $sx + $s_tol * ( PF_KILLER_ASSIST_MULT + 1.0 );
	$d = $run( PF_DROP_KILLER_PIECE, $wide_x, true );
	$add( 'Star wide of assist band + slot ON → never a win', 'on', 'fell/miss', "{$d['result']}/{$d['outcome']}", $d['result'] === 'fell' && $d['outcome'] === 'miss', $d['reason'] );

	// Anti-tell: a blocked star lands exactly where it was dropped, and its
	// fail style comes from the shared pool, same as an honest miss.
	$tell_ok = true;
	for ( $i = 0; $i < 50; $i++ ) {
		$x = $sx + ( mt_rand( -100, 100 ) / 100 ) * $s_tol;
		$b = $run( PF_DROP_KILLER_PIECE, $x, false );
		$h = $run( 1, $p1['anchor_x'] + $p1_tol * 2, false );
		if ( abs( $b['land_x'] - $x ) > 1e-9
			|| ! in_array( $b['fail']['style'], PF_DROP_FAIL_STYLES, true )
			|| ! in_array( $h['fail']['style'], PF_DROP_FAIL_STYLES, true ) ) {
			$tell_ok = false;
		}
	}
	$add( 'Anti-tell: blocked star lands at drop_x, shared fail-style pool', 'off', 'no shift, pooled style', $tell_ok ? 'no shift, pooled style' : 'TELL DETECTED', $tell_ok );

	// Normal pieces are honest and ignore slot state.
	$d = $run( 1, $p1['anchor_x'] + $p1_tol * 0.9, false );
	$add( 'Piece 1 inside tolerance → seats', 'off', 'landed/placed', "{$d['result']}/{$d['outcome']}", $d['result'] === 'landed' && $d['outcome'] === 'placed' );

	$d = $run( 1, $p1['anchor_x'] + $p1_tol * 1.1, true );
	$add( 'Piece 1 outside tolerance + slot ON → falls (slot irrelevant)', 'on', 'fell/miss', "{$d['result']}/{$d['outcome']}", $d['result'] === 'fell' && $d['outcome'] === 'miss' );

	// Sequence guard.
	$seq = [
		[ 0, false, 1, PHP_INT_MAX, '' ],
		[ 3, false, 5, 1000, 'out_of_order' ],
		[ 3, false, 4, 100, 'too_fast' ],
		[ 3, true, 4, 1000, 'run_over' ],
		[ 8, false, PF_DROP_PIECE_COUNT + 1, 1000, 'invalid_piece' ],
	];
	$seq_ok = true;
	foreach ( $seq as $s ) {
		if ( pf_validate_drop_sequence( $s[0], $s[1], $s[2], $s[3] ) !== $s[4] ) {
			$seq_ok = false;
		}
	}
	$add( 'Sequence guard: in-order, min interval, no replay after run end', 'n/a', 'all codes match', $seq_ok ? 'all codes match' : 'mismatch', $seq_ok );

	// Config shape: 9 pieces, 3-3-2-1, star last, anchors on the slider, difficulty ramps.
	$row_counts = array_count_values( array_column( $pieces, 'row' ) );
	ksort( $row_counts );
	$ramp_ok = true;
	$prev    = null;
	foreach ( $levels as $cfg ) {
		if ( $prev && ( $cfg['speed'] <= $prev['speed'] || $cfg['tolerance'] >= $prev['tolerance'] ) ) {
			$ramp_ok = false;
		}
		$prev = $cfg;
	}
	$on_slider = true;
	foreach ( $pieces as $p ) {
		if ( abs( (float) $p['anchor_x'] ) > PF_DROP_X_MAX ) {
			$on_slider = false;
		}
	}
	$shape_ok = count( $pieces ) === PF_DROP_PIECE_COUNT
		&& array_values( $row_counts ) === [ 3, 3, 2, 1 ]
		&& $on_slider
		&& $star['type'] === 'star'
		&& $star['level'] === 4
		&& $ramp_ok;
	$add( 'Config: 9 pieces, 3-3-2-1 tree, star last, speed↑ tolerance↓', 'n/a', 'ok', $shape_ok ? 'ok' : 'bad config', $shape_ok );

	$all = true;
	foreach ( $cases as $c ) {
		$all = $all && $c['pass'];
	}
	return [ 'all_pass' => $all, 'cases' => $cases ];
}
