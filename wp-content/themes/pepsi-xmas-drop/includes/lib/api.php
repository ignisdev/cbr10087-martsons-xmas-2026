<?php
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/drop-engine.php';

define( 'PF_SESSION_TTL',     1800 );  // 30 min
define( 'PF_COMPLETION_TTL',   900 );  // 15 min — single-use bridge to congrats page

/**
 * Resolve the prize-window duration (in minutes) for a competition.
 * Sourced from the BPM admin's Prize Window Duration setting:
 *   wp_option `bpm_prize_window_{competition_id}`
 * Falls back to BPM's own default of 10 minutes.
 */
function pf_prize_window_minutes( int $competition_id ): int {
	$minutes = (int) get_option( 'bpm_prize_window_' . $competition_id, 10 );
	return $minutes > 0 ? $minutes : 10;
}

/**
 * Resolve the active competition ID. Prefers the theme's own
 * `pepsi_football_competition_id` option (name kept from the base
 * platform); falls back to BPM's active
 * competition so staging/dev installs with an unset option still work.
 */
function pf_resolve_competition_id(): int {
	$id = (int) get_option( 'pepsi_football_competition_id', 0 );
	if ( $id > 0 ) {
		return $id;
	}
	if ( function_exists( 'bpm_get_active_competition_id' ) ) {
		return (int) bpm_get_active_competition_id();
	}
	return 0;
}

// ── Multi-route detection ─────────────────────────────────────────────────────

/**
 * Returns the current request's route: 'main' or 'alt'.
 * Maps host to route via PF_ALT_HOST constant in wp-config.php.
 * Used to segment prize pools and swap brand assets per subdomain.
 */
function pf_current_route(): string {
	$alt_host = defined( 'PF_ALT_HOST' ) ? strtolower( trim( (string) PF_ALT_HOST ) ) : '';
	if ( $alt_host === '' ) {
		return 'main';
	}
	$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	return $host === $alt_host ? 'alt' : 'main';
}

// ── REST API registration ─────────────────────────────────────────────────────

