<?php
defined( 'ABSPATH' ) || exit;

// ── Route shortcode ───────────────────────────────────────────────────────────
// Wrap CMS content that should only appear for specific routes.
//
// Usage:
//   [pf_route route="main"]This text is only on the main site.[/pf_route]
//   [pf_route route="alt"]This text is only on the Marstons site.[/pf_route]
//   [pf_route route="main,alt"]Shown on both.[/pf_route]
//
// The `route` attribute is a comma-separated list of route slugs. If the
// current route matches any entry the inner content is returned; otherwise
// an empty string is returned so CMS editors see nothing on the live page.
add_shortcode( 'pf_route', function ( $atts, string $content = '' ): string {
	$atts = shortcode_atts( [ 'route' => 'main' ], $atts, 'pf_route' );

	$allowed_routes = array_map( 'trim', explode( ',', strtolower( (string) $atts['route'] ) ) );
	$current_route  = function_exists( 'pf_current_route' ) ? pf_current_route() : 'main';

	if ( ! in_array( $current_route, $allowed_routes, true ) ) {
		return '';
	}

	return do_shortcode( $content );
} );


// Allow the alt subdomain (PF_ALT_HOST) to serve from the same WP install
// without WordPress 301-redirecting it to the canonical site URL.
function pepsi_is_alt_host_request(): bool {
	if ( ! defined( 'PF_ALT_HOST' ) || PF_ALT_HOST === '' ) {
		return false;
	}
	$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	return $host === strtolower( (string) PF_ALT_HOST );
}

add_filter( 'redirect_canonical', function ( $redirect_url, $requested_url ) {
	if ( pepsi_is_alt_host_request() ) {
		return false;
	}
	return $redirect_url;
}, 10, 2 );

// Make home_url() / site_url() return the alt host on alt-host requests so WP
// generates URLs (links, redirects, REST endpoints) using the host the user is on.
add_filter( 'home_url', function ( $url ) {
	if ( ! pepsi_is_alt_host_request() ) {
		return $url;
	}
	$alt_host = (string) PF_ALT_HOST;
	$parts    = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return $url;
	}
	$scheme = $parts['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
	$path   = $parts['path']   ?? '';
	$query  = isset( $parts['query'] )    ? '?' . $parts['query']    : '';
	$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
	return $scheme . '://' . $alt_host . $path . $query . $frag;
}, 10, 1 );

add_filter( 'site_url', function ( $url ) {
	if ( ! pepsi_is_alt_host_request() ) {
		return $url;
	}
	$alt_host = (string) PF_ALT_HOST;
	$parts    = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return $url;
	}
	$scheme = $parts['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
	$path   = $parts['path']   ?? '';
	$query  = isset( $parts['query'] )    ? '?' . $parts['query']    : '';
	$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
	return $scheme . '://' . $alt_host . $path . $query . $frag;
}, 10, 1 );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'script', 'style', 'search-form', 'comment-list', 'comment-form', 'gallery', 'caption' ] );

	// Strip WP meta noise from <head>
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
} );

// Disable XML-RPC
add_filter( 'xmlrpc_enabled', '__return_false' );

// Asset `?ver=` stripping lives in wp-hardening.php (pf_strip_version_query),
// which exempts the theme's own dist/ bundles so they keep their cache-buster.

// GF 2.10 ships gravityforms.min.js with `defer` and uses top-level `let`
// declarations (e.g. `let gformIsSpinnerInitialized`). iOS Safari has a
// long-standing quirk where deferred classic scripts that declare lexical
// bindings at the top level can be re-evaluated under certain bfcache /
// history / preload conditions — the second pass throws
// "SyntaxError: Can't create duplicate variable: 'gformIsSpinnerInitialized'"
// and aborts the rest of GF's JS, including the file-uploader. The result
// (as seen on a GF upload form in iPhone simulator) is GF's multipart serialization
// sending an empty file body and the page appearing to refresh. Stripping
// `defer` from that one script makes it run inline at parse time, so the
// double-evaluation path can't fire.
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( stripos( (string) $tag, 'gravityforms.min.js' ) !== false
		|| stripos( (string) $tag, 'gravityforms.js' ) !== false ) {
		// Strip defer/async (any quote style, or attribute-only). Regex is
		// anchored so it only matches the attribute, not strings inside
		// src=".." or id="..".
		$tag = preg_replace( '/\s+(defer|async)(=([\'"])\2\3|=([\'"])[a-z]+\4)?(?=[\s>])/i', '', (string) $tag );
	}
	return $tag;
}, 99999, 2 );

const PF_DEBUG_OPTION = 'pf_debug_panel_enabled';

/**
 * Toggle for the on-page PF DEBUG panel + any other "is this dev?" surfaces.
 *
 * Admin-only by design. The debug panel exposes live prize-window state and
 * the WIN-ASSIST / WIDE shot triggers, so it must never be reachable by a
 * public visitor. The only ways in:
 *  1. wp-admin option `pf_debug_panel_enabled`  — flipped via Settings → PF
 *                                                 Debug (admin-only to set).
 *                                                 Persists in DB. THE canonical
 *                                                 toggle for stakeholder demos.
 *  2. Logged-in admin (`manage_options`)        — admins always see the panel
 *                                                 regardless of the option.
 *
 * The previous `?pf_debug=1` URL flag + cookie and the WP_DEBUG/env fallback
 * were removed during hardening: the URL flag returned true for ANY visitor,
 * exposing the panel publicly. Log in as an admin (or flip the option) to debug.
 */
function pf_is_debug_panel_visible(): bool {
	if ( (int) get_option( PF_DEBUG_OPTION, 0 ) === 1 ) {
		return true;
	}
	return ( is_user_logged_in() && current_user_can( 'manage_options' ) );
}

// Settings → PF Debug — single-checkbox admin page so non-developers can flip
// the panel on/off without touching wp-config or URLs. Only admins can see
// the menu item; the option is stored as `pf_debug_panel_enabled` (0|1).
add_action( 'admin_menu', function () {
	add_options_page(
		'PF Debug',
		'PF Debug',
		'manage_options',
		'pf-debug',
		'pf_debug_admin_render'
	);
} );

add_action( 'admin_init', function () {
	register_setting(
		'pf_debug_settings',
		PF_DEBUG_OPTION,
		[
			'type'              => 'integer',
			'sanitize_callback' => static fn( $v ) => empty( $v ) ? 0 : 1,
			'default'           => 0,
		]
	);
	// Payout blocklist settings — read by the payout gate in stripe-payout.php.
	// Constants defined there (PF_PAYOUT_*_OPTION); string literals here keep
	// registration safe even if load order changes.
	register_setting(
		'pf_debug_settings',
		'pf_payout_dedupe_enabled',
		[
			'type'              => 'string',
			'sanitize_callback' => static fn( $v ) => empty( $v ) ? '0' : '1',
			'default'           => '1',
		]
	);
	$textarea_lines = static function ( $v ): string {
		$lines = preg_split( '/[\r\n]+/', (string) $v ) ?: [];
		$lines = array_filter( array_map( static fn( $l ) => sanitize_text_field( trim( $l ) ), $lines ) );
		return implode( "\n", $lines );
	};
	register_setting( 'pf_debug_settings', 'pf_payout_blocklist_names',  [ 'type' => 'string', 'sanitize_callback' => $textarea_lines, 'default' => '' ] );
	register_setting( 'pf_debug_settings', 'pf_payout_blocklist_emails', [ 'type' => 'string', 'sanitize_callback' => $textarea_lines, 'default' => '' ] );
} );

