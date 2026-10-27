<?php
/**
 * Prize Reseeder Class
 *
 * Handles reseeding of unclaimed prizes with comprehensive audit trails
 * Maintains original prize entry history for audit purposes
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
 * Prize Reseeder implementation
 */
class BPM_Prize_Reseeder {
    
    /**
     * Reseed unclaimed prizes for a competition
     *
     * @param int $competition_id Competition ID
     * @param array $reseed_options Reseeding configuration options
     * @return array Result with reseeded count and audit information
     * @throws Exception If reseeding fails
     */
    public function reseed_unclaimed_prizes(int $competition_id, array $reseed_options = []): array {
        try {
            // Get unclaimed prizes
            $unclaimed_prizes = $this->get_unclaimed_prizes($competition_id);
            
            if (empty($unclaimed_prizes)) {
                return [
                    'success' => true,
                    'reseeded_count' => 0,
                    'message' => __('No unclaimed prizes found to reseed', 'britvic-promo')
                ];
            }
            
            $reseeded_count = 0;
            $failed_count = 0;
            $errors = [];
            
            foreach ($unclaimed_prizes as $prize_post) {
                try {
                    $original_data = $this->capture_original_prize_data($prize_post);
                    $new_date = $this->generate_new_prize_date($competition_id, $reseed_options);
                    
                    if ($new_date) {
                        $this->update_prize_date($prize_post->ID, $new_date, $original_data);
                        $reseeded_count++;
                        
                        BPM_Audit_Logger::log(
                            $competition_id,
                            'prize_reseeded',
                            sprintf(
                                'Prize %s reseeded from %s to %s',
                                get_post_meta($prize_post->ID, 'code', true),
                                $original_data['original_date'],
                                $new_date
                            ),
                            'info',
                            [
                                'prize_id' => $prize_post->ID,
                                'original_data' => $original_data,
                                'new_date' => $new_date
                            ]
                        );
                    } else {
                        $failed_count++;
                        $errors[] = sprintf('No suitable date found for prize %s', $prize_post->ID);
                    }
                    
                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = sprintf('Failed to reseed prize %s: %s', $prize_post->ID, $e->getMessage());
                }
            }
            
            BPM_Audit_Logger::log(
                $competition_id,
                'prize_reseeding_completed',
                sprintf('Reseeding completed: %d reseeded, %d failed', $reseeded_count, $failed_count),
                $failed_count > 0 ? 'warning' : 'info'
            );
            
            return [
                'success' => true,
                'reseeded_count' => $reseeded_count,
                'failed_count' => $failed_count,
                'errors' => $errors,
                'total_processed' => count($unclaimed_prizes)
            ];
            
        } catch (Exception $e) {
            BPM_Audit_Logger::log(
                $competition_id,
                'prize_reseeding_failed',
                'Prize reseeding failed: ' . $e->getMessage(),
                'error'
            );
            
            throw $e;
        }
    }
    