add_action( 'rest_api_init', function (): void {
	$ns = 'pepsi-xmas/v1';

	// All game endpoints sit behind pf_rest_permission_game (same-origin + nonce);
	// /session/start additionally rate-limits by IP. See rest-security.php.
	$game_perm = function_exists( 'pf_rest_permission_game' ) ? 'pf_rest_permission_game' : '__return_true';
	$start_perm = function_exists( 'pf_rest_permission_session_start' ) ? 'pf_rest_permission_session_start' : '__return_true';

	register_rest_route( $ns, '/session/start', [
		'methods'             => 'POST',
		'callback'            => 'pf_api_session_start',
		'permission_callback' => $start_perm,
	] );

	register_rest_route( $ns, '/drop/attempt', [
		'methods'             => 'POST',
		'callback'            => 'pf_api_drop_attempt',
		'permission_callback' => $game_perm,
		'args'                => [
			'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'piece'         => [ 'required' => true ],
			'drop_x'        => [ 'required' => true ],
		],
	] );

	register_rest_route( $ns, '/session/complete', [
		'methods'             => 'POST',
		'callback'            => 'pf_api_session_complete',
		'permission_callback' => $game_perm,
		'args'                => [
			'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// Drop-flow self-test harness — asserts the two core scenarios. Gated to
	// logged-in admins only by pf_winflow_test_permission.
	register_rest_route( $ns, '/debug/dropflow-test', [
		'methods'             => 'GET',
		'callback'            => 'pf_api_dropflow_test',
		'permission_callback' => 'pf_winflow_test_permission',
	] );

	// Stripe v2 Global Payouts self-test — creates a v2 recipient + onboarding
	// link in test mode so a developer can complete the hosted form, then read
	// back the account status. Gated by pf_winflow_test_permission (logged-in
	// admins only), same as the win-flow harness.
	register_rest_route( $ns, '/debug/stripe-v2-test', [
		'methods'             => 'GET',
		'callback'            => 'pf_api_stripe_v2_test',
		'permission_callback' => 'pf_winflow_test_permission',
	] );

	// Backfills the Stripe-side bank identity (holder name / last4 /
	// fingerprint) onto already-paid prize entries by re-reading each stored
	// gbba_ from the Stripe vault. Read-only against Stripe — no payouts are
	// touched. Seeds the repeat-winner table + payout blocklist on the PF
	// Debug settings page. Batched (?limit=, default 50); re-run until
	// remaining=0.
	register_rest_route( $ns, '/debug/stripe-v2-backfill-bank-identity', [
		'methods'             => 'GET',
		'callback'            => 'pf_api_stripe_v2_backfill_bank_identity',
		'permission_callback' => 'pf_winflow_test_permission',
	] );

	// Payout status polling — used by the prize-processing page to detect when
	// the v2 webhook has confirmed the payout so it can auto-redirect to
	// prize-paid. Public (no nonce) because it's called from a page the winner
	// sees before a WordPress session cookie is issued; the opaque claim token
	// is the only secret required. Returns only a minimal status string — no
	// prize entry IDs or personal data are exposed.
	register_rest_route( $ns, '/payout-status', [
		'methods'             => 'GET',
		'callback'            => 'pf_api_payout_status',
		'permission_callback' => '__return_true',
		'args'                => [
			'pf_claim' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// Stripe webhook stays public — Stripe servers don't send Origin/Referer
	// headers, and the handler verifies the Stripe-Signature via the SDK.
	// GET endpoint removed: it only existed to prove reachability and was a
	// passive probe vector.
	register_rest_route( $ns, '/stripe/webhook', [
		'methods'             => 'POST',
		'callback'            => 'pf_api_stripe_webhook',
		'permission_callback' => '__return_true',
	] );
} );

function pf_api_payout_status( WP_REST_Request $req ): WP_REST_Response {
	$claim_token = sanitize_text_field( (string) $req->get_param( 'pf_claim' ) );
	if ( $claim_token === '' ) {
		return new WP_REST_Response( [ 'status' => 'invalid' ], 400 );
	}

	$context = function_exists( 'pf_get_stripe_claim_context' ) ? pf_get_stripe_claim_context( $claim_token ) : null;
	if ( ! is_array( $context ) ) {
		// Context deleted = payout completed and token destroyed, or token never existed.
		// Treat as complete so the polling page redirects to prize-paid.
		return new WP_REST_Response( [ 'status' => 'complete' ], 200 );
	}

	$prize_entry_id = (int) ( $context['prize_entry_id'] ?? 0 );
	if ( $prize_entry_id <= 0 || ! function_exists( 'pf_get_bpm_stripe_outbound_payment_id' ) ) {
		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	global $wpdb;
	$table  = $wpdb->prefix . 'bpm_prize_entries';
	$status = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT stripe_payout_status FROM {$table} WHERE id = %d LIMIT 1",
		$prize_entry_id
	) );

	// processing = OBP created, money sent (webhook may not have posted yet).
	// posted     = outbound payment posted by Stripe (webhook confirmed).
	// paid       = v1 payout path settled.
	if ( in_array( $status, [ 'processing', 'posted', 'paid' ], true ) ) {
		return new WP_REST_Response( [ 'status' => 'complete' ], 200 );
	}
	if ( in_array( $status, [ 'failed', 'cop_blocked', 'payout_blocked', 'account_rejected', 'canceled', 'cancelled', 'returned' ], true ) ) {
		return new WP_REST_Response( [ 'status' => 'failed' ], 200 );
	}

	return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
}

function pf_api_stripe_webhook( WP_REST_Request $req ): WP_REST_Response {
	if ( ! function_exists( 'pf_handle_stripe_webhook' ) ) {
		return new WP_REST_Response( [ 'error' => 'Stripe handler not available.' ], 501 );
	}

	return pf_handle_stripe_webhook( $req );
}

// ── Endpoint: session/start ───────────────────────────────────────────────────

function pf_api_session_start( WP_REST_Request $req ): WP_REST_Response {
	if ( function_exists( 'pf_flow_ensure_session' ) ) {
		pf_flow_ensure_session();
	}

	// /game/ self-defence: if the player already started a tree in this
	// session, refusing here is what actually blocks the replay even when
	// the browser served /game/ from bfcache and never hit flow-guard. JS
	// in main.ts follows the redirect URL we return → age-gate entry route
	// (which calls pf_flow_reset → tree_started cleared).
	if ( function_exists( 'pf_flow_tree_was_started' ) && pf_flow_tree_was_started() ) {
		$age_gate_url = function_exists( 'pepsi_get_page_url_by_template' )
			? pepsi_get_page_url_by_template( 'page-age-gate.php' )
			: '';
		if ( $age_gate_url === '' ) {
			$age_gate_url = home_url( '/' );
		}
		error_log( 'PF FLOW session/start: tree_started already set; refusing and redirecting to ' . $age_gate_url );
		return new WP_REST_Response( [
			'error'    => 'tree_started',
			'redirect' => $age_gate_url,
		], 403 );
	}

	// Daily-limit enforcement — delegated to BPM_Play_Limit. The cap comes
	// from the bpm_play_limit_per_day filter (set to 3 in daily-limit.php for
	// this promo). Fails open if we never saw the player's email (no age-gate
	// run this session) or if BPM_Play_Limit isn't loaded, so legitimate
	// players aren't bounced when sessions get weird.
	if ( class_exists( 'BPM_Play_Limit' ) && function_exists( 'pf_get_player_email' ) ) {
		$email = pf_get_player_email();
		if ( $email !== '' && BPM_Play_Limit::is_over_limit( $email ) ) {
			return new WP_REST_Response( [
				'error'      => 'daily_limit',
				'limit'      => BPM_Play_Limit::limit(),
				'play_count' => BPM_Play_Limit::count_today( $email ),
			], 429 );
		}
		if ( $email !== '' ) {
			BPM_Play_Limit::record( $email );
		}
	}

	$token   = bin2hex( random_bytes( 16 ) );
	$session = [
		'drops'         => [],
		'placed'        => 0,
		'run_over'      => false,
		'outcome'       => '',
		'last_drop_ms'  => 0,
		'started_at'    => time(),
		'ip'            => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		'completed'     => false,
		'competition_id'=> pf_resolve_competition_id(),
		'route'         => pf_current_route(),
	];
	set_transient( 'pf_session_' . $token, $session, PF_SESSION_TTL );
	if ( function_exists( 'pf_flow_mark_step' ) ) {
		pf_flow_mark_step( 'game' );
	}
	// NOTE: tree_started is NOT set here — loading /game/ alone must not
	// lock the player out. The flag is set in pf_api_drop_attempt when
	// they actually DROP the first piece.
	return new WP_REST_Response( [
		'session_token' => $token,
		'game'          => pf_drop_public_config(),
	], 200 );
}

// ── Previous-winner dedupe (EXACT name match) ─────────────────────────────────
//
// A second layer on top of BPM_Access_Control: once a player WINS a slot, their
// first+last name is recorded into wp_bpm_user_blocks under a dedicated block
// type. A later player whose age-gate name EXACTLY matches a recorded winner is
// forced to lose — their star falls through the same Scenario-2 win-block path as the
// access-control restriction, so the anti-tell behaviour is identical.
//
// This is deliberately separate from the plugin's block_type='name' rows, which
// stay SUBSTRING/LIKE (curated compère lists — "Smith" catches "John Smithson").
// Winner dedupe is EXACT on normalised first AND last, so winner "John Smith"
// blocks only a later "John Smith", never "John Smithson". The plugin's
// BPM_Access_Control never reads this block type, so existing block behaviour is
// untouched.
const PF_WINNER_DEDUPE_BLOCK_TYPE = 'pf_winner_dedupe';

/**
 * Normalised exact-match key for a player name: "first|last", lowercased with
 * whitespace collapsed. Returns '' when either part is empty — a blank name must
 * never be recorded or matched (it would block every nameless identity).
 */
function pf_winner_dedupe_key( string $first, string $last ): string {
	$norm = static function ( string $v ): string {
		$v = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $v ) ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $v ) : strtolower( $v );
	};
	$first = $norm( $first );
	$last  = $norm( $last );
	if ( $first === '' || $last === '' ) {
		return '';
	}
	return $first . '|' . $last;
}

/**
 * Has a player with this exact first+last name already won this competition?
 * Exact (case-insensitive) lookup against the recorded winner-dedupe rows.
 */
function pf_winner_name_already_won( array $identity, int $competition_id ): bool {
	if ( $competition_id <= 0 ) {
		return false;
	}
	$key = pf_winner_dedupe_key(
		(string) ( $identity['first_name'] ?? '' ),
		(string) ( $identity['last_name'] ?? '' )
	);
	if ( $key === '' ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_user_blocks';
	$hit   = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table
		 WHERE competition_id = %d AND block_type = %s AND block_value = %s
		 LIMIT 1",
		$competition_id,
		PF_WINNER_DEDUPE_BLOCK_TYPE,
		$key
	) );

	if ( $hit && class_exists( 'BPM_Audit_Logger' ) ) {
		BPM_Audit_Logger::log(
			$competition_id,
			'player_restriction_matched',
			'Winner dedupe matched: name already won',
			'warning',
			[ 'block_type' => PF_WINNER_DEDUPE_BLOCK_TYPE, 'block_value' => $key, 'block_id' => (int) $hit ]
		);
	}
	return (bool) $hit;
}

/**
 * Record a winner's first+last name so future exact-name matches are blocked.
 * INSERT … ON DUPLICATE KEY (the table's unique_block key on
 * competition_id+block_type+block_value makes a repeat win idempotent). No-op on
 * a blank name. Writes to the shared block-list table only — no plugin changes.
 */
function pf_record_winner_name( array $identity, int $competition_id ): void {
	if ( $competition_id <= 0 ) {
		return;
	}
	$key = pf_winner_dedupe_key(
		(string) ( $identity['first_name'] ?? '' ),
		(string) ( $identity['last_name'] ?? '' )
	);
	if ( $key === '' ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_user_blocks';
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO $table
			(competition_id, block_type, block_value, block_reason, block_behavior, created_at, created_by)
		 VALUES (%d, %s, %s, %s, %s, %s, %d)
		 ON DUPLICATE KEY UPDATE block_value = block_value",
		$competition_id,
		PF_WINNER_DEDUPE_BLOCK_TYPE,
		$key,
		'Auto: prior winner (exact-name dedupe)',
		'deny',
		current_time( 'mysql', true ),
		0
	) );
}

// ── Endpoint: drop/attempt ────────────────────────────────────────────────────
//
// Called once per piece, the moment the player taps to release it. The server
// judges the landing (pf_decide_drop_outcome — the single decision path) and
// the client animates whatever comes back: seat the piece, or tip/roll/bounce
// it off the tree. Only the killer piece (the star) ever reserves a prize slot.
//
// Idempotent per piece: a retried request for an already-judged piece returns
// the stored decision rather than re-rolling it.

function pf_api_drop_attempt( WP_REST_Request $req ): WP_REST_Response {
	$token  = sanitize_text_field( (string) $req->get_param( 'session_token' ) );
	$piece  = (int) $req->get_param( 'piece' );
	$drop_x = (float) $req->get_param( 'drop_x' );

	if ( ! is_finite( $drop_x ) ) {
		return new WP_REST_Response( [ 'error' => 'Invalid drop position.' ], 400 );
	}

	$session = get_transient( 'pf_session_' . $token );
	if ( ! $session ) {
		return new WP_REST_Response( [ 'error' => 'Session not found or expired.' ], 404 );
	}
	if ( $session['completed'] ) {
		return new WP_REST_Response( [ 'error' => 'Session already completed.' ], 409 );
	}

	// Retry of an already-judged piece → replay the stored decision.
	if ( isset( $session['drops'][ $piece ]['response'] ) ) {
		return new WP_REST_Response( $session['drops'][ $piece ]['response'], 200 );
	}

	$now_ms   = (int) floor( microtime( true ) * 1000 );
	$since_ms = $session['last_drop_ms'] > 0 ? $now_ms - (int) $session['last_drop_ms'] : PHP_INT_MAX;
	$seq_err  = pf_validate_drop_sequence( (int) $session['placed'], (bool) $session['run_over'], $piece, $since_ms );
	if ( $seq_err !== '' ) {
		return new WP_REST_Response( [ 'error' => $seq_err ], $seq_err === 'too_fast' ? 429 : 409 );
	}

	// First real drop — flip tree_started so refreshing /game/ from here on
	// bounces the player through the age-gate.
	if ( $piece === 1 && function_exists( 'pf_flow_mark_tree_started' ) ) {
		pf_flow_mark_tree_started();
	}

	$competition_id  = (int) ( $session['competition_id'] ?? 0 );
	$route           = $session['route'] ?? pf_current_route();
	$prize_entry     = null;
	$is_winning_slot = false;
	$identity        = [];

	// Only the killer piece touches the prize pool.
	if ( $piece === PF_DROP_KILLER_PIECE ) {
		// Plug-and-play access control: restricted players never reserve a
		// slot, so they hit the same WIN-BLOCK path as an empty window — same
		// anti-tell behaviour, no client-side difference. The exact-name
		// winner dedupe is a second layer on the same path.
		$identity   = function_exists( 'pf_current_player_identity' ) ? pf_current_player_identity() : [];
		$restricted = class_exists( 'BPM_Access_Control' )
			&& BPM_Access_Control::should_force_loss( $identity, $competition_id );
		if ( ! $restricted && pf_winner_name_already_won( $identity, $competition_id ) ) {
			$restricted = true;
		}
		if ( ! $restricted ) {
			$prize_entry     = pf_reserve_bpm_win_slot( $competition_id, $route );
			$is_winning_slot = $prize_entry !== null;
		}
	}

	$decision = pf_decide_drop_outcome( $piece, $drop_x, $is_winning_slot );
	$outcome  = $decision['outcome'];

	if ( $outcome === 'winner' && $is_winning_slot ) {
		$session['prize'] = [
			'prize_entry_id'  => (int) ( $prize_entry['id'] ?? 0 ),
			'prize_type'      => sanitize_text_field( $prize_entry['prize_type'] ?? '' ),
			'prize_name'      => sanitize_text_field( $prize_entry['prize_name'] ?? '' ),
			'reservation_key' => sanitize_text_field( $prize_entry['reservation_key'] ?? '' ),
		];
		pf_record_winner_name( $identity, $competition_id );
	} elseif ( $is_winning_slot ) {
		// Reserved but not won — release immediately so the next player in the
		// same window can win it.
		$rk = sanitize_text_field( $prize_entry['reservation_key'] ?? '' );
		$ri = (int) ( $prize_entry['id'] ?? 0 );
		if ( $ri > 0 && $rk !== '' ) {
			pf_release_bpm_reservation( $ri, $rk );
		}
	}

	if ( $decision['result'] === 'landed' ) {
		$session['placed'] = $piece;
	}
	$run_over = ( $decision['result'] === 'fell' ) || $piece === PF_DROP_KILLER_PIECE;
	if ( $run_over ) {
		$session['run_over'] = true;
		$session['outcome']  = $outcome;
	}

	$response = [
		'piece'    => $piece,
		'result'   => $decision['result'],
		'land_x'   => $decision['land_x'],
		'anchor_x' => (float) ( pf_drop_pieces()[ $piece ]['anchor_x'] ?? 0.0 ),
		'fail'     => $decision['fail'],
		'placed'   => (int) $session['placed'],
		'run_over' => $run_over,
		// Commentary only once the run ends. A WIN-BLOCKed star draws from the
		// same 'fell' pool as any honest miss — no "so close" tell.
		'label'    => $run_over ? pf_pick_commentary( $outcome === 'winner' ? 'winner' : 'fell' ) : '',
	];

	$session['drops'][ $piece ] = [
		'drop_x'   => $drop_x,
		'outcome'  => $outcome,
		'response' => $response,
	];
	$session['last_drop_ms'] = $now_ms;
	set_transient( 'pf_session_' . $token, $session, PF_SESSION_TTL );

	if ( $piece === PF_DROP_KILLER_PIECE || $run_over ) {
		error_log( sprintf(
			'PF FLOW drop/attempt: now=%s piece=%d killer=%s drop_x=%.4f offset=%.4f tol=%.4f slot=%s outcome=%s reason=%s prize_entry_id=%d',
			current_time( 'mysql' ),
			$piece,
			$piece === PF_DROP_KILLER_PIECE ? 'yes' : 'no',
			$drop_x,
			$decision['offset'],
			$decision['tolerance'] ?? 0,
			$is_winning_slot ? 'yes' : 'no',
			$outcome,
			$decision['reason'],
			(int) ( $session['prize']['prize_entry_id'] ?? 0 )
		) );
	}

	return new WP_REST_Response( $response, 200 );
}

// ── Endpoint: session/complete ────────────────────────────────────────────────

function pf_api_session_complete( WP_REST_Request $req ): WP_REST_Response {
	$token = sanitize_text_field( $req->get_param( 'session_token' ) );

	$session = get_transient( 'pf_session_' . $token );
	if ( ! $session ) {
		return new WP_REST_Response( [ 'error' => 'Session not found or expired.' ], 404 );
	}
	if ( $session['completed'] ) {
		return new WP_REST_Response( [ 'error' => 'Session already completed.' ], 409 );
	}
	if ( empty( $session['drops'] ) ) {
		return new WP_REST_Response( [ 'error' => 'No drop submitted.' ], 400 );
	}

	// A run abandoned mid-tree (network loss) completes as a non-winner.
	$prize           = (array) ( $session['prize'] ?? [] );
	$is_winner       = ( $session['run_over'] && $session['outcome'] === 'winner' && ! empty( $prize['prize_entry_id'] ) );
	$score           = (int) $session['placed'];
	$prize_entry_id  = $is_winner ? (int) $prize['prize_entry_id'] : 0;
	$prize_type      = $is_winner ? (string) $prize['prize_type'] : '';
	$prize_name      = $is_winner ? (string) $prize['prize_name'] : '';
	$reservation_key = $is_winner ? (string) $prize['reservation_key'] : '';

	$session['completed'] = true;
	set_transient( 'pf_session_' . $token, $session, PF_SESSION_TTL );

	// Issue a single-use completion token
	$completion_token = bin2hex( random_bytes( 16 ) );
	set_transient( 'pf_complete_' . $completion_token, [
		'score'          => $score,
		'is_winner'      => $is_winner,
		'prize_entry_id' => $prize_entry_id,
		'prize_type'     => $prize_type,
		'prize_name'     => $prize_name,
		'reservation_key'=> $reservation_key,
		'competition_id' => $session['competition_id'] ?? 0,
		'used'           => false,
	], PF_COMPLETION_TTL );

	return new WP_REST_Response( [
		'completion_token' => $completion_token,
		'is_winner'        => $is_winner,
	], 200 );
}

// ── BPM win-slot check ────────────────────────────────────────────────────────

function pf_bpm_prize_has_column( string $column ): bool {
	static $cache = [];

	if ( isset( $cache[ $column ] ) ) {
		return $cache[ $column ];
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$row   = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) );
	$cache[ $column ] = (bool) $row;

	return $cache[ $column ];
}

