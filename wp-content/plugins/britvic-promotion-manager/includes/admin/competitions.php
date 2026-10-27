<?php
/**
 * Competitions Admin Page
 *
 * Create and manage competitions
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
 * Helper function to check if a column exists in a table
 */
function bpm_column_exists($table_name, $column_name) {
    global $wpdb;
    $column = $wpdb->get_results($wpdb->prepare(
        "SHOW COLUMNS FROM $table_name LIKE %s",
        $column_name
    ));
    return !empty($column);
}

/**
 * Build a human-readable reason for why BPM_Theme_Manager::set_competition_theme()
 * returned false, so the admin notice can say WHY the theme wasn't assigned
 * instead of silently dropping the selection.
 */
function bpm_theme_assignment_error_message(string $theme_id, string $competition_type): string {
    if (!class_exists('BPM_Theme_Manager')) {
        return sprintf(__('Theme "%s" could not be assigned.', 'britvic-promo'), $theme_id);
    }

    $theme = BPM_Theme_Manager::get_theme($theme_id);

    if (!$theme) {
        return sprintf(__('Theme "%s" could not be found and was not assigned.', 'britvic-promo'), $theme_id);
    }

    return sprintf(
        __('Theme "%1$s" does not support "%2$s" competitions, so it was not assigned. Update the theme\'s supported types on the Theme Library page, or choose a compatible theme.', 'britvic-promo'),
        $theme['name'],
        $competition_type
    );
}

// Handle form submissions
global $wpdb;

// Handle competition update
if (isset($_POST['update_competition']) && wp_verify_nonce($_POST['bpm_nonce'], 'update_competition')) {
    $competition_id = (int) $_POST['competition_id'];
    $competition_name = sanitize_text_field($_POST['competition_name']);
    $competition_type = sanitize_text_field($_POST['competition_type']);
    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date = sanitize_text_field($_POST['end_date']);
    $live_page_id = (int) $_POST['live_page_id'];
    $closed_page_id = (int) $_POST['closed_page_id'];
    $prize_page_id = (int) $_POST['prize_page_id'];
    $terms_page_id = (int) $_POST['terms_page_id'];
    $theme_id = sanitize_text_field($_POST['theme_id'] ?? '');
    
    // Prepare update data - check if prize_page_id column exists
    $table_name = $wpdb->prefix . 'bpm_competitions';
    $update_data = [
        'name' => $competition_name,
        'type' => $competition_type,
        'start_date' => $start_date ? $start_date . ':00' : null,
        'end_date' => $end_date ? $end_date . ':00' : null,
        'live_page_id' => $live_page_id ?: null,
        'closed_page_id' => $closed_page_id ?: null,
        'updated_at' => current_time('mysql')
    ];
    $format = ['%s', '%s', '%s', '%s', '%d', '%d', '%s'];
    
    // Only add prize_page_id if the column exists
    if (bpm_column_exists($table_name, 'prize_page_id')) {
        $update_data['prize_page_id'] = $prize_page_id ?: null;
        $format[] = '%d';
    }
    
    // Only add terms_page_id if the column exists
    if (bpm_column_exists($table_name, 'terms_page_id')) {
        $update_data['terms_page_id'] = $terms_page_id ?: null;
        $format[] = '%d';
    }
    
    // Update competition
    $result = $wpdb->update(
        $table_name,
        $update_data,
        ['id' => $competition_id],
        $format,
        ['%d']
    );
    
    if ($result !== false) {
        // Assign theme to competition if specified
        if ($theme_id && class_exists('BPM_Theme_Manager')) {
            if (!BPM_Theme_Manager::set_competition_theme($competition_id, $theme_id)) {
                echo '<div class="notice notice-warning"><p>' . esc_html(bpm_theme_assignment_error_message($theme_id, $competition_type)) . '</p></div>';
            }
        }

        echo '<div class="notice notice-success"><p>' . __('Competition updated successfully!', 'britvic-promo') . '</p></div>';

        BPM_Audit_Logger::log(
            $competition_id,
            'competition_updated',
            sprintf('Competition "%s" updated', $competition_name)
        );
    } else {
        echo '<div class="notice notice-error"><p>' . __('Failed to update competition.', 'britvic-promo') . '</p></div>';
    }
}

