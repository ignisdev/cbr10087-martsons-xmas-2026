<?php
defined( 'ABSPATH' ) || exit;

// ── Token session latch ───────────────────────────────────────────────────────
// When the congratulations page loads with ?token=..., store the token in the
// PHP session so it is available at form-submission time even when GF fires its
// AJAX POST (which drops the original page query string from $_GET).
add_action( 'template_redirect', function (): void {
	if ( ! isset( $_GET['token'] ) || $_GET['token'] === '' ) {
		return;
	}
	// Only latch on the congratulations / winner page template.
	if ( ! is_page() ) {
		return;
	}
	$template = get_page_template_slug();
	if ( strpos( (string) $template, 'congratulations' ) === false
		&& strpos( (string) $template, 'winner' ) === false ) {
		return;
	}
	if ( ! session_id() && ! headers_sent() ) {
		session_start();
	}
	$_SESSION['pf_completion_token'] = sanitize_text_field( (string) $_GET['token'] );
} );

// ── GF hooks ─────────────────────────────────────────────────────────────────

/**
 * Gate for the forced-cash debug path — both the display in
 * pf_get_congrats_prize_info() and the claim arm in pf_pre_submission.
 *
 * NOTE: this is intentionally permissive on production so non-admin testers
 * can complete the full Stripe payout flow end-to-end. Anyone hitting
 * ?pf_force_cash_entry=N against an unclaimed entry id will receive a payout,
 * so leave only the entries you want claimable in an `claimed=0` state.
 *
 * Reads $_REQUEST so the gate stays open for both the GET that renders the
 * congrats page AND the POST that GF fires when the claim form submits — GF
 * sometimes drops the query string off the form action depending on AJAX
 * settings, which would otherwise close the gate mid-flow.
 */
function pf_is_forced_cash_test_allowed(): bool {
	return pf_get_forced_cash_entry_id() > 0;
}

/**
 * Resolve the forced-cash entry id from any of the available sources:
 *  1. The current request's $_REQUEST (initial GET / POST that still carries it)
 *  2. The PHP session — set the first time we see the param, persisted across
 *     the GF POST so the forced-claim path still arms even when GF's form
 *     action strips the query string.
 *
 * Returns 0 when no forced-cash id is in play.
 */
function pf_get_forced_cash_entry_id(): int {
	$from_req = isset( $_REQUEST['pf_force_cash_entry'] ) ? (int) $_REQUEST['pf_force_cash_entry'] : 0;
	if ( $from_req > 0 ) {
		// Latch the value so subsequent requests in this session see it too.
		if ( ! session_id() && ! headers_sent() ) {
			session_start();
		}
		$_SESSION['pf_force_cash_entry'] = $from_req;
		return $from_req;
	}
	if ( session_id() === '' && ! headers_sent() ) {
		session_start();
	}
	return isset( $_SESSION['pf_force_cash_entry'] ) ? (int) $_SESSION['pf_force_cash_entry'] : 0;
}

/** Alias kept for the view path — same gate as the claim path now. */
function pf_is_forced_cash_view_allowed(): bool {
	return pf_is_forced_cash_test_allowed();
}

function pf_get_forced_cash_debug_state(): ?array {
	// Read from $_REQUEST so the force id resolves whether it comes through
	// the URL (GET on the congrats page) or the form POST (claim submission).
	$force_id = pf_get_forced_cash_entry_id();
	if ( $force_id <= 0 ) {
		return null;
	}

	if ( ! pf_is_forced_cash_test_allowed() ) {
		return [
			'force_id' => $force_id,
			'accepted' => false,
			'message'  => 'Forced cash test is disabled by pf_is_forced_cash_test_allowed().',
		];
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, competition_id, claimed, prize_type, code
			 FROM $table
			 WHERE id = %d
			 LIMIT 1",
			$force_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return [
			'force_id' => $force_id,
			'accepted' => false,
			'message'  => 'No prize entry found for that id.',
		];
	}

	if ( (int) ( $row['claimed'] ?? 1 ) !== 0 ) {
		return [
			'force_id' => $force_id,
			'accepted' => false,
			'message'  => 'Entry exists but is already claimed.',
			'entry'    => $row,
		];
	}

	return [
		'force_id' => $force_id,
		'accepted' => true,
		'message'  => 'Forced cash test is active for this entry.',
		'entry'    => $row,
	];
}

