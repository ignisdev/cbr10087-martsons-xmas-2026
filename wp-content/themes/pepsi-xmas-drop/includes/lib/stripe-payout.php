<?php
defined( 'ABSPATH' ) || exit;

/**
 * Returns the GF form ID used for prize claims.
 *
 * Resolution order:
 *   1. BPM admin → Brand Config → Form IDs → "Winner Claim Form" via
 *      pf_resolve_gf_id('winner_claim'). Source of truth so each environment
 *      sets its own id in the admin (local: 3, prod: 2) and the code adapts.
 *   2. PF_CLAIM_FORM_ID constant from wp-config (legacy, kept for back-compat).
 *   3. Fallback `3` so local dev still works without configuration.
 */
function pf_claim_form_id(): int {
	if ( function_exists( 'pf_resolve_gf_id' ) ) {
		$bpm = (int) pf_resolve_gf_id( 'winner_claim' );
		if ( $bpm > 0 ) {
			return $bpm;
		}
	}
	if ( defined( 'PF_CLAIM_FORM_ID' ) && (int) PF_CLAIM_FORM_ID > 0 ) {
		return (int) PF_CLAIM_FORM_ID;
	}
	return 0;
}

function pf_stripe_bootstrap(): bool {
	static $loaded = false;

	if ( $loaded ) {
		return class_exists( '\Stripe\StripeClient' ) && class_exists( '\Stripe\Webhook' );
	}

	$autoload = get_template_directory() . '/vendor/autoload.php';
	if ( file_exists( $autoload ) ) {
		require_once $autoload;
	}

	$loaded = true;

	return class_exists( '\Stripe\StripeClient' ) && class_exists( '\Stripe\Webhook' );
}

function pf_stripe_secret_key(): string {
	return defined( 'STRIPE_SECRET_KEY' ) ? trim( (string) STRIPE_SECRET_KEY ) : '';
}

/**
 * Restricted key used solely for v2 /money_management/outbound_payments.
 *
 * Stripe forces a restricted key (rk_…) on the OutboundPayment endpoint
 * ("A restricted key is required to access this API."), while the v2 account
 * + account_link endpoints behave normally under the platform sk_…. Splitting
 * the keys lets us keep account creation on sk_live and payout creation on a
 * scoped rk_live. Falls back to the platform secret key if unset so existing
 * deployments keep working.
 */
function pf_stripe_payouts_key(): string {
	$k = defined( 'STRIPE_PAYOUTS_KEY' ) ? trim( (string) STRIPE_PAYOUTS_KEY ) : '';
	return $k !== '' ? $k : pf_stripe_secret_key();
}

function pf_stripe_enabled(): bool {
	return pf_stripe_secret_key() !== '' && pf_stripe_bootstrap();
}

function pf_get_stripe_client(): ?\Stripe\StripeClient {
	if ( ! pf_stripe_enabled() ) {
		return null;
	}

	return new \Stripe\StripeClient( pf_stripe_secret_key() );
}

function pf_get_payout_method(): string {
	$method = defined( 'STRIPE_PAYOUT_METHOD' ) ? strtolower( (string) STRIPE_PAYOUT_METHOD ) : 'instant';
	return in_array( $method, [ 'instant', 'standard' ], true ) ? $method : 'instant';
}

/**
 * Cash prize tiers. The Xmas Drop has two cash tiers (20 × £250, 500 × £10)
 * and the BPM prize table has no amount column, so the tier is encoded in the
 * slot's prize_type (`cash_250`, `cash_10`) and resolved to pence here. Plain
 * `cash` falls back to STRIPE_PRIZE_AMOUNT_PENCE for legacy schedules.
 */
function pf_prize_amount_map(): array {
	return (array) apply_filters( 'pf_prize_amount_map', [
		'cash_250' => 25000,
		'cash_10'  => 1000,
	] );
}

/** Is this prize_type a Stripe-paid cash prize (`cash` or any `cash_*` tier)? */
function pf_is_cash_prize_type( string $prize_type ): bool {
	$t = strtolower( trim( $prize_type ) );
	return $t === 'cash' || str_starts_with( $t, 'cash_' );
}

/**
 * Payout amount in pence for a prize_type. Returns 0 for an unmapped `cash_*`
 * tier — callers MUST refuse to pay rather than guess an amount.
 */
function pf_prize_amount_pence_for_type( string $prize_type ): int {
	$t   = strtolower( trim( $prize_type ) );
	$map = pf_prize_amount_map();
	if ( isset( $map[ $t ] ) ) {
		return max( 0, (int) $map[ $t ] );
	}
	if ( $t === 'cash' ) {
		$amount = defined( 'STRIPE_PRIZE_AMOUNT_PENCE' ) ? (int) STRIPE_PRIZE_AMOUNT_PENCE : 0;
		return max( 0, $amount );
	}
	return 0;
}

/** Payout amount in pence for a BPM prize entry, looked up by its prize_type. */
function pf_get_prize_amount_pence( int $prize_entry_id ): int {
	if ( $prize_entry_id <= 0 ) {
		return 0;
	}
	global $wpdb;
	$type = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT prize_type FROM {$wpdb->prefix}bpm_prize_entries WHERE id = %d",
		$prize_entry_id
	) );
	return pf_prize_amount_pence_for_type( $type );
}

/**
 * Statement descriptor for instant-payout transactions. Shown on the winner's
 * bank statement (subject to receiving-bank formatting). Stripe requires:
 * 1–22 chars, at least one letter, no <>'"\. We strip anything outside
 * alphanumeric + space and truncate to 22. Returns '' if nothing usable —
 * caller MUST omit the field rather than send an empty string (Stripe rejects
 * empty descriptors). Dashboard-side "Payout statement descriptor" is NOT
 * auto-applied to manual Payout::create calls, so this must be passed
 * explicitly per payout.
 */
function pf_get_payout_descriptor(): string {
	$raw = defined( 'STRIPE_PAYOUT_DESCRIPTOR' ) ? (string) STRIPE_PAYOUT_DESCRIPTOR : '';
	$clean = preg_replace( '/[^A-Za-z0-9 ]/', '', $raw );
	$clean = trim( preg_replace( '/\s+/', ' ', (string) $clean ) );
	if ( $clean === '' || ! preg_match( '/[A-Za-z]/', $clean ) ) {
		return '';
	}
	return substr( $clean, 0, 22 );
}

// ─── Stripe v2 Global Payouts HTTP wrappers ─────────────────────────────────
//
// v2 endpoints aren't covered by the Stripe PHP SDK we have pinned, so we
// talk to them directly via wp_remote_request with the preview API version
// header. All v2 traffic flows through pf_stripe_v2_request() so logging,
// error shape, and auth stay consistent.

function pf_stripe_v2_request( string $method, string $path, array $body = [], array $extra_headers = [], string $key_override = '' ): array {
	$url     = 'https://api.stripe.com/v2' . $path;
	$key     = $key_override !== '' ? $key_override : pf_stripe_secret_key();
	$version = defined( 'STRIPE_API_VERSION' ) ? (string) STRIPE_API_VERSION : '2026-10-07.preview';
	if ( $key === '' ) {
		return [ 'ok' => false, 'status' => 0, 'data' => [], 'error' => [ 'message' => 'Stripe secret key not configured.' ] ];
	}
	$args = [
		'method'  => strtoupper( $method ),
		'headers' => array_merge( [
			'Authorization'  => 'Bearer ' . $key,
			'Content-Type'   => 'application/json',
			'Stripe-Version' => $version,
		], $extra_headers ),
		'timeout' => 30,
	];
	if ( ! empty( $body ) ) {
		$args['body'] = wp_json_encode( $body );
	}
	$resp = wp_remote_request( $url, $args );
	if ( is_wp_error( $resp ) ) {
		$msg = $resp->get_error_message();
		error_log( 'PF FLOW stripe_v2: transport error ' . $method . ' ' . $path . ' :: ' . $msg );
		return [ 'ok' => false, 'status' => 0, 'data' => [], 'error' => [ 'message' => $msg ] ];
	}
	$status = (int) wp_remote_retrieve_response_code( $resp );
	$raw    = (string) wp_remote_retrieve_body( $resp );
	$data   = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		$data = [];
	}
	$ok = ( $status >= 200 && $status < 300 );
	if ( ! $ok ) {
		error_log( sprintf( 'PF FLOW stripe_v2: %s %s -> %d :: %s', $method, $path, $status, substr( $raw, 0, 500 ) ) );
	}
	return [
		'ok'     => $ok,
		'status' => $status,
		'data'   => $data,
		'error'  => $ok ? null : ( $data['error'] ?? [ 'message' => $raw ] ),
	];
}

function pf_stripe_v2_post( string $path, array $body, array $extra_headers = [], string $key_override = '' ): array {
	return pf_stripe_v2_request( 'POST', $path, $body, $extra_headers, $key_override );
}

function pf_stripe_v2_get( string $path, array $query = [], array $extra_headers = [], string $key_override = '' ): array {
	if ( ! empty( $query ) ) {
		// Stripe expects PHP-style include[]=foo&include[]=bar arrays. Build the
		// query manually so include[]= keys aren't collapsed/encoded oddly.
		$parts = [];
		foreach ( $query as $k => $v ) {
			if ( is_array( $v ) ) {
				foreach ( $v as $item ) {
					$parts[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $item );
				}
			} else {
				$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
		}
		$qs    = implode( '&', $parts );
		$path .= ( strpos( $path, '?' ) === false ? '?' : '&' ) . $qs;
	}
	return pf_stripe_v2_request( 'GET', $path, [], $extra_headers, $key_override );
}

function pf_stripe_financial_account_id(): string {
	return defined( 'STRIPE_FINANCIAL_ACCOUNT_ID' ) ? trim( (string) STRIPE_FINANCIAL_ACCOUNT_ID ) : '';
}

function pf_prize_page_url( string $slug, array $params = [] ): string {
	$page = get_page_by_path( $slug );
	$url  = $page ? get_permalink( $page ) : home_url( '/' . trim( $slug, '/' ) . '/' );
	if ( ! empty( $params ) ) {
		$url = add_query_arg( $params, $url );
	}
	return $url;
}

/**
 * Redirect + exit that survives a leaked notice/warning emitted earlier in the
 * request (e.g. the BPM audit logger printing "Undefined array key" before we
 * redirect). Discards any buffered output so the Location header can still be
 * sent; if headers were already committed, falls back to a meta-refresh + JS
 * redirect so the winner still lands on the page instead of a stuck screen.
 */
function pf_safe_redirect_exit( string $url ): void {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	if ( ! headers_sent() ) {
		wp_safe_redirect( $url );
		exit;
	}
	printf(
		'<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=%1$s">'
		. '<script>location.replace(%2$s);</script>'
		. '<p>Redirecting&hellip; <a href="%1$s">continue</a>.</p>',
		esc_url( $url ),
		wp_json_encode( $url )
	);
	exit;
}

function pf_set_form_redirect( int $entry_id, string $url ): void {
	if ( $entry_id <= 0 || $url === '' ) {
		return;
	}
	set_transient( 'pf_gf_redirect_' . $entry_id, esc_url_raw( $url ), 1800 );
}

function pf_get_form_redirect( int $entry_id ): string {
	if ( $entry_id <= 0 ) {
		return '';
	}
	$key = 'pf_gf_redirect_' . $entry_id;
	$url = (string) get_transient( $key );
	if ( $url !== '' ) {
		delete_transient( $key );
	}
	return $url;
}

function pf_update_bpm_stripe_fields( int $prize_entry_id, array $fields ): bool {
	if ( $prize_entry_id <= 0 || empty( $fields ) ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$data  = [];
	$fmt   = [];

	foreach ( $fields as $column => $value ) {
		if ( function_exists( 'pf_bpm_prize_has_column' ) && ! pf_bpm_prize_has_column( $column ) ) {
			continue;
		}
		$data[ $column ] = $value;
		$fmt[]           = is_int( $value ) ? '%d' : '%s';
	}

	if ( empty( $data ) ) {
		return false;
	}

	$result = $wpdb->update( $table, $data, [ 'id' => $prize_entry_id ], $fmt, [ '%d' ] );
	return $result !== false;
}

function pf_log_stripe_event( int $competition_id, string $action, string $details, string $level = 'info', array $metadata = [] ): void {
	if ( class_exists( 'BPM_Audit_Logger' ) ) {
		BPM_Audit_Logger::log( $competition_id, $action, $details, $level, $metadata );
	}
}

function pf_create_stripe_claim_context( array $context ): string {
	$token = bin2hex( random_bytes( 16 ) );
	set_transient( 'pf_stripe_claim_' . $token, $context, DAY_IN_SECONDS );
	return $token;
}

function pf_get_stripe_claim_context( string $token ): ?array {
	if ( $token === '' ) {
		return null;
	}
	$data = get_transient( 'pf_stripe_claim_' . $token );
	return is_array( $data ) ? $data : null;
}

function pf_set_stripe_claim_context( string $token, array $context ): void {
	if ( $token === '' ) {
		return;
	}
	set_transient( 'pf_stripe_claim_' . $token, $context, DAY_IN_SECONDS );
}

/**
 * Destroy a Stripe claim-context transient. Called the moment a payout
 * succeeds so the matching Stripe onboarding URL becomes inert — if the
 * player Backs to Stripe and re-completes, their browser will be redirected
 * to our return_url with this token, but the transient is gone → no context
 * → no re-payout. Cuts the second-claim exploit at the source.
 */
function pf_delete_stripe_claim_context( string $token ): void {
	if ( $token !== '' ) {
		delete_transient( 'pf_stripe_claim_' . sanitize_text_field( $token ) );
	}
}

/**
 * Read the recorded Stripe payout id for a BPM prize entry, or '' if none.
 * Used as an idempotency guard: if a payout is already on record for this
 * entry, the return handler MUST NOT create another transfer/payout — even
 * if a stale Stripe onboarding URL somehow brings the player back through.
 */
function pf_get_bpm_stripe_payout_id( int $prize_entry_id ): string {
	if ( $prize_entry_id <= 0
		|| ! function_exists( 'pf_bpm_prize_has_column' )
		|| ! pf_bpm_prize_has_column( 'stripe_payout_id' ) ) {
		return '';
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT stripe_payout_id FROM $table WHERE id = %d LIMIT 1",
		$prize_entry_id
	) );
}

/**
 * Wipe player-IDENTITY session keys at the end of a terminal render
 * (page-prize-paid.php / page-non-winner.php). Runs on `shutdown` so it
 * does NOT disturb the current response.
 *
 * Crucially this does NOT call pf_flow_reset() — that would unset the whole
 * PF_FLOW_SESSION_NS namespace including the `tree_started` flag, which is
 * exactly what enforces the "no replay on Back" gate in flow-guard. The
 * full namespace reset is reserved for the age-gate visit (the entry
 * route), which is where re-entry SHOULD wipe everything.
 */
function pf_terminal_session_wipe(): void {
	if ( ! session_id() && ! headers_sent() ) {
		// shutdown may run after headers — guarded; session_start will no-op
		// in that case and the unset()s below still hit any active session.
		@session_start();
	}
	// NOTE: `pf_player_email` is intentionally excluded — it's the per-day
	// per-email key that BPM_Play_Limit uses to enforce the 3-plays-per-day
	// cap. The "one attempt per journey" reset belongs to flow / claim
	// identity, not to the daily-limit ledger; wiping it here broke the cap
	// because the next age-gate submit started a fresh count for the same
	// email (record() ran but the existing increments were inaccessible
	// until the same email was re-submitted and re-recorded in the new
	// session). Identity prefill still re-captures first/last name at the
	// next age-gate submission, so those are safe to wipe.
	foreach ( [
		'pf_force_cash_entry', 'pf_player_first_name',
		'pf_player_last_name', 'pf_rest_nonce', 'pf_rest_nonce_ts',
		'pf_completion_token', 'pf_stripe_claim',
	] as $k ) {
		unset( $_SESSION[ $k ] );
	}
}

/**
 * Public base URL substituted for dev/localhost hosts when talking to Stripe
 * (Stripe rejects localhost / *.local / *.test as invalid return/refresh/
 * business URLs). Point this at your public tunnel (ngrok / cloudflared) so a
 * LOCAL test gets the Stripe return back to THIS environment, not production.
 *
 * Resolution order (first non-empty wins), all overridable via the
 * `pf_stripe_dev_url` filter:
 *   1. PF_STRIPE_DEV_URL constant (wp-config / env — project convention)
 *   2. PF_STRIPE_DEV_URL env var  (getenv, if the container exposes it)
 *   3. `pf_stripe_dev_url` option (set via admin/CLI — survives ngrok rotation,
 *                                  no file edits; ideal for local Stripe tests)
 *   4. britvic.promo (final fallback — a valid public URL so Stripe accepts
 *      the request; set PF_STRIPE_DEV_URL to get returns back to THIS machine)
 *
 * Returns a normalised origin WITHOUT a trailing slash.
 */
/**
 * The explicitly-configured dev tunnel base, or '' when none is set. Distinct
 * from pf_stripe_dev_base_url() so callers (e.g. the tunnel shim) can tell a
 * real local override apart from the production-domain fallback.
 */
function pf_stripe_dev_url_override(): string {
	if ( defined( 'PF_STRIPE_DEV_URL' ) && (string) PF_STRIPE_DEV_URL !== '' ) {
		return (string) PF_STRIPE_DEV_URL;
	}
	$env = getenv( 'PF_STRIPE_DEV_URL' );
	if ( $env !== false && (string) $env !== '' ) {
		return (string) $env;
	}
	return (string) get_option( 'pf_stripe_dev_url', '' );
}

function pf_stripe_dev_base_url(): string {
	$default = 'https://britvic.promo/';

	$base = pf_stripe_dev_url_override();
	if ( $base === '' ) {
		$base = $default;
	}

	return rtrim( (string) apply_filters( 'pf_stripe_dev_url', $base ), '/' );
}

/**
 * Dev-only tunnel shim. Stripe's onboarding redirects the winner back to the
 * tunnel host (ngrok / cloudflared). WordPress's stored siteurl/home is
 * `localhost`, so without this it canonical-redirects that inbound request
 * back to http(s)://localhost — and mis-derives the scheme behind the tunnel's
 * TLS — breaking the Stripe return/refresh leg.
 *
 * When (a) a real dev override is configured, (b) we're NOT production, and
 * (c) the request's Host actually IS the tunnel host, we: trust the proxied
 * HTTPS, make home/siteurl the tunnel origin for the request, and cancel the
 * canonical redirect. Triple-gated, so production and normal localhost
 * browsing are completely unaffected.
 */
function pf_stripe_tunnel_host(): string {
	$ovr = pf_stripe_dev_url_override();
	return $ovr === '' ? '' : strtolower( (string) parse_url( $ovr, PHP_URL_HOST ) );
}

function pf_request_is_via_stripe_tunnel(): bool {
	$tunnel = pf_stripe_tunnel_host();
	if ( $tunnel === '' ) {
		return false;
	}
	if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'production' ) {
		return false;
	}
	$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host ); // strip :port
	return $host !== '' && $host === $tunnel;
}

