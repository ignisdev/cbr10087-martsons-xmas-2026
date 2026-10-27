<?php
/**
 * Prizes Admin Page
 *
 * Generate and manage prizes
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
 * Format date for UK display (dd-mm-yyyy hh:mm AM/PM)
 * @param string $date_string The date string to format
 * @return string Formatted date or original string if invalid
 */
function bpm_format_uk_date($date_string) {
    if (empty($date_string)) {
        return '';
    }
    
    // Try to parse the date
    $timestamp = strtotime($date_string);
    if ($timestamp === false) {
        return esc_html($date_string); // Return original if can't parse
    }
    
    return date('d-m-Y g:i A', $timestamp);
}

$selected_competition = isset($_GET['competition_id']) ? (int) $_GET['competition_id'] : 0;

// Get competitions for dropdown
global $wpdb;
$competitions = [];

// Check if table exists before querying
$table_name = $wpdb->prefix . 'bpm_competitions';
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $competitions = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}bpm_competitions ORDER BY created_at DESC",
        ARRAY_A
    );
    
    if ($wpdb->last_error) {
        error_log('BPM Database Error in prizes.php: ' . $wpdb->last_error);
    }
}

// Auto-select the only competition when one exists and none chosen.
if ( ! $selected_competition && is_array( $competitions ) && count( $competitions ) === 1 ) {
    $only = reset( $competitions );
    $selected_competition = is_array( $only ) ? (int) $only['id'] : (int) $only->id;
}

// Handle prize window settings update
if (isset($_POST['save_prize_window']) && wp_verify_nonce($_POST['bpm_nonce'], 'save_prize_window')) {
    $competition_id = (int) $_POST['competition_id'];
    $prize_window_minutes = (int) $_POST['prize_window_minutes'];
    
    if ($competition_id && $prize_window_minutes > 0 && $prize_window_minutes <= 120) {
        update_option("bpm_prize_window_{$competition_id}", $prize_window_minutes);
        echo '<div class="notice notice-success"><p>' . 
            sprintf(__('Prize window updated to %d minutes', 'britvic-promo'), $prize_window_minutes) . 
            '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Invalid prize window value. Must be between 1 and 120 minutes.', 'britvic-promo') . '</p></div>';
    }
}

/**
 * Analyze available columns from imported prizes
 */
function analyze_available_columns($prizes, $competition_id) {
    $available_columns = [];
    
    // Always include core columns in specified order (dynamic code columns will be added later)
    // Function to determine appropriate column labels based on data source
    function get_contextual_column_labels($prizes) {
        $labels = [
            'unique_id' => __('ID', 'britvic-promo'),
            'prize' => __('Prize', 'britvic-promo'),
            'value' => __('Value', 'britvic-promo'),
            'date' => __('Date', 'britvic-promo'),
            'claimed' => __('Claimed', 'britvic-promo'),
            'status' => __('Status', 'britvic-promo'),
            'winner_id' => __('Winner ID', 'britvic-promo'),
            'winner_email' => __('Winner Email', 'britvic-promo'),
            'actions' => __('Actions', 'britvic-promo')
        ];
        
        // Check a sample of prizes to determine data sources
        if (!empty($prizes)) {
            $sample_size = min(5, count($prizes));
            $has_date_from_file = false;
            $has_date_from_generator = false;
            $has_prize_from_file = false;
            $has_prize_from_distribution = false;
            
            for ($i = 0; $i < $sample_size; $i++) {
                $mapped_data_json = get_post_meta($prizes[$i]->ID, 'mapped_data', true);
                $mapped_data = $mapped_data_json ? json_decode($mapped_data_json, true) : [];
                
                if (isset($mapped_data['win_date_from_file'])) {
                    $has_date_from_file = true;
                }
                if (isset($mapped_data['win_date_auto'])) {
                    $has_date_from_generator = true;
                }
                if (isset($mapped_data['prize_name_from_file'])) {
                    $has_prize_from_file = true;
                }
                if (isset($mapped_data['prize_name_from_distribution'])) {
                    $has_prize_from_distribution = true;
                }
            }
            
            // Determine appropriate labels based on data sources found
            if ($has_date_from_file || $has_date_from_generator) {
                $labels['date'] = __('Date', 'britvic-promo');
            }
            
            if ($has_prize_from_file || $has_prize_from_distribution) {
                $labels['prize'] = __('Prize Name', 'britvic-promo');
            }
        }
        
        return $labels;
    }
    
    // Get contextual labels for this competition's prizes
    $contextual_labels = get_contextual_column_labels($prizes);
    
    // Remove core 'prize', 'date', and 'value' columns - only use imported mapped columns.
    // `claimed` = slot-pool state from wp_bpm_prize_entries.claimed (1=claim form
    // submitted, 0=available). `status` = payment lifecycle from
    // wp_bpm_prize_entries.stripe_payout_status (paid/failed/cop_blocked/...).
    $core_columns = [
        'unique_id' => ['type' => 'meta', 'label' => $contextual_labels['unique_id'], 'editable' => false],
        // prize_type is sourced directly from wp_bpm_prize_entries.prize_type
        // (already SELECTed into $bpm_entry by the list-table query). Showing it
        // as a first-class column lets you confirm at a glance that the CSV
        // import wrote 'cash'/'merch' as expected — and immediately spot any
        // distribution-overwrite corruption (anything other than cash/merch).
        'prize_type' => ['type' => 'computed', 'label' => __('Prize Type', 'britvic-promo'), 'editable' => false],
        'claimed' => ['type' => 'computed', 'label' => $contextual_labels['claimed'], 'editable' => false],
        'status' => ['type' => 'computed', 'label' => $contextual_labels['status'], 'editable' => false],
        'winner_id' => ['type' => 'computed', 'label' => $contextual_labels['winner_id'], 'editable' => true],
        'winner_email' => ['type' => 'computed', 'label' => $contextual_labels['winner_email'], 'editable' => true],
        'actions' => ['type' => 'computed', 'label' => $contextual_labels['actions'], 'editable' => false]
    ];
    
    $available_columns = $core_columns;
    
    // Analyze mapped data columns from a sample of prizes
    $sample_size = min(5, count($prizes));
    $has_mapped_columns = false;
    
    for ($i = 0; $i < $sample_size; $i++) {
        if (isset($prizes[$i])) {
            $mapped_data_json = get_post_meta($prizes[$i]->ID, 'mapped_data', true);
            $mapped_data = $mapped_data_json ? json_decode($mapped_data_json, true) : [];
            
            if (!empty($mapped_data)) {
                $has_mapped_columns = true;
            }
            
            foreach ($mapped_data as $field_key => $field_value) {
                // Suppress any auto-imported field that would collide with the
                // new `claimed` core column (we now show the authoritative
                // claim state from wp_bpm_prize_entries, not from CSV).
                if ($field_key === 'claimed') {
                    continue;
                }
                if (!isset($available_columns[$field_key]) && !empty($field_value)) {
                    // Use the display name mapping for better labels
                    $display_name_mappings = [
                        'prize_name_from_distribution' => 'Prize',
                        'prize_name_from_file' => 'Prize', 
                        'win_date_auto' => 'Date',
                        'win_date_from_file' => 'Date'
                    ];
                    
                    // Special handling for code columns
                    if (preg_match('/^code_(\d+)$/', $field_key, $matches)) {
                        $code_number = $matches[1];
                        $label = "Code {$code_number}";
                    } else {
                        $label = $display_name_mappings[$field_key] ?? ucwords(str_replace('_', ' ', $field_key));
                    }
                    
                    $available_columns[$field_key] = [
                        'type' => 'mapped', 
                        'label' => $label, 
                        'editable' => true
                    ];
                }
            }
        }
    }
    
    // If we have imported prizes with mapped data, ensure core prize/date/value columns are available
    // for backwards compatibility when no mapped columns are detected
    if (!$has_mapped_columns && !empty($prizes)) {
        $available_columns['prize'] = ['type' => 'meta', 'label' => __('Prize Name', 'britvic-promo'), 'editable' => true];
        $available_columns['date'] = ['type' => 'meta', 'label' => __('Date', 'britvic-promo'), 'editable' => true];
        $available_columns['value'] = ['type' => 'meta', 'label' => __('Value', 'britvic-promo'), 'editable' => true];
    }
    
    return $available_columns;
}

/**
 * Get user column preferences with defaults
 */
function get_user_column_preferences($competition_id, $available_columns = []) {
    $user_id = get_current_user_id();
    $preferences = get_user_meta($user_id, "bpm_column_preferences_{$competition_id}", true);
    
    if (empty($preferences)) {
        // Default column order and visibility (hardcoded core columns only).
        // `claimed` sits between ID and the payout `status` so the slot-pool
        // truth + payment state are adjacent at a glance.
        $preferences = [
            'order' => ['unique_id', 'prize_type', 'claimed', 'status', 'winner_id', 'winner_email'],
            'visibility' => [
                'unique_id' => true,
                'prize_type' => true,
                'claimed' => true,
                'status' => true,
                'winner_id' => true,
                'winner_email' => true
            ]
        ];
    }
    
    // Dynamically add available mapped columns that aren't already in preferences
    if (!empty($available_columns)) {
        foreach ($available_columns as $column_key => $column_info) {
            // Skip if it's already in preferences or if it's a core column
            if (!isset($preferences['visibility'][$column_key])) {
                // Add mapped columns as visible by default
                if ($column_info['type'] === 'mapped') {
                    $preferences['visibility'][$column_key] = true;
                    
                    // Add to order - insert mapped columns in logical sequence
                    if (!in_array($column_key, $preferences['order'])) {
                        $insert_position = count($preferences['order']); // Default: add at end
                        
                        // Calculate position based on column type and current order
                        if (in_array($column_key, ['prize_name_from_distribution', 'prize_name_from_file'])) {
                            // Prize name goes right after unique_id
                            $insert_position = 1;
                        } elseif (in_array($column_key, ['win_date_auto', 'win_date_from_file'])) {
                            // Date goes after prize name (or after unique_id if no prize name)
                            $insert_position = 1;
                            foreach (['prize_name_from_distribution', 'prize_name_from_file'] as $prize_col) {
                                if (in_array($prize_col, $preferences['order'])) {
                                    $insert_position = array_search($prize_col, $preferences['order']) + 1;
                                    break;
                                }
                            }
                        } elseif (preg_match('/^code_(\d+)$/', $column_key, $matches)) {
                            // Code columns go after date/prize columns, in numerical order
                            $code_number = (int)$matches[1];
                            $insert_position = 1; // Start after unique_id
                            
                            // Move past prize and date columns
                            foreach (['prize_name_from_distribution', 'prize_name_from_file', 'win_date_auto', 'win_date_from_file'] as $col) {
                                if (in_array($col, $preferences['order'])) {
                                    $insert_position = max($insert_position, array_search($col, $preferences['order']) + 1);
                                }
                            }
                            
                            // Find position among existing code columns
                            for ($i = 1; $i < $code_number; $i++) {
                                $prev_code = "code_{$i}";
                                if (in_array($prev_code, $preferences['order'])) {
                                    $insert_position = max($insert_position, array_search($prev_code, $preferences['order']) + 1);
                                }
                            }
                        } elseif ($column_key === 'value') {
                            // Value goes after all code columns
                            $insert_position = 1;
                            foreach (['prize_name_from_distribution', 'prize_name_from_file', 'win_date_auto', 'win_date_from_file'] as $col) {
                                if (in_array($col, $preferences['order'])) {
                                    $insert_position = max($insert_position, array_search($col, $preferences['order']) + 1);
                                }
                            }
                            // Move past all code columns
                            for ($i = 1; $i <= 10; $i++) { // Check up to code_10
                                $code_col = "code_{$i}";
                                if (in_array($code_col, $preferences['order'])) {
                                    $insert_position = max($insert_position, array_search($code_col, $preferences['order']) + 1);
                                }
                            }
                        }
                        
                        array_splice($preferences['order'], $insert_position, 0, [$column_key]);
                    }
                }
            }
        }
    }
    
    return $preferences;
}

/**
 * Save user column preferences
 */
function save_user_column_preferences($competition_id, $preferences) {
    $user_id = get_current_user_id();
    return update_user_meta($user_id, "bpm_column_preferences_{$competition_id}", $preferences);
}

/**
 * Get column configuration with styling and user preferences
 */
function get_column_configuration($available_columns, $competition_id = null) {
    $column_config = [];
    $user_prefs = $competition_id ? get_user_column_preferences($competition_id, $available_columns) : null;
    
    // Apply user preferences if available
    $base_order = $user_prefs['order'] ?? ['unique_id', 'prize', 'value', 'date', 'status', 'winner_id', 'winner_email'];
    $column_visibility = $user_prefs['visibility'] ?? [];
    
    // Build column configuration
    
    // Build final column order: base columns with dynamic codes after prize + actions last
    $column_order = [];
    $dynamic_columns = [];
    
    // Collect dynamic columns
    foreach ($available_columns as $column_key => $column_info) {
        if (!in_array($column_key, $base_order) && $column_key !== 'actions') {
            $dynamic_columns[] = $column_key;
        }
        if (!isset($column_visibility[$column_key])) {
            $column_visibility[$column_key] = true; // New columns visible by default
        }
    }
    
    // Sort dynamic columns (code_1, code_2, etc. in numerical order)
    usort($dynamic_columns, function($a, $b) {
        // Put code columns in numerical order
        if (strpos($a, 'code_') === 0 && strpos($b, 'code_') === 0) {
            $num_a = intval(substr($a, 5));
            $num_b = intval(substr($b, 5));
            return $num_a - $num_b;
        }
        return strcmp($a, $b);
    });
    
    // Build final order: insert dynamic columns after prize
    foreach ($base_order as $column_key) {
        if (isset($available_columns[$column_key]) && $column_key !== 'actions') {
            $column_order[] = $column_key;
            
            // Insert dynamic columns right after prize
            if ($column_key === 'prize') {
                $column_order = array_merge($column_order, $dynamic_columns);
            }
        }
    }
    
    // Add actions at the very end
    if (isset($available_columns['actions'])) {
        $column_order[] = 'actions';
    }
    
    // Build table configuration from ordered columns
    
    foreach ($column_order as $column_key) {
        if (!isset($available_columns[$column_key])) {
            continue; // Skip missing columns
        }
        
        // Ensure visibility is set (default to true if not set)
        if (!isset($column_visibility[$column_key])) {
            $column_visibility[$column_key] = true;
        }
        
        if (!$column_visibility[$column_key]) {
            continue; // Skip hidden columns
        }
        $column_info = $available_columns[$column_key];
        $config = $column_info;
        
        // Add styling based on column type
        switch ($column_key) {
            case 'prize':
                $config['style'] = 'width: 150px;';
                break;
            case 'value':
                $config['style'] = 'width: 80px;';
                break;
            case 'date':
                $config['style'] = 'width: 120px;';
                break;
            case 'claimed':
                $config['style'] = 'width: 90px;';
                break;
            case 'prize_type':
                $config['style'] = 'width: 90px;';
                break;
            case 'status':
                $config['style'] = 'width: 120px;';
                break;
            case 'winner_id':
                $config['style'] = 'width: 100px;';
                $config['class'] = 'winner-field';
                break;
            case 'winner_email':
                $config['style'] = 'width: 180px;';
                $config['class'] = 'winner-field';
                break;
            case 'code_1':
            case 'code_2':
            case 'code_3':
            case 'code_4':
            case 'code_5':
            case 'code_6':
                $config['style'] = 'width: 120px;';
                break;
            case 'unique_id':
                $config['style'] = 'width: 100px;';
                break;
            case 'actions':
                $config['style'] = 'width: 120px;';
                $config['class'] = 'bpm-actions-column';
                break;
            default:
                $config['style'] = 'width: 120px;';
                break;
        }
        
        $column_config[$column_key] = $config;
    }
    
    return $column_config;
}

/**
 * Render column content based on column type
 */
function render_column_content($prize, $column_key, $column_info, $mapped_data, $is_claimed, $winner_id, $winner_email, $gravity_form_entry_id, $entry_id = 0, $bpm_entry = []) {
    switch ($column_key) {
        case 'prize':
            $prize_meta = get_post_meta($prize->ID, 'prize', true);
            $prize_name_meta = get_post_meta($prize->ID, 'prize_name', true);
            
            // Display logic: mapped prize names > prize_name meta > regular prize field
            if (!empty($mapped_data['prize_name_from_distribution'])) {
                return esc_html($mapped_data['prize_name_from_distribution']);
            } elseif (!empty($mapped_data['prize_name_from_file'])) {
                return esc_html($mapped_data['prize_name_from_file']);
            } elseif (!empty($prize_name_meta)) {
                return esc_html($prize_name_meta);
            } else {
                // Fallback to prize field, but show meaningful display for numeric values
                if (is_numeric($prize_meta)) {
                    return esc_html("Prize {$prize_meta}");
                } else {
                    return esc_html($prize_meta ?: 'Unknown Prize');
                }
            }
            
        case 'value':
            return esc_html(get_post_meta($prize->ID, 'value', true));
            
        case 'date':
            // Display logic: mapped date values > regular date field
            if (!empty($mapped_data['win_date_from_file'])) {
                return bpm_format_uk_date($mapped_data['win_date_from_file']);
            } elseif (!empty($mapped_data['win_date_auto'])) {
                return bpm_format_uk_date($mapped_data['win_date_auto']);
            } else {
                return bpm_format_uk_date(get_post_meta($prize->ID, 'date', true));
            }
            
        case 'claimed':
            // Yes/No badge sourced from wp_bpm_prize_entries.claimed when
            // available (authoritative slot-pool state); falls back to post-meta
            // for prizes that don't yet have a BPM entry row (rare).
            $bpm_claimed = isset($bpm_entry['claimed']) ? (int) $bpm_entry['claimed'] : ($is_claimed ? 1 : 0);
            ob_start();
            if ($bpm_claimed === 1): ?>
                <span class="bpm-status-claimed"><?php _e('Yes', 'britvic-promo'); ?></span>
                <?php if (get_post_meta($prize->ID, 'reseeded', true) === 'true'): ?>
                    <br><small class="bpm-status-reseeded"><?php _e('(Reseeded)', 'britvic-promo'); ?></small>
                <?php endif; ?>
            <?php else: ?>
                <span class="bpm-status-available"><?php _e('No', 'britvic-promo'); ?></span>
                <?php if (get_post_meta($prize->ID, 'reseeded', true) === 'true'): ?>
                    <br><small class="bpm-status-reseeded"><?php _e('(Reseeded)', 'britvic-promo'); ?></small>
                <?php endif; ?>
            <?php endif;
            return ob_get_clean();

        case 'prize_type':
            // Authoritative prize_type from wp_bpm_prize_entries.prize_type.
            // Only `cash` and `merch` are valid values; anything else is a
            // distribution-overwrite or import-mapping bug surfacing here as
            // an em-dash so it's visible at a glance.
            $entry_prize_type = isset($bpm_entry['prize_type']) ? strtolower((string) $bpm_entry['prize_type']) : '';
            switch ($entry_prize_type) {
                case 'cash':
                    return '<span class="bpm-prize-type-cash">' . esc_html__('Cash', 'britvic-promo') . '</span>';
                case 'merch':
                    return '<span class="bpm-prize-type-merch">' . esc_html__('Merch', 'britvic-promo') . '</span>';
                case '':
                    return '<span class="bpm-prize-type-unknown">—</span>';
                default:
                    return '<span class="bpm-prize-type-unknown" title="' . esc_attr__('Unexpected prize_type value — likely a CSV-import mapping issue.', 'britvic-promo') . '">' . esc_html($bpm_entry['prize_type']) . '</span>';
            }

        case 'status':
            // Payment lifecycle from wp_bpm_prize_entries.stripe_payout_status.
            // NULL + prize_type=merch → Manual (warehouse ships);
            // NULL + prize_type=cash → not yet started (claim still in flight).
            $payout_status = isset($bpm_entry['stripe_payout_status']) ? (string) $bpm_entry['stripe_payout_status'] : '';
            $prize_type    = isset($bpm_entry['prize_type']) ? (string) $bpm_entry['prize_type'] : '';
            $label = '';
            $css   = 'bpm-payout-unknown';
            if ($payout_status === '') {
                if ($prize_type === 'merch') {
                    $label = __('Manual', 'britvic-promo');
                    $css   = 'bpm-payout-manual';
                } elseif ($prize_type === 'cash') {
                    $label = '—';
                    $css   = 'bpm-payout-none';
                } else {
                    $label = '—';
                    $css   = 'bpm-payout-none';
                }
            } else {
                switch (strtolower($payout_status)) {
                    // Stripe-verbatim OutboundPayment statuses (preferred).
                    case 'processing':  $label = __('Processing', 'britvic-promo');  $css = 'bpm-payout-pending';   break;
                    case 'posted':      $label = __('Posted', 'britvic-promo');      $css = 'bpm-payout-paid';      break;
                    case 'returned':    $label = __('Returned', 'britvic-promo');    $css = 'bpm-payout-failed';    break;
                    case 'failed':      $label = __('Failed', 'britvic-promo');      $css = 'bpm-payout-failed';    break;
                    case 'cancelled':
                    case 'canceled':    $label = __('Cancelled', 'britvic-promo');   $css = 'bpm-payout-cancelled'; break;
                    // BPM-internal lifecycle states (pre-Stripe-call).
                    case 'pending':     $label = __('Pending', 'britvic-promo');     $css = 'bpm-payout-pending';   break;
                    case 'cop_blocked': $label = __('CoP Blocked', 'britvic-promo'); $css = 'bpm-payout-cop-blocked'; break;
                    // Legacy values from earlier code paths — kept so historical
                    // rows still render cleanly. New rows will land on
                    // processing / posted / returned / failed / cancelled.
                    case 'submitted':   $label = __('Submitted', 'britvic-promo');   $css = 'bpm-payout-submitted'; break;
                    case 'paid':        $label = __('Paid', 'britvic-promo');        $css = 'bpm-payout-paid';      break;
                    default:            $label = ucfirst($payout_status);            $css = 'bpm-payout-unknown';   break;
                }
            }
            return '<span class="' . esc_attr($css) . '">' . esc_html($label) . '</span>';
            
        case 'winner_id':
            ob_start();
            if ($gravity_form_entry_id): 
                // Get the winner form ID from competition settings
                $competition_id = get_post_meta($prize->ID, 'competition_id', true);
                $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
                $winner_form_id = $form_settings['winner_form']['form_id'] ?? '';
                ?>
                <span class="gf-entry-link">
                    <a href="<?php echo admin_url('admin.php?page=gf_entries&view=entry&id=' . $winner_form_id . '&lid=' . $gravity_form_entry_id); ?>" target="_blank">
                        #<?php echo esc_html($gravity_form_entry_id); ?>
                    </a>
                </span>
            <?php elseif ($winner_id): ?>
                <?php echo esc_html($winner_id); ?>
            <?php else: ?>
                <span class="bpm-no-winner">Click to add</span>
            <?php endif;
            return ob_get_clean();
            
        case 'winner_email':
            ob_start();
            if ($winner_email): ?>
                <span class="email-link">
                    <a href="mailto:<?php echo esc_attr($winner_email); ?>"><?php echo esc_html($winner_email); ?></a>
                </span>
            <?php else: ?>
                <span class="bpm-no-winner">Click to add</span>
            <?php endif;
            return ob_get_clean();
            
        case 'actions':
            ob_start(); ?>
            <div class="bpm-action-message" id="action-message-<?php echo $prize->ID; ?>"></div>
            <div class="bpm-action-buttons">
                <button type="button" class="bpm-icon-button edit-prize" data-prize-id="<?php echo $prize->ID; ?>" title="<?php echo $is_claimed ? __('Mark Available', 'britvic-promo') : __('Mark Claimed', 'britvic-promo'); ?>">
                    <span class="dashicons <?php echo $is_claimed ? 'dashicons-unlock' : 'dashicons-lock'; ?>"></span>
                </button>
                <?php if ($is_claimed): ?>
                    <button type="button" class="bpm-icon-button reset-claim" data-prize-id="<?php echo $prize->ID; ?>" title="<?php _e('Reset Claim', 'britvic-promo'); ?>">
                        <span class="dashicons dashicons-undo"></span>
                    </button>
                <?php endif; ?>
                <button type="button" class="bpm-icon-button view-history" data-prize-id="<?php echo $prize->ID; ?>" title="<?php _e('View History', 'britvic-promo'); ?>">
                    <span class="dashicons dashicons-clock"></span>
                </button>
                <button type="button" class="bpm-icon-button delete-prize" data-prize-id="<?php echo $prize->ID; ?>" title="<?php _e('Delete this prize permanently', 'britvic-promo'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <?php
            return ob_get_clean();
            
        case 'unique_id':
            if ($entry_id) {
                return esc_html($entry_id);
            }
            if (isset($mapped_data['unique_id'])) {
                return esc_html($mapped_data['unique_id']);
            }
            return esc_html(get_post_meta($prize->ID, 'unique_id', true));
            
        default:
            // Handle mapped data columns (code_1 through code_6, etc.)
            if (isset($mapped_data[$column_key])) {
                // Check if this is a date-related column and format accordingly
                if (in_array($column_key, ['win_date_auto', 'win_date_from_file', 'prize_date', 'date_available'])) {
                    return bpm_format_uk_date($mapped_data[$column_key]);
                }
                return esc_html($mapped_data[$column_key]);
            }
            return '';
    }
}

