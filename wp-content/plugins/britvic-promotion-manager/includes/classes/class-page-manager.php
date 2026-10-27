<?php
/**
 * Page Manager Class
 *
 * Handles automated page publishing/unpublishing for competitions
 * Replaces the manual bash script with WordPress native functionality
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
 * Page Manager implementation
 */
class BPM_Page_Manager implements BPM_Page_Manager_Interface {
    
    /**
     * Valid actions for page management
     */
    private const VALID_ACTIONS = ['publish', 'close'];
    
    /**
     * Page statuses
     */
    private const STATUS_PUBLISHED = 'publish';
    private const STATUS_PRIVATE = 'private';
    private const STATUS_DRAFT = 'draft';
    
    /**
     * Toggle competition pages between live and closed states
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to perform ('publish' or 'close')
     * @return array Result array with success status and message
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If page toggle fails
     */
    public function toggle_competition_pages(int $competition_id, string $action): array {
        try {
            // Validate parameters
            $this->validate_action($competition_id, $action);
            
            // Get competition configuration
            $competition = $this->get_competition_pages($competition_id);
            
            $result = match($action) {
                'publish' => $this->publish_competition($competition_id),
                'close' => $this->close_competition($competition_id),
                default => throw new InvalidArgumentException('Invalid action specified')
            };
            
            if ($result) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'page_toggle_' . $action,
                    sprintf('Pages %s for competition %s', $action . 'ed', $competition['name'] ?? 'Unknown')
                );
                
                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Competition pages %s successfully.', 'britvic-promo'),
                        $action . 'ed'
                    ),
                    'action' => $action,
                    'competition_id' => $competition_id
                ];
            } else {
                throw new Exception(__('Failed to toggle pages', 'britvic-promo'));
            }
            
        } catch (Exception $e) {
            BPM_Audit_Logger::log(
                $competition_id,
                'page_toggle_failed',
                sprintf('Failed to %s competition pages: %s', $action, $e->getMessage()),
                'error'
            );
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'action' => $action,
                'competition_id' => $competition_id
            ];
        }
    }
    
    /**
     * Publish competition pages
     *
     * @param int $competition_id Competition ID
     * @return bool True on success
     * @throws Exception If publishing fails
     */
    public function publish_competition(int $competition_id): bool {
        $competition = $this->get_competition_pages($competition_id);
        
        // Step 1: Set all published pages to private
        $this->set_all_competition_pages_private();
        
        $pages_to_publish = [];

        // 1. Add terms page if it exists
        if (!empty($competition['terms_page_id'])) {
            $pages_to_publish[] = (int) $competition['terms_page_id'];
        }

        // 2. Add pages from theme config
        if (class_exists('BPM_Theme_Manager')) {
            $theme_id = BPM_Theme_Manager::get_competition_theme($competition_id);
            if ($theme_id) {
                $theme_config = BPM_Theme_Manager::get_theme($theme_id);

                // From theme 'pages' array
                if ($theme_config && isset($theme_config['pages']) && is_array($theme_config['pages'])) {
                    foreach ($theme_config['pages'] as $page_type => $template_path) {
                        $pages_with_template = get_posts([
                            'post_type' => 'page',
                            'posts_per_page' => -1,
                            'post_status' => ['private', 'draft'],
                            'meta_query' => [['key' => '_wp_page_template', 'value' => $template_path]],
                            'fields' => 'ids'
                        ]);
                        if($pages_with_template){
                            $pages_to_publish = array_merge($pages_to_publish, $pages_with_template);
                        }
                    }
                }

                // From theme 'redirect_settings' for 'thanks' page
                if ($theme_config && isset($theme_config['redirect_settings']['after_claim'])) {
                    $after_claim_slug = trim($theme_config['redirect_settings']['after_claim'], '/');
                    if ($after_claim_slug) {
                        $thanks_pages = get_posts([
                            'post_type' => 'page',
                            'name' => $after_claim_slug,
                            'post_status' => ['private', 'draft', 'publish'],
                            'posts_per_page' => 1,
                            'fields' => 'ids'
                        ]);
                        if (!empty($thanks_pages)) {
                            $pages_to_publish[] = $thanks_pages[0];
                        }
                    }
                }
            }
        }
    
        $closed_page_id = !empty($competition['closed_page_id']) ? (int)$competition['closed_page_id'] : null;

        $unique_pages = array_unique($pages_to_publish);
        foreach ($unique_pages as $page_id) {
            if ($page_id != $closed_page_id) {
                $this->set_page_published((int)$page_id);
            }
        }

        // Step 2: Publish the designated live page (age-gate)
        $live_page_id = null;
        if (!empty($competition['live_page_id'])) {
            $live_page_id = (int) $competition['live_page_id'];
            $this->set_page_published($live_page_id);
        } else {
            // Fallback...
            $live_page = $this->find_theme_entry_page();
            if ($live_page) {
                $live_page_id = $live_page->ID;
                $this->set_page_published($live_page_id);
            } else {
                throw new Exception(__('No live page found to publish', 'britvic-promo'));
            }
        }
        
        // Step 3: Set the live page as the front page
        if ($live_page_id) {
            $this->set_page_as_front_page($live_page_id);
        }
        
        // Step 4: Update competition status
        $this->update_competition_status($competition_id, 'active');
        
        return true;
    }
    
    /**
     * Close competition pages
     *
     * @param int $competition_id Competition ID
     * @return bool True on success
     * @throws Exception If closing fails
     */
    public function close_competition(int $competition_id): bool {
        $competition = $this->get_competition_pages($competition_id);
        
        // Step 1: Set all published pages to private
        $this->set_all_competition_pages_private();
        
        // Step 2: Publish the designated closed page and set as front page
        $closed_page_id = null;
        if (!empty($competition['closed_page_id'])) {
            $closed_page_id = (int) $competition['closed_page_id'];
            $this->set_page_published($closed_page_id);
        } else {
            // Fallback: find and publish the closed page for the current theme
            $closed_page = $this->find_theme_closed_page();
            if ($closed_page) {
                $closed_page_id = $closed_page->ID;
                $this->set_page_published($closed_page_id);
            } else {
                throw new Exception(__('No closed page found to publish', 'britvic-promo'));
            }
        }
        
        // Step 3: Set the closed page as the front page
        if ($closed_page_id) {
            $this->set_page_as_front_page($closed_page_id);
        }
        
        // Step 4: Update competition status
        $this->update_competition_status($competition_id, 'closed');
        
        return true;
    }
    
    /**
     * Get competition page configuration
     *
     * @param int $competition_id Competition ID
     * @return array Page configuration data
     * @throws Exception If competition not found
     */
    public function get_competition_pages(int $competition_id): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bpm_competitions';
        $competition = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $competition_id),
            ARRAY_A
        );
        
        if (!$competition) {
            throw new Exception(__('Competition not found', 'britvic-promo'));
        }
        
        return $competition;
    }
    
    /**
     * Set pages to private status (equivalent to bash script making pages private)
     *
     * @param array $page_ids Array of page IDs to set private
     * @return bool True on success
     * @throws Exception If setting pages private fails
     */
    public function set_pages_private(array $page_ids): bool {
        foreach ($page_ids as $page_id) {
            $result = wp_update_post([
                'ID' => (int) $page_id,
                'post_status' => self::STATUS_PRIVATE
            ], true);
            
            if (is_wp_error($result)) {
                throw new Exception(
                    sprintf(__('Failed to set page %d private: %s', 'britvic-promo'), $page_id, $result->get_error_message())
                );
            }
        }
        
        return true;
    }
    
    /**
     * Set all competition-related pages to private
     *
     * @return bool True on success
     */
    private function set_all_competition_pages_private(): bool {
        // Get all currently published pages
        $published_pages = get_posts([
            'post_type' => 'page',
            'post_status' => self::STATUS_PUBLISHED,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        if (!empty($published_pages)) {
            $this->set_pages_private($published_pages);
        }
        
        return true;
    }
    
    /**
     * Set page to published status
     *
     * @param int $page_id Page ID to publish
     * @return bool True on success
     * @throws Exception If publishing page fails
     */
    public function set_page_published(int $page_id): bool {
        $result = wp_update_post([
            'ID' => $page_id,
            'post_status' => self::STATUS_PUBLISHED
        ], true);
        
        if (is_wp_error($result)) {
            throw new Exception(
                sprintf(__('Failed to publish page %d: %s', 'britvic-promo'), $page_id, $result->get_error_message())
            );
        }
        
        return true;
    }
    
    /**
     * Set page as front page
     *
     * @param int $page_id Page ID to set as front page
     * @return bool True on success
     * @throws Exception If setting front page fails
     */
    public function set_page_as_front_page(int $page_id): bool {
        // Verify the page exists and is published
        $page = get_post($page_id);
        if (!$page || $page->post_status !== 'publish') {
            throw new Exception(
                sprintf(__('Cannot set page %d as front page: page must be published', 'britvic-promo'), $page_id)
            );
        }
        
        // Set the reading settings to use a static front page
        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);
        
        // Clear any posts page setting to avoid conflicts
        update_option('page_for_posts', 0);
        
        return true;
    }
    
    /**
     * Get all pages for a competition theme
     *
     * @param string $theme_name Theme name (e.g., 'fruitshoot-green-king')
     * @return array Array of page objects
     */
    public function get_theme_pages(string $theme_name): array {
        // This would need to be enhanced based on how you identify theme-specific pages
        // For now, returning all pages - you might need to add custom fields or taxonomy
        
        return get_posts([
            'post_type' => 'page',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'private', 'draft']
        ]);
    }
    
    /**
     * Find the entry page for current theme
     *
     * @return WP_Post|null Entry page object or null if not found
     */
    private function find_theme_entry_page(): ?WP_Post {
        // Look for page with 'entry' in the slug or template
        $entry_pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            'post_status' => ['private', 'draft'],
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wp_page_template',
                    'value' => 'page-entry.php',
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!empty($entry_pages)) {
            return $entry_pages[0];
        }
        
        // Fallback: look by post slug
        $entry_page = get_page_by_path('entry');
        if ($entry_page) {
            return $entry_page;
        }
        
        return null;
    }
    
    /**
     * Find the closed page for current theme
     *
     * @return WP_Post|null Closed page object or null if not found
     */
    private function find_theme_closed_page(): ?WP_Post {
        // Look for page with 'closed' in the slug or template
        $closed_pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            'post_status' => ['private', 'draft'],
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wp_page_template',
                    'value' => 'page-closed.php',
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!empty($closed_pages)) {
            return $closed_pages[0];
        }
        
        // Fallback: look by post slug
        $closed_page = get_page_by_path('closed');
        if ($closed_page) {
            return $closed_page;
        }
        
        return null;
    }
    
    /**
     * Validate page management action
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to validate
     * @return bool True if valid
     * @throws InvalidArgumentException If validation fails
     */
    public function validate_action(int $competition_id, string $action): bool {
        if ($competition_id <= 0) {
            throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
        }
        
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf(__('Invalid action. Allowed actions: %s', 'britvic-promo'), implode(', ', self::VALID_ACTIONS))
            );
        }
        
        // Check if competition exists
        $this->get_competition_pages($competition_id);
        
        return true;
    }
    
    /**
     * Schedule page toggle action
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to schedule
     * @param int $timestamp When to execute
     * @return bool True if scheduled successfully
     * @throws Exception If scheduling fails
     */
    public function schedule_page_toggle(int $competition_id, string $action, int $timestamp): bool {
        $this->validate_action($competition_id, $action);
        
        $hook_name = match($action) {
            'publish' => 'bpm_publish_competition',
            'close' => 'bpm_close_competition',
            default => throw new InvalidArgumentException('Invalid action for scheduling')
        };
        
        // Clear any existing scheduled event for this competition and action
        $this->cancel_scheduled_toggle($competition_id, $action);
        
        // Schedule new event
        $scheduled = wp_schedule_single_event($timestamp, $hook_name, [$competition_id]);
        
        if (!$scheduled) {
            throw new Exception(__('Failed to schedule page toggle', 'britvic-promo'));
        }
        
        BPM_Audit_Logger::log(
            $competition_id,
            'page_toggle_scheduled',
            sprintf('Scheduled %s action for %s', $action, date('Y-m-d H:i:s', $timestamp))
        );
        
        return true;
    }
    
    /**
     * Cancel scheduled page toggle
     *
     * @param int $competition_id Competition ID
     * @param string $action Action to cancel
     * @return bool True if cancelled successfully
     */
    public function cancel_scheduled_toggle(int $competition_id, string $action): bool {
        $hook_name = match($action) {
            'publish' => 'bpm_publish_competition',
            'close' => 'bpm_close_competition',
            default => null
        };
        
        if (!$hook_name) {
            return false;
        }
        
        $timestamp = wp_next_scheduled($hook_name, [$competition_id]);
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook_name, [$competition_id]);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'page_toggle_cancelled',
                sprintf('Cancelled scheduled %s action', $action)
            );
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Update competition status in database
     *
     * @param int $competition_id Competition ID
     * @param string $status New status
     * @return bool True on success
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
     * Get page management status for admin display
     *
     * @param int $competition_id Competition ID
     * @return array Status information
     */
    public function get_page_status(int $competition_id): array {
        try {
            $competition = $this->get_competition_pages($competition_id);
            
            $live_page_status = null;
            $closed_page_status = null;
            
            if ($competition['live_page_id']) {
                $live_page = get_post($competition['live_page_id']);
                $live_page_status = [
                    'id' => $live_page->ID,
                    'title' => $live_page->post_title,
                    'status' => $live_page->post_status,
                    'url' => get_permalink($live_page->ID)
                ];
            }
            
            if ($competition['closed_page_id']) {
                $closed_page = get_post($competition['closed_page_id']);
                $closed_page_status = [
                    'id' => $closed_page->ID,
                    'title' => $closed_page->post_title,
                    'status' => $closed_page->post_status,
                    'url' => get_permalink($closed_page->ID)
                ];
            }
            
            // Check for scheduled events
            $publish_scheduled = wp_next_scheduled('bpm_publish_competition', [$competition_id]);
            $close_scheduled = wp_next_scheduled('bpm_close_competition', [$competition_id]);
            
            // Get current front page information
            $front_page_id = get_option('page_on_front');
            $show_on_front = get_option('show_on_front');
            $front_page_status = null;
            
            if ($show_on_front === 'page' && $front_page_id) {
                $front_page = get_post($front_page_id);
                if ($front_page) {
                    $front_page_status = [
                        'id' => $front_page->ID,
                        'title' => $front_page->post_title,
                        'status' => $front_page->post_status,
                        'url' => get_permalink($front_page->ID),
                        'is_competition_page' => ($front_page->ID == $competition['live_page_id'] || $front_page->ID == $competition['closed_page_id'])
                    ];
                }
            }
            
            return [
                'competition_status' => $competition['status'],
                'live_page' => $live_page_status,
                'closed_page' => $closed_page_status,
                'front_page' => $front_page_status,
                'show_on_front' => $show_on_front,
                'scheduled_publish' => $publish_scheduled ? date('Y-m-d H:i:s', $publish_scheduled) : null,
                'scheduled_close' => $close_scheduled ? date('Y-m-d H:i:s', $close_scheduled) : null,
                'currently_published' => $this->get_currently_published_pages()
            ];
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get currently published pages
     *
     * @return array Array of published page information
     */
    private function get_currently_published_pages(): array {
        $published_pages = get_posts([
            'post_type' => 'page',
            'post_status' => self::STATUS_PUBLISHED,
            'posts_per_page' => -1
        ]);
        
        return array_map(function($page) {
            return [
                'id' => $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'url' => get_permalink($page->ID)
            ];
        }, $published_pages);
    }
}