if ( pf_request_is_via_stripe_tunnel() ) {
	// ngrok/cloudflared terminate TLS and forward over http — trust the proxy
	// proto so is_ssl() is correct before any scheme-based redirect runs.
	if ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https'
		|| strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' ) ) === 'on' ) {
		$_SERVER['HTTPS'] = 'on';
	}
	$pf_tunnel_origin = 'https://' . pf_stripe_tunnel_host();
	add_filter( 'pre_option_home',    static fn() => $pf_tunnel_origin );
	add_filter( 'pre_option_siteurl', static fn() => $pf_tunnel_origin );
	// Don't bounce the tunnel request to the stored localhost canonical URL.
	add_filter( 'redirect_canonical', '__return_false' );

	// WP_CONTENT_URL is baked from the localhost siteurl in wp_initial_constants()
	// BEFORE this shim's pre_option_* filters exist, so content/theme/plugin
	// asset URLs still resolve to http(s)://localhost — unreachable over the
	// tunnel, so CSS/JS 404 and the page renders unstyled. Rewrite any asset
	// URL whose host is the pinned local host to the tunnel origin.
	$pf_local_host = defined( 'WP_HOME' ) && WP_HOME
		? strtolower( (string) parse_url( WP_HOME, PHP_URL_HOST ) )
		: 'localhost';
	$pf_rewrite = static function ( $url ) use ( $pf_tunnel_origin, $pf_local_host ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}
		$h = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( $h === '' || ( $h !== $pf_local_host && $h !== '127.0.0.1' && $h !== '::1' ) ) {
			return $url;
		}
		$path = (string) ( parse_url( $url, PHP_URL_PATH ) ?? '' );
		$q    = parse_url( $url, PHP_URL_QUERY );
		$f    = parse_url( $url, PHP_URL_FRAGMENT );
		return $pf_tunnel_origin . $path . ( $q ? '?' . $q : '' ) . ( $f ? '#' . $f : '' );
	};
	foreach ( [
		'content_url', 'plugins_url', 'theme_root_uri', 'includes_url',
		'stylesheet_directory_uri', 'template_directory_uri', 'stylesheet_uri',
		'style_loader_src', 'script_loader_src', 'wp_get_attachment_url',
	] as $pf_url_filter ) {
		add_filter( $pf_url_filter, $pf_rewrite, 20 );
	}
}

/**
 * Stripe rejects URLs whose host is localhost / 127.0.0.1 / *.local etc. with
 * "Not a valid URL". On dev we need to ship a publicly-resolvable host or the
 * accounts->create / accountLinks->create call blows up. This swaps the host
 * portion to pf_stripe_dev_base_url() while preserving path + query so the URL
 * still encodes the slug and claim token.
 */
function pf_stripe_public_url( string $url ): string {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$dev  = $host === 'localhost'
		|| $host === '127.0.0.1'
		|| $host === '::1'
		|| str_ends_with( $host, '.local' )
		|| str_ends_with( $host, '.test' )
		|| str_ends_with( $host, '.localhost' );
	if ( ! $dev ) {
		return $url;
	}
	$base = pf_stripe_dev_base_url();
	$path = (string) ( parse_url( $url, PHP_URL_PATH ) ?? '/' );
	$qs   = parse_url( $url, PHP_URL_QUERY );
	return $base . $path . ( $qs ? '?' . $qs : '' );
}

function pf_create_account_link( \Stripe\StripeClient $stripe, string $account_id, string $claim_token ): \Stripe\AccountLink {
	return $stripe->accountLinks->create( [
		'account'     => $account_id,
		'type'        => 'account_onboarding',
		'refresh_url' => pf_stripe_public_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) ),
		'return_url'  => pf_stripe_public_url( pf_prize_page_url( 'stripe-return',  [ 'pf_claim' => $claim_token ] ) ),
	] );
}

// wp_safe_redirect() only permits the site host. The Stripe-refresh path
// redirects the winner to a freshly-generated connect.stripe.com onboarding
// link, so whitelist Stripe's redirect hosts — otherwise wp_safe_redirect()
// rejects it and bounces the winner to wp-admin instead of Stripe.
add_filter( 'allowed_redirect_hosts', static function ( $hosts ) {
	$hosts   = is_array( $hosts ) ? $hosts : [];
	$hosts[] = 'connect.stripe.com';
	$hosts[] = 'stripe.com';
	return $hosts;
} );

function pf_get_claim_name( array $entry ): array {
	// GF name fields use sub-keys: X.3 = first name, X.6 = last name.
	// Find the first field whose .3 sub-key has a value and pair it with .6.
	$first = '';
	$last  = '';
	foreach ( $entry as $key => $value ) {
		if ( ! is_string( $key ) || ! str_ends_with( $key, '.3' ) ) {
			continue;
		}
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			continue;
		}
		$candidate_first = sanitize_text_field( $value );
		$base_id         = substr( $key, 0, -2 );
		$candidate_last  = sanitize_text_field( (string) ( $entry[ $base_id . '.6' ] ?? '' ) );
		if ( $candidate_first !== '' ) {
			$first = $candidate_first;
			$last  = $candidate_last;
			break;
		}
	}
	$full = trim( $first . ' ' . $last );
	return [ $first, $last, $full ];
}

function pf_extract_stripe_phone( array $entry ): string {
	// Scan top-level entry values for a value that parses as a UK / E.164 phone.
	foreach ( $entry as $key => $value ) {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			continue;
		}
		if ( str_contains( (string) $key, '.' ) ) {
			continue;
		}
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			continue;
		}
		// Skip obvious non-phone values (emails, names without digits).
		if ( str_contains( $value, '@' ) ) {
			continue;
		}
		$clean = preg_replace( '/[^0-9+]/', '', $value );
		if ( ! is_string( $clean ) || strlen( $clean ) < 8 ) {
			continue;
		}
		if ( str_starts_with( $clean, '00' ) ) {
			$clean = '+' . substr( $clean, 2 );
		}
		if ( preg_match( '/^0\d{9,10}$/', $clean ) ) {
			$clean = '+44' . substr( $clean, 1 );
		}
		if ( preg_match( '/^44\d{9,10}$/', $clean ) ) {
			$clean = '+' . $clean;
		}
		if ( preg_match( '/^\+[0-9]{8,15}$/', $clean ) ) {
			return $clean;
		}
	}
	return '';
}

function pf_extract_stripe_address( array $entry ): array {
	// GF address sub-keys: X.1=line1, X.3=city, X.5=postcode. Find the first
	// field whose .1 sub-key has a value and pair the rest from the same base id.
	$address = [ 'country' => 'GB' ];

	foreach ( $entry as $key => $value ) {
		if ( ! is_string( $key ) || ! str_ends_with( $key, '.1' ) ) {
			continue;
		}
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			continue;
		}
		$base_id  = substr( $key, 0, -2 );
		$line1    = sanitize_text_field( $value );
		$city     = sanitize_text_field( (string) ( $entry[ $base_id . '.3' ] ?? '' ) );
		$postcode = sanitize_text_field( (string) ( $entry[ $base_id . '.5' ] ?? '' ) );

		if ( $line1 !== '' ) {
			$address['line1'] = $line1;
		}
		if ( $city !== '' ) {
			$address['city'] = $city;
		}
		if ( $postcode !== '' ) {
			$address['postal_code'] = $postcode;
		}

		if ( isset( $address['line1'] ) ) {
			break;
		}
	}

	return $address;
}

function pf_extract_stripe_dob( array $entry ): array {
	// Scan top-level entry values for the first that parses as a date.
	foreach ( $entry as $key => $value ) {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			continue;
		}
		if ( str_contains( (string) $key, '.' ) ) {
			continue;
		}
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			continue;
		}
		$normalized = str_replace( '/', '-', trim( $value ) );
		foreach ( [ 'd-m-Y', 'Y-m-d', 'm-d-Y', 'd.m.Y' ] as $fmt ) {
			$dt = DateTime::createFromFormat( $fmt, $normalized );
			if ( $dt instanceof DateTime ) {
				$y = (int) $dt->format( 'Y' );
				$m = (int) $dt->format( 'm' );
				$d = (int) $dt->format( 'd' );
				if ( checkdate( $m, $d, $y ) && $y >= 1900 && $y <= (int) date( 'Y' ) ) {
					return [ 'year' => $y, 'month' => $m, 'day' => $d ];
				}
			}
		}
	}
	return [];
}

function pf_extract_claim_email( array $entry ): string {
	// Scan every top-level entry value (skipping sub-fields like 12.3) and
	// return the first that passes WP's email validator. Avoids hardcoding
	// a field ID since form layouts differ between local and production.
	foreach ( $entry as $key => $value ) {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			continue;
		}
		if ( str_contains( (string) $key, '.' ) ) {
			continue;
		}
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			continue;
		}
		$candidate = sanitize_email( $value );
		if ( $candidate !== '' && is_email( $candidate ) ) {
			return $candidate;
		}
	}
	return '';
}

