<?php
/**
 * User Blocking Management Admin Page
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


// Handle adding new blocked user
if (isset($_POST['add_blocked_user']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_add_blocked_user')) {
    $competition_id = (int) $_POST['competition_id'];
    $block_type = sanitize_text_field($_POST['block_type']);
    $block_value = sanitize_text_field($_POST['block_value']);
    $block_reason = sanitize_text_field($_POST['block_reason']);
    $block_behavior = sanitize_text_field($_POST['block_behavior']);
    
    if ($competition_id && $block_type && $block_value) {
        global $wpdb;
        $table = $wpdb->prefix . 'bpm_user_blocks';
        
        $result = $wpdb->insert(
            $table,
            [
                'competition_id' => $competition_id,
                'block_type' => $block_type,
                'block_value' => $block_value,
                'block_reason' => $block_reason,
                'block_behavior' => $block_behavior,
                'created_at' => current_time('mysql', true),
                'created_by' => get_current_user_id()
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d']
        );
        
        if ($result) {
            echo '<div class="notice notice-success"><p>Player access restriction added successfully!</p></div>';
            
            // Log the action
            BPM_Audit_Logger::log(
                $competition_id,
                'user_blocked',
                "User blocked: {$block_type} = {$block_value}",
                'info',
                [
                    'block_type' => $block_type,
                    'block_value' => $block_value,
                    'block_reason' => $block_reason,
                    'block_behavior' => $block_behavior
                ]
            );
        } else {
            echo '<div class="notice notice-error"><p>Failed to add player access restriction. Error: ' . esc_html($wpdb->last_error) . '</p></div>';
        }
    }
}

// Handle removing blocked user
if (isset($_POST['remove_block']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_remove_block')) {
    $block_id = (int) $_POST['block_id'];
    
    if ($block_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bpm_user_blocks';
        
        // Get block info before deleting for logging
        $block_info = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $block_id
        ));
        
        $result = $wpdb->delete($table, ['id' => $block_id], ['%d']);
        
        if ($result && $block_info) {
            echo '<div class="notice notice-success"><p>Player access restriction removed successfully!</p></div>';
            
            // Log the action
            BPM_Audit_Logger::log(
                $block_info->competition_id,
                'user_unblocked',
                "User unblocked: {$block_info->block_type} = {$block_info->block_value}",
                'info',
                ['block_id' => $block_id, 'removed_by' => get_current_user_id()]
            );
        }
    }
}

// Get all competitions
global $wpdb;
$competitions_table = $wpdb->prefix . 'bpm_competitions';
$competitions = $wpdb->get_results("SELECT id, name, type, status FROM $competitions_table ORDER BY created_at DESC");

$selected_competition = isset($_GET['competition_id']) ? (int) $_GET['competition_id'] : 0;

// Auto-select the only competition when one exists and none chosen.
if ( ! $selected_competition && is_array( $competitions ) && count( $competitions ) === 1 ) {
    $only = reset( $competitions );
    $selected_competition = is_array( $only ) ? (int) $only['id'] : (int) $only->id;
}

// Get blocked users for selected competition
$blocked_users = [];
if ($selected_competition) {
    $blocks_table = $wpdb->prefix . 'bpm_user_blocks';
    $blocked_users = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $blocks_table WHERE competition_id = %d ORDER BY created_at DESC",
        $selected_competition
    ));
}
?>

<div class="wrap">

        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="bpm-admin-content">
        
        <div class="bpm-admin-section">
            <h2><?php _e('Select Competition', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-user_blocking" />
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="competition_id"><?php _e('Competition', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <select id="competition_id" name="competition_id" onchange="this.form.submit()">
                                <option value=""><?php _e('Select Competition', 'britvic-promo'); ?></option>
                                <?php foreach ($competitions as $competition): ?>
                                    <option value="<?php echo $competition->id; ?>" <?php selected($selected_competition, $competition->id); ?>>
                                        <?php echo esc_html($competition->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </form>
        </div>

        <?php if ($selected_competition): ?>

        <!-- Restrict Player Access -->
        <div class="bpm-admin-section">
            <h2>Restrict Player Access</h2>
            <div class="inside">
                <form method="post" action="">
                    <?php wp_nonce_field('bpm_add_blocked_user'); ?>
                    <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">Access Type</th>
                            <td>
                                <select name="block_type" required>
                                    <option value="">Select access type...</option>
                                    <option value="email">Email Address</option>
                                    <option value="ip">IP Address</option>
                                    <option value="name">Name</option>
                                    <option value="domain">Email Domain</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Access Value</th>
                            <td>
                                <input type="text" name="block_value" class="regular-text" placeholder="e.g., user@example.com, 192.168.1.1, John Doe" required />
                                <p class="description">Enter the specific value to restrict (email, IP, name, or domain)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Access Behaviour</th>
                            <td>
                                <select name="block_behavior" required>
                                    <option value="prevent_entry">Prevent Entry</option>
                                    <option value="always_lose">Allow Entry but Always Lose</option>
                                </select>
                                <p class="description">Choose how restricted players should be handled</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Reason</th>
                            <td>
                                <input type="text" name="block_reason" class="large-text" placeholder="Reason for restricting this player" />
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="add_blocked_user" class="button-primary" value="Restrict Player Access" />
                    </p>
                </form>
            </div>
        </div>

        <!-- Current Restrictions -->
        <div class="bpm-admin-section">
            <h2>Current Restrictions</h2>
            <div class="inside">
                <?php if (empty($blocked_users)): ?>
                    <p>No players are currently restricted for this competition.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Access Type</th>
                                <th>Access Value</th>
                                <th>Access Behaviour</th>
                                <th>Reason</th>
                                <th>Created</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blocked_users as $block): ?>
                            <tr>
                                <td>
                                    <span class="block-type-<?php echo esc_attr($block->block_type); ?>">
                                        <?php echo esc_html(ucfirst($block->block_type)); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($block->block_value); ?></code></td>
                                <td>
                                    <?php if ($block->block_behavior === 'prevent_entry'): ?>
                                        <span style="color: red;">🚫 Prevent Entry</span>
                                    <?php else: ?>
                                        <span style="color: orange;">⚠️ Always Lose</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($block->block_reason ?: '-'); ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($block->created_at))); ?></td>
                                <td>
                                    <?php 
                                    $user = get_user_by('id', $block->created_by);
                                    echo esc_html($user ? $user->display_name : 'Unknown');
                                    ?>
                                </td>
                                <td>
                                    <form method="post" action="" style="display: inline;">
                                        <?php wp_nonce_field('bpm_remove_block'); ?>
                                        <input type="hidden" name="block_id" value="<?php echo esc_attr($block->id); ?>" />
                                        <input type="submit" name="remove_block" class="button button-small" value="Remove"
                                               onclick="return confirm('Are you sure you want to remove this restriction?')" />
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bulk Import Restrictions -->
        <div class="bpm-admin-section">
            <h2>Bulk Import Restrictions</h2>
            <div class="inside">
                <p>You can bulk import access restrictions by uploading a CSV file with the following columns:</p>
                <ul>
                    <li><strong>block_type</strong>: email, ip, name, or domain</li>
                    <li><strong>block_value</strong>: the value to restrict</li>
                    <li><strong>block_behavior</strong>: prevent_entry or always_lose</li>
                    <li><strong>block_reason</strong>: reason for the restriction (optional)</li>
                </ul>

                <form method="post" enctype="multipart/form-data" id="bulk-import-form">
                    <?php wp_nonce_field('bpm_bulk_import_blocks'); ?>
                    <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />

                    <p>
                        <input type="file" name="blocks_csv" accept=".csv" required />
                        <input type="submit" name="bulk_import_blocks" class="button" value="Import Restrictions" />
                    </p>
                </form>
            </div>
        </div>

        <?php endif; ?>
        
    </div>
</div>

<style>
.bpm-admin-container .postbox {
    margin-bottom: 20px;
}
.block-type-email { background: #e3f2fd; padding: 2px 6px; border-radius: 3px; }
.block-type-ip { background: #fff3e0; padding: 2px 6px; border-radius: 3px; }
.block-type-name { background: #f3e5f5; padding: 2px 6px; border-radius: 3px; }
.block-type-domain { background: #e8f5e8; padding: 2px 6px; border-radius: 3px; }
</style>

<script>
jQuery(document).ready(function($) {
    // Handle bulk import form
    $('#bulk-import-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('action', 'bpm_bulk_import_blocks');
        formData.append('nonce', '<?php echo wp_create_nonce('bpm_nonce'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert('Blocks imported successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('An error occurred during import.');
            }
        });
    });
});
</script>