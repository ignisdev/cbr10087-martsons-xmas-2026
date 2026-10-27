<?php
/**
 * Pepsi Xmas Drop flow guard.
 *
 * One source of truth for user-journey gating, driven by the BPM Brand
 * Config "Page Flow" admin UI (Brand Config → Page Flow tab). That tab
 * stores an ordered list of step slugs per competition; we map each
 * theme page template onto its BPM step slug, then enforce on
 * `template_redirect` that the IMMEDIATE preceding step in the
 * admin-configured flow has been completed.
 *
 * Re-ordering or omitting a step in the BPM admin instantly reshapes the
 * gating — no theme code change required.
 *
 * Outcome pages (prize-paid / payout-failed / daily-limit)
 * aren't part of the BPM flow; they're gated by a dedicated outcome flag
 * set by the relevant submission hook.
 *
 * Setting an outcome clears the progression flags so the player can't loop
 * back to /game/ via refresh; the outcome flag itself stays so the outcome
 * page is safe to refresh.
 */
defined( 'ABSPATH' ) || exit;

// Outcome flags — Pepsi-specific terminal states (not part of BPM's flow).
const PF_FLOW_OUTCOME_WINNER_CASH  = 'winner_cash';
const PF_FLOW_OUTCOME_WINNER_MERCH = 'winner_merch';
const PF_FLOW_OUTCOME_NON_WINNER   = 'non_winner';
const PF_FLOW_OUTCOME_DAILY_LIMIT  = 'daily_limit';

const PF_FLOW_SESSION_NS = 'pf_flow';

/**
 * Page-template → BPM flow step slug. Null means "not in the BPM flow" —
 * those pages are gated by the outcome flag instead.
 */
function pf_flow_template_steps(): array {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	$map = [
		'page-age-gate.php'        => 'age-gate',
		'page-photo.php'           => 'photo',
		'page-how-to-play.php'     => 'how-to-play',
		'page-game.php'            => 'game',
		// Pepsi Xmas Drop names the winner page "congratulations" in BPM Page
		// Flow. If your BPM flow uses the default 'winner' slug instead,
		// change the value below to 'winner' to match.
		'page-congratulations.php' => 'congratulations',
		// `non-winner` and `thanks` are branch/terminal pages, not part of
		// the strict forward funnel. Setting `null` here makes flow-guard
		// gate them by outcome / game-step state instead of by "immediate
		// previous step in BPM flow" — that strict check would deny a
		// non-winner player access to /non-winner/ (because they never
		// passed through /congratulations/) and the same for /thanks/.
		'page-non-winner.php'      => null,
		'page-thanks.php'          => null,
		'page-prize-paid.php'      => null,
		'page-payout-failed.php'   => null,
		'page-daily-limit.php'     => null,
	];
	// Any other step dragged into the BPM Page Flow is gated by convention:
	// step `foo` ↔ template `page-foo.php` (the same rule BPM uses to build
	// its step list from theme templates). New steps need no entry here.
	foreach ( pf_flow_get_configured_steps() as $step ) {
		$tpl = 'page-' . sanitize_title( $step ) . '.php';
		if ( ! array_key_exists( $tpl, $map ) && ! in_array( $step, $map, true ) ) {
			$map[ $tpl ] = $step;
		}
	}
	if ( class_exists( 'BPM_Brand_Config' ) ) {
		$cached = $map; // cache only once BPM's flow is readable
	}
	return $map;
}

function pf_flow_ensure_session(): void {
	if ( session_id() || headers_sent() ) {
		return;
	}
	// `@` so a transient warning (e.g. session lock contention, an unwritable
	// save_path) does NOT print to the response and commit headers — without
	// this, the very next wp_safe_redirect() fails with "Cannot modify header
	// information" and the player slips past the gate.
	@session_start();
}

function pf_flow_state(): array {
	pf_flow_ensure_session();
	return isset( $_SESSION[ PF_FLOW_SESSION_NS ] ) && is_array( $_SESSION[ PF_FLOW_SESSION_NS ] )
		? $_SESSION[ PF_FLOW_SESSION_NS ]
		: [];
}

function pf_flow_mark_step( string $step ): void {
	if ( $step === '' ) {
		return;
	}
	pf_flow_ensure_session();
	if ( ! isset( $_SESSION[ PF_FLOW_SESSION_NS ] ) || ! is_array( $_SESSION[ PF_FLOW_SESSION_NS ] ) ) {
		$_SESSION[ PF_FLOW_SESSION_NS ] = [];
	}
	if ( ! isset( $_SESSION[ PF_FLOW_SESSION_NS ]['steps'] ) || ! is_array( $_SESSION[ PF_FLOW_SESSION_NS ]['steps'] ) ) {
		$_SESSION[ PF_FLOW_SESSION_NS ]['steps'] = [];
	}
	$_SESSION[ PF_FLOW_SESSION_NS ]['steps'][ $step ] = 1;
}

