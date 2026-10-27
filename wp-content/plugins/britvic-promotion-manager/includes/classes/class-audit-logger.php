<?php
/**
 * Audit Logger Class
 *
 * Handles comprehensive audit logging for all plugin activities
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit Logger implementation
 */
class BPM_Audit_Logger {
    
    /**
     * Database table name for audit logs
     */
    private const TABLE_NAME = 'bpm_audit_log';
    
    /**
     * Maximum age of audit logs in days
     */
    private const MAX_LOG_AGE_DAYS = 365;
    
    /**
     * Log levels
     */
    private const LOG_LEVELS = [
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];
    
    /**
     * Log an audit event
     *
     * @param int $competition_id Competition ID (0 for system-wide events)
     * @param string $action Action performed
     * @param string $details Additional details about the action
     * @param string $level Log level (info, warning, error, critical)
     * @param array $metadata Additional metadata
     * @return bool True on success, false on failure
     */
    public static function log(
        int $competition_id,
        string $action,
        string $details,
        string $level = 'info',
        array $metadata = []
    ): bool {
        
        try {
            global $wpdb;
            
            $table_name = $wpdb->prefix . self::TABLE_NAME;
            
            // Validate log level
            if (!array_key_exists($level, self::LOG_LEVELS)) {
                $level = 'info';
            }
            
            // Prepare audit data
            $audit_data = [
                'competition_id' => $competition_id ?: null,
                'action' => sanitize_text_field($action),
                'details' => wp_kses_post($details),
                'level' => $level,
                'user_id' => get_current_user_id() ?: null,
                'ip_address' => self::get_client_ip(),
                'user_agent' => self::get_user_agent(),
                'metadata' => wp_json_encode($metadata),
                'created_at' => current_time('mysql', true)
            ];
            
            // Insert audit log
            $result = $wpdb->insert(
                $table_name,
                $audit_data,
                ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
            
            if ($result === false) {
                error_log('BPM Audit Logger: Failed to insert audit log - ' . $wpdb->last_error);
                return false;
            }
            
            // Trigger cleanup if needed
            self::maybe_cleanup_old_logs();
            
            return true;
            
        } catch (Exception $e) {
            error_log('BPM Audit Logger Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log a prize award event with detailed information
     *
     * @param int $competition_id Competition ID
     * @param int $prize_id Prize post ID
     * @param array $winner_data Winner information
     * @param string $form_entry_id Gravity Forms entry ID
     * @return bool True on success
     */
    public static function log_prize_award(
        int $competition_id,
        int $prize_id,
        array $winner_data,
        string $form_entry_id = ''
    ): bool {
        
        $details = sprintf(
            'Prize awarded - Prize ID: %d, Winner: %s (%s), Form Entry: %s',
            $prize_id,
            $winner_data['name'] ?? 'Unknown',
            $winner_data['email'] ?? 'Unknown',
            $form_entry_id
        );
        
        $metadata = [
            'prize_id' => $prize_id,
            'winner_data' => $winner_data,
            'form_entry_id' => $form_entry_id,
            'prize_details' => self::get_prize_details($prize_id)
        ];
        
        return self::log($competition_id, 'prize_awarded', $details, 'info', $metadata);
    }
    
    /**
     * Log a failed prize attempt
     *
     * @param int $competition_id Competition ID
     * @param array $user_data User information
     * @param string $reason Reason for failure
     * @return bool True on success
     */
    public static function log_prize_failure(
        int $competition_id,
        array $user_data,
        string $reason
    ): bool {
        
        $details = sprintf(
            'Prize attempt failed - User: %s (%s), Reason: %s',
            $user_data['name'] ?? 'Unknown',
            $user_data['email'] ?? 'Unknown',
            $reason
        );
        
        $metadata = [
            'user_data' => $user_data,
            'failure_reason' => $reason,
            'available_prizes' => self::get_available_prize_count($competition_id)
        ];
        
        return self::log($competition_id, 'prize_attempt_failed', $details, 'warning', $metadata);
    }
    
    /**
     * Log security events (blacklisted users, suspicious activity)
     *
     * @param int $competition_id Competition ID
     * @param string $event_type Type of security event
     * @param array $user_data User information
     * @param string $details Additional details
     * @return bool True on success
     */
    public static function log_security_event(
        int $competition_id,
        string $event_type,
        array $user_data,
        string $details = ''
    ): bool {
        
        $log_details = sprintf(
            'Security Event: %s - User: %s (%s), IP: %s. %s',
            $event_type,
            $user_data['name'] ?? 'Unknown',
            $user_data['email'] ?? 'Unknown',
            self::get_client_ip(),
            $details
        );
        
        $metadata = [
            'event_type' => $event_type,
            'user_data' => $user_data,
            'request_details' => [
                'user_agent' => self::get_user_agent(),
                'referer' => wp_get_referer(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]
        ];
        
        return self::log($competition_id, 'security_event', $log_details, 'warning', $metadata);
    }
    
    /**
     * Log competition state changes
     *
     * @param int $competition_id Competition ID
     * @param string $from_state Previous state
     * @param string $to_state New state
     * @param string $trigger What triggered the change
     * @return bool True on success
     */
    public static function log_competition_state_change(
        int $competition_id,
        string $from_state,
        string $to_state,
        string $trigger = 'manual'
    ): bool {
        
        $details = sprintf(
            'Competition state changed from "%s" to "%s" (triggered by: %s)',
            $from_state,
            $to_state,
            $trigger
        );
        
        $metadata = [
            'from_state' => $from_state,
            'to_state' => $to_state,
            'trigger' => $trigger,
            'competition_data' => self::get_competition_summary($competition_id)
        ];
        
        return self::log($competition_id, 'competition_state_changed', $details, 'info', $metadata);
    }
    
    /**
     * Get audit logs for display
     *
     * @param array $filters Filters to apply
     * @param int $limit Number of logs to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of audit log entries
     */
    public static function get_audit_logs(array $filters = [], int $limit = 50, int $offset = 0): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Build WHERE clause
        $where_clauses = ['1=1'];
        $where_values = [];
        
        if (!empty($filters['competition_id'])) {
            $where_clauses[] = 'competition_id = %d';
            $where_values[] = (int) $filters['competition_id'];
        }
        
        if (!empty($filters['action'])) {
            $where_clauses[] = 'action = %s';
            $where_values[] = sanitize_text_field($filters['action']);
        }
        
        if (!empty($filters['level'])) {
            $where_clauses[] = 'level = %s';
            $where_values[] = sanitize_text_field($filters['level']);
        }
        
        if (!empty($filters['user_id'])) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = (int) $filters['user_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_clauses);
        
        // Prepare query
        $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $limit;
        $where_values[] = $offset;
        
        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, ...$where_values);
        }
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Decode metadata for each result
        foreach ($results as &$result) {
            $result['metadata'] = json_decode($result['metadata'], true) ?: [];
            
            // Add user display name if user_id exists
            if ($result['user_id']) {
                $user = get_user_by('ID', $result['user_id']);
                $result['user_display_name'] = $user ? $user->display_name : 'Unknown User';
            }
        }
        
        return $results;
    }
    
    /**
     * Get audit log statistics
     *
     * @param int $competition_id Competition ID (0 for all competitions)
     * @param int $days Number of days to look back
     * @return array Statistics array
     */
    public static function get_audit_statistics(int $competition_id = 0, int $days = 30): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $where_clause = 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
        $params = [$days];
        
        if ($competition_id > 0) {
            $where_clause .= ' AND competition_id = %d';
            $params[] = $competition_id;
        }
        
        // Get total counts by action
        $action_query = "
            SELECT action, COUNT(*) as count 
            FROM {$table_name} 
            WHERE {$where_clause}
            GROUP BY action 
            ORDER BY count DESC
        ";
        
        $action_stats = $wpdb->get_results(
            $wpdb->prepare($action_query, ...$params),
            ARRAY_A
        );
        
        // Get counts by level
        $level_query = "
            SELECT level, COUNT(*) as count 
            FROM {$table_name} 
            WHERE {$where_clause}
            GROUP BY level
        ";
        
        $level_stats = $wpdb->get_results(
            $wpdb->prepare($level_query, ...$params),
            ARRAY_A
        );
        
        // Get daily activity
        $daily_query = "
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM {$table_name} 
            WHERE {$where_clause}
            GROUP BY DATE(created_at) 
            ORDER BY date DESC
        ";
        
        $daily_stats = $wpdb->get_results(
            $wpdb->prepare($daily_query, ...$params),
            ARRAY_A
        );
        
        return [
            'actions' => $action_stats,
            'levels' => $level_stats,
            'daily_activity' => $daily_stats,
            'total_events' => array_sum(array_column($action_stats, 'count'))
        ];
    }
    
    /**
     * Export audit logs to CSV
     *
     * @param array $filters Filters to apply
     * @return array File information
     * @throws Exception If export fails
     */
    public static function export_audit_logs(array $filters = []): array {
        $logs = self::get_audit_logs($filters, 10000, 0); // Large limit for export
        
        if (empty($logs)) {
            throw new Exception(__('No audit logs found to export.', 'britvic-promo'));
        }
        
        $csv_data = [];
        $headers = [
            'ID', 'Competition ID', 'Action', 'Details', 'Level', 
            'User ID', 'User Name', 'IP Address', 'Created At'
        ];
        
        foreach ($logs as $log) {
            $csv_data[] = [
                $log['id'],
                $log['competition_id'] ?: '',
                $log['action'],
                $log['details'],
                $log['level'],
                $log['user_id'] ?: '',
                $log['user_display_name'] ?? '',
                $log['ip_address'],
                $log['created_at']
            ];
        }
        
        $csv_handler = new BPM_CSV_Handler();
        $csv_content = $csv_handler->generate_csv_content($csv_data, $headers);
        
        $filename = sprintf('audit_logs_%s.csv', date('Y-m-d_H-i-s'));
        return $csv_handler->save_csv_file($csv_content, $filename);
    }
    
    /**
     * Clean up old audit logs
     *
     * @param int $days Maximum age in days
     * @return int Number of deleted records
     */
    public static function cleanup_old_logs(int $days = null): int {
        global $wpdb;
        
        $days = $days ?: self::MAX_LOG_AGE_DAYS;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $query = "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
        $result = $wpdb->query($wpdb->prepare($query, $days));
        
        if ($result !== false && $result > 0) {
            self::log(0, 'audit_cleanup', "Cleaned up {$result} old audit log entries");
        }
        
        return (int) $result;
    }
    
    /**
     * Maybe cleanup old logs (probabilistic cleanup)
     */
    private static function maybe_cleanup_old_logs(): void {
        // Run cleanup with 1% probability
        if (wp_rand(1, 100) === 1) {
            wp_schedule_single_event(time() + 60, 'bpm_cleanup_audit_logs');
        }
    }
    
    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    private static function get_client_ip(): string {
        $ip_fields = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_fields as $field) {
            if (!empty($_SERVER[$field])) {
                $ip = $_SERVER[$field];
                // Handle comma-separated IPs (from proxies)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Get user agent string
     *
     * @return string User agent (truncated to 255 chars)
     */
    private static function get_user_agent(): string {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return substr(sanitize_text_field($user_agent), 0, 255);
    }
    
    /**
     * Get prize details for logging
     *
     * @param int $prize_id Prize post ID
     * @return array Prize details
     */
    private static function get_prize_details(int $prize_id): array {
        return [
            'prize_type' => get_post_meta($prize_id, 'prize', true),
            'prize_value' => get_post_meta($prize_id, 'value', true),
            'prize_code' => get_post_meta($prize_id, 'code', true),
            'prize_date' => get_post_meta($prize_id, 'date', true)
        ];
    }
    
    /**
     * Get available prize count for competition
     *
     * @param int $competition_id Competition ID
     * @return int Number of available prizes
     */
    private static function get_available_prize_count(int $competition_id): int {
        $args = [
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ],
                [
                    'key' => 'claimed',
                    'value' => 'true',
                    'compare' => '!='
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Get competition summary for logging
     *
     * @param int $competition_id Competition ID
     * @return array Competition summary
     */
    private static function get_competition_summary(int $competition_id): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        $competition = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $competition_id),
            ARRAY_A
        );
        
        if (!$competition) {
            return ['error' => 'Competition not found'];
        }
        
        return [
            'name' => $competition['name'],
            'type' => $competition['type'],
            'status' => $competition['status'],
            'start_date' => $competition['start_date'],
            'end_date' => $competition['end_date']
        ];
    }
}