function pf_get_stripe_business_profile(): array {
	// Stripe rejects localhost / non-public hostnames as invalid URLs. When
	// running against a sandbox key on a dev box we still need to send a
	// valid-looking public URL or accounts->create blows up with
	// "Not a valid URL". Swap localhost-style hosts for the production site.
	$site_url = home_url( '/' );
	$host     = strtolower( (string) parse_url( $site_url, PHP_URL_HOST ) );
	$is_dev   = $host === 'localhost'
		|| $host === '127.0.0.1'
		|| $host === '::1'
		|| str_ends_with( $host, '.local' )
		|| str_ends_with( $host, '.test' )
		|| str_ends_with( $host, '.localhost' );
	if ( $is_dev ) {
		$site_url = pf_stripe_dev_base_url() . '/';
	}

	$support_phone = apply_filters( 'pf_stripe_support_phone', '+442073856677' );  // E.164 — UK trunk '(0)' is dropped in international format

	$profile = [
		'name'                => 'Pepsi Xmas Drop',
		'mcc'                 => '7999',  // Recreation Services NEC — promotional sweepstakes prize payouts
		'product_description' => 'Promotional prize reimbursement payouts for Pepsi winners in the United Kingdom. Eligible winners of the Pepsi Xmas Drop instant-win game receive a one-time cash prize.',
		'url'                 => $site_url,
		'support_url'         => $site_url,
		'support_email'       => apply_filters( 'pf_stripe_support_email', 'help@britvic.promo' ),
	];

	if ( $support_phone !== '' && preg_match( '/^\+[0-9]{8,15}$/', $support_phone ) ) {
		$profile['support_phone'] = $support_phone;
	}

	return $profile;
}

function pf_process_claim_submission_and_get_redirect( array $entry ): string {
	$claim_key = sanitize_text_field( $_POST['_pf_claim_key'] ?? '' );
	$gf_entry_id = (int) ( $entry['id'] ?? 0 );
	error_log(
		sprintf(
			'PF FLOW after_submission: gf_entry_id=%d claim_key_present=%s',
			$gf_entry_id,
			$claim_key !== '' ? 'yes' : 'no'
		)
	);
	if ( $claim_key === '' ) {
		error_log( 'PF FLOW after_submission: exiting, no claim key on submission.' );
		return '';
	}

	$pending = get_transient( 'pf_pending_claim_' . $claim_key );
	if ( ! $pending ) {
		error_log( 'PF FLOW after_submission: no pending claim transient found for claim key.' );
		return '';
	}
	delete_transient( 'pf_pending_claim_' . $claim_key );

	$prize_entry_id  = (int) ( $pending['prize_entry_id'] ?? 0 );
	$competition_id  = (int) ( $pending['competition_id'] ?? 0 );
	$prize_type      = sanitize_text_field( (string) ( $pending['prize_type'] ?? '' ) );
	$reservation_key = sanitize_text_field( (string) ( $pending['reservation_key'] ?? '' ) );

	$email       = pf_extract_claim_email( $entry );
	[ $first_name, $last_name, $full_name ] = pf_get_claim_name( $entry );
	error_log(
		sprintf(
			'PF FLOW after_submission: pending claim loaded prize_entry_id=%d prize_type=%s competition_id=%d',
			$prize_entry_id,
			$prize_type,
			$competition_id
		)
	);

	if ( $prize_entry_id <= 0 || $gf_entry_id <= 0 || $email === '' || ! function_exists( 'pf_claim_bpm_prize' ) ) {
		$url = pf_prize_page_url( 'payout-failed' );
		error_log( 'PF FLOW after_submission: invalid claim payload; redirecting payout-failed.' );
		return $url;
	}

	$claimed = pf_claim_bpm_prize( $prize_entry_id, $email, $full_name, $gf_entry_id, $reservation_key );
	if ( ! $claimed ) {
		pf_log_stripe_event(
			$competition_id,
			'prize_claim_failed',
			'Prize claim failed on form submission.',
			'warning',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id ]
		);
		pf_set_form_redirect( $gf_entry_id, pf_prize_page_url( 'payout-failed' ) );
		error_log(
			sprintf(
				'PF FLOW after_submission: claim failed prize_entry_id=%d gf_entry_id=%d',
				$prize_entry_id,
				$gf_entry_id
			)
		);
		return pf_prize_page_url( 'payout-failed' );
	}

	// Merch prizes are completed immediately after form submission.
	if ( ! pf_is_cash_prize_type( $prize_type ) ) {
		// Pass an unguessable token, not the enumerable GF entry id.
		$thanks_param = function_exists( 'pf_create_thanks_token' )
			? [ 'pf_thanks' => pf_create_thanks_token( $gf_entry_id ) ]
			: [ 'gf_entry' => $gf_entry_id ];
		$url = pf_prize_page_url( 'thanks', $thanks_param );
		pf_log_stripe_event(
			$competition_id,
			'merch_claimed',
			'Merch prize claimed.',
			'info',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id, 'email' => $email ]
		);
		error_log(
			sprintf(
				'PF FLOW after_submission: merch flow redirect set gf_entry_id=%d',
				$gf_entry_id
			)
		);
		return $url;
	}

	// Early tripwire: a blocklisted claim email never receives a Stripe
	// onboarding link. The bank-identity gate in pf_v2_execute_payout() is the
	// authoritative check; this just stops known offenders one step sooner.
	if ( pf_email_is_payout_blocked( $email ) ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => 'payout_blocked' ] );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_payout_blocked',
			'Payout withheld at form submission: blocked_email.',
			'warning',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id, 'reason' => 'blocked_email' ]
		);
		error_log( sprintf(
			'PF FLOW after_submission: blocked reason=blocked_email prize_entry_id=%d gf_entry_id=%d',
			$prize_entry_id, $gf_entry_id
		) );
		return pf_prize_page_url( 'payout-failed', [ 'pf_reason' => 'review' ] );
	}

	// Prefer v2 Global Payouts when a FinancialAccount is configured. v1 path
	// is retained below for back-compat / fallback when v2 is unconfigured.
	if ( pf_stripe_financial_account_id() !== '' && pf_stripe_secret_key() !== '' ) {
		return pf_v2_create_account_and_return_url(
			$competition_id,
			$prize_entry_id,
			$gf_entry_id,
			$email,
			$first_name,
			$last_name
		);
	}

	$stripe = pf_get_stripe_client();
	if ( ! $stripe ) {
		pf_log_stripe_event(
			$competition_id,
			'stripe_not_available',
			'Stripe SDK unavailable or key missing.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id ]
		);
		error_log( 'PF FLOW after_submission: stripe client unavailable; redirecting payout-failed.' );
		return pf_prize_page_url( 'payout-failed' );
	}

	try {
		$phone = pf_extract_stripe_phone( $entry );
		$dob   = pf_extract_stripe_dob( $entry );
		$individual = [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'address'    => pf_extract_stripe_address( $entry ),
		];
		if ( $phone !== '' ) {
			$individual['phone'] = $phone;
		}
		if ( ! empty( $dob ) ) {
			$individual['dob'] = $dob;
		}
		$business_profile = pf_get_stripe_business_profile();
		// Override the default 'Pepsi Xmas Drop' name with the winner's own
		// first + last name so the Stripe Express account is identifiable as
		// the recipient rather than the platform.
		$winner_name = trim( $first_name . ' ' . $last_name );
		if ( $winner_name !== '' ) {
			$business_profile['name'] = $winner_name;
		}

		$missing = [];
		if ( $first_name === '' )                       $missing[] = 'first_name';
		if ( $last_name === '' )                        $missing[] = 'last_name';
		if ( ! is_email( $email ) )                     $missing[] = 'email';
		if ( $phone === '' )                            $missing[] = 'phone';
		if ( empty( $dob ) )                            $missing[] = 'dob';
		if ( empty( $individual['address']['line1'] ) ) $missing[] = 'address.line1';
		if ( empty( $individual['address']['city'] ) )  $missing[] = 'address.city';
		if ( empty( $individual['address']['postal_code'] ) ) $missing[] = 'address.postal_code';
		if ( ! empty( $missing ) ) {
			error_log( 'PF FLOW stripe account create: GF entry missing fields=' . implode( ',', $missing ) . ' — Stripe will request these during onboarding.' );
		}

		// Stripe doesn't allow the platform to pre-accept ToS for Express
		// accounts (controller.requirement_collection=stripe) — the winner
		// accepts the service agreement themselves during the hosted onboarding
		// flow. So we omit tos_acceptance entirely; Stripe surfaces the
		// agreement in the Express onboarding UI.

		$account = $stripe->accounts->create( [
			'type'          => 'express',
			'country'       => 'GB',
			'business_type' => 'individual',
			'email'         => $email,
			'capabilities'  => [ 'transfers' => [ 'requested' => true ] ],
			'business_profile' => $business_profile,
			'individual'    => $individual,
			'settings'      => [
				'payouts' => [
					'schedule' => [ 'interval' => 'manual' ],
				],
			],
			'metadata'      => [
				'prize_entry_id' => (string) $prize_entry_id,
				'gf_entry_id'    => (string) $gf_entry_id,
				'competition_id' => (string) $competition_id,
				'route'          => function_exists( 'pf_current_route' ) ? pf_current_route() : 'main',
				'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
			],
		] );

		pf_update_bpm_stripe_fields( $prize_entry_id, [
			'stripe_account_id'    => $account->id,
			'stripe_payout_status' => 'pending',
		] );

		$claim_token = pf_create_stripe_claim_context( [
			'competition_id' => $competition_id,
			'prize_entry_id' => $prize_entry_id,
			'gf_entry_id'    => $gf_entry_id,
			'stripe_account_id' => $account->id,
			'email'          => $email,
			'payout_method'  => pf_get_payout_method(),
		] );

		$account_link = pf_create_account_link( $stripe, $account->id, $claim_token );
		error_log(
			sprintf(
				'PF FLOW after_submission: cash flow redirect set gf_entry_id=%d prize_entry_id=%d stripe_account_id=%s',
				$gf_entry_id,
				$prize_entry_id,
				$account->id
			)
		);

		pf_log_stripe_event(
			$competition_id,
			'stripe_account_created',
			'Created Stripe account and onboarding link for cash winner.',
			'info',
			[
				'prize_entry_id'    => $prize_entry_id,
				'gf_entry_id'       => $gf_entry_id,
				'stripe_account_id' => $account->id,
			]
		);
		return (string) $account_link->url;
	} catch ( Throwable $e ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => 'failed' ] );
		pf_log_stripe_event(
			$competition_id,
			'stripe_account_create_failed',
			'Failed to create Stripe account for winner.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id, 'error' => $e->getMessage() ]
		);
		error_log( 'PF FLOW after_submission: exception during Stripe account create: ' . $e->getMessage() );
		return pf_prize_page_url( 'payout-failed' );
	}
}

add_action( 'gform_after_submission', function ( array $entry, array $form ): void {
	if ( (int) ( $form['id'] ?? 0 ) !== pf_claim_form_id() ) {
		return;
	}
	$gf_entry_id = (int) ( $entry['id'] ?? 0 );
	$url         = pf_process_claim_submission_and_get_redirect( $entry );
	if ( $gf_entry_id > 0 && $url !== '' ) {
		pf_set_form_redirect( $gf_entry_id, $url );
	}
}, 10, 2 );

add_filter( 'gform_confirmation', function ( $confirmation, $form, $entry ) {
	if ( (int) ( $form['id'] ?? 0 ) !== pf_claim_form_id() ) {
		return $confirmation;
	}
	$entry_id = (int) ( $entry['id'] ?? 0 );
	$url      = pf_get_form_redirect( $entry_id );
	if ( $url === '' ) {
		$url = pf_process_claim_submission_and_get_redirect( (array) $entry );
	}
	if ( $url === '' ) {
		error_log( sprintf( 'PF FLOW confirmation: no redirect found for gf_entry_id=%d', $entry_id ) );
		return $confirmation;
	}
	error_log( sprintf( 'PF FLOW confirmation: redirecting gf_entry_id=%d to=%s', $entry_id, $url ) );
	return [ 'redirect' => esc_url_raw( $url ) ];
}, 10, 3 );

// ─── Stripe v2 Global Payouts — account creation + onboarding link ──────────
//
// Mirrors the v1 cash-prize branch but uses POST /v2/core/accounts and
// POST /v2/core/account_links. Persists the v2 account id in
// stripe_account_id_v2 (separate column from v1 so in-flight v1 records keep
// settling unchanged) and stamps the claim context with flow_version=v2 so
// the return / refresh / verify handlers route to the v2 path.