function pf_flow_has_step( string $step ): bool {
	$state = pf_flow_state();
	return ! empty( $state['steps'][ $step ] );
}

/**
 * `tree_started` flag — set the moment the player makes their FIRST DROP
 * (in pf_api_drop_attempt, when the server registers piece 1). Loading
 * /game/ alone does NOT set it; only an actual drop does. Once set,
 * refreshing /game/ MUST NOT replay — flow-guard denies page-game.php and
 * bounces the player back through the age-gate, which is the only way to
 * clear the flag (pf_flow_reset() unsets the whole PF_FLOW_SESSION_NS
 * namespace, including this key).
 */
function pf_flow_mark_tree_started(): void {
	pf_flow_ensure_session();
	if ( ! isset( $_SESSION[ PF_FLOW_SESSION_NS ] ) || ! is_array( $_SESSION[ PF_FLOW_SESSION_NS ] ) ) {
		$_SESSION[ PF_FLOW_SESSION_NS ] = [];
	}
	$_SESSION[ PF_FLOW_SESSION_NS ]['tree_started'] = 1;
	error_log( 'PF FLOW tree_started = TRUE (session ' . session_id() . ')' );
}

function pf_flow_tree_was_started(): bool {
	$state = pf_flow_state();
	return ! empty( $state['tree_started'] );
}

function pf_flow_outcome(): string {
	$state = pf_flow_state();
	return isset( $state['outcome'] ) ? (string) $state['outcome'] : '';
}

function pf_flow_set_outcome( string $outcome ): void {
	pf_flow_ensure_session();
	if ( ! isset( $_SESSION[ PF_FLOW_SESSION_NS ] ) || ! is_array( $_SESSION[ PF_FLOW_SESSION_NS ] ) ) {
		$_SESSION[ PF_FLOW_SESSION_NS ] = [];
	}
	$_SESSION[ PF_FLOW_SESSION_NS ]['outcome'] = $outcome;
	// Reaching an outcome page (non-winner / daily-limit / winner-claim) is
	// PROOF the player started the tree — even if the JS drop/attempt call
	// was aborted by tunnel latency. Pin tree_started here so the funnel gate
	// fires reliably regardless of the network round-trip. error_log via
	// the helper documents the fact for debugging.
	$_SESSION[ PF_FLOW_SESSION_NS ]['tree_started'] = 1;
	error_log( sprintf(
		'PF FLOW set_outcome=%s — pinning tree_started=1 (session %s)',
		$outcome,
		session_id()
	) );
	// Clear progression — player can't loop back to /game/ on refresh.
	// Age-gate step stays so daily-limit / outcome pages keep working.
	$keep = [ 'age-gate' ];
	$steps = isset( $_SESSION[ PF_FLOW_SESSION_NS ]['steps'] ) && is_array( $_SESSION[ PF_FLOW_SESSION_NS ]['steps'] )
		? $_SESSION[ PF_FLOW_SESSION_NS ]['steps']
		: [];
	foreach ( array_keys( $steps ) as $step ) {
		if ( ! in_array( $step, $keep, true ) ) {
			unset( $steps[ $step ] );
		}
	}
	$_SESSION[ PF_FLOW_SESSION_NS ]['steps'] = $steps;
}

function pf_flow_reset(): void {
	pf_flow_ensure_session();
	unset( $_SESSION[ PF_FLOW_SESSION_NS ] );
	// Also clear the BPM legacy age-gate markers. The flow-guard fallback
	// at pf_flow_can_access_template trusts `britvic_age_gate` /
	// `britvic_entry_data` as proof the player passed the gate; if we leave
	// them set when resetting, /how-to-play/ would still be
	// reachable via the fallback. Clearing them here means a fresh entry
	// requires re-submitting the age-gate form to set both markers again.
	unset( $_SESSION['britvic_age_gate'] );
	unset( $_SESSION['britvic_entry_data'] );
	error_log( 'PF FLOW pf_flow_reset() — full wipe (session ' . session_id() . ')' );
}

/**
 * Hardcoded fallback sequence used when the BPM-configured flow doesn't
 * include a step we expect to be gated. Failing closed against this list
 * prevents direct deep-links from slipping through when the admin Page Flow
 * is unset or has been normalised away from these custom slugs.
 */
const PF_FLOW_FALLBACK_SEQUENCE = [
	'age-gate',
	'photo',
	'how-to-play',
	'game',
	'congratulations',
	'non-winner',
	'thanks',
];