// "Run backfill" button on Settings → PF Debug. Runs server-side in admin
// context — the REST endpoint 401s on a bare browser visit because WordPress
// treats cookie-authenticated REST requests without a nonce as anonymous.
add_action( 'admin_post_pf_backfill_bank_identity', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.' ) );
	}
	check_admin_referer( 'pf_backfill_bank_identity' );

	$out = function_exists( 'pf_stripe_v2_backfill_bank_identity_run' )
		? pf_stripe_v2_backfill_bank_identity_run( 50 )
		: [ 'ok' => false, 'error' => 'backfill function unavailable' ];

	$args = [
		'page'        => 'pf-debug',
		'pf_backfill' => empty( $out['ok'] ) ? 'error' : 'ok',
	];
	if ( empty( $out['ok'] ) ) {
		$args['pf_error'] = rawurlencode( (string) ( $out['error'] ?? 'unknown' ) );
	} else {
		$args['pf_processed'] = (int) ( $out['processed'] ?? 0 );
		$args['pf_updated']   = count( (array) ( $out['updated'] ?? [] ) );
		$args['pf_failed']    = count( (array) ( $out['failed'] ?? [] ) );
		$args['pf_remaining'] = (int) ( $out['remaining'] ?? 0 );
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
	exit;
} );

/**
 * Cash payouts grouped by Stripe-side bank identity (holder name + last4,
 * fingerprint-aware), most-paid first. Feeds the repeat-winner table on the
 * PF Debug settings page. Rows exist only where an OutboundPayment was
 * created; run the backfill endpoint to populate identity on older payouts.
 *
 * @return array<int, object{holder_name: string, last4: string, pay_count: int, entry_ids: string, last_paid: string}>
 */