function pf_v2_create_account_and_return_url(
	int $competition_id,
	int $prize_entry_id,
	int $gf_entry_id,
	string $email,
	string $first_name,
	string $last_name
): string {
	try {
		$display_name = trim( $first_name . ' ' . $last_name );

		// Prefill identity.individual so the Stripe-hosted onboarding form opens
		// with name + email already populated AND so the bank_accounts.local
		// capability isn't held in `restricted` state on `requirements_past_due`
		// for the given_name / surname fields. Without these, the hosted form
		// doesn't always collect them and the capability never reaches `active`.
		$individual = [];
		if ( $first_name !== '' ) {
			$individual['given_name'] = $first_name;
		}
		if ( $last_name !== '' ) {
			$individual['surname'] = $last_name;
		}
		if ( $email !== '' ) {
			// PF_V2_TBD: confirm identity.individual.email is accepted in test
			// mode; if Stripe rejects it, contact_email above is sufficient.
			$individual['email'] = $email;
		}

		$identity_payload = [ 'country' => 'gb', 'entity_type' => 'individual' ];
		if ( ! empty( $individual ) ) {
			$identity_payload['individual'] = $individual;
		}

		$create_payload = [
			'contact_email' => $email,
			'display_name'  => $display_name !== '' ? $display_name : $email,
			'identity'      => $identity_payload,
			'configuration' => [
				'recipient' => [
					'capabilities' => [ 'bank_accounts' => [ 'local' => [ 'requested' => true ] ] ],
				],
			],
			'metadata'      => [
				'prize_entry_id' => (string) $prize_entry_id,
				'gf_entry_id'    => (string) $gf_entry_id,
				'competition_id' => (string) $competition_id,
				'route'          => function_exists( 'pf_current_route' ) ? pf_current_route() : 'main',
				'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
			],
			'include'       => [ 'requirements', 'configuration.recipient', 'identity' ],
		];

		$account_result = pf_stripe_v2_post( '/core/accounts', $create_payload );
		if ( ! $account_result['ok'] ) {
			throw new Exception( 'v2 account create failed: ' . wp_json_encode( $account_result['error'] ) );
		}
		// PF_V2_TBD: confirm v2 account ID prefix in test mode; v1 was `acct_`,
		// v2 may differ. Treated opaque here — we never parse the prefix.
		$account_id = (string) ( $account_result['data']['id'] ?? '' );
		if ( $account_id === '' ) {
			throw new Exception( 'v2 account create returned no id; payload=' . wp_json_encode( $account_result['data'] ) );
		}

		pf_update_bpm_stripe_fields( $prize_entry_id, [
			'stripe_account_id_v2' => $account_id,
			'stripe_payout_status' => 'pending',
		] );

		$claim_token = pf_create_stripe_claim_context( [
			'competition_id'       => $competition_id,
			'prize_entry_id'       => $prize_entry_id,
			'gf_entry_id'          => $gf_entry_id,
			'stripe_account_id_v2' => $account_id,
			'email'                => $email,
			'flow_version'         => 'v2',
		] );

		// Patch claim_token into account metadata so the webhook handler can read
		// it without needing the WP transient (which may be expired on resend).
		pf_stripe_v2_post( '/core/accounts/' . rawurlencode( $account_id ), [
			'metadata' => [ 'claim_token' => $claim_token ],
		] );

		$link_result = pf_stripe_v2_post( '/core/account_links', [
			'account'  => $account_id,
			'use_case' => [
				'type'               => 'account_onboarding',
				'account_onboarding' => [
					// v2 URL semantics (confirmed by Stripe support June 2026):
					// return_url  = called after recipient COMPLETES the form → trigger payout
					// refresh_url = called when link is expired/already-visited/rejected → regenerate link
					'return_url'     => pf_stripe_public_url( pf_prize_page_url( 'stripe-return',  [ 'pf_claim' => $claim_token ] ) ),
					'refresh_url'    => pf_stripe_public_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) ),
				],
			],
		] );
		if ( ! $link_result['ok'] ) {
			throw new Exception( 'v2 account link create failed: ' . wp_json_encode( $link_result['error'] ) );
		}
		$onboarding_url = (string) ( $link_result['data']['url'] ?? '' );
		if ( $onboarding_url === '' ) {
			throw new Exception( 'v2 account link returned no url; payload=' . wp_json_encode( $link_result['data'] ) );
		}

		// Store the Stripe URL in context (used by stripe-refresh if session expires).
		// Load back the context stored by pf_create_stripe_claim_context rather than
		// referencing an undefined $context variable which would overwrite the full
		// context with a single-key array, stripping flow_version and prize_entry_id.
		$stored_context = pf_get_stripe_claim_context( $claim_token );
		if ( is_array( $stored_context ) ) {
			$stored_context['stripe_onboarding_url'] = $onboarding_url;
			pf_set_stripe_claim_context( $claim_token, $stored_context );
		}

		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_account_created',
			'Created v2 recipient + onboarding link for cash winner.',
			'info',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id, 'stripe_account_id_v2' => $account_id ]
		);
		error_log( sprintf(
			'PF FLOW stripe_v2: account created prize_entry_id=%d account_id=%s',
			$prize_entry_id,
			$account_id
		) );

		// Redirect directly to Stripe — refresh_url (v2 completion callback) brings
		// them back to stripe-return which fires the payout and redirects to prize-paid.
		return pf_stripe_public_url( $onboarding_url );
	} catch ( Throwable $e ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => 'failed' ] );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_account_create_failed',
			'Failed to create v2 Stripe account for winner.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'gf_entry_id' => $gf_entry_id, 'error' => $e->getMessage() ]
		);
		error_log( 'PF FLOW after_submission: exception during v2 Stripe account create: ' . $e->getMessage() );
		return pf_prize_page_url( 'payout-failed' );
	}
}

/**
 * Generate (or regenerate) a v2 AccountLink for an existing v2 recipient,
 * for use on the /stripe-refresh/ and /stripe-verify/ pages.
 */
function pf_v2_create_account_link( string $account_id, string $claim_token ): array {
	return pf_stripe_v2_post( '/core/account_links', [
		'account'  => $account_id,
		'use_case' => [
			'type'               => 'account_onboarding',
			'account_onboarding' => [
				// v2 URL semantics (confirmed by Stripe support June 2026):
				// return_url  = called after recipient COMPLETES the form → trigger payout
				// refresh_url = called when link is expired/already-visited/rejected → regenerate link
				'return_url'     => pf_stripe_public_url( pf_prize_page_url( 'stripe-return',  [ 'pf_claim' => $claim_token ] ) ),
				'refresh_url'    => pf_stripe_public_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) ),
			],
		],
	] );
}

/**
 * Resolve the active gbba_ payment-method id and its Confirmation-of-Payee
 * status for a v2 recipient account.
 *
 * Returns: [ 'gbba_id' => string, 'cop_result' => string, 'raw' => array ]
 * PF_V2_TBD: exact response path for the local payout method + CoP fields
 * isn't documented in the preview reference yet. Best-effort path inspection
 * — caller logs the raw payload on miss so it's easy to adjust in test mode.
 */
/**
 * Extracts the Stripe-side bank identity from a v2 vault gb_bank_account
 * object. Field paths verified against a real test-mode vault response
 * (object `v2.core.vault.gb_bank_account`):
 *
 *  - holder name — `confirmation_of_payee.result.provided.name`: the name the
 *    winner gave Stripe **with the bank details** (may differ from the
 *    claim-form name). CoP Option A strict means a paid entry's provided name
 *    matched the bank's records.
 *  - last4 / sort_code — top-level fields.
 *  - fingerprint — Stripe exposes none on this object, so we derive our own
 *    from sha256(sort_code:last4): stable for the same physical bank account
 *    across recipient accounts, without storing raw bank details.
 *
 * This identity — not the claim email — is what the payout blocklist and
 * one-payout-per-bank-account rule key on.
 *
 * @return array{holder_name: string, last4: string, fingerprint: string}
 */
function pf_v2_extract_bank_identity( array $gbba_data ): array {
	$cop_result = (array) ( $gbba_data['confirmation_of_payee']['result'] ?? [] );
	$holder = (string) ( $cop_result['provided']['name']
		?? $gbba_data['account_holder_name']
		?? '' );
	$last4     = (string) ( $gbba_data['last4'] ?? '' );
	$sort_code = (string) ( $gbba_data['sort_code'] ?? '' );

	$fingerprint = (string) ( $gbba_data['fingerprint'] ?? '' );
	if ( $fingerprint === '' && $sort_code !== '' && $last4 !== '' ) {
		$fingerprint = hash( 'sha256', $sort_code . ':' . $last4 );
	}

	return [
		'holder_name' => sanitize_text_field( $holder ),
		'last4'       => sanitize_text_field( $last4 ),
		'fingerprint' => sanitize_text_field( $fingerprint ),
	];
}

/**
 * Normalises a bank holder name for blocklist / duplicate matching:
 * lowercase, punctuation stripped, whitespace collapsed. "MR. J  Smith" and
 * "mr j smith" compare equal.
 */
function pf_normalize_holder_name( string $name ): string {
	$n = strtolower( trim( $name ) );
	$n = (string) preg_replace( '/[^a-z0-9 ]+/', '', $n );
	$n = (string) preg_replace( '/\s+/', ' ', $n );
	return trim( $n );
}

// ─── Payout blocklist + one-payout-per-bank-account rule ────────────────────
//
// Repeat winners rotate claim emails freely, but the bank account they onboard
// with Stripe is stable — so blocking keys on the Stripe-side bank identity
// (holder name / last4 / fingerprint captured at payout time), plus a manual
// email list as an early tripwire at form-submit. Options are maintained on
// the Settings → PF Debug page.

const PF_PAYOUT_DEDUPE_OPTION          = 'pf_payout_dedupe_enabled';
const PF_PAYOUT_BLOCKLIST_NAMES_OPTION = 'pf_payout_blocklist_names';
const PF_PAYOUT_BLOCKLIST_EMAILS_OPTION = 'pf_payout_blocklist_emails';

/** One cash payout per bank account for the whole campaign. Default ON. */
function pf_payout_dedupe_enabled(): bool {
	return get_option( PF_PAYOUT_DEDUPE_OPTION, '1' ) === '1';
}

/** @return string[] normalised blocked holder names */
function pf_payout_blocked_names(): array {
	$raw   = (string) get_option( PF_PAYOUT_BLOCKLIST_NAMES_OPTION, '' );
	$names = [];
	foreach ( preg_split( '/[\r\n]+/', $raw ) ?: [] as $line ) {
		$n = pf_normalize_holder_name( $line );
		if ( $n !== '' ) {
			$names[] = $n;
		}
	}
	return $names;
}

/** @return string[] lowercased blocked emails */
function pf_payout_blocked_emails(): array {
	$raw    = (string) get_option( PF_PAYOUT_BLOCKLIST_EMAILS_OPTION, '' );
	$emails = [];
	foreach ( preg_split( '/[\r\n]+/', $raw ) ?: [] as $line ) {
		$e = strtolower( trim( $line ) );
		if ( $e !== '' && is_email( $e ) ) {
			$emails[] = $e;
		}
	}
	return $emails;
}

function pf_email_is_payout_blocked( string $email ): bool {
	$e = strtolower( trim( $email ) );
	return $e !== '' && in_array( $e, pf_payout_blocked_emails(), true );
}

/**
 * Finds another prize entry already paid (has an OutboundPayment ID) to the
 * same physical bank account. Fingerprint match wins outright when Stripe
 * exposes one; otherwise normalised holder name + last4 must both match.
 *
 * @return int the duplicate entry's ID, or 0 if none.
 */
function pf_v2_find_paid_duplicate( int $prize_entry_id, array $identity ): int {
	$fingerprint = (string) ( $identity['fingerprint'] ?? '' );
	$holder_norm = pf_normalize_holder_name( (string) ( $identity['holder_name'] ?? '' ) );
	$last4       = (string) ( $identity['last4'] ?? '' );

	if ( ! function_exists( 'pf_bpm_prize_has_column' ) || ! pf_bpm_prize_has_column( 'stripe_bank_holder_name' ) ) {
		error_log( 'PF FLOW stripe_v2 dedupe: bank identity columns missing (migration not run) — cannot check for duplicate bank account.' );
		return 0;
	}
	if ( $fingerprint === '' && ( $holder_norm === '' || $last4 === '' ) ) {
		error_log( 'PF FLOW stripe_v2 dedupe: bank identity incomplete (no fingerprint, name/last4 missing) — cannot check for duplicate bank account.' );
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, stripe_bank_holder_name, stripe_bank_last4, stripe_bank_fingerprint
		 FROM {$table}
		 WHERE id != %d
		   AND stripe_outbound_payment_id IS NOT NULL AND stripe_outbound_payment_id != ''
		   AND ( ( %s != '' AND stripe_bank_fingerprint = %s )
		      OR ( %s != '' AND stripe_bank_last4 = %s ) )",
		$prize_entry_id,
		$fingerprint, $fingerprint,
		$last4, $last4
	) );

	foreach ( (array) $rows as $row ) {
		if ( $fingerprint !== '' && (string) $row->stripe_bank_fingerprint === $fingerprint ) {
			return (int) $row->id;
		}
		if ( $holder_norm !== '' && $last4 !== ''
			&& (string) $row->stripe_bank_last4 === $last4
			&& pf_normalize_holder_name( (string) $row->stripe_bank_holder_name ) === $holder_norm ) {
			return (int) $row->id;
		}
	}
	return 0;
}

/**
 * Decides whether this payout must be withheld. Checked inside
 * pf_v2_execute_payout() so every trigger path (browser return, refresh,
 * webhook) passes through the same gate before an OutboundPayment exists.
 *
 * @return array{reason: string, duplicate_of: int} reason '' = not blocked;
 *   otherwise 'duplicate_bank' | 'blocked_name' | 'blocked_email'.
 */
function pf_v2_payout_block_reason( int $prize_entry_id, array $identity ): array {
	$holder_norm = pf_normalize_holder_name( (string) ( $identity['holder_name'] ?? '' ) );
	if ( $holder_norm !== '' && in_array( $holder_norm, pf_payout_blocked_names(), true ) ) {
		return [ 'reason' => 'blocked_name', 'duplicate_of' => 0 ];
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$email = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT claimed_by_email FROM {$table} WHERE id = %d",
		$prize_entry_id
	) );
	if ( pf_email_is_payout_blocked( $email ) ) {
		return [ 'reason' => 'blocked_email', 'duplicate_of' => 0 ];
	}

	if ( pf_payout_dedupe_enabled() ) {
		$dup = pf_v2_find_paid_duplicate( $prize_entry_id, $identity );
		if ( $dup > 0 ) {
			return [ 'reason' => 'duplicate_bank', 'duplicate_of' => $dup ];
		}
	}
	return [ 'reason' => '', 'duplicate_of' => 0 ];
}

/**
 * Backfills the Stripe-side bank identity onto already-paid entries.
 *
 * Entries paid before the bank-identity columns existed have a stored
 * gbba_ / account id but empty identity fields. This re-reads each gbba_
 * from the Stripe vault and fills the columns, so the repeat-winner report
 * covers historical payouts. Read-only against Stripe. Batched; run until
 * `remaining` is 0.
 *
 * Called from the "Run backfill" button on Settings → PF Debug and from the
 * admin-gated REST endpoint (the latter needs an X-WP-Nonce/_wpnonce or an
 * application password — a bare logged-in browser hit is anonymous to REST).
 */