/**
 * Atomically reserve an available prize row in the current minute window.
 * Returns the reserved row (including reservation_key) or null if unavailable.
 */
function pf_reserve_bpm_win_slot( int $competition_id, string $route = 'main' ): ?array {
	if ( $competition_id <= 0 ) {
		return null;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';

	// Check if the table exists before querying
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return null;
	}

	// A prize unlocks at date_available and stays winnable for N minutes.
	$now            = current_time( 'mysql' );
	$window_minutes = pf_prize_window_minutes( $competition_id );
	$window_start   = date( 'Y-m-d H:i:s', strtotime( $now ) - $window_minutes * 60 );
	$window_end     = $now;

	$has_route = pf_bpm_prize_has_column( 'source_route' );

	if ( ! pf_bpm_prize_has_column( 'reservation_key' ) || ! pf_bpm_prize_has_column( 'reservation_expires_at' ) ) {
		if ( $has_route ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, prize_type, prize_name, code
				 FROM   $table
				 WHERE  competition_id  = %d
				 AND    claimed         = 0
				 AND    date_available  >= %s
				 AND    date_available  <= %s
				 AND    source_route IN ('both', %s)
				 ORDER BY (source_route = 'both') ASC, date_available ASC, id ASC
				 LIMIT  1",
				$competition_id, $window_start, $window_end, $route
			), ARRAY_A );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, prize_type, prize_name, code
				 FROM   $table
				 WHERE  competition_id  = %d
				 AND    claimed         = 0
				 AND    date_available  >= %s
				 AND    date_available  <= %s
				 LIMIT  1",
				$competition_id, $window_start, $window_end
			), ARRAY_A );
		}
		return $row ?: null;
	}

	$reservation_key = bin2hex( random_bytes( 16 ) );
	// Use WP-local time (matches `current_time('mysql')` and the date_available column)
	// so reserved_at / reservation_expires_at line up with the prize window timeline
	// instead of being an hour out under BST.
	// Reservation lifespan is bound to the BPM Prize Window Duration so a lock can
	// never outlast the window itself — no hardcoded TTL.
	$reserved_at_str = $now;
	$expires_at      = date( 'Y-m-d H:i:s', strtotime( $now ) + $window_minutes * 60 );

	if ( $has_route ) {
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE $table
			 SET reservation_key = %s,
			     reserved_at = %s,
			     reservation_expires_at = %s
			 WHERE id = (
			   SELECT id FROM (
			     SELECT id
			     FROM $table
			     WHERE competition_id = %d
			       AND claimed = 0
			       AND date_available >= %s
			       AND date_available <= %s
			       AND source_route IN ('both', %s)
			       AND (reservation_expires_at IS NULL OR reservation_expires_at < %s)
			     ORDER BY (source_route = 'both') ASC, date_available ASC, id ASC
			     LIMIT 1
			   ) pick
			 )
			 AND claimed = 0
			 AND (reservation_expires_at IS NULL OR reservation_expires_at < %s)",
			$reservation_key,
			$reserved_at_str,
			$expires_at,
			$competition_id,
			$window_start,
			$window_end,
			$route,
			$now,
			$now
		) );
	} else {
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE $table
			 SET reservation_key = %s,
			     reserved_at = %s,
			     reservation_expires_at = %s
			 WHERE id = (
			   SELECT id FROM (
			     SELECT id
			     FROM $table
			     WHERE competition_id = %d
			       AND claimed = 0
			       AND date_available >= %s
			       AND date_available <= %s
			       AND (reservation_expires_at IS NULL OR reservation_expires_at < %s)
			     ORDER BY date_available ASC, id ASC
			     LIMIT 1
			   ) pick
			 )
			 AND claimed = 0
			 AND (reservation_expires_at IS NULL OR reservation_expires_at < %s)",
			$reservation_key,
			$reserved_at_str,
			$expires_at,
			$competition_id,
			$window_start,
			$window_end,
			$now,
			$now
		) );
	}

	if ( $updated === false || $updated < 1 ) {
		return null;
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, prize_type, prize_name, code, reservation_key, reservation_expires_at
		 FROM $table
		 WHERE reservation_key = %s
		 LIMIT 1",
		$reservation_key
	), ARRAY_A );

	return $row ?: null;
}