// Handle CSV import
if (isset($_POST['import_csv']) && wp_verify_nonce($_POST['bpm_nonce'], 'import_csv')) {
    if (isset($_FILES['csv_file']) && $selected_competition) {
        try {
            $csv_handler = new BPM_CSV_Handler();
            $result = $csv_handler->import_prizes($_FILES['csv_file'], $selected_competition);
            
            if ($result['success_count'] > 0) {
                echo '<div class="notice notice-success"><p>' . 
                    sprintf(__('Successfully imported %d prizes! (%d failed, %d skipped)', 'britvic-promo'), 
                    $result['success_count'], $result['error_count'], $result['skipped_count']) . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('No prizes were imported.', 'britvic-promo') . '</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// Get prizes for selected competition
$prizes = [];
// Pagination and search setup
$paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
$per_page = 50;
$search_email = isset($_GET['search_email']) ? sanitize_text_field($_GET['search_email']) : '';
$order_by = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

if ($selected_competition) {
    $meta_query = [
        [
            'key' => 'competition_id',
            'value' => $selected_competition,
            'compare' => '='
        ]
    ];
    
    // Add email search if provided
    if ($search_email) {
        $meta_query[] = [
            'key' => 'claimed_by_email',
            'value' => $search_email,
            'compare' => 'LIKE'
        ];
    }
    
    $args = [
        'post_type' => 'prize',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'meta_query' => $meta_query,
        'order' => $order
    ];

    // Meta-keyed sort. Pick the right cast so chronological / numeric
    // columns don't fall back to lexicographic string order:
    //   - date columns → ALWAYS sort by the `date` postmeta with DATETIME cast.
    //     This is the authoritative source matching what render_column_content
    //     displays in the Date column. The dynamic-mapped date keys like
    //     `win_date_from_file` exist as standalone postmeta too but can be out
    //     of sync with the mapped_data JSON (which is the actual display source),
    //     so sorting by them would give an order that doesn't match the visible
    //     rows. `date` postmeta tracks the mapped_data and is the safe pivot.
    //   - numeric columns (prize value) → meta_value_num
    //   - everything else (names, codes, unique_id) → plain meta_value
    $date_column_keys = ['date', 'win_date_auto', 'win_date_from_file', 'prize_date', 'date_available'];
    if (in_array($order_by, $date_column_keys, true)) {
        $args['orderby']   = 'meta_value';
        $args['meta_key']  = 'date';
        $args['meta_type'] = 'DATETIME';
    } elseif ($order_by === 'value') {
        $args['orderby']  = 'meta_value_num';
        $args['meta_key'] = $order_by;
    } elseif ($order_by === 'post_date') {
        // Legacy escape hatch: sort by WP post creation timestamp.
        $args['orderby'] = 'date';
    } else {
        $args['orderby']  = 'meta_value';
        $args['meta_key'] = $order_by;
    }
    
    // Get total count for pagination (filtered results)
    $count_args = $args;
    $count_args['posts_per_page'] = -1;
    $count_args['fields'] = 'ids';
    $filtered_prizes = get_posts($count_args);
    $filtered_count = count($filtered_prizes);
    $total_pages = ceil($filtered_count / $per_page);
    
    $prizes = get_posts($args);
    
    // Debug logging
    // Debug: Log prize query results
    
    // Get ACTUAL totals for the entire competition (not filtered)
    $actual_total_count = 0;
    $claimed_count = 0;
    if ($selected_competition) {
        // Get all prizes for this competition (unfiltered)
        $all_prizes = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $selected_competition,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);
        $actual_total_count = count($all_prizes);
        
        // Get total count for pagination
        
        // Get claimed prizes for this competition
        $claimed_prizes = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'competition_id',
                    'value' => $selected_competition,
                    'compare' => '='
                ],
                [
                    'key' => 'claimed',
                    'value' => 'true',
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);
        $claimed_count = count($claimed_prizes);
    }
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="bpm-admin-content">
        
        <!-- Competition Selection -->
        <div class="bpm-admin-section">
            <h2><?php _e('Select Competition', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-prizes" />
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="competition_id"><?php _e('Competition', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="competition_id" name="competition_id" onchange="this.form.submit()">
                                <option value=""><?php _e('Select Competition', 'britvic-promo'); ?></option>
                                <?php foreach ($competitions as $competition): ?>
                                    <option value="<?php echo $competition['id']; ?>" <?php selected($selected_competition, $competition['id']); ?>>
                                        <?php echo esc_html($competition['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        
        <?php if ($selected_competition): 
            // Load saved prize types for this competition
            $saved_prize_types = get_option("bpm_generation_prize_types_{$selected_competition}", []);
            
            // Load current prize window setting (default: 10 minutes)
            $current_prize_window = get_option("bpm_prize_window_{$selected_competition}", 10);
        ?>
        

        
        <!-- Prize Availability Window -->
        <div class="bpm-admin-section">
            <h2><?php _e('Prize Availability Window', 'britvic-promo'); ?></h2>
            <div class="inside">
                <p class="description">
                    <?php _e('Configure how long each prize remains available for winning after its scheduled time.', 'britvic-promo'); ?>
                </p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('save_prize_window', 'bpm_nonce'); ?>
                    <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="prize_window_minutes"><?php _e('Prize Window Duration', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="prize_window_minutes" 
                                       name="prize_window_minutes" 
                                       value="<?php echo esc_attr($current_prize_window); ?>" 
                                       min="1" 
                                       max="120" 
                                       step="1" 
                                       class="small-text" /> 
                                <?php _e('minutes', 'britvic-promo'); ?>
                                
                                <p class="description">
                                    <?php _e('Example: If set to 10 minutes, a prize scheduled for 12:38:00 will be available from 12:38:00 to 12:48:00', 'britvic-promo'); ?>
                                    <br>
                                    <strong><?php _e('Current setting:', 'britvic-promo'); ?></strong> 
                                    <?php echo esc_html($current_prize_window); ?> <?php _e('minutes', 'britvic-promo'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="save_prize_window" class="button-primary" value="<?php _e('Save Prize Window', 'britvic-promo'); ?>" />
                    </p>
                </form>
                
            </div>
        </div>
        
        <!-- Generate Prizes Section -->
        <div class="bpm-admin-section">
            <h2><?php _e('Generate Prizes', 'britvic-promo'); ?></h2>
            
            <!-- Prize Types Configuration -->
            <div style="margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                <h3 style="margin-top: 0;"><?php _e('Prize Types', 'britvic-promo'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Configure the types of prizes that will be available for this competition. These prize types will be available in field mapping when importing prizes.', 'britvic-promo'); ?>
                </p>
                
                <div id="prize-types-container">
                    <?php foreach ($saved_prize_types as $index => $type): ?>
                        <div class="prize-type-row" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ccc; border-radius: 4px; background: #fff; display: flex; align-items: center; gap: 15px;">
                            <label style="margin: 0; font-weight: 600;"><?php _e('Prize Name:', 'britvic-promo'); ?></label>
                            <input type="text" class="prize-type-name" value="<?php echo esc_attr($type['name']); ?>" placeholder="<?php _e('e.g., drink, voucher, discount', 'britvic-promo'); ?>" style="flex: 1; max-width: 300px;" />
                            <button type="button" class="button remove-prize-type"><?php _e('Remove', 'britvic-promo'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <p style="margin-top: 15px;">
                    <button type="button" id="add-prize-type" class="button"><?php _e('+ Add Prize Type', 'britvic-promo'); ?></button>
                    <button type="button" id="save-prize-types" class="button button-primary"><?php _e('Save Prize Types', 'britvic-promo'); ?></button>
                    <span id="save-prize-types-status"></span>
                </p>
            </div>

            <!-- Import Prizes from File -->
            <div style="margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                <h3 style="margin-top: 0;"><?php _e('Import Prizes from File', 'britvic-promo'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Start by uploading a spreadsheet with your prize data, then choose to generate additional prizes manually if needed.', 'britvic-promo'); ?>
                </p>
                
                <form method="post" enctype="multipart/form-data" id="file-import-form">
                    <?php wp_nonce_field('import_file', 'bpm_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="import_file"><?php _e('Prize File', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx,.xls" required />
                                <p class="description">
                                    <?php _e('Upload a CSV or Excel file with prize data.', 'britvic-promo'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Column Mapping Section (shown after file analysis) -->
                    <div id="column-mapping" style="display: none;">                        

                        <div id="csv-info" style="background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; padding-left: 10px; margin-bottom: 15px; display: none;">
                            <p><strong><?php _e('CSV File Information: ', 'britvic-promo'); ?></strong><span style="margin: 5px 0;" id="csv-row-count"><?php _e('File analysis in progress...', 'britvic-promo'); ?></span></p>
                        </div>

                        <div id="prize-type-distribution" style="display: none; background: #f0f6fc; border: 1px solid #c8e1ff; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
                            <h4><?php _e('Prize Type Distribution', 'britvic-promo'); ?></h4>
                            <p class="description"><?php _e('Distribute the imported prizes among the available prize types. The total must match the number of rows in the file.', 'britvic-promo'); ?></p>
                            <div id="prize-type-distribution-container">
                                <!-- Prize type inputs will be populated here by JS -->
                            </div>
                            <div style="margin-top: 10px; font-weight: bold;">
                                <?php _e('Total Allocated:', 'britvic-promo'); ?> <span id="total-allocated-prizes">0</span> / <span id="total-csv-rows">0</span>
                                <span id="allocation-feedback" style="margin-left: 15px; font-style: italic;"></span>
                            </div>
                        </div>

                        <div id="prize-date-generation" style="display: none; margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background: #ffffff;">
                            <h3 style="margin-top: 0;"><?php _e('Prize Date Generation', 'britvic-promo'); ?></h3>
                            <p class="description" style="margin-bottom: 15px;">
                                <?php _e('Configure the date and time range for generating random prize dates. The number of dates generated will match the number of rows in your imported file.', 'britvic-promo'); ?>
                            </p>
                            
                            <div id="prize-generator">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="start_date"><?php _e('Start Date', 'britvic-promo'); ?></label>
                                        </th>
                                        <td>
                                            <input type="date" id="start_date" value="<?php echo date('Y-m-d'); ?>" />
                                        </td>
                                        <th scope="row">
                                            <label for="end_date"><?php _e('End Date', 'britvic-promo'); ?></label>
                                        </th>
                                        <td>
                                            <input type="date" id="end_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" />
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="start_time"><?php _e('Start Time', 'britvic-promo'); ?></label>
                                        </th>
                                        <td>
                                            <input type="time" id="start_time" value="09:00" />
                                        </td>
                                        <th scope="row">
                                            <label for="end_time"><?php _e('End Time', 'britvic-promo'); ?></label>
                                        </th>
                                        <td>
                                            <input type="time" id="end_time" value="17:00" />
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="num_prizes"><?php _e('Number of Prizes', 'britvic-promo'); ?></label>
                                        </th>
                                        <td colspan="3">
                                            <input type="number" id="num_prizes" min="1" value="100" readonly style="background: #eee;"/>
                                            <span class="description"><?php _e('This is the number of rows from your imported file.', 'britvic-promo'); ?></span>
                                        </td>
                                    </tr>
                                </table>
                                
                                <div id="date-settings-info" style="background: #e7f3ff; border: 1px solid #0073aa; border-radius: 4px; padding: 15px; margin-top: 15px;">
                                    <strong><?php _e('Date Range Summary:', 'britvic-promo'); ?></strong>
                                    <p style="margin: 5px 0 0 0; color: #666;" id="date-range-summary">
                                        <?php _e('Configure dates above to see summary', 'britvic-promo'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <h4><?php _e('Column Mapping', 'britvic-promo'); ?></h4>
                        <p class="description"><?php _e('Map the columns from your file to the prize fields. Excel files are automatically converted to CSV to preserve data formatting. Codes will be imported exactly as they appear in your file. If you have a prize type column, you can map it to use the prize types configured above.', 'britvic-promo'); ?></p>
             
                        <!-- Auto-Mapped Columns -->
                        <div id="auto-mapping" class="mapping-section">
                            <h4><?php _e('Detected Columns', 'britvic-promo'); ?></h4>
                            <p class="description"><?php _e('All columns from your CSV will be imported automatically. You can exclude columns or add custom fields below.', 'britvic-promo'); ?></p>
                            
                            <table class="wp-list-table widefat fixed striped" id="auto-mapping-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;"><?php _e('Column Name', 'britvic-promo'); ?></th>
                                        <th style="width: 25%;"><?php _e('Field Name', 'britvic-promo'); ?></th>
                                        <th style="width: 30%;"><?php _e('Sample Data', 'britvic-promo'); ?></th>
                                        <th style="width: 15%;"><?php _e('Include', 'britvic-promo'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="auto-mapping-tbody">
                                    <!-- Auto-mapped columns will be populated here -->
                                </tbody>
                            </table>
                            
                            <div style="margin-top: 15px;">
                                <button type="button" id="add-custom-field-btn" class="button"><?php _e('+ Add Custom Field', 'britvic-promo'); ?></button>
                            </div>
                        </div>
                        
                        <p style="margin-top: 15px;">
                            <button type="button" id="auto-map-columns" class="button"><?php _e('Auto-Map Columns', 'britvic-promo'); ?></button>
                            <button type="button" id="preview-import" class="button button-secondary"><?php _e('Preview Import', 'britvic-promo'); ?></button>
                            <span class="description"><?php _e('Auto-mapping will try to match columns based on common names like "code", "prize", "value", etc.', 'britvic-promo'); ?></span>
                        </p>
                    </div>
                    
                    <!-- Preview Section -->
                    <div id="import-preview" style="display: none;">
                        <h4><?php _e('Import Preview', 'britvic-promo'); ?></h4>
                        <div class="notice notice-info inline">
                            <p><strong><?php _e('Note:', 'britvic-promo'); ?></strong> <?php _e('Unique IDs will be automatically generated for all prizes. You do not need to map a unique ID column.', 'britvic-promo'); ?></p>
                        </div>
                        <div id="preview-content"></div>
                    </div>
                    
                    <div id="import-controls" style="display: none; margin-top: 20px;">
                        <p class="description">
                            <strong><?php _e('Ready to import!', 'britvic-promo'); ?></strong> 
                            <?php _e('Click the button below to import the mapped data into the current competition.', 'britvic-promo'); ?>
                        </p>
                        <?php submit_button(__('Import File', 'britvic-promo'), 'primary', 'import_file'); ?>
                    </div>
                    
                    <div id="import-progress" style="display: none; margin-top: 20px;">
                        <h3><?php _e('Import Progress', 'britvic-promo'); ?></h3>
                        <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
                            <div style="margin-bottom: 10px;">
                                <span id="progress-text"><?php _e('Preparing import...', 'britvic-promo'); ?></span>
                            </div>
                            <div style="background: #f0f0f0; border-radius: 10px; height: 20px; overflow: hidden; margin-bottom: 10px;">
                                <div id="progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                <span id="progress-percentage">0%</span> - 
                                <span id="progress-status"><?php _e('Processing...', 'britvic-promo'); ?></span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            

            
            <!-- Manual Single Prize Addition -->
            <div style="margin-top: 40px; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                <h3><?php _e('Add Individual Prize', 'britvic-promo'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Add a single prize entry manually with custom details.', 'britvic-promo'); ?>
                </p>
                
                <div id="add-single-prize-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php _e('Prize Codes', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <div id="single-prize-codes-container">
                                    <div class="single-prize-code-group" style="display: flex; gap: 5px; margin-bottom: 5px;">
                                        <input type="text" class="single-prize-code-input regular-text" placeholder="e.g., DRINK001, BP2024" style="flex: 1;" />
                                        <button type="button" class="button remove-single-code-btn" style="display: none;"><?php _e('−', 'britvic-promo'); ?></button>
                                    </div>
                                </div>
                                <button type="button" id="add-single-code-btn" class="button button-small" style="margin-top: 5px;"><?php _e('+ Add Code', 'britvic-promo'); ?></button>
                                <p class="description"><?php _e('Enter one or more unique codes for this prize. Each code will create a separate prize entry.', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="single-prize-category"><?php _e('Prize Type', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <select id="single-prize-category" class="regular-text">
                                    <option value=""><?php _e('Select type', 'britvic-promo'); ?></option>
                                    <?php foreach ($saved_prize_types as $pt): ?>
                                        <option value="<?php echo esc_attr($pt['name']); ?>">
                                            <?php echo esc_html($pt['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($saved_prize_types)): ?>
                                    <p class="description" style="color:#d63638;"><?php _e('No prize types configured. Add them in the Prize Types section below.', 'britvic-promo'); ?></p>
                                <?php else: ?>
                                    <p class="description"><?php _e('Prize type for this entry', 'britvic-promo'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="single-prize-type"><?php _e('Prize', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="single-prize-type" class="regular-text" placeholder="e.g., Pepsi Max Pint" />
                                <p class="description"><?php _e('Display name of the prize', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="single-prize-date"><?php _e('Win Date/Time', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <input type="datetime-local" id="single-prize-date" class="regular-text" value="<?php echo date('Y-m-d\TH:i'); ?>" />
                                <p class="description"><?php _e('When this prize can be won', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="single-prize-status"><?php _e('Initial Status', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <select id="single-prize-status" class="regular-text">
                                    <option value="available"><?php _e('Available', 'britvic-promo'); ?></option>
                                    <option value="claimed"><?php _e('Already Claimed', 'britvic-promo'); ?></option>
                                </select>
                                <p class="description"><?php _e('Set as claimed if adding a historical prize', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="single-prize-source-route"><?php _e('Source Route', 'britvic-promo'); ?></label>
                            </th>
                            <td>
                                <select id="single-prize-source-route" class="regular-text">
                                    <option value="both"><?php _e('Both routes', 'britvic-promo'); ?></option>
                                    <option value="main"><?php _e('Main domain only', 'britvic-promo'); ?></option>
                                    <option value="alt"><?php _e('Alternative subdomain only', 'britvic-promo'); ?></option>
                                </select>
                                <p class="description"><?php _e('Restrict which domain entries can win this prize. "Both" makes it available to either route.', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                        <tr id="winner-details-row" style="display: none;">
                            <th scope="row"><?php _e('Winner Details', 'britvic-promo'); ?></th>
                            <td>
                                <input type="text" id="single-prize-winner-email" class="regular-text" placeholder="winner@example.com" style="margin-bottom: 5px;" />
                                <input type="text" id="single-prize-winner-id" class="regular-text" placeholder="Winner ID or Entry ID" />
                                <p class="description"><?php _e('Winner email and ID (if already claimed)', 'britvic-promo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="button" id="add-single-prize" class="button button-primary">
                            <?php _e('Add Prize', 'britvic-promo'); ?>
                        </button>
                        <button type="button" id="clear-single-prize-form" class="button">
                            <?php _e('Clear Form', 'britvic-promo'); ?>
                        </button>
                        <span id="single-prize-status"></span>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Existing Prizes -->
        <div class="bpm-admin-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php _e('Existing Prizes', 'britvic-promo'); ?></h2>
                
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div class="prize-stat-inline">
                        <span class="stat-label"><?php _e('Total:', 'britvic-promo'); ?></span>
                        <span class="stat-value" id="total-prizes"><?php echo number_format($actual_total_count); ?></span>
                    </div>
                    <div class="prize-stat-inline">
                        <span class="stat-label"><?php _e('Claimed:', 'britvic-promo'); ?></span>
                        <span class="stat-value claimed-count" id="claimed-count"><?php echo number_format($claimed_count); ?></span>
                    </div>
                    <div class="prize-stat-inline">
                        <span class="stat-label"><?php _e('Available:', 'britvic-promo'); ?></span>
                        <span class="stat-value available-count"><?php echo number_format($actual_total_count - $claimed_count); ?></span>
                    </div>
                    <div class="prize-stat-inline">
                        <span class="stat-label"><?php _e('Rate:', 'britvic-promo'); ?></span>
                        <span class="stat-value"><?php echo $actual_total_count > 0 ? number_format(($claimed_count / $actual_total_count) * 100, 1) : '0'; ?>%</span>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter -->
            <form id="bpm-prizes-filter-form" method="get" action="" style="margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 4px; background: #f9f9f9;">
                <input type="hidden" name="page" value="britvic-prizes" />
                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                
                <div class="search-filters" style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
                    <div>
                        <label for="search_email"><?php _e('Winner Email', 'britvic-promo'); ?></label><br>
                        <input type="email" id="search_email" name="search_email" value="<?php echo esc_attr($search_email); ?>" placeholder="Search by winner email" class="regular-text" />
                    </div>
                    
                    <input type="hidden" name="orderby" value="<?php echo esc_attr($order_by); ?>" />
                    <input type="hidden" name="order" value="<?php echo esc_attr(strtolower($order)); ?>" />

                    <div>
                        <button type="submit" class="button"><?php _e('Apply Filters', 'britvic-promo'); ?></button>
                        <?php if ($search_email): ?>
                            <a href="<?php echo admin_url('admin.php?page=britvic-prizes&competition_id=' . $selected_competition); ?>" class="button"><?php _e('Clear', 'britvic-promo'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($selected_competition): ?>
                    <p class="description" style="margin: 10px 0 0 0;">
                        <?php if ($search_email): ?>
                            <?php printf(__('Showing %d-%d of %d filtered results (from %d total prizes)', 'britvic-promo'), 
                                (($paged - 1) * $per_page) + 1, 
                                min($paged * $per_page, $filtered_count), 
                                $filtered_count,
                                $actual_total_count
                            ); ?>
                        <?php else: ?>
                            <?php printf(__('Showing %d-%d of %d prizes', 'britvic-promo'), 
                                (($paged - 1) * $per_page) + 1, 
                                min($paged * $per_page, $actual_total_count), 
                                $actual_total_count
                            ); ?>
                        <?php endif; ?>
                        <?php if ($search_email): ?>
                            <?php printf(__(' (filtered by email: %s)', 'britvic-promo'), esc_html($search_email)); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </form>
            
            <?php if (empty($prizes)): ?>
                <p><?php _e('No prizes found for this competition. Generate or import prizes above.', 'britvic-promo'); ?></p>
            <?php else: ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bpm-tablenav-actions">
                        <div class="bpm-tablenav-row bpm-tablenav-row--buttons">
                            <button type="button" id="export-csv" class="button"><?php _e('Export CSV', 'britvic-promo'); ?></button>
                            <button type="button" id="reseed-prizes" class="button"><?php _e('Reseed Unclaimed', 'britvic-promo'); ?></button>
                            <button type="button" id="sync-prizes" class="button button-primary"><?php _e('Sync Prizes', 'britvic-promo'); ?></button>
                            <button type="button" id="bpm-sync-aliases" class="button" title="<?php esc_attr_e('Backfill date / prize-name / code aliases across postmeta, mapped_data and bpm_prize_entries so the admin sort and display always agree.', 'britvic-promo'); ?>"><?php _e('Sync Prize Data', 'britvic-promo'); ?></button>
                        </div>

                        <div class="bpm-tablenav-row bpm-tablenav-row--bulk">
                            <label for="bulk-action-selector-top" class="screen-reader-text"><?php _e('Select bulk action', 'britvic-promo'); ?></label>
                            <select name="action" id="bulk-action-selector-top">
                                <option value="-1"><?php _e('Bulk actions', 'britvic-promo'); ?></option>
                                <option value="mark_available"><?php _e('Mark as Available', 'britvic-promo'); ?></option>
                                <option value="mark_claimed"><?php _e('Mark as Claimed', 'britvic-promo'); ?></option>
                                <option value="delete"><?php _e('Delete', 'britvic-promo'); ?></option>
                            </select>
                            <button type="button" id="doaction" class="button action"><?php _e('Apply', 'britvic-promo'); ?></button>
                        </div>
                    </div>
                    
                    <!-- Column Management Icons -->
                    <div class="alignright" style="display: flex; align-items: center; gap: 5px;">
                        <button type="button" id="configure-columns-btn" style="background: none; border: none; padding: 4px; cursor: pointer; color: #646970;" title="<?php _e('Configure Columns', 'britvic-promo'); ?>">
                            <span class="dashicons dashicons-admin-generic" style="font-size: 20px; width: 20px; height: 20px; line-height: 1;"></span>
                        </button>
                        <button type="button" id="reset-columns-btn" style="background: none; border: none; padding: 4px; cursor: pointer; color: #646970;" title="<?php _e('Reset to Default', 'britvic-promo'); ?>">
                            <span class="dashicons dashicons-update" style="font-size: 20px; width: 20px; height: 20px; line-height: 1;"></span>
                        </button>
                    </div>
                </div>

                <!-- BPM_FRAGMENT_START -->
                <div id="bpm-prizes-fragment">

                <div id="bpm-bulk-select-all-banner" class="notice notice-info inline" style="display:none; margin: 0 0 10px 0; padding: 8px 12px;">
                    <p style="margin: 0;">
                        <span class="bpm-banner-page-msg"></span>
                        <a href="#" id="bpm-select-all-pages-link" style="margin-left: 6px;"></a>
                        <span id="bpm-select-all-pages-active" style="display:none; margin-left: 6px; font-weight: 600; color: #2271b1;">
                            <?php _e('All prizes across every page are selected.', 'britvic-promo'); ?>
                            <a href="#" id="bpm-select-all-pages-clear"><?php _e('Clear selection.', 'britvic-promo'); ?></a>
                        </span>
                    </p>
                </div>

<?php
                    // Analyze available columns from imported prizes
                    $available_columns = analyze_available_columns($prizes, $selected_competition);
                    $column_config = get_column_configuration($available_columns, $selected_competition);
                    
                    // Debug output (remove in production)
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // Table configuration ready
                    }
                ?>
                
                <div class="bpm-prizes-table-wrap">
                <table id="existing-prizes-table" class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th class="column-bulk-select check-column">
                                <label class="screen-reader-text" for="cb-select-all-1"><?php _e('Select All', 'britvic-promo'); ?></label>
                                <input id="cb-select-all-1" type="checkbox" />
                            </th>
                            <?php foreach ($column_config as $column_key => $column_info): ?>
                                <?php
                                // Every column is sortable except `actions` (no meaningful order)
                                // and computed convenience columns that don't map to a stable key.
                                $non_sortable_columns = ['actions'];
                                $sortable_class = in_array($column_key, $non_sortable_columns, true) ? '' : ' sortable';
                                ?>
                                <?php
                                $is_active_sort = ( $sortable_class && $order_by === $column_key );
                                $indicator      = $is_active_sort ? ( strtoupper( $order ) === 'ASC' ? '↑' : '↓' ) : '⇅';
                                ?>
                                <th class="column-<?php echo esc_attr($column_key); ?><?php echo $sortable_class; ?><?php echo $is_active_sort ? ' sorted-' . esc_attr( strtolower( $order ) ) : ''; ?>"
                                    style="<?php echo esc_attr($column_info['style'] ?? ''); ?><?php echo $sortable_class ? 'cursor: pointer;' : ''; ?>"
                                    data-column="<?php echo esc_attr($column_key); ?>"
                                    title="<?php echo $sortable_class ? esc_attr(__('Click to sort', 'britvic-promo')) : ''; ?>">
                                    <?php echo esc_html($column_info['label']); ?>
                                    <?php if ($sortable_class): ?>
                                        <span class="sort-indicator"><?php echo $indicator; ?></span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Pre-fetch entry data from prize_entries for all prizes on this page.
                        // Pulls id + claimed + stripe_payout_status + prize_type so the new
                        // Claimed / Status columns can render without a per-row DB hit.
                        $entry_id_map  = [];
                        $bpm_entry_map = [];
                        if (!empty($prizes)) {
                            $post_ids = array_map(fn($p) => $p->ID, $prizes);
                            $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
                            $entries = $wpdb->get_results(
                                $wpdb->prepare(
                                    "SELECT id, prize_post_id, claimed, stripe_payout_status, prize_type
                                     FROM {$wpdb->prefix}bpm_prize_entries
                                     WHERE prize_post_id IN ($placeholders)",
                                    ...$post_ids
                                ),
                                ARRAY_A
                            );
                            foreach ($entries as $entry) {
                                $pid = (int) $entry['prize_post_id'];
                                $entry_id_map[$pid]  = (int) $entry['id'];
                                $bpm_entry_map[$pid] = [
                                    'claimed'              => isset($entry['claimed']) ? (int) $entry['claimed'] : 0,
                                    'stripe_payout_status' => (string) ($entry['stripe_payout_status'] ?? ''),
                                    'prize_type'           => (string) ($entry['prize_type'] ?? ''),
                                ];
                            }
                        }
                        ?>
                        <?php foreach ($prizes as $prize):
                            $is_claimed = get_post_meta($prize->ID, 'claimed', true) === 'true';
                            $winner_id = get_post_meta($prize->ID, 'claimed_by_user_id', true);
                            $winner_email = get_post_meta($prize->ID, 'claimed_by_email', true);
                            $gravity_form_entry_id = get_post_meta($prize->ID, 'gravity_form_entry_id', true);
                            $entry_id = $entry_id_map[$prize->ID] ?? 0;
                            $bpm_entry = $bpm_entry_map[$prize->ID] ?? [];

                            // Get mapped data for this prize
                            $mapped_data_json = get_post_meta($prize->ID, 'mapped_data', true);
                            $mapped_data = $mapped_data_json ? json_decode($mapped_data_json, true) : [];
                        ?>
                            <tr data-prize-id="<?php echo $prize->ID; ?>" data-entry-id="<?php echo (int) $entry_id; ?>">
                                <th scope="row" class="check-column column-bulk-select">
                                    <label class="screen-reader-text" for="cb-select-<?php echo (int) $prize->ID; ?>"><?php printf( esc_html__( 'Select prize %d', 'britvic-promo' ), (int) $prize->ID ); ?></label>
                                    <input id="cb-select-<?php echo (int) $prize->ID; ?>" type="checkbox" class="bpm-row-select" value="<?php echo (int) $prize->ID; ?>" />
                                </th>
                                <?php foreach ($column_config as $column_key => $column_info): ?>
                                    <td class="<?php echo esc_attr($column_info['editable'] ? 'editable' : ''); ?> column-<?php echo esc_attr($column_key); ?><?php echo isset($column_info['class']) ? ' ' . esc_attr($column_info['class']) : ''; ?>" 
                                        <?php if ($column_info['editable']): ?>data-field="<?php echo esc_attr($column_key); ?>"<?php endif; ?>>
                                        <?php echo render_column_content($prize, $column_key, $column_info, $mapped_data, $is_claimed, $winner_id, $winner_email, $gravity_form_entry_id, $entry_id, $bpm_entry); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper" style="margin-top: 20px; text-align: center;">
                        <?php
                        $base_url = add_query_arg([
                            'page' => 'britvic-prizes',
                            'competition_id' => $selected_competition,
                            'search_email' => $search_email,
                            'orderby' => $order_by,
                            'order' => strtolower($order)
                        ], admin_url('admin.php'));
                        ?>
                        
                        <div class="pagination-links">
                            <?php if ($paged > 1): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)); ?>" class="button" title="<?php esc_attr_e('First', 'britvic-promo'); ?>"><i class="fa-solid fa-angles-left" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('First', 'britvic-promo'); ?></span></a>
                                <a href="<?php echo esc_url(add_query_arg('paged', $paged - 1, $base_url)); ?>" class="button" title="<?php esc_attr_e('Previous', 'britvic-promo'); ?>"><i class="fa-solid fa-angle-left" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Previous', 'britvic-promo'); ?></span></a>
                            <?php endif; ?>

                            <span class="pagination-info">
                                <?php printf(__('Page %d of %d', 'britvic-promo'), $paged, $total_pages); ?>
                            </span>

                            <?php if ($paged < $total_pages): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $paged + 1, $base_url)); ?>" class="button" title="<?php esc_attr_e('Next', 'britvic-promo'); ?>"><i class="fa-solid fa-angle-right" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Next', 'britvic-promo'); ?></span></a>
                                <a href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_url)); ?>" class="button" title="<?php esc_attr_e('Last', 'britvic-promo'); ?>"><i class="fa-solid fa-angles-right" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Last', 'britvic-promo'); ?></span></a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pagination-jump" style="margin-top: 10px;">
                            <form method="get" action="" style="display: inline;">
                                <input type="hidden" name="page" value="britvic-prizes" />
                                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                                <input type="hidden" name="search_email" value="<?php echo esc_attr($search_email); ?>" />
                                <input type="hidden" name="orderby" value="<?php echo esc_attr($order_by); ?>" />
                                <input type="hidden" name="order" value="<?php echo esc_attr(strtolower($order)); ?>" />
                                
                                <label for="jump_to_page"><?php _e('Jump to page:', 'britvic-promo'); ?></label>
                                <input type="number" id="jump_to_page" name="paged" min="1" max="<?php echo $total_pages; ?>" value="<?php echo $paged; ?>" />
                                <button type="submit" class="button button-small"><?php _e('Go', 'britvic-promo'); ?></button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                </div><!-- /#bpm-prizes-fragment -->
                <!-- BPM_FRAGMENT_END -->
                <?php if ( ! empty( $GLOBALS['bpm_ajax_fragment_only'] ) ) { return; } ?>
            <?php endif; ?>

            <!-- Column Management Popup Dialog -->
            <div id="column-config-dialog" class="column-dialog-overlay" style="display: none;">
                <div class="column-dialog">
                    <div class="column-dialog-header">
                        <h3><?php _e('Configure Table Columns', 'britvic-promo'); ?></h3>
                        <button type="button" id="close-column-dialog" class="column-dialog-close" title="<?php _e('Close', 'britvic-promo'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    
                    <div class="column-dialog-content">
                        <!-- Available Columns -->
                        <div>
                            <h4><?php _e('Available Columns', 'britvic-promo'); ?></h4>
                            <p class="description"><?php _e('Drag columns to reorder them. Toggle visibility with checkboxes.', 'britvic-promo'); ?></p>
                            <ul id="sortable-columns" style="list-style: none; margin: 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #fff; min-height: 300px; max-height: 400px; overflow-y: auto;">
                                <!-- Column list will be populated by JavaScript -->
                            </ul>
                        </div>
                    </div>
                    
                    <div class="column-dialog-footer">
                        <button type="button" id="save-column-config" class="button button-primary">
                            <?php _e('Save Configuration', 'britvic-promo'); ?>
                        </button>
                        <button type="button" id="cancel-column-config" class="button">
                            <?php _e('Cancel', 'britvic-promo'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
        
    </div>
</div>

<script>
// Date formatting helper function for UK format (dd-mm-yyyy hh:mm AM/PM)
function formatUKDate(dateString) {
    if (!dateString) return '';
    
    var date = new Date(dateString);
    if (isNaN(date.getTime())) {
        return dateString; // Return original if invalid
    }
    
    var day = String(date.getDate()).padStart(2, '0');
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var year = date.getFullYear();
    
    // Convert to 12-hour format with AM/PM
    var hours = date.getHours();
    var minutes = String(date.getMinutes()).padStart(2, '0');
    var ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    
    return day + '-' + month + '-' + year + ' ' + hours + ':' + minutes + ' ' + ampm;
}

// Progress simulation helper functions
var progressInterval;

function simulateImportProgress() {
    var currentProgress = 0;
    var steps = [
        { progress: 20, message: '<?php _e('Analyzing file...', 'britvic-promo'); ?>' },
        { progress: 40, message: '<?php _e('Validating data...', 'britvic-promo'); ?>' },
        { progress: 60, message: '<?php _e('Processing prizes...', 'britvic-promo'); ?>' },
        { progress: 80, message: '<?php _e('Saving to database...', 'britvic-promo'); ?>' },
        { progress: 90, message: '<?php _e('Finalizing import...', 'britvic-promo'); ?>' }
    ];
    var currentStep = 0;
    
    progressInterval = setInterval(function() {
        if (currentStep < steps.length) {
            var step = steps[currentStep];
            
            // Gradually increase progress to step target
            if (currentProgress < step.progress) {
                currentProgress += 2;
                if (currentProgress > step.progress) {
                    currentProgress = step.progress;
                }
                
                jQuery('#progress-bar').css('width', currentProgress + '%');
                jQuery('#progress-percentage').text(currentProgress + '%');
                jQuery('#progress-status').text(step.message);
                
                if (currentProgress === step.progress) {
                    currentStep++;
                }
            }
        } else {
            // Stop at 90% and wait for actual completion
            clearInterval(progressInterval);
        }
    }, 150);
}

function resetImportUI(button) {
    // Clear progress interval
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    
    if (button) {
        button.prop('disabled', false).val('<?php _e('Import File', 'britvic-promo'); ?>');
    } else {
        jQuery('input[type="submit"][name="import_file"]').prop('disabled', false).val('<?php _e('Import File', 'britvic-promo'); ?>');
    }
    jQuery('#import-progress').hide();
    jQuery('#import-controls').show();
    
    // Reset progress elements
    jQuery('#progress-bar').css('width', '0%');
    jQuery('#progress-percentage').text('0%');
    jQuery('#progress-text').text('<?php _e('Preparing import...', 'britvic-promo'); ?>');
    jQuery('#progress-status').text('<?php _e('Processing...', 'britvic-promo'); ?>');
}

jQuery(document).ready(function(jQuery) {
    // Add prize type functionality
    jQuery('#add-prize-type').on('click', function() {
        var newRow = jQuery('<div class="prize-type-row" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ccc; border-radius: 4px; background: #fff; display: flex; align-items: center; gap: 15px;">')
            .append('<label style="margin: 0; font-weight: 600;"><?php _e('Prize Name:', 'britvic-promo'); ?></label>')
            .append('<input type="text" class="prize-type-name" placeholder="<?php _e('e.g., drink, voucher, discount', 'britvic-promo'); ?>" style="flex: 1; max-width: 300px;" />')
            .append('<button type="button" class="button remove-prize-type"><?php _e('Remove', 'britvic-promo'); ?></button>');
        jQuery('#prize-types-container').append(newRow);
    });

    // Remove prize type functionality
    jQuery(document).on('click', '.remove-prize-type', function() {
        var jQueryrow = jQuery(this).closest('.prize-type-row');
        if (jQuery('.prize-type-row').length <= 1) {
            alert('<?php _e('At least one prize type must remain.', 'britvic-promo'); ?>');
            return;
        }
        if (confirm('<?php _e('Are you sure you want to remove this prize type?', 'britvic-promo'); ?>')) {
            jQueryrow.fadeOut(300, function() { jQuery(this).remove(); });
        }
    });

    // Save prize types configuration
    jQuery('#save-prize-types').on('click', function() {
        var button = jQuery(this);
        var status = jQuery('#save-prize-types-status');
        var originalText = button.text();
        var prizeTypes = [];

        jQuery('.prize-type-row').each(function() {
            var name = jQuery(this).find('.prize-type-name').val().trim();
            if (name) {
                prizeTypes.push({ name: name });
            }
        });

        if (prizeTypes.length === 0) {
            status.html('<span style="color: red;"><?php _e('Please add at least one prize type.', 'britvic-promo'); ?></span>');
            return;
        }

        button.text('<?php _e('Saving...', 'britvic-promo'); ?>').prop('disabled', true);
        status.html('<?php _e('Saving...', 'britvic-promo'); ?>');

        jQuery.post(ajaxurl, {
            action: 'bpm_save_generation_prize_types',
            competition_id: <?php echo $selected_competition; ?>,
            prize_types: prizeTypes,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                status.html('<span style="color: green;">' + response.data.message + '</span>');
                if (response.data.prize_types) {
                    savedPrizeTypes = response.data.prize_types;
                    // If the distribution section is visible, refresh it
                    if (jQuery('#prize-type-distribution').is(':visible')) {
                        var totalRows = parseInt(jQuery('#total-csv-rows').text()) || 0;
                        setupPrizeDistribution(totalRows);
                    }
                }
            } else {
                status.html('<span style="color: red;"><?php _e('Save failed:', 'britvic-promo'); ?> ' + response.data.message + '</span>');
            }
        }).fail(function() {
            status.html('<span style="color: red;"><?php _e('Request failed.', 'britvic-promo'); ?></span>');
        }).always(function() {
            button.text(originalText).prop('disabled', false);
            setTimeout(function() { status.html(''); }, 5000);
        });
    });

    jQuery(document).on('change', '.prize-type-name', function() {
        jQuery('#save-prize-types-status').html('<span style="color: #666; font-style: italic;"><?php _e('Changes detected. Click "Save Prize Types" to persist.', 'britvic-promo'); ?></span>');
    });


    
    // This handler is replaced by the more comprehensive one later in the file
    

    
    // Export CSV
    jQuery('#export-csv').on('click', function() {
        window.location.href = ajaxurl + '?action=bpm_export_csv&competition_id=<?php echo $selected_competition; ?>&export_type=prizes&nonce=' + 
            '<?php echo wp_create_nonce('bpm_nonce'); ?>';
    });
    
    // Reseed prizes with dialog
    jQuery('#reseed-prizes').on('click', function() {
        var selectedIds = jQuery('#existing-prizes-table tbody .bpm-row-select:checked').map(function() {
            return parseInt(this.value, 10);
        }).get();

        var dialogTitle = selectedIds.length > 0
            ? selectedIds.length + ' <?php _e('Selected Prize(s) — Reseed', 'britvic-promo'); ?>'
            : '<?php _e('Reseed Unclaimed Prizes', 'britvic-promo'); ?>';

        jQuery('#reseed-dialog').dialog({
            title: dialogTitle,
            modal: true,
            width: 600,
            height: 'auto',
            resizable: false,
            close: function() {
                jQuery('#reseed-loading, #reseed-content').hide();
                jQuery('#reseed-selected-info').hide();
                jQuery('#reseed-all-info').show();
            },
            buttons: [
                {
                    text: '<?php _e('Cancel', 'britvic-promo'); ?>',
                    class: 'button',
                    click: function() {
                        jQuery(this).dialog('close');
                    }
                },
                {
                    text: '<?php _e('Reseed Prizes', 'britvic-promo'); ?>',
                    class: 'button button-primary',
                    id: 'reseed-confirm-btn',
                    click: function() {
                        performReseed(selectedIds);
                    }
                }
            ]
        });

        // Load reseed information only if a competition is selected
        <?php if ($selected_competition): ?>
        loadReseedInfo(selectedIds);
        <?php endif; ?>
    });

    function loadReseedInfo(selectedIds) {
        selectedIds = selectedIds || [];
        jQuery('#reseed-loading').show();
        jQuery('#reseed-content').hide();

        if (selectedIds.length > 0) {
            // Selected mode: no need to query unclaimed count
            jQuery('#reseed-loading').hide();
            jQuery('#reseed-selected-info').show();
            jQuery('#reseed-selected-info-count').text(selectedIds.length);
            jQuery('#reseed-all-info').hide();
            jQuery('#reseed-content').show();
            jQuery('#reseed-confirm-btn').prop('disabled', false);
            return;
        }

        jQuery('#reseed-selected-info').hide();
        jQuery('#reseed-all-info').show();

        jQuery.post(ajaxurl, {
            action: 'bpm_get_reseed_info',
            competition_id: <?php echo $selected_competition; ?>,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            jQuery('#reseed-loading').hide();

            if (response.success) {
                var data = response.data;

                // Populate dialog with data
                jQuery('#competition-name').text(data.competition_name);
                jQuery('#total-prizes').text(data.total_prizes);
                jQuery('#unclaimed-count').text(data.unclaimed_count);
                jQuery('#reseeded-count').text(data.reseeded_count);

                // Set default date range
                jQuery('#reseed-start-date').val(data.suggested_start);
                jQuery('#reseed-end-date').val(data.suggested_end);

                // Show content and enable/disable reseed button
                jQuery('#reseed-content').show();
                jQuery('#reseed-confirm-btn').prop('disabled', data.unclaimed_count === 0);

                if (data.unclaimed_count === 0) {
                    jQuery('#reseed-content').prepend('<div class="notice notice-info"><p><?php _e('No unclaimed prizes found to reseed.', 'britvic-promo'); ?></p></div>');
                }
            } else {
                jQuery('#reseed-content').html('<div class="notice notice-error"><p>' +
                    (response.data ? response.data.message : '<?php _e('Unknown error occurred', 'britvic-promo'); ?>') +
                    '</p></div>');
                jQuery('#reseed-content').show();
            }
        }).fail(function() {
            jQuery('#reseed-loading').hide();
            jQuery('#reseed-content').html('<div class="notice notice-error"><p><?php _e('Failed to load prize information. Please try again.', 'britvic-promo'); ?></p></div>');
            jQuery('#reseed-content').show();
        });
    }

    function performReseed(selectedIds) {
        selectedIds = selectedIds || [];
        var startDate = jQuery('#reseed-start-date').val();
        var endDate = jQuery('#reseed-end-date').val();
        var startTime = jQuery('#reseed-start-time').val();
        var endTime = jQuery('#reseed-end-time').val();

        if (!startDate || !endDate) {
            alert('<?php _e('Please fill in all required fields.', 'britvic-promo'); ?>');
            return;
        }

        if (startDate > endDate) {
            alert('<?php _e('End date cannot be before start date.', 'britvic-promo'); ?>');
            return;
        }

        // Disable the button and show loading
        var confirmBtn = jQuery('#reseed-confirm-btn');
        confirmBtn.prop('disabled', true).text('<?php _e('Reseeding...', 'britvic-promo'); ?>');

        var postData = {
            action: 'bpm_reseed_prizes',
            competition_id: <?php echo $selected_competition; ?>,
            start_date: startDate,
            end_date: endDate,
            start_time: startTime,
            end_time: endTime,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        };

        // Attach selected IDs when in selected mode
        if (selectedIds.length > 0) {
            jQuery.each(selectedIds, function(i, id) {
                postData['prize_ids[' + i + ']'] = id;
            });
        }

        jQuery.post(ajaxurl, postData, function(response) {
            if (response.success) {
                alert('<?php _e('Successfully reseeded', 'britvic-promo'); ?> ' + response.data.reseeded_count + ' <?php _e('prizes!', 'britvic-promo'); ?>' +
                    (response.data.failed_count > 0 ? '\n' + response.data.failed_count + ' <?php _e('prizes failed to reseed.', 'britvic-promo'); ?>' : ''));
                jQuery('#reseed-dialog').dialog('close');
                location.reload();
            } else {
                alert('<?php _e('Reseeding failed:', 'britvic-promo'); ?> ' + (response.data ? response.data.message : '<?php _e('Unknown error occurred', 'britvic-promo'); ?>'));
            }
            confirmBtn.prop('disabled', false).text('<?php _e('Reseed Prizes', 'britvic-promo'); ?>');
        }).fail(function() {
            alert('<?php _e('Reseeding failed. Please try again.', 'britvic-promo'); ?>');
            confirmBtn.prop('disabled', false).text('<?php _e('Reseed Prizes', 'britvic-promo'); ?>');
        });
    }
    
    // Inline editing functionality
    var editingCell = null;
    
    // Double-click for regular fields (using event delegation)
    jQuery(document).on('dblclick', '.editable:not(.winner-field)', function() {
        if (editingCell) return; // Already editing
        startFieldEdit(jQuery(this));
    });
    
    // Single-click for winner fields (using event delegation)
    jQuery(document).on('click', '.winner-field', function(e) {
        console.log('Winner field clicked:', this);
        console.log('Event target:', e.target);
        console.log('Has save-field class:', jQuery(e.target).hasClass('save-field'));
        console.log('Has cancel-field class:', jQuery(e.target).hasClass('cancel-field'));
        
        // Don't start editing if clicking on a button
        if (jQuery(e.target).hasClass('save-field') || jQuery(e.target).hasClass('cancel-field')) {
            console.log('Clicking on button, not starting edit');
            return;
        }
        if (editingCell) {
            console.log('Already editing another cell');
            return; // Already editing
        }
        console.log('Starting field edit...');
        startFieldEdit(jQuery(this));
    });
    
    function startFieldEdit(cell) {
        console.log('startFieldEdit called with cell:', cell);
        editingCell = cell;
        var field = cell.data('field');
        var currentValue = extractCurrentValue(cell, field);
        var isWinnerField = cell.hasClass('winner-field');
        
        console.log('Field:', field);
        console.log('Current value:', currentValue);
        console.log('Is winner field:', isWinnerField);
        
        var input = createInput(field, currentValue);
        var container = jQuery('<div class="edit-container"></div>');
        container.append(input);
        
        if (isWinnerField) {
            // Add save/cancel buttons for winner fields
            var saveBtn = jQuery('<button type="button" class="button button-small save-field" title="Save">✓</button>');
            var cancelBtn = jQuery('<button type="button" class="button button-small cancel-field" title="Cancel">✗</button>');
            container.append(saveBtn).append(cancelBtn);
            
            saveBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var newValue = input.inputElement ? input.inputElement.val() : input.val();
                console.log('Save button clicked for field:', field);
                console.log('Input element:', input);
                console.log('Current value from input:', newValue);
                console.log('Original value:', currentValue);
                console.log('Prize ID:', cell.closest('tr').data('prize-id'));
                
                if (newValue !== currentValue) {
                    savePrizeField(cell.closest('tr').data('prize-id'), field, newValue, cell, currentValue);
                } else {
                    console.log('Value unchanged, canceling edit');
                    cancelEdit(cell, field, currentValue);
                }
            });
            
            cancelBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                cancelEdit(cell, field, currentValue);
            });
        } else {
            // For regular fields, save on blur or enter
            input.on('blur keypress', function(e) {
                if (e.type === 'keypress' && e.which !== 13) return;
                
                var newValue = input.inputElement ? input.inputElement.val() : input.val();
                if (newValue !== currentValue) {
                    savePrizeField(cell.closest('tr').data('prize-id'), field, newValue, cell, currentValue);
                } else {
                    cancelEdit(cell, field, currentValue);
                }
            });
        }
        
        // Cancel on escape for all fields
        input.on('keyup', function(e) {
            if (e.which === 27) {
                cancelEdit(cell, field, currentValue);
            }
        });
        
        cell.html(container);
        input.focus().select();
    }
    
    function extractCurrentValue(cell, field) {
        if (field === 'winner_id') {
            var gfLink = cell.find('.gf-entry-link a');
            if (gfLink.length) {
                return gfLink.text().replace('#', '');
            } else {
                return cell.text().replace('Click to add', '').trim();
            }
        } else if (field === 'winner_email') {
            var emailLink = cell.find('.email-link a');
            if (emailLink.length) {
                return emailLink.text();
            } else {
                return cell.text().replace('Click to add', '').trim();
            }
        } else {
            return cell.text().trim();
        }
    }
    
    function createInput(field, currentValue) {
        var input;
        if (field === 'date' || field === 'win_date_auto' || field === 'win_date_from_file' || field === 'prize_date' || field === 'date_available') {
            input = jQuery('<input type="datetime-local" class="edit-input" />');
            
            if (currentValue) {
                // Manually parse the UK date format (dd-mm-yyyy hh:mm AM/PM)
                var parts = currentValue.match(/(\d{2})-(\d{2})-(\d{4})\s+(\d{1,2}):(\d{2})\s+(AM|PM)/);
                if (parts) {
                    var day = parts[1];
                    var month = parts[2];
                    var year = parts[3];
                    var hour = parseInt(parts[4]);
                    var minute = parts[5];
                    var ampm = parts[6];

                    if (ampm === 'PM' && hour < 12) {
                        hour += 12;
                    }
                    if (ampm === 'AM' && hour === 12) {
                        hour = 0;
                    }

                    // Construct a valid ISO-like string for Date constructor
                    var isoDateString = `${year}-${month}-${day}T${String(hour).padStart(2, '0')}:${minute}:00`;
                    var dateValue = new Date(isoDateString).toISOString().slice(0, 16);
                    input.val(dateValue);
                } else {
                    // Fallback for other date formats or if parsing fails
                    var dateValue = new Date(currentValue).toISOString().slice(0, 16);
                    input.val(dateValue);
                }
            }
            
            return input;
        } else if (field === 'winner_email') {
            input = jQuery('<input type="email" class="edit-input" placeholder="Enter email address" />');
            input.val(currentValue);
        } else if (field === 'winner_id') {
            input = jQuery('<input type="text" class="edit-input" placeholder="Enter winner ID" />');
            input.val(currentValue);
        } else {
            input = jQuery('<input type="text" class="edit-input" />');
            input.val(currentValue);
        }
        return input;
    }
    
    function cancelEdit(cell, field, originalValue) {
        console.log('Canceling edit for field:', field, 'with value:', originalValue);
        restoreFieldValue(cell, field, originalValue);
        editingCell = null;
    }
    
    // Message display helper functions
    function showActionMessage(prizeId, message, type) {
        var messageArea = jQuery('#action-message-' + prizeId);
        messageArea.removeClass('success error loading').addClass(type);
        messageArea.text(message).fadeIn();
        
        // Auto-hide success messages after 3 seconds
        if (type === 'success') {
            setTimeout(function() {
                hideActionMessage(prizeId);
            }, 3000);
        }
    }
    
    function hideActionMessage(prizeId) {
        var messageArea = jQuery('#action-message-' + prizeId);
        messageArea.fadeOut(function() {
            jQuery(this).removeClass('success error loading').text('');
        });
    }
    
    // Toggle claim status (using event delegation for dynamic content)
    jQuery(document).on('click', '.edit-prize', function() {
        var prizeId = jQuery(this).data('prize-id');
        var button = jQuery(this);
        var row = button.closest('tr');
        // The "Mark Claimed / Mark Available" toggle now updates the Claimed
        // column (Yes/No), not the Status column (which shows Stripe payout
        // state). The .bpm-status-claimed / -available CSS class names are
        // unchanged — they just live in a different cell now.
        var statusCell = row.find('td.column-claimed');
        var currentStatus = row.find('.bpm-status-claimed').length > 0 ? 'claimed' : 'available';
        var newStatus = currentStatus === 'claimed' ? 'available' : 'claimed';
        
        // Show loading message in the dedicated area
        showActionMessage(prizeId, '<?php _e('Updating...', 'britvic-promo'); ?>', 'loading');
        
        button.prop('disabled', true).css('opacity', '0.6');
        
        jQuery.post(ajaxurl, {
            action: 'bpm_toggle_prize_claim',
            prize_id: prizeId,
            status: newStatus,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            console.log('Toggle response:', response);
            console.log('New status:', newStatus);
            console.log('Status cell:', statusCell);
            
            if (response.success) {
                // Update status display without refresh
                if (newStatus === 'claimed') {
                    statusCell.html('<span class="bpm-status-claimed"><?php _e('Yes', 'britvic-promo'); ?></span>');
                    // Update icon and tooltip
                    button.attr('title', '<?php _e('Mark Available', 'britvic-promo'); ?>')
                          .find('.dashicons').removeClass('dashicons-lock').addClass('dashicons-unlock');
                    // Show success message
                    showActionMessage(prizeId, '<?php _e('Marked as claimed', 'britvic-promo'); ?>', 'success');
                    console.log('Updated to claimed');
                } else {
                    statusCell.html('<span class="bpm-status-available"><?php _e('No', 'britvic-promo'); ?></span>');
                    // Update icon and tooltip  
                    button.attr('title', '<?php _e('Mark Claimed', 'britvic-promo'); ?>')
                          .find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                    // Clear winner fields when marking as available
                    row.find('[data-field="winner_id"]').html('<span class="bpm-no-winner">Click to add</span>');
                    row.find('[data-field="winner_email"]').html('<span class="bpm-no-winner">Click to add</span>');
                    // Show success message
                    showActionMessage(prizeId, '<?php _e('Marked as available', 'britvic-promo'); ?>', 'success');
                    console.log('Updated to available');
                }
                
                // Update claimed count if provided
                if (response.data && response.data.claimed_count !== undefined) {
                    var totalPrizes = parseInt(jQuery('#total-prizes').text().replace(/,/g, ''));
                    var claimedCount = response.data.claimed_count;
                    var availableCount = totalPrizes - claimedCount;
                    var claimRate = totalPrizes > 0 ? ((claimedCount / totalPrizes) * 100).toFixed(1) : '0';
                    
                    jQuery('#claimed-count').text(claimedCount.toLocaleString());
                    jQuery('.available-count').text(availableCount.toLocaleString());
                    jQuery('.stat-value:contains("%")').text(claimRate + '%');
                    
                    console.log('Updated counts - Claimed:', claimedCount, 'Available:', availableCount);
                }
            } else {
                console.log('Error:', response.data);
                console.log('Full response:', response);
                // Show error message
                showActionMessage(prizeId, '<?php _e('Failed to update status:', 'britvic-promo'); ?> ' + (response.data ? response.data.message : ''), 'error');
            }
            // Restore button state
            button.prop('disabled', false).css('opacity', '1');
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.log('AJAX failed:', textStatus, errorThrown);
            console.log('Response text:', jqXHR.responseText);
            console.log('Status:', jqXHR.status);
            // Show error message  
            showActionMessage(prizeId, '<?php _e('Network error occurred', 'britvic-promo'); ?>', 'error');
            button.prop('disabled', false).css('opacity', '1');
        });
    });
    
    // Reset claim functionality (using event delegation)
    jQuery(document).on('click', '.reset-claim', function() {
        console.log('Reset button clicked');
        if (confirm('<?php _e('Are you sure you want to reset this claim? This will make the prize available again.', 'britvic-promo'); ?>')) {
            var prizeId = jQuery(this).data('prize-id');
            var jQuerybutton = jQuery(this);
            
            console.log('Resetting prize ID:', prizeId);
            
            // Show loading message
            showActionMessage(prizeId, '<?php _e('Resetting...', 'britvic-promo'); ?>', 'loading');
            
            // Show loading state
            jQuerybutton.prop('disabled', true)
                   .attr('title', '<?php _e('Resetting...', 'britvic-promo'); ?>')
                   .css('opacity', '0.6')
                   .find('.dashicons')
                   .removeClass('dashicons-undo')
                   .addClass('dashicons-update')
                   .css('animation', 'spin 1s linear infinite');
            
            jQuery.post(ajaxurl, {
                action: 'bpm_reset_prize_claim',
                prize_id: prizeId,
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
            }, function(response) {
                console.log('Reset response received:', response);
                
                if (response.success) {
                    console.log('Reset successful, response.data:', response.data);
                    
                    // Update the prize row directly instead of reloading
                    var jQueryrow = jQuery('tr[data-prize-id="' + prizeId + '"]');
                    console.log('Found row:', jQueryrow.length, 'Row element:', jQueryrow[0]);
                    
                    if (jQueryrow.length > 0) {
                        console.log('Updating UI elements...');
                        
                        // Update claimed cell - find by class. The reset-claim
                        // action flips the Claimed column back to No (slot
                        // becomes available); the Status column shows Stripe
                        // payout state and stays as it was.
                        var jQuerystatusCell = jQueryrow.find('td.column-claimed');
                        console.log('Claimed cell found:', jQuerystatusCell.length, 'Current content:', jQuerystatusCell.html());
                        jQuerystatusCell.html('<span class="bpm-status-available"><?php _e('No', 'britvic-promo'); ?></span>');
                        console.log('Status cell updated to:', jQuerystatusCell.html());
                        
                        // Clear winner ID cell - find by class
                        var jQuerywinnerIdCell = jQueryrow.find('td.editable.column-winner_id');
                        console.log('Winner ID cell found:', jQuerywinnerIdCell.length);
                        jQuerywinnerIdCell.html('<span class="bpm-no-winner">Click to add</span>');
                        
                        // Clear winner email cell - find by class
                        var jQuerywinnerEmailCell = jQueryrow.find('td.editable.column-winner_email');
                        console.log('Winner email cell found:', jQuerywinnerEmailCell.length);
                        jQuerywinnerEmailCell.html('<span class="bpm-no-winner">Click to add</span>');
                        
                        // Update action buttons - find by class
                        var jQueryactionCell = jQueryrow.find('td.column-actions');
                        console.log('Action cell found:', jQueryactionCell.length);
                        var jQueryresetButton = jQueryactionCell.find('.reset-claim');
                        var jQueryeditButton = jQueryactionCell.find('.edit-prize');
                        console.log('Reset button found:', jQueryresetButton.length, 'Edit button found:', jQueryeditButton.length);
                        
                        jQueryresetButton.remove();
                        // Update edit button icon and tooltip
                        jQueryeditButton.attr('title', '<?php _e('Mark Claimed', 'britvic-promo'); ?>')
                                   .find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                        
                        console.log('UI update completed');
                        
                        // Show success message
                        showActionMessage(prizeId, '<?php _e('Reset successfull!', 'britvic-promo'); ?>', 'success');
                    } else {
                        console.log('Row not found, attempting alternative selector');
                        // Try alternative approach - maybe the data attribute is different
                        var jQueryallRows = jQuery('tr[data-prize-id]');
                        console.log('All rows with data-prize-id:', jQueryallRows.length);
                        jQueryallRows.each(function() {
                            console.log('Row prize ID:', jQuery(this).data('prize-id'));
                        });
                        
                        // Fallback to reload
                        console.log('Falling back to reload');
                        location.reload();
                    }
                } else {
                    console.log('Reset failed:', response.data);
                    // Restore button state on error
                    jQuerybutton.prop('disabled', false)
                           .attr('title', '<?php _e('Reset Claim', 'britvic-promo'); ?>')
                           .css('opacity', '1')
                           .find('.dashicons')
                           .removeClass('dashicons-update')
                           .addClass('dashicons-undo')
                           .css('animation', 'none');
                    // Show error message
                    showActionMessage(prizeId, '<?php _e('Failed to reset claim:', 'britvic-promo'); ?> ' + response.data.message, 'error');
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.log('Reset AJAX failed:', textStatus, errorThrown);
                console.log('Response text:', jqXHR.responseText);
                // Restore button state on AJAX failure
                jQuerybutton.prop('disabled', false)
                       .attr('title', '<?php _e('Reset Claim', 'britvic-promo'); ?>')
                       .css('opacity', '1')
                       .find('.dashicons')
                       .removeClass('dashicons-update')
                       .addClass('dashicons-undo')
                       .css('animation', 'none');
                // Show error message
                showActionMessage(prizeId, '<?php _e('AJAX request failed:', 'britvic-promo'); ?> ' + textStatus, 'error');
            });
        }
    });
    
    // View history functionality (using event delegation)
    jQuery(document).on('click', '.view-history', function() {
        var prizeId = jQuery(this).data('prize-id');
        
        jQuery.post(ajaxurl, {
            action: 'bpm_get_prize_history',
            prize_id: prizeId,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                showPrizeHistory(response.data);
            } else {
                showActionMessage(prizeId, '<?php _e('Failed to load history:', 'britvic-promo'); ?> ' + response.data.message, 'error');
            }
        });
    });
    
    function savePrizeField(prizeId, field, value, cell, originalValue) {
        console.log('Saving field:', field, 'with value:', value, 'for prize:', prizeId);
        
        jQuery.post(ajaxurl, {
            action: 'bpm_update_prize_field',
            prize_id: prizeId,
            field: field,
            value: value,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            console.log('Save response:', response);
            
            if (response.success) {
                console.log('Field saved successfully, restoring value:', value);
                restoreFieldValue(cell, field, value);
                cell.addClass('updated');
                setTimeout(function() {
                    cell.removeClass('updated');
                }, 2000);
                
                // Use the prize status from the server response for accurate updates
                if (response.data.prize_status && response.data.claimed !== undefined) {
                    var row = cell.closest('tr');
                    // Inline edits that flip claim state update the Claimed
                    // column (Yes/No), not the Status column (which shows
                    // Stripe payout state).
                    var statusCell = row.find('td.column-claimed');
                    var button = row.find('.edit-prize');
                    var actionCell = row.find('td.column-actions');

                    if (response.data.claimed === 'true') {
                        statusCell.html('<span class="bpm-status-claimed"><?php _e('Yes', 'britvic-promo'); ?></span>');
                        button.attr('title', '<?php _e('Mark Available', 'britvic-promo'); ?>');
                        button.find('.dashicons').removeClass('dashicons-lock').addClass('dashicons-unlock');
                        // Add reset button if it doesn't exist
                        if (actionCell.find('.reset-claim').length === 0) {
                            button.after('<button type="button" class="bpm-icon-button reset-claim" data-prize-id="' + prizeId + '" title="<?php _e('Reset Claim', 'britvic-promo'); ?>"><span class="dashicons dashicons-undo"></span></button>');
                        }
                    } else {
                        statusCell.html('<span class="bpm-status-available"><?php _e('No', 'britvic-promo'); ?></span>');
                        button.attr('title', '<?php _e('Mark Claimed', 'britvic-promo'); ?>');
                        button.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                        // Remove reset button if it exists
                        actionCell.find('.reset-claim').remove();
                    }
                }
            } else {
                console.log('Save failed:', response.data);
                // Extract prize ID for message display
                var row = cell.closest('tr');
                var prizeId = row.find('.edit-prize').data('prize-id');
                showActionMessage(prizeId, '<?php _e('Failed to update:', 'britvic-promo'); ?> ' + response.data.message, 'error');
                restoreFieldValue(cell, field, originalValue || value);
            }
            editingCell = null;
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.log('Save AJAX failed:', textStatus, errorThrown);
            console.log('Response text:', jqXHR.responseText);
            // Extract prize ID for message display
            var row = cell.closest('tr');
            var prizeId = row.find('.edit-prize').data('prize-id');
            showActionMessage(prizeId, '<?php _e('AJAX request failed:', 'britvic-promo'); ?> ' + textStatus, 'error');
            restoreFieldValue(cell, field, originalValue || value);
            editingCell = null;
        });
    }
    
    function restoreFieldValue(cell, field, value) {
        console.log('Restoring field value:', field, 'with value:', value);
        
        if (field === 'date' || field === 'win_date_auto' || field === 'win_date_from_file' || field === 'prize_date' || field === 'date_available') {
            var displayDate = value ? formatUKDate(value) : '';
            cell.text(displayDate);
        } else if (field === 'winner_id') {
            if (value && value.trim()) {
                var gfUrl = '<?php echo admin_url('admin.php?page=gf_entries&view=entry&id='); ?>' + winnerFormId + '&lid=' + value;
                cell.html('<span class="gf-entry-link"><a href="' + gfUrl + '" target="_blank">#' + value + '</a></span>');
            } else {
                cell.html('<span class="bpm-no-winner">Click to add</span>');
            }
        } else if (field === 'winner_email') {
            if (value && value.trim()) {
                cell.html('<span class="email-link"><a href="mailto:' + value + '">' + value + '</a></span>');
            } else {
                cell.html('<span class="bpm-no-winner">Click to add</span>');
            }
        } else {
            cell.text(value || '');
        }
    }
    
    function showPrizeHistory(history) {
        var modal = jQuery('<div class="bpm-modal"><div class="bpm-modal-content"></div></div>');
        var content = '<h3><?php _e('Prize History', 'britvic-promo'); ?></h3>';
        
        if (history.audit_trail && history.audit_trail.length > 0) {
            content += '<table class="bpm-history-table">';
            content += '<thead><tr><th><?php _e('Action', 'britvic-promo'); ?></th><th><?php _e('Details', 'britvic-promo'); ?></th><th><?php _e('Date', 'britvic-promo'); ?></th></tr></thead>';
            content += '<tbody>';
            
            history.audit_trail.forEach(function(entry) {
                content += '<tr>';
                content += '<td>' + entry.action + '</td>';
                content += '<td>';
                if (entry.original_date && entry.new_date) {
                    content += '<?php _e('From:', 'britvic-promo'); ?> ' + entry.original_date + '<br><?php _e('To:', 'britvic-promo'); ?> ' + entry.new_date;
                }
                content += '</td>';
                content += '<td>' + entry.timestamp + '</td>';
                content += '</tr>';
            });
            
            content += '</tbody></table>';
        } else {
            content += '<p><?php _e('No history available for this prize.', 'britvic-promo'); ?></p>';
        }
        
        content += '<div class="bpm-stats">';
        content += '<p><strong><?php _e('Reseeded Count:', 'britvic-promo'); ?></strong> ' + history.reseeded_count + '</p>';
        if (history.last_reseeded) {
            content += '<p><strong><?php _e('Last Reseeded:', 'britvic-promo'); ?></strong> ' + history.last_reseeded + '</p>';
        }
        content += '</div>';
        
        content += '<button type="button" class="button close-modal"><?php _e('Close', 'britvic-promo'); ?></button>';
        
        modal.find('.bpm-modal-content').html(content);
        jQuery('body').append(modal);
        
        modal.on('click', '.close-modal, .bpm-modal', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    }
    
    // Enable/disable bulk delete button based on selection
    jQuery('#bulk-delete-type').on('change', function() {
        var button = jQuery('#bulk-delete-prizes');
        if (jQuery(this).val()) {
            button.prop('disabled', false);
        } else {
            button.prop('disabled', true);
        }
    });
    
    // Bulk delete prizes
    jQuery('#bulk-delete-prizes').on('click', function() {
        var deleteType = jQuery('#bulk-delete-type').val();
        if (!deleteType) return;
        
        var confirmMessage = '';
        switch(deleteType) {
            case 'all':
                confirmMessage = '<?php _e('Are you sure you want to delete ALL prizes for this competition? This action cannot be undone.', 'britvic-promo'); ?>';
                break;
            case 'unclaimed':
                confirmMessage = '<?php _e('Are you sure you want to delete all UNCLAIMED prizes? This action cannot be undone.', 'britvic-promo'); ?>';
                break;
            case 'claimed':
                confirmMessage = '<?php _e('Are you sure you want to delete all CLAIMED prizes? This action cannot be undone.', 'britvic-promo'); ?>';
                break;
        }
        
        if (confirm(confirmMessage)) {
            var button = jQuery(this);
            button.prop('disabled', true).text('<?php _e('Deleting...', 'britvic-promo'); ?>');
            
            jQuery.post(ajaxurl, {
                action: 'bpm_bulk_delete_prizes',
                competition_id: <?php echo $selected_competition; ?>,
                delete_type: deleteType,
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('<?php _e('Bulk delete failed:', 'britvic-promo'); ?> ' + response.data.message);
                }
                button.prop('disabled', false).text('<?php _e('Delete Selected', 'britvic-promo'); ?>');
            }).fail(function() {
                alert('<?php _e('Request failed. Please try again.', 'britvic-promo'); ?>');
                button.prop('disabled', false).text('<?php _e('Delete Selected', 'britvic-promo'); ?>');
            });
        }
    });
    
    // Individual prize deletion (using event delegation)
    jQuery(document).on('click', '.delete-prize', function() {
        var prizeId = jQuery(this).data('prize-id');
        var row = jQuery(this).closest('tr');
        var prizeCode = row.find('[data-field="code"]').text();
        var prizeType = row.find('[data-field="prize"]').text();
        
        var confirmMessage = '<?php _e('Are you sure you want to delete this prize?', 'britvic-promo'); ?>\n\n' +
                           '<?php _e('Code:', 'britvic-promo'); ?> ' + prizeCode + '\n' +
                           '<?php _e('Prize:', 'britvic-promo'); ?> ' + prizeType + '\n\n' +
                           '<?php _e('This action cannot be undone.', 'britvic-promo'); ?>';
        
        if (confirm(confirmMessage)) {
            var button = jQuery(this);
            
            // Show loading message
            showActionMessage(prizeId, '<?php _e('Deleting...', 'britvic-promo'); ?>', 'loading');
            
            button.prop('disabled', true).css('opacity', '0.6');
            
            jQuery.post(ajaxurl, {
                action: 'bpm_delete_prize',
                prize_id: prizeId,
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    // Show success message
                    showActionMessage(prizeId, '<?php _e('Prize deleted successfully!', 'britvic-promo'); ?>', 'success');
                    
                    // Remove the row from the table
                    setTimeout(function() {
                        row.fadeOut(300, function() {
                        jQuery(this).remove();
                        
                        // Update counts
                        var currentTotal = parseInt(jQuery('#total-prizes').text().replace(/,/g, ''));
                        var newTotal = currentTotal - 1;
                        jQuery('#total-prizes').text(newTotal.toLocaleString());
                        
                        // If this was claimed, update claimed count
                        if (row.find('.bpm-status-claimed').length > 0) {
                            var currentClaimed = parseInt(jQuery('#claimed-count').text().replace(/,/g, ''));
                            var newClaimed = currentClaimed - 1;
                            jQuery('#claimed-count').text(newClaimed.toLocaleString());
                        }
                        
                        // Update available count
                        var newAvailable = newTotal - parseInt(jQuery('#claimed-count').text().replace(/,/g, ''));
                        jQuery('.available-count').text(newAvailable.toLocaleString());
                        
                        // Update claim rate
                        var claimedCount = parseInt(jQuery('#claimed-count').text().replace(/,/g, ''));
                        var claimRate = newTotal > 0 ? ((claimedCount / newTotal) * 100).toFixed(1) : '0';
                        jQuery('.stat-value:contains("%")').text(claimRate + '%');
                        
                        // Check if table is now empty
                            if (jQuery('tbody tr').length === 0) {
                                location.reload(); // Reload to show "no prizes" message
                            }
                        });
                    }, 1000); // Wait 1 second to show success message
                } else {
                    // Show error message
                    showActionMessage(prizeId, '<?php _e('Delete failed:', 'britvic-promo'); ?> ' + response.data.message, 'error');
                    button.prop('disabled', false).css('opacity', '1');
                }
            }).fail(function() {
                // Show error message
                showActionMessage(prizeId, '<?php _e('Request failed. Please try again.', 'britvic-promo'); ?>', 'error');
                button.prop('disabled', false).css('opacity', '1');
            });
        }
    });
    
    // Show/hide custom prize field
    // Show/hide winner details based on status
    jQuery('#single-prize-status').on('change', function() {
        var jQuerywinnerRow = jQuery('#winner-details-row');
        if (jQuery(this).val() === 'claimed') {
            jQuerywinnerRow.show();
        } else {
            jQuerywinnerRow.hide();
        }
    });
    
    // Clear single prize form
    jQuery('#clear-single-prize-form').on('click', function() {
        jQuery('#single-prize-codes-container').html(`
            <div class="single-prize-code-group" style="display: flex; gap: 5px; margin-bottom: 5px;">
                <input type="text" class="single-prize-code-input regular-text" placeholder="e.g., DRINK001, BP2024" style="flex: 1;" />
                <button type="button" class="button remove-single-code-btn" style="display: none;"><?php _e('−', 'britvic-promo'); ?></button>
            </div>
        `);
        jQuery('#single-prize-category').val('');
        jQuery('#single-prize-type').val('');
        jQuery('#single-prize-date').val('<?php echo date('Y-m-d\TH:i'); ?>');
        jQuery('#single-prize-status').val('available');
        jQuery('#single-prize-winner-email').val('');
        jQuery('#single-prize-winner-id').val('');
        jQuery('#winner-details-row').hide();
    });
    
    // Add single prize
    jQuery('#add-single-prize').on('click', function() {
        // Collect all prize codes
        var prizeCodes = [];
        jQuery('.single-prize-code-input').each(function() {
            var code = jQuery(this).val().trim();
            if (code) {
                prizeCodes.push(code);
            }
        });
        
        var prizeCategory = jQuery('#single-prize-category').val();
        var prizeName = jQuery('#single-prize-type').val().trim();
        var prizeDate = jQuery('#single-prize-date').val();
        var prizeStatus = jQuery('#single-prize-status').val();
        var sourceRoute = jQuery('#single-prize-source-route').val() || 'both';
        var winnerEmail = jQuery('#single-prize-winner-email').val().trim();
        var winnerId = jQuery('#single-prize-winner-id').val().trim();

        // Validation
        if (prizeCodes.length === 0) {
            alert('<?php _e('Please enter at least one prize code', 'britvic-promo'); ?>');
            jQuery('.single-prize-code-input').first().focus();
            return;
        }

        if (!prizeCategory) {
            alert('<?php _e('Please select a prize type (cash or merch)', 'britvic-promo'); ?>');
            jQuery('#single-prize-category').focus();
            return;
        }

        if (!prizeName) {
            alert('<?php _e('Please enter a prize name', 'britvic-promo'); ?>');
            jQuery('#single-prize-type').focus();
            return;
        }
        
        var button = jQuery(this);
        var originalText = button.text();
        button.text('<?php _e('Adding...', 'britvic-promo'); ?>').prop('disabled', true);
        
        jQuery.post(ajaxurl, {
            action: 'bpm_add_single_prize',
            competition_id: <?php echo $selected_competition; ?>,
            prize_codes: prizeCodes,
            prize_category: prizeCategory,
            prize_type: prizeName,
            win_datetime: prizeDate,
            initial_status: prizeStatus,
            source_route: sourceRoute,
            winner_email: winnerEmail,
            winner_id: winnerId,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                jQuery('#single-prize-status').html('<span style="color: green;">' + response.data.message + '</span>');
                jQuery('#clear-single-prize-form').click(); // Clear the form
                // Refresh the page to show the new prize
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                jQuery('#single-prize-status').html('<span style="color: red;"><?php _e('Error:', 'britvic-promo'); ?> ' + response.data.message + '</span>');
            }
        }).fail(function() {
            jQuery('#single-prize-status').html('<span style="color: red;"><?php _e('Request failed. Please try again.', 'britvic-promo'); ?></span>');
        }).always(function() {
            button.text(originalText).prop('disabled', false);
            setTimeout(function() {
                jQuery('#single-prize-status').html('');
            }, 5000);
        });
    });
    
    
    
    
    
    // Helper function for single prize code buttons
    function updateSinglePrizeCodeButtons() {
        var jQuerycodeGroups = jQuery('#single-prize-codes-container .single-prize-code-group');
        jQuerycodeGroups.each(function(index) {
            var jQueryremoveBtn = jQuery(this).find('.remove-single-code-btn');
            if (jQuerycodeGroups.length === 1) {
                jQueryremoveBtn.hide();
            } else {
                jQueryremoveBtn.show();
            }
        });
    }
    
    // Add code functionality for individual prize
    jQuery('#add-single-code-btn').on('click', function() {
        var newCodeInput = `
            <div class="single-prize-code-group" style="display: flex; gap: 5px; margin-bottom: 5px;">
                <input type="text" class="single-prize-code-input regular-text" placeholder="e.g., DRINK001, BP2024" style="flex: 1;" />
                <button type="button" class="button remove-single-code-btn"><?php _e('−', 'britvic-promo'); ?></button>
            </div>
        `;
        jQuery('#single-prize-codes-container').append(newCodeInput);
        updateSinglePrizeCodeButtons();
    });
    
    // Remove code functionality for individual prize
    jQuery(document).on('click', '.remove-single-code-btn', function() {
        jQuery(this).closest('.single-prize-code-group').remove();
        updateSinglePrizeCodeButtons();
    });
    

    
    function updateDateRangeSummary() {
        var startDate = jQuery('#start_date').val();
        var endDate = jQuery('#end_date').val();
        var startTime = jQuery('#start_time').val();
        var endTime = jQuery('#end_time').val();
        
        if (startDate && endDate && startTime && endTime) {
            var start = new Date(startDate + 'T' + startTime);
            var end = new Date(endDate + 'T' + endTime);
            var days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            jQuery('#date-range-summary').html(
                '<?php _e('Date Range:', 'britvic-promo'); ?> ' + startDate + ' ' + startTime + ' <?php _e('to', 'britvic-promo'); ?> ' + endDate + ' ' + endTime + 
                '<br><?php _e('Duration:', 'britvic-promo'); ?> ' + days + ' <?php _e('days', 'britvic-promo'); ?>'
            );
        } else {
            jQuery('#date-range-summary').text('<?php _e('Configure dates above to see summary', 'britvic-promo'); ?>');
        }
    }

    // Update date range summary when fields change
    jQuery(document).on('change', '#start_date, #end_date, #start_time, #end_time', updateDateRangeSummary);

    // Initialize date range summary
    updateDateRangeSummary();

    // File upload and column mapping functionality
    var uploadedFileData = null;
    var fileHeaders = [];
    var totalRowCount = 0;
    var savedPrizeTypes = <?php echo json_encode(isset($saved_prize_types) ? $saved_prize_types : []); ?>;

    // Handle file selection
    jQuery('#import_file').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        // Reset UI
        jQuery('#column-mapping, #import-preview, #import-controls, #prize-type-distribution').hide();
        jQuery('#auto-mapping-tbody, #prize-type-distribution-container').empty();

        if (file.type.includes('xlsx') || file.name.endsWith('.xlsx')) {
            processXLSXFile(file);
        } else if (file.type.includes('csv') || file.name.endsWith('.csv')) {
            processCSVFile(file);
        } else {
            alert('<?php _e('Please select a CSV or Excel file.', 'britvic-promo'); ?>');
        }
    });

    function processXLSXFile(file) {
        var formData = new FormData();
        formData.append('xlsx_file', file);
        formData.append('action', 'bpm_analyze_xlsx');
        formData.append('nonce', '<?php echo wp_create_nonce('bpm_nonce'); ?>');
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    fileHeaders = response.data.headers;
                    uploadedFileData = response.data.sample_data;
                    setupColumnMapping();
                } else {
                    alert('<?php _e('Error analyzing Excel file:', 'britvic-promo'); ?> ' + response.data.message);
                }
            },
            error: function() {
                alert('<?php _e('Error uploading file. Please try again.', 'britvic-promo'); ?>');
            }
        });
    }

    function processCSVFile(file) {
        var formData = new FormData();
        formData.append('csv_file', file);
        formData.append('action', 'bpm_analyze_csv');
        formData.append('nonce', '<?php echo wp_create_nonce('bpm_nonce'); ?>');
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    fileHeaders = response.data.headers;
                    uploadedFileData = response.data.sample_data;
                    totalRowCount = response.data.total_rows || uploadedFileData.length; // Use actual row count
                    setupColumnMapping();
                } else {
                    alert('<?php _e('Error analyzing CSV file:', 'britvic-promo'); ?> ' + response.data.message);
                }
            },
            error: function() {
                alert('<?php _e('Error uploading CSV file. Please try again.', 'britvic-promo'); ?>');
            }
        });
    }

    function setupColumnMapping() {
        if (!fileHeaders.length || !uploadedFileData) {
            alert('<?php _e('Could not process the file. No headers or data found.', 'britvic-promo'); ?>');
            return;
        }

        var rowCount = totalRowCount || uploadedFileData.length;

        // Show CSV info
        jQuery('#csv-row-count').html('<?php _e('File contains', 'britvic-promo'); ?> <strong>' + rowCount + '</strong> <?php _e('rows (prizes).', 'britvic-promo'); ?>');
        jQuery('#csv-info').show();

        // Show and configure Prize Date Generation
        jQuery('#prize-date-generation').show();
        jQuery('#num_prizes').val(rowCount);
        updateDateRangeSummary(); // Update summary based on default values

        // Setup prize distribution
        setupPrizeDistribution(rowCount);

        // Build the column mapping table
        buildColumnMappingTable();

        // Show the main sections
        jQuery('#column-mapping').show();
        jQuery('#import-controls').show();
    }

    function setupPrizeDistribution(rowCount) {
        var jQuerycontainer = jQuery('#prize-type-distribution-container');
        jQuerycontainer.empty();

        if (savedPrizeTypes && savedPrizeTypes.length > 0) {
            savedPrizeTypes.forEach(function(type) {
                var row = `
                    <div class="prize-type-dist-row" style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                        <label style="flex-basis: 150px; font-weight: 600;">${type.name}</label>
                        <input type="number" class="prize-type-dist-input" data-type-name="${type.name}" min="0" value="0" style="width: 100px;">
                    </div>
                `;
                jQuerycontainer.append(row);
            });
        } else {
            jQuerycontainer.html('<p><?php _e('No prize types configured. Please go to the main settings to configure prize types.', 'britvic-promo'); ?></p>');
        }

        jQuery('#total-csv-rows').text(rowCount);
        updateAllocationTotals(); // Initial call
        jQuery('#prize-type-distribution').show();
    }

    jQuery(document).on('input', '.prize-type-dist-input', updateAllocationTotals);

    function updateAllocationTotals() {
        var totalAllocated = 0;
        jQuery('.prize-type-dist-input').each(function() {
            totalAllocated += parseInt(jQuery(this).val()) || 0;
        });

        var totalRows = parseInt(jQuery('#total-csv-rows').text()) || 0;
        jQuery('#total-allocated-prizes').text(totalAllocated);

        var jQueryfeedback = jQuery('#allocation-feedback');
        if (totalAllocated > totalRows) {
            jQueryfeedback.text('<?php _e('Warning: Total allocated exceeds number of rows.', 'britvic-promo'); ?>').css('color', 'red');
        } else if (totalAllocated < totalRows) {
            jQueryfeedback.text('<?php _e('You still have prizes to allocate.', 'britvic-promo'); ?>').css('color', 'orange');
        } else {
            jQueryfeedback.text('<?php _e('All prizes allocated.', 'britvic-promo'); ?>').css('color', 'green');
        }
    }

    function buildColumnMappingTable() {
        var jQuerytbody = jQuery('#auto-mapping-tbody');
        jQuerytbody.empty();

        fileHeaders.forEach(function(header, index) {
            var sampleData = '<?php _e('N/A', 'britvic-promo'); ?>';
            if (uploadedFileData.length > 0 && uploadedFileData[0]) {
                if (Array.isArray(uploadedFileData[0])) {
                    sampleData = uploadedFileData[0][index] || '<?php _e('N/A', 'britvic-promo'); ?>';
                } else {
                    sampleData = uploadedFileData[0][header] || '<?php _e('N/A', 'britvic-promo'); ?>';
                }
            }

            var row = `<tr data-column-index="${index}">
                <td><input type="text" class="column-display-name" value="${header}" style="width: 100%; font-weight: bold;" placeholder="Column Name" /></td>
                <td>${buildFieldNameDropdown(header, sampleData)}</td>
                <td><em>${sampleData}</em></td>
                <td class="text-center"><input type="checkbox" class="include-column" checked></td>
            </tr>`;
            jQuerytbody.append(row);
        });
    }

    function buildFieldNameDropdown(header, sampleData) {
        var headerLower = header.toLowerCase();
        var select = '<select class="field-name-select" style="width: 100%;">';

        // Content-aware prize_type detection: a column literally named `prize`
        // that holds 'cash' or 'merch' in its sample row IS the prize_type
        // column, not a prize-name column. Without this, that CSV shape gets
        // auto-mapped to prize_name_from_distribution and the value is silently
        // overwritten if any distribution config is configured on the comp.
        var sampleLower = (typeof sampleData === 'string' ? sampleData : '').trim().toLowerCase();
        var sampleIsTypeValue = sampleLower === 'cash' || sampleLower === 'merch';
        var isPrizeTypeCol = headerLower === 'prize_type'
            || headerLower === 'type'
            || (headerLower === 'prize' && sampleIsTypeValue);
        var isPrizeValueCol = headerLower === 'prize_value' || headerLower === 'prize_name';
        select += '<option value="_ignore"><?php _e('Ignore this column', 'britvic-promo'); ?></option>';
        select += '<option value="code" ' + (headerLower.includes('code') ? 'selected' : '') + '><?php _e('Prize Code', 'britvic-promo'); ?></option>';
        select += '<option value="prize_type_from_file" ' + (isPrizeTypeCol ? 'selected' : '') + '><?php _e('Prize Type (from import: cash/merch)', 'britvic-promo'); ?></option>';
        select += '<option value="prize_name_from_file" ' + (isPrizeValueCol ? 'selected' : '') + '><?php _e('Prize Name (from import)', 'britvic-promo'); ?></option>';
        select += '<option value="prize_name_from_distribution" ' + (!isPrizeTypeCol && !isPrizeValueCol && headerLower.includes('prize') ? 'selected' : '') + '><?php _e('Prize Name (from Prize Type)', 'britvic-promo'); ?></option>';
        select += '<option value="value" ' + (!isPrizeValueCol && headerLower.includes('value') ? 'selected' : '') + '><?php _e('Prize Value', 'britvic-promo'); ?></option>';
        
        // If the column header looks like a date/time column, default to USING
        // the CSV value ('win_date_from_file'). The Date Generator is the path
        // for CSVs that don't carry dates and want random distribution; making
        // it the default silently overwrote real CSV dates with random ones.
        var isDateColumn = headerLower.includes('date') || headerLower.includes('time');
        select += '<option value="win_date_from_file" ' + (isDateColumn ? 'selected' : '') + '><?php _e('Date (from import)', 'britvic-promo'); ?></option>';
        select += '<option value="win_date_auto"><?php _e('Date (from Date Generator)', 'britvic-promo'); ?></option>';

        select += '<option value="_custom"><?php _e('Custom Field...', 'britvic-promo'); ?></option>';

        select += '</select>';
        select += '<input type="text" class="custom-field-name" style="display:none; width: 100%; margin-top: 5px;" placeholder="<?php _e('Enter custom field name', 'britvic-promo'); ?>">';
        
        return select;
    }

    jQuery(document).on('change', '.field-name-select', function() {
        var jQuerythis = jQuery(this);
        var jQuerycustomInput = jQuerythis.siblings('.custom-field-name');
        var distributionInfo = jQuerythis.siblings('.prize-distribution-info');
        
        if (jQuerythis.val() === '_custom') {
            jQuerycustomInput.show().focus();
            distributionInfo.hide();
        } else {
            jQuerycustomInput.hide();
            
            // Show info for Prize Type Distribution option
            if (jQuerythis.val() === 'prize_name_from_distribution') {
                if (distributionInfo.length === 0) {
                    distributionInfo = jQuery('<div class="prize-distribution-info" style="margin-top: 5px; padding: 8px; background: #e7f3ff; border: 1px solid #0073aa; border-radius: 3px; font-size: 12px;"><strong><?php _e('Note:', 'britvic-promo'); ?></strong> <?php _e('Prize names will be distributed according to your Prize Type Distribution configuration below.', 'britvic-promo'); ?></div>');
                    jQuerythis.after(distributionInfo);
                }
                distributionInfo.show();
            } else {
                distributionInfo.hide();
            }
        }
    });

    
    // Add custom field functionality
    jQuery(document).on('click', '#add-custom-field-btn', function() {
        var tbody = jQuery('#auto-mapping-tbody');
        var customFieldCount = tbody.find('tr[data-custom="true"]').length + 1;
        
        var row = jQuery('<tr data-custom="true"></tr>');
        
        // Column Name (editable)
        var columnNameCell = jQuery('<td></td>');
        var columnNameInput = jQuery('<input type="text" class="custom-column-name regular-text" placeholder="<?php _e('Custom Field Name', 'britvic-promo'); ?>" value="<?php _e('Custom Field', 'britvic-promo'); ?> ' + customFieldCount + '" style="width: 100%; font-weight: bold; color: #0073aa;">');
        columnNameCell.append(columnNameInput);
        row.append(columnNameCell);
        
        // Field Type Selection
        var fieldCell = jQuery('<td></td>');
        var fieldSelect = jQuery('<select class="field-name-select" style="width: 100%;"></select>');
        fieldSelect.append('<option value="_ignore"><?php _e('Ignore this field', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="code"><?php _e('Prize Code', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="prize_type_from_file"><?php _e('Prize Type (from import: cash/merch)', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="prize_name_from_file" selected><?php _e('Prize Name (from import)', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="prize_name_from_distribution"><?php _e('Prize Name (from Prize Type)', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="value"><?php _e('Prize Value', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="win_date_from_file"><?php _e('Win Date (from import)', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="win_date_auto"><?php _e('Win Date (auto-generate)', 'britvic-promo'); ?></option>');
        fieldSelect.append('<option value="_custom"><?php _e('Custom Field...', 'britvic-promo'); ?></option>');
        
        var customFieldInput = jQuery('<input type="text" class="custom-field-name" style="display:none; width: 100%; margin-top: 5px;" placeholder="<?php _e('Enter custom field name', 'britvic-promo'); ?>">');
        fieldCell.append(fieldSelect);
        fieldCell.append(customFieldInput);
        row.append(fieldCell);
        
        // Default Value Input
        var valueCell = jQuery('<td></td>');
        var valueInput = jQuery('<input type="text" class="custom-value-input regular-text" placeholder="<?php _e('Enter default value (optional)', 'britvic-promo'); ?>" style="width: 100%;">');
        valueCell.append(valueInput);
        row.append(valueCell);
        
        // Include Checkbox and Remove Button
        var actionCell = jQuery('<td class="text-center"></td>');
        var includeCheckbox = jQuery('<input type="checkbox" class="include-column" checked style="margin-right: 10px;">');
        var removeBtn = jQuery('<button type="button" class="button button-small remove-custom-field"><?php _e('Remove', 'britvic-promo'); ?></button>');
        actionCell.append(includeCheckbox);
        actionCell.append(removeBtn);
        row.append(actionCell);
        
        tbody.append(row);
    });
    
    // Remove custom field
    jQuery(document).on('click', '.remove-custom-field', function() {
        jQuery(this).closest('tr').remove();
    });
    
    // Setup custom mapping
    function setupCustomMapping() {
        var tbody = jQuery('#custom-fields-tbody');
        
        // Add initial custom fields if none exist
        if (tbody.find('tr').length === 0) {
            addCustomFieldRow('code', true); // Required field
            addCustomFieldRow('prize', true); // Required field  
        }
    }
    
    // Add custom field button handler
    jQuery(document).on('click', '#add-custom-field', function() {
        addCustomFieldRow();
    });
    
    // Add custom field row
    function addCustomFieldRow(fieldName = '', required = false) {
        var tbody = jQuery('#custom-fields-tbody');
        var rowId = 'custom-field-' + Date.now();
        
        var row = jQuery('<tr id="' + rowId + '"></tr>');
        
        // Field name input
        var nameCell = jQuery('<td></td>');
        var nameInput = jQuery('<input type="text" name="custom_fields[' + rowId + '][name]" class="custom-field-name" placeholder="<?php _e('Enter field name', 'britvic-promo'); ?>" value="' + fieldName + '">').css('width', '100%');
        if (required) nameInput.prop('readonly', true);
        nameCell.append(nameInput);
        
        // Column select
        var columnCell = jQuery('<td></td>');
        var columnSelect = jQuery('<select name="custom_fields[' + rowId + '][column]" class="column-select custom-column-select"></select>').css('width', '100%');
        columnSelect.append('<option value=""><?php _e('-- Select Column --', 'britvic-promo'); ?></option>');
        fileHeaders.forEach(function(header, index) {
            columnSelect.append('<option value="' + index + '">' + header + '</option>');
        });
        columnCell.append(columnSelect);
        
        // Sample data
        var sampleCell = jQuery('<td class="sample-data" data-field="' + rowId + '"><em><?php _e('Select column to see sample', 'britvic-promo'); ?></em></td>');
        
        // Required checkbox
        var requiredCell = jQuery('<td class="text-center"></td>');
        var requiredCheckbox = jQuery('<input type="checkbox" name="custom_fields[' + rowId + '][required]" ' + (required ? 'checked disabled' : '') + '>');
        requiredCell.append(requiredCheckbox);
        
        // Action button
        var actionCell = jQuery('<td class="text-center"></td>');
        if (!required) {
            var removeBtn = jQuery('<button type="button" class="button remove-custom-field" data-row="' + rowId + '"><?php _e('Remove', 'britvic-promo'); ?></button>');
            actionCell.append(removeBtn);
        }
        
        row.append(nameCell).append(columnCell).append(sampleCell).append(requiredCell).append(actionCell);
        tbody.append(row);
    }
    
    // Remove custom field
    jQuery(document).on('click', '.remove-custom-field', function() {
        var rowId = jQuery(this).data('row');
        jQuery('#' + rowId).remove();
    });
    
    // Handle custom column selection changes
    jQuery(document).on('change', '.custom-column-select', function() {
        var columnIndex = jQuery(this).val();
        var row = jQuery(this).closest('tr');
        var sampleCell = row.find('.sample-data');
        
        if (columnIndex === '') {
            sampleCell.html('<em><?php _e('Select column to see sample', 'britvic-promo'); ?></em>');
        } else {
            var samples = [];
            var colIndex = parseInt(columnIndex);
            
            // Get sample data from first few rows
            for (var i = 0; i < Math.min(3, uploadedFileData.length); i++) {
                if (uploadedFileData[i] && uploadedFileData[i][colIndex]) {
                    samples.push(uploadedFileData[i][colIndex]);
                }
            }
            
            if (samples.length > 0) {
                sampleCell.html('<small>' + samples.join(', ') + '</small>');
            } else {
                sampleCell.html('<em><?php _e('No sample data', 'britvic-promo'); ?></em>');
            }
        }
    });
    
    // Auto-map columns based on common names
    function autoMapColumns() {
        var autoMappings = {
            'code': ['code', 'prize_code', 'coupon_code', 'ticket'],
            'prize_name_from_file': ['prize_value', 'prize', 'prize_name', 'reward', 'item'],
            'value': ['value', 'amount', 'worth'],
            'win_date_from_file': ['date', 'draw_date', 'valid_date', 'expiry'],
            'winner_id': ['winner_id', 'user_id', 'member_id', 'customer_id'],
            'winner_email': ['winner_email', 'email', 'user_email', 'customer_email'],
            'prize_type_from_file': ['prize_type']
        };
        
        // Auto-map using the current table structure
        jQuery('#auto-mapping-tbody tr').each(function() {
            var row = jQuery(this);
            var columnIndex = row.data('column-index');
            var header = fileHeaders[columnIndex];
            var fieldSelect = row.find('.field-name-select');
            
            if (header && fieldSelect.length > 0) {
                var headerLower = header.toLowerCase();
                
                // Check each mapping pattern
                Object.keys(autoMappings).forEach(function(fieldType) {
                    var patterns = autoMappings[fieldType];
                    for (var i = 0; i < patterns.length; i++) {
                        if (headerLower.includes(patterns[i].toLowerCase())) {
                            fieldSelect.val(fieldType);
                            return; // Break out of inner loops
                        }
                    }
                });
            }
        });
    }
    
    // Handle column selection change
    jQuery(document).on('change', '.column-select', function() {
        var field = jQuery(this).attr('name').replace('mapping[', '').replace(']', '');
        var columnIndex = jQuery(this).val();
        updateSampleData(field, columnIndex);
    });
    
    // Update sample data display
    function updateSampleData(field, columnIndex) {
        var sampleCell = jQuery('.sample-data[data-field="' + field + '"]');
        
        if (columnIndex === '' || columnIndex === 'ignore') {
            sampleCell.html('<em><?php _e('No column selected', 'britvic-promo'); ?></em>');
        } else {
            var samples = [];
            var colIndex = parseInt(columnIndex);
            
            uploadedFileData.forEach(function(row, index) {
                if (index < 3 && row[colIndex]) { // Show first 3 samples
                    samples.push(row[colIndex]);
                }
            });
            
            if (samples.length > 0) {
                sampleCell.html(samples.join(', '));
            } else {
                sampleCell.html('<em><?php _e('No data', 'britvic-promo'); ?></em>');
            }
        }
    }
    
    // Auto-map button click
    jQuery('#auto-map-columns').on('click', function() {
        autoMapColumns();
    });
    
    // Preview import button
    jQuery('#preview-import').on('click', function() {
        showImportPreview();
    });
    
    // Generate sample auto date
    function generateSampleDate() {
        var startDate = jQuery('#start_date').val();
        var endDate = jQuery('#end_date').val();
        var startTime = jQuery('#start_time').val();
        var endTime = jQuery('#end_time').val();
        
        if (!startDate || !endDate || !startTime || !endTime) {
            return '2024-12-15 10:30:00';
        }
        
        var start = new Date(startDate + 'T' + startTime);
        var end = new Date(endDate + 'T' + endTime);
        var randomTime = start.getTime() + Math.random() * (end.getTime() - start.getTime());
        var randomDate = new Date(randomTime);
        
        return randomDate.toISOString().slice(0, 19).replace('T', ' ');
    }
    
    // Generate sample prize name from distribution
    function generateSamplePrizeName() {
        var prizeTypes = [];
        jQuery('.prize-type-dist-input').each(function() {
            var allocation = parseInt(jQuery(this).val()) || 0;
            if (allocation > 0) {
                var typeName = jQuery(this).data('type-name');
                prizeTypes.push(typeName);
            }
        });
        
        if (prizeTypes.length === 0) {
            return ' ';
        }
        
        return prizeTypes[Math.floor(Math.random() * prizeTypes.length)];
    }

    // Get display name for field names
    function getDisplayName(fieldName) {
        var displayNames = {
            'win_date_auto': 'Date',
            'win_date_from_file': 'Date',
            'prize_name_from_distribution': 'Prize Name',
            'prize_name_from_file': 'Prize Name',
            'code': 'Code',
            'value': 'Value',
            'winner_id': 'Winner ID',
            'winner_email': 'Winner Email'
        };
        
        if (displayNames[fieldName]) {
            return displayNames[fieldName];
        }
        
        // Default formatting for custom fields
        return fieldName.charAt(0).toUpperCase() + fieldName.slice(1).replace(/_/g, ' ');
    }

    // Show import preview
    function showImportPreview() {
        var mapping = {};
        var includeColumns = [];
        var columnNames = {};
        
        // Get field mappings from auto-mapping table (including both detected and custom fields)
        jQuery('#auto-mapping-tbody tr').each(function() {
            var row = jQuery(this);
            var columnIndex = row.data('column-index');
            var isIncluded = row.find('.include-column').is(':checked');
            var fieldSelect = row.find('.field-name-select');
            var isCustomField = row.data('custom') === true;
            
            if (isIncluded && fieldSelect.length > 0) {
                var selectedValue = fieldSelect.val();
                var fieldName = '';
                var displayName = '';
                
                // Get the field name
                if (selectedValue === '_custom') {
                    fieldName = row.find('.custom-field-name').val().trim();
                } else if (selectedValue !== '_ignore') {
                    fieldName = selectedValue;
                }
                
                // Get the display name (editable column name)
                if (isCustomField) {
                    displayName = row.find('.custom-column-name').val().trim();
                } else {
                    displayName = row.find('.column-display-name').val().trim();
                }
                
                if (fieldName && displayName) {
                    // Use column index or create custom index for custom fields
                    var index = columnIndex !== undefined ? columnIndex : (-1 - Object.keys(mapping).filter(function(k) { return k < 0; }).length);
                    mapping[index] = fieldName;
                    columnNames[index] = displayName;
                    includeColumns.push(index);
                }
            }
        });
        
        if (Object.keys(mapping).length === 0) {
            alert('<?php _e('Please select at least one column to include in the import.', 'britvic-promo'); ?>');
            return;
        }
        
        var previewHtml = '<table class="wp-list-table widefat fixed striped">';
        previewHtml += '<thead><tr>';
        
        // Add headers for mapped fields
        includeColumns.sort(function(a, b) { return a - b; }); // Sort by column index
        var usedNames = {};
        includeColumns.forEach(function(columnIndex) {
            var displayName = columnNames[columnIndex] || 'Unknown Column';
            
            // Handle duplicate column names by adding a suffix
            var originalName = displayName;
            var counter = 1;
            while (usedNames[displayName]) {
                displayName = originalName + ' (' + (++counter) + ')';
            }
            usedNames[displayName] = true;
            
            previewHtml += '<th>' + displayName + '</th>';
        });
        previewHtml += '</tr></thead><tbody>';
        
        // Add sample rows (limit to 5 rows)
        var sampleRows = uploadedFileData.slice(0, 5);
        sampleRows.forEach(function(row) {
            previewHtml += '<tr>';
            includeColumns.forEach(function(columnIndex) {
                var value = '';
                var fieldName = mapping[columnIndex];
                
                // Handle different field types
                if (fieldName === 'win_date_auto') {
                    value = generateSampleDate();
                } else if (fieldName === 'prize_name_from_distribution') {
                    value = generateSamplePrizeName();
                } else if (columnIndex < 0) {
                    // Custom field - get default value
                    var customRows = jQuery('#auto-mapping-tbody tr[data-custom="true"]');
                    var customRowIndex = Math.abs(columnIndex) - 1;
                    if (customRows.length > customRowIndex) {
                        var customRow = jQuery(customRows[customRowIndex]);
                        var defaultValue = customRow.find('.custom-value-input').val().trim();
                        value = defaultValue || '<?php _e('[Custom value]', 'britvic-promo'); ?>';
                    }
                } else {
                    // Regular column from file
                    value = row[columnIndex] || '';
                }
                
                // Truncate long values for preview
                if (value.length > 50) {
                    value = value.substring(0, 47) + '...';
                }
                previewHtml += '<td>' + value + '</td>';
            });
            previewHtml += '</tr>';
        });
        
        previewHtml += '</tbody></table>';
        var displayedRows = Math.min(sampleRows.length, 5);
        var totalRows = uploadedFileData.length;
        previewHtml += '<p class="description"><strong><?php _e('Preview showing', 'britvic-promo'); ?> ' + displayedRows + ' <?php _e('of', 'britvic-promo'); ?> ' + totalRows + ' <?php _e('total rows', 'britvic-promo'); ?></strong> (' + Object.keys(mapping).length + ' <?php _e('columns included', 'britvic-promo'); ?>)</p>';
        
        if (totalRows > displayedRows) {
            previewHtml += '<p class="description" style="color: #0073aa; font-weight: bold;"><?php _e('All', 'britvic-promo'); ?> ' + totalRows + ' <?php _e('rows will be imported when you click Import Prizes.', 'britvic-promo'); ?></p>';
        }
        
        // Add date source information to preview
        var dateSource = jQuery('input[name="date_source"]:checked').val();
        if (dateSource === 'generated') {
            var startDate = jQuery('#start_date').val();
            var endDate = jQuery('#end_date').val();
            var startTime = jQuery('#start_time').val();
            var endTime = jQuery('#end_time').val();
            
            if (startDate && endDate && startTime && endTime) {
                previewHtml += '<div style="background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; padding: 10px; margin-top: 15px;">';
                previewHtml += '<strong><?php _e('Date Generation Settings:', 'britvic-promo'); ?></strong><br>';
                previewHtml += '<?php _e('Dates will be auto-generated between', 'britvic-promo'); ?> ' + startDate + ' ' + startTime + ' <?php _e('and', 'britvic-promo'); ?> ' + endDate + ' ' + endTime;
                previewHtml += '</div>';
            } else {
                previewHtml += '<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin-top: 15px;">';
                previewHtml += '<strong><?php _e('Warning:', 'britvic-promo'); ?></strong> <?php _e('Please configure date range in Prize Generation section above.', 'britvic-promo'); ?>';
                previewHtml += '</div>';
            }
        }
        
        jQuery('#preview-content').html(previewHtml);
        jQuery('#import-preview').show();
        jQuery('#import-controls').show();
    }
    
    // Handle date source change
    jQuery('input[name="date_source"]').on('change', function() {
        var dateSource = jQuery(this).val();
        var dateColumns = jQuery('.field-name-input').filter(function() {
            var value = jQuery(this).val().toLowerCase();
            return value.includes('date') || value.includes('time') || value.includes('when') || value.includes('datetime');
        });
        
        if (dateSource === 'generated') {
            // When using generated dates, suggest unchecking date columns
            dateColumns.each(function() {
                var row = jQuery(this).closest('tr');
                var checkbox = row.find('.include-column');
                checkbox.prop('checked', false);
                row.css('opacity', '0.6');
            });
        } else {
            // When using file dates, restore date columns
            dateColumns.each(function() {
                var row = jQuery(this).closest('tr');
                row.css('opacity', '1');
            });
        }
    });
    
    // Update prize types list in field mapping
    function updatePrizeTypesList(prizeTypes) {
        var typeNames = prizeTypes.map(function(type) {
            return type.name;
        });
        jQuery('#prize-types-list').text(typeNames.join(', '));
    }
    
    // Handle import form submission
    jQuery('input[type="submit"][name="import_file"]').on('click', function(e) {
        e.preventDefault();
        
        var file = jQuery('#import_file')[0].files[0];
        if (!file) {
            alert('<?php _e('Please select a file first', 'britvic-promo'); ?>');
            return;
        }
        
        var importType = jQuery('input[name="import_type"]:checked').val() || 'standard';
        var mapping = {};
        var customFields = {};
        
        // console.log('Import Type:', importType);
        // console.log('Auto mapping tbody exists:', jQuery('#auto-mapping-tbody').length);
        // console.log('Total rows in tbody:', jQuery('#auto-mapping-tbody tr').length);
        
        // Check if file has been uploaded and table populated
        if (jQuery('#auto-mapping-tbody tr').length === 0) {
            alert('<?php _e('Please upload a file first to configure column mappings.', 'britvic-promo'); ?>');
            return;
        }
        
        // Collect mapping based on selected type
        if (importType === 'standard') {
            // Debug: Check if auto-mapping table exists and has rows
            console.log('Auto-mapping tbody rows found:', jQuery('#auto-mapping-tbody tr').length);
            
            // Auto-mapping - collect all included columns (both detected and custom)
            jQuery('#auto-mapping-tbody tr').each(function(index) {
                var row = jQuery(this);
                var includeColumn = row.find('.include-column').prop('checked');
                
                if (includeColumn) {
                    var columnIndex = row.data('column-index');
                    var fieldSelect = row.find('.field-name-select');
                    var isCustom = row.data('custom') === true;
                    
                    console.log('Row ' + index + ':', {
                        columnIndex: columnIndex,
                        fieldSelectLength: fieldSelect.length,
                        fieldSelectValue: fieldSelect.val(),
                        isCustom: isCustom,
                        rowHTML: row.html().substring(0, 200) + '...'
                    });
                    
                    if (fieldSelect.length > 0) {
                        var selectedValue = fieldSelect.val();
                        var fieldName = '';
                        
                        if (selectedValue === '_custom') {
                            fieldName = row.find('.custom-field-name').val().trim();
                        } else if (selectedValue !== '_ignore') {
                            fieldName = selectedValue;
                        }
                        
                        if (fieldName) {
                            if (isCustom) {
                                // Custom field with default value
                                var defaultValue = row.find('.custom-value-input').val().trim();
                                var customFieldName = row.find('.custom-column-name').val().trim() || fieldName;
                                customFields[customFieldName] = {
                                    field_type: fieldName,
                                    default_value: defaultValue,
                                    is_custom: true
                                };
                            } else {
                                // Regular column mapping
                                if (columnIndex !== undefined) {
                                    mapping[columnIndex] = fieldName;
                                }
                            }
                        }
                    }
                }
            });
            
            // Check if win_date_auto or prize_name are selected without column data
            // These will be handled by Prize Date Generation and Prize Type Distribution
            var hasWinDateAuto = false;
            var hasPrizeName = false;
            
            jQuery('#auto-mapping-tbody tr').each(function() {
                var row = jQuery(this);
                var includeColumn = row.find('.include-column').prop('checked');
                var fieldSelect = row.find('.field-name-select');
                
                if (includeColumn && fieldSelect.length > 0) {
                    var selectedValue = fieldSelect.val();
                    if (selectedValue === 'win_date_auto') {
                        hasWinDateAuto = true;
                        // Add to custom fields so validation passes
                        customFields['win_date_auto'] = {
                            default_value: 'auto_generated_from_date_range',
                            is_custom: true
                        };
                    } else if (selectedValue === 'prize_name_from_distribution') {
                        hasPrizeName = true;
                        // Add to custom fields so validation passes
                        customFields['prize_name_from_distribution'] = {
                            default_value: 'distributed_from_prize_types',
                            is_custom: true
                        };
                    }
                }
            });
        } else if (importType === 'multi_code') {
            // Multi-code mapping - collect date, prize and code columns
            var dateColumn = jQuery('#multi-code-date-select').val();
            var prizeColumn = jQuery('#multi-code-prize-select').val();
            var codeColumns = [];
            
            jQuery('input[name="multi_code_columns[]"]:checked').each(function() {
                codeColumns.push(parseInt(jQuery(this).val()));
            });
            
            if (!dateColumn || !prizeColumn || codeColumns.length === 0) {
                alert('<?php _e('Please select date, prize, and at least one code column', 'britvic-promo'); ?>');
                return;
            }
            
            // Store multi-code specific mapping
            mapping = {
                date_column: dateColumn,
                prize_column: prizeColumn,
                code_columns: codeColumns
            };
        }
        
        // Validate mapping based on import type
        if (importType === 'multi_code') {
            // Multi-code validation already handled above
            if (!mapping.date_column || !mapping.prize_column || !mapping.code_columns || mapping.code_columns.length === 0) {
                alert('<?php _e('Please configure multi-code mapping properly', 'britvic-promo'); ?>');
                return;
            }
        } else {
            // Additional validation for special field types
            if (hasWinDateAuto) {
                // Validate that Prize Date Generation is configured
                var startDate = jQuery('#start_date').val();
                var endDate = jQuery('#end_date').val();
                var startTime = jQuery('#start_time').val();
                var endTime = jQuery('#end_time').val();
                
                if (!startDate || !endDate || !startTime || !endTime) {
                    alert('<?php _e('Please configure the Prize Date Generation settings when using auto-generated win dates.', 'britvic-promo'); ?>');
                    return;
                }
            }
            
            if (hasPrizeName) {
                // Ensure Prize Type Distribution section is visible
                if (!jQuery('#prize-type-distribution').is(':visible')) {
                    alert('<?php _e('Prize Type Distribution section must be configured when using "Prize Name (from Prize Type)". Please upload your file first to configure the distribution.', 'britvic-promo'); ?>');
                    return;
                }
                
                // Validate that Prize Type Distribution is configured
                var totalAllocated = 0;
                jQuery('.prize-type-dist-input').each(function() {
                    totalAllocated += parseInt(jQuery(this).val()) || 0;
                });
                
                var totalRows = parseInt(jQuery('#num_prizes').val()) || 0;
                if (totalAllocated !== totalRows) {
                    alert('<?php _e('Please configure the Prize Type Distribution to match the number of imported rows when using distributed prize names.', 'britvic-promo'); ?>' + ' <?php _e('Required:', 'britvic-promo'); ?> ' + totalRows + ', <?php _e('Currently allocated:', 'britvic-promo'); ?> ' + totalAllocated);
                    return;
                }
                
                if (totalAllocated === 0) {
                    alert('<?php _e('Please allocate at least some prizes in the Prize Type Distribution section when using distributed prize names.', 'britvic-promo'); ?>');
                    return;
                }
            }
            
            // Debug output (remove these lines later)
            // console.log('Mapping:', mapping);
            // console.log('Custom Fields:', customFields);
            
            // Standard auto-mapping validation
            if (Object.keys(mapping).length === 0 && Object.keys(customFields).length === 0) {
                alert('<?php _e('Please include at least one column or custom field', 'britvic-promo'); ?>');
                return;
            }
        }
        
        // Submit import with progress tracking
        var formData = new FormData();
        var isCSV = file.type.includes('csv') || file.name.endsWith('.csv');
        
        if (isCSV) {
            formData.append('csv_file', file);
            formData.append('action', 'bpm_import_csv');
        } else {
            formData.append('xlsx_file', file);
            formData.append('action', 'bpm_import_xlsx');
        }
        
        // Debug: Log final mapping before sending
        console.log('Final mapping to send:', mapping);
        console.log('Final custom fields to send:', customFields);
        console.log('Mapping object keys:', Object.keys(mapping));
        console.log('Auto mapping tbody rows when collecting:', jQuery('#auto-mapping-tbody tr').length);
        
        // Convert mapping object to proper format for PHP
        var mappingForServer = {};
        for (var key in mapping) {
            if (mapping.hasOwnProperty(key)) {
                mappingForServer[key.toString()] = mapping[key];
            }
        }
        console.log('Converted mapping for server:', mappingForServer);
        console.log('JSON stringified mapping:', JSON.stringify(mappingForServer));
        
        if (Object.keys(mapping).length === 0) {
            console.error('ERROR: Mapping object is empty! Check column mapping collection logic.');
            alert('Error: No column mapping found. Please check your column selections and try again.');
            button.prop('disabled', false).val('<?php _e('Import Prizes', 'britvic-promo'); ?>');
            return false;
        }
        
        formData.append('competition_id', <?php echo $selected_competition; ?>);
        formData.append('import_type', importType);
        formData.append('column_mapping', JSON.stringify(mappingForServer));
        formData.append('custom_fields', JSON.stringify(customFields));
        formData.append('nonce', '<?php echo wp_create_nonce('bpm_nonce'); ?>');
        
        // Debug: Log all FormData entries
        console.log('FormData entries:');
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + pair[1]);
        }
        
        var button = jQuery(this);
        button.prop('disabled', true).val('<?php _e('Importing...', 'britvic-promo'); ?>');
        
        // Hide controls and show progress
        jQuery('#import-controls').hide();
        jQuery('#import-progress').show();
        
        // Start progress simulation
        simulateImportProgress();
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                // Clear progress interval and complete progress
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                
                jQuery('#progress-bar').css('width', '100%');
                jQuery('#progress-percentage').text('100%');
                
                if (response.success) {
                    jQuery('#progress-text').text('<?php _e('Import completed!', 'britvic-promo'); ?>');
                    jQuery('#progress-status').text(response.data.message || '<?php _e('All prizes imported successfully', 'britvic-promo'); ?>');
                    
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    jQuery('#progress-text').text('<?php _e('Import failed', 'britvic-promo'); ?>');
                    jQuery('#progress-status').text(response.data.message || '<?php _e('Unknown error occurred', 'britvic-promo'); ?>');
                    
                    setTimeout(function() {
                        resetImportUI(button);
                    }, 3000);
                }
            },
            error: function() {
                // Clear progress interval and show error
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                
                jQuery('#progress-bar').css('width', '100%');
                jQuery('#progress-percentage').text('100%');
                jQuery('#progress-text').text('<?php _e('Import failed', 'britvic-promo'); ?>');
                jQuery('#progress-status').text('<?php _e('Network error occurred', 'britvic-promo'); ?>');
                
                setTimeout(function() {
                    resetImportUI(button);
                }, 3000);
            }
        });
    });

    // Comprehensive prize sync - combines both table sync and claims sync
    jQuery('#sync-prizes').on('click', function() {
        if (confirm('<?php echo esc_js(__("Are you sure you want to sync prizes? This will:\n\n1. Add missing prize posts to the prize entries table\n2. Update WordPress posts to match claimed prize data\n\nThis ensures both databases are fully synchronized.", "britvic-promo")); ?>')) {
            var button = jQuery(this);
            var originalText = button.text();
            button.prop('disabled', true).text('<?php _e("Syncing...", "britvic-promo"); ?>');
            
            // First: Sync prize table (WordPress posts → prize entries)
            jQuery.post(ajaxurl, {
                action: 'bpm_sync_prizes',
                competition_id: <?php echo $selected_competition; ?>,
                nonce: '<?php echo wp_create_nonce("bpm_nonce"); ?>'
            })
            .done(function(response1) {
                if (response1.success) {
                    // Second: Sync claims (prize entries → WordPress posts)
                    button.text('<?php _e("Syncing Claims...", "britvic-promo"); ?>');
                    
                    jQuery.post(ajaxurl, {
                        action: 'bpm_manual_sync',
                        nonce: '<?php echo wp_create_nonce("bpm_nonce"); ?>'
                    })
                    .done(function(response2) {
                        if (response2.success) {
                            alert('<?php echo esc_js(__("Complete sync successful!", "britvic-promo")); ?>' + '\n\n' +
                                  '<?php echo esc_js(__("Step 1:", "britvic-promo")); ?> ' + response1.data.message + '\n' +
                                  '<?php echo esc_js(__("Step 2:", "britvic-promo")); ?> ' + response2.data.message);
                            location.reload();
                        } else {
                            alert('<?php echo esc_js(__("Claims sync failed:", "britvic-promo")); ?> ' + response2.data.message + '\n\n' +
                                  '<?php echo esc_js(__("Prize table sync was successful:", "britvic-promo")); ?> ' + response1.data.message);
                        }
                    })
                    .fail(function() {
                        alert('<?php echo esc_js(__("Claims sync request failed.", "britvic-promo")); ?>' + '\n\n' +
                              '<?php echo esc_js(__("Prize table sync was successful:", "britvic-promo")); ?> ' + response1.data.message);
                    })
                    .always(function() {
                        button.prop('disabled', false).text(originalText);
                    });
                } else {
                    alert('<?php echo esc_js(__("Prize table sync failed:", "britvic-promo")); ?> ' + response1.data.message);
                    button.prop('disabled', false).text(originalText);
                }
            })
            .fail(function() {
                alert('<?php echo esc_js(__("Prize table sync request failed. Please try again.", "britvic-promo")); ?>');
                button.prop('disabled', false).text(originalText);
            });
        }
    });

    // Multi-code information is now handled automatically through the "+Add Code" functionality
    // No additional JavaScript needed - existing code input management handles multiple codes per prize type

    // Note: Manual sync functionality is now integrated into the main "Sync Prizes" button above
    // Old manual sync button handler removed

    // Initialize column management
    initializeColumnManagement();

    // Column Management JavaScript
    var currentCompetitionId = <?php echo $selected_competition; ?>;
    
    // Get winner form ID for correct Gravity Forms links
    <?php 
    $form_settings = get_option("bpm_form_settings_{$selected_competition}", []);
    $winner_form_id = $form_settings['winner_form']['form_id'] ?? '';
    ?>
    var winnerFormId = '<?php echo esc_js($winner_form_id); ?>';
    
    function initializeColumnManagement() {
        // Wait a bit for the DOM to be fully ready
        setTimeout(function() {
            updateColumnsList();
            // Apply stored column configuration on page load
            applyStoredColumnConfiguration();
        }, 100);
    }
    
    function applyStoredColumnConfiguration() {
        // Use stored preferences from PHP
        var storedOrder = (typeof bpmAjax !== 'undefined' && bpmAjax.columnOrder) ? bpmAjax.columnOrder : [];
        var storedVisibility = (typeof bpmAjax !== 'undefined' && bpmAjax.columnVisibility) ? bpmAjax.columnVisibility : {};
        
        if (storedOrder.length > 0 || Object.keys(storedVisibility).length > 0) {
            var preferences = {
                order: storedOrder,
                visibility: storedVisibility
            };
            console.log('Applying stored column configuration on page load:', preferences);
            applyColumnConfiguration(preferences);
        }
    }
    
    // Show/Hide configure columns dialog
    jQuery('#configure-columns-btn').on('click', function() {
        updateColumnsList(); // Refresh the list when opening
        jQuery('#column-config-dialog').fadeIn(300);
        jQuery('body').addClass('column-dialog-open');
    });
    
    // Close dialog
    jQuery('#close-column-dialog, #cancel-column-config').on('click', function() {
        jQuery('#column-config-dialog').fadeOut(300);
        jQuery('body').removeClass('column-dialog-open');
    });
    
    // Close dialog when clicking outside
    jQuery('#column-config-dialog').on('click', function(e) {
        if (e.target === this) {
            jQuery('#column-config-dialog').fadeOut(300);
            jQuery('body').removeClass('column-dialog-open');
        }
    });
    
    // Save column configuration
    jQuery('#save-column-config').on('click', function() {
        applyColumnConfiguration();
        saveColumnPreferences();
        jQuery('#column-config-dialog').fadeOut(300);
        jQuery('body').removeClass('column-dialog-open');
    });
    
    // Handle column visibility toggle
    jQuery(document).on('change', '.column-visibility-toggle', function() {
        // Just update the visual state, don't save yet
    });
    
    // Reset to default configuration
    jQuery('#reset-columns-btn').on('click', function() {
        if (confirm('<?php _e('Reset column configuration to default? This will restore all columns in their original order.', 'britvic-promo'); ?>')) {
            resetColumnConfiguration();
        }
    });
    
    function updateColumnsList() {
        console.log('UpdateColumnsList called');
        
        var table = jQuery('#existing-prizes-table');
        
        if (table.length === 0) {
            // Try to find any table in the prizes area
            table = jQuery('.wp-list-table').first();
            console.log('Primary table not found, using fallback:', table.length);
        }
        
        if (table.length === 0) {
            console.log('No table found for column management');
            return;
        }
        
        var headers = table.find('thead th');
        var columnsList = jQuery('#sortable-columns');
        columnsList.empty();
        
        console.log('Found headers:', headers.length);
        
        // Collect all columns (including hidden ones) with their current state
        var columnInfo = [];
        
        // Get stored visibility preferences once
        var storedVisibility = (typeof bpmAjax !== 'undefined' && bpmAjax.columnVisibility) ? bpmAjax.columnVisibility : {};
        
        headers.each(function(index) {
            var headerElement = jQuery(this);
            var headerText = headerElement.text().trim();
            var columnKey = headerElement.data('column') || (headerElement.attr('class') || '').replace('column-', '').split(' ')[0] || 'column-' + index;
            var isVisible = headerElement.is(':visible');
            
            console.log('Processing header:', {
                index: index,
                text: headerText,
                key: columnKey,
                visible: isVisible,
                classes: headerElement.attr('class')
            });
            
            if (headerText && headerText !== 'Actions') {
                // Use stored preference if available, otherwise fall back to DOM visibility
                var preferredVisibility = (storedVisibility.hasOwnProperty(columnKey)) ? storedVisibility[columnKey] : isVisible;
                
                columnInfo.push({
                    key: columnKey,
                    text: headerText, // Keep original text from DOM
                    index: index,
                    visible: preferredVisibility
                });
            }
        });
        
        console.log('Column info collected:', columnInfo);
        
        // Also check if we have stored preferences that might include hidden columns
        // not currently in the DOM
        var storedOrder = (typeof bpmAjax !== 'undefined' && bpmAjax.columnOrder) ? bpmAjax.columnOrder : [];
        
        console.log('Stored preferences:', {order: storedOrder, visibility: storedVisibility});
        
        // Add any columns from stored preferences that might be missing from DOM
        storedOrder.forEach(function(storedKey) {
            var exists = columnInfo.find(function(col) { return col.key === storedKey; });
            if (!exists && storedKey !== 'actions') {
                console.log('Adding stored column not found in DOM:', storedKey);
                
                // Generate proper display text - use simple fallback labels for missing columns
                // The actual table already has the correct contextual labels from PHP
                var displayText;
                switch(storedKey) {
                    case 'unique_id':
                        displayText = 'ID';
                        break;
                    case 'prize':
                        displayText = 'Prize';
                        break;
                    case 'date':
                        displayText = 'Date';
                        break;
                    case 'claimed':
                        displayText = 'Claimed';
                        break;
                    case 'status':
                        displayText = 'Status';
                        break;
                    case 'winner_id':
                        displayText = 'Winner ID';
                        break;
                    case 'winner_email':
                        displayText = 'Winner Email';
                        break;
                    case 'value':
                        displayText = 'Value';
                        break;
                    default:
                        // For dynamic columns like code_1, code_2, etc, use title case
                        displayText = storedKey.replace(/_/g, ' ').replace(/\b\w/g, function(char) {
                            return char.toUpperCase();
                        });
                        break;
                }
                
                columnInfo.push({
                    key: storedKey,
                    text: displayText,
                    index: 999, // Put at end
                    visible: storedVisibility[storedKey] !== false
                });
            }
        });
        
        // Sort by stored order preference if available, otherwise by index
        if (storedOrder.length > 0) {
            columnInfo.sort(function(a, b) {
                var aOrderIndex = storedOrder.indexOf(a.key);
                var bOrderIndex = storedOrder.indexOf(b.key);
                
                // If both columns are in stored order, sort by that
                if (aOrderIndex !== -1 && bOrderIndex !== -1) {
                    return aOrderIndex - bOrderIndex;
                }
                // If only one is in stored order, prioritize it
                if (aOrderIndex !== -1) return -1;
                if (bOrderIndex !== -1) return 1;
                // If neither is in stored order, sort by index
                return a.index - b.index;
            });
        } else {
            // Fallback to index sorting
            columnInfo.sort(function(a, b) {
                return a.index - b.index;
            });
        }
        
        console.log('Final column info after sorting:', columnInfo);
        
        // Create column items for ALL columns (visible and hidden)
        columnInfo.forEach(function(column) {
            var columnItem = jQuery('<li class="column-item" data-column-key="' + column.key + '" data-column-index="' + column.index + '">' +
                '<span class="drag-handle dashicons dashicons-menu" title="<?php _e('Drag to reorder', 'britvic-promo'); ?>"></span>' +
                '<label class="column-label">' +
                    '<input type="checkbox" class="column-visibility-toggle" ' + (column.visible ? 'checked' : '') + '> ' +
                    '<span class="column-name">' + column.text + '</span>' +
                '</label>' +
            '</li>');
            columnsList.append(columnItem);
        });
        
        console.log('Added', columnInfo.length, 'columns to dialog');
        
        // Re-initialize sortable after updating list
        if (columnsList.hasClass('ui-sortable')) {
            columnsList.sortable('destroy');
        }
        
        columnsList.sortable({
            handle: '.drag-handle',
            placeholder: 'column-placeholder',
            update: function(event, ui) {
                // Just update the order visually, don't save yet
            },
            cursor: 'move',
            tolerance: 'pointer'
        }).disableSelection();
    }
    
    function applyColumnConfiguration(savedPreferences) {
        var table = jQuery('#existing-prizes-table');
        if (table.length === 0) return;
        
        // If no preferences provided, get from dialog state
        var preferences = savedPreferences;
        if (!preferences) {
            preferences = {
                order: [],
                visibility: {}
            };
            
            jQuery('#sortable-columns .column-item').each(function() {
                var columnKey = jQuery(this).data('column-key');
                var isVisible = jQuery(this).find('.column-visibility-toggle').is(':checked');
                
                preferences.order.push(columnKey);
                preferences.visibility[columnKey] = isVisible;
            });
        }
        
        console.log('Applying column configuration:', preferences);
        
        // Hide/show columns based on visibility settings
        var headers = table.find('thead th');
        headers.each(function(index) {
            var headerElement = jQuery(this);
            var columnKey = headerElement.data('column') || (headerElement.attr('class') || '').replace('column-', '').split(' ')[0] || 'column-' + index;
            var headerText = headerElement.text().trim();
            
            // Don't hide the Actions column
            if (headerText === 'Actions') {
                headerElement.show();
                table.find('tbody tr').each(function() {
                    jQuery(this).find('td').eq(index).show();
                });
                return;
            }
            
            // Check if this column should be visible
            var shouldShow = preferences.visibility.hasOwnProperty(columnKey) ? 
                preferences.visibility[columnKey] : true;
            
            console.log('Column', columnKey, 'should show:', shouldShow);
            
            if (shouldShow) {
                // Show header and all cells in this column
                headerElement.show();
                table.find('tbody tr').each(function() {
                    jQuery(this).find('td').eq(index).show();
                });
            } else {
                // Hide header and all cells in this column
                headerElement.hide();
                table.find('tbody tr').each(function() {
                    jQuery(this).find('td').eq(index).hide();
                });
            }
        });
    }
    
    function saveColumnPreferences() {
        var preferences = {
            order: [],
            visibility: {}
        };
        
        jQuery('#sortable-columns .column-item').each(function() {
            var columnKey = jQuery(this).data('column-key');
            var isVisible = jQuery(this).find('.column-visibility-toggle').is(':checked');
            
            preferences.order.push(columnKey);
            preferences.visibility[columnKey] = isVisible;
        });
        
        // Save via AJAX
        jQuery.post(ajaxurl, {
            action: 'bpm_save_column_preferences',
            competition_id: currentCompetitionId,
            preferences: JSON.stringify(preferences),
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                console.log('Column preferences saved');
                // Apply the changes immediately to the table using the saved preferences
                applyColumnConfiguration(preferences);
                // Close the dialog
                jQuery('#column-config-dialog').hide();
            } else {
                console.error('Failed to save column preferences:', response.data);
                alert('<?php _e('Failed to save column configuration', 'britvic-promo'); ?>');
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX error saving column preferences:', error);
            alert('<?php _e('Error saving column configuration', 'britvic-promo'); ?>');
        });
    }
    
    function resetColumnConfiguration() {
        // Reset via AJAX
        jQuery.post(ajaxurl, {
            action: 'bpm_reset_column_preferences', 
            competition_id: currentCompetitionId,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload(); // Reload to apply default configuration
            } else {
                alert('<?php _e('Failed to reset column configuration', 'britvic-promo'); ?>');
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX error resetting column preferences:', error);
            alert('<?php _e('Network error occurred while resetting', 'britvic-promo'); ?>');
        });
    }
    
    // ── AJAX-driven sort + pagination ────────────────────────────────────
    // Sort, pagination, and the email-search submit all swap just the
    // <div id="bpm-prizes-fragment"> in place instead of doing a full page
    // reload. The URL is updated via history.pushState so the state is
    // bookmarkable + back-button friendly. No scroll-position gymnastics
    // needed because the page never reloads.
    var BPM_AJAX_ACTION = 'bpm_prizes_fetch_fragment';
    var BPM_AJAX_NONCE  = '<?php echo esc_js( wp_create_nonce('bpm_nonce') ); ?>';
    var BPM_AJAX_URL    = ajaxurl;

    function bpmFragmentParams(overrides) {
        var url = new URL(window.location.href);
        var p = {
            competition_id: <?php echo (int) $selected_competition; ?>,
            orderby:        url.searchParams.get('orderby') || 'date',
            order:          (url.searchParams.get('order')  || 'asc').toLowerCase(),
            paged:          parseInt(url.searchParams.get('paged'), 10) || 1,
            search_email:   url.searchParams.get('search_email') || ''
        };
        if (overrides) { for (var k in overrides) { p[k] = overrides[k]; } }
        if (p.order !== 'desc') { p.order = 'asc'; }
        return p;
    }

    function bpmFragmentURL(params) {
        var url = new URL(window.location.href);
        url.searchParams.set('orderby', params.orderby);
        url.searchParams.set('order',   params.order);
        url.searchParams.set('paged',   params.paged);
        if (params.search_email) { url.searchParams.set('search_email', params.search_email); }
        else                     { url.searchParams.delete('search_email'); }
        return url.toString();
    }

    function bpmSetLoading(on) {
        var frag = document.getElementById('bpm-prizes-fragment');
        if (!frag) { return; }
        frag.classList.toggle('is-loading', !!on);
    }

    function bpmLoadFragment(overrides, pushHistory) {
        var params = bpmFragmentParams(overrides);
        bpmSetLoading(true);

        var body = new URLSearchParams();
        body.set('action',         BPM_AJAX_ACTION);
        body.set('nonce',          BPM_AJAX_NONCE);
        body.set('competition_id', params.competition_id);
        body.set('orderby',        params.orderby);
        body.set('order',          params.order);
        body.set('paged',          params.paged);
        body.set('search_email',   params.search_email);

        return fetch(BPM_AJAX_URL, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString()
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !data.success || !data.data || typeof data.data.html !== 'string') {
                throw new Error((data && data.data && data.data.message) || 'Unexpected response.');
            }
            var frag = document.getElementById('bpm-prizes-fragment');
            if (!frag) { throw new Error('Fragment container missing.'); }
            // The server-rendered fragment wraps the same #bpm-prizes-fragment
            // div. Use outerHTML so the element is fully replaced (preserves
            // its id + class state).
            frag.outerHTML = data.data.html;
            if (pushHistory !== false) {
                try { window.history.pushState(params, '', bpmFragmentURL(params)); } catch (e) {}
            }
        }).catch(function (err) {
            console.error('[BPM Prizes] fragment load failed:', err);
        }).finally(function () {
            bpmSetLoading(false);
        });
    }

    // Delegated handlers survive fragment swaps without rebinding.
    jQuery(document).on('click', '#existing-prizes-table th.sortable', function (e) {
        var column = jQuery(this).data('column');
        if (!column) { return; }
        e.preventDefault();
        var current = (new URL(window.location.href)).searchParams.get('orderby');
        var dir     = ((new URL(window.location.href)).searchParams.get('order') || 'asc').toLowerCase();
        var nextDir = (current === column && dir === 'asc') ? 'desc' : 'asc';
        bpmLoadFragment({ orderby: column, order: nextDir, paged: 1 }, true);
    });

    jQuery(document).on('click', '.pagination-wrapper a.button', function (e) {
        var href = this.getAttribute('href');
        if (!href) { return; }
        e.preventDefault();
        var u = new URL(href, window.location.origin);
        bpmLoadFragment({
            paged:   parseInt(u.searchParams.get('paged'), 10) || 1,
            orderby: u.searchParams.get('orderby') || undefined,
            order:   u.searchParams.get('order')   || undefined
        }, true);
    });

    jQuery(document).on('submit', '.pagination-jump form', function (e) {
        e.preventDefault();
        var input = this.querySelector('input[name="paged"]');
        var p     = input ? parseInt(input.value, 10) || 1 : 1;
        bpmLoadFragment({ paged: p }, true);
    });

    // Apply Filters / Clear (search by email)
    jQuery(document).on('submit', '#bpm-prizes-filter-form', function (e) {
        e.preventDefault();
        var emailInput = this.querySelector('input[name="search_email"]');
        bpmLoadFragment({ search_email: (emailInput && emailInput.value) || '', paged: 1 }, true);
    });

    // ── Bulk actions: per-row checkbox + select-all-on-page + select-all
    // -across-pages banner + Apply. Delegated so it survives fragment swaps.
    var bpmSelectAllPages = false;

    function bpmUpdateBulkBanner() {
        var $rows  = jQuery('#existing-prizes-table tbody .bpm-row-select');
        var $banner = jQuery('#bpm-bulk-select-all-banner');
        if (!$rows.length || !$banner.length) { return; }
        var total     = $rows.length;
        var checked   = $rows.filter(':checked').length;
        jQuery('#cb-select-all-1').prop('checked', total > 0 && checked === total);
        if (checked === total && total > 0) {
            $banner.find('.bpm-banner-page-msg').text(
                'All ' + total + ' prizes on this page are selected.'
            );
            $banner.find('#bpm-select-all-pages-link').text(
                'Select all prizes across every page.'
            ).show();
            $banner.find('#bpm-select-all-pages-active').toggle(bpmSelectAllPages);
            $banner.find('#bpm-select-all-pages-link').toggle(!bpmSelectAllPages);
            $banner.show();
        } else {
            bpmSelectAllPages = false;
            $banner.hide();
        }
    }

    jQuery(document).on('change', '#cb-select-all-1', function () {
        var on = this.checked;
        jQuery('#existing-prizes-table tbody .bpm-row-select').prop('checked', on);
        if (!on) { bpmSelectAllPages = false; }
        bpmUpdateBulkBanner();
    });
    jQuery(document).on('change', '.bpm-row-select', function () {
        if (!this.checked) { bpmSelectAllPages = false; }
        bpmUpdateBulkBanner();
    });
    jQuery(document).on('click', '#bpm-select-all-pages-link', function (e) {
        e.preventDefault();
        bpmSelectAllPages = true;
        bpmUpdateBulkBanner();
    });
    jQuery(document).on('click', '#bpm-select-all-pages-clear', function (e) {
        e.preventDefault();
        bpmSelectAllPages = false;
        jQuery('#existing-prizes-table tbody .bpm-row-select').prop('checked', false);
        bpmUpdateBulkBanner();
    });

    jQuery(document).on('click', '#doaction', function () {
        var btn      = this;
        var action   = jQuery('#bulk-action-selector-top').val();
        if (action === '-1' || !action) {
            alert('Choose a bulk action first.');
            return;
        }
        var ids = jQuery('#existing-prizes-table tbody .bpm-row-select:checked').map(function () {
            return parseInt(this.value, 10);
        }).get();
        if (!bpmSelectAllPages && ids.length === 0) {
            alert('Tick at least one row first (or choose "Select all prizes across every page").');
            return;
        }

        var actionLabels = {
            mark_available: 'mark as available',
            mark_claimed:   'mark as claimed',
            reseed:         'reseed',
            'delete':       'DELETE'
        };
        var scope = bpmSelectAllPages
            ? 'EVERY prize in this competition'
            : (ids.length + ' selected prize(s)');
        var verb = actionLabels[action] || action;
        if (action === 'delete' || action === 'reseed' || action === 'mark_claimed' || bpmSelectAllPages) {
            if (!confirm('You are about to ' + verb + ' ' + scope + '. Continue?')) { return; }
        }

        btn.disabled = true;
        var orig = btn.textContent;
        btn.textContent = 'Working…';

        var url = new URL(window.location.href);
        var body = new URLSearchParams();
        body.set('action', 'bpm_prizes_bulk_action');
        body.set('nonce', BPM_AJAX_NONCE);
        body.set('bulk_action', action);
        body.set('competition_id', <?php echo (int) $selected_competition; ?>);
        body.set('search_email', url.searchParams.get('search_email') || '');
        if (bpmSelectAllPages) {
            body.set('select_all', '1');
        } else {
            ids.forEach(function (id) { body.append('prize_ids[]', String(id)); });
        }

        fetch(BPM_AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success && data.data) {
                var msg = action.replace(/_/g, ' ') + ': ' + data.data.updated + ' of ' + data.data.total + ' prizes updated.';
                if (data.data.errors && data.data.errors.length) {
                    msg += '\n\nIssues:\n' + data.data.errors.slice(0, 5).join('\n');
                }
                alert(msg);
                bpmSelectAllPages = false;
                bpmLoadFragment({}, false);
            } else {
                alert('Bulk action failed: ' + ((data && data.data && data.data.message) || 'unknown error'));
            }
        }).catch(function (err) {
            alert('Bulk action failed: ' + err.message);
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = orig;
        });
    });

    // "Sync Prize Data" — backfill date/prize-name/code aliases across
    // postmeta + mapped_data + bpm_prize_entries for every prize in the
    // current competition. One-shot maintenance action.
    jQuery(document).on('click', '#bpm-sync-aliases', function () {
        var btn = this;
        if (btn.disabled) { return; }
        if (!confirm('Sync prize-data aliases for all prizes in this competition? Safe to run; idempotent.')) { return; }
        btn.disabled = true;
        var orig = btn.textContent;
        btn.textContent = 'Syncing…';

        var body = new URLSearchParams();
        body.set('action', 'bpm_prizes_sync_aliases');
        body.set('nonce', BPM_AJAX_NONCE);
        body.set('competition_id', <?php echo (int) $selected_competition; ?>);

        fetch(BPM_AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success && data.data) {
                alert('Synced ' + data.data.synced + ' of ' + data.data.total + ' prizes.');
                bpmLoadFragment({}, false);
            } else {
                alert('Sync failed: ' + ((data && data.data && data.data.message) || 'unknown error'));
            }
        }).catch(function (err) {
            alert('Sync failed: ' + err.message);
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = orig;
        });
    });

    // Browser back / forward — re-fetch the fragment matching the URL state.
    window.addEventListener('popstate', function () {
        bpmLoadFragment({}, false);
    });
});
</script>