/**
 * The Page Flow saved in BPM (Brand Config → Page Flow) — the single source
 * of truth for step order. Reads the ACTIVE competition (BPM → Competitions →
 * Activate, via pf_active_competition_id()) explicitly rather than
 * BPM_Brand_Config::get_flow_steps(), whose page-based competition lookup
 * returns 0 outside page context (and caches it), which silently swapped in
 * BPM's generic instant-win default flow. Falls back to any competition with
 * a saved flow; [] if none (callers then use PF_FLOW_FALLBACK_SEQUENCE).
 */
function pf_flow_get_configured_steps(): array {
	static $steps = null;
	if ( $steps !== null ) {
		return $steps;
	}
	// BPM loads its classes on `init` (priority 10). Don't cache before then,
	// or the rest of the request would run on the fallback sequence.
	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return [];
	}
	$steps = [];
	global $wpdb;
	$ids = [];
	// Saved active competition only — never a ?competition_id= URL param.
	$ids[] = pf_active_competition_id();
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id DESC" ) as $cid ) {
		$ids[] = (int) $cid;
	}
	// Read the stored option, not get_config(): that fills in the mechanic's
	// DEFAULT_FLOWS when nothing is saved, which isn't this promo's journey.
	foreach ( array_unique( array_filter( $ids ) ) as $cid ) {
		$stored = get_option( 'bpm_brand_' . $cid, [] ); // BPM_Brand_Config::OPTION_PREFIX (private)
		if ( ! empty( $stored['flow'] ) && is_array( $stored['flow'] ) ) {
			$steps = array_values( array_map( 'strval', $stored['flow'] ) );
			break;
		}
	}
	return $steps;
}

/**
 * Effective step order: the BPM flow, or the fallback sequence if BPM has
 * none. Steps with no published page are dropped so a stray slug in the
 * admin (e.g. BPM's generic `entry`) can't strand the player between pages.
 */
function pf_flow_steps(): array {
	static $flow = null;
	if ( $flow !== null ) {
		return $flow;
	}
	$source = pf_flow_get_configured_steps() ?: PF_FLOW_FALLBACK_SEQUENCE;
	$steps  = [];
	foreach ( $source as $step ) {
		if ( pf_flow_step_url( $step ) !== '' ) {
			$steps[] = $step;
		}
	}
	if ( class_exists( 'BPM_Brand_Config' ) ) {
		$flow = $steps; // cache only once BPM's flow is readable
	}
	return $steps;
}

/** Page template for a flow step (explicit map first, then `page-{step}.php`). */
function pf_flow_step_template( string $step ): string {
	$tpl = array_search( $step, pf_flow_template_steps(), true );
	return $tpl !== false ? (string) $tpl : 'page-' . sanitize_title( $step ) . '.php';
}

/** Published URL for a flow step, or '' if no page uses its template. */
function pf_flow_step_url( string $step ): string {
	static $cache = [];
	if ( ! array_key_exists( $step, $cache ) ) {
		$cache[ $step ] = function_exists( 'pepsi_get_page_url_by_template' )
			? pepsi_get_page_url_by_template( pf_flow_step_template( $step ) )
			: '';
	}
	return $cache[ $step ];
}

/**
 * URL of the step after $step in the BPM flow — what every form confirmation
 * and "next" button uses, so re-ordering the Page Flow re-routes the journey.
 * '' if $step isn't in the flow or is last.
 */
function pf_flow_next_url( string $step ): string {
	$flow = pf_flow_steps();
	$idx  = array_search( $step, $flow, true );
	if ( $idx === false || ! isset( $flow[ $idx + 1 ] ) ) {
		return '';
	}
	return pf_flow_step_url( $flow[ $idx + 1 ] );
}

/**
 * Pre-game funnel steps (after the age gate, before `game`) — e.g. photo,
 * how-to-play. These are one-way and locked once the tree is started.
 */
function pf_flow_funnel_steps(): array {
	$flow = pf_flow_steps();
	$game = array_search( 'game', $flow, true );
	if ( $game === false ) {
		return [];
	}
	return array_values( array_diff( array_slice( $flow, 0, $game ), [ 'age-gate' ] ) );
}

/** Has the player completed any step that comes after $step in the flow? */
function pf_flow_has_later_step( string $step ): bool {
	$flow = pf_flow_steps();
	$idx  = array_search( $step, $flow, true );
	if ( $idx === false ) {
		return false;
	}
	foreach ( array_slice( $flow, $idx + 1 ) as $later ) {
		if ( pf_flow_has_step( $later ) ) {
			return true;
		}
	}
	return false;
}

