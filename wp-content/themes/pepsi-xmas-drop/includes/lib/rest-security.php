<?php
/**
 * REST + form-flow hardening.
 *
 * Layered defences applied to the game endpoints:
 *
 *   1. Per-IP rate limit on /session/start    — caps token-minting flood.
 *   2. Same-origin / referer check             — drops headerless bots.
 *   3. Anonymous nonce                         — proves the request originated
 *                                                from a real /game/ render.
 *   4. Removal of /stripe/webhook GET          — see api.php; no probe vector.
 *   5. Daily-limit IP backstop                 — adds a per-IP fallback when
 *                                                the email session is missing.
 *
 * Game outcome itself was already server-authoritative (the prize slot is
 * picked by the server at flick time, not from anything the client sends), so
 * these protections are about denying access / quota — not about preventing
 * a fake "win" payload. Anonymous nonce is the strongest of the bunch because
 * minting requires actually loading /game/ in a real browser.
 */
defined( 'ABSPATH' ) || exit;

// ── Tunables (kept in code, not BPM admin, so they can't be relaxed via UI). ─
const PF_REST_RATE_WINDOW   = HOUR_IN_SECONDS;  // sliding-window length
const PF_REST_RATE_LIMIT    = 30;               // session-starts per window per IP — bumped from 10; legitimate QA on prod (admin demos + dev devices on a NAT'd IP) was tripping the limit before the duplicate-session/start dedup landed
const PF_REST_DAILY_IP_HARDCAP = 60;            // safety net: per-IP plays/day — bumped in step with the per-hour limit
const PF_REST_NONCE_TTL     = 6 * HOUR_IN_SECONDS;
const PF_REST_NONCE_HEADER  = 'x-pf-nonce';
const PF_REST_NONCE_SESSION = 'pf_rest_nonce';

// ── Client identification ────────────────────────────────────────────────────

/**
 * Best-effort client IP. Honours common reverse-proxy headers when WP is
 * behind one, but falls back to REMOTE_ADDR.
 */
function pf_client_ip(): string {
	$candidates = [];
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
	}
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$xff = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$candidates[] = trim( $xff[0] );
	}
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$candidates[] = (string) $_SERVER['REMOTE_ADDR'];
	}
	foreach ( $candidates as $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	return '0.0.0.0';
}

/**
 * Hashed IP — what gets stored in transient keys and the daily-IP backstop
 * so raw IPs never sit in `wp_options` / object cache.
 */
function pf_client_ip_hash(): string {
	return substr( hash( 'sha256', pf_client_ip() ), 0, 32 );
}

// ── Rate limit ────────────────────────────────────────────────────────────────

/**
 * Atomic rolling-window rate-limit check. Returns true when the caller is
 * within budget AND increments the counter; false when the budget is blown
 * for this window (caller should reject with 429).
 *
 * Keyed by ip_hash + bucket so cleanup is automatic (transient TTL expires).
 */
function pf_rate_limit_ok( string $bucket, int $limit, int $window_seconds ): bool {
	// Dev bypass — local play-throughs hit the per-IP cap quickly (every
	// /game/ load mints a session-start, and ten reload-test-play cycles
	// silently 429 everything that follows, breaking drop/attempt). The
	// cap is bot protection for production, not for the dev's own machine.
	if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'development' ) {
		return true;
	}
	$key   = 'pf_rate_' . $bucket . '_' . pf_client_ip_hash();
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return false;
	}
	set_transient( $key, $count + 1, $window_seconds );
	return true;
}

// ── Origin / Referer check ───────────────────────────────────────────────────

/**
 * Returns true when the request's Origin or Referer matches one of the
 * configured site hostnames (main + alt). Allows missing headers only on
 * non-browser callers we control (e.g. nothing here right now — Stripe has
 * its own dedicated permission check).
 */
function pf_request_is_same_origin(): bool {
	$origin   = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
	$referer  = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
	if ( $origin === '' && $referer === '' ) {
		return false; // a real browser sends at least one
	}
	$allowed = [
		strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ),
	];
	if ( defined( 'PF_ALT_HOST' ) && PF_ALT_HOST !== '' ) {
		$allowed[] = strtolower( (string) PF_ALT_HOST );
	}
	foreach ( [ $origin, $referer ] as $hdr ) {
		if ( $hdr === '' ) {
			continue;
		}
		$host = strtolower( (string) parse_url( $hdr, PHP_URL_HOST ) );
		if ( $host !== '' && in_array( $host, $allowed, true ) ) {
			return true;
		}
	}
	return false;
}

// ── Anonymous nonce ──────────────────────────────────────────────────────────

/**
 * Mint (or return existing) a per-session anonymous nonce. Issued when
 * /game/ is rendered so it can be localized into the JS bundle and replayed
 * on every game endpoint via the X-PF-Nonce header.
 */