function pf_release_bpm_reservation( int $prize_entry_id, string $reservation_key ): bool {
	if ( $prize_entry_id <= 0 || $reservation_key === '' || ! pf_bpm_prize_has_column( 'reservation_key' ) ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';

	$updated = $wpdb->query( $wpdb->prepare(
		"UPDATE $table
		 SET reservation_key = NULL, reserved_at = NULL, reservation_expires_at = NULL
		 WHERE id = %d
		   AND claimed = 0
		   AND reservation_key = %s",
		$prize_entry_id,
		$reservation_key
	) );

	return $updated !== false && $updated > 0;
}

function pf_validate_bpm_reservation( int $prize_entry_id, string $reservation_key ): bool {
	if ( $prize_entry_id <= 0 || $reservation_key === '' ) {
		return false;
	}

	if ( ! pf_bpm_prize_has_column( 'reservation_key' ) || ! pf_bpm_prize_has_column( 'reservation_expires_at' ) ) {
		return true;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$valid = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		 FROM $table
		 WHERE id = %d
		   AND claimed = 0
		   AND reservation_key = %s
		   AND reservation_expires_at >= %s",
		$prize_entry_id,
		$reservation_key,
		current_time( 'mysql' )
	) );

	return $valid > 0;
}

/**
 * Claim a BPM prize entry — called from gform_pre_submission when a winner
 * submits their details.
 */