/**
 * BPM form key for a step: `age-gate` → `age_gate`, `photo` → `photo`.
 * A step with a mapped form is completed by submitting it; one without is
 * completed by viewing it.
 */
function pf_flow_step_form_id( string $step ): int {
	return pf_resolve_gf_id( str_replace( '-', '_', $step ) );
}

/**
 * Step that must already be marked before the given step can be entered.
 *
 * Resolves position in the BPM-configured flow first; if the step isn't
 * present there, falls back to PF_FLOW_FALLBACK_SEQUENCE so we never
 * accidentally treat a gated page as "first step / open access" just
 * because the BPM admin hasn't been wired up for this promo's custom
 * slugs.
 */
function pf_flow_previous_step( string $step ): string {
	foreach ( [ pf_flow_steps(), PF_FLOW_FALLBACK_SEQUENCE ] as $flow ) {
		$idx = array_search( $step, $flow, true );
		if ( $idx === false ) {
			continue;
		}
		if ( $idx === 0 ) {
			return ''; // genuinely the first step
		}
		return (string) $flow[ $idx - 1 ];
	}
	// Step isn't in either flow — fail closed by requiring age-gate at minimum.
	return 'age-gate';
}

/**
 * Can the current request access the given template? True if it's open
 * (age-gate, or not gated), the prior BPM step is marked, an outcome
 * matches (for outcome pages), or a legacy session marker is present.
 */
function pf_flow_can_access_template( string $template ): bool {
	$map = pf_flow_template_steps();
	if ( ! array_key_exists( $template, $map ) ) {
		return true; // unknown template — not gated
	}
	$step = $map[ $template ];

	// Step removed from the BPM Page Flow → its page is switched off.
	if ( $step !== null && $step !== 'age-gate' && pf_flow_get_configured_steps()
		&& ! in_array( $step, pf_flow_get_configured_steps(), true ) ) {
		error_log( sprintf( 'PF FLOW guard: %s denied — step "%s" is not in the BPM Page Flow.', $template, $step ) );
		return false;
	}

	$is_funnel = $step !== null && in_array( $step, pf_flow_funnel_steps(), true );

	// Once the player has started the tree, the entire pre-game funnel is
	// off-limits — /game/ and every funnel step in the BPM flow (photo,
	// how-to-play, …) bounce to the age-gate, which is the only way back in
	// (pf_flow_reset on the age-gate template clears tree_started).
	if ( ( $template === 'page-game.php' || $is_funnel ) && pf_flow_tree_was_started() ) {
		error_log( sprintf( 'PF FLOW guard: %s access denied — tree_started is set; bouncing to age-gate.', $template ) );
		return false;
	}

	// Refresh-and-replay guard: /game/ marks the 'game' step the first time
	// it renders (via the template_redirect step-marker hook + REST
	// session/start). On a refresh that step is ALREADY in the session when
	// this gate runs at priority 1 (before the marker at priority 10), so
	// it's a reliable "this isn't your first /game/ load" signal. Player
	// gets ONE attempt per age-gate entry — refresh bounces to the age-gate.
	if ( $template === 'page-game.php' && pf_flow_has_step( 'game' ) ) {
		error_log( 'PF FLOW guard: /game/ refresh denied — already loaded once this session; bouncing to age-gate.' );
		return false;
	}

	// One-way funnel: once any later step in the BPM flow is marked, Back to
	// an earlier funnel page is blocked. Marks are written on submit (form
	// steps) or render (view steps), so they're reliable proof of forward
	// progression. Only the age-gate (via pf_flow_reset) reopens the funnel.
	if ( $is_funnel && pf_flow_has_later_step( $step ) ) {
		error_log( sprintf( 'PF FLOW guard: %s access denied — already progressed past it; bouncing to age-gate.', $template ) );
		return false;
	}

	// Outcome pages.
	if ( $step === null ) {
		$outcome = pf_flow_outcome();
		switch ( $template ) {
			case 'page-prize-paid.php':
			case 'page-payout-failed.php':
				return $outcome === PF_FLOW_OUTCOME_WINNER_CASH;
			case 'page-non-winner.php':
				// Branch endpoint — losing player jumps here directly from /game/.
				return pf_flow_has_step( 'game' )
					|| $outcome === PF_FLOW_OUTCOME_NON_WINNER;
			case 'page-thanks.php':
				// Terminal — reachable from winner-claim (any outcome flag) or
				// directly after a non-winning game.
				return $outcome !== ''
					|| pf_flow_has_step( 'game' );
			case 'page-daily-limit.php':
				return pf_flow_has_step( 'age-gate' )
					|| $outcome === PF_FLOW_OUTCOME_DAILY_LIMIT
					|| ! empty( $_SESSION['britvic_age_gate'] );
		}
		return false;
	}

	// Forced-cash debug always allowed on congrats so the team can preview.
	// pf_get_forced_cash_entry_id() reads $_REQUEST first then $_SESSION,
	// so this bypass survives GF's form-action query-string stripping on
	// the subsequent POST to /congratulations/.
	if ( $template === 'page-congratulations.php'
		&& function_exists( 'pf_get_forced_cash_entry_id' )
		&& pf_get_forced_cash_entry_id() > 0 ) {
		return true;
	}

	$prev = pf_flow_previous_step( $step );
	if ( $prev === '' ) {
		return true; // first step in the BPM flow — open access
	}
	if ( pf_flow_has_step( $prev ) ) {
		return true;
	}
	// Legacy fallback: BPM sets britvic_age_gate / britvic_entry_data once
	// the age gate is past. Honour those so existing sessions keep working.
	if ( $prev === 'age-gate' && ( ! empty( $_SESSION['britvic_age_gate'] ) || ! empty( $_SESSION['britvic_entry_data'] ) ) ) {
		return true;
	}
	// Referer-based forward-progress fallback. When a form submission redirects
	// to the next page in the funnel, the `gform_after_submission` hook that
	// marks the just-completed step has to fire AND its session write has to
	// land before the redirect target loads — that chain occasionally breaks
	// (form id mis-resolution, session lock contention, etc.) and locks out a
	// legitimate player. If the request referrer matches the URL of the
	// previous step's page, treat that as proof of forward progress, mark the
	// step retroactively, and let the request through. Fresh visitors with no
	// referrer (or a referrer outside the funnel) still get bounced.
	if ( pf_flow_referer_matches_step( $prev ) ) {
		// Referer alone is NOT enough — it survives browser back-nav across
		// a pf_flow_reset(), which would let the player back into the funnel
		// after they've completed a journey. Require fresh proof that they
		// passed the age-gate in THIS session (pf_flow has age-gate step OR
		// BPM's britvic_age_gate marker is set). Both are cleared by
		// pf_flow_reset(), so after a reset the only way back in is to
		// re-submit the age-gate form (which re-sets both).
		$age_gate_passed = pf_flow_has_step( 'age-gate' )
			|| ! empty( $_SESSION['britvic_age_gate'] )
			|| ! empty( $_SESSION['britvic_entry_data'] );
		if ( ! $age_gate_passed ) {
			error_log( sprintf(
				'PF FLOW guard: %s referer-match denied — no fresh age-gate proof (session %s).',
				$template, session_id()
			) );
			return false;
		}
		pf_flow_mark_step( $prev );
		return true;
	}
	return false;
}