function pf_get_test_cash_prize_entry( int $prize_entry_id ): ?array {
	if ( $prize_entry_id <= 0 ) {
		return null;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bpm_prize_entries';
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, competition_id, claimed, prize_type, prize_name
			 FROM $table
			 WHERE id = %d
			 LIMIT 1",
			$prize_entry_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	if ( (int) ( $row['claimed'] ?? 1 ) !== 0 ) {
		return null;
	}

	return $row;
}

/**
 * Pre-populate GF field 10 (parameter name: "score") with the completion token.
 * The token is a server-issued opaque string passed via ?token= — the real
 * score is never exposed to the client.  gform_pre_submission (below)
 * consumes the token and overwrites this field with the verified score before
 * Gravity Forms persists the entry.
 *
 * Requires field 10 to have "Allow field to be populated dynamically" enabled
 * in the GF form editor with parameter name set to "score".
 */
add_filter( 'gform_field_value_score', function (): string {
	return isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
} );

/**
 * Server-side overwrite of hidden contextual fields (route, prize_name) at
 * POST time. The values are computed from request context (host for route,
 * BPM prize lookup for prize_name) and overwrite whatever the client sent.
 *
 * Why this instead of `gform_field_value_<param>`:
 *   The param-name filter only runs at *render* time, and only when the field
 *   has "Allow field to be populated dynamically" ticked in GF admin — which
 *   then ALSO accepts the value from ?param=... URL queries. That makes the
 *   value trivially overridable by anyone. Pre-submission runs after the
 *   POST lands and replaces whatever the client tried to send.
 *
 * Field identification is by `inputName` (Admin Field Label) so the same code
 * works on staging (id 6) and production (id 8) without environment branches.
 * Set the hidden field's admin label / parameter name to one of:
 *   - `route`       → pf_current_route()
 *   - `prize_name`  → pf_get_congrats_prize_info()['prize_name']
 *
 * The "Allow field to be populated dynamically" checkbox should be UNCHECKED
 * in GF admin for these fields — that closes the URL-param injection vector.
 */
add_action( 'gform_pre_submission', function ( $form ) {
	if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
		return;
	}
	$route_value = function_exists( 'pf_current_route' ) ? (string) pf_current_route() : 'main';
	$prize_name  = '';
	$prize_info_loaded = false;

	foreach ( $form['fields'] as $field ) {
		$param = strtolower( trim( (string) ( $field->inputName ?? '' ) ) );
		if ( $param === '' ) {
			continue;
		}
		$field_id = (int) ( $field->id ?? 0 );
		if ( $field_id <= 0 ) {
			continue;
		}

		if ( $param === 'route' ) {
			$_POST[ 'input_' . $field_id ] = $route_value;
			continue;
		}

		if ( $param === 'prize_name' ) {
			if ( ! $prize_info_loaded ) {
				$prize_info_loaded = true;
				if ( function_exists( 'pf_get_congrats_prize_info' ) ) {
					$info       = pf_get_congrats_prize_info();
					$prize_name = (string) ( $info['prize_name'] ?? '' );
				}
			}
			$_POST[ 'input_' . $field_id ] = $prize_name;
			continue;
		}

		// Inject the completion token from session if the field is empty.
		// gform_field_value_score pre-populates at render time, but GF AJAX
		// submissions drop the page query string so $_GET['token'] is gone by
		// the time pre_submission fires. The session latch (template_redirect
		// above) stores the token when the congrats page first loads.
		if ( $param === 'score' ) {
			if ( ! empty( $_POST[ 'input_' . $field_id ] ) ) {
				continue; // already populated — GF dynamic field worked fine
			}
			// Try query string first (non-AJAX flow), then session fallback.
			$latched_token = '';
			if ( isset( $_GET['token'] ) && $_GET['token'] !== '' ) {
				$latched_token = sanitize_text_field( (string) $_GET['token'] );
			} elseif ( session_id() !== '' || ( ! headers_sent() && session_start() ) ) {
				$latched_token = isset( $_SESSION['pf_completion_token'] )
					? sanitize_text_field( (string) $_SESSION['pf_completion_token'] )
					: '';
			}
			if ( $latched_token !== '' ) {
				$_POST[ 'input_' . $field_id ] = $latched_token;
			}
			continue;
		}
	}
}, 5 ); // priority 5 — runs before the score-token consumer at default 10

/**
 * Intercept form 3 before submission.
 * Field 10 currently holds the completion token.  We consume it server-side,
 * get the verified total and winner status, and overwrite the field with the
 * actual score so Gravity Forms saves the real value.
 * Also claims the BPM prize entry for winners.
 */