function pf_claim_bpm_prize( int $prize_entry_id, string $email, string $name, int $gf_entry_id, string $reservation_key = '' ): bool {
	if ( $prize_entry_id <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';

	$claimed_at_mysql = current_time( 'mysql' );
	$claimed_email    = sanitize_email( $email );
	$claimed_name     = sanitize_text_field( $name );

	$data = [
		'claimed'               => 1,
		'claimed_at'            => $claimed_at_mysql,
		'claimed_by_email'      => $claimed_email,
		'gravity_form_entry_id' => $gf_entry_id,
	];
	$formats = [ '%d', '%s', '%s', '%d' ];

	if ( pf_bpm_prize_has_column( 'claimed_by_name' ) ) {
		$data['claimed_by_name'] = $claimed_name;
		$formats[]               = '%s';
	}
	if ( pf_bpm_prize_has_column( 'reservation_key' ) ) {
		$data['reservation_key'] = null;
		$formats[]               = '%s';
	}
	if ( pf_bpm_prize_has_column( 'reserved_at' ) ) {
		$data['reserved_at'] = null;
		$formats[]           = '%s';
	}
	if ( pf_bpm_prize_has_column( 'reservation_expires_at' ) ) {
		$data['reservation_expires_at'] = null;
		$formats[]                      = '%s';
	}

	$where = [ 'id' => $prize_entry_id, 'claimed' => 0 ];
	$where_formats = [ '%d', '%d' ];

	if ( $reservation_key !== '' && pf_bpm_prize_has_column( 'reservation_key' ) ) {
		$where['reservation_key'] = sanitize_text_field( $reservation_key );
		$where_formats[]          = '%s';
	}

	$updated = $wpdb->update( $table, $data, $where, $formats, $where_formats );
	if ( $updated === false || $updated < 1 ) {
		return false;
	}

	// Mirror the claim onto the prize post's meta so the BPM dashboard (which
	// reads postmeta) stays in sync with the bpm_prize_entries table.
	$prize_post_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT prize_post_id FROM $table WHERE id = %d",
		$prize_entry_id
	) );
	if ( $prize_post_id > 0 ) {
		update_post_meta( $prize_post_id, 'claimed',          'true' );
		update_post_meta( $prize_post_id, 'claimed_at',       $claimed_at_mysql );
		update_post_meta( $prize_post_id, 'claimed_by_email', $claimed_email );
		if ( $claimed_name !== '' ) {
			update_post_meta( $prize_post_id, 'claimed_by_name', $claimed_name );
		}
		update_post_meta( $prize_post_id, 'gravity_form_entry_id', $gf_entry_id );

		// Also sync the prize's `mapped_data` JSON. The BPM prizes dashboard
		// renders a second, editable "Claimed" column from `mapped_data` (any
		// unmapped key becomes its own auto-generated column with editable=true)
		// — separately from the computed "Status" column, which reads
		// post_meta 'claimed'. Without this sync the dashboard splits: Status
		// flips to "Claimed" but the editable Claimed column stays at its
		// CSV-import "false", so editors see the entry as half-claimed.
		$mapped_json = get_post_meta( $prize_post_id, 'mapped_data', true );
		$mapped      = is_string( $mapped_json ) && $mapped_json !== ''
			? json_decode( $mapped_json, true )
			: [];
		if ( ! is_array( $mapped ) ) {
			$mapped = [];
		}
		// Only rewrite + persist when the value actually changed — avoids a
		// spurious wp_postmeta write (and the cache-bust that comes with it)
		// on every claim when the column was already set to 'true' via the
		// dashboard's manual "Mark Claimed" path.
		if ( ( $mapped['claimed'] ?? null ) !== 'true' ) {
			$mapped['claimed'] = 'true';
			update_post_meta( $prize_post_id, 'mapped_data', wp_json_encode( $mapped ) );
		}
	}

	return true;
}