function pf_payouts_by_bank_identity(): array {
	if ( ! function_exists( 'pf_bpm_prize_has_column' ) || ! pf_bpm_prize_has_column( 'stripe_bank_holder_name' ) ) {
		return [];
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	return (array) $wpdb->get_results(
		"SELECT
			COALESCE(NULLIF(stripe_bank_holder_name,''), '(name not captured)') AS holder_name,
			COALESCE(NULLIF(stripe_bank_last4,''), '?') AS last4,
			COUNT(*) AS pay_count,
			GROUP_CONCAT(id ORDER BY id) AS entry_ids,
			MAX(claimed_at) AS last_paid
		 FROM {$table}
		 WHERE prize_type LIKE 'cash%'
		   AND stripe_outbound_payment_id IS NOT NULL AND stripe_outbound_payment_id != ''
		 GROUP BY holder_name, last4
		 ORDER BY pay_count DESC, last_paid DESC
		 LIMIT 200"
	);
}

function pf_debug_admin_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.' ) );
	}
	$enabled = (int) get_option( PF_DEBUG_OPTION, 0 ) === 1;
	?>
	<div class="wrap">
		<h1>PF Debug</h1>
		<p>Toggle the on-page <strong>PF DEBUG</strong> panel (PERFECT SHOT / WIN-ASSIST / WIDE / RUN SELF-TEST) on <code>/game/</code>, plus the gated win-flow self-test endpoint.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'pf_debug_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( PF_DEBUG_OPTION ); ?>">Show debug panel</label></th>
					<td>
						<label>
							<input
								type="checkbox"
								id="<?php echo esc_attr( PF_DEBUG_OPTION ); ?>"
								name="<?php echo esc_attr( PF_DEBUG_OPTION ); ?>"
								value="1"
								<?php checked( $enabled ); ?>
							>
							Show the PF DEBUG panel to <strong>everyone</strong> on <code>/game/</code>.
						</label>
						<p class="description">
							Admins (<code>manage_options</code>) always see the panel regardless of this toggle.
							Leave this <strong>off</strong> on production unless you're actively running a
							live demo or stakeholder walkthrough — the panel exposes the prize-window state
							and the WIN-ASSIST / WIDE shot triggers.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">One payout per bank account</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="pf_payout_dedupe_enabled"
								value="1"
								<?php checked( get_option( 'pf_payout_dedupe_enabled', '1' ) === '1' ); ?>
							>
							Block a cash payout when the bank account (Stripe fingerprint, or holder name + last4) has already been paid this campaign.
						</label>
						<p class="description">
							Blocked entries get <code>stripe_payout_status = payout_blocked</code> and the winner sees the
							generic “payment under review” page. Release one by resetting its status to <code>pending</code>.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pf_payout_blocklist_names">Blocked bank holder names</label></th>
					<td>
						<textarea
							id="pf_payout_blocklist_names"
							name="pf_payout_blocklist_names"
							rows="6"
							cols="50"
							class="large-text code"
							placeholder="One name per line, e.g. J Smith"
						><?php echo esc_textarea( (string) get_option( 'pf_payout_blocklist_names', '' ) ); ?></textarea>
						<p class="description">
							Matched against the <strong>name given to Stripe with the bank details</strong> (not the claim-form name).
							Case, punctuation and extra spaces are ignored. Copy names from the table below.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pf_payout_blocklist_emails">Blocked claim emails</label></th>
					<td>
						<textarea
							id="pf_payout_blocklist_emails"
							name="pf_payout_blocklist_emails"
							rows="6"
							cols="50"
							class="large-text code"
							placeholder="One email per line"
						><?php echo esc_textarea( (string) get_option( 'pf_payout_blocklist_emails', '' ) ); ?></textarea>
						<p class="description">
							Checked at claim-form submission (before a Stripe onboarding link is created) and again at payout time.
							Easily dodged with a new address — the bank-account rules above are the real gate.
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>

		<?php if ( function_exists( 'pf_prize_schedule_admin_render' ) ) { pf_prize_schedule_admin_render(); } ?>

		<h2>Cash payouts by bank account</h2>
		<?php if ( isset( $_GET['pf_backfill'] ) ) : ?>
			<?php if ( $_GET['pf_backfill'] === 'ok' ) : ?>
				<div class="notice notice-success"><p>
					Backfill batch complete: <strong><?php echo (int) ( $_GET['pf_updated'] ?? 0 ); ?></strong> updated,
					<strong><?php echo (int) ( $_GET['pf_failed'] ?? 0 ); ?></strong> failed,
					<strong><?php echo (int) ( $_GET['pf_remaining'] ?? 0 ); ?></strong> remaining.
					<?php if ( (int) ( $_GET['pf_remaining'] ?? 0 ) > 0 ) : ?>
						Click <em>Run Stripe backfill</em> again until remaining is 0.
					<?php endif; ?>
					<?php if ( (int) ( $_GET['pf_failed'] ?? 0 ) > 0 ) : ?>
						Failures are logged as <code>PF FLOW stripe_v2 backfill</code> in the PHP log.
					<?php endif; ?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-error"><p>
					Backfill failed: <?php echo esc_html( rawurldecode( (string) ( $_GET['pf_error'] ?? 'unknown' ) ) ); ?>
				</p></div>
			<?php endif; ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 1em;">
			<input type="hidden" name="action" value="pf_backfill_bank_identity">
			<?php wp_nonce_field( 'pf_backfill_bank_identity' ); ?>
			<?php submit_button( 'Run Stripe backfill (50 entries per click)', 'secondary', 'submit', false ); ?>
			<p class="description">
				Fetches the bank holder name / last4 from Stripe for already-paid entries that don't have them yet.
				Read-only against Stripe — no payouts are created or changed. Click repeatedly until remaining is 0.
			</p>
		</form>
		<?php $pf_identity_rows = function_exists( 'pf_payouts_by_bank_identity' ) ? pf_payouts_by_bank_identity() : []; ?>
		<?php if ( empty( $pf_identity_rows ) ) : ?>
			<p>No payouts with a captured bank identity yet — run the backfill above to populate historical payouts.</p>
		<?php else : ?>
			<p>Grouped by the Stripe-side bank identity. Anything with <strong>2+ payouts</strong> is a repeat winner —
				add the holder name to the blocklist above.</p>
			<table class="widefat striped" style="max-width: 900px;">
				<thead>
					<tr>
						<th>Bank holder name (from Stripe)</th>
						<th>Last 4</th>
						<th>Payouts</th>
						<th>Prize entry IDs</th>
						<th>Last paid</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pf_identity_rows as $row ) : ?>
						<tr<?php echo ( (int) $row->pay_count > 1 ) ? ' style="background:#fcf0f1;"' : ''; ?>>
							<td><?php echo esc_html( (string) $row->holder_name ); ?></td>
							<td><?php echo esc_html( (string) $row->last4 ); ?></td>
							<td><strong><?php echo (int) $row->pay_count; ?></strong></td>
							<td><code><?php echo esc_html( (string) $row->entry_ids ); ?></code></td>
							<td><?php echo esc_html( (string) $row->last_paid ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

add_filter( 'gform_field_content', function ( $content, $field ) {
	if ( ! is_object( $field ) || strtolower( (string) ( $field->type ?? '' ) ) !== 'fileupload' ) {
		return $content;
	}
	$html = (string) $content;
	// Strip any existing accept / capture to avoid duplicates.
	$html = preg_replace( '/\s+(accept|capture)=("[^"]*"|\'[^\']*\')/i', '', $html );
	// Inject the camera-capture attributes onto each <input type="file">
	// (handles both single- and double-quoted `type` attributes).
	$html = preg_replace(
		'/(<input\b[^>]*?\btype=([\'"])file\2)/i',
		'$1 accept="image/*" capture="environment"',
		$html
	);
	return $html;
}, 10, 2 );

// No admin bar on frontend
add_filter( 'show_admin_bar', '__return_false' );

// Redirect feeds
add_action( 'do_feed',      fn() => wp_redirect( home_url() ), 1 );
add_action( 'do_feed_rdf',  fn() => wp_redirect( home_url() ), 1 );
add_action( 'do_feed_rss',  fn() => wp_redirect( home_url() ), 1 );
add_action( 'do_feed_rss2', fn() => wp_redirect( home_url() ), 1 );
add_action( 'do_feed_atom', fn() => wp_redirect( home_url() ), 1 );

/**
 * The active competition as saved by BPM → Competitions → Activate
 * (`bpm_active_competition_id`), falling back to the first competition.
 *
 * Deliberately NOT bpm_get_active_competition_id(): that honours a
 * `?competition_id=` URL parameter first, so any visitor could swap in
 * another competition's Page Flow (skipping steps) or its artwork.
 */
function pf_active_competition_id(): int {
	$id = (int) get_option( 'bpm_active_competition_id', 0 );
	if ( $id > 0 ) {
		return $id;
	}
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id ASC LIMIT 1" );
}

/**
 * Candidate competition IDs for Brand Config reads (asset URLs, bg deco,
 * sizes, alignment, styles).
 *
 * With an active competition, ONLY that one — an empty slot must stay empty
 * rather than silently borrowing another competition's artwork (e.g. the
 * football headline showing on the Xmas age gate). The cross-competition
 * scan is kept for when no competition is active.
 *
 * @return int[]
 */
function pepsi_get_candidate_competition_ids(): array {
	$ids = [];

	$active_id = pf_active_competition_id();
	if ( $active_id > 0 ) {
		return [ $active_id ];
	}

	if ( function_exists( 'bpm_current_competition_id' ) ) {
		$ids[] = (int) bpm_current_competition_id();
	}
	if ( isset( $_GET['competition_id'] ) ) {
		$ids[] = (int) $_GET['competition_id'];
	}

	// Fallback: include all known competitions so static templates can still resolve
	// Brand Config values when current-page competition detection is ambiguous.
	global $wpdb;
	if ( ! empty( $wpdb ) && isset( $wpdb->prefix ) ) {
		$table = $wpdb->prefix . 'bpm_competitions';
		$all_ids = $wpdb->get_col( "SELECT id FROM {$table} ORDER BY created_at DESC" );
		if ( is_array( $all_ids ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $all_ids ) );
		}
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	return $ids;
}

/**
 * Resolve a brand config asset URL with explicit competition fallbacks.
 *
 * @param string      $slot
 * @param string|null $page
 * @return string
 */
function pepsi_resolve_asset_url( string $slot, ?string $page = null ): string {
	// Alt-route override: on the alternative subdomain, try {slot}_alt first
	// (per-page, then global) and fall back to the original slot if unset.
	$is_alt_slot = substr( $slot, -4 ) === '_alt';
	if ( ! $is_alt_slot && function_exists( 'pf_current_route' ) && pf_current_route() === 'alt' ) {
		$alt_slot = $slot . '_alt';

		if ( $page !== null ) {
			$alt = pepsi_resolve_asset_url( $alt_slot, $page );
			if ( $alt !== '' ) {
				return $alt;
			}
		}

		$alt_global = pepsi_resolve_asset_url( $alt_slot, null );
		if ( $alt_global !== '' ) {
			return $alt_global;
		}
	}

	$url = function_exists( 'bpm_img_url' ) ? bpm_img_url( $slot, $page ) : '';
	if ( $url !== '' ) {
		return $url;
	}

	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return '';
	}

	foreach ( pepsi_get_candidate_competition_ids() as $competition_id ) {
		$fallback_url = BPM_Brand_Config::get_asset_url( $slot, $page, $competition_id );
		if ( $fallback_url !== '' ) {
			return $fallback_url;
		}
	}

	return '';
}

/**
 * Resolve an asset URL from multiple possible page keys.
 *
 * @param string   $slot
 * @param string[] $pages
 * @return string
 */
function pepsi_resolve_asset_url_multi( string $slot, array $pages ): string {
	$pages = pepsi_expand_page_keys( $pages );
	if ( empty( $pages ) ) {
		return '';
	}
	$preferred_page = pepsi_resolve_configured_page_key( $pages );
	$ordered_pages = array_values( array_unique( array_merge( [ $preferred_page ], $pages ) ) );
	foreach ( $ordered_pages as $page ) {
		$url = pepsi_resolve_asset_url( $slot, $page );
		if ( $url !== '' ) {
			return $url;
		}
	}
	return '';
}