// Handle competition creation
if (isset($_POST['create_competition']) && wp_verify_nonce($_POST['bpm_nonce'], 'create_competition')) {
    $competition_name = sanitize_text_field($_POST['competition_name']);
    $competition_type = sanitize_text_field($_POST['competition_type']);
    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date = sanitize_text_field($_POST['end_date']);
    $live_page_id = (int) $_POST['live_page_id'];
    $closed_page_id = (int) $_POST['closed_page_id'];
    $prize_page_id = (int) $_POST['prize_page_id'];
    $terms_page_id = (int) $_POST['terms_page_id'];
    $theme_id = sanitize_text_field($_POST['theme_id'] ?? '');
    
    // Prepare insert data - check if prize_page_id column exists
    $table_name = $wpdb->prefix . 'bpm_competitions';
    $insert_data = [
        'name' => $competition_name,
        'type' => $competition_type,
        'status' => 'draft',
        'start_date' => $start_date ? $start_date . ':00' : null,
        'end_date' => $end_date ? $end_date . ':00' : null,
        'live_page_id' => $live_page_id ?: null,
        'closed_page_id' => $closed_page_id ?: null
    ];
    $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%d'];
    
    // Only add prize_page_id if the column exists
    if (bpm_column_exists($table_name, 'prize_page_id')) {
        $insert_data['prize_page_id'] = $prize_page_id ?: null;
        $format[] = '%d';
    }
    
    // Only add terms_page_id if the column exists
    if (bpm_column_exists($table_name, 'terms_page_id')) {
        $insert_data['terms_page_id'] = $terms_page_id ?: null;
        $format[] = '%d';
    }
    
    // Insert competition
    $result = $wpdb->insert(
        $table_name,
        $insert_data,
        $format
    );
    
    if ($result) {
        $competition_id = $wpdb->insert_id;

        // Assign theme to competition if specified
        if ($theme_id && class_exists('BPM_Theme_Manager')) {
            if (!BPM_Theme_Manager::set_competition_theme($competition_id, $theme_id)) {
                echo '<div class="notice notice-warning"><p>' . esc_html(bpm_theme_assignment_error_message($theme_id, $competition_type)) . '</p></div>';
            }
        }

        echo '<div class="notice notice-success"><p>' . __('Competition created successfully!', 'britvic-promo') . '</p></div>';
        
        BPM_Audit_Logger::log(
            $competition_id,
            'competition_created',
            sprintf('Competition "%s" created', $competition_name)
        );
    } else {
        echo '<div class="notice notice-error"><p>' . __('Failed to create competition.', 'britvic-promo') . '</p></div>';
    }
}

// Handle competition copy
if (isset($_GET['copy']) && is_numeric($_GET['copy'])) {
    $source_id = (int) $_GET['copy'];

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to do this.', 'britvic-promo'));
    }

    check_admin_referer('bpm_copy_competition_' . $source_id);

    $table_name = $wpdb->prefix . 'bpm_competitions';
    $source = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $source_id),
        ARRAY_A
    );

    if (!$source) {
        echo '<div class="notice notice-error"><p>' . __('Competition to copy was not found.', 'britvic-promo') . '</p></div>';
    } else {
        $insert_data = [
            'name' => $source['name'] . ' (Copy)',
            'type' => $source['type'],
            'status' => 'draft',
            'start_date' => null,
            'end_date' => null,
            'live_page_id' => $source['live_page_id'],
            'closed_page_id' => $source['closed_page_id'],
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%d'];

        if (bpm_column_exists($table_name, 'prize_page_id')) {
            $insert_data['prize_page_id'] = $source['prize_page_id'] ?? null;
            $format[] = '%d';
        }
        if (bpm_column_exists($table_name, 'terms_page_id')) {
            $insert_data['terms_page_id'] = $source['terms_page_id'] ?? null;
            $format[] = '%d';
        }

        $result = $wpdb->insert($table_name, $insert_data, $format);

        if ($result) {
            $new_id = $wpdb->insert_id;

            // Copy reusable per-competition configuration (options keyed by competition ID).
            // Deliberately excludes prize entries, audit log, email log, and user blocks,
            // which are transactional/historical and must never be duplicated.
            $option_prefixes_to_copy = [
                'bpm_brand_',
                'bpm_competition_theme_',
                'bpm_game_config_',
                'bpm_form_settings_',
                'bpm_prize_window_',
                'bpm_generation_prize_types_',
            ];
            foreach ($option_prefixes_to_copy as $prefix) {
                $value = get_option($prefix . $source_id);
                if ($value !== false) {
                    update_option($prefix . $new_id, $value);
                }
            }

            BPM_Audit_Logger::log(
                $new_id,
                'competition_copied',
                sprintf('Competition "%s" copied from competition %d ("%s")', $insert_data['name'], $source_id, $source['name'])
            );

            wp_safe_redirect(admin_url('admin.php?page=britvic-competitions&edit=' . $new_id));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>' . __('Failed to copy competition.', 'britvic-promo') . '</p></div>';
        }
    }
}

