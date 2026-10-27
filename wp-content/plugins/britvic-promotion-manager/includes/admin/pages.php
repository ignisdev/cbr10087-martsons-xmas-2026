<?php
/**
 * Pages Admin Page
 *
 * Manage page publishing and automation
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$selected_competition = isset($_GET['competition_id']) ? (int) $_GET['competition_id'] : 0;

// Get competitions for dropdown
global $wpdb;
$competitions = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}bpm_competitions ORDER BY created_at DESC",
    ARRAY_A
);

// Auto-select the only competition when one exists and none chosen.
if ( ! $selected_competition && is_array( $competitions ) && count( $competitions ) === 1 ) {
    $only = reset( $competitions );
    $selected_competition = is_array( $only ) ? (int) $only['id'] : (int) $only->id;
}

// Handle page toggle actions
if (isset($_POST['toggle_pages']) && wp_verify_nonce($_POST['bpm_nonce'], 'toggle_pages')) {
    $action = sanitize_text_field($_POST['page_action']);
    
    if ($selected_competition && in_array($action, ['publish', 'close'])) {
        try {
            $page_manager = new BPM_Page_Manager();
            $result = $page_manager->toggle_competition_pages($selected_competition, $action);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// Handle scheduling
if (isset($_POST['schedule_toggle']) && wp_verify_nonce($_POST['bpm_nonce'], 'schedule_toggle')) {
    $action = sanitize_text_field($_POST['schedule_action']);
    $datetime = sanitize_text_field($_POST['schedule_datetime']);
    
    if ($selected_competition && $datetime && in_array($action, ['publish', 'close'])) {
        try {
            $page_manager = new BPM_Page_Manager();
            $timestamp = strtotime($datetime);
            $result = $page_manager->schedule_page_toggle($selected_competition, $action, $timestamp);
            
            if ($result) {
                echo '<div class="notice notice-success"><p>' . 
                    sprintf(__('Scheduled %s action for %s', 'britvic-promo'), $action, date('d/m/Y H:i', $timestamp)) . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('Failed to schedule action', 'britvic-promo') . '</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// Handle scheduled action management
if (isset($_POST['delete_scheduled_action']) && wp_verify_nonce($_POST['bpm_nonce'], 'delete_scheduled_action')) {
    $competition_id = (int) $_POST['competition_id'];
    $action = sanitize_text_field($_POST['action_type']);
    
    if ($competition_id && $action) {
        try {
            $page_manager = new BPM_Page_Manager();
            $result = $page_manager->cancel_scheduled_toggle($competition_id, $action);
            
            if ($result) {
                echo '<div class="notice notice-success"><p>' . 
                    sprintf(__('Scheduled %s action has been cancelled.', 'britvic-promo'), $action) . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-info"><p>' . __('No scheduled action found to cancel.', 'britvic-promo') . '</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// Handle test scheduled action (immediate execution)
if (isset($_POST['test_scheduled_action']) && wp_verify_nonce($_POST['bpm_nonce'], 'test_scheduled_action')) {
    $competition_id = (int) $_POST['competition_id'];
    $action = sanitize_text_field($_POST['action_type']);
    
    if ($competition_id && $action) {
        try {
            $page_manager = new BPM_Page_Manager();
            $result = $page_manager->toggle_competition_pages($competition_id, $action);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . 
                    sprintf(__('Test execution completed: %s', 'britvic-promo'), esc_html($result['message'])) . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// Get page status for selected competition
$page_status = [];
if ($selected_competition) {
    $page_manager = new BPM_Page_Manager();
    $page_status = $page_manager->get_page_status($selected_competition);
}

// Get currently published pages
$published_pages = get_posts([
    'post_type' => 'page',
    'post_status' => 'publish',
    'posts_per_page' => -1
]);
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="bpm-admin-content">
        
        <!-- Competition Selection -->
        <div class="bpm-admin-section">
            <h2><?php _e('Select Competition', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-pages" />
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
        
        <?php if ($selected_competition): ?>
        
        <!-- Current Status -->
        <div class="bpm-admin-section">
            <h2><?php _e('Current Status', 'britvic-promo'); ?></h2>
            
            <?php if (!empty($page_status['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($page_status['error']); ?></p></div>
            <?php else: ?>
                <div class="status-grid">
                    <div class="status-item">
                        <h3><?php _e('Competition Status', 'britvic-promo'); ?></h3>
                        <span class="bpm-status bpm-status-<?php echo esc_attr($page_status['competition_status']); ?>">
                            <?php echo esc_html(ucfirst($page_status['competition_status'])); ?>
                        </span>
                    </div>
                    
                    <?php if ($page_status['live_page']): ?>
                    <div class="status-item">
                        <h3><?php _e('Live Page', 'britvic-promo'); ?></h3>
                        <p>
                            <strong><?php echo esc_html($page_status['live_page']['title']); ?></strong><br>
                            Status: <span class="page-status-<?php echo esc_attr($page_status['live_page']['status']); ?>">
                                <?php echo esc_html(ucfirst($page_status['live_page']['status'])); ?>
                            </span>
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($page_status['closed_page']): ?>
                    <div class="status-item">
                        <h3><?php _e('Closed Page', 'britvic-promo'); ?></h3>
                        <p>
                            <strong><?php echo esc_html($page_status['closed_page']['title']); ?></strong><br>
                            Status: <span class="page-status-<?php echo esc_attr($page_status['closed_page']['status']); ?>">
                                <?php echo esc_html(ucfirst($page_status['closed_page']['status'])); ?>
                            </span>
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="status-item">
                        <h3><?php _e('WordPress Front Page', 'britvic-promo'); ?></h3>
                        <?php if ($page_status['show_on_front'] === 'page' && $page_status['front_page']): ?>
                            <p>
                                <strong><?php echo esc_html($page_status['front_page']['title']); ?></strong><br>
                                Status: <span class="page-status-<?php echo esc_attr($page_status['front_page']['status']); ?>">
                                    <?php echo esc_html(ucfirst($page_status['front_page']['status'])); ?>
                                </span>
                                <?php if ($page_status['front_page']['is_competition_page']): ?>
                                    <br><small style="color: #00a32a;">✅ <?php _e('Competition Page Active', 'britvic-promo'); ?></small>
                                <?php else: ?>
                                    <br><small style="color: #d63638;">⚠️ <?php _e('Non-competition page', 'britvic-promo'); ?></small>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p>
                                <?php if ($page_status['show_on_front'] === 'posts'): ?>
                                    <em><?php _e('Showing latest posts (no static front page)', 'britvic-promo'); ?></em>
                                <?php else: ?>
                                    <em><?php _e('No front page configured', 'britvic-promo'); ?></em>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Comprehensive Scheduled Actions Management -->
                <div class="scheduled-actions-management">
                    <h3><?php _e('Active Scheduled Actions', 'britvic-promo'); ?></h3>
                    
                    <?php if (!empty($page_status['scheduled_publish']) || !empty($page_status['scheduled_close'])): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 15%;"><?php _e('Action', 'britvic-promo'); ?></th>
                                    <th style="width: 25%;"><?php _e('Scheduled Time', 'britvic-promo'); ?></th>
                                    <th style="width: 20%;"><?php _e('Time Until Execution', 'britvic-promo'); ?></th>
                                    <th style="width: 40%;"><?php _e('Actions', 'britvic-promo'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($page_status['scheduled_publish']): ?>
                                    <?php 
                                    $publish_timestamp = strtotime($page_status['scheduled_publish']);
                                    $time_diff = $publish_timestamp - time();
                                    $time_until = '';
                                    
                                    if ($time_diff > 0) {
                                        $hours = floor($time_diff / 3600);
                                        $minutes = floor(($time_diff % 3600) / 60);
                                        $time_until = sprintf('%d hours, %d minutes', $hours, $minutes);
                                    } else {
                                        $time_until = __('Overdue', 'britvic-promo');
                                    }
                                    ?>
                                    <tr>
                                        <td><span class="action-badge action-publish">🟢 <?php _e('Publish', 'britvic-promo'); ?></span></td>
                                        <td><?php echo esc_html(date('d/m/Y H:i', $publish_timestamp)); ?></td>
                                        <td><?php echo esc_html($time_until); ?></td>
                                        <td>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('test_scheduled_action', 'bpm_nonce'); ?>
                                                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                                                <input type="hidden" name="action_type" value="publish" />
                                                <button type="submit" name="test_scheduled_action" class="button button-small" title="<?php esc_attr_e('Test Now', 'britvic-promo'); ?>"
                                                        onclick="return confirm('<?php _e('Run this action immediately? This will publish the competition.', 'britvic-promo'); ?>')">
                                                    <i class="fa-solid fa-bolt" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Test Now', 'britvic-promo'); ?></span>
                                                </button>
                                            </form>

                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('delete_scheduled_action', 'bpm_nonce'); ?>
                                                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                                                <input type="hidden" name="action_type" value="publish" />
                                                <button type="submit" name="delete_scheduled_action" class="button button-small" title="<?php esc_attr_e('Cancel', 'britvic-promo'); ?>"
                                                        onclick="return confirm('<?php _e('Cancel this scheduled action?', 'britvic-promo'); ?>')">
                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Cancel', 'britvic-promo'); ?></span>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <?php if ($page_status['scheduled_close']): ?>
                                    <?php 
                                    $close_timestamp = strtotime($page_status['scheduled_close']);
                                    $time_diff = $close_timestamp - time();
                                    $time_until = '';
                                    
                                    if ($time_diff > 0) {
                                        $hours = floor($time_diff / 3600);
                                        $minutes = floor(($time_diff % 3600) / 60);
                                        $time_until = sprintf('%d hours, %d minutes', $hours, $minutes);
                                    } else {
                                        $time_until = __('Overdue', 'britvic-promo');
                                    }
                                    ?>
                                    <tr>
                                        <td><span class="action-badge action-close">🔴 <?php _e('Close', 'britvic-promo'); ?></span></td>
                                        <td><?php echo esc_html(date('d/m/Y H:i', $close_timestamp)); ?></td>
                                        <td><?php echo esc_html($time_until); ?></td>
                                        <td>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('test_scheduled_action', 'bpm_nonce'); ?>
                                                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                                                <input type="hidden" name="action_type" value="close" />
                                                <button type="submit" name="test_scheduled_action" class="button button-small" title="<?php esc_attr_e('Test Now', 'britvic-promo'); ?>"
                                                        onclick="return confirm('<?php _e('Run this action immediately? This will close the competition.', 'britvic-promo'); ?>')">
                                                    <i class="fa-solid fa-bolt" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Test Now', 'britvic-promo'); ?></span>
                                                </button>
                                            </form>

                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('delete_scheduled_action', 'bpm_nonce'); ?>
                                                <input type="hidden" name="competition_id" value="<?php echo $selected_competition; ?>" />
                                                <input type="hidden" name="action_type" value="close" />
                                                <button type="submit" name="delete_scheduled_action" class="button button-small" title="<?php esc_attr_e('Cancel', 'britvic-promo'); ?>"
                                                        onclick="return confirm('<?php _e('Cancel this scheduled action?', 'britvic-promo'); ?>')">
                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Cancel', 'britvic-promo'); ?></span>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-scheduled-actions">
                            <p><?php _e('No scheduled actions for this competition.', 'britvic-promo'); ?></p>
                            <p class="description"><?php _e('Use the scheduling form below to create automated page management actions.', 'britvic-promo'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Manual Page Control -->
        <div class="bpm-admin-section">
            <h2><?php _e('Manual Page Control', 'britvic-promo'); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('toggle_pages', 'bpm_nonce'); ?>
                
                <p><?php _e('Immediately change page status for this competition:', 'britvic-promo'); ?></p>
                
                <div class="button-group">
                    <button type="submit" name="toggle_pages" value="1" onclick="document.querySelector('[name=page_action]').value='publish'" class="button button-primary">
                        🟢 <?php _e('Publish Competition', 'britvic-promo'); ?>
                    </button>
                    
                    <button type="submit" name="toggle_pages" value="1" onclick="document.querySelector('[name=page_action]').value='close'" class="button button-secondary">
                        🔴 <?php _e('Close Competition', 'britvic-promo'); ?>
                    </button>
                </div>
                
                <input type="hidden" name="page_action" value="" />
                
                <p class="description">
                    <strong><?php _e('Publish:', 'britvic-promo'); ?></strong> <?php _e('Sets all pages to private, then publishes the competition live page', 'britvic-promo'); ?><br>
                    <strong><?php _e('Close:', 'britvic-promo'); ?></strong> <?php _e('Sets all pages to private, then publishes the competition closed page', 'britvic-promo'); ?>
                </p>
            </form>
        </div>
        
        <!-- Schedule Page Control -->
        <div class="bpm-admin-section">
            <h2><?php _e('Schedule Page Control', 'britvic-promo'); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('schedule_toggle', 'bpm_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schedule_action"><?php _e('Action', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="schedule_action" name="schedule_action" required>
                                <option value=""><?php _e('Select Action', 'britvic-promo'); ?></option>
                                <option value="publish"><?php _e('Publish Competition', 'britvic-promo'); ?></option>
                                <option value="close"><?php _e('Close Competition', 'britvic-promo'); ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="schedule_datetime"><?php _e('Date & Time', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="datetime-local" id="schedule_datetime" name="schedule_datetime" required />
                            <p class="description"><?php _e('When should this action be executed?', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Schedule Action', 'britvic-promo'), 'primary', 'schedule_toggle'); ?>
            </form>
        </div>
        
        <!-- Testing & Diagnostics -->
        <div class="bpm-admin-section">
            <h2><?php _e('Testing & Diagnostics', 'britvic-promo'); ?></h2>
            
            <div class="testing-info">
                <h3><?php _e('WordPress Cron Status', 'britvic-promo'); ?></h3>
                <p>
                    <strong><?php _e('Cron Status:', 'britvic-promo'); ?></strong> 
                    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                        <span style="color: #d63638;">❌ <?php _e('Disabled (DISABLE_WP_CRON = true)', 'britvic-promo'); ?></span>
                        <br><small><?php _e('Scheduled actions will not run automatically. Enable WP Cron or set up a server cron job.', 'britvic-promo'); ?></small>
                    <?php else: ?>
                        <span style="color: #00a32a;">✅ <?php _e('Enabled', 'britvic-promo'); ?></span>
                        <br><small><?php _e('Scheduled actions will run automatically when pages are visited.', 'britvic-promo'); ?></small>
                    <?php endif; ?>
                </p>
                
                <h3><?php _e('Current Server Time', 'britvic-promo'); ?></h3>
                <p>
                    <strong><?php _e('Server Time:', 'britvic-promo'); ?></strong> <?php echo current_time('Y-m-d H:i:s T'); ?><br>
                    <strong><?php _e('WordPress Time:', 'britvic-promo'); ?></strong> <?php echo current_time('Y-m-d H:i:s'); ?><br>
                    <small><?php _e('Use WordPress time for scheduling. Check your timezone settings if these differ significantly.', 'britvic-promo'); ?></small>
                </p>
                
                <?php if ($selected_competition): ?>
                <h3><?php _e('Quick Test Scheduling', 'britvic-promo'); ?></h3>
                <p><?php _e('Schedule an action to run in the next few minutes to test the system:', 'britvic-promo'); ?></p>
                
                <div class="test-scheduling">
                    <form method="post" action="">
                        <?php wp_nonce_field('schedule_toggle', 'bpm_nonce'); ?>
                        
                        <p>
                            <label>
                                <input type="radio" name="schedule_action" value="publish" required /> 
                                <?php _e('Test Publish (sets all pages private, publishes live page)', 'britvic-promo'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="schedule_action" value="close" required /> 
                                <?php _e('Test Close (sets all pages private, publishes closed page)', 'britvic-promo'); ?>
                            </label>
                        </p>
                        
                        <p>
                            <label for="test_minutes"><?php _e('Minutes from now:', 'britvic-promo'); ?></label>
                            <input type="number" id="test_minutes" min="1" max="60" value="2" style="width: 80px;" onchange="updateTestDateTime(this.value)" />
                            <input type="datetime-local" id="test_datetime" name="schedule_datetime" required readonly style="margin-left: 10px;" />
                        </p>
                        
                        <p>
                            <button type="submit" name="schedule_toggle" class="button button-secondary" 
                                    onclick="return confirm('<?php _e('Schedule a test action? This will affect your page status in a few minutes.', 'britvic-promo'); ?>')">
                                🧪 <?php _e('Schedule Test Action', 'britvic-promo'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- Currently Published Pages -->
        <div class="bpm-admin-section">
            <h2><?php _e('Currently Published Pages', 'britvic-promo'); ?></h2>
            
            <?php if (empty($published_pages)): ?>
                <p><?php _e('No pages are currently published.', 'britvic-promo'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Page Title', 'britvic-promo'); ?></th>
                            <th><?php _e('Slug', 'britvic-promo'); ?></th>
                            <th><?php _e('Template', 'britvic-promo'); ?></th>
                            <th><?php _e('Actions', 'britvic-promo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($published_pages as $page): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($page->post_title); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($page->post_name); ?></code>
                                </td>
                                <td>
                                    <?php 
                                    $template = get_post_meta($page->ID, '_wp_page_template', true);
                                    echo esc_html($template ?: 'default');
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo get_permalink($page->ID); ?>" target="_blank" class="button button-small" title="<?php esc_attr_e('View', 'britvic-promo'); ?>">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('View', 'britvic-promo'); ?></span>
                                    </a>
                                    <a href="<?php echo get_edit_post_link($page->ID); ?>" class="button button-small" title="<?php esc_attr_e('Edit', 'britvic-promo'); ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Edit', 'britvic-promo'); ?></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Help Section -->
        <div class="bpm-admin-section">
            <h2><?php _e('How Page Management Works', 'britvic-promo'); ?></h2>
            
            <div class="help-content">
                <h3><?php _e('Automated Page Switching', 'britvic-promo'); ?></h3>
                <p><?php _e('This replaces your manual bash script with automated WordPress functionality:', 'britvic-promo'); ?></p>
                
                <ol>
                    <li><strong><?php _e('Publish Competition:', 'britvic-promo'); ?></strong> <?php _e('Sets all published pages to private, publishes your designated live page, and sets it as the WordPress front page', 'britvic-promo'); ?></li>
                    <li><strong><?php _e('Close Competition:', 'britvic-promo'); ?></strong> <?php _e('Sets all published pages to private, publishes your designated closed page, and sets it as the WordPress front page', 'britvic-promo'); ?></li>
                    <li><strong><?php _e('Front Page Management:', 'britvic-promo'); ?></strong> <?php _e('Automatically configures WordPress Reading Settings to use a static front page instead of showing latest posts', 'britvic-promo'); ?></li>
                    <li><strong><?php _e('Scheduling:', 'britvic-promo'); ?></strong> <?php _e('Automatically executes these actions at specified times using WordPress cron', 'britvic-promo'); ?></li>
                </ol>
                
                <h3><?php _e('Page Configuration', 'britvic-promo'); ?></h3>
                <p><?php _e('Configure which pages to use in the Competition settings. The system will automatically detect pages with these templates:', 'britvic-promo'); ?></p>
                <ul>
                    <li><code>page-entry.php</code> - <?php _e('Live competition page', 'britvic-promo'); ?></li>
                    <li><code>page-closed.php</code> - <?php _e('Competition closed page', 'britvic-promo'); ?></li>
                </ul>
            </div>
        </div>
        
    </div>
</div>

<style>
.status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.status-item {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    border-left: 4px solid #2271b1;
}

.status-item h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
}

.button-group {
    display: flex;
    gap: 10px;
    margin: 15px 0;
}

.scheduled-actions {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    padding: 15px;
    margin-top: 20px;
}

.scheduled-actions h3 {
    margin-top: 0;
    color: #856404;
}

.page-status-publish {
    color: #00a32a;
    font-weight: 500;
}

.page-status-private {
    color: #d63638;
    font-weight: 500;
}

.page-status-draft {
    color: #646970;
    font-weight: 500;
}

.help-content {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
}

.help-content h3 {
    margin-top: 0;
    color: #1d2327;
}

.help-content ol,
.help-content ul {
    padding-left: 20px;
}

.help-content code {
    background: #f1f1f1;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}

/* Scheduled Actions Management */
.scheduled-actions-management {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-top: 20px;
}