/**
 * Resolve background deco config with explicit competition fallbacks.
 *
 * @param string $page
 * @return array{url:string,position:string,size:string}
 */
function pepsi_resolve_bg_deco( string $page ): array {
	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
	}

	$defaults = [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
	$page = trim( strtolower( (string) $page ) );
	$candidate_ids = pepsi_get_candidate_competition_ids();
	if ( empty( $candidate_ids ) ) {
		$candidate_ids = [ 0 ];
	}

	$active_id = pf_active_competition_id();
	$best = null;
	$best_score = -1;

	foreach ( $candidate_ids as $competition_id ) {
		$competition_id = (int) $competition_id;
		$deco = BPM_Brand_Config::get_bg_deco( $page, $competition_id );
		$position = (string) ( $deco['position'] ?? $defaults['position'] );
		$size = (string) ( $deco['size'] ?? $defaults['size'] );
		$url = (string) ( $deco['url'] ?? '' );

		$has_custom_position = trim( $position ) !== $defaults['position'];
		$has_custom_size = trim( $size ) !== $defaults['size'];
		$has_url = $url !== '';
		$is_active = $active_id > 0 && $competition_id === $active_id;

		// Prefer explicitly customized deco settings, then usable URL, then active competition.
		$score = 0;
		if ( $has_custom_position ) {
			$score += 4;
		}
		if ( $has_custom_size ) {
			$score += 3;
		}
		if ( $has_url ) {
			$score += 2;
		}
		if ( $is_active ) {
			$score += 1;
		}

		if ( $score > $best_score ) {
			$best_score = $score;
			$best = [
				'url' => $url,
				'position' => $position,
				'size' => $size,
			];
		}
	}

	if ( is_array( $best ) ) {
		return $best;
	}

	return $defaults;
}

/**
 * Resolve background deco from multiple possible page keys.
 *
 * @param string[] $pages
 * @return array{url:string,position:string,size:string}
 */
function pepsi_resolve_bg_deco_multi( array $pages ): array {
	$pages = pepsi_expand_page_keys( $pages );
	if ( empty( $pages ) ) {
		return [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
	}

	$preferred_page = pepsi_resolve_configured_page_key( $pages );
	$deco = pepsi_resolve_bg_deco( $preferred_page );

	// Keep position/size from configured key; only backfill URL from alternates if needed.
	if ( empty( $deco['url'] ) ) {
		$ordered_pages = array_values( array_unique( array_merge( [ $preferred_page ], $pages ) ) );
		foreach ( $ordered_pages as $page ) {
			$fallback_deco = pepsi_resolve_bg_deco( $page );
			if ( ! empty( $fallback_deco['url'] ) ) {
				$deco['url'] = $fallback_deco['url'];
				break;
			}
		}
	}

	return $deco;
}

/**
 * Choose the most likely configured page key from aliases.
 *
 * Prefers keys with explicit bg_deco config, then keys with page_assets config.
 *
 * @param string[] $pages
 * @return string
 */
function pepsi_resolve_configured_page_key( array $pages ): string {
	$pages = pepsi_expand_page_keys( $pages );
	if ( empty( $pages ) || ! class_exists( 'BPM_Brand_Config' ) ) {
		return $pages[0] ?? '';
	}

	$competition_ids = pepsi_get_candidate_competition_ids();
	if ( empty( $competition_ids ) ) {
		$competition_ids = [ 0 ];
	}

	$default_pos = '50% 100%';
	$default_size = '100%';

	// 1) Prefer keys with explicit non-default bg_deco values.
	foreach ( $competition_ids as $competition_id ) {
		$config = BPM_Brand_Config::get_config( (int) $competition_id );
		$bg_deco_config = is_array( $config['bg_deco'] ?? null ) ? $config['bg_deco'] : [];

		foreach ( $pages as $page ) {
			if ( empty( $bg_deco_config[ $page ] ) || ! is_array( $bg_deco_config[ $page ] ) ) {
				continue;
			}
			$pos = (string) ( $bg_deco_config[ $page ]['position'] ?? $default_pos );
			$size = (string) ( $bg_deco_config[ $page ]['size'] ?? $default_size );
			if ( trim( $pos ) !== $default_pos || trim( $size ) !== $default_size ) {
				return $page;
			}
		}
	}

	foreach ( $competition_ids as $competition_id ) {
		$config = BPM_Brand_Config::get_config( (int) $competition_id );
		$bg_deco_config = is_array( $config['bg_deco'] ?? null ) ? $config['bg_deco'] : [];
		$page_assets_config = is_array( $config['page_assets'] ?? null ) ? $config['page_assets'] : [];

		// 2) Then prefer keys that have any bg_deco row at all.
		foreach ( $pages as $page ) {
			if ( ! empty( $bg_deco_config[ $page ] ) ) {
				return $page;
			}
		}

		// 3) Then keys that have page assets.
		foreach ( $pages as $page ) {
			if ( ! empty( $page_assets_config[ $page ] ) ) {
				return $page;
			}
		}
	}

	return $pages[0];
}

/**
 * Expand page keys to include both dash and underscore variants.
 *
 * @param string[] $pages
 * @return string[]
 */
function pepsi_expand_page_keys( array $pages ): array {
	$expanded = [];

	foreach ( $pages as $page ) {
		$page = strtolower( trim( (string) $page ) );
		if ( $page === '' ) {
			continue;
		}

		$dash = str_replace( '_', '-', $page );
		$underscore = str_replace( '-', '_', $page );

		$expanded[] = $page;
		$expanded[] = $dash;
		$expanded[] = $underscore;
	}

	return array_values( array_unique( array_filter( $expanded ) ) );
}

/**
 * Resolve a per-page asset width value with explicit competition fallbacks.
 *
 * @param string $slot
 * @param string $page
 * @return string
 */
function pepsi_resolve_page_asset_size( string $slot, string $page ): string {
	$is_alt_slot = substr( $slot, -4 ) === '_alt';
	if ( ! $is_alt_slot && function_exists( 'pf_current_route' ) && pf_current_route() === 'alt' ) {
		$alt_size = pepsi_resolve_page_asset_size( $slot . '_alt', $page );
		if ( $alt_size !== '' ) {
			return $alt_size;
		}
	}

	$size = function_exists( 'bpm_img_size' ) ? (string) bpm_img_size( $slot, $page ) : '';
	if ( $size !== '' ) {
		return $size;
	}

	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return '';
	}

	foreach ( pepsi_get_candidate_competition_ids() as $competition_id ) {
		$fallback_size = (string) BPM_Brand_Config::get_page_asset_size( $slot, $page, $competition_id );
		if ( $fallback_size !== '' ) {
			return $fallback_size;
		}
	}

	return '';
}

/**
 * Resolve a per-page asset horizontal alignment with explicit competition fallbacks.
 *
 * @param string $slot
 * @param string $page
 * @return string start|center|end
 */