/**
 * Map a BPM step slug back to the page-template that represents it, then
 * check whether the current request's referer URL points at that template's
 * published page on this host.
 */
function pf_flow_referer_matches_step( string $step ): bool {
	$referer = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
	if ( $referer === '' ) {
		return false;
	}
	// Reject cross-origin referers up front.
	$ref_host = strtolower( (string) parse_url( $referer, PHP_URL_HOST ) );
	$self_host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
	if ( $ref_host !== '' && $self_host !== '' && $ref_host !== $self_host ) {
		return false;
	}
	$ref_path = (string) parse_url( $referer, PHP_URL_PATH );
	if ( $ref_path === '' ) {
		return false;
	}

	// Reverse-lookup: which template is mapped to this step?
	$template = '';
	foreach ( pf_flow_template_steps() as $tpl => $tpl_step ) {
		if ( $tpl_step === $step ) {
			$template = $tpl;
			break;
		}
	}
	if ( $template === '' ) {
		return false;
	}
	$expected_url  = function_exists( 'pepsi_get_page_url_by_template' )
		? pepsi_get_page_url_by_template( $template )
		: '';
	$expected_path = $expected_url !== '' ? (string) parse_url( $expected_url, PHP_URL_PATH ) : '';
	if ( $expected_path === '' ) {
		// Fall back to the convention `/<step-slug>/`.
		$expected_path = '/' . trim( $step, '/' ) . '/';
	}
	return rtrim( $ref_path, '/' ) === rtrim( $expected_path, '/' );
}

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}

	$current_template = '';
	foreach ( array_keys( pf_flow_template_steps() ) as $candidate ) {
		if ( is_page_template( $candidate ) ) {
			$current_template = $candidate;
			break;
		}
	}
	if ( $current_template === '' ) {
		return;
	}

	// No-store on /game/, /congratulations/ and /payout-failed/. Each is a
	// page where bfcache-replay is a real risk — the browser would restore
	// the page on Back without hitting the server, and flow-guard's gating
	// (tree_started, outcome cleared on set_outcome, etc.) would never run.
	// Concretely: without no-store on /congratulations/, a player who is
	// redirected from /congratulations/ → /payout-failed/ via a CoP-mismatch
	// can press Back and the browser restores the (now-stale) winner form
	// from bfcache, letting them re-submit it. /payout-failed/ itself gets
	// no-store too so the exit-trap's pageshow handler reliably fires on
	// any bfcache restore there.
	//
	// /how-to-play/ is left cacheable — the one-way step rules already stop
	// a bfcache-restored copy from progressing anywhere it shouldn't.
	$no_store_templates = [
		'page-game.php',
		'page-congratulations.php',
		'page-payout-failed.php',
	];
	if ( in_array( $current_template, $no_store_templates, true ) ) {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private', true );
	}

	if ( pf_flow_can_access_template( $current_template ) ) {
		return;
	}

	// Debug preview bypass: ?pf_preview_prize_type=cash|merch skips the flow
	// guard so the page can be viewed directly. Only active in WP_DEBUG + non-production.
	if (
		defined( 'WP_DEBUG' ) && WP_DEBUG
		&& wp_get_environment_type() !== 'production'
		&& isset( $_GET['pf_preview_prize_type'] )
	) {
		return;
	}

	$age_gate_url = function_exists( 'pepsi_get_page_url_by_template' )
		? pepsi_get_page_url_by_template( 'page-age-gate.php' )
		: '';
	if ( $age_gate_url === '' ) {
		$age_gate_url = home_url( '/' );
	}
	wp_safe_redirect( $age_gate_url );
	exit;
}, 1 );