    /**
     * Reseed a specific list of prize posts by ID. Used by the bulk-actions
     * UI on the Prizes admin page; loops through the supplied IDs and runs
     * the same per-prize reseed pipeline used by reseed_unclaimed_prizes().
     *
     * @param int[] $prize_post_ids   wp_posts IDs of the prizes to reseed.
     * @param int   $competition_id   Used for audit-log scoping.
     * @param array $reseed_options   Passed through to generate_new_prize_date().
     * @return array { reseeded_count, failed_count, errors[] }
     */
    public function reseed_prize_ids(array $prize_post_ids, int $competition_id, array $reseed_options = []): array {
        $reseeded_count = 0;
        $failed_count   = 0;
        $errors         = [];

        foreach ( $prize_post_ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( $post_id <= 0 ) {
                continue;
            }
            $prize_post = get_post( $post_id );
            if ( ! $prize_post || $prize_post->post_type !== 'prize' ) {
                $failed_count++;
                $errors[] = sprintf( 'Prize %d not found', $post_id );
                continue;
            }
            try {
                $original_data = $this->capture_original_prize_data( $prize_post );
                $new_date      = $this->generate_new_prize_date( $competition_id, $reseed_options );
                if ( $new_date ) {
                    $this->update_prize_date( $prize_post->ID, $new_date, $original_data );
                    $reseeded_count++;
                    if ( class_exists( 'BPM_Audit_Logger' ) ) {
                        BPM_Audit_Logger::log(
                            $competition_id,
                            'prize_reseeded',
                            sprintf( 'Prize %s reseeded to %s (bulk)', get_post_meta( $prize_post->ID, 'code', true ), $new_date ),
                            'info',
                            [ 'prize_id' => $prize_post->ID, 'new_date' => $new_date, 'source' => 'bulk_action' ]
                        );
                    }
                } else {
                    $failed_count++;
                    $errors[] = sprintf( 'No suitable date for prize %d', $prize_post->ID );
                }
            } catch ( Exception $e ) {
                $failed_count++;
                $errors[] = sprintf( 'Prize %d: %s', $prize_post->ID, $e->getMessage() );
            }
        }

        return [
            'reseeded_count' => $reseeded_count,
            'failed_count'   => $failed_count,
            'errors'         => $errors,
        ];
    }

    /**
     * Get unclaimed prizes for a competition
     *
     * @param int $competition_id Competition ID
     * @param array $filters Additional filters
     * @return array Array of unclaimed prize post objects
     */
    public function get_unclaimed_prizes(int $competition_id, array $filters = []): array {
        $meta_query = [
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
        ];
        
        // Add date range filter if specified
        if (!empty($filters['date_range'])) {
            $meta_query[] = [
                'key' => 'date',
                'value' => $filters['date_range'],
                'compare' => 'BETWEEN',
                'type' => 'DATETIME'
            ];
        }
        
        // Add prize type filter if specified
        if (!empty($filters['prize_type'])) {
            $meta_query[] = [
                'key' => 'prize',
                'value' => $filters['prize_type'],
                'compare' => '='
            ];
        }
        
        $args = [
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => $meta_query,
            'orderby' => 'meta_value',
            'meta_key' => 'date',
            'order' => 'ASC'
        ];
        
        return get_posts($args);
    }
    
    /**
     * Capture original prize data for audit trail
     *
     * @param WP_Post $prize_post Prize post object
     * @return array Original prize data
     */
    private function capture_original_prize_data(WP_Post $prize_post): array {
        return [
            'prize_id' => $prize_post->ID,
            'original_date' => get_post_meta($prize_post->ID, 'date', true),
            'original_timestamp' => get_post_meta($prize_post->ID, 'original_timestamp', true),
            'code' => get_post_meta($prize_post->ID, 'code', true),
            'prize' => get_post_meta($prize_post->ID, 'prize', true),
            'value' => get_post_meta($prize_post->ID, 'value', true),
            'pin' => get_post_meta($prize_post->ID, 'pin', true),
            'capture_timestamp' => current_time('mysql', true)
        ];
    }
    