function pepsi_resolve_page_asset_alignment( string $slot, string $page ): string {
	$is_alt_slot = substr( $slot, -4 ) === '_alt';
	if ( ! $is_alt_slot && function_exists( 'pf_current_route' ) && pf_current_route() === 'alt' ) {
		$alt_alignment = pepsi_resolve_page_asset_alignment( $slot . '_alt', $page );
		if ( $alt_alignment !== 'center' ) {
			return $alt_alignment;
		}
	}

	$alignment = function_exists( 'bpm_img_alignment' ) ? (string) bpm_img_alignment( $slot, $page ) : 'center';
	if ( in_array( $alignment, [ 'start', 'center', 'end' ], true ) && $alignment !== 'center' ) {
		return $alignment;
	}

	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return 'center';
	}

	foreach ( pepsi_get_candidate_competition_ids() as $competition_id ) {
		$fallback_alignment = (string) BPM_Brand_Config::get_page_asset_alignment( $slot, $page, $competition_id );
		if ( in_array( $fallback_alignment, [ 'start', 'center', 'end' ], true ) && $fallback_alignment !== 'center' ) {
			return $fallback_alignment;
		}
	}

	return 'center';
}

/**
 * Resolve a global asset width value with explicit competition fallbacks.
 *
 * @param string $slot
 * @return string
 */
function pepsi_resolve_asset_size( string $slot ): string {
	$is_alt_slot = substr( $slot, -4 ) === '_alt';
	if ( ! $is_alt_slot && function_exists( 'pf_current_route' ) && pf_current_route() === 'alt' ) {
		$alt_size = pepsi_resolve_asset_size( $slot . '_alt' );
		if ( $alt_size !== '' ) {
			return $alt_size;
		}
	}

	$size = ( class_exists( 'BPM_Brand_Config' ) && method_exists( 'BPM_Brand_Config', 'get_asset_size' ) )
		? (string) BPM_Brand_Config::get_asset_size( $slot )
		: '';
	if ( $size !== '' ) {
		return $size;
	}

	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return '';
	}

	foreach ( pepsi_get_candidate_competition_ids() as $competition_id ) {
		$fallback_size = (string) BPM_Brand_Config::get_asset_size( $slot, $competition_id );
		if ( $fallback_size !== '' ) {
			return $fallback_size;
		}
	}

	return '';
}

/**
 * Resolve a global asset horizontal alignment with explicit competition fallbacks.
 *
 * @param string $slot
 * @return string start|center|end
 */
function pepsi_resolve_asset_alignment( string $slot ): string {
	$is_alt_slot = substr( $slot, -4 ) === '_alt';
	if ( ! $is_alt_slot && function_exists( 'pf_current_route' ) && pf_current_route() === 'alt' ) {
		$alt_alignment = pepsi_resolve_asset_alignment( $slot . '_alt' );
		if ( $alt_alignment !== 'center' ) {
			return $alt_alignment;
		}
	}

	$alignment = ( class_exists( 'BPM_Brand_Config' ) && method_exists( 'BPM_Brand_Config', 'get_asset_alignment' ) )
		? (string) BPM_Brand_Config::get_asset_alignment( $slot )
		: 'center';
	if ( in_array( $alignment, [ 'start', 'center', 'end' ], true ) && $alignment !== 'center' ) {
		return $alignment;
	}

	if ( ! class_exists( 'BPM_Brand_Config' ) ) {
		return 'center';
	}

	foreach ( pepsi_get_candidate_competition_ids() as $competition_id ) {
		$fallback_alignment = (string) BPM_Brand_Config::get_asset_alignment( $slot, $competition_id );
		if ( in_array( $fallback_alignment, [ 'start', 'center', 'end' ], true ) && $fallback_alignment !== 'center' ) {
			return $fallback_alignment;
		}
	}

	return 'center';
}

/**
 * Build resilient asset <img> markup from Brand Config.
 *
 * @param string      $slot
 * @param string|null $page
 * @param string      $class
 * @param string      $alt
 * @return string
 */
function pepsi_resolve_asset_img( string $slot, ?string $page, string $class = '', string $alt = '' ): string {
	$url = pepsi_resolve_asset_url( $slot, $page );
	if ( $url === '' ) {
		return '';
	}

	$class_attr = trim( $class );
	$class_html = $class_attr !== '' ? ' class="' . esc_attr( $class_attr ) . '"' : '';

	return '<img' . $class_html . ' src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />';
}

/**
 * Build inline style string for page-slot image size + alignment.
 *
 * @param string $slot
 * @param string $page
 * @return string
 */
function pepsi_page_asset_style_attr( string $slot, string $page ): string {
	$size = pepsi_resolve_page_asset_size( $slot, $page );
	$alignment = pepsi_resolve_page_asset_alignment( $slot, $page );
	$style_bits = [ 'height:auto', 'display:block' ];

	if ( $size !== '' ) {
		$style_bits[] = 'width:' . $size;
		$style_bits[] = 'max-width:none';
	}

	if ( $alignment === 'start' ) {
		$style_bits[] = 'margin-left:0';
		$style_bits[] = 'margin-right:auto';
	} elseif ( $alignment === 'end' ) {
		$style_bits[] = 'margin-left:auto';
		$style_bits[] = 'margin-right:0';
	} else {
		$style_bits[] = 'margin-left:auto';
		$style_bits[] = 'margin-right:auto';
	}

	return implode( ';', $style_bits ) . ';';
}

/**
 * Build inline style string for global asset size + alignment.
 *
 * @param string $slot
 * @return string
 */
function pepsi_asset_style_attr( string $slot ): string {
	$size = pepsi_resolve_asset_size( $slot );
	$alignment = pepsi_resolve_asset_alignment( $slot );
	$style_bits = [ 'height:auto', 'display:block' ];

	if ( $size !== '' ) {
		$style_bits[] = 'width:' . $size;
		$style_bits[] = 'max-width:none';
	}

	if ( $alignment === 'start' ) {
		$style_bits[] = 'margin-left:0';
		$style_bits[] = 'margin-right:auto';
	} elseif ( $alignment === 'end' ) {
		$style_bits[] = 'margin-left:auto';
		$style_bits[] = 'margin-right:0';
	} else {
		$style_bits[] = 'margin-left:auto';
		$style_bits[] = 'margin-right:auto';
	}

	return implode( ';', $style_bits ) . ';';
}

/**
 * Resolve the configured Age Gate form ID (competition mapping first, then brand config fallback).
 */