.scheduled-actions-management h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #1d2327;
}

.no-scheduled-actions {
    text-align: center;
    padding: 40px 20px;
    background: #f9f9f9;
    border: 2px dashed #ddd;
    border-radius: 4px;
    margin: 20px 0;
}

.action-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    color: white;
}

.action-badge.action-publish {
    background-color: #00a32a;
}

.action-badge.action-close {
    background-color: #d63638;
}

/* Button improvements for scheduled actions */
.scheduled-actions-management .button {
    margin-right: 5px;
}

.scheduled-actions-management .button:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.scheduled-actions-management td {
    vertical-align: middle;
}

/* Live status indicators */
.scheduled-actions-management tr:hover {
    background-color: #f8f9fa;
}

.scheduled-actions-management tbody tr td:first-child {
    font-weight: 600;
}

/* Testing section styles */
.testing-info {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
}

.test-scheduling {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
}

.test-scheduling label {
    display: block;
    margin-bottom: 8px;
}
</style>

<script>
function updateTestDateTime(minutes) {
    const now = new Date();
    now.setMinutes(now.getMinutes() + parseInt(minutes));
    
    // Format for datetime-local input (YYYY-MM-DDTHH:MM)
    const formatted = now.getFullYear() + '-' + 
                     String(now.getMonth() + 1).padStart(2, '0') + '-' +
                     String(now.getDate()).padStart(2, '0') + 'T' +
                     String(now.getHours()).padStart(2, '0') + ':' +
                     String(now.getMinutes()).padStart(2, '0');
    
    document.getElementById('test_datetime').value = formatted;
}

// Initialize with default 2 minutes
document.addEventListener('DOMContentLoaded', function() {
    updateTestDateTime(2);
});
</script>