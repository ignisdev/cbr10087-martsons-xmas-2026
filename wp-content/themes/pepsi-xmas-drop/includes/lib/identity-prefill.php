<?php
/**
 * Cross-form identity prefill (name + email).
 *
 * One trusted capture point, two prefill targets:
 *
 *   1. CAPTURE — on the age-gate form's final submission we read the player's
 *      first name, last name and email straight off the Gravity Forms *entry*
 *      (i.e. AFTER GF has run its own validation and persisted the values) and
 *      stash them in the PHP session. The session is the only carrier; nothing
 *      is ever sourced from a URL `?param=` or a client-supplied POST field on
 *      the later forms, so the values can't be spoofed by tampering with the
 *      claim form markup.
 *
 *   2. WINNER CLAIM FORM — name (composite) + email are PREFILLED server-side
 *      via the standard GFAPI render hooks (`gform_pre_render` et al.) by
 *      setting each field/input `defaultValue` from the session. These stay
 *      EDITABLE: defaultValue is only used by GF when the input is empty, so a
 *      winner can still correct a typo before it reaches prize fulfilment /
 *      Stripe. The claim form is only ever reached by winners (flow-guard gates
 *      page-congratulations.php), so the prefill is inherently win-dependent.
 *
 * Field IDs are exposed through filters so a form rebuild doesn't need a code
 * change:
 *   - `pf_identity_claim_field_map`      => [ 'first'=>'12.3', 'last'=>'12.6', 'email'=>'2' ]
 *   - `pf_identity_age_gate_name_inputs` => [ 'first'=>3, 'last'=>6 ]  (GF Name sub-inputs)
 *
 * GF admin note: the claim identity fields should have "Allow field to
 * be populated dynamically" UNCHECKED. We populate server-side, so that box
 * only adds a `?param=` injection vector with no upside.
 */
defined( 'ABSPATH' ) || exit;

const PF_ID_SESS_FIRST = 'pf_player_first_name';
const PF_ID_SESS_LAST  = 'pf_player_last_name';
// Email reuses daily-limit.php's PF_DAILY_SESSION_KEY ('pf_player_email') so we
// have a single source of truth for the player's address across the funnel.
const PF_ID_SESS_EMAIL = 'pf_player_email';

/* ── Session helpers ──────────────────────────────────────────────────────── */

function pf_id_session(): void {
	if ( function_exists( 'pf_ensure_session' ) ) {
		pf_ensure_session();
		return;
	}
	if ( ! session_id() && ! headers_sent() ) {
		session_start();
	}
}

/**
 * Normalise a free-text name part: strip markup, collapse to a single line,
 * cap length. Returns '' for anything that sanitises away to nothing.
 */
function pf_id_clean_name( string $value ): string {
	$value = sanitize_text_field( wp_strip_all_tags( $value ) );
	$value = trim( preg_replace( '/\s+/', ' ', $value ) );
	if ( $value === '' ) {
		return '';
	}
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 100 ) : substr( $value, 0, 100 );
}

function pf_id_set_identity( string $first, string $last, string $email ): void {
	pf_id_session();
	$first = pf_id_clean_name( $first );
	$last  = pf_id_clean_name( $last );
	$email = sanitize_email( $email );

	if ( $first !== '' ) {
		$_SESSION[ PF_ID_SESS_FIRST ] = $first;
	}
	if ( $last !== '' ) {
		$_SESSION[ PF_ID_SESS_LAST ] = $last;
	}
	if ( $email !== '' && is_email( $email ) ) {
		// Prefer the dedicated setter so daily-limit.php's validation/keying
		// stays the single owner of the email session slot.
		if ( function_exists( 'pf_set_player_email' ) ) {
			pf_set_player_email( $email );
		} else {
			$_SESSION[ PF_ID_SESS_EMAIL ] = $email;
		}
	}
}

function pf_id_get(): array {
	pf_id_session();
	$email = '';
	if ( function_exists( 'pf_get_player_email' ) ) {
		$email = (string) pf_get_player_email();
	} elseif ( isset( $_SESSION[ PF_ID_SESS_EMAIL ] ) ) {
		$email = (string) $_SESSION[ PF_ID_SESS_EMAIL ];
	}
	return [
		'first' => isset( $_SESSION[ PF_ID_SESS_FIRST ] ) ? (string) $_SESSION[ PF_ID_SESS_FIRST ] : '',
		'last'  => isset( $_SESSION[ PF_ID_SESS_LAST ] ) ? (string) $_SESSION[ PF_ID_SESS_LAST ] : '',
		'email' => $email,
	];
}

/**
 * Player identity in the shape BPM_Access_Control expects.
 *
 * Combines the session-captured email + name with the current request IP. The
 * `pf_current_player_identity` filter lets the self-test harness inject a
 * synthetic identity without touching $_SESSION.
 */
function pf_current_player_identity(): array {
	$base = pf_id_get();
	$first = (string) ( $base['first'] ?? '' );
	$last  = (string) ( $base['last'] ?? '' );
	$identity = [
		'email'      => (string) ( $base['email'] ?? '' ),
		'first_name' => $first,
		'last_name'  => $last,
		'full_name'  => trim( $first . ' ' . $last ),
		'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
	];
	return (array) apply_filters( 'pf_current_player_identity', $identity );
}

/* ── Field-map resolvers (filterable) ─────────────────────────────────────── */

