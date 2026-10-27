<?php
/**
 * Access Control Class
 *
 * Single source of truth for player-access restrictions across all BPM-driven
 * promotions. Reads wp_bpm_user_blocks and decides whether a player identity
 * (email, name, domain, or IP) is restricted from winning.
 *
 * INTEGRATION RECIPE (for any promo that wants block-aware win logic):
 *
 *   // Inside your promo's win-decision code, before reserving a prize:
 *   $identity = [
 *       'email'      => $player_email,      // optional
 *       'first_name' => $player_first_name, // optional
 *       'last_name'  => $player_last_name,  // optional
 *       'full_name'  => $player_full_name,  // optional
 *       'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
 *   ];
 *   if ( BPM_Access_Control::should_force_loss( $identity, $competition_id ) ) {
 *       // Force this player to lose (skip slot reservation, force losing card,
 *       // exclude from draw pool — whatever the promo's "lose" path is).
 *   }
 *
 *   // OR via the filter, for promos using shared win-decision logic:
 *   $can_win = apply_filters( 'bpm_player_can_win', true, $identity, $competition_id );
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BPM_Access_Control {

    /**
     * Is this player identity restricted from this competition?
     *
     * Matches against wp_bpm_user_blocks rows scoped to $competition_id:
     *   - block_type='ip'     : exact match on $identity['ip']
     *   - block_type='email'  : case-insensitive exact match on $identity['email']
     *   - block_type='domain' : case-insensitive exact match on the domain part
     *                           of $identity['email']
     *   - block_type='name'   : case-insensitive SUBSTRING match against
     *                           first_name, last_name, or full_name (mirrors
     *                           the legacy regex-style behaviour expected by
     *                           hand-curated compère name lists)
     *
     * Returns true on the first match. Audit-logs a player_restriction_matched
     * event when a match is found.
     *
     * @param array $identity Keys: email, first_name, last_name, full_name, ip
     *                        (any subset — pass what the promo has). Missing or
     *                        empty keys are skipped.
     * @param int   $competition_id
     */
    public static function is_player_restricted( array $identity, int $competition_id ): bool {
        if ( $competition_id <= 0 ) {
            return false;
        }

        $email      = isset( $identity['email'] )      ? trim( (string) $identity['email'] )      : '';
        $first_name = isset( $identity['first_name'] ) ? trim( (string) $identity['first_name'] ) : '';
        $last_name  = isset( $identity['last_name'] )  ? trim( (string) $identity['last_name'] )  : '';
        $full_name  = isset( $identity['full_name'] )  ? trim( (string) $identity['full_name'] )  : '';
        $ip         = isset( $identity['ip'] )         ? trim( (string) $identity['ip'] )         : '';

        if ( $full_name === '' && ( $first_name !== '' || $last_name !== '' ) ) {
            $full_name = trim( $first_name . ' ' . $last_name );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bpm_user_blocks';

        // IP — exact match.
        if ( $ip !== '' ) {
            $hit = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, block_type, block_value, block_behavior FROM $table
                 WHERE competition_id = %d AND block_type = 'ip' AND block_value = %s
                 LIMIT 1",
                $competition_id,
                $ip
            ), ARRAY_A );
            if ( $hit ) {
                self::log_match( $hit, $identity, $competition_id );
                return true;
            }
        }

        // Email — case-insensitive exact match.
        if ( $email !== '' ) {
            $hit = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, block_type, block_value, block_behavior FROM $table
                 WHERE competition_id = %d AND block_type = 'email'
                   AND LOWER(block_value) = LOWER(%s)
                 LIMIT 1",
                $competition_id,
                $email
            ), ARRAY_A );
            if ( $hit ) {
                self::log_match( $hit, $identity, $competition_id );
                return true;
            }

            // Domain — case-insensitive exact match on the domain portion.
            $at = strrpos( $email, '@' );
            if ( $at !== false ) {
                $domain = substr( $email, $at + 1 );
                if ( $domain !== '' ) {
                    $hit = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, block_type, block_value, block_behavior FROM $table
                         WHERE competition_id = %d AND block_type = 'domain'
                           AND LOWER(block_value) = LOWER(%s)
                         LIMIT 1",
                        $competition_id,
                        $domain
                    ), ARRAY_A );
                    if ( $hit ) {
                        self::log_match( $hit, $identity, $competition_id );
                        return true;
                    }
                }
            }
        }

        // Name — case-insensitive SUBSTRING match against first/last/full.
        // We do the substring match in SQL via LIKE %value% so the DB index
        // doesn't help — but name lists are short (dozens of rows) and this
        // only runs once per attempt, so the cost is negligible.
        if ( $first_name !== '' || $last_name !== '' || $full_name !== '' ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, block_type, block_value, block_behavior FROM $table
                 WHERE competition_id = %d AND block_type = 'name'",
                $competition_id
            ), ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $needle = strtolower( trim( (string) ( $row['block_value'] ?? '' ) ) );
                    if ( $needle === '' ) {
                        continue;
                    }
                    foreach ( [ $first_name, $last_name, $full_name ] as $hay ) {
                        if ( $hay !== '' && str_contains( strtolower( $hay ), $needle ) ) {
                            self::log_match( $row, $identity, $competition_id );
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Plug-and-play convenience: returns true if the player is restricted AND
     * the matched block's behaviour is not "prevent_entry" (i.e. should be
     * silently forced to lose rather than blocked outright).
     *
     * Most game-flow promos (instant-win flicks, scratchcards, wheels) want
     * this — let the player play, but never let them win.
     *
     * Promos that need to handle prevent_entry differently (e.g. an age-gate
     * redirect) should call is_player_restricted() directly and branch on the
     * matched row's block_behavior.
     */
    public static function should_force_loss( array $identity, int $competition_id ): bool {
        if ( ! self::is_player_restricted( $identity, $competition_id ) ) {
            return false;
        }
        // Default semantic: any matched restriction forces a loss in the game.
        // The `prevent_entry` flag is a promo-level signal that the player
        // should not even reach the game in the first place — but if they did
        // reach it (because the promo hasn't implemented pre-entry blocking),
        // forcing a loss is still the safe fallback.
        return true;
    }

    /**
     * Filter callback for `bpm_player_can_win`.
     *
     * Promos using shared win-decision logic can do:
     *
     *   $can_win = apply_filters( 'bpm_player_can_win', true, $identity, $comp_id );
     *
     * …and this class will transparently veto the win when the identity is
     * restricted.
     */
    public static function filter_player_can_win( bool $can_win, array $identity, int $competition_id ): bool {
        if ( ! $can_win ) {
            return false;
        }
        return ! self::is_player_restricted( $identity, $competition_id );
    }

    /**
     * Emit an audit-log entry + debug-log line when a restriction matches.
     */
    private static function log_match( array $block_row, array $identity, int $competition_id ): void {
        $email_masked = '';
        if ( ! empty( $identity['email'] ) ) {
            $email = (string) $identity['email'];
            $at = strrpos( $email, '@' );
            if ( $at !== false && $at > 1 ) {
                $email_masked = substr( $email, 0, 1 ) . str_repeat( '*', max( 1, $at - 1 ) ) . substr( $email, $at );
            } else {
                $email_masked = str_repeat( '*', strlen( $email ) );
            }
        }

        $ctx = [
            'block_id'       => (int) ( $block_row['id'] ?? 0 ),
            'block_type'     => (string) ( $block_row['block_type'] ?? '' ),
            'block_value'    => (string) ( $block_row['block_value'] ?? '' ),
            'block_behavior' => (string) ( $block_row['block_behavior'] ?? '' ),
            'email_masked'   => $email_masked,
            'ip'             => (string) ( $identity['ip'] ?? '' ),
        ];

        error_log( sprintf(
            'BPM ACCESS restricted: comp=%d type=%s value=%s behaviour=%s ip=%s email=%s',
            $competition_id,
            $ctx['block_type'],
            $ctx['block_value'],
            $ctx['block_behavior'],
            $ctx['ip'],
            $ctx['email_masked']
        ) );

        if ( class_exists( 'BPM_Audit_Logger' ) ) {
            BPM_Audit_Logger::log(
                $competition_id,
                'player_restriction_matched',
                sprintf( 'Player restriction matched: %s = %s', $ctx['block_type'], $ctx['block_value'] ),
                'warning',
                $ctx
            );
        }
    }
}

// Register the filter so promos using the `bpm_player_can_win` filter get the
// access-control veto for free.
add_filter( 'bpm_player_can_win', [ BPM_Access_Control::class, 'filter_player_can_win' ], 10, 3 );
