<?php
/**
 * Competition Scheduler Class
 *
 * Handles automated scheduling and execution of competition state changes
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
 * Competition Scheduler implementation
 */
class BPM_Competition_Scheduler {
    
    /**
     * Competition statuses
     */
    private const STATUS_DRAFT = 'draft';
    private const STATUS_SCHEDULED = 'scheduled';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_CLOSED = 'closed';
    
    /**
     * Time buffer in minutes before competition start to trigger pre-launch checks
     */
    private const PRE_LAUNCH_BUFFER_MINUTES = 30;
    
    /**
     * Process all scheduled competitions
     *
     * This method is called by the cron job to check and process scheduled competitions
     */
    public function process_scheduled_competitions(): void {
        try {
            $competitions = $this->get_scheduled_competitions();
            
            foreach ($competitions as $competition) {
                $this->process_single_competition($competition);
            }
            
            // Clean up old completed competitions
            $this->cleanup_old_competitions();
            
        } catch (Exception $e) {
            BPM_Audit_Logger::log(
                0,
                'scheduler_error',
                'Competition scheduler error: ' . $e->getMessage(),
                'error'
            );
            error_log('BPM Scheduler Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Schedule a competition for automatic start/stop
     *
     * @param int $competition_id Competition ID
     * @param string $start_datetime Start date and time (Y-m-d H:i:s)
     * @param string $end_datetime End date and time (Y-m-d H:i:s)
     * @return bool True if scheduled successfully
     * @throws Exception If scheduling fails
     */
    public function schedule_competition(int $competition_id, string $start_datetime, string $end_datetime): bool {
        $this->validate_schedule_parameters($competition_id, $start_datetime, $end_datetime);
        
        $start_timestamp = strtotime($start_datetime);
        $end_timestamp = strtotime($end_datetime);
        
        if ($start_timestamp >= $end_timestamp) {
            throw new InvalidArgumentException(__('Start time must be before end time', 'britvic-promo'));
        }
        
        // Update competition with schedule information
        $result = $this->update_competition_schedule($competition_id, $start_datetime, $end_datetime);
        
        if ($result) {
            // Schedule WordPress cron events
            $this->schedule_cron_events($competition_id, $start_timestamp, $end_timestamp);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_scheduled',
                sprintf('Competition scheduled: Start %s, End %s', $start_datetime, $end_datetime)
            );
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Cancel scheduled competition
     *
     * @param int $competition_id Competition ID
     * @return bool True if cancelled successfully
     */
    public function cancel_competition_schedule(int $competition_id): bool {
        // Clear scheduled cron events
        $this->clear_competition_cron_events($competition_id);
        
        // Update competition status
        global $wpdb;
        $table_name = $wpdb->prefix . 'bpm_competitions';
        
        $result = $wpdb->update(
            $table_name,
            [
                'status' => self::STATUS_DRAFT,
                'start_date' => null,
                'end_date' => null,
                'updated_at' => current_time('mysql', true)
            ],
            ['id' => $competition_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        if ($result !== false) {
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_schedule_cancelled',
                'Competition schedule cancelled'
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * Get competition schedule status
     *
     * @param int $competition_id Competition ID
     * @return array Schedule information
     */
    public function get_competition_schedule(int $competition_id): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        $competition = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $competition_id),
            ARRAY_A
        );
        
        if (!$competition) {
            return ['error' => 'Competition not found'];
        }
        
        $schedule_info = [
            'id' => $competition['id'],
            'name' => $competition['name'],
            'status' => $competition['status'],
            'start_date' => $competition['start_date'],
            'end_date' => $competition['end_date'],
            'is_scheduled' => !empty($competition['start_date']) && !empty($competition['end_date']),
            'is_active' => $competition['status'] === self::STATUS_ACTIVE,
            'is_closed' => $competition['status'] === self::STATUS_CLOSED
        ];
        
        // Add time-based information
        if ($schedule_info['is_scheduled']) {
            $now = current_time('timestamp', true);
            $start_timestamp = strtotime($competition['start_date']);
            $end_timestamp = strtotime($competition['end_date']);
            
            $schedule_info['starts_in_seconds'] = max(0, $start_timestamp - $now);
            $schedule_info['ends_in_seconds'] = max(0, $end_timestamp - $now);
            $schedule_info['has_started'] = $now >= $start_timestamp;
            $schedule_info['has_ended'] = $now >= $end_timestamp;
            $schedule_info['is_running'] = $now >= $start_timestamp && $now < $end_timestamp;
        }
        
        // Check for scheduled cron events
        $schedule_info['cron_events'] = [
            'publish_scheduled' => wp_next_scheduled('bpm_publish_competition', [$competition_id]),
            'close_scheduled' => wp_next_scheduled('bpm_close_competition', [$competition_id])
        ];
        
        return $schedule_info;
    }
    
    /**
     * Get all scheduled competitions
     *
     * @return array Array of scheduled competitions
     */
    private function get_scheduled_competitions(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        
        // Get competitions that are scheduled or active and have dates
        $competitions = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE status IN ('scheduled', 'active') 
             AND start_date IS NOT NULL 
             AND end_date IS NOT NULL
             ORDER BY start_date ASC",
            ARRAY_A
        );
        
        return $competitions ?: [];
    }
    
    /**
     * Process a single competition's schedule
     *
     * @param array $competition Competition data
     */
    private function process_single_competition(array $competition): void {
        $competition_id = (int) $competition['id'];
        $now = current_time('timestamp', true);
        $start_timestamp = strtotime($competition['start_date']);
        $end_timestamp = strtotime($competition['end_date']);
        
        try {
            // Check if competition should start
            if ($competition['status'] === self::STATUS_SCHEDULED && $now >= $start_timestamp) {
                $this->start_competition($competition_id);
            }
            
            // Check if competition should end
            if ($competition['status'] === self::STATUS_ACTIVE && $now >= $end_timestamp) {
                $this->end_competition($competition_id);
            }
            
            // Pre-launch checks (30 minutes before start)
            if ($competition['status'] === self::STATUS_SCHEDULED) {
                $pre_launch_time = $start_timestamp - (self::PRE_LAUNCH_BUFFER_MINUTES * 60);
                if ($now >= $pre_launch_time && $now < $start_timestamp) {
                    $this->perform_pre_launch_checks($competition_id);
                }
            }
            
        } catch (Exception $e) {
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_processing_error',
                sprintf('Error processing competition %d: %s', $competition_id, $e->getMessage()),
                'error'
            );
        }
    }
    
    /**
     * Start a competition
     *
     * @param int $competition_id Competition ID
     */
    private function start_competition(int $competition_id): void {
        // Use page manager to publish competition pages
        $page_manager = new BPM_Page_Manager();
        $result = $page_manager->toggle_competition_pages($competition_id, 'publish');
        
        if ($result['success']) {
            // Update competition status
            $this->update_competition_status($competition_id, self::STATUS_ACTIVE);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_auto_started',
                'Competition automatically started by scheduler'
            );
            
            // Send notification if configured
            $this->send_competition_notification($competition_id, 'started');
        } else {
            throw new Exception('Failed to start competition: ' . $result['message']);
        }
    }
    
    /**
     * End a competition
     *
     * @param int $competition_id Competition ID
     */
    private function end_competition(int $competition_id): void {
        // Use page manager to close competition pages
        $page_manager = new BPM_Page_Manager();
        $result = $page_manager->toggle_competition_pages($competition_id, 'close');
        
        if ($result['success']) {
            // Update competition status
            $this->update_competition_status($competition_id, self::STATUS_CLOSED);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_auto_ended',
                'Competition automatically ended by scheduler'
            );
            
            // Send notification if configured
            $this->send_competition_notification($competition_id, 'ended');
            
            // Clear remaining cron events
            $this->clear_competition_cron_events($competition_id);
        } else {
            throw new Exception('Failed to end competition: ' . $result['message']);
        }
    }
    
    /**
     * Perform pre-launch checks
     *
     * @param int $competition_id Competition ID
     */
    private function perform_pre_launch_checks(int $competition_id): void {
        $checks = [];
        
        // Check if prizes are configured
        $prize_count = $this->get_competition_prize_count($competition_id);
        $checks['prizes'] = $prize_count > 0;
        
        // Check if pages are configured
        $page_config = $this->check_page_configuration($competition_id);
        $checks['pages'] = $page_config;
        
        // Check if forms are ready (if applicable)
        $checks['forms'] = true; // Simplified for now
        
        $all_checks_passed = !in_array(false, $checks, true);
        
        BPM_Audit_Logger::log(
            $competition_id,
            'pre_launch_checks',
            sprintf(
                'Pre-launch checks: Prizes=%s, Pages=%s, Forms=%s',
                $checks['prizes'] ? 'OK' : 'FAIL',
                $checks['pages'] ? 'OK' : 'FAIL',
                $checks['forms'] ? 'OK' : 'FAIL'
            ),
            $all_checks_passed ? 'info' : 'warning'
        );
        
        if (!$all_checks_passed) {
            // Send warning notification
            $this->send_pre_launch_warning($competition_id, $checks);
        }
    }
    
    /**
     * Validate schedule parameters
     */
    private function validate_schedule_parameters(int $competition_id, string $start_datetime, string $end_datetime): void {
        if ($competition_id <= 0) {
            throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
        }
        
        $start_timestamp = strtotime($start_datetime);
        $end_timestamp = strtotime($end_datetime);
        
        if (!$start_timestamp || !$end_timestamp) {
            throw new InvalidArgumentException(__('Invalid datetime format', 'britvic-promo'));
        }
        
        $now = current_time('timestamp', true);
        if ($start_timestamp <= $now) {
            throw new InvalidArgumentException(__('Start time must be in the future', 'britvic-promo'));
        }
    }
    
    /**
     * Update competition schedule in database
     */
    private function update_competition_schedule(int $competition_id, string $start_datetime, string $end_datetime): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        
        $result = $wpdb->update(
            $table_name,
            [
                'status' => self::STATUS_SCHEDULED,
                'start_date' => $start_datetime,
                'end_date' => $end_datetime,
                'updated_at' => current_time('mysql', true)
            ],
            ['id' => $competition_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Schedule WordPress cron events
     */
    private function schedule_cron_events(int $competition_id, int $start_timestamp, int $end_timestamp): void {
        // Clear any existing events first
        $this->clear_competition_cron_events($competition_id);
        
        // Schedule start event
        wp_schedule_single_event($start_timestamp, 'bpm_publish_competition', [$competition_id]);
        
        // Schedule end event
        wp_schedule_single_event($end_timestamp, 'bpm_close_competition', [$competition_id]);
    }
    
    /**
     * Clear competition cron events
     */
    private function clear_competition_cron_events(int $competition_id): void {
        // Clear publish event
        $publish_timestamp = wp_next_scheduled('bpm_publish_competition', [$competition_id]);
        if ($publish_timestamp) {
            wp_unschedule_event($publish_timestamp, 'bpm_publish_competition', [$competition_id]);
        }
        
        // Clear close event
        $close_timestamp = wp_next_scheduled('bpm_close_competition', [$competition_id]);
        if ($close_timestamp) {
            wp_unschedule_event($close_timestamp, 'bpm_close_competition', [$competition_id]);
        }
    }
    
    /**
     * Update competition status
     */
    private function update_competition_status(int $competition_id, string $status): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        
        $result = $wpdb->update(
            $table_name,
            ['status' => $status, 'updated_at' => current_time('mysql', true)],
            ['id' => $competition_id],
            ['%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get competition prize count
     */
    private function get_competition_prize_count(int $competition_id): int {
        $args = [
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Check page configuration
     */
    private function check_page_configuration(int $competition_id): bool {
        try {
            $page_manager = new BPM_Page_Manager();
            $page_config = $page_manager->get_competition_pages($competition_id);
            
            return !empty($page_config['live_page_id']) || !empty($page_config['closed_page_id']);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Send competition notification
     */
    private function send_competition_notification(int $competition_id, string $event): void {
        // Implementation for sending notifications (email, webhook, etc.)
        // This could be enhanced to send actual notifications to administrators
        BPM_Audit_Logger::log(
            $competition_id,
            'notification_sent',
            sprintf('Competition %s notification triggered', $event)
        );
    }
    
    /**
     * Send pre-launch warning
     */
    private function send_pre_launch_warning(int $competition_id, array $checks): void {
        $failed_checks = [];
        foreach ($checks as $check => $passed) {
            if (!$passed) {
                $failed_checks[] = $check;
            }
        }
        
        BPM_Audit_Logger::log(
            $competition_id,
            'pre_launch_warning',
            sprintf('Pre-launch warning: Failed checks: %s', implode(', ', $failed_checks)),
            'warning'
        );
    }
    
    /**
     * Clean up old completed competitions
     */
    private function cleanup_old_competitions(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        
        // Clean up competitions that ended more than 30 days ago
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $old_competitions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE status = 'closed' AND end_date < %s",
                $cutoff_date
            ),
            ARRAY_A
        );
        
        foreach ($old_competitions as $competition) {
            $this->clear_competition_cron_events((int) $competition['id']);
        }
        
        if (!empty($old_competitions)) {
            BPM_Audit_Logger::log(
                0,
                'old_competitions_cleanup',
                sprintf('Cleaned up cron events for %d old competitions', count($old_competitions))
            );
        }
    }
}