<style>
/* Prizes table — fit container, horizontal scroll on overflow, padded headers */
.bpm-prizes-table-wrap {
    overflow-x: auto;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
}
/* Loading state during AJAX sort / pagination fragment swaps */
#bpm-prizes-fragment.is-loading {
    opacity: 0.55;
    pointer-events: none;
    transition: opacity 120ms ease-out;
}
/* Bulk-select column (first column) — compact + centred. */
#existing-prizes-table th.column-bulk-select,
#existing-prizes-table td.column-bulk-select {
    width: 28px;
    min-width: 28px;
    padding: 8px 4px;
    text-align: center;
    vertical-align: middle;
}
#existing-prizes-table th.column-bulk-select input,
#existing-prizes-table td.column-bulk-select input {
    margin: 0;
}
/* Tablenav layout: a single flex row containing utility buttons + the bulk
   actions widget. Override WP's default float-left so the flex parent can
   own the layout cleanly. `display: contents` on the row wrappers lifts
   their children up into the parent flex context so everything sits on one
   line regardless of the inner grouping. */
.bpm-tablenav-actions {
    float: none !important;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.bpm-tablenav-row {
    display: contents;
}
/* Optional left margin to visually separate the bulk-actions cluster from
   the utility buttons within the same row. */
.bpm-tablenav-row--bulk #bulk-action-selector-top {
    margin-left: 16px;
}
/* Bulk-action dropdown — match adjacent .button height so the row is even. */
#bulk-action-selector-top {
    height: 30px;
    line-height: 28px;
    padding: 0 24px 0 8px;
    margin: 0;
    vertical-align: middle;
    box-sizing: border-box;
}
#existing-prizes-table {
    width: 100%;
}
#existing-prizes-table th,
#existing-prizes-table td {
    padding-left: 12px;
    padding-right: 12px;
}

