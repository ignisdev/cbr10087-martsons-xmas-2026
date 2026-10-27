<?php
/**
 * Form Assignment Admin Page
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}



// Handle saving form assignments
if (isset($_POST['save_form_assignments']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_save_form_assignments')) {
    $competition_id = (int) $_POST['competition_id'];
    $form_settings = json_decode(wp_unslash($_POST['form_settings_data']), true);
    
    if ($competition_id && is_array($form_settings)) {
        // Debug log
        // error_log('BPM Form Settings - Saving for competition ' . $competition_id . ': ' . print_r($form_settings, true));
        
        update_option("bpm_form_settings_{$competition_id}", $form_settings);
        
        // Generate age validation functions for age gate forms
        if (isset($form_settings['age_gate']['form_id']) && isset($form_settings['age_gate']['field_mapping']['dob_field'])) {
            $form_id = $form_settings['age_gate']['form_id'];
            $field_id = $form_settings['age_gate']['field_mapping']['dob_field'];
            $age_limit = $form_settings['age_gate']['age_limit'] ?? 16;
            
            // Generate the validation function code
            $validation_code = "
add_filter('gform_field_validation_{$form_id}_{$field_id}', function(\$result, \$value, \$form, \$field) {
    if (\$result['is_valid']) {
        if (is_array(\$value)) {
            \$value = array_values(\$value);
        }
        \$date_value = GFFormsModel::prepare_date(\$field->dateFormat, \$value);
        
        \$today = new DateTime();
        \$diff = \$today->diff(new DateTime(\$date_value));
        \$age = \$diff->y;
        
        if (\$age < {$age_limit}) {
            \$result['is_valid'] = false;
            \$result['message'] = 'PLEASE ENTER A VALID DATE OF BIRTH';
        }
    }
    
    return \$result;
}, 10, 4);
";
            
            // Save the validation code to be included
            update_option("bpm_age_validation_code_{$competition_id}", $validation_code);
            
            // Also save it to a PHP file that can be included
            $upload_dir = wp_upload_dir();
            $validation_file = $upload_dir['basedir'] . "/bpm_age_validation_{$competition_id}.php";
            file_put_contents($validation_file, "<?php\n" . $validation_code);
            
            echo '<div class="notice notice-info"><p>Age validation function generated for form ' . $form_id . ' field ' . $field_id . ' with age limit ' . $age_limit . '</p></div>';
        }
        
        echo '<div class="notice notice-success"><p>Form assignments saved successfully!</p></div>';
        
        // Log the update
        BPM_Audit_Logger::log(
            $competition_id,
            'form_assignments_updated',
            'Form assignments updated for competition',
            'info',
            ['form_settings' => $form_settings, 'user_id' => get_current_user_id()]
        );
        
        // Re-initialize form hooks
        if (class_exists('BPM_Form_Integration')) {
            BPM_Form_Integration::setup_validation_hooks();
            BPM_Form_Integration::setup_submission_hooks();
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

// Get current form settings
$form_settings = [];
if ($selected_competition) {
    $form_settings = get_option("bpm_form_settings_{$selected_competition}", []);
}

// Get all Gravity Forms
$available_forms = [];
if (class_exists('GFAPI')) {
    $forms = GFAPI::get_forms();
    foreach ($forms as $form) {
        $available_forms[$form['id']] = $form['title'];
    }
}

// Default form types and descriptions
$form_types = [
    'age_gate' => [
        'label' => 'Age Gate Form',
        'description' => 'Form used for age verification',
        'fields' => ['dob_field', 'name_field', 'email_field', 'age_limit']
    ],
    'initial_entry' => [
        'label' => 'Initial Entry Form',
        'description' => 'First form users fill out (usually collects basic info like name/email)',
        'fields' => ['name_field', 'email_field', 'file_field']
    ],
    'code_entry' => [
        'label' => 'Code Entry Form',
        'description' => 'Form where users enter their unique code',
        'fields' => ['code_field']
    ],
    'winner_form' => [
        'label' => 'Winner Claim Form',
        'description' => 'Form shown to winners to claim their prize',
        'fields' => ['name_field', 'email_field', 'phone_field', 'dob_field', 'county_field', 'terms_field', 'file_field', 'prize_code_field', 'prize_value_field', 'prize_id_field']
    ],
    'prize_draw' => [
        'label' => 'Prize Draw Entry',
        'description' => 'Form for prize draw competitions',
        'fields' => ['name_field', 'email_field']
    ],
    'photo' => [
        'label' => 'Photo Upload Form',
        'description' => 'Form used for photo/file upload step',
        'fields' => ['file_field', 'name_field', 'email_field']
    ]
];

// Prize-specific forms
$prize_types = get_option("bpm_prize_types_{$selected_competition}", []);
foreach ($prize_types as $prize_key => $prize_data) {
    if ($prize_key !== 'loser') {
        $form_types["prize_{$prize_key}"] = [
            'label' => "Winner Form - {$prize_data['name']}",
            'description' => "Specific form for {$prize_data['name']} winners",
            'fields' => ['name_field', 'email_field', 'phone_field', 'dob_field', 'county_field', 'terms_field', 'file_field', 'prize_code_field', 'prize_value_field', 'prize_id_field']
        ];
    }
}

// Keep any already-saved custom form types visible/editable.
if (!empty($form_settings) && is_array($form_settings)) {
    foreach ($form_settings as $saved_type => $saved_config) {
        if (!isset($form_types[$saved_type])) {
            $form_types[$saved_type] = [
                'label' => ucwords(str_replace('_', ' ', (string) $saved_type)),
                'description' => 'Saved dynamic mapping',
                'fields' => ['name_field', 'email_field', 'file_field', 'dob_field', 'code_field']
            ];
        }
    }
}
?>

<div class="wrap">

        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="bpm-admin-content">
        
        <div class="bpm-admin-section">
            <h2><?php _e('Select Competition', 'britvic-promo'); ?></h2>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="britvic-form_assignment" />
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

        <?php if ($selected_competition && !empty($available_forms)): ?>

        <!-- Form Assignments -->
        <div class="bpm-admin-section">
            <h2>Form Assignments</h2>
            <div class="inside">
                <form method="post" action="" id="form-assignments-form">
                    <?php wp_nonce_field('bpm_save_form_assignments'); ?>
                    <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />
                    
                    <div id="form-assignments-container">
                        <?php foreach ($form_types as $form_type => $config): ?>
                        <div class="form-assignment-section" data-form-type="<?php echo esc_attr($form_type); ?>">
                            <h3><?php echo esc_html($config['label']); ?></h3>
                            <p class="description"><?php echo esc_html($config['description']); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Gravity Form</th>
                                    <td>
                                        <select name="form_assignments[<?php echo esc_attr($form_type); ?>][form_id]" class="form-selector">
                                            <option value="">No form assigned</option>
                                            <?php foreach ($available_forms as $form_id => $form_title): ?>
                                                <option value="<?php echo esc_attr($form_id); ?>" 
                                                    <?php selected($form_settings[$form_type]['form_id'] ?? '', $form_id); ?>>
                                                    <?php echo esc_html("#{$form_id} - {$form_title}"); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="button load-form-fields" data-form-type="<?php echo esc_attr($form_type); ?>">
                                            Load Fields
                                        </button>
                                        <p class="description">Fields load dynamically from the selected Gravity Form.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <div class="field-mapping" id="field-mapping-<?php echo esc_attr($form_type); ?>" style="display: none;">
                                <h4>Field Mapping</h4>
                                <table class="form-table">
                                    <?php foreach ($config['fields'] as $field_type): ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html(ucwords(str_replace('_', ' ', $field_type))); ?></th>
                                        <td>
                                            <?php if ($field_type === 'age_limit'): ?>
                                                <input type="number" name="form_assignments[<?php echo esc_attr($form_type); ?>][age_limit]" value="<?php echo esc_attr($form_settings[$form_type]['age_limit'] ?? '16'); ?>" />
                                            <?php else: ?>
                                                <select name="form_assignments[<?php echo esc_attr($form_type); ?>][field_mapping][<?php echo esc_attr($field_type); ?>]" 
                                                        class="field-mapping-select" data-field-type="<?php echo esc_attr($field_type); ?>">
                                                    <option value="">No field mapped</option>
                                                </select>
                                            <?php endif; ?>
                                            <span class="field-description"></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                
                                <?php if ($form_type === 'winner_form'): ?>
                                <h4>Field Population Settings</h4>
                                <p class="description">Configure which fields should be pre-populated from other forms and session data.</p>
                                <table class="form-table">
                                    <thead>
                                        <tr>
                                            <th>Winner Form Field</th>
                                            <th>Populate From</th>
                                            <th>Parameter Name</th>
                                        </tr>
                                    </thead>
                                    <tbody id="population-settings-<?php echo esc_attr($form_type); ?>">
                                        <tr class="population-template" style="display: none;">
                                            <td>
                                                <select class="winner-field-select" name="form_assignments[<?php echo esc_attr($form_type); ?>][population_mapping][PLACEHOLDER][winner_field_id]">
                                                    <option value="">Select field...</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select class="population-source-select" name="form_assignments[<?php echo esc_attr($form_type); ?>][population_mapping][PLACEHOLDER][source]">
                                                    <option value="">No population</option>
                                                    <option value="initial_entry.name">Initial Entry - Name</option>
                                                    <option value="initial_entry.email">Initial Entry - Email</option>
                                                    <option value="initial_entry.phone">Initial Entry - Phone</option>
                                                    <option value="age_gate.dob">Age Gate - Date of Birth</option>
                                                    <option value="session.ip">Session - IP Address</option>
                                                    <option value="session.entry_date">Session - Entry Date</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="population-param" name="form_assignments[<?php echo esc_attr($form_type); ?>][population_mapping][PLACEHOLDER][parameter_name]" placeholder="e.g. winner_name" />
                                                <button type="button" class="button remove-population-row">Remove</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <button type="button" class="button add-population-row" data-form-type="<?php echo esc_attr($form_type); ?>">Add Field Population</button>
                                <?php endif; ?>
                            </div>
                            
                            <hr />
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="submit">
                        <input type="submit" name="save_form_assignments" class="button-primary" value="Save Form Assignments" />
                    </p>
                    
                    <input type="hidden" name="form_settings_data" id="form-settings-data" />
                </form>
            </div>
        </div>

        <!-- Preview Current Settings -->
        <div class="bpm-admin-section">
            <h2>Current Form Settings</h2>
            <div class="inside">
                <?php if (empty($form_settings)): ?>
                    <p>No form assignments configured for this competition.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Form Type</th>
                                <th>Assigned Form</th>
                                <th>Field Mappings</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($form_settings as $form_type => $settings): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($form_types[$form_type]['label'] ?? ucwords(str_replace('_', ' ', $form_type))); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $form_id = $settings['form_id'] ?? '';
                                    if ($form_id && isset($available_forms[$form_id])) {
                                        echo esc_html("#{$form_id} - {$available_forms[$form_id]}");
                                    } else {
                                        echo '<span style="color: red;">Form not found</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $mappings = $settings['field_mapping'] ?? [];
                                    if (!empty($mappings)) {
                                        $mapped_fields = array_filter($mappings);
                                        echo esc_html(count($mapped_fields) . ' fields mapped');
                                    } else {
                                        echo '<span style="color: orange;">No field mappings</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($form_id && isset($available_forms[$form_id]) && !empty($mappings)): ?>
                                        <span style="color: green;">✓ Active</span>
                                    <?php else: ?>
                                        <span style="color: red;">✗ Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($selected_competition && empty($available_forms)): ?>
        
        <div class="notice notice-warning">
            <p><strong>Gravity Forms not found.</strong> Please ensure Gravity Forms plugin is installed and activated to manage form assignments.</p>
        </div>

        <?php endif; ?>
        
    </div>
</div>

<style>
.bpm-admin-container .postbox {
    margin-bottom: 20px;
}
.form-assignment-section {
    background: #f9f9f9;
    padding: 15px;
    margin-bottom: 20px;
    border-left: 4px solid #2271b1;
}
.field-mapping {
    background: #fff;
    padding: 15px;
    margin-top: 15px;
    border: 1px solid #ddd;
}
.field-mapping h4 {
    margin-top: 0;
}
.field-description {
    font-style: italic;
    color: #666;
    margin-left: 10px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Load form fields when form is selected
    $('.load-form-fields').on('click', function() {
        var formType = $(this).data('form-type');
        var formId = $(`.form-assignment-section[data-form-type="${formType}"] .form-selector`).val();
        
        if (!formId) {
            alert('Please select a form first');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bpm_get_form_fields',
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>',
                form_id: formId
            },
            success: function(response) {
                if (response.success) {
                    populateFieldMappings(formType, response.data.fields);
                    $(`#field-mapping-${formType}`).show();
                } else {
                    alert('Error loading form fields: ' + response.data);
                }
            },
            error: function() {
                alert('Error loading form fields');
            }
        });
    });

    // Auto-load fields whenever a form is selected (dynamic UX, no manual load dependency).
    $('.form-selector').on('change', function() {
        var $section = $(this).closest('.form-assignment-section');
        var formType = $section.data('form-type');
        var formId = $(this).val();
        if (!formId) {
            $(`#field-mapping-${formType}`).hide();
            return;
        }
        $section.find('.load-form-fields').trigger('click');
    });
    
    // Populate field mapping dropdowns
    function populateFieldMappings(formType, fields) {
        $(`.form-assignment-section[data-form-type="${formType}"] .field-mapping-select`).each(function() {
            var $select = $(this);
            var fieldType = $select.data('field-type');
            var currentValue = $select.val();
            
            // Clear existing options except the first
            $select.find('option:not(:first)').remove();
            
            // Add form fields as options
            fields.forEach(function(field) {
                $select.append(`<option value="${field.id}">${field.label} (${field.type})</option>`);
            });
            
            // Restore previous selection if it exists
            if (currentValue) {
                $select.val(currentValue);
            }
        });
        
        // Also populate winner form field selects for population mapping
        if (formType === 'winner_form') {
            $(`.form-assignment-section[data-form-type="${formType}"] .winner-field-select`).each(function() {
                var $select = $(this);
                var currentValue = $select.val();
                
                // Clear existing options except the first
                $select.find('option:not(:first)').remove();
                
                // Add form fields as options
                fields.forEach(function(field) {
                    $select.append(`<option value="${field.id}">${field.label} (${field.type})</option>`);
                });
                
                // Restore previous selection if it exists
                if (currentValue) {
                    $select.val(currentValue);
                }
            });
        }
    }
    
    // Add new population mapping row
    $('.add-population-row').on('click', function() {
        var formType = $(this).data('form-type');
        var $template = $(`.form-assignment-section[data-form-type="${formType}"] .population-template`);
        var $tbody = $(`#population-settings-${formType}`);
        var rowIndex = $tbody.find('tr:not(.population-template)').length;
        
        var $newRow = $template.clone();
        $newRow.removeClass('population-template').show();
        
        // Replace PLACEHOLDER with actual index
        var html = $newRow.html().replace(/PLACEHOLDER/g, rowIndex);
        $newRow.html(html);
        
        $tbody.append($newRow);
        
        // Populate field options if form fields are already loaded
        var formId = $(`.form-assignment-section[data-form-type="${formType}"] .form-selector`).val();
        if (formId) {
            loadFormFieldsForRow($newRow.find('.winner-field-select'), formId);
        }
    });
    
    // Remove population mapping row
    $(document).on('click', '.remove-population-row', function() {
        $(this).closest('tr').remove();
    });
    
    // Load form fields for a specific dropdown
    function loadFormFieldsForRow($select, formId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bpm_get_form_fields',
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>',
                form_id: formId
            },
            success: function(response) {
                if (response.success) {
                    var currentValue = $select.val();
                    $select.find('option:not(:first)').remove();
                    
                    response.data.fields.forEach(function(field) {
                        $select.append(`<option value="${field.id}">${field.label} (${field.type})</option>`);
                    });
                    
                    if (currentValue) {
                        $select.val(currentValue);
                    }
                }
            }
        });
    }
    
    // Load form fields and restore saved mappings
    function loadFormFieldsAndRestoreMappings(formType, formId, savedSettings) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bpm_get_form_fields',
                nonce: '<?php echo wp_create_nonce('bpm_nonce'); ?>',
                form_id: formId
            },
            success: function(response) {
                if (response.success) {
                    populateFieldMappings(formType, response.data.fields);
                    
                    // Restore saved field mappings
                    if (savedSettings.field_mapping) {
                        Object.keys(savedSettings.field_mapping).forEach(function(fieldType) {
                            var fieldId = savedSettings.field_mapping[fieldType];
                            if (fieldId) {
                                $(`.form-assignment-section[data-form-type="${formType}"] .field-mapping-select[data-field-type="${fieldType}"]`).val(fieldId);
                            }
                        });
                    }
                    
                    // Show the field mapping section
                    $(`#field-mapping-${formType}`).show();
                } else {
                    console.error('Error loading form fields for ' + formType + ':', response.data);
                }
            },
            error: function() {
                console.error('AJAX error loading form fields for ' + formType);
            }
        });
    }
    
    // Load existing field mappings on page load
    <?php if (!empty($form_settings)): ?>
    var existingMappings = <?php echo json_encode($form_settings); ?>;
    console.log('Loading existing mappings:', existingMappings);
    <?php foreach ($form_settings as $form_type => $settings): ?>
        <?php if (isset($settings['form_id']) && $settings['form_id']): ?>
        (function(formType, formId, settings) {
            setTimeout(function() {
                loadFormFieldsAndRestoreMappings(formType, formId, settings);
            }, 100 * <?php echo array_search($form_type, array_keys($form_settings)) + 1; ?>);
        })('<?php echo esc_js($form_type); ?>', <?php echo (int)$settings['form_id']; ?>, existingMappings['<?php echo esc_js($form_type); ?>'] || {});
        <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    // For newly created GF forms with no saved mapping, auto-load currently selected form fields.
    $('.form-assignment-section').each(function() {
        var formType = $(this).data('form-type');
        var formId = $(this).find('.form-selector').val();
        if (formId && !$(`#field-mapping-${formType}`).is(':visible')) {
            $(this).find('.load-form-fields').trigger('click');
        }
    });
    
    // Serialize form data before submission
    $('#form-assignments-form').on('submit', function(e) {
        var formSettings = {};
        
        $('.form-assignment-section').each(function() {
            var formType = $(this).data('form-type');
            var formId = $(this).find('.form-selector').val();
            
            if (formId) {
                formSettings[formType] = {
                    form_id: formId,
                    field_mapping: {}
                };
                
                if (formType === 'age_gate') {
                    var ageLimit = $(this).find('input[name*="[age_limit]"]').val();
                    if (ageLimit) {
                        formSettings[formType].age_limit = ageLimit;
                    }
                    $(this).find('.field-mapping-select').each(function() {
                        var fieldType = $(this).data('field-type');
                        var fieldId = $(this).val();
                        if (fieldId) {
                            formSettings[formType].field_mapping[fieldType] = fieldId;
                        }
                    });
                } else {
                    $(this).find('.field-mapping-select').each(function() {
                        var fieldType = $(this).data('field-type');
                        var fieldId = $(this).val();
                        
                        if (fieldId) {
                            formSettings[formType].field_mapping[fieldType] = fieldId;
                        }
                    });
                    
                    // Handle population mappings for winner form
                    if (formType === 'winner_form') {
                        formSettings[formType].population_mapping = {};
                        
                        $(this).find('#population-settings-winner_form tr:not(.population-template)').each(function(index) {
                            var winnerFieldId = $(this).find('.winner-field-select').val();
                            var source = $(this).find('.population-source-select').val();
                            var parameterName = $(this).find('.population-param').val();
                            
                            if (winnerFieldId && source && parameterName) {
                                formSettings[formType].population_mapping[index] = {
                                    winner_field_id: winnerFieldId,
                                    source: source,
                                    parameter_name: parameterName
                                };
                            }
                        });
                    }
                }
            }
        });
        
        $('#form-settings-data').val(JSON.stringify(formSettings));
        
        // Debug log
        console.log('Submitting form settings:', formSettings);
    });
});
</script>
