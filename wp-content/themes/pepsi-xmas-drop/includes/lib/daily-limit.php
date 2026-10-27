<?php
/**
 * Pepsi Xmas Drop integration shim for BPM's per-player daily play limit.
 *
 * The schema, table, count/record helpers, and daily cleanup cron all live in
 * the BPM plugin (`BPM_Play_Limit`) — see
 * `wp-content/plugins/britvic-promotion-manager/includes/classes/class-play-limit.php`.
 *
 * This file only:
 *   1. Sets the per-day cap for this promo via the `bpm_play_limit_per_day` filter.
 *   2. Captures the player's email off the age-gate GF submission into the PHP
 *      session so /session/start can identify them.
 *   3. Exposes a thin wrapper `pf_get_player_email()` used by the REST endpoint.
 */
defined( 'ABSPATH' ) || exit;

const PF_DAILY_PLAY_LIMIT  = 3;
const PF_DAILY_SESSION_KEY = 'pf_player_email';

// Tell BPM the cap for this promo. BPM returns 0 (no limit) by default.
add_filter( 'bpm_play_limit_per_day', function () {
	return PF_DAILY_PLAY_LIMIT;
} );

function pf_ensure_session(): void {
	if ( ! session_id() && ! headers_sent() ) {
		session_start();
	}
}

function pf_get_player_email(): string {
	pf_ensure_session();
	return isset( $_SESSION[ PF_DAILY_SESSION_KEY ] ) ? (string) $_SESSION[ PF_DAILY_SESSION_KEY ] : '';
}

function pf_set_player_email( string $email ): void {
	pf_ensure_session();
	$email = sanitize_email( $email );
	if ( $email !== '' && is_email( $email ) ) {
		$_SESSION[ PF_DAILY_SESSION_KEY ] = $email;
	}
}

// ── Email capture on age-gate submission ─────────────────────────────────────
//
// The age-gate form already collects an email; we grab it from the final-page
// submission entry and store it in the session so /session/start can identify
// the player without re-asking. Field id comes from the BPM age-gate mapping.

/**
 * Pull the email value off a GF age-gate entry, using the BPM field-mapping
 * setting. Walks the sub-keys (e.g. "5.3") when the top-level key is empty.
 */
function pf_extract_age_gate_email( array $entry, int $form_id ): string {
	$settings       = function_exists( 'pepsi_get_age_gate_settings' )
		? pepsi_get_age_gate_settings( $form_id )
		: [];
	$mapping        = is_array( $settings['field_mapping'] ?? null ) ? $settings['field_mapping'] : [];
	$email_field_id = isset( $mapping['email_field'] ) ? (int) $mapping['email_field'] : 0;
	if ( $email_field_id <= 0 ) {
		return '';
	}
	$email = (string) rgar( $entry, (string) $email_field_id );
	if ( $email === '' ) {
		foreach ( $entry as $key => $value ) {
			if ( is_string( $key ) && strpos( $key, $email_field_id . '.' ) === 0 && is_email( (string) $value ) ) {
				$email = (string) $value;
				break;
			}
		}
	}
	return sanitize_email( $email );
}

add_action( 'init', function () {
	$age_gate_form_id = function_exists( 'pepsi_get_age_gate_form_id' ) ? (int) pepsi_get_age_gate_form_id() : 0;
	if ( $age_gate_form_id <= 0 ) {
		return;
	}

	add_action( "gform_after_submission_{$age_gate_form_id}", function ( $entry, $form ) {
		$fid         = (int) $form['id'];
		$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
		$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
		$is_final    = $source_page > 0 && $target_page === 0;
		if ( ! $is_final ) {
			return;
		}

		$email = pf_extract_age_gate_email( (array) $entry, $fid );
		if ( $email !== '' ) {
			pf_set_player_email( $email );
		}
	}, 30, 2 );

	// Early daily-limit check on the age-gate confirmation. If the player has
	// already used all their plays today, redirect them straight to the
	// daily-limit page instead of letting them walk through how-to-play
	// only to be bounced when /session/start fires on the game page.
	//
	// Priority 110 runs after the existing 100-priority filter in methods.php
	// that points the confirmation at the next BPM flow step, so this override wins.
	add_filter( "gform_confirmation_{$age_gate_form_id}", function ( $confirmation, $form, $entry, $ajax ) {
		$fid         = (int) $form['id'];
		$source_page = (int) ( $_POST[ "gform_source_page_number_{$fid}" ] ?? 0 );
		$target_page = (int) ( $_POST[ "gform_target_page_number_{$fid}" ] ?? 0 );
		$is_final    = $source_page > 0 && $target_page === 0;
		if ( ! $is_final ) {
			return $confirmation;
		}
		if ( ! class_exists( 'BPM_Play_Limit' ) ) {
			return $confirmation;
		}
		$email = pf_extract_age_gate_email( (array) $entry, $fid );
		if ( $email === '' || ! BPM_Play_Limit::is_over_limit( $email ) ) {
			return $confirmation;
		}
		$daily_limit_url = function_exists( 'pepsi_get_page_url_by_template' )
			? pepsi_get_page_url_by_template( 'page-daily-limit.php' )
			: '';
		if ( $daily_limit_url === '' ) {
			$daily_limit_url = home_url( '/daily-limit/' );
		}
		return [ 'redirect' => $daily_limit_url ];
	}, 110, 4 );
} );