/* Pagination — every button + the jump input render at the same height,
   same width, and have text centred. inline-flex avoids the inline-anchor
   height quirk where <a class="button"> ignores height and grows past 30px. */
.pagination-wrapper a.button,
.pagination-wrapper button.button,
.pagination-wrapper input[type="number"] {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    height: 30px !important;
    min-height: 30px;
    line-height: 1;
    padding: 0 10px;
    min-width: 66px;
    margin: 0 2px;
    font-size: 13px;
    vertical-align: middle;
    text-align: center;
}
/* Override .button-small specificity so the "Go" button matches the row */
.pagination-wrapper .button-small {
    height: 30px !important;
    min-height: 30px;
    min-width: 96px;
    font-size: 13px;
    padding: 0 10px;
    line-height: 1;
}
.pagination-wrapper .pagination-info {
    display: inline-flex;
    align-items: center;
    height: 30px;
    margin: 0 8px;
    vertical-align: middle;
}

/* Sortable table headers */
.sortable {
    position: relative;
    user-select: none;
    white-space: nowrap;
}

.sortable:hover {
    background-color: #f0f6fc !important;
}

.sort-indicator {
    margin-left: 6px;
    color: #8c8f94;
    font-weight: normal;
    font-size: 0.7em;
    vertical-align: middle;
    display: inline-block;
}