// ── Forward progression hooks ────────────────────────────────────────────────

/**
 * Resolve a BPM-mapped form ID with a multi-competition fallback.
 *
 * BPM_Brand_Config::get_gf_id() depends on get_current_competition_id()
 * which returns 0 on non-page-mapped templates (e.g. our static
 * page-how-to-play template), so the per-page form-ID lookup silently fails
 * in those contexts. This helper scans every competition's brand config for
 * the requested key so the form-id resolves regardless of current-comp state.
 */
function pf_resolve_gf_id( string $form_key ): int {
	if ( function_exists( 'bpm_get_gf_id' ) ) {
		$direct = (int) bpm_get_gf_id( $form_key );
		if ( $direct > 0 ) {
			return $direct;
		}
	}
	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return 0;
	}
	global $wpdb;
	$comp_ids = (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id DESC" );
	foreach ( $comp_ids as $cid ) {
		$config = BPM_Brand_Config::get_config( (int) $cid );
		$found  = isset( $config['gf_ids'][ $form_key ] ) ? (int) $config['gf_ids'][ $form_key ] : 0;
		if ( $found > 0 ) {
			return $found;
		}
	}
	return 0;
}

add_action( 'init', function () {
	// Age-gate final submit → mark `age-gate`.
	$age_gate_form_id = function_exists( 'pepsi_get_age_gate_form_id' ) ? (int) pepsi_get_age_gate_form_id() : 0;
	if ( $age_gate_form_id <= 0 ) {
		$age_gate_form_id = pf_resolve_gf_id( 'age_gate' );
	}
	if ( $age_gate_form_id > 0 ) {
		add_action( "gform_after_submission_{$age_gate_form_id}", function ( $entry, $form ) {
			$fid         = (int) $form['id'];
			$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
			$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
			if ( $source_page > 0 && $target_page === 0 ) {
				pf_flow_mark_step( 'age-gate' );
			}
		}, 5, 2 );
	}

	// Funnel form steps (e.g. photo → BPM form key `photo`): submitting marks
	// the step, and the confirmation goes to the next step in the BPM flow.
	// Priority 100 runs after BPM's own confirmation filter (99); the GF
	// confirmation setting is only the fallback when no next page resolves.
	foreach ( pf_flow_funnel_steps() as $funnel_step ) {
		$step_form_id = pf_flow_step_form_id( $funnel_step );
		if ( $step_form_id <= 0 || $step_form_id === $age_gate_form_id ) {
			continue;
		}
		add_action( "gform_after_submission_{$step_form_id}", function () use ( $funnel_step ) {
			pf_flow_mark_step( $funnel_step );
		}, 5, 0 );
		add_filter( "gform_confirmation_{$step_form_id}", function ( $confirmation ) use ( $funnel_step ) {
			$next = pf_flow_next_url( $funnel_step );
			return $next !== '' ? [ 'redirect' => $next ] : $confirmation;
		}, 100, 1 );
	}

	// Claim (congratulations) form → outcome based on prize_type. The Stripe
	// payout / merch claim wiring lives in stripe-payout.php at priority 10;
	// we run at 5 so the outcome flag is set before that handler fires.
	//
	// Form-id resolution order: BPM admin (winner_claim) first, then the
	// static PF_CLAIM_FORM_ID / pf_claim_form_id helper. Hook registers on
	// every candidate id so the outcome setter still fires when the constant
	// (local: 3) and the BPM-admin value (prod: 2) disagree.
	$claim_handler = function ( $entry, $form ) {
		$claim_key = sanitize_text_field( $_POST['_pf_claim_key'] ?? '' );
		if ( $claim_key === '' ) {
			return;
		}
		$pending = get_transient( 'pf_pending_claim_' . $claim_key );
		if ( ! is_array( $pending ) ) {
			return;
		}
		$prize_type = strtolower( (string) ( $pending['prize_type'] ?? '' ) );
		pf_flow_set_outcome(
			pf_is_cash_prize_type( $prize_type )
				? PF_FLOW_OUTCOME_WINNER_CASH
				: PF_FLOW_OUTCOME_WINNER_MERCH
		);
	};

	$claim_form_ids = [];
	$bpm_claim_id   = function_exists( 'pf_resolve_gf_id' ) ? pf_resolve_gf_id( 'winner_claim' ) : 0;
	if ( $bpm_claim_id > 0 ) {
		$claim_form_ids[] = $bpm_claim_id;
	}
	$static_claim_id = function_exists( 'pf_claim_form_id' )
		? (int) pf_claim_form_id()
		: ( defined( 'PF_CLAIM_FORM_ID' ) ? (int) PF_CLAIM_FORM_ID : 0 );
	if ( $static_claim_id > 0 ) {
		$claim_form_ids[] = $static_claim_id;
	}
	foreach ( array_unique( $claim_form_ids ) as $cid ) {
		add_action( "gform_after_submission_{$cid}", $claim_handler, 5, 2 );
	}
} );

