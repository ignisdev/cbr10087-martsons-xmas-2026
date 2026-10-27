<?php
/**
 * Admin Dashboard Page
 *
 * Main dashboard for the Britvic Promotion Manager
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get dashboard statistics
$audit_stats = BPM_Audit_Logger::get_audit_statistics(0, 7); // Last 7 days
$scheduler = new BPM_Competition_Scheduler();

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="bpm-dashboard">
        <div class="bpm-dashboard-widgets">
            
            <!-- Quick Stats Widget -->
            <div class="bpm-widget">
                <div class="bmp-widget-header">
                    <h2><?php _e('Activity Summary (Last 7 Days)', 'britvic-promo'); ?></h2>
                </div>
                <div class="bpm-widget-content">
                    <div class="bpm-stats-grid">
                        <div class="bpm-stat-item">
                            <div class="bpm-stat-number"><?php echo esc_html($audit_stats['total_events']); ?></div>
                            <div class="bpm-stat-label"><?php _e('Total Events', 'britvic-promo'); ?></div>
                        </div>
                        
                        <?php foreach ($audit_stats['actions'] as $action): ?>
                            <div class="bpm-stat-item">
                                <div class="bpm-stat-number"><?php echo esc_html($action['count']); ?></div>
                                <div class="bpm-stat-label"><?php echo esc_html(ucwords(str_replace('_', ' ', $action['action']))); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Widget -->
            <div class="bpm-widget">
                <div class="bpm-widget-header">
                    <h2><?php _e('Quick Actions', 'britvic-promo'); ?></h2>
                </div>
                <div class="bpm-widget-content">
                    <div class="bpm-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=britvic-competitions'); ?>" class="button button-primary">
                            <?php _e('Create Competition', 'britvic-promo'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=britvic-prizes'); ?>" class="button">
                            <?php _e('Generate Prizes', 'britvic-promo'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=britvic-pages'); ?>" class="button">
                            <?php _e('Manage Pages', 'britvic-promo'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=britvic-audit'); ?>" class="button">
                            <?php _e('View Audit Log', 'britvic-promo'); ?>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity Widget -->
            <div class="bpm-widget bpm-widget-full">
                <div class="bpm-widget-header">
                    <h2><?php _e('Recent Activity', 'britvic-promo'); ?></h2>
                </div>
                <div class="bpm-widget-content">
                    <?php 
                    $recent_logs = BPM_Audit_Logger::get_audit_logs([], 10, 0);
                    if (!empty($recent_logs)): 
                    ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Time', 'britvic-promo'); ?></th>
                                    <th><?php _e('Action', 'britvic-promo'); ?></th>
                                    <th><?php _e('Details', 'britvic-promo'); ?></th>
                                    <th><?php _e('User', 'britvic-promo'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="bpm-timestamp" title="<?php echo esc_attr($log['created_at']); ?>">
                                                <?php echo esc_html(human_time_diff(strtotime($log['created_at'])) . ' ago'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="bpm-action bpm-action-<?php echo esc_attr($log['level']); ?>">
                                                <?php echo esc_html(ucwords(str_replace('_', ' ', $log['action']))); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html(wp_trim_words($log['details'], 10)); ?></td>
                                        <td><?php echo esc_html($log['user_display_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php _e('No recent activity found.', 'britvic-promo'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>
</div>

<style>
.bpm-dashboard {
    margin-top: 20px;
}

.bpm-dashboard-widgets {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.bpm-widget {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.bpm-widget-full {
    grid-column: 1 / -1;
}

.bpm-widget-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    background: #f9f9f9;
}

.bpm-widget-header h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.bpm-widget-content {
    padding: 20px;
}

.bpm-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 20px;
}

.bpm-stat-item {
    text-align: center;
}

.bmp-stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #2271b1;
    line-height: 1;
}

.bpm-stat-label {
    font-size: 13px;
    color: #646970;
    margin-top: 5px;
}

.bpm-quick-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.bpm-action {
    font-weight: 500;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}

.bpm-action-info {
    background: #e1f5fe;
    color: #0277bd;
}

.bpm-action-warning {
    background: #fff3e0;
    color: #f57c00;
}

.bpm-action-error {
    background: #ffebee;
    color: #d32f2f;
}

.bpm-timestamp {
    color: #646970;
    font-size: 13px;
}

@media (max-width: 782px) {
    .bpm-dashboard-widgets {
        grid-template-columns: 1fr;
    }
    
    .bpm-quick-actions {
        flex-direction: column;
    }
    
    .bpm-quick-actions .button {
        width: 100%;
        text-align: center;
    }
}
</style>