.sortable:hover .sort-indicator {
    color: #2271b1;
}

/* Every column fits its full value on one line; the wrapper scrolls horizontally
   if the total width exceeds the container. */
#existing-prizes-table th,
#existing-prizes-table td {
    white-space: nowrap;
}

/* Editable cells — pencil cue, hidden while editing */
#existing-prizes-table td.editable {
    padding-right: 28px;
}
#existing-prizes-table td.editable::after {
    content: "\f464"; /* dashicons-edit */
    font-family: dashicons;
    font-size: 10px;
    line-height: 1;
    color: #8c8f94;
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.4;
    pointer-events: none;
}
#existing-prizes-table td.editable:hover::after {
    opacity: 1;
    color: #2271b1;
}
#existing-prizes-table td.editable:has(.edit-input)::after {
    display: none;
}

.prize-types {
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 4px;
    margin: 15px 0;
}

.prize-type-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.prize-type-row input {
    width: 100%;
    flex: 1;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
}

.stat-label {
    font-size: 13px;
    color: #646970;
    margin-top: 5px;
}

.bpm-status-claimed {
    color: #d63638;
    font-weight: 500;
}

.bpm-status-available {
    color: #00a32a;
    font-weight: 500;
}

.bpm-status-reseeded {
    color: #f0b849;
    font-style: italic;
}

