<?php
/**
 * Prize Data Sync — single source of truth for writes that touch prize fields
 * which exist in multiple storage locations.
 *
 * BACKGROUND
 * ----------
 * A prize's date / prize-name / code / value can live in up to four places:
 *
 *   1. `mapped_data` JSON post meta (what the admin Prize table displays from)
 *   2. Individual postmeta keys (`date`, `win_date_from_file`, `win_date_auto`,
 *      `prize_date`, `prize`, `prize_name_from_distribution`,
 *      `prize_name_from_file`, `code`, `code_1`, `value`, etc.) — used by
 *      WP_Query orderby and various legacy code paths
 *   3. `wp_bpm_prize_entries` row (the canonical slot reservation table — the
 *      live game reads from here, NOT from postmeta)
 *
 * Historically each write path (dashboard inline edit, reseeder, CSV import)
 * updated a different subset of those locations, which drifted the data and
 * broke sort-by-date in the admin table (the sort uses the standalone postmeta
 * while the display uses `mapped_data`).
 *
 * This class is the canonical write API. Every code path that mutates a prize
 * field calls one method here; the method propagates the new value into every
 * location that holds an alias of the same field, ONLY where the alias already
 * exists on that post (so we don't pollute posts with keys they didn't have).
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BPM_Prize_Sync {

    /** All postmeta keys that hold a date alias. */
    private const DATE_META_KEYS = [
        'date',
        'win_date_auto',
        'win_date_from_file',
        'prize_date',
        'date_available',
    ];

    /** All mapped_data keys that hold a date alias. */
    private const DATE_MAPPED_KEYS = [
        'date',
        'win_date_auto',
        'win_date_from_file',
        'prize_date',
        'date_available',
    ];

    /** All postmeta keys that hold the prize name alias. */
    private const PRIZE_NAME_META_KEYS = [
        'prize',
        'prize_name_from_distribution',
        'prize_name_from_file',
    ];

    /** All mapped_data keys that hold the prize name alias. */
    private const PRIZE_NAME_MAPPED_KEYS = [
        'prize',
        'prize_name_from_distribution',
        'prize_name_from_file',
    ];

    /**
     * Parse a date string into MySQL DATETIME (`Y-m-d H:i:s`).
     *
     * UK-first explicit formats — `strtotime()` is intentionally a last
     * resort because it misinterprets ambiguous slashed inputs as US
     * (`05/06/2026` → 6 May, not 5 June). Mirrors the format list in
     * BPM_CSV_Handler::convert_date_format() so every write path agrees.
     *
     * Returns '' if the string can't be parsed.
     */
    public static function normalise_datetime( string $datetime ): string {
        $datetime = trim( $datetime );
        if ( $datetime === '' ) {
            return '';
        }

        // Fast path: already MySQL canonical.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime ) ) {
            return $datetime;
        }

        $formats = [
            'Y-m-d H:i:s',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y',
            'd/m/Y H:i',
            'd/m/Y',
            'Y-m-d',
            'm/d/Y H:i',
            'm/d/Y',
        ];
        foreach ( $formats as $fmt ) {
            $dt = \DateTime::createFromFormat( $fmt, $datetime );
            if ( $dt instanceof \DateTime ) {
                return $dt->format( 'Y-m-d H:i:s' );
            }
        }

        // Last resort.
        $ts = strtotime( $datetime );
        return $ts === false ? '' : date( 'Y-m-d H:i:s', $ts );
    }

    /**
     * Write a date value (MySQL DATETIME string) across every location that
     * already holds an alias of "the prize's date" for the given post.
     *
     * Returns true on success. Idempotent: writing the same value twice is a
     * no-op for any location that already matches.
     */
    public static function write_date( int $prize_post_id, string $datetime ): bool {
        if ( $prize_post_id <= 0 || $datetime === '' ) {
            return false;
        }

        $value = self::normalise_datetime( $datetime );
        if ( $value === '' ) {
            return false;
        }

        // 1. Standalone postmeta — write to EVERY known date alias that
        //    already exists, so legacy reads stay consistent. We also write
        //    `date` unconditionally because it's the canonical key the admin
        //    sort routes through.
        update_post_meta( $prize_post_id, 'date', $value );
        foreach ( self::DATE_META_KEYS as $key ) {
            if ( $key === 'date' || $key === 'date_available' ) {
                continue; // 'date' already done; 'date_available' is a column, not meta
            }
            $existing = get_post_meta( $prize_post_id, $key, true );
            if ( $existing !== '' && $existing !== null && $existing !== false ) {
                update_post_meta( $prize_post_id, $key, $value );
            }
        }

        // 2. mapped_data JSON — update every date-shaped key that already
        //    appears in the blob. Don't add new ones.
        self::patch_mapped_data( $prize_post_id, function ( array $md ) use ( $value ): array {
            foreach ( self::DATE_MAPPED_KEYS as $k ) {
                if ( array_key_exists( $k, $md ) ) {
                    $md[ $k ] = $value;
                }
            }
            return $md;
        } );

        // 3. bpm_prize_entries.date_available — single SQL UPDATE.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bpm_prize_entries',
            [ 'date_available' => $value, 'updated_at' => current_time( 'mysql' ) ],
            [ 'prize_post_id' => $prize_post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /**
     * Write the prize-name value across every alias location for this post.
     */
    public static function write_prize_name( int $prize_post_id, string $name ): bool {
        if ( $prize_post_id <= 0 ) {
            return false;
        }
        $name = wp_strip_all_tags( $name );

        update_post_meta( $prize_post_id, 'prize', $name );
        foreach ( self::PRIZE_NAME_META_KEYS as $key ) {
            if ( $key === 'prize' ) {
                continue;
            }
            $existing = get_post_meta( $prize_post_id, $key, true );
            if ( $existing !== '' && $existing !== null && $existing !== false ) {
                update_post_meta( $prize_post_id, $key, $name );
            }
        }

        self::patch_mapped_data( $prize_post_id, function ( array $md ) use ( $name ): array {
            foreach ( self::PRIZE_NAME_MAPPED_KEYS as $k ) {
                if ( array_key_exists( $k, $md ) ) {
                    $md[ $k ] = $name;
                }
            }
            return $md;
        } );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bpm_prize_entries',
            [ 'prize_name' => $name, 'updated_at' => current_time( 'mysql' ) ],
            [ 'prize_post_id' => $prize_post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /**
     * Write the prize code across every alias location.
     */
    public static function write_code( int $prize_post_id, string $code ): bool {
        if ( $prize_post_id <= 0 ) {
            return false;
        }
        $code = sanitize_text_field( $code );

        update_post_meta( $prize_post_id, 'code', $code );
        // code_1 / code_2 / ... — only sync existing variants.
        for ( $i = 1; $i <= 6; $i++ ) {
            $k = "code_{$i}";
            $existing = get_post_meta( $prize_post_id, $k, true );
            if ( $existing !== '' && $existing !== null && $existing !== false ) {
                update_post_meta( $prize_post_id, $k, $code );
            }
        }

        self::patch_mapped_data( $prize_post_id, function ( array $md ) use ( $code ): array {
            if ( array_key_exists( 'code', $md ) )   { $md['code']   = $code; }
            if ( array_key_exists( 'code_1', $md ) ) { $md['code_1'] = $code; }
            return $md;
        } );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bpm_prize_entries',
            [ 'code' => $code, 'updated_at' => current_time( 'mysql' ) ],
            [ 'prize_post_id' => $prize_post_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /**
     * Write the prize value (e.g. "£5" / "Pepsi Max Pint") across aliases.
     */
    public static function write_value( int $prize_post_id, string $value ): bool {
        if ( $prize_post_id <= 0 ) {
            return false;
        }
        $value = wp_strip_all_tags( $value );

        update_post_meta( $prize_post_id, 'value', $value );
        self::patch_mapped_data( $prize_post_id, function ( array $md ) use ( $value ): array {
            if ( array_key_exists( 'value', $md ) ) { $md['value'] = $value; }
            return $md;
        } );

        return true;
    }

    /**
     * Backfill: read every alias for this post, pick the authoritative source
     * (mapped_data wins where present; otherwise postmeta), then push that
     * value back through the write_* methods so every location ends up equal.
     *
     * This is the one-shot migration entry point. It's safe to call multiple
     * times — second call is a no-op once everything agrees.
     */
    public static function backfill_post( int $prize_post_id ): array {
        if ( $prize_post_id <= 0 ) {
            return [ 'ok' => false, 'reason' => 'invalid_post_id' ];
        }

        $report = [ 'ok' => true, 'fields_synced' => [] ];

        $md_json = get_post_meta( $prize_post_id, 'mapped_data', true );
        $md      = is_string( $md_json ) && $md_json !== '' ? json_decode( $md_json, true ) : null;
        $md      = is_array( $md ) ? $md : [];

        // Date: prefer the FIRST non-empty mapped_data date alias; fall back
        // to the FIRST non-empty postmeta date alias.
        $date_value = '';
        foreach ( self::DATE_MAPPED_KEYS as $k ) {
            if ( ! empty( $md[ $k ] ) ) {
                $date_value = (string) $md[ $k ];
                break;
            }
        }
        if ( $date_value === '' ) {
            foreach ( self::DATE_META_KEYS as $k ) {
                if ( $k === 'date_available' ) {
                    continue;
                }
                $v = get_post_meta( $prize_post_id, $k, true );
                if ( ! empty( $v ) ) {
                    $date_value = (string) $v;
                    break;
                }
            }
        }
        if ( $date_value !== '' && self::write_date( $prize_post_id, $date_value ) ) {
            $report['fields_synced'][] = 'date';
        }

        // Prize name
        $prize_name = '';
        foreach ( self::PRIZE_NAME_MAPPED_KEYS as $k ) {
            if ( ! empty( $md[ $k ] ) ) {
                $prize_name = (string) $md[ $k ];
                break;
            }
        }
        if ( $prize_name === '' ) {
            foreach ( self::PRIZE_NAME_META_KEYS as $k ) {
                $v = get_post_meta( $prize_post_id, $k, true );
                if ( ! empty( $v ) ) {
                    $prize_name = (string) $v;
                    break;
                }
            }
        }
        if ( $prize_name !== '' && self::write_prize_name( $prize_post_id, $prize_name ) ) {
            $report['fields_synced'][] = 'prize_name';
        }

        // Code: mapped_data code/code_1 → postmeta code/code_1
        $code = '';
        foreach ( [ 'code', 'code_1' ] as $k ) {
            if ( ! empty( $md[ $k ] ) ) { $code = (string) $md[ $k ]; break; }
        }
        if ( $code === '' ) {
            foreach ( [ 'code', 'code_1' ] as $k ) {
                $v = get_post_meta( $prize_post_id, $k, true );
                if ( ! empty( $v ) ) { $code = (string) $v; break; }
            }
        }
        if ( $code !== '' && self::write_code( $prize_post_id, $code ) ) {
            $report['fields_synced'][] = 'code';
        }

        // Value
        $value = '';
        if ( ! empty( $md['value'] ) ) {
            $value = (string) $md['value'];
        } else {
            $v = get_post_meta( $prize_post_id, 'value', true );
            $value = is_string( $v ) ? $v : '';
        }
        if ( $value !== '' && self::write_value( $prize_post_id, $value ) ) {
            $report['fields_synced'][] = 'value';
        }

        return $report;
    }

    /**
     * Backfill every prize in the given competition (or all if null).
     */
    public static function backfill_competition( ?int $competition_id = null ): array {
        $args = [
            'post_type'      => 'prize',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ( $competition_id ) {
            $args['meta_query'] = [
                [ 'key' => 'competition_id', 'value' => $competition_id, 'compare' => '=' ],
            ];
        }
        $post_ids = get_posts( $args );

        $synced = 0;
        $errors = 0;
        foreach ( $post_ids as $post_id ) {
            $r = self::backfill_post( (int) $post_id );
            if ( ! empty( $r['ok'] ) ) {
                $synced++;
            } else {
                $errors++;
            }
        }
        return [ 'total' => count( $post_ids ), 'synced' => $synced, 'errors' => $errors ];
    }

    /**
     * Apply a mutator to the mapped_data JSON blob for this post and persist.
     */
    private static function patch_mapped_data( int $prize_post_id, callable $mutator ): void {
        $md_json = get_post_meta( $prize_post_id, 'mapped_data', true );
        if ( ! is_string( $md_json ) || $md_json === '' ) {
            return; // nothing to patch — don't create a blob from thin air
        }
        $md = json_decode( $md_json, true );
        if ( ! is_array( $md ) ) {
            return;
        }
        $new = $mutator( $md );
        if ( ! is_array( $new ) ) {
            return;
        }
        update_post_meta( $prize_post_id, 'mapped_data', wp_json_encode( $new ) );
    }
}