function pf_id_claim_map(): array {
	$map = (array) apply_filters( 'pf_identity_claim_field_map', [
		'first' => '12.3',
		'last'  => '12.6',
		'email' => '2',
	] );
	return [
		'first' => (string) ( $map['first'] ?? '' ),
		'last'  => (string) ( $map['last'] ?? '' ),
		'email' => (string) ( $map['email'] ?? '' ),
	];
}

/* ── 1. Capture from the age-gate entry ───────────────────────────────────── */

/**
 * Pull first/last name off a GF age-gate entry. Handles a composite GF Name
 * field (sub-inputs, default first=.3 / last=.6) and a plain single-line text
 * field (split on first whitespace run).
 */
function pf_id_extract_age_gate_name( array $entry, int $form_id ): array {
	$settings = function_exists( 'pepsi_get_age_gate_settings' )
		? pepsi_get_age_gate_settings( $form_id )
		: [];
	$mapping       = is_array( $settings['field_mapping'] ?? null ) ? $settings['field_mapping'] : [];
	$name_field_id = isset( $mapping['name_field'] ) ? (int) $mapping['name_field'] : 0;
	if ( $name_field_id <= 0 ) {
		return [ 'first' => '', 'last' => '' ];
	}

	$sub = (array) apply_filters( 'pf_identity_age_gate_name_inputs', [
		'first' => 3,
		'last'  => 6,
	] );
	$first_in = (int) ( $sub['first'] ?? 3 );
	$last_in  = (int) ( $sub['last'] ?? 6 );

	$get = static function ( $key ) use ( $entry ): string {
		return function_exists( 'rgar' )
			? (string) rgar( $entry, (string) $key )
			: (string) ( $entry[ (string) $key ] ?? '' );
	};

	// Composite GF Name field first.
	$first = trim( $get( $name_field_id . '.' . $first_in ) );
	$last  = trim( $get( $name_field_id . '.' . $last_in ) );

	// Fall back to a single text field: "First Last [more]".
	if ( $first === '' && $last === '' ) {
		$whole = trim( $get( $name_field_id ) );
		if ( $whole !== '' ) {
			$bits  = preg_split( '/\s+/', $whole, 2 );
			$first = (string) ( $bits[0] ?? '' );
			$last  = (string) ( $bits[1] ?? '' );
		}
	}

	return [
		'first' => pf_id_clean_name( $first ),
		'last'  => pf_id_clean_name( $last ),
	];
}

add_action( 'init', function () {
	$age_gate_form_id = function_exists( 'pepsi_get_age_gate_form_id' )
		? (int) pepsi_get_age_gate_form_id()
		: 0;
	if ( $age_gate_form_id <= 0 && function_exists( 'pf_resolve_gf_id' ) ) {
		$age_gate_form_id = (int) pf_resolve_gf_id( 'age_gate' );
	}
	if ( $age_gate_form_id <= 0 ) {
		return;
	}

	add_action( "gform_after_submission_{$age_gate_form_id}", function ( $entry, $form ) {
		$fid         = (int) $form['id'];
		$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
		$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
		// Final submission only: source page > 0 and no next page.
		if ( ! ( $source_page > 0 && $target_page === 0 ) ) {
			return;
		}

		$name  = pf_id_extract_age_gate_name( (array) $entry, $fid );
		$email = function_exists( 'pf_extract_age_gate_email' )
			? (string) pf_extract_age_gate_email( (array) $entry, $fid )
			: '';

		pf_id_set_identity( $name['first'], $name['last'], $email );
	}, 31, 2 );
} );

/* ── 2. Winner claim form: editable server-side prefill ───────────────────── */

add_action( 'init', function () {
	$claim_form_id = function_exists( 'pf_claim_form_id' ) ? (int) pf_claim_form_id() : 0;
	if ( $claim_form_id <= 0 ) {
		return;
	}

	$prefill = function ( $form ) {
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $form;
		}
		$id  = pf_id_get();
		$map = pf_id_claim_map();

		// Split "12.3" → field 12, sub-input "12.3".
		$first_field = (int) strtok( $map['first'], '.' );
		$last_field  = (int) strtok( $map['last'], '.' );
		$email_field = (int) strtok( $map['email'], '.' );

		foreach ( $form['fields'] as $field ) {
			$fid = (int) ( $field->id ?? 0 );

			// Email — simple field, set its defaultValue.
			if ( $fid === $email_field && $id['email'] !== '' && is_email( $id['email'] ) ) {
				$field->defaultValue = $id['email'];
			}

			// Name — composite GF Name field: prefill matching sub-inputs.
			if ( ( $fid === $first_field || $fid === $last_field ) && ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as &$input ) {
					$input_id = (string) ( $input['id'] ?? '' );
					if ( $input_id === $map['first'] && $id['first'] !== '' ) {
						$input['defaultValue'] = $id['first'];
					} elseif ( $input_id === $map['last'] && $id['last'] !== '' ) {
						$input['defaultValue'] = $id['last'];
					}
				}
				unset( $input );
			} elseif ( $fid === $first_field && empty( $field->inputs ) ) {
				// Name held in a single text field — combine.
				$full = trim( $id['first'] . ' ' . $id['last'] );
				if ( $full !== '' ) {
					$field->defaultValue = $full;
				}
			}
		}
		return $form;
	};

	// Standard GFAPI server-side population recipe. defaultValue is only used
	// when the input is empty, so the winner's own edits always win — the
	// prefill is a starting point, not a lock.
	add_filter( "gform_pre_render_{$claim_form_id}", $prefill );
	add_filter( "gform_pre_validation_{$claim_form_id}", $prefill );
	add_filter( "gform_admin_pre_render_{$claim_form_id}", $prefill );
} );