// Handle setting the active competition (the switch that
// bpm_get_active_competition_id() actually reads — see britvic-promotion-manager.php).
if (isset($_GET['activate']) && is_numeric($_GET['activate'])) {
    $activate_id = (int) $_GET['activate'];

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to do this.', 'britvic-promo'));
    }

    check_admin_referer('bpm_activate_competition_' . $activate_id);

    $table_name = $wpdb->prefix . 'bpm_competitions';
    $competition_to_activate = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $activate_id),
        ARRAY_A
    );

    if (!$competition_to_activate) {
        echo '<div class="notice notice-error"><p>' . __('Competition to activate was not found.', 'britvic-promo') . '</p></div>';
    } else {
        update_option('bpm_active_competition_id', $activate_id);

        BPM_Audit_Logger::log(
            $activate_id,
            'competition_activated',
            sprintf('Competition "%s" set as the active competition', $competition_to_activate['name'])
        );

        wp_safe_redirect(add_query_arg(
            ['page' => 'britvic-competitions', 'bpm_activated' => $activate_id],
            admin_url('admin.php')
        ));
        exit;
    }
}

if (isset($_GET['bpm_activated']) && is_numeric($_GET['bpm_activated'])) {
    echo '<div class="notice notice-success"><p>' . sprintf(__('Competition #%d is now the active competition.', 'britvic-promo'), (int) $_GET['bpm_activated']) . '</p></div>';
}

// Check if we're editing a competition
$edit_competition = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $table_name = $wpdb->prefix . 'bpm_competitions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $edit_competition = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}bpm_competitions WHERE id = %d", $edit_id),
            ARRAY_A
        );
        
        // Debug: Log if competition was found
        if (!$edit_competition) {
            // error_log("BPM Debug: Competition with ID $edit_id not found in database");
        } else {
            // error_log("BPM Debug: Found competition: " . print_r($edit_competition, true));
        }
    }
}

// Get existing competitions
$competitions = [];

// Check if table exists before querying
$table_name = $wpdb->prefix . 'bpm_competitions';
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $competitions = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}bpm_competitions ORDER BY created_at DESC",
        ARRAY_A
    );
    
    if ($wpdb->last_error) {
        echo '<div class="notice notice-error"><p>' . sprintf(__('Database error: %s', 'britvic-promo'), esc_html($wpdb->last_error)) . '</p></div>';
    }
} else {
    echo '<div class="notice notice-warning"><p>' . __('Competition database table not found. Please activate the plugin to create required tables.', 'britvic-promo') . '</p></div>';
}

// Which competition's assets/pages actually resolve on the front end —
// see bpm_get_active_competition_id() in britvic-promotion-manager.php.
$active_competition_id = (int) get_option('bpm_active_competition_id', 0);