function pepsi_get_age_gate_form_id(): int {
	$age_gate_form_id = 0;

	// IMPORTANT: avoid bpm_current_competition_id() / BPM_Brand_Config::get_current_competition_id()
	// here — those have a static cache that permanently stores 0 when called during 'init'
	// (is_page() is false at that point), which then breaks every subsequent brand-config
	// asset and form-id lookup on the page (including bpm_get_gf_id()).
	$competition_id = function_exists( 'bpm_get_active_competition_id' ) ? (int) bpm_get_active_competition_id() : 0;

	// Fallback: scan all competitions for an age_gate form ID without touching the cache.
	if ( $competition_id <= 0 ) {
		global $wpdb;
		$comp_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id DESC" );
		foreach ( (array) $comp_ids as $cid ) {
			$fs  = get_option( "bpm_form_settings_{$cid}", [] );
			$fid = (int) ( $fs['age_gate']['form_id'] ?? 0 );
			if ( $fid > 0 ) {
				$competition_id = (int) $cid;
				break;
			}
		}
	}

	if ( $competition_id > 0 ) {
		$form_settings = get_option( "bpm_form_settings_{$competition_id}", [] );
		if ( ! empty( $form_settings['age_gate']['form_id'] ) ) {
			$age_gate_form_id = (int) $form_settings['age_gate']['form_id'];
		}

		// Also look at the brand config option (Brand Config UI saves form IDs here
		// under gf_ids[<form_key>], separate from the bpm_form_settings option above).
		if ( $age_gate_form_id <= 0 ) {
			$brand = get_option( "bpm_brand_{$competition_id}", [] );
			$age_gate_form_id = (int) ( $brand['gf_ids']['age_gate'] ?? 0 );
			if ( $age_gate_form_id <= 0 ) {
				$age_gate_form_id = (int) ( $brand['gf_ids']['initial_entry'] ?? 0 );
			}
			if ( $age_gate_form_id <= 0 ) {
				$age_gate_form_id = (int) ( $brand['gf_ids']['entry'] ?? 0 );
			}
		}
	}

	return $age_gate_form_id;
}

/**
 * Resolve a page URL from a page template.
 */
function pepsi_get_page_url_by_template( string $template ): string {
	$pages = get_posts( [
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'meta_key'       => '_wp_page_template',
		'meta_value'     => $template,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	] );
	return ! empty( $pages ) ? (string) get_permalink( $pages[0] ) : '';
}

/**
 * Resolve Age Gate settings.
 *
 * Tries (in order):
 *   1. Settings tied to the currently-active competition via
 *      bpm_current_competition_id() / bpm_get_active_competition_id().
 *   2. Scan every `bpm_form_settings_*` option and find one whose age_gate
 *      block exists — used when no competition context is available (e.g.
 *      inside a `gform_validation` hook with no page state).
 *
 * If $form_id is given, the scan prefers settings whose `age_gate.form_id`
 * matches it; otherwise the first competition with an age_gate block wins.
 */
function pepsi_get_age_gate_settings( int $form_id = 0 ): array {
	$competition_id = function_exists( 'bpm_current_competition_id' ) ? (int) bpm_current_competition_id() : 0;
	if ( $competition_id <= 0 && function_exists( 'bpm_get_active_competition_id' ) ) {
		$competition_id = (int) bpm_get_active_competition_id();
	}
	if ( $competition_id > 0 ) {
		$form_settings = get_option( "bpm_form_settings_{$competition_id}", [] );
		if ( is_array( $form_settings['age_gate'] ?? null ) && ! empty( $form_settings['age_gate'] ) ) {
			return $form_settings['age_gate'];
		}
	}

	// Fallback: scan all competition form-settings options. Slow path, only
	// hit when the context-less helpers above can't pin down a competition.
	global $wpdb;
	$rows = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bpm_form_settings_%'"
	);
	$best = [];
	foreach ( (array) $rows as $option_name ) {
		$settings = get_option( $option_name, [] );
		$age_gate = is_array( $settings['age_gate'] ?? null ) ? $settings['age_gate'] : [];
		if ( empty( $age_gate ) ) {
			continue;
		}
		if ( $form_id > 0 && (int) ( $age_gate['form_id'] ?? 0 ) === $form_id ) {
			return $age_gate; // exact match wins immediately
		}
		if ( empty( $best ) ) {
			$best = $age_gate;
		}
	}
	return $best;
}

/**
 * Detect whether a DD/MM/YYYY-style DOB value parses as a real calendar date.
 * Used to distinguish "you entered nonsense (e.g. 20/20/1990)" from "the date
 * is valid but you're too young" so the error message can match the cause.
 */
function pepsi_dob_value_is_valid( string $dob_value ): bool {
	$dob_value = trim( $dob_value );
	if ( $dob_value === '' ) {
		return false;
	}
	$normalized = str_replace( '-', '/', $dob_value );
	$parts      = explode( '/', $normalized );
	if ( count( $parts ) !== 3 ) {
		return false;
	}
	$day   = (int) $parts[0];
	$month = (int) $parts[1];
	$year  = (int) $parts[2];
	return $year >= 1900 && checkdate( $month, $day, $year );
}

/**
 * Parse a DOB string in common formats and return age in years, or null if invalid.
 */
function pepsi_parse_age_from_dob_value( string $dob_value ): ?int {
	$dob_value = trim( $dob_value );
	if ( $dob_value === '' ) {
		return null;
	}

	$normalized = str_replace( '-', '/', $dob_value );
	$parts      = explode( '/', $normalized );
	if ( count( $parts ) === 3 ) {
		$day   = (int) $parts[0];
		$month = (int) $parts[1];
		$year  = (int) $parts[2];
		if ( checkdate( $month, $day, $year ) ) {
			$dob = DateTime::createFromFormat( 'Y-n-j', "{$year}-{$month}-{$day}" );
			if ( $dob instanceof DateTime ) {
				$today = new DateTime( 'today' );
				return (int) $dob->diff( $today )->y;
			}
		}
	}

	$ts = strtotime( $dob_value );
	if ( $ts === false ) {
		return null;
	}
	$dob = new DateTime( '@' . $ts );
	$dob->setTimezone( wp_timezone() );
	$today = new DateTime( 'today' );
	return (int) $dob->diff( $today )->y;
}

/**
 * Remove "Previous" from the Age Gate multi-page form; DOB should gate forward only.
 */
add_filter( 'gform_previous_button', function ( $button, $form ) {
	if ( ! is_array( $form ) || empty( $form['id'] ) ) {
		return $button;
	}

	$age_gate_form_id = pepsi_get_age_gate_form_id();
	if ( $age_gate_form_id > 0 && (int) $form['id'] === $age_gate_form_id ) {
		return '';
	}

	return $button;
}, 10, 2 );

/**
 * Route age-gate → the next step in the BPM Page Flow after final submission
 * (currently /photo/). The GF confirmation setting is only the fallback when
 * no next page resolves — see pf_flow_next_url() in flow-guard.php.
 *
 * GF fires gform_confirmation (generic) first, then gform_confirmation_{id} (specific).
 * BPM hooks gform_confirmation_{id} at priority 99 and redirects to its own "reveal" URL,
 * overwriting any generic-filter redirect. We register the form-specific filter at
 * priority 100 — one step after BPM — so this theme redirect always wins.
 *
 * IMPORTANT: we resolve the form ID via bpm_get_active_competition_id() + a direct option
 * read, NOT via pepsi_get_age_gate_form_id() / bpm_current_competition_id().
 * The latter calls BPM_Brand_Config::get_current_competition_id() which has a static
 * cache that permanently stores 0 when called during init (is_page() is false at that
 * point), breaking every subsequent brand-config asset lookup on the page.
 */