function pf_stripe_v2_backfill_bank_identity_run( int $limit = 50 ): array {
	if ( ! function_exists( 'pf_bpm_prize_has_column' ) || ! pf_bpm_prize_has_column( 'stripe_bank_holder_name' ) ) {
		return [
			'ok'    => false,
			'error' => 'bank identity columns missing — visit any wp-admin page to run the BPM migration first.',
		];
	}

	$limit = ( $limit >= 1 && $limit <= 200 ) ? $limit : 50;

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, stripe_account_id_v2, stripe_gb_bank_account_id
		 FROM {$table}
		 WHERE prize_type LIKE 'cash%'
		   AND stripe_account_id_v2 IS NOT NULL AND stripe_account_id_v2 != ''
		   AND stripe_gb_bank_account_id IS NOT NULL AND stripe_gb_bank_account_id != ''
		   AND (stripe_bank_fingerprint IS NULL OR stripe_bank_fingerprint = '')
		 ORDER BY id
		 LIMIT %d",
		$limit
	) );

	$updated = [];
	$failed  = [];
	foreach ( (array) $rows as $row ) {
		$gbba = pf_stripe_v2_get(
			'/core/vault/gb_bank_accounts/' . rawurlencode( (string) $row->stripe_gb_bank_account_id ),
			[],
			[ 'Stripe-Context' => (string) $row->stripe_account_id_v2 ]
		);
		if ( ! $gbba['ok'] ) {
			$failed[] = [ 'id' => (int) $row->id, 'error' => $gbba['error'] ];
			continue;
		}
		$identity = pf_v2_extract_bank_identity( (array) $gbba['data'] );
		if ( $identity['holder_name'] === '' && $identity['last4'] === '' && $identity['fingerprint'] === '' ) {
			// PF_V2_TBD paths didn't match — surface one raw object for correction.
			$failed[] = [ 'id' => (int) $row->id, 'error' => 'no identity fields found', 'raw' => $gbba['data'] ];
			continue;
		}
		pf_update_bpm_stripe_fields( (int) $row->id, [
			'stripe_bank_holder_name' => $identity['holder_name'],
			'stripe_bank_last4'       => $identity['last4'],
			'stripe_bank_fingerprint' => $identity['fingerprint'],
		] );
		$updated[] = [
			'id'          => (int) $row->id,
			'holder_name' => $identity['holder_name'],
			'last4'       => $identity['last4'],
			'fingerprint' => $identity['fingerprint'] !== '' ? 'yes' : 'no',
		];
	}

	$remaining = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table}
		 WHERE prize_type LIKE 'cash%'
		   AND stripe_account_id_v2 IS NOT NULL AND stripe_account_id_v2 != ''
		   AND stripe_gb_bank_account_id IS NOT NULL AND stripe_gb_bank_account_id != ''
		   AND (stripe_bank_fingerprint IS NULL OR stripe_bank_fingerprint = '')"
	);

	error_log( sprintf(
		'PF FLOW stripe_v2 backfill: processed=%d updated=%d failed=%d remaining=%d',
		count( (array) $rows ), count( $updated ), count( $failed ), $remaining
	) );

	return [
		'ok'        => true,
		'processed' => count( (array) $rows ),
		'updated'   => $updated,
		'failed'    => $failed,
		'remaining' => $remaining,
	];
}

function pf_api_stripe_v2_backfill_bank_identity( WP_REST_Request $req ): WP_REST_Response {
	$out = pf_stripe_v2_backfill_bank_identity_run( (int) $req->get_param( 'limit' ) );
	return new WP_REST_Response( $out, empty( $out['ok'] ) ? 500 : 200 );
}

function pf_v2_discover_gb_bank_account( string $account_id ): array {
	$out = [
		'gbba_id'    => '',
		'cop_result' => '',
		'raw'        => [],
		'bank_identity' => [ 'holder_name' => '', 'last4' => '', 'fingerprint' => '' ],
	];
	if ( $account_id === '' ) {
		return $out;
	}

	// PF_V2_TBD: confirm exact include syntax + path for listing payout methods
	// on a v2 recipient. Tries account include first; if not present, falls
	// back to a list endpoint.
	$acct = pf_stripe_v2_get( '/core/accounts/' . rawurlencode( $account_id ), [
		'include' => [ 'configuration.recipient', 'requirements', 'identity' ],
	] );
	$out['raw'] = $acct['data'];
	if ( ! $acct['ok'] ) {
		return $out;
	}

	$recipient = $acct['data']['configuration']['recipient'] ?? [];

	// Primary source: configuration.recipient.default_outbound_destination is
	// an object `{ id: "gbba_…", type: "gb_bank_account" }` populated by Stripe
	// the moment the hosted onboarding form completes successfully.
	$dod = $recipient['default_outbound_destination'] ?? null;
	if ( is_array( $dod ) ) {
		$candidate = (string) ( $dod['id'] ?? '' );
		if ( $candidate !== '' && str_starts_with( $candidate, 'gbba_' ) ) {
			$out['gbba_id'] = $candidate;
		}
	}

	// Fallback: some account shapes expose a list of payout methods directly.
	if ( $out['gbba_id'] === '' ) {
		foreach ( (array) ( $recipient['payout_methods'] ?? [] ) as $pm ) {
			$cand = '';
			if ( is_array( $pm ) && isset( $pm['id'] ) ) {
				$cand = (string) $pm['id'];
			} elseif ( is_string( $pm ) ) {
				$cand = $pm;
			}
			if ( $cand !== '' && str_starts_with( $cand, 'gbba_' ) ) {
				$out['gbba_id'] = $cand;
				break;
			}
		}
	}

	// CoP result: retrieve the specific gbba via the vault endpoint with the
	// recipient account ID in the Stripe-Context header (vault endpoints are
	// scoped to a recipient, not the platform).
	if ( $out['gbba_id'] !== '' ) {
		$gbba = pf_stripe_v2_get(
			'/core/vault/gb_bank_accounts/' . rawurlencode( $out['gbba_id'] ),
			[],
			[ 'Stripe-Context' => $account_id ]
		);
		if ( $gbba['ok'] ) {
			$cop = $gbba['data']['confirmation_of_payee'] ?? [];
			$out['cop_result'] = (string) ( $cop['result']['match_result']
				?? $cop['status']
				?? '' );
			$out['bank_identity'] = pf_v2_extract_bank_identity( (array) $gbba['data'] );
			if ( $out['bank_identity']['holder_name'] === ''
				&& function_exists( 'pf_is_debug_panel_visible' ) && pf_is_debug_panel_visible() ) {
				// PF_V2_TBD: holder name not found at guessed paths — dump the
				// raw vault object so the field paths can be corrected.
				error_log( 'PF FLOW stripe_v2: gbba bank identity empty, raw vault object=' . wp_json_encode( $gbba['data'] ) );
			}
		} else {
			error_log( sprintf(
				'PF FLOW stripe_v2: CoP retrieve failed for gbba=%s account=%s :: %s',
				$out['gbba_id'], $account_id, wp_json_encode( $gbba['error'] )
			) );
		}
	}

	return $out;
}

/**
 * Option A strict CoP policy: only `match` / `partial_match` pay in live mode.
 *
 * Test-mode relaxation: Stripe synthesises test bank-account holder names that
 * won't match what a winner types, so CoP almost always returns `mismatch` in
 * sandbox. To keep the test flow exercisable, when `$livemode === false` we
 * treat any CoP result (including mismatch / unavailable / empty) as payable
 * and log the bypass loudly. Live mode behaviour is unchanged.
 */
function pf_v2_cop_result_is_payable( string $result, bool $livemode = true ): bool {
	$r = strtolower( trim( $result ) );
	if ( $r === 'match' || $r === 'partial_match' ) {
		return true;
	}
	if ( ! $livemode ) {
		error_log( sprintf(
			'PF FLOW stripe_v2: TEST-MODE CoP bypass — would-have-blocked result=%s, allowing payout. Live mode keeps Option A strict.',
			$r !== '' ? $r : 'empty'
		) );
		return true;
	}
	return false;
}

/**
 * Stripe REQUIRES an explicit acknowledge for any CoP result other than
 * `match` before it will accept an OutboundPayment to the bank account. This
 * is independent of our Option A payable policy — payable says "should we
 * pay?", ack says "Stripe demands we confirm we've seen the CoP outcome".
 *
 * In live mode the only non-match result that reaches this point is
 * `partial_match` (cop_result_is_payable blocks the others), so we ack
 * partial_match and pay. In test mode the bypass lets through mismatch /
 * unavailable / empty too, and we must ack those for Stripe to accept the
 * OutboundPayment.
 */
function pf_v2_should_acknowledge_cop( string $result ): bool {
	$r = strtolower( trim( $result ) );
	return $r !== 'match';
}

/**
 * POST /v2/core/vault/gb_bank_accounts/{id}/acknowledge_confirmation_of_payee
 * Vault endpoints require the recipient account id in the Stripe-Context header.
 */
function pf_v2_acknowledge_cop( string $gbba_id, string $account_id ): array {
	if ( $gbba_id === '' || $account_id === '' ) {
		return [ 'ok' => false, 'status' => 0, 'data' => [], 'error' => [ 'message' => 'gbba_id or account_id empty.' ] ];
	}
	return pf_stripe_v2_post(
		'/core/vault/gb_bank_accounts/' . rawurlencode( $gbba_id ) . '/acknowledge_confirmation_of_payee',
		[],
		[ 'Stripe-Context' => $account_id ]
	);
}

function pf_process_stripe_return_page(): void {
	// Buffer output so a leaked plugin notice/warning during processing (e.g.
	// the BPM audit logger) can't commit headers before our redirect.
	ob_start();
	$claim_token = isset( $_GET['pf_claim'] ) ? sanitize_text_field( (string) $_GET['pf_claim'] ) : '';
	error_log( 'PF FLOW stripe_return: started claim_token_present=' . ( $claim_token !== '' ? 'yes' : 'no' ) );
	$context     = pf_get_stripe_claim_context( $claim_token );
	if ( ! $context ) {
		error_log( 'PF FLOW stripe_return: missing claim context; redirect payout-failed.' );
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	// Valid cash-winner claim context + we're back from Stripe — re-assert the
	// flow outcome on THIS (return-domain) session so the guarded outcome pages
	// (/prize-paid/, /payout-failed/) are reachable. The flag set during the GF
	// claim does not reliably survive the cross-site game → claim → stripe.com
	// → tunnel-return round-trip, so flow-guard would otherwise bounce the
	// winner to the age gate.
	if ( function_exists( 'pf_flow_set_outcome' ) && defined( 'PF_FLOW_OUTCOME_WINNER_CASH' ) ) {
		pf_flow_set_outcome( PF_FLOW_OUTCOME_WINNER_CASH );
	}

	// ── Idempotency guard ────────────────────────────────────────────────────
	// If this prize entry already has a Stripe payout on record, the player
	// is re-entering after a completed claim (e.g. clicked Back from
	// /prize-paid/ to the Stripe URL and re-completed onboarding). Destroy
	// any remaining claim token and force them back through the entry route
	// — NEVER create a second transfer/payout for the same entry.
	$prize_entry_id_guard = (int) ( $context['prize_entry_id'] ?? 0 );
	$existing_payout_id   = pf_get_bpm_stripe_payout_id( $prize_entry_id_guard );
	$existing_obp_id      = pf_get_bpm_stripe_outbound_payment_id( $prize_entry_id_guard );
	if ( $existing_payout_id !== '' || $existing_obp_id !== '' ) {
		error_log( sprintf(
			'PF FLOW stripe_return: replay blocked — prize_entry_id=%d already settled (payout_id=%s, obp_id=%s); destroying token + redirecting by payout status.',
			$prize_entry_id_guard,
			$existing_payout_id,
			$existing_obp_id
		) );
		pf_delete_stripe_claim_context( $claim_token );
		pf_safe_redirect_exit( pf_v2_settled_entry_redirect_url( $prize_entry_id_guard, '' ) );
	}

	// Branch v2 vs v1 by claim-context flow_version.
	if ( ( $context['flow_version'] ?? '' ) === 'v2' ) {
		pf_v2_process_stripe_return( $claim_token, $context );
		return;
	}

	$stripe = pf_get_stripe_client();
	if ( ! $stripe ) {
		error_log( 'PF FLOW stripe_return: stripe client unavailable; redirect payout-failed.' );
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	$competition_id = (int) ( $context['competition_id'] ?? 0 );
	$prize_entry_id = (int) ( $context['prize_entry_id'] ?? 0 );
	$account_id     = sanitize_text_field( (string) ( $context['stripe_account_id'] ?? '' ) );

	try {
		$account = $stripe->accounts->retrieve( $account_id, [] );
		if ( empty( $account->payouts_enabled ) ) {
			$currently_due = (array) ( $account->requirements->currently_due ?? [] );
			if ( ! empty( $currently_due ) ) {
				// Stripe needs more from the winner (e.g. identity document) — send to verify page.
				$verify_attempts = (int) ( $context['verify_attempts'] ?? 0 ) + 1;
				$context['verify_attempts'] = $verify_attempts;
				pf_set_stripe_claim_context( $claim_token, $context );
				error_log( sprintf( 'PF FLOW stripe_return: currently_due not empty attempt=%d items=%s; redirect stripe-verify.', $verify_attempts, implode( ',', $currently_due ) ) );
				if ( $verify_attempts > 3 ) {
					wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'verify' ] ) );
				} else {
					wp_safe_redirect( pf_prize_page_url( 'stripe-verify', [ 'pf_claim' => $claim_token ] ) );
				}
			} else {
				// No outstanding requirements — onboarding link simply expired, regenerate it.
				error_log( 'PF FLOW stripe_return: payouts not enabled, no currently_due; redirect stripe-refresh.' );
				wp_safe_redirect( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) );
			}
			exit;
		}

		$amount = pf_get_prize_amount_pence( $prize_entry_id );
		if ( $amount <= 0 ) {
			error_log( sprintf( 'PF FLOW stripe_return: no amount mapped for prize_entry_id=%d; redirect payout-failed.', $prize_entry_id ) );
			wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
			exit;
		}
		$transfer = $stripe->transfers->create( [
			'amount'      => $amount,
			'currency'    => 'gbp',
			'destination' => $account_id,
			'metadata'    => [
				'prize_entry_id' => (string) $prize_entry_id,
				'gf_entry_id'    => (string) ( $context['gf_entry_id'] ?? 0 ),
				'claim_token'    => $claim_token,
				'route'          => function_exists( 'pf_current_route' ) ? pf_current_route() : 'main',
				'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
			],
		] );

		$payout_args = [
			'amount'   => $amount,
			'currency' => 'gbp',
			'method'   => pf_get_payout_method(),
			'metadata' => [
				'prize_entry_id' => (string) $prize_entry_id,
				'gf_entry_id'    => (string) ( $context['gf_entry_id'] ?? 0 ),
				'claim_token'    => $claim_token,
				'route'          => function_exists( 'pf_current_route' ) ? pf_current_route() : 'main',
				'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
			],
		];
		$descriptor = pf_get_payout_descriptor();
		if ( $descriptor !== '' ) {
			$payout_args['statement_descriptor'] = $descriptor;
		}
		$payout = $stripe->payouts->create(
			$payout_args,
			[ 'stripe_account' => $account_id ]
		);

		pf_update_bpm_stripe_fields( $prize_entry_id, [
			'stripe_transfer_id'   => $transfer->id,
			'stripe_payout_id'     => $payout->id,
			'stripe_payout_status' => 'pending',
		] );

		pf_log_stripe_event(
			$competition_id,
			'stripe_payout_initiated',
			'Stripe transfer and payout created.',
			'info',
			[
				'prize_entry_id'     => $prize_entry_id,
				'stripe_account_id'  => $account_id,
				'stripe_transfer_id' => $transfer->id,
				'stripe_payout_id'   => $payout->id,
			]
		);

		error_log(
			sprintf(
				'PF FLOW stripe_return: payout initiated prize_entry_id=%d transfer_id=%s payout_id=%s',
				$prize_entry_id,
				$transfer->id,
				$payout->id
			)
		);

		// Payout succeeded — kill the claim transient so the matching Stripe
		// onboarding URL becomes inert. If the player Backs to Stripe and
		// re-completes, the idempotency guard above catches the existing OBP
		// and redirects to prize-paid without attempting a second payout.
		pf_delete_stripe_claim_context( $claim_token );

		// No query string: page-prize-paid.php is static and never reads
		// gf_entry — exposing an enumerable GF entry id in the URL is an
		// unnecessary information-disclosure surface.
		pf_safe_redirect_exit( pf_prize_page_url( 'prize-paid' ) );
	} catch ( Throwable $e ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => 'failed' ] );
		pf_log_stripe_event(
			$competition_id,
			'stripe_payout_failed',
			'Stripe payout initiation failed.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'error' => $e->getMessage() ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		error_log( 'PF FLOW stripe_return: exception during payout flow: ' . $e->getMessage() );
		exit;
	}
}

