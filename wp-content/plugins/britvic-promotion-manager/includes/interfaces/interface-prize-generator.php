<?php
/**
 * Prize Generator Interface
 *
 * Defines the contract for prize generation functionality
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
 * Interface for prize generation functionality
 */
interface BPM_Prize_Generator_Interface {
    
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
    ): array;
    
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
    ): array;
    
    /**
     * Create prize posts from generated data
     *
     * @param array $prize_data Array of prize data with timestamps
     * @param int $competition_id Competition ID to associate prizes with
     * @return array Result with created prize IDs and any errors
     * @throws Exception If prize creation fails
     */
    public function create_prize_posts(array $prize_data, int $competition_id): array;
    
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
    ): bool;
    
    /**
     * Export prize data to CSV format
     *
     * @param int $competition_id Competition ID
     * @return array CSV data and file information
     * @throws Exception If export fails
     */
    public function export_to_csv(int $competition_id): array;
    
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
    ): int;
}