<?php
/**
 * Audit Log Admin Page
 *
 * View comprehensive audit logs and activity tracking
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get filter parameters
$filters = [
    'competition_id' => isset($_GET['competition_id']) ? (int) $_GET['competition_id'] : 0,
    'action' => isset($_GET['audit_action']) ? sanitize_text_field($_GET['audit_action']) : '',
    'level' => isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '',
    'user_id' => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
    'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
    'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : ''
];

// Remove empty filters
$filters = array_filter($filters);

// Pagination
$page_num = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
$per_page = 50;
$offset = ($page_num - 1) * $per_page;

// Get audit logs
$audit_logs = BPM_Audit_Logger::get_audit_logs($filters, $per_page, $offset);

// Get total count for pagination
global $wpdb;
$where_clauses = ['1=1'];
$where_values = [];

if (!empty($filters['competition_id'])) {
    $where_clauses[] = 'competition_id = %d';
    $where_values[] = $filters['competition_id'];
}
if (!empty($filters['action'])) {
    $where_clauses[] = 'action = %s';
    $where_values[] = $filters['action'];
}
if (!empty($filters['level'])) {
    $where_clauses[] = 'level = %s';
    $where_values[] = $filters['level'];
}
if (!empty($filters['user_id'])) {
    $where_clauses[] = 'user_id = %d';
    $where_values[] = $filters['user_id'];
}
if (!empty($filters['date_from'])) {
    $where_clauses[] = 'created_at >= %s';
    $where_values[] = $filters['date_from'] . ' 00:00:00';
}
if (!empty($filters['date_to'])) {
    $where_clauses[] = 'created_at <= %s';
    $where_values[] = $filters['date_to'] . ' 23:59:59';
}

$where_clause = implode(' AND ', $where_clauses);
$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}bpm_audit_log WHERE {$where_clause}";

if (!empty($where_values)) {
    $count_query = $wpdb->prepare($count_query, ...$where_values);
}

$total_items = (int) $wpdb->get_var($count_query);
$total_pages = ceil($total_items / $per_page);

// Get competitions for filter dropdown
$competitions = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}bpm_competitions ORDER BY created_at DESC",
    ARRAY_A
);

// Get unique actions for filter dropdown
$unique_actions = $wpdb->get_col(
    "SELECT DISTINCT action FROM {$wpdb->prefix}bpm_audit_log ORDER BY action ASC"
);

// Get statistics
$stats = BPM_Audit_Logger::get_audit_statistics($filters['competition_id'] ?? 0, 30);

// Handle export request
if (isset($_GET['export']) && wp_verify_nonce($_GET['nonce'], 'export_audit')) {
    try {
        $export_result = BPM_Audit_Logger::export_audit_logs($filters);
        
        // Force download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($export_result['filename']) . '"');
        header('Content-Length: ' . filesize($export_result['file_path']));
        readfile($export_result['file_path']);
        
        // Clean up
        unlink($export_result['file_path']);
        exit;
        
    } catch (Exception $e) {
        echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
    }
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="bpm-admin-content">
        
        <!-- Statistics -->
        <div class="bpm-admin-section">
            <h2><?php _e('Activity Overview (Last 30 Days)', 'britvic-promo'); ?></h2>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?php echo esc_html($stats['total_events']); ?></div>
                    <div class="stat-label"><?php _e('Total Events', 'britvic-promo'); ?></div>
                </div>
                
                <?php foreach ($stats['levels'] as $level): ?>
                    <div class="stat-item stat-<?php echo esc_attr($level['level']); ?>">
                        <div class="stat-number"><?php echo esc_html($level['count']); ?></div>
                        <div class="stat-label"><?php echo esc_html(ucfirst($level['level'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($stats['actions'])): ?>
            <div class="top-actions">
                <h3><?php _e('Most Common Actions', 'britvic-promo'); ?></h3>
                <div class="actions-list">
                    <?php foreach (array_slice($stats['actions'], 0, 5) as $action): ?>
                        <div class="action-item">
                            <span class="action-name"><?php echo esc_html(ucwords(str_replace('_', ' ', $action['action']))); ?></span>
                            <span class="action-count"><?php echo esc_html($action['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Filters -->
        <div class="bpm-admin-section">
            <h2><?php _e('Filter Audit Logs', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-audit" />
                
                <div class="filter-grid">
                    <div class="filter-item">
                        <label for="competition_id"><?php _e('Competition', 'britvic-promo'); ?></label>
                        <select id="competition_id" name="competition_id">
                            <option value=""><?php _e('All Competitions', 'britvic-promo'); ?></option>
                            <?php foreach ($competitions as $competition): ?>
                                <option value="<?php echo $competition['id']; ?>" <?php selected($filters['competition_id'] ?? 0, $competition['id']); ?>>
                                    <?php echo esc_html($competition['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="audit_action"><?php _e('Action', 'britvic-promo'); ?></label>
                        <select id="audit_action" name="audit_action">
                            <option value=""><?php _e('All Actions', 'britvic-promo'); ?></option>
                            <?php foreach ($unique_actions as $action): ?>
                                <option value="<?php echo esc_attr($action); ?>" <?php selected($filters['action'] ?? '', $action); ?>>
                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $action))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="level"><?php _e('Level', 'britvic-promo'); ?></label>
                        <select id="level" name="level">
                            <option value=""><?php _e('All Levels', 'britvic-promo'); ?></option>
                            <option value="info" <?php selected($filters['level'] ?? '', 'info'); ?>><?php _e('Info', 'britvic-promo'); ?></option>
                            <option value="warning" <?php selected($filters['level'] ?? '', 'warning'); ?>><?php _e('Warning', 'britvic-promo'); ?></option>
                            <option value="error" <?php selected($filters['level'] ?? '', 'error'); ?>><?php _e('Error', 'britvic-promo'); ?></option>
                            <option value="critical" <?php selected($filters['level'] ?? '', 'critical'); ?>><?php _e('Critical', 'britvic-promo'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="date_from"><?php _e('From Date', 'britvic-promo'); ?></label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($filters['date_from'] ?? ''); ?>" />
                    </div>
                    
                    <div class="filter-item">
                        <label for="date_to"><?php _e('To Date', 'britvic-promo'); ?></label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($filters['date_to'] ?? ''); ?>" />
                    </div>
                </div>
                
                <div class="filter-actions">
                    <?php submit_button(__('Apply Filters', 'britvic-promo'), 'primary', 'filter', false); ?>
                    <a href="<?php echo admin_url('admin.php?page=britvic-audit'); ?>" class="button" title="<?php esc_attr_e('Clear Filters', 'britvic-promo'); ?>"><i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Clear Filters', 'britvic-promo'); ?></span></a>
                    <a href="<?php echo wp_nonce_url(add_query_arg(array_merge($_GET, ['export' => 'csv'])), 'export_audit', 'nonce'); ?>" class="button" title="<?php esc_attr_e('Export CSV', 'britvic-promo'); ?>"><i class="fa-solid fa-file-export" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Export CSV', 'britvic-promo'); ?></span></a>
                </div>
            </form>
        </div>
        
        <!-- Audit Logs Table -->
        <div class="bpm-admin-section">
            <h2>
                <?php _e('Audit Logs', 'britvic-promo'); ?>
                <span class="count">(<?php echo number_format($total_items); ?> <?php _e('entries', 'britvic-promo'); ?>)</span>
            </h2>
            
            <?php if (empty($audit_logs)): ?>
                <p><?php _e('No audit logs found matching your criteria.', 'britvic-promo'); ?></p>
            <?php else: ?>
                
                <!-- Pagination -->
                <div class="tablenav top">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page_num,
                            'type' => 'array'
                        ]);
                        
                        if ($page_links) {
                            echo '<span class="displaying-num">' . 
                                sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items)) . 
                                '</span>';
                            echo '<span class="pagination-links">' . implode(' ', $page_links) . '</span>';
                        }
                        ?>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-time"><?php _e('Time', 'britvic-promo'); ?></th>
                            <th scope="col" class="manage-column column-level"><?php _e('Level', 'britvic-promo'); ?></th>
                            <th scope="col" class="manage-column column-action"><?php _e('Action', 'britvic-promo'); ?></th>
                            <th scope="col" class="manage-column column-details"><?php _e('Details', 'britvic-promo'); ?></th>
                            <th scope="col" class="manage-column column-user"><?php _e('User', 'britvic-promo'); ?></th>
                            <th scope="col" class="manage-column column-ip"><?php _e('IP Address', 'britvic-promo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_logs as $log): ?>
                            <tr>
                                <td class="column-time">
                                    <span title="<?php echo esc_attr($log['created_at']); ?>">
                                        <?php echo esc_html(human_time_diff(strtotime($log['created_at'])) . ' ago'); ?>
                                    </span>
                                    <br>
                                    <small><?php echo esc_html(date('d/m/Y H:i:s', strtotime($log['created_at']))); ?></small>
                                </td>
                                <td class="column-level">
                                    <span class="audit-level audit-level-<?php echo esc_attr($log['level']); ?>">
                                        <?php echo esc_html(ucfirst($log['level'])); ?>
                                    </span>
                                </td>
                                <td class="column-action">
                                    <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $log['action']))); ?></strong>
                                    <?php if ($log['competition_id']): ?>
                                        <br><small>Competition ID: <?php echo esc_html($log['competition_id']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="column-details">
                                    <?php echo esc_html($log['details']); ?>
                                    <?php if (!empty($log['metadata']) && is_array($log['metadata'])): ?>
                                        <div class="metadata-toggle">
                                            <a href="#" onclick="jQuery(this).next().toggle(); return false;">
                                                <small><?php _e('Show metadata', 'britvic-promo'); ?></small>
                                            </a>
                                            <div style="display: none; margin-top: 5px;">
                                                <small><pre><?php echo esc_html(json_encode($log['metadata'], JSON_PRETTY_PRINT)); ?></pre></small>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="column-user">
                                    <?php if ($log['user_display_name']): ?>
                                        <?php echo esc_html($log['user_display_name']); ?>
                                        <br><small>ID: <?php echo esc_html($log['user_id']); ?></small>
                                    <?php else: ?>
                                        <em><?php _e('System', 'britvic-promo'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td class="column-ip">
                                    <code><?php echo esc_html($log['ip_address']); ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        if ($page_links) {
                            echo '<span class="displaying-num">' . 
                                sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items)) . 
                                '</span>';
                            echo '<span class="pagination-links">' . implode(' ', $page_links) . '</span>';
                        }
                        ?>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
        
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    border-top: 3px solid #2271b1;
}

.stat-item.stat-warning {
    border-top-color: #dba617;
}

.stat-item.stat-error {
    border-top-color: #d63638;
}

.stat-item.stat-critical {
    border-top-color: #dc3232;
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
    line-height: 1;
}

.stat-label {
    font-size: 13px;
    color: #646970;
    margin-top: 5px;
}

.top-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

.actions-list {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.action-item {
    background: #f0f0f1;
    padding: 8px 12px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.action-count {
    background: #2271b1;
    color: white;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: bold;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.filter-item label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.filter-item select,
.filter-item input {
    width: 100%;
}

.filter-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.audit-level {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.audit-level-info {
    background: #e1f5fe;
    color: #0277bd;
}

.audit-level-warning {
    background: #fff3e0;
    color: #f57c00;
}

.audit-level-error {
    background: #ffebee;
    color: #d32f2f;
}

.audit-level-critical {
    background: #fce4ec;
    color: #ad1457;
}

.column-time {
    width: 150px;
}

.column-level {
    width: 80px;
}

.column-action {
    width: 150px;
}

.column-user {
    width: 120px;
}

.column-ip {
    width: 120px;
}

.metadata-toggle pre {
    background: #f5f5f5;
    padding: 8px;
    border-radius: 3px;
    font-size: 11px;
    max-height: 200px;
    overflow-y: auto;
}

.count {
    color: #646970;
    font-weight: normal;
    font-size: 14px;
}
</style>