add_action( 'gform_pre_submission', function ( array $form ): void {
	if ( (int) ( $form['id'] ?? 0 ) !== ( function_exists( 'pf_claim_form_id' ) ? pf_claim_form_id() : 3 ) ) {
		return;
	}
	// Resolve the score field by parameter name (inputName='score') rather than
	// a hardcoded ID so the same code works across form layouts — Pepsi (id 10),
	// Marstons (id 15), and any future re-numbering. Matches the inputName-based
	// pattern used by the other hidden-field handlers in this file. Falls back
	// to legacy id 10 only if no field is tagged 'score'.
	$field_id = 0;
	foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
		$param = strtolower( trim( (string) ( $field->inputName ?? '' ) ) );
		if ( $param === 'score' ) {
			$field_id = (int) ( $field->id ?? 0 );
			break;
		}
	}
	if ( $field_id <= 0 ) {
		$field_id = 10; // legacy fallback
	}
	$field_key = 'input_' . $field_id;
	$token     = isset( $_POST[ $field_key ] ) ? sanitize_text_field( $_POST[ $field_key ] ) : '';

	// Belt-and-suspenders: if the field is still empty (GF dynamic population
	// missed, or the form field's inputName wasn't 'score'), pull the token
	// directly from the session latch set at congrats-page render time.
	if ( $token === '' ) {
		if ( session_id() === '' && ! headers_sent() ) {
			session_start();
		}
		if ( isset( $_SESSION['pf_completion_token'] ) && $_SESSION['pf_completion_token'] !== '' ) {
			$token = sanitize_text_field( (string) $_SESSION['pf_completion_token'] );
		}
	}
	$force_debug = function_exists( 'pf_get_forced_cash_debug_state' ) ? pf_get_forced_cash_debug_state() : null;
	$force_id    = (int) ( $force_debug['force_id'] ?? 0 );
	$allow_forced_test = function_exists( 'pf_is_forced_cash_test_allowed' ) && pf_is_forced_cash_test_allowed();

	if ( $token === '' || ! function_exists( 'pf_consume_completion_token' ) ) {
		if ( $allow_forced_test && $force_id > 0 && ! empty( $force_debug['accepted'] ) ) {
			$prize = pf_get_test_cash_prize_entry( $force_id );
			if ( is_array( $prize ) ) {
				$_POST[ $field_key ] = 1000;
				$claim_key   = 'forced_' . md5( (string) $force_id . '|' . wp_generate_password( 12, false ) );
				$actual_type = strtolower( (string) ( $prize['prize_type'] ?? '' ) );
				set_transient(
					'pf_pending_claim_' . $claim_key,
					[
						'prize_entry_id'  => (int) $prize['id'],
						'competition_id'  => (int) ( $prize['competition_id'] ?? 0 ),
						'prize_type'      => $actual_type !== '' ? $actual_type : 'merch',
						'reservation_key' => '',
					],
					300
				);
				$_POST['_pf_claim_key'] = $claim_key;
				error_log(
					sprintf(
						'PF FLOW pre_submission: forced path armed prize_entry_id=%d prize_type=%s claim_key=%s',
						(int) $prize['id'],
						$actual_type,
						$claim_key
					)
				);
				return;
			}
		}

		$_POST[ $field_key ] = 0;
		return;
	}

	$data = pf_consume_completion_token( $token );

	// Clear the session latch now that the token has been consumed (or failed).
	// Prevents the same token from being re-used via a subsequent form submit.
	if ( session_id() !== '' ) {
		unset( $_SESSION['pf_completion_token'] );
	}

	if ( $data === null ) {
		// Token invalid, expired, or already used
		$_POST[ $field_key ] = 0;
		error_log( 'PF FLOW pre_submission: token consume failed (invalid/expired/used).' );
		return;
	}

	$_POST[ $field_key ] = (int) $data['score'];

	// Store winner data for the after-submission hook
	if ( ! empty( $data['is_winner'] ) && ! empty( $data['prize_entry_id'] ) ) {
		$prize_entry_id  = (int) $data['prize_entry_id'];
		$reservation_key = sanitize_text_field( $data['reservation_key'] ?? '' );

		if ( $reservation_key !== '' && function_exists( 'pf_validate_bpm_reservation' ) && ! pf_validate_bpm_reservation( $prize_entry_id, $reservation_key ) ) {
			$_POST[ $field_key ] = 0;
			return;
		}

		set_transient(
			'pf_pending_claim_' . md5( $token ),
			[
				'prize_entry_id'  => $prize_entry_id,
				'competition_id'  => (int) ( $data['competition_id'] ?? 0 ),
				'prize_type'      => sanitize_text_field( $data['prize_type'] ?? '' ),
				'reservation_key' => $reservation_key,
			],
			300 // 5 minutes — only needed until after_submission fires
		);
		// Pass the pending-claim key through a hidden channel
		$_POST['_pf_claim_key'] = md5( $token );
		error_log(
			sprintf(
				'PF FLOW pre_submission: winner claim armed prize_entry_id=%d prize_type=%s claim_key=%s',
				$prize_entry_id,
				sanitize_text_field( $data['prize_type'] ?? '' ),
				md5( $token )
			)
		);
	}
} );
