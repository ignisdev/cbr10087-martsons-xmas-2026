<?php
/**
 * Per-player daily play-limit tracking.
 *
 * Generic, reusable across promos: one row per (email_hash, play_date) in
 * `wp_bpm_daily_plays`, atomic increment via ON DUPLICATE KEY UPDATE so
 * concurrent requests can't both squeak past the cap.
 *
 * The per-promo cap is read from a filter (`bpm_play_limit_per_day`) so each
 * theme can set its own value without touching the plugin. Default = 0 (no
 * limit) so the plugin doesn't impose behaviour on promos that don't opt in.
 *
 * @package BritvicPromotionManager
 * @since 1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class BPM_Play_Limit {

    public const TABLE          = 'bpm_daily_plays';
    public const RETENTION_DAYS = 7;
    public const CRON_HOOK      = 'bpm_daily_plays_cleanup';

    /** Fully-qualified table name. */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Per-day cap. Filter `bpm_play_limit_per_day` to set per-theme. */
    public static function limit(): int {
        return (int) apply_filters('bpm_play_limit_per_day', 0);
    }

    /** SHA-256 of a normalised email — what gets stored in the table. */
    public static function hash_email(string $email): string {
        return hash('sha256', strtolower(trim($email)));
    }

    /** Today in WP timezone. */
    public static function today(): string {
        return wp_date('Y-m-d');
    }

    /**
     * Return today's play count for an email. 0 if no row.
     */
    public static function count_today(string $email): int {
        if ($email === '') {
            return 0;
        }
        global $wpdb;
        $table = self::table_name();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT play_count FROM {$table} WHERE email_hash = %s AND play_date = %s LIMIT 1",
            self::hash_email($email),
            self::today()
        ));
        return (int) $count;
    }

    /**
     * Atomically increment today's play count for an email and return the new
     * value. Returns 0 on invalid input (caller should treat as "fail open").
     */
    public static function record(string $email): int {
        if ($email === '') {
            return 0;
        }
        global $wpdb;
        $table      = self::table_name();
        $email_hash = self::hash_email($email);
        $date       = self::today();
        $now        = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (email_hash, play_date, play_count, updated_at)
             VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE play_count = play_count + 1, updated_at = VALUES(updated_at)",
            $email_hash,
            $date,
            $now
        ));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT play_count FROM {$table} WHERE email_hash = %s AND play_date = %s LIMIT 1",
            $email_hash,
            $date
        ));
    }

    /**
     * Has this email reached the per-day cap? Returns false when the cap is 0
     * (no limit configured) so promos that don't opt in are never blocked.
     */
    public static function is_over_limit(string $email): bool {
        $limit = self::limit();
        if ($limit <= 0 || $email === '') {
            return false;
        }
        return self::count_today($email) >= $limit;
    }

    /**
     * Daily cleanup: drop rows older than RETENTION_DAYS so the table stays
     * tiny. Registered via WP-cron in init_cron().
     */
    public static function cleanup_old_rows(): void {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = wp_date('Y-m-d', strtotime('-' . self::RETENTION_DAYS . ' days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE play_date < %s",
            $cutoff
        ));
    }

    /** Schedule the daily cleanup if not already scheduled. */
    public static function init_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $first = strtotime('tomorrow 03:00', current_time('timestamp'));
            wp_schedule_event($first, 'daily', self::CRON_HOOK);
        }
    }
}

add_action(BPM_Play_Limit::CRON_HOOK, [BPM_Play_Limit::class, 'cleanup_old_rows']);
add_action('wp', [BPM_Play_Limit::class, 'init_cron']);