function pf_process_stripe_refresh_page(): void {
	ob_start(); // see pf_process_stripe_return_page — guard against leaked output
	$claim_token = isset( $_GET['pf_claim'] ) ? sanitize_text_field( (string) $_GET['pf_claim'] ) : '';
	$context     = pf_get_stripe_claim_context( $claim_token );
	if ( ! $context ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	// Re-assert the cash-winner outcome on this return-domain session so the
	// guarded /payout-failed/ target stays reachable across the cross-site
	// Stripe round-trip (see pf_process_stripe_return_page).
	if ( function_exists( 'pf_flow_set_outcome' ) && defined( 'PF_FLOW_OUTCOME_WINNER_CASH' ) ) {
		pf_flow_set_outcome( PF_FLOW_OUTCOME_WINNER_CASH );
	}

	// v2 path uses a different account-id key + endpoint.
	if ( ( $context['flow_version'] ?? '' ) === 'v2' ) {
		pf_v2_process_stripe_refresh( $claim_token, $context );
		return;
	}

	$stripe = pf_get_stripe_client();
	if ( ! $stripe ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	$account_id = sanitize_text_field( (string) ( $context['stripe_account_id'] ?? '' ) );
	if ( $account_id === '' ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	try {
		$account_link = pf_create_account_link( $stripe, $account_id, $claim_token );
		wp_safe_redirect( $account_link->url );
		exit;
	} catch ( Throwable $e ) {
		$competition_id = (int) ( $context['competition_id'] ?? 0 );
		pf_log_stripe_event(
			$competition_id,
			'stripe_refresh_failed',
			'Failed to generate refreshed Stripe account link.',
			'error',
			[ 'prize_entry_id' => (int) ( $context['prize_entry_id'] ?? 0 ), 'error' => $e->getMessage() ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
}

function pf_process_stripe_verify_page(): void {
	ob_start(); // see pf_process_stripe_return_page — guard against leaked output
	$claim_token = isset( $_GET['pf_claim'] ) ? sanitize_text_field( (string) $_GET['pf_claim'] ) : '';
	$context     = pf_get_stripe_claim_context( $claim_token );
	if ( ! $context ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	// Re-assert the cash-winner outcome on this return-domain session so the
	// guarded /payout-failed/ target stays reachable across the cross-site
	// Stripe round-trip (see pf_process_stripe_return_page).
	if ( function_exists( 'pf_flow_set_outcome' ) && defined( 'PF_FLOW_OUTCOME_WINNER_CASH' ) ) {
		pf_flow_set_outcome( PF_FLOW_OUTCOME_WINNER_CASH );
	}

	$verify_attempts = (int) ( $context['verify_attempts'] ?? 0 );
	if ( $verify_attempts > 3 ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'verify' ] ) );
		exit;
	}

	// v2 path: regenerate AccountLink off the v2 endpoints.
	if ( ( $context['flow_version'] ?? '' ) === 'v2' ) {
		pf_v2_process_stripe_verify( $claim_token, $context );
		return;
	}

	$stripe     = pf_get_stripe_client();
	$account_id = sanitize_text_field( (string) ( $context['stripe_account_id'] ?? '' ) );
	if ( ! $stripe || $account_id === '' ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	try {
		$account_link = pf_create_account_link( $stripe, $account_id, $claim_token );
		// Store the fresh link URL in the context so the template can render the CTA
		// without issuing a blind redirect — the winner sees the interstitial first.
		$context['verify_link'] = (string) $account_link->url;
		pf_set_stripe_claim_context( $claim_token, $context );
		error_log( sprintf( 'PF FLOW stripe_verify: link generated attempt=%d account=%s', $verify_attempts, $account_id ) );
	} catch ( Throwable $e ) {
		$competition_id = (int) ( $context['competition_id'] ?? 0 );
		pf_log_stripe_event(
			$competition_id,
			'stripe_verify_link_failed',
			'Failed to generate verification account link.',
			'error',
			[ 'prize_entry_id' => (int) ( $context['prize_entry_id'] ?? 0 ), 'error' => $e->getMessage() ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
	// Falls through to the page template which renders the interstitial.
}

function pf_find_prize_entry_id_by_payout( string $payout_id ): int {
	if ( $payout_id === '' || ! function_exists( 'pf_bpm_prize_has_column' ) || ! pf_bpm_prize_has_column( 'stripe_payout_id' ) ) {
		return 0;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table WHERE stripe_payout_id = %s LIMIT 1",
		$payout_id
	) );
}

function pf_find_prize_entry_id_by_v2_account( string $account_id ): int {
	if ( $account_id === ''
		|| ! function_exists( 'pf_bpm_prize_has_column' )
		|| ! pf_bpm_prize_has_column( 'stripe_account_id_v2' ) ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE stripe_account_id_v2 = %s LIMIT 1",
		$account_id
	) );
}

function pf_get_prize_entry_competition_id( int $prize_entry_id ): int {
	if ( $prize_entry_id <= 0 ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT competition_id FROM $table WHERE id = %d LIMIT 1",
		$prize_entry_id
	) );
}

// ─── Stripe v2 Global Payouts — return / refresh / verify handlers ──────────

/**
 * Executes the v2 OutboundPayment for a cash-prize winner. Called from both
 * the browser-return path and the v2.core.account_link.returned webhook so that
 * whichever fires first wins. The atomic DB lock prevents a double payout if
 * both paths race (e.g. webhook fires while winner's browser is also returning).
 *
 * Caller is responsible for fetching the Stripe account and confirming
 * bank_status === 'active' before calling this function.
 *
 * @return array{status: string, obp_id: string}
 *   status values: 'ok' | 'already_done' | 'blocked' | 'cop_blocked' |
 *                  'no_gbba' | 'cop_ack_failed' | 'no_fa' | 'obp_failed'
 */
function pf_v2_execute_payout(
	int $prize_entry_id,
	string $account_id,
	int $competition_id,
	?string $claim_token,
	int $gf_entry_id = 0,
	bool $is_livemode = true
): array {
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';

	// Atomic lock: claim processing slot or abort if already taken. Replaces
	// the weak read-then-check pattern that allowed concurrent requests to both
	// read 'pending' and both create an OBP (race condition / double-payment).
	$rows = $wpdb->query( $wpdb->prepare(
		"UPDATE {$table} SET stripe_payout_status = 'processing' WHERE id = %d AND stripe_payout_status IN ('pending','failed')",
		$prize_entry_id
	) );
	if ( $rows === 0 ) {
		error_log( sprintf(
			'PF FLOW stripe_v2 execute_payout: atomic lock failed — prize_entry_id=%d not in pending/failed state; aborting.',
			$prize_entry_id
		) );
		return [ 'status' => 'already_done', 'obp_id' => '' ];
	}

	// Discover the gbba_ payment method + Confirmation of Payee result.
	$gbba             = pf_v2_discover_gb_bank_account( $account_id );
	$gbba_id          = (string) $gbba['gbba_id'];
	$cop_match_result = (string) $gbba['cop_result'];
	$bank_identity    = (array) ( $gbba['bank_identity'] ?? [] );
	// Persisted on every outcome so the site accumulates the Stripe-side bank
	// identity list (holder name as given with the bank details).
	$identity_fields  = [
		'stripe_bank_holder_name' => (string) ( $bank_identity['holder_name'] ?? '' ),
		'stripe_bank_last4'       => (string) ( $bank_identity['last4'] ?? '' ),
		'stripe_bank_fingerprint' => (string) ( $bank_identity['fingerprint'] ?? '' ),
	];

	if ( $gbba_id === '' ) {
		error_log( 'PF FLOW stripe_v2 execute_payout: could not resolve gbba_ for account=' . $account_id . ' raw=' . wp_json_encode( $gbba['raw'] ) );
		// Reset status so a future retry via webhook or refresh is possible.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET stripe_payout_status = 'pending' WHERE id = %d AND stripe_payout_status = 'processing'",
			$prize_entry_id
		) );
		return [ 'status' => 'no_gbba', 'obp_id' => '' ];
	}

	// Payout gate: manual blocklist (Stripe-side holder name / claim email) +
	// one-payout-per-bank-account campaign rule. Sits before the OutboundPayment
	// is created; 'payout_blocked' is outside the pending/failed retry set, so
	// neither webhook nor refresh can resurrect a blocked entry — release one
	// by resetting stripe_payout_status to 'pending'.
	$block = pf_v2_payout_block_reason( $prize_entry_id, $bank_identity );
	if ( $block['reason'] !== '' ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, array_merge( $identity_fields, [
			'stripe_gb_bank_account_id' => $gbba_id,
			'stripe_cop_result'         => $cop_match_result,
			'stripe_payout_status'      => 'payout_blocked',
		] ) );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_payout_blocked',
			'Payout withheld before OutboundPayment: ' . $block['reason'] . '.',
			'warning',
			[
				'prize_entry_id'   => $prize_entry_id,
				'gbba_id'          => $gbba_id,
				'reason'           => $block['reason'],
				'duplicate_of'     => $block['duplicate_of'],
				'bank_holder_name' => $identity_fields['stripe_bank_holder_name'],
				'bank_last4'       => $identity_fields['stripe_bank_last4'],
			]
		);
		error_log( sprintf(
			'PF FLOW stripe_v2 execute_payout: blocked reason=%s prize_entry_id=%d duplicate_of=%d holder=%s last4=%s',
			$block['reason'], $prize_entry_id, $block['duplicate_of'],
			$identity_fields['stripe_bank_holder_name'], $identity_fields['stripe_bank_last4']
		) );
		return [ 'status' => 'blocked', 'obp_id' => '' ];
	}

	// CoP policy: OPTION A STRICT — match/partial_match pay, anything else
	// withheld. Test mode relaxes; see pf_v2_cop_result_is_payable.
	if ( ! pf_v2_cop_result_is_payable( $cop_match_result, $is_livemode ) ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, array_merge( $identity_fields, [
			'stripe_gb_bank_account_id' => $gbba_id,
			'stripe_cop_result'         => $cop_match_result !== '' ? $cop_match_result : 'unknown',
			'stripe_payout_status'      => 'cop_blocked',
		] ) );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_cop_blocked',
			'CoP match_result not payable; payout withheld.',
			'warning',
			[ 'prize_entry_id' => $prize_entry_id, 'gbba_id' => $gbba_id, 'cop_result' => $cop_match_result ]
		);
		error_log( sprintf( 'PF FLOW stripe_v2 execute_payout: CoP not payable result=%s', $cop_match_result ) );
		return [ 'status' => 'cop_blocked', 'obp_id' => '', 'cop_result' => $cop_match_result ];
	}

	if ( pf_v2_should_acknowledge_cop( $cop_match_result ) ) {
		$ack = pf_v2_acknowledge_cop( $gbba_id, $account_id );
		if ( ! $ack['ok'] ) {
			pf_log_stripe_event(
				$competition_id,
				'stripe_v2_cop_ack_failed',
				'CoP acknowledge call failed; cannot proceed to OutboundPayment.',
				'error',
				[ 'prize_entry_id' => $prize_entry_id, 'gbba_id' => $gbba_id, 'error' => $ack['error'] ]
			);
			error_log( sprintf(
				'PF FLOW stripe_v2 execute_payout: CoP acknowledge failed gbba=%s :: %s',
				$gbba_id, wp_json_encode( $ack['error'] )
			) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET stripe_payout_status = 'pending' WHERE id = %d AND stripe_payout_status = 'processing'",
				$prize_entry_id
			) );
			return [ 'status' => 'cop_ack_failed', 'obp_id' => '' ];
		}
		error_log( sprintf(
			'PF FLOW stripe_v2 execute_payout: CoP acknowledged result=%s gbba=%s',
			$cop_match_result !== '' ? $cop_match_result : 'empty',
			$gbba_id
		) );
	}

	$fa_id = pf_stripe_financial_account_id();
	if ( $fa_id === '' ) {
		error_log( 'PF FLOW stripe_v2 execute_payout: STRIPE_FINANCIAL_ACCOUNT_ID not configured.' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET stripe_payout_status = 'pending' WHERE id = %d AND stripe_payout_status = 'processing'",
			$prize_entry_id
		) );
		return [ 'status' => 'no_fa', 'obp_id' => '' ];
	}

	$amount_pence = pf_get_prize_amount_pence( $prize_entry_id );
	if ( $amount_pence <= 0 ) {
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_no_amount',
			'No payout amount mapped for this prize_type; payout withheld.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id ]
		);
		error_log( sprintf( 'PF FLOW stripe_v2 execute_payout: no amount mapped prize_entry_id=%d', $prize_entry_id ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET stripe_payout_status = 'pending' WHERE id = %d AND stripe_payout_status = 'processing'",
			$prize_entry_id
		) );
		return [ 'status' => 'no_amount', 'obp_id' => '' ];
	}
	$op_payload = [
		'from'        => [ 'financial_account' => $fa_id, 'currency' => 'gbp' ],
		'to'          => [ 'recipient' => $account_id, 'payout_method' => $gbba_id, 'currency' => 'gbp' ],
		'amount'      => [ 'value' => $amount_pence, 'currency' => 'gbp' ],
		'description' => 'Pepsi Xmas Drop 2026 prize – GF entry #' . $gf_entry_id,
		'metadata'    => [
			'prize_entry_id' => (string) $prize_entry_id,
			'gf_entry_id'    => (string) $gf_entry_id,
			'claim_token'    => ( $claim_token !== null && $claim_token !== '' ) ? $claim_token : 'webhook',
			'route'          => function_exists( 'pf_current_route' ) ? pf_current_route() : 'main',
			'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
		],
	];
	$descriptor = pf_get_payout_descriptor();
	if ( $descriptor !== '' ) {
		$op_payload['statement_descriptor'] = $descriptor;
	}
	$op_result = pf_stripe_v2_post( '/money_management/outbound_payments', $op_payload, [], pf_stripe_payouts_key() );

	if ( ! $op_result['ok'] ) {
		pf_update_bpm_stripe_fields( $prize_entry_id, array_merge( $identity_fields, [
			'stripe_gb_bank_account_id' => $gbba_id,
			'stripe_cop_result'         => $cop_match_result,
			'stripe_payout_status'      => 'failed',
		] ) );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_outbound_payment_failed',
			'v2 OutboundPayment create failed.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'account_id' => $account_id, 'gbba_id' => $gbba_id, 'error' => $op_result['error'] ]
		);
		error_log( 'PF FLOW stripe_v2 execute_payout: OutboundPayment create failed :: ' . wp_json_encode( $op_result['error'] ) );
		return [ 'status' => 'obp_failed', 'obp_id' => '' ];
	}

	$obp_id     = (string) ( $op_result['data']['id'] ?? '' );
	$obp_status = (string) ( $op_result['data']['status'] ?? 'processing' );

	pf_update_bpm_stripe_fields( $prize_entry_id, array_merge( $identity_fields, [
		'stripe_outbound_payment_id' => $obp_id,
		'stripe_gb_bank_account_id'  => $gbba_id,
		'stripe_cop_result'          => $cop_match_result,
		'stripe_payout_status'       => $obp_status,
	] ) );
	pf_log_stripe_event(
		$competition_id,
		'stripe_v2_outbound_payment_created',
		'v2 OutboundPayment created.',
		'info',
		[
			'prize_entry_id'             => $prize_entry_id,
			'stripe_account_id_v2'       => $account_id,
			'stripe_outbound_payment_id' => $obp_id,
			'stripe_gb_bank_account_id'  => $gbba_id,
			'cop_result'                 => $cop_match_result,
			'status'                     => $obp_status,
			'triggered_by'               => $claim_token !== null ? 'browser_return' : 'webhook',
		] + pf_v2_obp_log_fields( (array) $op_result['data'] )
	);
	error_log( sprintf(
		'PF FLOW stripe_v2 execute_payout: outbound_payment created prize_entry_id=%d obp_id=%s status=%s',
		$prize_entry_id, $obp_id, $obp_status
	) );

	// Context deletion is intentionally NOT done here — the browser path
	// (pf_v2_process_stripe_return) deletes the context after its own payout.
	// Deleting here would race the browser redirect: webhook fires first,
	// wipes the context, then stripe-return arrives and finds nothing.

	return [ 'status' => 'ok', 'obp_id' => $obp_id ];
}