add_action( 'init', function () {
	$competition_id = function_exists( 'bpm_get_active_competition_id' )
		? (int) bpm_get_active_competition_id()
		: 0;

	// Fallback: scan all competition form settings when active competition isn't set.
	if ( $competition_id <= 0 ) {
		global $wpdb;
		$comp_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bpm_competitions ORDER BY id DESC" );
		foreach ( (array) $comp_ids as $cid ) {
			$fs  = get_option( "bpm_form_settings_{$cid}", [] );
			$fid = (int) ( $fs['age_gate']['form_id'] ?? 0 );
			if ( $fid > 0 ) {
				$competition_id   = (int) $cid;
				break;
			}
		}
	}

	$form_settings    = get_option( "bpm_form_settings_{$competition_id}", [] );
	$age_gate_form_id = (int) ( $form_settings['age_gate']['form_id'] ?? 0 );

	if ( $age_gate_form_id <= 0 ) {
		return;
	}

	add_filter( "gform_confirmation_{$age_gate_form_id}", function ( $confirmation, $form, $entry, $ajax ) {
		$fid         = (int) $form['id'];
		$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
		$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
		$next_url    = function_exists( 'pf_flow_next_url' ) ? pf_flow_next_url( 'age-gate' ) : '';

		$is_final_submit = $source_page > 0 && $target_page === 0;
		if ( ! $is_final_submit ) {
			return $confirmation;
		}
		if ( $next_url !== '' ) {
			return [ 'redirect' => $next_url ];
		}
		return $confirmation;
	}, 100, 4 );

	// Safety fallback: BPM's dynamic field detector may fail to find name/email fields,
	// which prevents it from setting $_SESSION['britvic_entry_data']. Without that key
	// flow-guard's legacy age-gate fallback fails. Set a sentinel value here
	// after BPM's own submission handler (priority 10) has had its chance.
	add_action( "gform_after_submission_{$age_gate_form_id}", function ( $entry, $form ) {
		$fid         = (int) $form['id'];
		$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
		$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
		$is_final    = $source_page > 0 && $target_page === 0;
		if ( ! $is_final ) {
			return;
		}
		if ( ! session_id() ) {
			session_start();
		}
		if ( empty( $_SESSION['britvic_entry_data'] ) ) {
			$_SESSION['britvic_entry_data'] = 'entered';
		}
	}, 20, 2 );
} );

/**
 * Server-side hardening: enforce Age Gate progression on final submit.
 * UI state can be manipulated in browser, but this guarantees required data and age check.
 */
add_filter( 'gform_validation', function ( $validation_result ) {
	if ( ! is_array( $validation_result ) || empty( $validation_result['form'] ) || ! is_array( $validation_result['form'] ) ) {
		return $validation_result;
	}

	$form = $validation_result['form'];
	if ( empty( $form['id'] ) || (int) $form['id'] !== pepsi_get_age_gate_form_id() ) {
		return $validation_result;
	}

	$source_page = (int) ( $_POST[ 'gform_source_page_number_' . (int) $form['id'] ] ?? 0 );
	$target_page = (int) ( $_POST[ 'gform_target_page_number_' . (int) $form['id'] ] ?? 0 );
	$is_step_1_next = $source_page === 1 && $target_page > 1;
	$is_final_submit = $source_page > 0 && $target_page === 0;
	if ( ! $is_step_1_next && ! $is_final_submit ) {
		return $validation_result;
	}

	$age_gate_settings = pepsi_get_age_gate_settings( (int) ( $form['id'] ?? 0 ) );
	$mapping           = is_array( $age_gate_settings['field_mapping'] ?? null ) ? $age_gate_settings['field_mapping'] : [];
	$age_limit         = (int) ( $age_gate_settings['age_limit'] ?? 18 );
	if ( $age_limit <= 0 ) {
		$age_limit = 18;
	}

	$dob_field_id   = isset( $mapping['dob_field'] ) ? (int) $mapping['dob_field'] : 0;
	$name_field_id  = isset( $mapping['name_field'] ) ? (int) $mapping['name_field'] : 0;
	$email_field_id = isset( $mapping['email_field'] ) ? (int) $mapping['email_field'] : 0;

	$dob_value = $dob_field_id > 0 ? (string) ( $_POST[ 'input_' . $dob_field_id ] ?? '' ) : '';
	$age       = pepsi_parse_age_from_dob_value( $dob_value );
	$is_valid  = true;

	foreach ( $form['fields'] as &$field ) {
		$field_id = isset( $field->id ) ? (int) $field->id : 0;

		if ( $dob_field_id > 0 && $field_id === $dob_field_id ) {
			$valid_date = pepsi_dob_value_is_valid( $dob_value );
			if ( ! $valid_date ) {
				$field->failed_validation  = true;
				$field->validation_message = 'Please enter a valid date in DD/MM/YYYY format.';
				$is_valid                  = false;
			} elseif ( $age === null || $age < $age_limit ) {
				$field->failed_validation  = true;
				$field->validation_message = sprintf( 'You must be %d or over to enter.', $age_limit );
				$is_valid                  = false;
			}
		}

		if ( $is_final_submit && $name_field_id > 0 && $field_id === $name_field_id ) {
			$name_value = '';
			$name_keys  = [
				'input_' . $name_field_id,
				'input_' . $name_field_id . '.2',
				'input_' . $name_field_id . '.3',
				'input_' . $name_field_id . '.4',
				'input_' . $name_field_id . '.6',
				'input_' . $name_field_id . '.8',
				'input_' . $name_field_id . '_2',
				'input_' . $name_field_id . '_3',
				'input_' . $name_field_id . '_4',
				'input_' . $name_field_id . '_6',
				'input_' . $name_field_id . '_8',
			];

			foreach ( $name_keys as $name_key ) {
				$candidate = trim( (string) ( $_POST[ $name_key ] ?? '' ) );
				if ( $candidate !== '' ) {
					$name_value = $candidate;
					break;
				}
			}

			if ( $name_value === '' ) {
				$field->failed_validation  = true;
				$field->validation_message = 'Please enter your name.';
				$is_valid                  = false;
			}
		}

		if ( $is_final_submit && $email_field_id > 0 && $field_id === $email_field_id ) {
			$email_value = trim( (string) ( $_POST[ 'input_' . $email_field_id ] ?? '' ) );
			if ( $email_value === '' || ! is_email( $email_value ) ) {
				$field->failed_validation  = true;
				$field->validation_message = 'Please enter a valid email address.';
				$is_valid                  = false;
			}
		}
	}
	unset( $field );

	// Only ever flip is_valid OFF — never back ON. GF's core validation has
	// already run by the time this filter fires (priority 20, default is 10),
	// so it may have failed required-field checks like the terms-acceptance
	// checkbox (id 5 local / 7 prod). Overwriting with our local $is_valid
	// would clear those legitimate failures whenever our own DOB/name/email
	// checks happened to pass.
	if ( ! $is_valid ) {
		$validation_result['is_valid'] = false;
	}
	$validation_result['form'] = $form;
	return $validation_result;
}, 20 );

/**
 * DOB age check on the prize-claim form. Same 16+ rule as the age gate, with
 * the same "valid date vs underage" distinction. The DOB field is auto-detected
 * (first `date` field on the form) so it works for both local (form 3) and
 * production (form 2) without hardcoding field IDs.
 */
