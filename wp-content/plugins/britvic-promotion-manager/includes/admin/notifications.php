<?php
/**
 * Notifications Admin Page
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'britvic-promo'));
}

// Handle email configuration update
if (isset($_POST['save_email_config']) && wp_verify_nonce($_POST['bpm_email_config_nonce'], 'bpm_save_email_config')) {
    $competition_id = (int) $_POST['competition_id'];
    $email_sending_enabled = isset($_POST['email_sending_enabled']) ? 1 : 0;
    
    if ($competition_id) {
        $updated = update_option("bpm_email_sending_enabled_{$competition_id}", $email_sending_enabled);
        if ($updated !== false) {
            $status = $email_sending_enabled ? __('enabled', 'britvic-promo') : __('disabled', 'britvic-promo');
            echo '<div class="notice notice-success"><p>' . 
                sprintf(__('Email sending has been %s for this competition.', 'britvic-promo'), $status) . 
                '</p></div>';
        }
    }
}

// Handle form template assignments update
if (isset($_POST['save_form_template_assignments']) && wp_verify_nonce($_POST['form_template_nonce'], 'bpm_save_form_template_assignments')) {
    $competition_id = (int) $_POST['competition_id'];
    $form_template_assignments = $_POST['form_template_assignments'] ?? [];
    
    if ($competition_id) {
        // Sanitize the data
        $sanitized_assignments = [];
        foreach ($form_template_assignments as $form_type => $assignment) {
            $sanitized_assignments[sanitize_key($form_type)] = [
                'template_index' => isset($assignment['template_index']) && $assignment['template_index'] !== '' ? (int) $assignment['template_index'] : null,
                'email_enabled' => isset($assignment['email_enabled']) && $assignment['email_enabled'] === '1'
            ];
        }
        
        $updated = update_option("bpm_form_template_assignments_{$competition_id}", $sanitized_assignments);
        if ($updated !== false) {
            echo '<div class="notice notice-success"><p>' . 
                __('Form template assignments have been saved successfully.', 'britvic-promo') . 
                '</p></div>';
        }
        
        // Log the update
        BPM_Audit_Logger::log(
            $competition_id,
            'form_template_assignments_updated',
            'Form template assignments updated',
            'info',
            [
                'assignments' => $sanitized_assignments,
                'user_id' => get_current_user_id()
            ]
        );
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
?>

<div class="wrap">

        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="bpm-admin-content">
        
        <div class="bpm-admin-section">
            <h2><?php _e('Select Competition', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-notifications" />
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

        <?php if ($selected_competition): 
            $email_templates = get_option("bpm_email_templates_{$selected_competition}", []);
            
            // Load saved prize types for this competition to provide as suggestions
            $saved_prize_types = get_option("bpm_generation_prize_types_{$selected_competition}", []);
            
            // Load email sending configuration
            $email_sending_enabled = get_option("bpm_email_sending_enabled_{$selected_competition}", false);
            
            // Get available placeholders for the selected competition
            $available_placeholders = function_exists('bpm_get_email_placeholders') ? bpm_get_email_placeholders($selected_competition) : [];
            
            // Format placeholders for display
            $placeholder_list = [];
            foreach ($available_placeholders as $placeholder => $description) {
                $placeholder_list[] = $placeholder;
            }
            $placeholders_text = implode(', ', $placeholder_list);
        ?>
        
        <?php
        // Ensure email templates variable is available for JavaScript
        $email_templates = $email_templates ?? [];
        ?>

        <!-- Email Configuration -->
        <div class="bpm-admin-section">
            <h2><?php _e('Email Configuration', 'britvic-promo'); ?></h2>
            <p><?php _e('Configure whether automatic winner emails should be sent when prizes are claimed.', 'britvic-promo'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('bpm_save_email_config', 'bpm_email_config_nonce'); ?>
                <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="email_sending_enabled"><?php _e('Automatic Email Sending', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="email_sending_enabled" name="email_sending_enabled" value="1" <?php checked($email_sending_enabled, 1); ?> />
                                <?php _e('Enable automatic winner emails', 'britvic-promo'); ?>
                            </label>
                            <p class="description">
                                <?php _e('When enabled, winner emails will be automatically sent when prizes are claimed. Disable this for prizes that are fulfilled manually or when emails should not be sent.', 'britvic-promo'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Email Configuration', 'britvic-promo'), 'primary', 'save_email_config'); ?>
            </form>
        </div>

        <!-- Winner Email Templates -->
        <div class="bpm-admin-section">
            <h2><?php _e('Winner Email Templates', 'britvic-promo'); ?></h2>
            <p><?php _e('Configure different email templates based on the prize type won. When a winner is assigned to a prize in this competition, the appropriate email template will be used.', 'britvic-promo'); ?></p>
            
            <?php if (!$email_sending_enabled): ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php _e('Notice:', 'britvic-promo'); ?></strong> 
                    <?php _e('Automatic email sending is currently disabled for this competition. Email templates can be configured but no emails will be sent when prizes are claimed.', 'britvic-promo'); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-info inline">
                    <p><strong><?php _e('Status:', 'britvic-promo'); ?></strong> 
                    <?php _e('Automatic email sending is enabled. Emails will be sent when prizes are claimed if matching templates are configured.', 'britvic-promo'); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <?php
            // Get existing email templates for this competition
            $existing_templates = get_option("bpm_email_templates_{$selected_competition}", []);
            ?>

            <!-- Template List -->
            <div id="templates-list-view">
                <?php if (empty($existing_templates)): ?>
                    <div class="no-templates-message">
                        <p><?php _e('No email templates have been created yet.', 'britvic-promo'); ?></p>
                        <p class="description"><?php _e('Create your first template using the form below.', 'britvic-promo'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 20%;"><?php _e('Prize Match', 'britvic-promo'); ?></th>
                                <th style="width: 30%;"><?php _e('Subject', 'britvic-promo'); ?></th>
                                <th style="width: 30%;"><?php _e('Preview', 'britvic-promo'); ?></th>
                                <th style="width: 20%;"><?php _e('Actions', 'britvic-promo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_templates as $index => $template): ?>
                                <tr data-template-index="<?php echo esc_attr($index); ?>">
                                    <td><strong><?php echo esc_html($template['prize_match']); ?></strong></td>
                                    <td><?php echo esc_html($template['email_subject']); ?></td>
                                    <td><?php echo esc_html(wp_trim_words(strip_tags($template['email_template']), 10)); ?>...</td>
                                    <td>
                                        <button type="button" class="button button-small view-template" data-index="<?php echo esc_attr($index); ?>" title="<?php esc_attr_e('View', 'britvic-promo'); ?>">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('View', 'britvic-promo'); ?></span>
                                        </button>
                                        <button type="button" class="button button-small edit-template" data-index="<?php echo esc_attr($index); ?>" title="<?php esc_attr_e('Edit', 'britvic-promo'); ?>">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Edit', 'britvic-promo'); ?></span>
                                        </button>
                                        <button type="button" class="button button-small delete-template" data-index="<?php echo esc_attr($index); ?>" title="<?php esc_attr_e('Delete', 'britvic-promo'); ?>">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i><span class="screen-reader-text"><?php _e('Delete', 'britvic-promo'); ?></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Add/Edit Template Form -->
            <div id="template-form" style="display:none; margin-top: 20px;">
                <h3 id="form-title"><?php _e('Add New Email Template', 'britvic-promo'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="template-prize-match"><?php _e('Prize Match', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="template-prize-match" class="regular-text" placeholder="e.g., Vintage Inns Voucher" list="prize-types-list" />
                            <datalist id="prize-types-list">
                                <?php foreach ($saved_prize_types as $prize_type): ?>
                                    <option value="<?php echo esc_attr($prize_type['name']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <p class="description"><?php _e('Exact prize name or keyword to match (case insensitive). You can select from configured prize types above or enter a custom value.', 'britvic-promo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="template-email-subject"><?php _e('Email Subject', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="template-email-subject" class="regular-text" placeholder="You've won a {prize_name}!" />
                            <p class="description"><?php printf(__('Available placeholders: %s', 'britvic-promo'), $placeholders_text); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="template-email-body"><?php _e('Email Template', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <textarea id="template-email-body" class="large-text" rows="8" placeholder="Congratulations! You've won a {prize_name}..."></textarea>
                            <p class="description"><?php printf(__('HTML is supported. Use placeholders: %s', 'britvic-promo'), $placeholders_text); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="save-template" class="button button-primary" data-competition-id="<?php echo $selected_competition; ?>"><?php _e('Save Template', 'britvic-promo'); ?></button>
                    <button type="button" id="cancel-template" class="button"><?php _e('Cancel', 'britvic-promo'); ?></button>
                    <input type="hidden" id="editing-template-index" value="" />
                </p>
            </div>

            <p>
                <button type="button" id="add-new-template" class="button button-secondary"><?php _e('Add New Email Template', 'britvic-promo'); ?></button>
            </p>
            
            <!-- Test Email Form -->
            <div class="email-preview">
                <h3><?php _e('Test Email Template', 'britvic-promo'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_prize"><?php _e('Test Prize', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="test_prize" class="regular-text" placeholder="Vintage Inns Voucher" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="test_email"><?php _e('Test Email', 'britvic-promo'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="test_email" class="regular-text" placeholder="winner@example.com" />
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button type="button" id="preview-email" class="button" data-competition-id="<?php echo $selected_competition; ?>"><?php _e('Preview Email', 'britvic-promo'); ?></button>
                            <button type="button" id="send-test-email" class="button" data-competition-id="<?php echo $selected_competition; ?>"><?php _e('Send Test Email', 'britvic-promo'); ?></button>
                        </td>
                    </tr>
                </table>
                <div id="email-preview-content" style="display:none; border: 1px solid #ddd; padding: 15px; margin-top: 15px; background: #f9f9f9;"></div>
            </div>
        </div>

        <!-- Form Template Assignments -->
        <div class="bpm-admin-section">
            <h2><?php _e('Form Template Assignments', 'britvic-promo'); ?></h2>
            <p><?php _e('Assign specific email templates to different forms. When a form is submitted, the assigned template will be used for sending winner emails.', 'britvic-promo'); ?></p>
            
            <?php
            // Get form settings for this competition
            $form_settings = get_option("bpm_form_settings_{$selected_competition}", []);
            $form_template_assignments = get_option("bpm_form_template_assignments_{$selected_competition}", []);
            
            // Define form types that can send emails
            $email_form_types = [
                'winner_form' => 'Winner Claim Form',
                'initial_entry' => 'Initial Entry Form',
                'prize_draw' => 'Prize Draw Entry'
            ];
            ?>
            
            <?php if (!empty($form_settings)): ?>
                <form method="post" action="" id="form-template-assignments">
                    <?php wp_nonce_field('bpm_save_form_template_assignments', 'form_template_nonce'); ?>
                    <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 25%;"><?php _e('Form Type', 'britvic-promo'); ?></th>
                                <th style="width: 30%;"><?php _e('Assigned Form', 'britvic-promo'); ?></th>
                                <th style="width: 30%;"><?php _e('Email Template', 'britvic-promo'); ?></th>
                                <th style="width: 15%;"><?php _e('Email Enabled', 'britvic-promo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($email_form_types as $form_type => $form_label): ?>
                                <?php if (isset($form_settings[$form_type]) && isset($form_settings[$form_type]['form_id'])): ?>
                                    <?php
                                    $form_id = $form_settings[$form_type]['form_id'];
                                    $form_title = 'Unknown Form';
                                    
                                    // Get form title from Gravity Forms
                                    if (class_exists('GFAPI')) {
                                        $form = GFAPI::get_form($form_id);
                                        if ($form) {
                                            $form_title = $form['title'];
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($form_label); ?></strong></td>
                                        <td><?php echo esc_html("#{$form_id} - {$form_title}"); ?></td>
                                        <td>
                                            <select name="form_template_assignments[<?php echo esc_attr($form_type); ?>][template_index]" class="regular-text">
                                                <option value=""><?php _e('No template (match by prize)', 'britvic-promo'); ?></option>
                                                <?php foreach ($existing_templates as $index => $template): ?>
                                                    <option value="<?php echo esc_attr($index); ?>" 
                                                        <?php selected($form_template_assignments[$form_type]['template_index'] ?? '', $index); ?>>
                                                        <?php echo esc_html($template['prize_match'] . ' - ' . $template['email_subject']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <label>
                                                <input type="checkbox" 
                                                       name="form_template_assignments[<?php echo esc_attr($form_type); ?>][email_enabled]" 
                                                       value="1" 
                                                       <?php checked($form_template_assignments[$form_type]['email_enabled'] ?? false, 1); ?> />
                                                <?php _e('Send emails', 'britvic-promo'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="save_form_template_assignments" class="button-primary" value="<?php _e('Save Template Assignments', 'britvic-promo'); ?>" />
                    </p>
                </form>
            <?php else: ?>
                <div class="notice notice-info inline">
                    <p><?php _e('No forms are configured for this competition. Please configure forms in the Form Assignment page first.', 'britvic-promo'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Email Log -->
        <div class="bpm-admin-section">
            <h2><?php _e('Email Log', 'britvic-promo'); ?></h2>
            <p><?php _e('Recent winner emails sent for this competition.', 'britvic-promo'); ?></p>
            
            <?php
            // Get email logs from the new email log table
            global $wpdb;
            $email_log_table = $wpdb->prefix . 'bpm_email_log';
            $email_logs = [];

            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $email_log_table
            ));

            if ($table_exists) {
                $email_logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $email_log_table 
                     WHERE competition_id = %d
                     ORDER BY sent_at DESC 
                     LIMIT 20",
                    $selected_competition
                ));
            }
            ?>
            
            <?php if (empty($email_logs)) : ?>
                <p><?php _e('No emails have been sent yet for this competition.', 'britvic-promo'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 15%;"><?php _e('Date/Time', 'britvic-promo'); ?></th>
                            <th style="width: 25%;"><?php _e('Recipient', 'britvic-promo'); ?></th>
                            <th style="width: 20%;"><?php _e('Subject', 'britvic-promo'); ?></th>
                            <th style="width: 15%;"><?php _e('Prize Type', 'britvic-promo'); ?></th>
                            <th style="width: 15%;"><?php _e('Template Used', 'britvic-promo'); ?></th>
                            <th style="width: 10%;"><?php _e('Status', 'britvic-promo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($email_logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($log->sent_at))); ?></td>
                                <td>
                                    <?php echo esc_html($log->recipient_email); ?>
                                    <?php if (!empty($log->winner_name)) : ?>
                                        <br><small><?php echo esc_html($log->winner_name); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->subject); ?></td>
                                <td><?php echo esc_html($log->prize_type); ?></td>
                                <td><?php echo esc_html($log->template_used); ?></td>
                                <td>
                                    <?php if ($log->status === 'sent') : ?>
                                        <span class="badge badge-success"><?php _e('Sent', 'britvic-promo'); ?></span>
                                    <?php else : ?>
                                        <span class="badge badge-danger"><?php _e('Failed', 'britvic-promo'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php endif; ?>
        
    </div>
</div>

<style>
.bpm-admin-container .postbox {
    margin-bottom: 20px;
}

/* Prize Type Management Styles */
.prize-type-row {
    background: #fafafa;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.prize-type-row:hover {
    border-color: #2271b1;
    box-shadow: 0 2px 5px rgba(34, 113, 177, 0.1);
}