// ── Admin: hide outcome-only templates from the BPM Page Flow Builder ────────
//
// Pages that flow-guard gates by outcome flag (prize-paid, payout-failed,
// daily-limit) don't belong in the sequential BPM Page Flow — their position
// in that ordered list has no effect, so showing them in the admin only
// invites confusion. The new `bpm_brand_config_page_steps` filter on
// BPM_Brand_Config::get_page_steps() lets us prune them.
add_filter( 'bpm_brand_config_page_steps', function ( array $steps ): array {
	$hidden = [
		'prize-paid',
		'payout-failed',
		'daily-limit',
	];
	foreach ( $hidden as $slug ) {
		unset( $steps[ $slug ] );
	}
	return $steps;
} );

// ── Admin: age-gate step 2 assets (Brand Config → Page Assets) ──────────────
//
// The age gate's contact step (name/email, GF page 2) needs its own background.
// Registering `age-gate-contact` as a page step gives it a full Page Assets
// panel — bg_deco_image now, and bg_deco_image_alt stays free for a real
// alternative route. It's assets only: it isn't a template and must never be
// dragged into the Page Flow. Read by page-age-gate.php.
add_filter( 'bpm_brand_config_page_steps', function ( array $steps ): array {
	$steps['age-gate-contact'] = 'Age Gate — Step 2 (assets only, not a flow step)';
	return $steps;
} );

// Explicit terminal reset. Back from a terminal page like /prize-paid/ must
// not return the player to the (single-use, now-stale) Stripe onboarding
// page — the client traps Back and sends them to /?pf_reset=1. Here we wipe
// the funnel state + session identity so the journey can't be resumed, then
// bounce to a clean home URL (no param, so it can't loop on a further Back).
add_action( 'init', function () {
	if ( is_admin() || wp_doing_ajax() || ! isset( $_GET['pf_reset'] ) ) {
		return;
	}
	if ( function_exists( 'pf_flow_reset' ) ) {
		pf_flow_reset();
	}
	if ( ! session_id() && ! headers_sent() ) {
		session_start();
	}
	// pf_player_email intentionally excluded — see comment in
	// pf_terminal_session_wipe(). It's the daily-limit ledger key and must
	// persist across the ?pf_reset=1 fresh-start boundary so the per-day cap
	// keeps counting against the player's email.
	foreach ( [
		'pf_force_cash_entry', 'pf_player_first_name',
		'pf_player_last_name', 'pf_rest_nonce', 'pf_rest_nonce_ts',
		'pf_completion_token',
	] as $pf_reset_key ) {
		unset( $_SESSION[ $pf_reset_key ] );
	}
	// Always send the player back through the entry route (the age-gate),
	// even if they've recently played — pf_flow_reset above clears the
	// played/step state so the gate itself is reachable.
	$reset_target = function_exists( 'pepsi_get_page_url_by_template' )
		? pepsi_get_page_url_by_template( 'page-age-gate.php' )
		: '';
	if ( $reset_target === '' ) {
		$reset_target = home_url( '/' );
	}
	wp_safe_redirect( $reset_target );
	exit;
}, 0 );