function pf_get_bpm_stripe_outbound_payment_id( int $prize_entry_id ): string {
	if ( $prize_entry_id <= 0
		|| ! function_exists( 'pf_bpm_prize_has_column' )
		|| ! pf_bpm_prize_has_column( 'stripe_outbound_payment_id' ) ) {
		return '';
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT stripe_outbound_payment_id FROM $table WHERE id = %d LIMIT 1",
		$prize_entry_id
	) );
}

function pf_find_prize_entry_id_by_outbound_payment( string $obp_id ): int {
	if ( $obp_id === ''
		|| ! function_exists( 'pf_bpm_prize_has_column' )
		|| ! pf_bpm_prize_has_column( 'stripe_outbound_payment_id' ) ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE stripe_outbound_payment_id = %s LIMIT 1",
		$obp_id
	) );
}

/**
 * Where to send a winner whose payout lock was already taken ('already_done').
 * Reads the entry's real status instead of assuming success: the lock also
 * fails for cop_blocked / payout_blocked entries and while a parallel webhook
 * is mid-payout, and none of those may land on a "you've been paid" page
 * unless a payout actually exists or is in flight.
 */
/**
 * Stripe marks the recipient's bank_accounts.local capability `rejected` when
 * it won't let that recipient receive funds (platform- or Stripe-initiated).
 * Terminal: record it, never pay, send the winner to support. Skipped if a
 * payout already exists so a late rejection can't rewrite a paid entry.
 * 'account_rejected' sits outside the pending/failed lock set, so no trigger
 * path can retry it — release one manually by resetting to 'pending'.
 */
function pf_v2_mark_account_rejected( int $prize_entry_id, int $competition_id, string $account_id, string $via ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}bpm_prize_entries SET stripe_payout_status = 'account_rejected'
		 WHERE id = %d AND ( stripe_outbound_payment_id IS NULL OR stripe_outbound_payment_id = '' )",
		$prize_entry_id
	) );
	pf_log_stripe_event(
		$competition_id,
		'stripe_v2_account_rejected',
		'Stripe rejected the recipient bank_accounts capability; payout withheld.',
		'warning',
		[ 'prize_entry_id' => $prize_entry_id, 'account_id' => $account_id, 'via' => $via ]
	);
	error_log( sprintf( 'PF FLOW stripe_v2 %s: bank_status=rejected prize_entry_id=%d; payout withheld.', $via, $prize_entry_id ) );
}

/**
 * Support-facing fields from a v2 OutboundPayment object, for the audit log:
 * Stripe's hosted receipt (live 60 days), expected arrival, and the trace id
 * the winner's bank can search for. status_details explains failed/returned.
 */
function pf_v2_obp_log_fields( array $obp ): array {
	return array_filter( [
		'receipt_url'           => (string) ( $obp['receipt_url'] ?? '' ),
		'expected_arrival_date' => (string) ( $obp['expected_arrival_date'] ?? '' ),
		'trace_id'              => (string) ( $obp['trace_id']['value'] ?? '' ),
		'trace_id_status'       => (string) ( $obp['trace_id']['status'] ?? '' ),
		'status_details'        => $obp['status_details'] ?? null,
	], static fn( $v ) => $v !== '' && $v !== null );
}

function pf_v2_entry_payout_status( int $prize_entry_id ): string {
	global $wpdb;
	return strtolower( (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT stripe_payout_status FROM {$wpdb->prefix}bpm_prize_entries WHERE id = %d",
		$prize_entry_id
	) ) );
}

function pf_v2_settled_entry_redirect_url( int $prize_entry_id, string $claim_token ): string {
	switch ( pf_v2_entry_payout_status( $prize_entry_id ) ) {
		case 'cop_blocked':
			return pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'cop_mismatch' ] );
		case 'payout_blocked':
			return pf_prize_page_url( 'payout-failed', [ 'pf_reason' => 'review' ] );
		case 'failed':
		case 'account_rejected':
		case 'canceled':
		case 'cancelled':
		case 'returned':
			return pf_prize_page_url( 'payout-failed' );
		default:
			// processing (lock held by a parallel webhook, or OBP in flight),
			// posted, paid.
			return pf_prize_page_url( 'prize-paid' );
	}
}

/**
 * CoP retry: a cop_blocked winner pressed TRY AGAIN on /payout-failed/. Their
 * recipient is already active, so re-running the payout would just re-block on
 * the same bank account. Instead send them back to Stripe to change their bank
 * details (account_update link); the payout re-runs on return / webhook.
 * If Stripe won't issue the link, stop at the support message — never claim
 * the prize has been paid.
 */
function pf_v2_process_cop_retry( string $claim_token, string $account_id, int $prize_entry_id, int $competition_id ): void {
	$link = pf_stripe_v2_post( '/core/account_links', [
		'account'  => $account_id,
		'use_case' => [
			'type'           => 'account_update',
			'account_update' => [
				'return_url'     => pf_stripe_public_url( pf_prize_page_url( 'stripe-return',  [ 'pf_claim' => $claim_token ] ) ),
				'refresh_url'    => pf_stripe_public_url( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) ),
			],
		],
	] );
	$url = $link['ok'] ? (string) ( $link['data']['url'] ?? '' ) : '';
	if ( $url === '' ) {
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_cop_retry_link_failed',
			'Could not create account_update link for CoP retry.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'error' => $link['error'] ?? null ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	// Re-open the entry for payout only once the winner has somewhere to go.
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}bpm_prize_entries SET stripe_payout_status = 'pending' WHERE id = %d AND stripe_payout_status = 'cop_blocked'",
		$prize_entry_id
	) );
	pf_log_stripe_event(
		$competition_id,
		'stripe_v2_cop_retry',
		'CoP-blocked winner sent back to Stripe to update bank details.',
		'info',
		[ 'prize_entry_id' => $prize_entry_id ]
	);
	wp_safe_redirect( $url );
	exit;
}

function pf_v2_process_stripe_return( string $claim_token, array $context ): void {
	$competition_id = (int) ( $context['competition_id'] ?? 0 );
	$prize_entry_id = (int) ( $context['prize_entry_id'] ?? 0 );
	$account_id     = sanitize_text_field( (string) ( $context['stripe_account_id_v2'] ?? '' ) );

	if ( $account_id === '' ) {
		error_log( 'PF FLOW stripe_v2 return: missing stripe_account_id_v2; redirect payout-failed.' );
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	$acct = pf_stripe_v2_get( '/core/accounts/' . rawurlencode( $account_id ), [
		'include' => [ 'configuration.recipient', 'requirements', 'identity' ],
	] );
	if ( ! $acct['ok'] ) {
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_account_fetch_failed',
			'v2 account fetch failed on return.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'account_id' => $account_id, 'error' => $acct['error'] ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	// PF_V2_TBD: confirm exact capability status path. Best-guess
	// configuration.recipient.capabilities.bank_accounts.local.status with
	// values active / pending / inactive / unrequested.
	$bank_status = (string) ( $acct['data']['configuration']['recipient']['capabilities']['bank_accounts']['local']['status'] ?? '' );
	$currently_due = (array) ( $acct['data']['requirements']['currently_due'] ?? [] );

	if ( strtolower( $bank_status ) === 'rejected' ) {
		pf_v2_mark_account_rejected( $prize_entry_id, $competition_id, $account_id, 'return' );
		pf_delete_stripe_claim_context( $claim_token );
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	if ( strtolower( $bank_status ) !== 'active' ) {
		if ( ! empty( $currently_due ) ) {
			$verify_attempts = (int) ( $context['verify_attempts'] ?? 0 ) + 1;
			$context['verify_attempts'] = $verify_attempts;
			pf_set_stripe_claim_context( $claim_token, $context );
			error_log( sprintf(
				'PF FLOW stripe_v2 return: bank_status=%s currently_due=%s attempt=%d; redirect stripe-verify.',
				$bank_status, implode( ',', $currently_due ), $verify_attempts
			) );
			$dest = $verify_attempts > 3
				? pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'verify' ] )
				: pf_prize_page_url( 'stripe-verify', [ 'pf_claim' => $claim_token ] );
			wp_safe_redirect( $dest );
			exit;
		}
		error_log( sprintf(
			'PF FLOW stripe_v2 return: bank_status=%s with no currently_due; redirect stripe-refresh.',
			$bank_status
		) );
		wp_safe_redirect( pf_prize_page_url( 'stripe-refresh', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	$gf_entry_id = (int) ( $context['gf_entry_id'] ?? 0 );
	$is_livemode = (bool) ( $acct['data']['livemode'] ?? true );

	$result = pf_v2_execute_payout( $prize_entry_id, $account_id, $competition_id, $claim_token, $gf_entry_id, $is_livemode );

	switch ( $result['status'] ) {
		case 'ok':
			pf_safe_redirect_exit( pf_prize_page_url( 'prize-paid' ) );
			return;
		case 'already_done':
			pf_safe_redirect_exit( pf_v2_settled_entry_redirect_url( $prize_entry_id, $claim_token ) );
			return;
		case 'blocked':
			wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'review' ] ) );
			exit;
		case 'cop_blocked':
			wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'cop_mismatch' ] ) );
			exit;
		default:
			wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
			exit;
	}
}