// ── Drop-flow self-test harness ──────────────────────────────────────────────
//
// Deterministic assertions for the two CORE scenarios. The pure rule checks
// live in pf_drop_engine_self_test() (drop-engine.php, no WP needed); this
// wrapper adds the WP-dependent checks. Surfaced via
// GET /wp-json/pepsi-xmas/v1/debug/dropflow-test (logged-in admins only) and
// the PF DEBUG panel "RUN SELF-TEST".

function pf_dropflow_self_test(): array {
	$out      = pf_drop_engine_self_test();
	$results  = $out['cases'];
	$all_pass = $out['all_pass'];

	// Access-control wiring: restricted players skip pf_reserve_bpm_win_slot(),
	// so they resolve through the same slot=OFF path asserted above.
	$ac_pass   = class_exists( 'BPM_Access_Control' );
	$all_pass  = $all_pass && $ac_pass;
	$results[] = [
		'name'     => 'Access control: BPM_Access_Control wired (plug-and-play)',
		'slot'     => 'n/a',
		'expected' => 'class present',
		'actual'   => $ac_pass ? 'class present' : 'class missing',
		'pass'     => $ac_pass,
		'reason'   => 'restricted players are forced down the slot=OFF path',
	];

	// Winner-dedupe key: EXACT first+last (case/space-insensitive), never a
	// match on a blank part.
	$k_smith     = pf_winner_dedupe_key( 'John', 'Smith' );
	$k_smith_dup = pf_winner_dedupe_key( '  john ', 'SMITH' );
	$k_smithson  = pf_winner_dedupe_key( 'John', 'Smithson' );
	$k_blank     = pf_winner_dedupe_key( 'John', '' );
	$dedupe_pass = ( $k_smith !== '' )
		&& ( $k_smith === $k_smith_dup )
		&& ( $k_smith !== $k_smithson )
		&& ( $k_blank === '' );
	$all_pass    = $all_pass && $dedupe_pass;
	$results[]   = [
		'name'     => 'Winner dedupe: exact first+last key (not substring)',
		'slot'     => 'n/a',
		'expected' => 'smith==smith, smith!=smithson, blank==empty',
		'actual'   => $dedupe_pass ? 'smith==smith, smith!=smithson, blank==empty' : 'mismatch',
		'pass'     => $dedupe_pass,
		'reason'   => 'pf_winner_dedupe_key normalises and requires both parts',
	];

	// Prize tiers resolve to the right payout amount; unknown cash tiers pay 0.
	if ( function_exists( 'pf_prize_amount_pence_for_type' ) ) {
		$tier_pass = pf_prize_amount_pence_for_type( 'cash_250' ) === 25000
			&& pf_prize_amount_pence_for_type( 'cash_10' ) === 1000
			&& pf_prize_amount_pence_for_type( 'cash_999' ) === 0
			&& pf_is_cash_prize_type( 'cash_250' )
			&& ! pf_is_cash_prize_type( 'merch' );
		$all_pass  = $all_pass && $tier_pass;
		$results[] = [
			'name'     => 'Prize tiers: cash_250 → £250, cash_10 → £10, unknown → no payout',
			'slot'     => 'n/a',
			'expected' => '25000 / 1000 / 0',
			'actual'   => sprintf( '%d / %d / %d',
				pf_prize_amount_pence_for_type( 'cash_250' ),
				pf_prize_amount_pence_for_type( 'cash_10' ),
				pf_prize_amount_pence_for_type( 'cash_999' )
			),
			'pass'     => $tier_pass,
			'reason'   => 'pf_prize_amount_map in stripe-payout.php',
		];
	}

	return [ 'all_pass' => $all_pass, 'cases' => $results ];
}

/** Gate for the debug endpoints: logged-in admins only (hardened). */
function pf_winflow_test_permission(): bool {
	return ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) );
}

function pf_api_dropflow_test( WP_REST_Request $req ): WP_REST_Response {
	return new WP_REST_Response( pf_dropflow_self_test(), 200 );
}

/**
 * Stripe v2 Global Payouts smoke-test endpoint.
 *
 * GET  /debug/stripe-v2-test
 *      → creates v2 recipient + AccountLink, returns onboarding url + ids.
 * GET  /debug/stripe-v2-test?account=acct_…
 *      → fetches account status + tries to discover the gbba_ payment method
 *        + CoP result. Use this after completing the hosted onboarding to
 *        verify the wiring before plumbing it into the real claim flow.
 */