// ULTRA-EARLY funnel guard — runs on `init`, BEFORE template machinery,
// so it cannot be missed even if WP's template resolution / is_page_template
// / the canonical-redirect machinery doesn't fire. Priority 11: just after
// BPM loads its classes (init 10), so the locked paths come from its flow. Path-based: any request
// whose URL path matches one of the locked funnel slugs is checked against
// the tree_started flag. Once the tree is started, the entry route (age-gate)
// is the only way back into ANY pre-game funnel page.
add_action( 'init', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	$path = strtolower( trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' ) );
	// Locked paths = /game/ + every pre-game funnel step in the BPM flow,
	// keyed by their published page path.
	$locked = [];
	foreach ( array_merge( pf_flow_funnel_steps(), [ 'game' ] ) as $locked_step ) {
		$url      = pf_flow_step_url( $locked_step );
		$step_path = $url !== '' ? strtolower( trim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) ) : $locked_step;
		$locked[ $step_path ] = $locked_step;
	}
	if ( ! isset( $locked[ $path ] ) ) {
		return;
	}
	$step = $locked[ $path ];
	pf_flow_ensure_session();
	$started    = pf_flow_tree_was_started();
	$progressed = $step === 'game'
		? pf_flow_has_step( 'game' )         // /game/ refresh
		: pf_flow_has_later_step( $step );   // Back to an earlier funnel page
	if ( ! $started && ! $progressed ) {
		return;
	}
	$age_gate_url = function_exists( 'pepsi_get_page_url_by_template' )
		? pepsi_get_page_url_by_template( 'page-age-gate.php' )
		: '';
	if ( $age_gate_url === '' ) {
		$age_gate_url = home_url( '/' );
	}
	nocache_headers();
	wp_safe_redirect( $age_gate_url );
	exit;
}, 11 );

// How-to-play visit → mark `how-to-play` so the next page (game) opens.
// Non-winner / daily-limit visits set the outcome on render.
// Age-gate visit → reset the funnel state so a returning player can't deep-link
// to a page they reached on a previous run in the same browser session.
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	if ( is_page_template( 'page-age-gate.php' ) ) {
		// Fresh attempt — wipe in-progress flags and any prior outcome so the
		// player has to re-submit the age gate to unlock /how-to-play/, /game/, etc.
		// The daily play-count in wp_bpm_daily_plays is unaffected.
		pf_flow_reset();

		// Explicit "Cancel" entry (e.g. from the Stripe verify page) — do a
		// fuller wipe so the next visit really is a clean slate: also drop the
		// forced-cash latch, the player-email association, and the anonymous
		// REST nonce. Without this, the cancelled player would still be
		// recognised as "the same person mid-claim" on their next attempt.
		if ( isset( $_REQUEST['pf_cancel'] ) ) {
			if ( ! session_id() && ! headers_sent() ) {
				session_start();
			}
			// pf_player_email intentionally excluded — see comment in
			// pf_terminal_session_wipe(). The daily-limit ledger must
			// persist across the Stripe-cancel fresh-start.
			foreach ( [
				'pf_force_cash_entry',
				'pf_player_first_name',
				'pf_player_last_name',
				'pf_rest_nonce',
				'pf_rest_nonce_ts',
			] as $key ) {
				unset( $_SESSION[ $key ] );
			}
		}
	}
	// View-only funnel steps (no BPM form mapped, e.g. how-to-play) complete
	// on render; form steps (e.g. photo) complete on submit — see the
	// progression hooks above — so opening /photo/ can't skip the upload.
	foreach ( pf_flow_funnel_steps() as $funnel_step ) {
		if ( is_page_template( pf_flow_step_template( $funnel_step ) ) && pf_flow_step_form_id( $funnel_step ) <= 0 ) {
			pf_flow_mark_step( $funnel_step );
		}
	}
	if ( is_page_template( 'page-game.php' ) ) {
		pf_flow_mark_step( 'game' );
	}
	if ( is_page_template( 'page-non-winner.php' ) ) {
		pf_flow_set_outcome( PF_FLOW_OUTCOME_NON_WINNER );
	}
	if ( is_page_template( 'page-daily-limit.php' ) ) {
		pf_flow_set_outcome( PF_FLOW_OUTCOME_DAILY_LIMIT );
	}
}, 2 );