/* Stripe payout-status badges (Status column).
   Greens for success, blues for in-flight, reds for ops-attention,
   greys for inactive/non-applicable. */
.bpm-payout-paid       { color: #fff; background: #00a32a; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-pending    { color: #fff; background: #2271b1; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-submitted  { color: #fff; background: #2271b1; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-failed     { color: #fff; background: #d63638; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-cop-blocked { color: #fff; background: #d63638; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-cancelled  { color: #fff; background: #646970; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-payout-manual     { color: #646970; font-style: italic; font-weight: 500; }
.bpm-payout-none       { color: #c3c4c7; }
.bpm-payout-unknown    { color: #646970; font-style: italic; }

/* Prize Type column badges. cash → green (paid-out shape), merch →
   blue (in-flight/manual shape), unknown → grey + italic so any
   data-quality issue is visible at a glance. */
.bpm-prize-type-cash    { color: #fff; background: #00a32a; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-prize-type-merch   { color: #fff; background: #2271b1; padding: 2px 8px; border-radius: 3px; font-weight: 500; font-size: 12px; display: inline-block; }
.bpm-prize-type-unknown { color: #646970; font-style: italic; }

.bpm-no-winner {
    color: #646970;
    font-style: italic;
    cursor: pointer;
}

.bpm-no-winner:hover {
    color: #2271b1;
    text-decoration: underline;
}

.winner-field {
    min-width: 150px;
}

.gf-entry-link a, .email-link a {
    text-decoration: none;
}

.gf-entry-link a:hover, .email-link a:hover {
    text-decoration: underline;
}

.editable {
    cursor: pointer;
    position: relative;
}

.editable:hover {
    background-color: #f0f6fc;
}

.edit-input {
    width: 100%;
    padding: 2px 4px;
    border: 1px solid #2271b1;
    border-radius: 3px;
    font-size: inherit;
}

.updated {
    background-color: #d1e7dd !important;
    transition: background-color 0.3s ease;
}

/* Prize Statistics */
.prize-stat {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    min-width: 120px;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}

.stat-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #2c3e50;
}

.claimed-count {
    color: #e74c3c !important;
}

.available-count {
    color: #27ae60 !important;
}

.bpm-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bpm-modal-content {
    background: white;
    padding: 30px;
    border-radius: 8px;
    max-width: 80%;
    max-height: 80%;
    overflow: auto;
    position: relative;
}

.bpm-history-table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
}

.bpm-history-table th,
.bpm-history-table td {
    padding: 8px 12px;
    border: 1px solid #ddd;
    text-align: left;
}

.bpm-history-table th {
    background-color: #f1f1f1;
    font-weight: 600;
}

.bpm-stats {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin: 15px 0;
}

.button-small {
    font-size: 11px;
    height: auto;
    line-height: 1.5;
    padding: 2px 8px;
    margin-right: 5px;
}

.edit-container {
    display: flex;
    align-items: center;
    gap: 5px;
}

.edit-container .edit-input {
    flex: 1;
    min-width: 120px;
}

.save-field {
    background-color: #00a32a !important;
    color: white !important;
    border-color: #00a32a !important;
    font-weight: bold;
    min-width: 24px;
    text-align: center;
    z-index: 1000;
    position: relative;
}

.save-field:hover {
    background-color: #008a20 !important;
}

.cancel-field {
    background-color: #d63638 !important;
    color: white !important;
    border-color: #d63638 !important;
    font-weight: bold;
    min-width: 24px;
    text-align: center;
    z-index: 1000;
    position: relative;
}

.cancel-field:hover {
    background-color: #b32d2e !important;
}

.edit-container {
    z-index: 999;
    position: relative;
    background: white;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    border-radius: 3px;
    padding: 2px;
}

/* Delete button styles */
.delete-prize:hover {
    background-color: #b32d2e !important;
    border-color: #b32d2e !important;
}

#bulk-delete-prizes:hover:not(:disabled) {
    background-color: #b32d2e !important;
    border-color: #b32d2e !important;
}

#bulk-delete-prizes:disabled {
    background-color: #999 !important;
    border-color: #999 !important;
    cursor: not-allowed;
}

#bulk-delete-type {
    margin-right: 5px;
}