function pf_api_stripe_v2_test( WP_REST_Request $req ): WP_REST_Response {
	if ( ! function_exists( 'pf_stripe_v2_post' ) ) {
		return new WP_REST_Response( [ 'error' => 'stripe-payout.php not loaded.' ], 500 );
	}
	if ( pf_stripe_secret_key() === '' ) {
		return new WP_REST_Response( [ 'error' => 'STRIPE_SECRET_KEY not configured.' ], 500 );
	}

	$existing = sanitize_text_field( (string) ( $req->get_param( 'account' ) ?? '' ) );
	if ( $existing !== '' ) {
		$acct = pf_stripe_v2_get( '/core/accounts/' . rawurlencode( $existing ), [
			'include' => [ 'configuration.recipient', 'requirements', 'identity' ],
		] );
		$gbba = pf_v2_discover_gb_bank_account( $existing );
		return new WP_REST_Response( [
			'mode'         => 'inspect',
			'account_id'   => $existing,
			'account_ok'   => $acct['ok'],
			'bank_status'  => $acct['data']['configuration']['recipient']['capabilities']['bank_accounts']['local']['status'] ?? null,
			'currently_due'=> $acct['data']['requirements']['currently_due'] ?? [],
			'gbba_id'      => $gbba['gbba_id'],
			'cop_result'   => $gbba['cop_result'],
			'is_payable'   => pf_v2_cop_result_is_payable( $gbba['cop_result'] ),
			'raw_account'  => $acct['data'],
		], 200 );
	}

	$ts      = (int) ( $_SERVER['REQUEST_TIME'] ?? time() );
	$email   = 'pf-v2-test-' . $ts . '@example.com';
	$create  = pf_stripe_v2_post( '/core/accounts', [
		'contact_email' => $email,
		'display_name'  => 'PF v2 Test ' . $ts,
		'identity'      => [ 'country' => 'gb', 'entity_type' => 'individual' ],
		'configuration' => [
			'recipient' => [
				'capabilities' => [ 'bank_accounts' => [ 'local' => [ 'requested' => true ] ] ],
			],
		],
		'metadata'      => [ 'pf_self_test' => '1', 'created_ts' => (string) $ts ],
		'include'       => [ 'requirements', 'configuration.recipient', 'identity' ],
	] );
	if ( ! $create['ok'] ) {
		return new WP_REST_Response( [ 'mode' => 'create', 'ok' => false, 'error' => $create['error'] ], 500 );
	}
	$account_id = (string) ( $create['data']['id'] ?? '' );

	$link = pf_stripe_v2_post( '/core/account_links', [
		'account'  => $account_id,
		'use_case' => [
			'type'               => 'account_onboarding',
			'account_onboarding' => [
				'return_url'     => pf_stripe_public_url( pf_prize_page_url( 'stripe-return',  [ 'pf_claim' => 'selftest_' . $ts ] ) ),
				'refresh_url'    => pf_stripe_public_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => 'selftest_' . $ts ] ) ),
			],
		],
	] );

	return new WP_REST_Response( [
		'mode'         => 'create',
		'ok'           => $link['ok'],
		'account_id'   => $account_id,
		'onboarding_url' => $link['data']['url'] ?? null,
		'next_step'    => 'Open onboarding_url, complete the form, then GET this endpoint again with ?account=' . $account_id . ' to inspect status + CoP.',
		'link_error'   => $link['ok'] ? null : $link['error'],
	], 200 );
}

// ── Commentary ───────────────────────────────────────────────────────────────
// Lines rotate randomly within each bucket. There is deliberately ONE losing
// pool: an honest miss and a WIN-BLOCKed star read identically.

function pf_pick_commentary( string $bucket ): string {
	$pools = [
		'winner' => [
			'STAR ON TOP. XMAS SORTED.',
			'THAT’S THE PEPSI XMAS TREE, COMPLETE!',
			'NAILED IT. PURE FESTIVE CLASS.',
			'THE STAR HAS LANDED!',
		],
		'fell' => [
			'TIMBER! SO NEAR.',
			'OOH, IT TOPPLED.',
			'THE TREE WASN’T HAVING IT.',
			'GO ON, ONE MORE DROP. IT’S XMAS AFTER ALL.',
			'WOBBLE, WOBBLE… GONE.',
			'NOT QUITE THE SPOT.',
		],
	];
	$pool = $pools[ $bucket ] ?? $pools['fell'];
	return $pool[ array_rand( $pool ) ];
}

// ── Completion token helpers ──────────────────────────────────────────────────

/**
 * Read the verified data from a completion token without consuming it.
 * Returns null if the token is invalid or expired.
 */
function pf_peek_completion_token( string $token ): ?array {
	$data = get_transient( 'pf_complete_' . $token );
	if ( ! $data ) return null;
	return $data;
}

/**
 * Consume a completion token — returns the verified data and marks it used.
 * Returns null if the token is invalid, expired, or already consumed.
 * Call this exactly once: inside gform_pre_submission to write the real score
 * into field 10 before Gravity Forms processes the entry.
 */
function pf_consume_completion_token( string $token ): ?array {
	$data = get_transient( 'pf_complete_' . $token );
	if ( ! $data || $data['used'] ) return null;

	$data['used'] = true;
	set_transient( 'pf_complete_' . $token, $data, PF_COMPLETION_TTL );

	return $data;
}

/**
 * Single-use-ish bridge for the post-claim /thanks/ page. The merch claim
 * redirect must NOT expose the raw Gravity Forms entry id in the URL (an
 * enumerable id is an information-disclosure surface). Instead we stash the
 * entry id behind an unguessable token and put the token in the URL.
 *
 * The token is intentionally NOT consumed on read so a refresh of /thanks/
 * still renders the personalised prize copy; its short TTL bounds exposure.
 */
function pf_create_thanks_token( int $gf_entry_id ): string {
	$token = bin2hex( random_bytes( 16 ) );
	set_transient( 'pf_thanks_' . $token, [ 'gf_entry_id' => $gf_entry_id ], PF_COMPLETION_TTL );
	return $token;
}

function pf_resolve_thanks_token( string $token ): int {
	if ( $token === '' ) {
		return 0;
	}
	$data = get_transient( 'pf_thanks_' . sanitize_text_field( $token ) );
	return is_array( $data ) ? (int) ( $data['gf_entry_id'] ?? 0 ) : 0;
}

/**
 * Read-only peek at the prize available in the current minute window for a
 * given competition + route. Does not reserve. Used by the debug overlay to
 * tell testers whether a flick "right now" would hit a prize slot.
 */
