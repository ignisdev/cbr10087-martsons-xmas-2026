<?php
/**
 * Prize Generator Class
 *
 * Handles prize date generation and prize post creation
 * Enhanced version of the existing prize logic with modern PHP practices
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
 * Prize Generator implementation
 */
class BPM_Prize_Generator implements BPM_Prize_Generator_Interface {
    
    /**
     * Prize post type name
     */
    private const PRIZE_POST_TYPE = 'prize';
    
    /**
     * Maximum number of dates that can be generated in one batch
     */
    private const MAX_BATCH_SIZE = 50000;
    
    /**
     * Time increment in minutes for slot generation
     */
    private const TIME_INCREMENT_MINUTES = 1;
    
    /**
     * Generate prize dates and create prize posts
     *
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format  
     * @param string $start_time Start time in H:i format
     * @param string $end_time End time in H:i format
     * @param int $num_dates Number of dates to generate
     * @param array $prizes_data Array of prize data with types and values
     * @return array Result array with success status and data
     * @throws InvalidArgumentException If parameters are invalid
     */
    public function generate_prize_dates(
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time,
        int $num_dates,
        array $prizes_data
    ): array {
        
        try {
            // Validate all parameters
            $this->validate_parameters($start_date, $end_date, $start_time, $end_time, $num_dates, $prizes_data);
            
            // Generate random timestamps
            $timestamps = $this->generate_random_timestamps(
                $start_date,
                $end_date,
                $start_time,
                $end_time,
                $num_dates
            );
            
            // Prepare prize data with timestamps
            $prepared_prizes = $this->prepare_prize_data($timestamps, $prizes_data);
            
            // Create prize posts
            $competition_id = $prizes_data['competition_id'] ?? 0;
            $result = $this->create_prize_posts($prepared_prizes, $competition_id);
            
            // Generate CSV file for backup/audit
            $csv_result = $this->generate_csv_backup($prepared_prizes, $competition_id);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'prizes_generated',
                sprintf('Generated %d prizes for competition', count($prepared_prizes))
            );
            
            return [
                'success' => true,
                'created_count' => count($result['created']),
                'failed_count' => count($result['failed']),
                'csv_file' => $csv_result['file_path'] ?? null,
                'created_ids' => $result['created'],
                'errors' => $result['failed']
            ];
            
        } catch (Exception $e) {
            BPM_Audit_Logger::log(
                $prizes_data['competition_id'] ?? 0,
                'prize_generation_failed',
                'Prize generation failed: ' . $e->getMessage()
            );
            
            throw $e;
        }
    }
    
    /**
     * Generate random timestamps within date/time ranges
     *
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @param string $start_time Start time in H:i format
     * @param string $end_time End time in H:i format
     * @param int $count Number of timestamps to generate
     * @return array Array of unique timestamps
     * @throws InvalidArgumentException If date ranges are invalid
     */
    public function generate_random_timestamps(
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time,
        int $count
    ): array {
        
        $start_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$start_date} {$start_time}");
        $end_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$end_date} {$end_time}");
        
        if (!$start_datetime || !$end_datetime) {
            throw new InvalidArgumentException(__('Invalid date/time format provided', 'britvic-promo'));
        }
        
        if ($start_datetime >= $end_datetime) {
            throw new InvalidArgumentException(__('Start date must be before end date', 'britvic-promo'));
        }
        
        // Generate all possible timestamps
        $all_timestamps = $this->generate_all_possible_timestamps(
            $start_datetime,
            $end_datetime,
            $start_time,
            $end_time
        );
        
        $available_slots = count($all_timestamps);
        
        if ($count > $available_slots) {
            throw new InvalidArgumentException(
                sprintf(
                    __('Requested %d dates but only %d slots available', 'britvic-promo'),
                    $count,
                    $available_slots
                )
            );
        }
        
        // Randomly sample unique timestamps
        $selected_keys = array_rand($all_timestamps, $count);
        
        // Handle case where only one timestamp is selected
        if (!is_array($selected_keys)) {
            $selected_keys = [$selected_keys];
        }
        
        $selected_timestamps = [];
        foreach ($selected_keys as $key) {
            $selected_timestamps[] = $all_timestamps[$key];
        }
        
        sort($selected_timestamps);
        
        return $selected_timestamps;
    }
    
    /**
     * Generate all possible timestamps within the date/time range
     *
     * @param DateTime $start_datetime Start datetime
     * @param DateTime $end_datetime End datetime
     * @param string $start_time Daily start time
     * @param string $end_time Daily end time
     * @return array Array of all possible timestamps
     */
    private function generate_all_possible_timestamps(
        DateTime $start_datetime,
        DateTime $end_datetime,
        string $start_time,
        string $end_time
    ): array {
        
        $all_timestamps = [];
        $current_date = clone $start_datetime;
        $current_date->setTime(0, 0, 0); // Reset to start of day
        
        while ($current_date <= $end_datetime) {
            $day_start = clone $current_date;
            $day_start->setTime(
                ...array_map('intval', explode(':', $start_time))
            );
            
            $day_end = clone $current_date;
            $day_end->setTime(
                ...array_map('intval', explode(':', $end_time))
            );
            
            // Ensure we don't go beyond the overall end datetime
            if ($day_end > $end_datetime) {
                $day_end = clone $end_datetime;
            }
            
            // Generate timestamps for this day
            $current_time = clone $day_start;
            while ($current_time <= $day_end) {
                // Only include if within the overall range
                if ($current_time >= $start_datetime && $current_time <= $end_datetime) {
                    $all_timestamps[] = $current_time->getTimestamp();
                }
                $current_time->add(new DateInterval('PT' . self::TIME_INCREMENT_MINUTES . 'M'));
            }
            
            $current_date->add(new DateInterval('P1D'));
        }
        
        return $all_timestamps;
    }
    
    /**
     * Prepare prize data with timestamps and UUIDs
     *
     * @param array $timestamps Array of timestamps
     * @param array $prizes_data Prize configuration data
     * @return array Prepared prize data
     */
    private function prepare_prize_data(array $timestamps, array $prizes_data): array {
        $prepared_prizes = [];
        
        foreach ($timestamps as $timestamp) {
            // Generate unique ID for this prize
            $unique_id = wp_generate_uuid4();
            
            // Select prize type based on configuration
            $prize_config = $this->select_prize_type($prizes_data);
            
            $prepared_prizes[] = [
                'unique_id' => $unique_id,
                'timestamp' => $timestamp,
                'date_formatted' => date('d/m/Y H:i', $timestamp),
                'date_mysql' => date('Y-m-d H:i:s', $timestamp),
                'prize' => $prize_config['prize'],
                'value' => $prize_config['value'] ?? '',
                'code' => $prize_config['code'] ?? $unique_id,
                'claimed' => false,
            ];
        }
        
        return $prepared_prizes;
    }
    
    /**
     * Select prize type based on configuration and distribution logic
     *
     * @param array $prizes_data Prize configuration
     * @return array Selected prize configuration
     */
    private function select_prize_type(array $prizes_data): array {
        // Default prize distribution logic - can be enhanced based on requirements
        $prize_types = $prizes_data['types'] ?? [];
        
        if (empty($prize_types)) {
            return [
                'prize' => null,
                'value' => '',
                'code' => ''
            ];
        }
        
        // Simple random selection - can be enhanced with weighted distribution
        $selected_type = $prize_types[array_rand($prize_types)];
        
        return [
            'prize' => $selected_type['name'] ?? null,
            'value' => $selected_type['value'] ?? '',
            'code' => $this->generate_prize_code($selected_type)
        ];
    }
    
    /**
     * Generate prize code based on prize type
     *
     * @param array $prize_type Prize type configuration
     * @return string Generated prize code
     */
    private function generate_prize_code(array $prize_type): string {
        $prefix = $prize_type['code_prefix'] ?? 'FS';
        $random_suffix = strtoupper(wp_generate_password(8, false));
        
        return $prefix . $random_suffix;
    }
    
    /**
     * Create prize posts from generated data
     *
     * @param array $prize_data Array of prize data with timestamps
     * @param int $competition_id Competition ID to associate prizes with
     * @return array Result with created prize IDs and any errors
     * @throws Exception If prize creation fails
     */
    public function create_prize_posts(array $prize_data, int $competition_id): array {
        $created = [];
        $failed = [];
        
        foreach ($prize_data as $prize) {
            try {
                $post_id = $this->create_single_prize_post($prize, $competition_id);
                if ($post_id) {
                    $created[] = $post_id;
                } else {
                    $failed[] = [
                        'prize' => $prize,
                        'error' => 'Failed to create post'
                    ];
                }
            } catch (Exception $e) {
                $failed[] = [
                    'prize' => $prize,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'created' => $created,
            'failed' => $failed
        ];
    }
    
    /**
     * Create a single prize post
     *
     * @param array $prize_data Prize data
     * @param int $competition_id Competition ID
     * @return int|false Post ID on success, false on failure
     * @throws Exception If post creation fails
     */
    private function create_single_prize_post(array $prize_data, int $competition_id): int|false {
        $post_data = [
            'post_title' => sprintf(
                'Prize %s - %s',
                $prize_data['unique_id'],
                $prize_data['date_formatted']
            ),
            'post_type' => self::PRIZE_POST_TYPE,
            'post_status' => 'publish',
            'meta_input' => [
                'date' => $prize_data['date_mysql'],
                'prize' => sanitize_text_field($prize_data['prize']),
                'value' => sanitize_text_field($prize_data['value']),
                'code' => sanitize_text_field($prize_data['code']),
                'claimed' => 'false',
                'competition_id' => $competition_id,
                'unique_id' => $prize_data['unique_id'],
                'original_timestamp' => $prize_data['timestamp'],
            ]
        ];
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create prize post: ' . $post_id->get_error_message());
        }
        
        return $post_id;
    }
    
    /**
     * Validate prize generation parameters
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param string $start_time Start time
     * @param string $end_time End time
     * @param int $num_dates Number of dates
     * @param array $prizes_data Prize data array
     * @return bool True if valid
     * @throws InvalidArgumentException If validation fails
     */
    public function validate_parameters(
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time,
        int $num_dates,
        array $prizes_data
    ): bool {
        
        // Validate date formats
        if (!$this->validate_date_format($start_date, 'Y-m-d')) {
            throw new InvalidArgumentException(__('Invalid start date format. Use Y-m-d.', 'britvic-promo'));
        }
        
        if (!$this->validate_date_format($end_date, 'Y-m-d')) {
            throw new InvalidArgumentException(__('Invalid end date format. Use Y-m-d.', 'britvic-promo'));
        }
        
        // Validate time formats
        if (!$this->validate_date_format($start_time, 'H:i')) {
            throw new InvalidArgumentException(__('Invalid start time format. Use H:i.', 'britvic-promo'));
        }
        
        if (!$this->validate_date_format($end_time, 'H:i')) {
            throw new InvalidArgumentException(__('Invalid end time format. Use H:i.', 'britvic-promo'));
        }
        
        // Validate number of dates
        if ($num_dates <= 0) {
            throw new InvalidArgumentException(__('Number of dates must be positive.', 'britvic-promo'));
        }
        
        if ($num_dates > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                sprintf(__('Maximum batch size is %d dates.', 'britvic-promo'), self::MAX_BATCH_SIZE)
            );
        }
        
        // Validate prizes data
        if (!is_array($prizes_data)) {
            throw new InvalidArgumentException(__('Prizes data must be an array.', 'britvic-promo'));
        }
        
        return true;
    }
    
    /**
     * Validate date/time format
     *
     * @param string $date Date string to validate
     * @param string $format Expected format
     * @return bool True if valid
     */
    private function validate_date_format(string $date, string $format): bool {
        $parsed = DateTime::createFromFormat($format, $date);
        return $parsed && $parsed->format($format) === $date;
    }
    
    /**
     * Export prize data to CSV format
     *
     * @param int $competition_id Competition ID
     * @return array CSV data and file information
     * @throws Exception If export fails
     */
    public function export_to_csv(int $competition_id): array {
        $prizes = $this->get_competition_prizes($competition_id);
        
        if (empty($prizes)) {
            throw new Exception(__('No prizes found for this competition.', 'britvic-promo'));
        }
        
        $csv_data = [];
        $headers = ['unique_id', 'date', 'prize', 'value', 'code', 'claimed'];
        
        foreach ($prizes as $prize) {
            $csv_data[] = [
                get_post_meta($prize->ID, 'unique_id', true),
                get_post_meta($prize->ID, 'date', true),
                get_post_meta($prize->ID, 'prize', true),
                get_post_meta($prize->ID, 'value', true),
                get_post_meta($prize->ID, 'code', true),
                get_post_meta($prize->ID, 'claimed', true),
            ];
        }
        
        $csv_handler = new BPM_CSV_Handler();
        $csv_content = $csv_handler->generate_csv_content($csv_data, $headers);
        
        $filename = sprintf('prizes_competition_%d_%s.csv', $competition_id, date('Y-m-d_H-i-s'));
        $file_info = $csv_handler->save_csv_file($csv_content, $filename);
        
        return $file_info;
    }
    
    /**
     * Get prizes for a specific competition
     *
     * @param int $competition_id Competition ID
     * @return array Array of prize post objects
     */
    private function get_competition_prizes(int $competition_id): array {
        $args = [
            'post_type' => self::PRIZE_POST_TYPE,
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ]
            ]
        ];
        
        return get_posts($args);
    }
    
    /**
     * Generate CSV backup file
     *
     * @param array $prize_data Prize data array
     * @param int $competition_id Competition ID
     * @return array File information
     */
    private function generate_csv_backup(array $prize_data, int $competition_id): array {
        $csv_data = [];
        $headers = ['unique_id', 'date', 'prize', 'value', 'code', 'claimed'];
        
        foreach ($prize_data as $prize) {
            $csv_data[] = [
                $prize['unique_id'],
                $prize['date_formatted'],
                $prize['prize'],
                $prize['value'],
                $prize['code'],
                $prize['claimed'] ? 'true' : 'false'
            ];
        }
        
        try {
            $csv_handler = new BPM_CSV_Handler();
            $csv_content = $csv_handler->generate_csv_content($csv_data, $headers);
            
            $filename = sprintf('prizes_backup_competition_%d_%s.csv', $competition_id, date('Y-m-d_H-i-s'));
            return $csv_handler->save_csv_file($csv_content, $filename);
            
        } catch (Exception $e) {
            // Log error but don't fail the main operation
            error_log('BPM: Failed to create CSV backup: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get available prize slots for a date range
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param string $start_time Start time
     * @param string $end_time End time
     * @return int Number of available time slots
     */
    public function get_available_slots(
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time
    ): int {
        
        $start_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$start_date} {$start_time}");
        $end_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$end_date} {$end_time}");
        
        if (!$start_datetime || !$end_datetime) {
            return 0;
        }
        
        $all_timestamps = $this->generate_all_possible_timestamps(
            $start_datetime,
            $end_datetime,
            $start_time,
            $end_time
        );
        
        return count($all_timestamps);
    }
}