/* Icon Button Styles */
.bpm-actions-column {
    text-align: center;
    white-space: nowrap;
    width: 140px;
    vertical-align: top;
}

.bpm-action-message {
    min-height: 18px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
    margin-bottom: 4px;
    text-align: center;
    transition: all 0.3s ease;
}

.bpm-action-message.loading {
    color: #0073aa;
}

.bpm-action-message.success {
    color: #00a32a;
}

.bpm-action-message.error {
    color: #d63638;
}

.bpm-action-message:empty {
    display: none;
}

.bpm-action-buttons {
    display: flex;
    justify-content: start;
    align-items: center;
    gap: 2px;
}

.bpm-icon-button {
    background: none;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    margin: 0 2px;
    padding: 0;
    transition: all 0.2s ease;
}

.bpm-icon-button:hover {
    border-color: #2271b1;
    background-color: #f6f7f7;
}

.bpm-icon-button:focus {
    box-shadow: 0 0 0 1px #2271b1;
    outline: 2px solid transparent;
}

.bpm-icon-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.bpm-icon-button .dashicons {
    font-size: 16px;
    line-height: 1;
    color: #50575e;
}

.bpm-icon-button:hover .dashicons {
    color: #2271b1;
}

/* Specific button colors */
.bpm-icon-button.edit-prize .dashicons-lock {
    color: #d63638; /* Red for locked/claimed */
}