function pf_peek_current_minute_prize( int $competition_id, string $route = 'main' ): ?array {
	if ( $competition_id <= 0 ) {
		return null;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';

	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return null;
	}

	// A prize unlocks at its date_available and stays winnable for N minutes after.
	// So at the current moment, eligible prizes are those whose date_available is
	// within the last N minutes (between now − N min and now).
	$now            = current_time( 'mysql' );
	$window_minutes = pf_prize_window_minutes( $competition_id );
	$window_start   = date( 'Y-m-d H:i:s', strtotime( $now ) - $window_minutes * 60 );  // earliest: now − N min
	$window_end     = $now;                                                              // latest: now
	$has_route      = pf_bpm_prize_has_column( 'source_route' );

	if ( $has_route ) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, code, prize_type, prize_name, source_route, date_available
			 FROM   $table
			 WHERE  competition_id = %d
			 AND    claimed        = 0
			 AND    date_available >= %s
			 AND    date_available <= %s
			 AND    source_route IN ('both', %s)
			 AND    (reservation_expires_at IS NULL OR reservation_expires_at < %s)
			 ORDER BY (source_route = 'both') ASC, date_available ASC, id ASC
			 LIMIT 1",
			$competition_id, $window_start, $window_end, $route, $now
		), ARRAY_A );
	} else {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, code, prize_type, prize_name, date_available
			 FROM   $table
			 WHERE  competition_id = %d
			 AND    claimed        = 0
			 AND    date_available >= %s
			 AND    date_available <= %s
			 AND    (reservation_expires_at IS NULL OR reservation_expires_at < %s)
			 ORDER BY date_available ASC, id ASC
			 LIMIT 1",
			$competition_id, $window_start, $window_end, $now
		), ARRAY_A );
	}

	return is_array( $row ) ? $row : null;
}

// ── Prize info helpers for templates ──────────────────────────────────────────

/**
 * Format a prize name with the correct English article so it slots into
 * sentences naturally:
 *   "£5"       → "£5"            (no article — currency amount)
 *   "100 pts"  → "100 pts"       (no article — numeric)
 *   "iPhone"   → "an iPhone"
 *   "t-shirt"  → "a t-shirt"
 *
 * Returns an empty string if $prize_name is empty.
 */
function pf_format_prize_with_article( string $prize_name ): string {
	$prize_name = trim( $prize_name );
	if ( $prize_name === '' ) {
		return '';
	}

	$first = mb_substr( $prize_name, 0, 1 );

	// Currency or numeric — no article.
	if ( preg_match( '/^[£$€¥0-9]/u', $first ) ) {
		return $prize_name;
	}

	// Vowel sound (rough heuristic — covers most cases).
	if ( preg_match( '/^[aeiouAEIOU]/u', $first ) ) {
		return 'an ' . $prize_name;
	}

	return 'a ' . $prize_name;
}


/**
 * Resolve the prize info to display on the congratulations page.
 * Reads the active completion token from ?token= and returns
 * [ 'is_winner', 'prize_type', 'prize_name', 'score' ] — all strings safe to echo.
 */
function pf_get_congrats_prize_info(): array {
	$default = [ 'is_winner' => false, 'prize_type' => '', 'prize_name' => '', 'score' => 0 ];

	// Forced cash debug path: no completion token, but we can still derive
	// the prize info from the entry id in the URL. Display-only — gated by
	// the lighter view check so stakeholders can preview prize pages without
	// needing admin auth. The actual claim path uses pf_is_forced_cash_test_allowed.
	$force_id = function_exists( 'pf_get_forced_cash_entry_id' ) ? pf_get_forced_cash_entry_id() : 0;
	if ( $force_id > 0 && function_exists( 'pf_is_forced_cash_view_allowed' ) && pf_is_forced_cash_view_allowed() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bpm_prize_entries';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT prize_type, prize_name FROM $table WHERE id = %d LIMIT 1",
				$force_id
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			return [
				'is_winner' => true,
				'prize_type'=> sanitize_text_field( (string) ( $row['prize_type'] ?? '' ) ),
				'prize_name'=> sanitize_text_field( (string) ( $row['prize_name'] ?? '' ) ),
				'score'     => 1000,
			];
		}
	}

	$token = isset( $_GET['token'] ) ? sanitize_text_field( (string) $_GET['token'] ) : '';
	if ( $token === '' ) {
		return $default;
	}

	$data = pf_peek_completion_token( $token );
	if ( ! is_array( $data ) ) {
		return $default;
	}

	$prize_type = sanitize_text_field( (string) ( $data['prize_type'] ?? '' ) );
	$prize_name = sanitize_text_field( (string) ( $data['prize_name'] ?? '' ) );

	// Robust fallback: if the token's prize_name is empty but we have an
	// entry id, fetch the BPM row directly. Covers older sessions or any
	// case where prize_name didn't get threaded through the chain.
	if ( $prize_name === '' && ! empty( $data['prize_entry_id'] ) ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bpm_prize_entries';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT prize_type, prize_name FROM $table WHERE id = %d LIMIT 1",
			(int) $data['prize_entry_id']
		), ARRAY_A );
		if ( is_array( $row ) ) {
			if ( $prize_type === '' ) {
				$prize_type = sanitize_text_field( (string) ( $row['prize_type'] ?? '' ) );
			}
			$prize_name = sanitize_text_field( (string) ( $row['prize_name'] ?? '' ) );
		}
	}

	return [
		'is_winner' => ! empty( $data['is_winner'] ),
		'prize_type'=> $prize_type,
		'prize_name'=> $prize_name,
		'score'     => (int) ( $data['score'] ?? 0 ),
	];
}

/**
 * Look up the BPM prize entry that was claimed by a given GF entry ID.
 * Used by the post-claim success pages (prize-paid, thanks)
 * to render dynamic prize copy after the completion token is gone.
 *
 * Returns [ 'prize_type', 'prize_name' ] — empty strings if not found.
 */
function pf_get_claimed_prize_info_by_gf_entry( int $gf_entry_id ): array {
	$default = [ 'prize_type' => '', 'prize_name' => '' ];
	if ( $gf_entry_id <= 0 ) {
		return $default;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT prize_type, prize_name FROM $table WHERE gravity_form_entry_id = %d AND claimed = 1 LIMIT 1",
			$gf_entry_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return $default;
	}

	return [
		'prize_type' => sanitize_text_field( (string) ( $row['prize_type'] ?? '' ) ),
		'prize_name' => sanitize_text_field( (string) ( $row['prize_name'] ?? '' ) ),
	];
}