.prize-type-header {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
    position: relative;
    overflow: hidden;
}

.prize-type-header h4 code {
    background: #f1f1f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    color: #666;
    margin-left: 10px;
}

.prize-type-row .form-table th {
    width: 150px;
    font-weight: 600;
}

.prize-type-row .form-table td {
    padding: 10px 0;
}

.remove-prize-type:hover {
    background-color: #b32d2e !important;
    border-color: #b32d2e !important;
}

/* Add Prize Form Styles */
#add-prize-form {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
    }
    to {
        opacity: 1;
        max-height: 500px;
        padding-top: 20px;
        padding-bottom: 20px;
    }
}

#add-prize-form .form-table th {
    width: 150px;
    font-weight: 600;
}

#add-prize-form .description {
    color: #666;
    font-style: italic;
    margin-top: 5px;
}

/* Input validation styles */
.input-error {
    border-color: #d63638 !important;
    box-shadow: 0 0 2px rgba(214, 54, 56, 0.8) !important;
}

.input-success {
    border-color: #00a32a !important;
    box-shadow: 0 0 2px rgba(0, 163, 42, 0.8) !important;
}

/* Button improvements */
#add-prize-type {
    background: linear-gradient(45deg, #2271b1, #135e96);
    border-color: #135e96;
    color: white;
    transition: all 0.3s ease;
}