    /**
     * Generate new prize date within competition parameters
     *
     * @param int $competition_id Competition ID
     * @param array $options Reseeding options
     * @return string|null New date in MySQL format or null if no suitable date found
     */
    private function generate_new_prize_date(int $competition_id, array $options): ?string {
        // Get competition details
        global $wpdb;
        $competition = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bpm_competitions WHERE id = %d",
                $competition_id
            ),
            ARRAY_A
        );
        
        if (!$competition) {
            return null;
        }
        
        // Use competition end date or default range
        $start_date = $options['start_date'] ?? date('Y-m-d');
        $end_date = $options['end_date'] ?? ($competition['end_date'] ? date('Y-m-d', strtotime($competition['end_date'])) : date('Y-m-d', strtotime('+30 days')));
        $start_time = $options['start_time'] ?? '00:00';
        $end_time = $options['end_time'] ?? '23:59';
        
        // Generate available timestamps
        $prize_generator = new BPM_Prize_Generator();
        
        try {
            $available_slots = $prize_generator->get_available_slots($start_date, $end_date, $start_time, $end_time);
            
            if ($available_slots > 0) {
                $timestamps = $prize_generator->generate_random_timestamps(
                    $start_date,
                    $end_date,
                    $start_time,
                    $end_time,
                    1
                );
                
                return date('Y-m-d H:i:s', $timestamps[0]);
            }
        } catch (Exception $e) {
            error_log('BPM Prize Reseeder: Failed to generate new date - ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Update prize date and maintain audit trail
     *
     * @param int $prize_id Prize post ID
     * @param string $new_date New date in MySQL format
     * @param array $original_data Original prize data for audit
     * @return bool True on success
     */
    private function update_prize_date(int $prize_id, string $new_date, array $original_data): bool {
        // Store original data in audit meta
        $audit_trail = get_post_meta($prize_id, 'audit_trail', true) ?: [];
        $audit_trail[] = [
            'action' => 'reseeded',
            'original_date' => $original_data['original_date'],
            'new_date' => $new_date,
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id()
        ];
        
        // Single source of truth for the write — propagates the new DATETIME
        // into every alias location (postmeta date / win_date_auto /
        // win_date_from_file / prize_date, mapped_data JSON, and
        // bpm_prize_entries.date_available). Previously this block missed
        // win_date_from_file which caused the admin date sort to be wrong.
        if ( class_exists( 'BPM_Prize_Sync' ) ) {
            BPM_Prize_Sync::write_date( $prize_id, $new_date );
        } else {
            // Defensive fallback — should never hit in practice.
            update_post_meta( $prize_id, 'date', $new_date );
        }
        
        // Update reseeding metadata
        update_post_meta($prize_id, 'reseeded', 'true');
        update_post_meta($prize_id, 'reseeded_count', (int) get_post_meta($prize_id, 'reseeded_count', true) + 1);
        update_post_meta($prize_id, 'audit_trail', $audit_trail);
        update_post_meta($prize_id, 'last_reseeded', current_time('mysql', true));
        
        error_log("BPM Prize Reseeder: Updated post meta date for prize {$prize_id} using key '{$current_date_key}' to {$new_date}");
        
        // Format date for UK display in logs
        $uk_formatted_date = date('d-m-Y g:i A', strtotime($new_date));
        error_log("BPM Prize Reseeder: UK formatted date: {$uk_formatted_date}");
        
        // Also update the wp_bpm_prize_entries table if the entry exists
        global $wpdb;
        $unique_id = get_post_meta($prize_id, 'unique_id', true);
        $code = get_post_meta($prize_id, 'code', true);
        
        if ($unique_id) {
            // Update by unique_id first
            $updated = $wpdb->update(
                $wpdb->prefix . 'bpm_prize_entries',
                [
                    'date_available' => $new_date,
                    'reseeded' => 1,
                    'reseeded_count' => $wpdb->get_var($wpdb->prepare(
                        "SELECT reseeded_count FROM {$wpdb->prefix}bpm_prize_entries WHERE unique_id = %s",
                        $unique_id
                    )) + 1,
                    'last_reseeded' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                [
                    'unique_id' => $unique_id
                ],
                ['%s', '%d', '%d', '%s', '%s'],
                ['%s']
            );
            
            // If no rows updated by unique_id and we have a code, try updating by code
            if ($updated === 0 && $code) {
                $wpdb->update(
                    $wpdb->prefix . 'bpm_prize_entries',
                    [
                        'date_available' => $new_date,
                        'reseeded' => 1,
                        'reseeded_count' => $wpdb->get_var($wpdb->prepare(
                            "SELECT reseeded_count FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s",
                            $code
                        )) + 1,
                        'last_reseeded' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    [
                        'code' => $code
                    ],
                    ['%s', '%d', '%d', '%s', '%s'],
                    ['%s']
                );
            }
            
            error_log("BPM Prize Reseeder: Updated prize entry date for unique_id {$unique_id} to {$new_date}");
        }
        
        // Update post title to reflect reseeding
        wp_update_post([
            'ID' => $prize_id,
            'post_title' => sprintf(
                'Prize %s - %s (Reseeded)',
                $code ?: 'Unknown',
                date('d/m/Y H:i', strtotime($new_date))
            )
        ]);
        
        return true;
    }
    
    /**
     * Format date to UK format for display (dd-mm-yyyy hh:mm AM/PM)
     * @param string $date_string The date string to format
     * @return string Formatted date or original string if invalid
     */
    private function format_uk_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return $date_string; // Return original if can't parse
        }
        
        return date('d-m-Y g:i A', $timestamp);
    }
    
    /**
     * Get prize reseeding history
     *
     * @param int $prize_id Prize post ID
     * @return array Reseeding history with UK formatted dates
     */
    public function get_prize_history(int $prize_id): array {
        $audit_trail = get_post_meta($prize_id, 'audit_trail', true) ?: [];
        $reseeded_count = (int) get_post_meta($prize_id, 'reseeded_count', true);
        $last_reseeded = get_post_meta($prize_id, 'last_reseeded', true);
        
        // Format dates in audit trail for display
        $formatted_audit_trail = [];
        foreach ($audit_trail as $entry) {
            $formatted_entry = $entry;
            
            // Format timestamp if present
            if (!empty($entry['timestamp'])) {
                $formatted_entry['timestamp'] = $this->format_uk_date($entry['timestamp']);
            }
            
            // Format original_date if present
            if (!empty($entry['original_date'])) {
                $formatted_entry['original_date'] = $this->format_uk_date($entry['original_date']);
            }
            
            // Format new_date if present
            if (!empty($entry['new_date'])) {
                $formatted_entry['new_date'] = $this->format_uk_date($entry['new_date']);
            }
            
            $formatted_audit_trail[] = $formatted_entry;
        }
        
        return [
            'prize_id' => $prize_id,
            'reseeded_count' => $reseeded_count,
            'last_reseeded' => $this->format_uk_date($last_reseeded),
            'audit_trail' => $formatted_audit_trail,
            'is_reseeded' => get_post_meta($prize_id, 'reseeded', true) === 'true'
        ];
    }
    
    /**
     * Get reseeding statistics for a competition
     *
     * @param int $competition_id Competition ID
     * @return array Reseeding statistics
     */
    public function get_reseeding_stats(int $competition_id): array {
        $all_prizes = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);
        
        $reseeded_prizes = get_posts([
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
                    'key' => 'reseeded',
                    'value' => 'true',
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);
        
        $unclaimed_prizes = $this->get_unclaimed_prizes($competition_id);
        
        return [
            'total_prizes' => count($all_prizes),
            'reseeded_prizes' => count($reseeded_prizes),
            'unclaimed_prizes' => count($unclaimed_prizes),
            'reseeding_percentage' => count($all_prizes) > 0 ? round((count($reseeded_prizes) / count($all_prizes)) * 100, 2) : 0
        ];
    }
    
    /**
     * Bulk reseed prizes from uploaded CSV
     *
     * @param array $file_data Uploaded CSV file data
     * @param int $competition_id Competition ID
     * @param array $options Reseeding options
     * @return array Result with processing statistics
     */
    public function bulk_reseed_from_csv(array $file_data, int $competition_id, array $options = []): array {
        $csv_handler = new BPM_CSV_Handler();
        
        // Import and validate CSV
        $import_result = $csv_handler->import_prizes($file_data, $competition_id);
        
        if ($import_result['success_count'] > 0) {
            BPM_Audit_Logger::log(
                $competition_id,
                'bulk_reseed_csv',
                sprintf('Bulk reseeded %d prizes from CSV import', $import_result['success_count']),
                'info',
                $import_result
            );
        }
        
        return $import_result;
    }
}