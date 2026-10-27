<?php
/**
 * Page Manager Interface
 *
 * Defines the contract for page management functionality
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
 * Interface for page management functionality
 */
interface BPM_Page_Manager_Interface {
    
    /**
     * Toggle competition pages between live and closed states
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to perform ('publish' or 'close')
     * @return array Result array with success status and message
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If page toggle fails
     */
    public function toggle_competition_pages(int $competition_id, string $action): array;
    
    /**
     * Publish competition pages
     *
     * @param int $competition_id Competition ID
     * @return bool True on success
     * @throws Exception If publishing fails
     */
    public function publish_competition(int $competition_id): bool;
    
    /**
     * Close competition pages
     *
     * @param int $competition_id Competition ID
     * @return bool True on success
     * @throws Exception If closing fails
     */
    public function close_competition(int $competition_id): bool;
    
    /**
     * Get competition page configuration
     *
     * @param int $competition_id Competition ID
     * @return array Page configuration data
     * @throws Exception If competition not found
     */
    public function get_competition_pages(int $competition_id): array;
    
    /**
     * Set pages to private status
     *
     * @param array $page_ids Array of page IDs to set private
     * @return bool True on success
     * @throws Exception If setting pages private fails
     */
    public function set_pages_private(array $page_ids): bool;
    
    /**
     * Set page to published status
     *
     * @param int $page_id Page ID to publish
     * @return bool True on success
     * @throws Exception If publishing page fails
     */
    public function set_page_published(int $page_id): bool;
    
    /**
     * Get all pages for a competition theme
     *
     * @param string $theme_name Theme name (e.g., 'fruitshoot-green-king')
     * @return array Array of page objects
     */
    public function get_theme_pages(string $theme_name): array;
    
    /**
     * Validate page management action
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to validate
     * @return bool True if valid
     * @throws InvalidArgumentException If validation fails
     */
    public function validate_action(int $competition_id, string $action): bool;
    
    /**
     * Schedule page toggle action
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to schedule
     * @param int $timestamp When to execute
     * @return bool True if scheduled successfully
     * @throws Exception If scheduling fails
     */
    public function schedule_page_toggle(int $competition_id, string $action, int $timestamp): bool;
    
    /**
     * Cancel scheduled page toggle
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to cancel
     * @return bool True if cancelled successfully
     */
    public function cancel_scheduled_toggle(int $competition_id, string $action): bool;
}