// Get pages for dropdowns
$pages = get_pages();
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php if (isset($_GET['edit']) && $edit_competition): ?>
        <!-- Debug info for edit mode -->
        <div class="notice notice-info" style="background-color: #f0f8ff; border-left-color: #007cba;">
            <p><strong>Debug:</strong> Editing Competition ID: <?php echo esc_html($edit_competition['id']); ?> - 
            Name: "<?php echo esc_html($edit_competition['name']); ?>"</p>
        </div>
    <?php elseif (isset($_GET['edit'])): ?>
        <!-- Debug info when edit ID provided but no competition found -->
        <div class="notice notice-error">
            <p><strong>Debug:</strong> Edit ID provided (<?php echo esc_html($_GET['edit']); ?>) but no competition found in database.</p>
        </div>
    <?php endif; ?>
    
    <div class="bpm-admin-content">

        <!-- Existing Competitions -->
        <?php if (!$edit_competition): ?>
        <div class="bpm-admin-section">
            <h2><?php _e('Existing Competitions', 'britvic-promo'); ?></h2>

            <?php if (empty($competitions)): ?>
                <p><?php _e('No competitions found. Create your first competition above.', 'britvic-promo'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Name', 'britvic-promo'); ?></th>
                            <th><?php _e('Type', 'britvic-promo'); ?></th>
                            <th><?php _e('Status', 'britvic-promo'); ?></th>
                            <th><?php _e('Start Date', 'britvic-promo'); ?></th>
                            <th><?php _e('End Date', 'britvic-promo'); ?></th>
                            <th><?php _e('Actions', 'britvic-promo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($competitions as $competition): ?>
                            <tr>
                                <td><strong><?php echo esc_html($competition['name']); ?></strong></td>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $competition['type']))); ?></td>
                                <td>
                                    <span class="bpm-status bpm-status-<?php echo esc_attr($competition['status']); ?>">
                                        <?php echo esc_html(ucfirst($competition['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $competition['start_date'] ? esc_html(date('d/m/Y H:i', strtotime($competition['start_date']))) : '—'; ?></td>
                                <td><?php echo $competition['end_date'] ? esc_html(date('d/m/Y H:i', strtotime($competition['end_date']))) : '—'; ?></td>
                                <td>
                                    <?php if ((int) $competition['id'] === $active_competition_id): ?>
                                        <span class="bpm-active-badge"><i class="fa-solid fa-check" aria-hidden="true"></i> <?php _e('Active', 'britvic-promo'); ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=britvic-competitions&activate=' . $competition['id']), 'bpm_activate_competition_' . $competition['id'])); ?>" class="button button-small button-primary" title="<?php esc_attr_e('Set as the active competition', 'britvic-promo'); ?>" onclick="return confirm('<?php echo esc_js(__('Make this the active competition? Its assets and pages will start being served on the front end instead of the current active competition.', 'britvic-promo')); ?>');">
                                            <i class="fa-solid fa-power-off" aria-hidden="true"></i> <?php _e('Activate', 'britvic-promo'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo admin_url('admin.php?page=britvic-competitions&edit=' . $competition['id']); ?>" class="button button-small" title="<?php esc_attr_e('Edit', 'britvic-promo'); ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Edit', 'britvic-promo'); ?></span>
                                    </a>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=britvic-competitions&copy=' . $competition['id']), 'bpm_copy_competition_' . $competition['id'])); ?>" class="button button-small" title="<?php esc_attr_e('Copy', 'britvic-promo'); ?>" onclick="return confirm('<?php echo esc_js(__('Copy this competition? Pages, theme, and brand/game/form config will be duplicated. Prize entries and history are not copied.', 'britvic-promo')); ?>');">
                                        <i class="fa-solid fa-copy" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Copy', 'britvic-promo'); ?></span>
                                    </a>
                                    <a href="<?php echo admin_url('admin.php?page=britvic-prizes&competition_id=' . $competition['id']); ?>" class="button button-small" title="<?php esc_attr_e('Prizes', 'britvic-promo'); ?>">
                                        <i class="fa-solid fa-trophy" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Prizes', 'britvic-promo'); ?></span>
                                    </a>
                                    <a href="<?php echo admin_url('admin.php?page=britvic-pages&competition_id=' . $competition['id']); ?>" class="button button-small" title="<?php esc_attr_e('Pages', 'britvic-promo'); ?>">
                                        <i class="fa-solid fa-file-lines" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Pages', 'britvic-promo'); ?></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Create/Edit Competition -->
        <div class="bpm-admin-section">
            <h2>
                <?php 
                if ($edit_competition) {
                    printf(__('Edit Competition: %s', 'britvic-promo'), esc_html($edit_competition['name']));
                } else {
                    _e('Create New Competition', 'britvic-promo');
                }
                ?>
            </h2>
            
            <form method="post" action="">
                <?php 
                if ($edit_competition) {
                    wp_nonce_field('update_competition', 'bpm_nonce');
                    echo '<input type="hidden" name="competition_id" value="' . esc_attr($edit_competition['id']) . '" />';
                } else {
                    wp_nonce_field('create_competition', 'bpm_nonce');
                }
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="competition_name"><?php _e('Competition Name', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="competition_name" name="competition_name" class="regular-text" 
                                   value="<?php echo $edit_competition ? esc_attr($edit_competition['name']) : ''; ?>" required />
                            <p class="description"><?php _e('Enter a descriptive name for your competition', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="competition_type"><?php _e('Competition Type', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="competition_type" name="competition_type" required>
                                <option value=""><?php _e('Select Type', 'britvic-promo'); ?></option>
                                <option value="instant_win" <?php selected($edit_competition['type'] ?? '', 'instant_win'); ?>>
                                    <?php _e('Instant Win', 'britvic-promo'); ?>
                                </option>
                                <option value="prize_draw" <?php selected($edit_competition['type'] ?? '', 'prize_draw'); ?>>
                                    <?php _e('Prize Draw', 'britvic-promo'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('Choose the type of competition', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="theme_id"><?php _e('Theme', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <?php 
                            // Get current selection for edit mode
                            $selected_theme_id = '';
                            if ($edit_competition && class_exists('BPM_Theme_Manager')) {
                                $selected_theme_id = BPM_Theme_Manager::get_competition_theme((int) $edit_competition['id']);
                                echo '<!-- Selected theme ID in edit mode: ' . esc_html($selected_theme_id ?: 'none') . ' -->';
                            }
                            
                            // Get current active theme info
                            $current_theme = wp_get_theme();
                            $current_theme_slug = get_option('stylesheet');
                            echo '<!-- Current active theme: ' . esc_html($current_theme_slug) . ' -->';
                            
                            // Get registered themes
                            $registered_themes = [];
                            if (class_exists('BPM_Theme_Manager')) {
                                $registered_themes = BPM_Theme_Manager::get_registered_themes();
                                echo '<!-- Registered themes count: ' . count($registered_themes) . ' -->';
                            }
                            
                            $none_selected = empty($selected_theme_id) ? 'selected="selected"' : '';
                            ?>
                            
                            <select id="theme_id" name="theme_id" style="min-width: 200px;" class="regular-text">
                                <option value="" <?php echo $none_selected; ?>><?php _e('Select Theme (Optional)', 'britvic-promo'); ?></option>
                                
                                <?php if (!empty($registered_themes)): ?>
                                    <?php foreach ($registered_themes as $theme_id => $theme_config): ?>
                                        <?php 
                                        $is_selected = ($selected_theme_id === $theme_id) ? 'selected="selected"' : '';
                                        $is_active = ($theme_id === $current_theme_slug) ? ' (Current Active)' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($theme_id); ?>" <?php echo $is_selected; ?>>
                                            <?php echo esc_html($theme_config['name']); ?><?php echo $is_active; ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No themes registered</option>
                                <?php endif; ?>
                                
                                <?php 
                                // Debug: Check if BPM_Theme_Manager is loaded
                                if (class_exists('BPM_Theme_Manager')) {
                                    echo '<!-- BPM_Theme_Manager class is loaded -->';
                                    $registered_themes = BPM_Theme_Manager::get_registered_themes();
                                    echo '<!-- Registered themes count: ' . count($registered_themes) . ' -->';
                                } else {
                                    echo '<!-- BPM_Theme_Manager class NOT loaded -->';
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Select which theme to use for this competition\'s pages', 'britvic-promo'); ?></p>
                            
                            <!-- Debug JavaScript to test dropdown functionality -->
                            <script>
                            jQuery(document).ready(function($) {
                                // Test dropdown is selectable
                                $('#theme_id').on('change', function() {
                                    console.log('Theme dropdown changed to:', $(this).val());
                                });
                                
                                // Make sure it's not disabled
                                $('#theme_id').prop('disabled', false);
                                
                                console.log('Theme dropdown initialized. Options count:', $('#theme_id option').length);
                            });
                            </script>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="start_date"><?php _e('Start Date & Time', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <?php 
                            $start_date_value = '';
                            if ($edit_competition && $edit_competition['start_date']) {
                                $start_date_value = date('Y-m-d\TH:i', strtotime($edit_competition['start_date']));
                            }
                            ?>
                            <input type="datetime-local" id="start_date" name="start_date" 
                                   value="<?php echo esc_attr($start_date_value); ?>" />
                            <p class="description"><?php _e('When should the competition start? (optional)', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="end_date"><?php _e('End Date & Time', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <?php 
                            $end_date_value = '';
                            if ($edit_competition && $edit_competition['end_date']) {
                                $end_date_value = date('Y-m-d\TH:i', strtotime($edit_competition['end_date']));
                            }
                            ?>
                            <input type="datetime-local" id="end_date" name="end_date" 
                                   value="<?php echo esc_attr($end_date_value); ?>" />
                            <p class="description"><?php _e('When should the competition end? (optional)', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="live_page_id"><?php _e('Live Page', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="live_page_id" name="live_page_id">
                                <option value=""><?php _e('Select Live Page', 'britvic-promo'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo $page->ID; ?>" 
                                            <?php selected($edit_competition['live_page_id'] ?? '', $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Page to show when competition is active', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="closed_page_id"><?php _e('Closed Page', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="closed_page_id" name="closed_page_id">
                                <option value=""><?php _e('Select Closed Page', 'britvic-promo'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo $page->ID; ?>" 
                                            <?php selected($edit_competition['closed_page_id'] ?? '', $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Page to show when competition is closed', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="prize_page_id"><?php _e('Prize Display Page', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="prize_page_id" name="prize_page_id">
                                <option value=""><?php _e('Select Prize Display Page', 'britvic-promo'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo $page->ID; ?>" 
                                            <?php selected($edit_competition['prize_page_id'] ?? '', $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Page where prize results will be displayed (overrides theme default)', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="terms_page_id"><?php _e('Terms & Conditions Page', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="terms_page_id" name="terms_page_id">
                                <option value=""><?php _e('Select Terms & Conditions Page', 'britvic-promo'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo $page->ID; ?>" 
                                            <?php selected($edit_competition['terms_page_id'] ?? '', $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Page containing terms and conditions content (will be displayed in popup)', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div class="submit-section">
                    <?php 
                    if ($edit_competition) {
                        echo '<a href="' . admin_url('admin.php?page=britvic-competitions') . '" class="button button-large" style="margin-right: 10px;">' . __('← Back to List', 'britvic-promo') . '</a>';
                        submit_button(__('Update Competition', 'britvic-promo'), 'primary large', 'update_competition', false);
                    } else {
                        submit_button(__('Create Competition', 'britvic-promo'), 'primary', 'create_competition');
                    }
                    ?>
                </div>
            </form>
        </div>

    </div>
</div>

<style>
.bpm-admin-content {
    max-width: 1200px;
}

.bpm-admin-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.bmp-admin-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.bpm-status {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.bpm-status-draft { background: #f0f0f1; color: #50575e; }
.bpm-status-scheduled { background: #fff3cd; color: #856404; }
.bpm-status-active { background: #d1ecf1; color: #0c5460; }
.bpm-status-closed { background: #f8d7da; color: #721c24; }

.bpm-active-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    background: #d1ecf1;
    color: #0c5460;
    white-space: nowrap;
}
</style>