function pf_v2_process_stripe_refresh( string $claim_token, array $context ): void {
	$account_id     = sanitize_text_field( (string) ( $context['stripe_account_id_v2'] ?? '' ) );
	$prize_entry_id = (int) ( $context['prize_entry_id'] ?? 0 );
	$competition_id = (int) ( $context['competition_id'] ?? 0 );
	$gf_entry_id    = (int) ( $context['gf_entry_id'] ?? 0 );

	if ( $account_id === '' ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}

	// Fetch account to check if onboarding is already complete.
	$acct        = pf_stripe_v2_get( '/core/accounts/' . rawurlencode( $account_id ), [
		'include' => [ 'configuration.recipient' ],
	] );
	$bank_status = (string) ( $acct['data']['configuration']['recipient']['capabilities']['bank_accounts']['local']['status'] ?? '' );
	$is_livemode = (bool) ( $acct['data']['livemode'] ?? true );

	if ( $acct['ok'] && strtolower( $bank_status ) === 'rejected' ) {
		pf_v2_mark_account_rejected( $prize_entry_id, $competition_id, $account_id, 'refresh' );
		pf_delete_stripe_claim_context( $claim_token );
		wp_safe_redirect( pf_prize_page_url( 'payout-failed' ) );
		exit;
	}

	if ( $acct['ok'] && $bank_status === 'active' ) {
		// Account fully onboarded — winner is on the "you're ready to go" page.
		// Attempt payout (webhook may have already fired; atomic lock handles it).
		error_log( sprintf( 'PF FLOW stripe_v2 refresh: account active, executing payout prize_entry_id=%d', $prize_entry_id ) );
		if ( function_exists( 'pf_flow_set_outcome' ) && defined( 'PF_FLOW_OUTCOME_WINNER_CASH' ) ) {
			pf_flow_set_outcome( PF_FLOW_OUTCOME_WINNER_CASH );
		}
		if ( pf_v2_entry_payout_status( $prize_entry_id ) === 'cop_blocked' ) {
			pf_v2_process_cop_retry( $claim_token, $account_id, $prize_entry_id, $competition_id );
		}
		$result = pf_v2_execute_payout( $prize_entry_id, $account_id, $competition_id, $claim_token, $gf_entry_id, $is_livemode );
		error_log( sprintf( 'PF FLOW stripe_v2 refresh: payout result=%s prize_entry_id=%d', $result['status'], $prize_entry_id ) );
		switch ( $result['status'] ) {
			case 'ok':
				pf_safe_redirect_exit( pf_prize_page_url( 'prize-paid' ) );
				return;
			case 'already_done':
				pf_safe_redirect_exit( pf_v2_settled_entry_redirect_url( $prize_entry_id, $claim_token ) );
				return;
			case 'blocked':
				wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'review' ] ) );
				exit;
			case 'cop_blocked':
				wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token, 'pf_reason' => 'cop_mismatch' ] ) );
				exit;
			default:
				wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
				exit;
		}
	}

	// Account not yet fully onboarded — regenerate the AccountLink so they can
	// complete their bank details.
	$res = pf_v2_create_account_link( $account_id, $claim_token );
	if ( ! $res['ok'] ) {
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_refresh_failed',
			'Failed to regenerate v2 account link.',
			'error',
			[ 'prize_entry_id' => $prize_entry_id, 'error' => $res['error'] ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
	$url = (string) ( $res['data']['url'] ?? '' );
	if ( $url === '' ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
	wp_safe_redirect( $url );
	exit;
}

function pf_v2_process_stripe_verify( string $claim_token, array $context ): void {
	$account_id = sanitize_text_field( (string) ( $context['stripe_account_id_v2'] ?? '' ) );
	if ( $account_id === '' ) {
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
	$res = pf_v2_create_account_link( $account_id, $claim_token );
	if ( ! $res['ok'] ) {
		$competition_id = (int) ( $context['competition_id'] ?? 0 );
		pf_log_stripe_event(
			$competition_id,
			'stripe_v2_verify_link_failed',
			'Failed to generate v2 verification account link.',
			'error',
			[ 'prize_entry_id' => (int) ( $context['prize_entry_id'] ?? 0 ), 'error' => $res['error'] ]
		);
		wp_safe_redirect( pf_prize_page_url( 'payout-failed', [ 'pf_claim' => $claim_token ] ) );
		exit;
	}
	$context['verify_link'] = (string) ( $res['data']['url'] ?? '' );
	pf_set_stripe_claim_context( $claim_token, $context );
	error_log( sprintf( 'PF FLOW stripe_v2 verify: link generated account=%s', $account_id ) );
	// Falls through to template render.
}

function pf_handle_stripe_webhook( WP_REST_Request $req ): WP_REST_Response {
	$secret    = defined( 'STRIPE_WEBHOOK_SECRET' )    ? trim( (string) STRIPE_WEBHOOK_SECRET )    : '';
	$secret_v2 = defined( 'STRIPE_WEBHOOK_SECRET_V2' ) ? trim( (string) STRIPE_WEBHOOK_SECRET_V2 ) : '';

	if ( ( $secret === '' && $secret_v2 === '' ) || ! pf_stripe_bootstrap() ) {
		error_log( 'PF Stripe webhook: not configured (missing secret or Stripe SDK).' );
		return new WP_REST_Response( [ 'error' => 'Stripe webhook not configured.' ], 500 );
	}

	$payload   = $req->get_body();
	$signature = $req->get_header( 'stripe-signature' );
	if ( $signature === '' ) {
		error_log( 'PF Stripe webhook: missing Stripe-Signature header.' );
		return new WP_REST_Response( [ 'error' => 'Missing Stripe signature.' ], 400 );
	}

	$event      = null;
	$last_error = '';
	foreach ( array_filter( [ $secret, $secret_v2 ] ) as $try_secret ) {
		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $signature, $try_secret );
			break;
		} catch ( Throwable $e ) {
			$last_error = $e->getMessage();
		}
	}
	if ( $event === null ) {
		error_log( 'PF Stripe webhook: signature verification failed: ' . $last_error );
		return new WP_REST_Response( [ 'error' => 'Invalid webhook signature.' ], 400 );
	}

	$type = (string) ( $event->type ?? '' );
	$obj  = $event->data->object ?? null;
	$event_id = sanitize_text_field( (string) ( $event->id ?? '' ) );

	error_log( sprintf( 'PF Stripe webhook received: event_id=%s type=%s', $event_id, $type ) );

	if ( str_starts_with( $type, 'v2.' ) ) {
		return pf_handle_stripe_v2_webhook( $event, $event_id, $type, $obj );
	}

	if ( $type === 'payout.paid' || $type === 'payout.failed' ) {
		$payout_id = sanitize_text_field( (string) ( $obj->id ?? '' ) );
		$metadata  = (array) ( $obj->metadata ?? [] );
		$prize_entry_id = isset( $metadata['prize_entry_id'] ) ? (int) $metadata['prize_entry_id'] : 0;
		if ( $prize_entry_id <= 0 ) {
			$prize_entry_id = pf_find_prize_entry_id_by_payout( $payout_id );
		}

		if ( $prize_entry_id > 0 ) {
			$status = ( $type === 'payout.paid' ) ? 'paid' : 'failed';
			pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => $status ] );
			pf_log_stripe_event(
				pf_get_prize_entry_competition_id( $prize_entry_id ),
				'stripe_webhook_' . str_replace( '.', '_', $type ),
				'Stripe webhook processed: ' . $type,
				'info',
				[ 'prize_entry_id' => $prize_entry_id, 'stripe_payout_id' => $payout_id ]
			);
		} else {
			// In multi-install setups (several promos sharing the same Stripe
			// platform account) every install receives
			// every event. Events whose prize_entry_id isn't in this install's
			// DB belong to a sibling install — log + 200 OK so Stripe doesn't
			// retry on what's actually expected behaviour.
			error_log(
				sprintf(
					'PF Stripe webhook: prize_entry_id not found in this install (likely belongs to a sibling install). type=%s payout_id=%s',
					$type,
					$payout_id
				)
			);
		}
	} else {
		error_log( sprintf( 'PF Stripe webhook: unhandled event type=%s', $type ) );
	}

	return new WP_REST_Response( [ 'received' => true ], 200 );
}

/**
 * Dispatcher for v2-namespaced events (Stripe-Version: 2026-10-07.preview).
 * PF_V2_TBD: confirm exact v2 event-type strings in test mode and update the
 * in_array() list below if Stripe uses a different naming convention.
 */
function pf_handle_stripe_v2_webhook( $event, string $event_id, string $type, $obj ): WP_REST_Response {
	error_log( sprintf( 'PF FLOW stripe_v2 webhook: event_id=%s type=%s', $event_id, $type ) );

	if ( $type === 'v2.core.account_link.returned' ) {
		// Primary payout trigger: Stripe's v2 hosted onboarding no longer
		// auto-redirects to return_url — winners land on a "you're ready to go"
		// page. This webhook fires when they complete the bank-detail form, so
		// we must trigger the payout here rather than waiting for a browser return.

		// v2 thin-event: account ID lives in data.account_id when Stripe delivers
		// the full payload. On resends Stripe strips data to a minimal envelope,
		// so we fall back to fetching the full event from the API by event ID.
		$account_id = '';
		$event_data = $event->data ?? null;
		if ( is_object( $event_data ) && isset( $event_data->account_id ) && (string) $event_data->account_id !== '' ) {
			$account_id = sanitize_text_field( (string) $event_data->account_id );
		} elseif ( isset( $event->context ) && is_string( $event->context ) && $event->context !== '' ) {
			$account_id = sanitize_text_field( $event->context );
		} elseif ( is_object( $obj ) && isset( $obj->account ) && (string) $obj->account !== '' ) {
			$account_id = sanitize_text_field( (string) $obj->account );
		}

		// Thin-envelope fallback: fetch full event from API to get data.account_id.
		if ( $account_id === '' && $event_id !== '' ) {
			$full = pf_stripe_v2_get( '/core/events/' . rawurlencode( $event_id ) );
			if ( $full['ok'] ) {
				$full_data = $full['data']['data'] ?? [];
				$account_id = sanitize_text_field( (string) ( $full_data['account_id'] ?? '' ) );
			}
			error_log( sprintf(
				'PF FLOW stripe_v2 webhook: account_link.returned fetched full event account_id=%s',
				$account_id !== '' ? $account_id : 'not_found'
			) );
		}

		error_log( sprintf(
			'PF FLOW stripe_v2 webhook: account_link.returned account_id=%s',
			$account_id !== '' ? $account_id : 'not_found'
		) );

		if ( $account_id === '' ) {
			error_log( 'PF FLOW stripe_v2 webhook: account_link.returned — could not resolve account_id; giving up.' );
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		$prize_entry_id = pf_find_prize_entry_id_by_v2_account( $account_id );
		if ( $prize_entry_id <= 0 ) {
			error_log( sprintf(
				'PF FLOW stripe_v2 webhook: account_link.returned — prize entry not found for account=%s (sibling install?)',
				$account_id
			) );
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		$competition_id = pf_get_prize_entry_competition_id( $prize_entry_id );

		// Confirm bank_status is active before attempting payout.
		$acct = pf_stripe_v2_get( '/core/accounts/' . rawurlencode( $account_id ), [
			'include' => [ 'configuration.recipient', 'requirements' ],
		] );
		if ( ! $acct['ok'] ) {
			error_log( sprintf(
				'PF FLOW stripe_v2 webhook: account_link.returned — account fetch failed account=%s',
				$account_id
			) );
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		$bank_status    = (string) ( $acct['data']['configuration']['recipient']['capabilities']['bank_accounts']['local']['status'] ?? '' );
		$is_livemode    = (bool) ( $acct['data']['livemode'] ?? true );
		$acct_meta      = (array) ( $acct['data']['metadata'] ?? [] );
		$gf_entry_id    = (int) ( $acct_meta['gf_entry_id'] ?? 0 );
		$claim_token_wh = (string) ( $acct_meta['claim_token'] ?? 'webhook' );

		if ( strtolower( $bank_status ) === 'rejected' ) {
			pf_v2_mark_account_rejected( $prize_entry_id, $competition_id, $account_id, 'webhook' );
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		if ( strtolower( $bank_status ) !== 'active' ) {
			error_log( sprintf(
				'PF FLOW stripe_v2 webhook: account_link.returned — bank_status=%s not active prize_entry_id=%d; no payout',
				$bank_status, $prize_entry_id
			) );
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		$result = pf_v2_execute_payout( $prize_entry_id, $account_id, $competition_id, $claim_token_wh, $gf_entry_id, $is_livemode );
		error_log( sprintf(
			'PF FLOW stripe_v2 webhook: account_link.returned payout result=%s prize_entry_id=%d obp_id=%s',
			$result['status'],
			$prize_entry_id,
			$result['obp_id'] ?? ''
		) );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	if ( in_array( $type, [
		'v2.money_management.outbound_payment.posted',
		'v2.money_management.outbound_payment.failed',
		'v2.money_management.outbound_payment.canceled',
		'v2.money_management.outbound_payment.cancelled', // legacy spelling, harmless
		'v2.money_management.outbound_payment.returned',
	], true ) ) {
		// v2 thin-event: $obj is empty; OBP ID is in related_object.id.
		$obp_id = sanitize_text_field( (string) ( $obj->id ?? '' ) );
		if ( $obp_id === '' ) {
			$related = $event->related_object ?? null;
			$obp_id  = sanitize_text_field( (string) ( is_object( $related ) ? ( $related->id ?? '' ) : '' ) );
		}
		$metadata = (array) ( $obj->metadata ?? [] );
		$prize_entry_id = isset( $metadata['prize_entry_id'] ) ? (int) $metadata['prize_entry_id'] : 0;
		if ( $prize_entry_id <= 0 ) {
			$prize_entry_id = pf_find_prize_entry_id_by_outbound_payment( $obp_id );
		}
		if ( $prize_entry_id > 0 ) {
			// Store Stripe's verbatim status names (posted / failed / canceled /
			// returned) — matches what the Stripe Dashboard shows so admin UI
			// and Stripe Dashboard agree on terminology.
			$status_from_event = (string) str_replace( 'v2.money_management.outbound_payment.', '', $type );
			// Fallback to the object's `status` field if the event suffix is
			// somehow unexpected; should never trigger but defensive.
			$obj_status = (string) ( $obj->status ?? '' );
			$status = $status_from_event !== '' ? $status_from_event : ( $obj_status !== '' ? $obj_status : 'unknown' );
			pf_update_bpm_stripe_fields( $prize_entry_id, [ 'stripe_payout_status' => $status ] );
			// Best-effort: pull receipt / arrival / trace id (and failure
			// reason) onto the audit row; the status update above never
			// depends on this call succeeding.
			$obp_fields = [];
			if ( $obp_id !== '' ) {
				$obp = pf_stripe_v2_get( '/money_management/outbound_payments/' . rawurlencode( $obp_id ), [], [], pf_stripe_payouts_key() );
				$obp_fields = $obp['ok'] ? pf_v2_obp_log_fields( (array) $obp['data'] ) : [];
			}
			pf_log_stripe_event(
				pf_get_prize_entry_competition_id( $prize_entry_id ),
				'stripe_v2_webhook_' . str_replace( '.', '_', $type ),
				'Stripe v2 webhook processed: ' . $type,
				in_array( $status, [ 'failed', 'canceled', 'cancelled', 'returned' ], true ) ? 'warning' : 'info',
				[ 'prize_entry_id' => $prize_entry_id, 'stripe_outbound_payment_id' => $obp_id ] + $obp_fields
			);
		} else {
			// Sibling-install event (e.g. marstons OBP arriving at the pepsi
			// install since both share the platform account). 200 + log so
			// Stripe doesn't retry.
			error_log( sprintf(
				'PF Stripe v2 webhook: prize_entry_id not found for type=%s obp_id=%s (likely sibling install).',
				$type,
				$obp_id
			) );
		}
		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	error_log( sprintf( 'PF Stripe v2 webhook: unhandled type=%s', $type ) );
	return new WP_REST_Response( [ 'received' => true ], 200 );
}