.bpm-icon-button.edit-prize .dashicons-unlock {
    color: #00a32a; /* Green for unlocked/available */
}

.bpm-icon-button.delete-prize {
    border-color: #d63638;
}

.bpm-icon-button.delete-prize .dashicons {
    color: #d63638;
}

.bpm-icon-button.delete-prize:hover {
    background-color: #d63638;
    border-color: #d63638;
}

.bpm-icon-button.delete-prize:hover .dashicons {
    color: white;
}

.bpm-icon-button.reset-claim .dashicons {
    color: #f0b849; /* Orange for reset */
}

.bpm-icon-button.view-history .dashicons {
    color: #72aee6; /* Blue for history */
}

/* Spin animation for loading state */
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Mapping Mode Selection Styles */
#mapping-mode-selection label {
    display: inline-block;
    background: #f6f7f7;
    padding: 8px 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s;
}

#mapping-mode-selection label:hover {
    background: #e1e1e1;
}

#mapping-mode-selection input[type="radio"]:checked + label,
#mapping-mode-selection label:has(input[type="radio"]:checked) {
    background: #0073aa;
    color: white;
    border-color: #005a87;
}

#mapping-mode-selection input[type="radio"] {
    margin-right: 5px;
}

/* Mapping Section Styles */
.mapping-section {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
    background: #fff;
}

.mapping-section h4 {
    margin-top: 0;
    color: #23282d;
}

#direct-columns-preview table,
#custom-mapping-table,
#standard-mapping-table {
    margin-top: 10px;
}

#custom-fields-tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}

.custom-field-name {
    font-family: monospace;
    background: #f1f1f1;
}

.remove-custom-field {
    color: #a00;
}

.remove-custom-field:hover {
    color: #dc3232;
}

.text-center {
    text-align: center;
}

.required {
    color: #d63638;
    font-weight: bold;
}

/* Multi-Code Mapping Styles */
#multi-code-columns-container {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    max-height: 200px;
    overflow-y: auto;
}

#multi-code-columns-container label {
    cursor: pointer;
    padding: 2px 0;
    user-select: none;
}

#multi-code-columns-container label:hover {
    background: #e6f3ff;
}

#multi-code-preview-content table {
    margin-top: 10px;
}

#multi-code-preview-content code {
    background: #f1f1f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: monospace;
}

/* Column Management Styles */
#column-management {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
}

#column-management h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #23282d;
}

.column-management-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

#column-config-panel {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
    display: none;
}

#sortable-columns {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
}

.column-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border-bottom: 1px solid #eee;
    cursor: move;
    transition: background-color 0.2s ease;
}

.column-item:last-child {
    border-bottom: none;
}

.column-item:hover {
    background-color: #f0f6fc;
}

.column-item.ui-sortable-helper {
    background-color: #e1f3ff;
    border: 1px solid #2271b1;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.column-placeholder {
    height: 40px;
    background-color: #f0f6fc;
    border: 2px dashed #2271b1;
    margin: 2px 0;
    border-radius: 4px;
}

.drag-handle {
    color: #c3c4c7;
    margin-right: 8px;
    cursor: move;
}

.drag-handle:hover {
    color: #2271b1;
}

.column-label {
    display: flex;
    align-items: center;
    flex: 1;
    margin: 0;
    cursor: pointer;
}

.column-visibility-toggle {
    margin-right: 8px;
}

.column-name {
    font-weight: 500;
    color: #23282d;
}


/* Column Management Icon Buttons */
#configure-columns-btn, #reset-columns-btn {
    background: none !important;
    border: none !important;
    padding: 4px !important;
    cursor: pointer !important;
    color: #646970 !important;
    transition: color 0.2s ease !important;
    border-radius: 3px !important;
}

#configure-columns-btn:hover, #reset-columns-btn:hover {
    color: #2271b1 !important;
    background: rgba(34, 113, 177, 0.1) !important;
}

#configure-columns-btn:focus, #reset-columns-btn:focus {
    outline: 2px solid #2271b1 !important;
    outline-offset: 1px !important;
    box-shadow: none !important;
}

#configure-columns-btn .dashicons, #reset-columns-btn .dashicons {
    font-size: 20px !important;
    width: 20px !important;
    height: 20px !important;
    line-height: 1 !important;
}

.tablenav .alignright {
    float: right !important;
}

/* Column Configuration Dialog */
.column-dialog-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.column-dialog {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    max-width: 800px;
    width: 50%;
    overflow: hidden;
}

.column-dialog-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px 15px 25px;
    border-bottom: 1px solid #ddd;
    background: #f8f9fa;
}

.column-dialog-header h3 {
    margin: 0;
    color: #23282d;
    font-size: 18px;
}

.column-dialog-close {
    background: none !important;
    border: none !important;
    padding: 5px !important;
    cursor: pointer !important;
    color: #646970 !important;
    border-radius: 4px !important;
    transition: all 0.2s ease !important;
}

.column-dialog-close:hover {
    color: #d63638 !important;
    background: rgba(214, 54, 56, 0.1) !important;
}

.column-dialog-close .dashicons {
    font-size: 18px !important;
    width: 18px !important;
    height: 18px !important;
}

.column-dialog-content {
    padding: 25px;
    max-height: calc(80vh - 140px);
    overflow-y: auto;
}

.column-dialog-footer {
    padding: 15px 25px;
    border-top: 1px solid #ddd;
    background: #f8f9fa;
    text-align: right;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

body.column-dialog-open {
    overflow: hidden;
}

/* Enhanced sortable columns styling */
#sortable-columns .column-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    cursor: move;
    transition: all 0.2s ease;
    background: #fff;
}

#sortable-columns .column-item:last-child {
    border-bottom: none;
}

#sortable-columns .column-item:hover {
    background-color: #f0f6fc;
}

#sortable-columns .column-item.ui-sortable-helper {
    background-color: #e1f3ff;
    border: 1px solid #2271b1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    border-radius: 4px;
}

.column-placeholder {
    height: 44px;
    background-color: #f0f6fc;
    border: 2px dashed #2271b1;
    margin: 2px 0;
    border-radius: 4px;
}

.drag-handle {
    color: #c3c4c7;
    margin-right: 10px;
    cursor: move;
    font-size: 16px;
}

.drag-handle:hover {
    color: #2271b1;
}

.column-label {
    display: flex;
    align-items: center;
    flex: 1;
    margin: 0;
    cursor: pointer;
    font-weight: 500;
}

.column-visibility-toggle {
    margin-right: 10px;
}

.column-name {
    color: #23282d;
}
</style>

<!-- Reseed Prize Dialog -->
<div id="reseed-dialog" style="display: none;" title="<?php _e('Reseed Unclaimed Prizes', 'britvic-promo'); ?>">
    <div id="reseed-loading" style="text-align: center; display: none;">
        <p><?php _e('Loading prize information...', 'britvic-promo'); ?></p>
        <div class="spinner is-active" style="float: none; margin: 0 auto;"></div>
    </div>
    
    <div id="reseed-content" style="display: none;">

        <div id="reseed-selected-info" style="display: none;" class="reseed-info">
            <h3><?php _e('Selected Prizes', 'britvic-promo'); ?></h3>
            <div class="info-grid">
                <div class="info-item">
                    <strong><?php _e('Mode:', 'britvic-promo'); ?></strong>
                    <span><?php _e('Selected only', 'britvic-promo'); ?></span>
                </div>
                <div class="info-item">
                    <strong><?php _e('Prizes to reseed:', 'britvic-promo'); ?></strong>
                    <span id="reseed-selected-info-count" class="highlight">0</span>
                </div>
                <div class="info-item" style="grid-column: span 2;">
                    <strong><?php _e('Note:', 'britvic-promo'); ?></strong>
                    <span><?php _e('All other prizes will be left unchanged.', 'britvic-promo'); ?></span>
                </div>
            </div>
        </div>

        <div id="reseed-all-info" class="reseed-info">
            <h3><?php _e('Prize Information', 'britvic-promo'); ?></h3>
            <div class="info-grid">
                <div class="info-item">
                    <strong><?php _e('Competition:', 'britvic-promo'); ?></strong>
                    <span id="competition-name"></span>
                </div>
                <div class="info-item">
                    <strong><?php _e('Total Prizes:', 'britvic-promo'); ?></strong>
                    <span id="total-prizes"></span>
                </div>
                <div class="info-item">
                    <strong><?php _e('Unclaimed Prizes:', 'britvic-promo'); ?></strong>
                    <span id="unclaimed-count" class="highlight"></span>
                </div>
                <div class="info-item">
                    <strong><?php _e('Previously Reseeded:', 'britvic-promo'); ?></strong>
                    <span id="reseeded-count"></span>
                </div>
            </div>
        </div>
        
        <div class="reseed-config">
            <h3><?php _e('New Prize Window Configuration', 'britvic-promo'); ?></h3>
            <p class="description"><?php _e('Set the date range for reseeding unclaimed prizes. This will overwrite the existing dates in both wp_bpm_prize_entries and wp_post_meta.', 'britvic-promo'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th><label for="reseed-start-date"><?php _e('Start Date:', 'britvic-promo'); ?></label></th>
                    <td><input type="date" id="reseed-start-date" required /></td>
                </tr>
                <tr>
                    <th><label for="reseed-end-date"><?php _e('End Date:', 'britvic-promo'); ?></label></th>
                    <td><input type="date" id="reseed-end-date" required /></td>
                </tr>
                <tr>
                    <th><label for="reseed-start-time"><?php _e('Start Time:', 'britvic-promo'); ?></label></th>
                    <td><input type="time" id="reseed-start-time" value="09:00" /></td>
                </tr>
                <tr>
                    <th><label for="reseed-end-time"><?php _e('End Time:', 'britvic-promo'); ?></label></th>
                    <td><input type="time" id="reseed-end-time" value="17:00" /></td>
                </tr>
            </table>
        </div>
    </div>
    
</div>

<style>
.reseed-info {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 10px;
}

.info-item {
    padding: 8px 0;
}

.info-item strong {
    display: inline-block;
    width: 120px;
}

.highlight {
    color: #d63638;
    font-weight: bold;
    font-size: 1.1em;
}

.reseed-config {
    padding: 15px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #fff;
}

.reseed-config h3 {
    margin-top: 0;
    color: #1d2327;
}

.reseed-config .description {
    margin-bottom: 15px;
    font-style: italic;
    color: #646970;
}

#reseed-dialog .form-table th {
    width: 150px;
    padding-left: 0;
}

#reseed-dialog .form-table td {
    padding-left: 10px;
}

#reseed-dialog input[type="date"],
#reseed-dialog input[type="time"] {
    width: 200px;
}
</style>