#add-prize-type:hover {
    background: linear-gradient(45deg, #135e96, #0a4b7a);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Success message animation */
.notice.fade-in {
    animation: fadeInUp 0.5s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Prize type container improvements */
#prize-types-container:empty::after {
    content: "No prize types configured yet. Click 'Add New Prize Type' to get started.";
    display: block;
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 40px;
    background: #f9f9f9;
    border: 2px dashed #ddd;
    border-radius: 4px;
}

/* Email configuration notices */
.notice.inline {
    margin: 15px 0;
    display: block;
}

/* Email log badges */
.badge {
    display: inline-block;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    border-radius: 3px;
    color: white;
}

.badge-success {
    background-color: #00a32a;
}

.badge-secondary {
    background-color: #646970;
}

.badge-danger {
    background-color: #d63638;
}

/* Template management styles */
.no-templates-message {
    text-align: center;
    padding: 40px 20px;
    background: #f9f9f9;
    border: 2px dashed #ddd;
    border-radius: 4px;
    margin: 20px 0;
}

#template-form {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
}

#template-form h3 {
    margin-top: 0;
    color: #1d2327;
}

.template-actions {
    margin-top: 20px;
}

.view-template, .edit-template, .delete-template {
    margin-right: 5px !important;
}

.delete-template:hover {
    background-color: #d63638 !important;
    border-color: #d63638 !important;
    color: white !important;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Winner Email Templates functionality
    var currentTemplates = <?php echo wp_json_encode($existing_templates ?? []); ?>;
    
    // Show add new template form
    $('#add-new-template').on('click', function() {
        $('#form-title').text('<?php _e('Add New Email Template', 'britvic-promo'); ?>');
        $('#template-prize-match').val('');
        $('#template-email-subject').val('');
        $('#template-email-body').val('');
        $('#editing-template-index').val('');
        $('#template-form').show();
        $('#template-prize-match').focus();
    });
    
    // Cancel template form
    $('#cancel-template').on('click', function() {
        $('#template-form').hide();
    });
    
    // Save template
    $('#save-template').on('click', function() {
        var competitionId = $(this).data('competition-id');
        var prizeMatch = $('#template-prize-match').val().trim();
        var emailSubject = $('#template-email-subject').val().trim();
        var emailBody = $('#template-email-body').val().trim();
        var editingIndex = $('#editing-template-index').val();
        
        if (!prizeMatch || !emailSubject || !emailBody) {
            alert('<?php _e('Please fill in all fields.', 'britvic-promo'); ?>');
            return;
        }
        
        // Update or add to current templates array
        var templateData = {
            prize_match: prizeMatch,
            email_subject: emailSubject,
            email_template: emailBody,
            created_at: new Date().toISOString()
        };
        
        if (editingIndex !== '') {
            // Editing existing template
            currentTemplates[parseInt(editingIndex)] = templateData;
        } else {
            // Adding new template
            currentTemplates.push(templateData);
        }
        
        // Save to database
        $.post(ajaxurl, {
            action: 'bpm_save_email_templates',
            competition_id: competitionId,
            templates: currentTemplates,
            nonce: '<?php echo wp_create_nonce('bpm_save_email_templates'); ?>'
        }, function(response) {
            if (response.success) {
                alert('<?php _e('Email template saved successfully!', 'britvic-promo'); ?>');
                location.reload(); // Refresh to show updated list
            } else {
                alert('<?php _e('Error saving template:', 'britvic-promo'); ?> ' + response.data);
            }
        });
    });
    
    // View template
    $(document).on('click', '.view-template', function() {
        var index = $(this).data('index');
        var template = currentTemplates[index];
        
        if (template) {
            var content = `
                <h3>Prize Match: ${template.prize_match}</h3>
                <h4>Subject: ${template.email_subject}</h4>
                <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; margin-top: 10px;">
                    <strong>Template Content:</strong><br>
                    ${template.email_template}
                </div>
            `;
            
            var popup = window.open('', '_blank', 'width=700,height=500,scrollbars=yes');
            popup.document.write(`
                <html>
                    <head>
                        <title>Email Template - ${template.prize_match}</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            h3, h4 { margin-top: 0; color: #1d2327; }
                        </style>
                    </head>
                    <body>${content}</body>
                </html>
            `);
        }
    });
    
    // Edit template
    $(document).on('click', '.edit-template', function() {
        var index = $(this).data('index');
        var template = currentTemplates[index];
        
        if (template) {
            $('#form-title').text('<?php _e('Edit Email Template', 'britvic-promo'); ?>');
            $('#template-prize-match').val(template.prize_match);
            $('#template-email-subject').val(template.email_subject);
            $('#template-email-body').val(template.email_template);
            $('#editing-template-index').val(index);
            $('#template-form').show();
            $('#template-prize-match').focus();
        }
    });
    
    // Delete template
    $(document).on('click', '.delete-template', function() {
        var index = $(this).data('index');
        var template = currentTemplates[index];
        
        if (template && confirm('<?php _e('Are you sure you want to delete the email template for', 'britvic-promo'); ?> "' + template.prize_match + '"?\n\n<?php _e('This action cannot be undone.', 'britvic-promo'); ?>')) {
            var competitionId = $('#save-template').data('competition-id');
            
            // Remove from array
            currentTemplates.splice(index, 1);
            
            // Save updated templates
            $.post(ajaxurl, {
                action: 'bpm_save_email_templates',
                competition_id: competitionId,
                templates: currentTemplates,
                nonce: '<?php echo wp_create_nonce('bpm_save_email_templates'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('<?php _e('Email template deleted successfully!', 'britvic-promo'); ?>');
                    location.reload(); // Refresh to show updated list
                } else {
                    alert('<?php _e('Error deleting template:', 'britvic-promo'); ?> ' + response.data);
                }
            });
        }
    });
    
    // Preview email
    $('#preview-email').on('click', function() {
        var competitionId = $(this).data('competition-id');
        var testPrize = $('#test_prize').val().trim();
        var testEmail = $('#test_email').val().trim();
        
        if (!testPrize) {
            alert('<?php _e('Please enter a test prize name', 'britvic-promo'); ?>');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'bpm_preview_email_template',
            competition_id: competitionId,
            test_prize: testPrize,
            test_email: testEmail,
            nonce: '<?php echo wp_create_nonce('bpm_preview_email'); ?>'
        }, function(response) {
            console.log('Preview response:', response);
            if (response.success) {
                var previewHtml = '<div class="email-preview">' +
                    '<h3><?php _e('Subject:', 'britvic-promo'); ?></h3>' +
                    '<p><strong>' + response.data.subject + '</strong></p>' +
                    '<h3><?php _e('Email Body:', 'britvic-promo'); ?></h3>' +
                    '<div class="email-body-preview" style="border: 1px solid #ddd; padding: 15px; background: #fff;">' +
                    response.data.body +
                    '</div>' +
                    '<h4><?php _e('Placeholders Used:', 'britvic-promo'); ?></h4>' +
                    '<p><code>' + response.data.placeholders_used.join(', ') + '</code></p>' +
                    '</div>';
                $('#email-preview-content').html(previewHtml).show();
            } else {
                alert('<?php _e('Error previewing email:', 'britvic-promo'); ?> ' + (response.data ? response.data.message : response.data));
            }
        });
    });
    
    // Send test email
    $('#send-test-email').on('click', function() {
        var competitionId = $(this).data('competition-id');
        var testPrize = $('#test_prize').val().trim();
        var testEmail = $('#test_email').val().trim();
        
        if (!testPrize || !testEmail) {
            alert('<?php _e('Please enter both test prize name and email address', 'britvic-promo'); ?>');
            return;
        }
        
        if (!competitionId) {
            alert('Competition ID not found. Please refresh the page and try again.');
            return;
        }
        
        if (!confirm('<?php _e('Send test email to', 'britvic-promo'); ?> ' + testEmail + '?')) {
            return;
        }
        
        $.post(ajaxurl, {
            action: 'bpm_send_test_email',
            competition_id: competitionId,
            test_prize: testPrize,
            test_email: testEmail,
            nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                alert('<?php _e('Test email sent successfully!', 'britvic-promo'); ?>');
            } else {
                alert('<?php _e('Error sending test email:', 'britvic-promo'); ?> ' + response.data);
            }
        }).fail(function(xhr, status, error) {
            alert('Network error occurred. Please try again.');
        });
    });

    // Preview email template
    $('.preview-template').on('click', function() {
        var index = $(this).data('index');
        var templates = <?php echo wp_json_encode($email_templates ?? []); ?>;
        var template = templates[index];
        
        if (template) {
            var content = `
                <h3>Subject: ${template.email_subject}</h3>
                <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                    ${template.email_template}
                </div>
            `;
            
            // Show in a simple modal/alert (you could enhance this with a proper modal)
            var popup = window.open('', '_blank', 'width=600,height=400,scrollbars=yes');
            popup.document.write(`
                <html>
                    <head><title>Email Template Preview</title></head>
                    <body style="font-family: Arial, sans-serif; padding: 20px;">
                        ${content}
                    </body>
                </html>
            `);
        }
    });
});
</script>