add_filter( 'gform_validation', function ( $validation_result ) {
	if ( ! is_array( $validation_result ) || empty( $validation_result['form'] ) || ! is_array( $validation_result['form'] ) ) {
		return $validation_result;
	}

	$form           = $validation_result['form'];
	$claim_form_id  = function_exists( 'pf_claim_form_id' ) ? (int) pf_claim_form_id() : ( defined( 'PF_CLAIM_FORM_ID' ) ? (int) PF_CLAIM_FORM_ID : 0 );
	if ( $claim_form_id <= 0 || (int) ( $form['id'] ?? 0 ) !== $claim_form_id ) {
		return $validation_result;
	}

	$age_gate_settings = pepsi_get_age_gate_settings();
	$age_limit         = (int) ( $age_gate_settings['age_limit'] ?? 18 );
	if ( $age_limit <= 0 ) {
		$age_limit = 18;
	}

	$is_valid = true;

	foreach ( $form['fields'] as &$field ) {
		if ( ( $field->type ?? '' ) !== 'date' ) {
			continue;
		}
		$field_id  = (int) ( $field->id ?? 0 );
		if ( $field_id <= 0 ) {
			continue;
		}
		$dob_value = (string) ( $_POST[ 'input_' . $field_id ] ?? '' );
		if ( $dob_value === '' && empty( $field->isRequired ) ) {
			continue; // optional, blank — let GF skip
		}
		$valid_date = pepsi_dob_value_is_valid( $dob_value );
		$age        = $valid_date ? pepsi_parse_age_from_dob_value( $dob_value ) : null;
		if ( ! $valid_date ) {
			$field->failed_validation  = true;
			$field->validation_message = 'Please enter a valid date in DD/MM/YYYY format.';
			$is_valid                  = false;
		} elseif ( $age === null || $age < $age_limit ) {
			$field->failed_validation  = true;
			$field->validation_message = sprintf( 'You must be %d or over to enter.', $age_limit );
			$is_valid                  = false;
		}
	}
	unset( $field );

	if ( ! $is_valid ) {
		$validation_result['is_valid'] = false;
	}
	$validation_result['form'] = $form;
	return $validation_result;
}, 20 );

/**
 * Inject the page-transition overlay div before every page closes.
 * CSS starts it opaque (black); JS (initPageTransitions) fades it out on load
 * and back in before navigating away.
 */
add_action( 'wp_footer', function (): void {
	?>
	<script>
	(function () {
		const modal = document.getElementById('termsModal');
		if (!modal) return;
		const closeBtn = document.getElementById('termsClose');
		const openers = document.querySelectorAll('#termsOpen, [data-terms-open]');
		const openModal = function (event) {
			if (event) event.preventDefault();
			modal.classList.add('terms-wrapper--is-active');
			modal.setAttribute('aria-hidden', 'false');
			document.documentElement.classList.add('has-terms-modal-open');
			document.body.classList.add('has-terms-modal-open');
		};
		const closeModal = function () {
			modal.classList.remove('terms-wrapper--is-active');
			modal.setAttribute('aria-hidden', 'true');
			document.documentElement.classList.remove('has-terms-modal-open');
			document.body.classList.remove('has-terms-modal-open');
		};

		openers.forEach(function (el) {
			el.addEventListener('click', openModal);
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', closeModal);
		}

		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeModal();
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && modal.classList.contains('terms-wrapper--is-active')) {
				closeModal();
			}
		});
	})();
	</script>
	<?php
} );

/**
 * Replace GF's date-field markup with a plain text input.
 *
 * Why: native <input type="date"> renders inconsistently across browsers
 * (Safari desktop's day/month/year sub-pseudos can't be styled, iOS Safari
 * has no placeholder, the calendar widget itself is opaque to screen
 * readers). Per Hassell Inclusion's guidance on accessible date entry
 * (https://www.hassellinclusion.com/blog/collecting-dates-accessible/),
 * a single text field with a visible format hint is the most accessible
 * cross-browser option. Validation still enforces DD/MM/YYYY via
 * pepsi_parse_age_from_dob_value() / gform_validation.
 */
add_filter( 'gform_field_input', function ( $html, $field, $value, $entry_id, $form_id ) {
	if ( ! is_object( $field ) || ( $field->type ?? '' ) !== 'date' ) {
		return $html;
	}

	$input_id  = sprintf( 'input_%d_%d', (int) $form_id, (int) $field->id );
	$input_nm  = sprintf( 'input_%d',    (int) $field->id );
	$value_str = is_string( $value ) ? $value : '';
	$required  = ! empty( $field->isRequired ) ? ' aria-required="true" required' : '';
	// Intentionally NO `datepicker` / `datepicker_with_icon` / `datepicker_dmy`
	// classes — GF's datepicker.js auto-binds the jQuery-UI picker to anything
	// matching `input.datepicker`, which would re-introduce the calendar widget
	// we're replacing. Same reason `autocomplete="bday"` is omitted: it can
	// trigger Safari's autofill date scroller on iOS / desktop.
	$class       = 'large gfield_date_input pf-date-text';
	$placeholder = 'DD/MM/YYYY';

	return sprintf(
		'<div class="ginput_container ginput_container_date">'
		. '<input type="text" name="%1$s" id="%2$s" value="%3$s"'
		. ' class="%4$s"'
		. ' placeholder="%5$s"'
		. ' inputmode="numeric"'
		. ' autocomplete="off"'
		. ' maxlength="10"'
		. ' pattern="\d{2}/\d{2}/\d{4}"'
		. ' data-pf-date-mask="1"'
		. ' aria-label="%5$s"%6$s />'
		. '</div>',
		esc_attr( $input_nm ),
		esc_attr( $input_id ),
		esc_attr( $value_str ),
		esc_attr( $class ),
		esc_attr( $placeholder ),
		$required
	);
}, 10, 5 );

/**
 * Belt-and-braces: if anything in GF still attaches a jQuery-UI datepicker to
 * our text input (some GF add-ons re-bind `input.datepicker`), tear it down on
 * `gform_post_render` so the calendar widget can't pop up. Cheap to run when
 * jQuery / jQuery UI aren't loaded.
 */
add_action( 'wp_footer', function (): void {
	?>
	<script>
	(function () {
		var jq = window.jQuery;
		if ( ! jq ) return;
		jq( document ).on( 'gform_post_render', function () {
			jq( 'input.pf-date-text.hasDatepicker' ).each( function () {
				try { jq( this ).datepicker && jq( this ).datepicker( 'destroy' ); } catch ( e ) {}
				this.classList.remove( 'hasDatepicker' );
			} );
		} );
	}());
	</script>
	<?php
}, 99 );

/**
 * Safety net: if a native <input type="date"> value (YYYY-MM-DD) somehow still
 * reaches the server (e.g. browser autofill on the legacy markup), rewrite it
 * to DD/MM/YYYY before GF validates. The text-field render above means this is
 * mostly defensive.
 */
add_filter( 'gform_pre_validation', function ( array $form ): array {
	foreach ( $form['fields'] as $field ) {
		if ( $field->type !== 'date' ) {
			continue;
		}
		$key = 'input_' . $field->id;
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$val = trim( (string) $_POST[ $key ] );
		// Convert YYYY-MM-DD → DD/MM/YYYY (GF date field format for this campaign).
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m ) ) {
			$_POST[ $key ] = "{$m[3]}/{$m[2]}/{$m[1]}";
		}
	}
	return $form;
} );