function pf_mint_anon_nonce(): string {
	if ( function_exists( 'pf_flow_ensure_session' ) ) {
		pf_flow_ensure_session();
	} elseif ( ! session_id() && ! headers_sent() ) {
		session_start();
	}
	if ( empty( $_SESSION[ PF_REST_NONCE_SESSION ] ) ) {
		$_SESSION[ PF_REST_NONCE_SESSION ] = bin2hex( random_bytes( 16 ) );
		$_SESSION[ PF_REST_NONCE_SESSION . '_ts' ] = time();
	}
	return (string) $_SESSION[ PF_REST_NONCE_SESSION ];
}

function pf_verify_anon_nonce( WP_REST_Request $req ): bool {
	if ( function_exists( 'pf_flow_ensure_session' ) ) {
		pf_flow_ensure_session();
	}
	$expected = isset( $_SESSION[ PF_REST_NONCE_SESSION ] ) ? (string) $_SESSION[ PF_REST_NONCE_SESSION ] : '';
	$issued   = (int) ( $_SESSION[ PF_REST_NONCE_SESSION . '_ts' ] ?? 0 );
	if ( $expected === '' || $issued <= 0 ) {
		return false;
	}
	if ( ( time() - $issued ) > PF_REST_NONCE_TTL ) {
		// Expired — force a fresh mint on next /game/ render.
		unset( $_SESSION[ PF_REST_NONCE_SESSION ], $_SESSION[ PF_REST_NONCE_SESSION . '_ts' ] );
		return false;
	}
	$got = (string) $req->get_header( PF_REST_NONCE_HEADER );
	if ( $got === '' ) {
		return false;
	}
	return hash_equals( $expected, $got );
}

// ── Game-endpoint permission_callback ────────────────────────────────────────

/**
 * Combined gate for /session/start, /drop/attempt and
 * /session/complete. Order matters — cheapest reject first.
 */
function pf_rest_permission_game( WP_REST_Request $req ) {
	$route        = (string) $req->get_route();
	$origin       = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
	$referer      = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
	$nonce_header = (string) $req->get_header( PF_REST_NONCE_HEADER );
	$home_host    = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );

	if ( ! pf_request_is_same_origin() ) {
		error_log( sprintf(
			'PF FLOW REST DENIED (origin) route=%s home_host=%s origin=%s referer=%s',
			$route, $home_host, $origin, $referer
		) );
		return new WP_Error( 'pf_forbidden', 'Origin not allowed.', [ 'status' => 403 ] );
	}
	if ( ! pf_verify_anon_nonce( $req ) ) {
		if ( function_exists( 'pf_flow_ensure_session' ) ) {
			pf_flow_ensure_session();
		}
		$session_nonce = (string) ( $_SESSION[ PF_REST_NONCE_SESSION ] ?? '' );
		error_log( sprintf(
			'PF FLOW REST DENIED (nonce) route=%s session=%s sent_len=%d session_nonce_present=%s match=%s',
			$route,
			session_id(),
			strlen( $nonce_header ),
			$session_nonce !== '' ? 'yes' : 'no',
			( $session_nonce !== '' && $nonce_header !== '' && hash_equals( $session_nonce, $nonce_header ) ) ? 'yes' : 'no'
		) );
		return new WP_Error( 'pf_bad_nonce', 'Missing or expired nonce.', [ 'status' => 403 ] );
	}
	return true;
}

/**
 * Stricter gate for /session/start specifically — additionally rate-limits
 * by IP and enforces the per-IP daily-play hardcap (the email-keyed cap
 * already runs inside the handler; this is the bot backstop).
 */
function pf_rest_permission_session_start( WP_REST_Request $req ) {
	$base = pf_rest_permission_game( $req );
	if ( is_wp_error( $base ) ) {
		return $base;
	}
	if ( ! pf_rate_limit_ok( 'session_start', PF_REST_RATE_LIMIT, PF_REST_RATE_WINDOW ) ) {
		return new WP_Error( 'pf_rate_limited', 'Too many session starts. Try again later.', [ 'status' => 429 ] );
	}
	if ( ! pf_rate_limit_ok( 'session_daily', PF_REST_DAILY_IP_HARDCAP, DAY_IN_SECONDS ) ) {
		return new WP_Error( 'pf_rate_limited', 'Daily session cap reached for this network.', [ 'status' => 429 ] );
	}
	return true;
}

// ── Localize the nonce so /game/ can replay it on every fetch ────────────────

add_filter( 'pf_game_config_extra', function ( array $config ): array {
	$config['pf_nonce'] = pf_mint_anon_nonce();
	return $config;
}, 10 );

