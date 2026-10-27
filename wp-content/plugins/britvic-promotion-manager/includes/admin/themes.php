<?php
/**
 * Theme Management Admin Page
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Handle theme deletion
if (isset($_POST['delete_theme']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_delete_theme')) {
    $theme_id = sanitize_text_field($_POST['delete_theme_id']);
    
    if (!empty($theme_id) && class_exists('BPM_Theme_Manager')) {
        if (BPM_Theme_Manager::delete_theme($theme_id)) {
            echo '<div class="notice notice-success"><p>Theme deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to delete theme.</p></div>';
        }
    }
}

// Handle theme registration
if (isset($_POST['register_theme']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_register_theme')) {
    $theme_id = sanitize_text_field($_POST['new_theme_id']);
    $name = sanitize_text_field($_POST['name']);
    $description = sanitize_text_field($_POST['description']);
    $version = sanitize_text_field($_POST['version']);
    $template_path = sanitize_text_field($_POST['template_path']);
    $supports = array_map('sanitize_text_field', $_POST['supports'] ?? []);
    $after_entry = sanitize_text_field($_POST['after_entry']);
    $after_claim = sanitize_text_field($_POST['after_claim']);
    
    // Debug: Log the data being submitted
    error_log("BPM: Theme registration attempt - Theme ID: $theme_id, Name: $name, Template: $template_path");
    
    if (!empty($theme_id) && !empty($name) && class_exists('BPM_Theme_Manager')) {
        $theme_config = [
            'name' => $name,
            'description' => $description,
            'version' => $version,
            'template_path' => $template_path,
            'supports' => $supports ?: ['instant_win'], // Default if empty
            'pages' => [
                'entry' => $template_path,
                'reveal' => str_replace('entry', 'reveal', $template_path),
            ],
            'active' => true,
            'redirect_settings' => [
                'after_entry' => $after_entry ?: '/reveal',
                'after_claim' => $after_claim ?: '/thanks'
            ]
        ];
        
        // Debug: Check if template file exists
        $template_file_path = get_template_directory() . '/' . $template_path;
        error_log("BPM: Checking template file: $template_file_path - Exists: " . (file_exists($template_file_path) ? 'YES' : 'NO'));
        
        if (BPM_Theme_Manager::register_theme($theme_id, $theme_config)) {
            echo '<div class="notice notice-success"><p>Theme registered successfully!</p></div>';
        } else {
            $error_details = [
                'theme_id' => $theme_id,
                'name' => $name,
                'template_path' => $template_path,
                'template_exists' => file_exists($template_file_path),
                'template_directory' => get_template_directory()
            ];
            echo '<div class="notice notice-error"><p>Failed to register theme. Debug info logged to error log.</p></div>';
            error_log("BPM: Theme registration failed. Details: " . print_r($error_details, true));
        }
    } else {
        echo '<div class="notice notice-error"><p>Missing required fields or BPM_Theme_Manager class not available.</p></div>';
    }
}

// Handle theme editing
if (isset($_POST['edit_theme']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_edit_theme')) {
    $theme_id = sanitize_text_field($_POST['edit_theme_id']);
    $name = sanitize_text_field($_POST['name']);
    $description = sanitize_text_field($_POST['description']);
    $version = sanitize_text_field($_POST['version']);
    $template_path = sanitize_text_field($_POST['template_path']);
    $supports = array_map('sanitize_text_field', $_POST['supports'] ?? []);
    $after_entry = sanitize_text_field($_POST['after_entry']);
    $after_claim = sanitize_text_field($_POST['after_claim']);
    
    if (!empty($theme_id) && !empty($name) && class_exists('BPM_Theme_Manager')) {
        $theme_config = [
            'name' => $name,
            'description' => $description,
            'version' => $version,
            'template_path' => $template_path,
            'supports' => $supports,
            'pages' => [
                'entry' => $template_path,
                'reveal' => str_replace('entry', 'reveal', $template_path),
            ],
            'active' => true,
            'redirect_settings' => [
                'after_entry' => $after_entry,
                'after_claim' => $after_claim
            ]
        ];
        
        if (BPM_Theme_Manager::register_theme($theme_id, $theme_config)) {
            echo '<div class="notice notice-success"><p>Theme updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to update theme. Check configuration.</p></div>';
        }
    }
}

// Handle theme assignment
if (isset($_POST['assign_theme']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_assign_theme')) {
    $competition_id = (int) $_POST['competition_id'];
    $theme_id = sanitize_text_field($_POST['theme_id']);
    
    if ($competition_id > 0 && !empty($theme_id)) {
        if (class_exists('BPM_Theme_Manager') && BPM_Theme_Manager::set_competition_theme($competition_id, $theme_id)) {
            echo '<div class="notice notice-success"><p>Theme assigned successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to assign theme. Check theme compatibility.</p></div>';
        }
    }
}

// Get all competitions
global $wpdb;
$competitions_table = $wpdb->prefix . 'bpm_competitions';
$competitions = $wpdb->get_results("SELECT id, name, type, status FROM $competitions_table ORDER BY created_at DESC");

// Get all registered themes
$registered_themes = [];
if (class_exists('BPM_Theme_Manager')) {
    $registered_themes = BPM_Theme_Manager::get_registered_themes();
}

// Get all available WordPress themes
$available_themes = [];
$wp_themes = wp_get_themes();
foreach ($wp_themes as $theme_stylesheet => $theme_object) {
    $theme_data = [
        'id' => sanitize_key(strtolower(str_replace([' ', '-'], '_', $theme_object->get('Name')))),
        'name' => $theme_object->get('Name'),
        'description' => $theme_object->get('Description'),
        'version' => $theme_object->get('Version'),
        'stylesheet' => $theme_stylesheet,
        'template_directory' => $theme_object->get_template_directory(),
        'templates' => []
    ];
    
    // Scan for relevant template files
    $template_dir = $theme_object->get_template_directory();
    $template_files = glob($template_dir . '/page-*.php');
    
    foreach ($template_files as $template_file) {
        $template_name = basename($template_file);
        $theme_data['templates'][] = $template_name;
    }
    
    // Look for entry template specifically
    if (file_exists($template_dir . '/page-entry.php')) {
        $theme_data['entry_template'] = 'page-entry.php';
    } elseif (!empty($theme_data['templates'])) {
        $theme_data['entry_template'] = $theme_data['templates'][0];
    } else {
        $theme_data['entry_template'] = 'page.php';
    }
    
    $available_themes[] = $theme_data;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    
    <div class="bpm-admin-content">
        
        <div class="bpm-admin-section">
            <h2><?php _e('Register New Theme', 'britvic-promo'); ?></h2>
            
            <form method="post" action="" id="theme-registration-form">
                <?php wp_nonce_field('bpm_register_theme'); ?>
                <p><strong>Note:</strong> Fields marked with <span style="color: red;">*</span> are required.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">WordPress Theme <span style="color: red;">*</span></th>
                        <td>
                            <select name="wp_theme_selector" id="wp-theme-selector" onchange="populateThemeData()" required>
                                <option value="">Select a WordPress theme...</option>
                                <?php foreach ($available_themes as $theme_data): ?>
                                    <option value="<?php echo esc_attr(json_encode($theme_data)); ?>">
                                        <?php echo esc_html($theme_data['name']); ?> (<?php echo esc_html($theme_data['version']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select from available WordPress themes - all fields below will auto-populate</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Theme ID <span style="color: red;">*</span></th>
                        <td>
                            <input type="text" name="new_theme_id" id="theme-id" class="regular-text" readonly required />
                            <p class="description">Auto-generated unique identifier (readonly)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Theme Name <span style="color: red;">*</span></th>
                        <td>
                            <input type="text" name="name" id="theme-name" class="regular-text" readonly required />
                            <p class="description">Auto-populated from WordPress theme (readonly)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Description</th>
                        <td>
                            <input type="text" name="description" id="theme-description" class="regular-text" />
                            <p class="description">Auto-populated from WordPress theme (optional - editable for custom descriptions)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Version <span style="color: red;">*</span></th>
                        <td>
                            <input type="text" name="version" id="theme-version" class="regular-text" readonly required />
                            <p class="description">Auto-populated from WordPress theme (readonly)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Template Path <span style="color: red;">*</span></th>
                        <td>
                            <select name="template_path" id="theme-template-path" required>
                                <option value="">Select template file...</option>
                            </select>
                            <p class="description">Available template files for the selected theme</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Supports</th>
                        <td>
                            <label><input type="checkbox" name="supports[]" value="instant_win" checked /> Instant Win</label><br />
                            <label><input type="checkbox" name="supports[]" value="prize_draw" /> Prize Draw</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">After Entry Redirect <span style="color: red;">*</span></th>
                        <td>
                            <input type="text" id="after-entry" name="after_entry" class="regular-text" value="/reveal" required />
                            <p class="description">URL to redirect to after initial form submission</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">After Claim Redirect <span style="color: red;">*</span></th>
                        <td>
                            <input type="text" id="after-claim" name="after_claim" class="regular-text" value="/thanks" required />
                            <p class="description">URL to redirect to after prize claim</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="register_theme" class="button-primary" value="Register Theme" onclick="return validateThemeForm()" />
                </p>
            </form>
        </div>
        
        <!-- Edit Theme Form (hidden by default) -->
        <div class="bpm-admin-section" id="edit-theme-section" style="display: none;">
            <h2><?php _e('Edit Theme', 'britvic-promo'); ?></h2>
            
            <form method="post" action="" id="edit-theme-form">
                <?php wp_nonce_field('bpm_edit_theme'); ?>
                <input type="hidden" name="edit_theme_id" id="edit-theme-id" />
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Theme ID</th>
                        <td>
                            <input type="text" id="edit-theme-id-display" class="regular-text" disabled />
                            <p class="description">Theme ID cannot be changed</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Theme Name</th>
                        <td>
                            <input type="text" name="name" id="edit-name" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Description</th>
                        <td>
                            <input type="text" name="description" id="edit-description" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Version</th>
                        <td>
                            <input type="text" name="version" id="edit-version" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Template Path</th>
                        <td>
                            <input type="text" name="template_path" id="edit-template-path" class="regular-text" required />
                            <p class="description">Main template file for the entry page</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Supports</th>
                        <td>
                            <label><input type="checkbox" name="supports[]" value="instant_win" id="edit-instant-win" /> Instant Win</label><br />
                            <label><input type="checkbox" name="supports[]" value="prize_draw" id="edit-prize-draw" /> Prize Draw</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">After Entry Redirect</th>
                        <td>
                            <input type="text" name="after_entry" id="edit-after-entry" class="regular-text" required />
                            <p class="description">URL to redirect to after initial form submission</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">After Claim Redirect</th>
                        <td>
                            <input type="text" name="after_claim" id="edit-after-claim" class="regular-text" required />
                            <p class="description">URL to redirect to after prize claim</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="edit_theme" class="button-primary" value="Update Theme" />
                    <button type="button" class="button" onclick="cancelEdit()">Cancel</button>
                </p>
            </form>
        </div>
        
        <div class="bpm-admin-section">
            <h2><?php _e('Registered Themes', 'britvic-promo'); ?></h2>
            <?php if (empty($registered_themes)): ?>
                <p>No themes registered. Make sure your theme is calling <code>BPM_Theme_Manager::register_theme()</code>.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Theme ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Version</th>
                            <th>Supports</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registered_themes as $theme_id => $theme): ?>
                        <tr>
                            <td><code><?php echo esc_html($theme_id); ?></code></td>
                            <td><strong><?php echo esc_html($theme['name']); ?></strong></td>
                            <td><?php echo esc_html($theme['description']); ?></td>
                            <td><?php echo esc_html($theme['version']); ?></td>
                            <td><?php echo esc_html(implode(', ', $theme['supports'])); ?></td>
                            <td>
                                <?php 
                                $validation = class_exists('BPM_Theme_Manager') ? BPM_Theme_Manager::validate_theme($theme_id) : ['valid' => false, 'errors' => ['Theme manager not available'], 'warnings' => []];
                                if ($validation['valid']): ?>
                                    <span style="color: green;">✓ Valid</span>
                                <?php else: ?>
                                    <span style="color: red;">✗ Invalid</span>
                                    <details>
                                        <summary>Errors</summary>
                                        <ul>
                                            <?php foreach ($validation['errors'] as $error): ?>
                                                <li style="color: red;"><?php echo esc_html($error); ?></li>
                                            <?php endforeach; ?>
                                            <?php foreach ($validation['warnings'] as $warning): ?>
                                                <li style="color: orange;"><?php echo esc_html($warning); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button" onclick="showThemeDetails('<?php echo esc_js($theme_id); ?>')" style="margin-right: 5px;" title="View Details">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i><span class="screen-reader-text">View Details</span>
                                </button>
                                <button type="button" class="button button-primary" onclick="editTheme('<?php echo esc_js($theme_id); ?>')" style="margin-right: 5px;" title="Edit">
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i><span class="screen-reader-text">Edit</span>
                                </button>
                                <button type="button" class="button button-secondary" onclick="deleteTheme('<?php echo esc_js($theme_id); ?>', '<?php echo esc_js($theme['name']); ?>')" style="color: #d63638;" title="Delete">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i><span class="screen-reader-text">Delete</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        
    </div>
</div>

<!-- Hidden form for theme deletion -->
<form method="post" action="" id="delete-theme-form" style="display: none;">
    <?php wp_nonce_field('bpm_delete_theme'); ?>
    <input type="hidden" name="delete_theme_id" id="delete-theme-id" />
    <input type="hidden" name="delete_theme" value="1" />
</form>

<style>
.bpm-admin-container .postbox {
    margin-bottom: 20px;
}
.status-active { color: green; font-weight: bold; }
.status-scheduled { color: blue; }
.status-draft { color: gray; }
.status-closed { color: red; }
</style>

<script>
function showThemeDetails(themeId) {
    const themes = <?php echo wp_json_encode($registered_themes); ?>;
    const theme = themes[themeId];
    
    if (!theme) return;
    
    let details = `Theme: ${theme.name}\n`;
    details += `ID: ${themeId}\n`;
    details += `Version: ${theme.version}\n`;
    details += `Description: ${theme.description}\n`;
    details += `Supports: ${theme.supports.join(', ')}\n`;
    details += `Template: ${theme.template_path}\n`;
    
    if (theme.required_functions) {
        details += `Required Functions: ${theme.required_functions.join(', ')}\n`;
    }
    
    if (theme.pages) {
        details += `\nPages:\n`;
        Object.entries(theme.pages).forEach(([type, path]) => {
            details += `  ${type}: ${path}\n`;
        });
    }
    
    alert(details);
}

function editTheme(themeId) {
    const themes = <?php echo wp_json_encode($registered_themes); ?>;
    const theme = themes[themeId];
    
    if (!theme) return;
    
    // Hide register form and show edit form
    document.getElementById('edit-theme-section').style.display = 'block';
    document.getElementById('edit-theme-section').scrollIntoView({ behavior: 'smooth' });
    
    // Populate the form
    document.getElementById('edit-theme-id').value = themeId;
    document.getElementById('edit-theme-id-display').value = themeId;
    document.getElementById('edit-name').value = theme.name || '';
    document.getElementById('edit-description').value = theme.description || '';
    document.getElementById('edit-version').value = theme.version || '';
    document.getElementById('edit-template-path').value = theme.template_path || '';
    document.getElementById('edit-after-entry').value = theme.redirect_settings?.after_entry || '/reveal';
    document.getElementById('edit-after-claim').value = theme.redirect_settings?.after_claim || '/thanks';
    
    // Handle checkboxes
    document.getElementById('edit-instant-win').checked = theme.supports?.includes('instant_win') || false;
    document.getElementById('edit-prize-draw').checked = theme.supports?.includes('prize_draw') || false;
}

function cancelEdit() {
    document.getElementById('edit-theme-section').style.display = 'none';
}

function validateThemeForm() {
    const requiredFields = [
        { id: 'wp-theme-selector', name: 'WordPress Theme' },
        { id: 'theme-id', name: 'Theme ID' },
        { id: 'theme-name', name: 'Theme Name' },
        { id: 'theme-version', name: 'Version' },
        { id: 'theme-template-path', name: 'Template Path' },
        { id: 'after-entry', name: 'After Entry Redirect' },
        { id: 'after-claim', name: 'After Claim Redirect' }
    ];
    
    let missingFields = [];
    
    for (let field of requiredFields) {
        const element = document.getElementById(field.id);
        if (!element || !element.value.trim()) {
            missingFields.push(field.name);
        }
    }
    
    if (missingFields.length > 0) {
        alert('Please fill in the following required fields:\n• ' + missingFields.join('\n• '));
        return false;
    }
    
    return true;
}

function deleteTheme(themeId, themeName) {
    if (confirm(`Are you sure you want to delete the theme "${themeName}"? This action cannot be undone.`)) {
        document.getElementById('delete-theme-id').value = themeId;
        document.getElementById('delete-theme-form').submit();
    }
}

function populateThemeData() {
    const selector = document.getElementById('wp-theme-selector');
    const selectedOption = selector.value;
    
    if (!selectedOption) {
        // Clear all fields
        document.getElementById('theme-id').value = '';
        document.getElementById('theme-name').value = '';
        document.getElementById('theme-description').value = '';
        document.getElementById('theme-version').value = '';
        document.getElementById('theme-template-path').innerHTML = '<option value="">Select template file...</option>';
        return;
    }
    
    try {
        const themeData = JSON.parse(selectedOption);
        
        // Populate fields (description is editable)
        document.getElementById('theme-id').value = themeData.id;
        document.getElementById('theme-name').value = themeData.name;
        document.getElementById('theme-description').value = themeData.description;
        document.getElementById('theme-version').value = themeData.version;
        
        // Populate template dropdown
        const templateSelect = document.getElementById('theme-template-path');
        templateSelect.innerHTML = '<option value="">Select template file...</option>';
        
        if (themeData.templates && themeData.templates.length > 0) {
            themeData.templates.forEach(function(template) {
                const option = document.createElement('option');
                option.value = template;
                option.textContent = template;
                if (template === themeData.entry_template) {
                    option.selected = true;
                }
                templateSelect.appendChild(option);
            });
        }
        
        // If no templates found, add the entry template as option
        if (themeData.entry_template && themeData.templates.length === 0) {
            const option = document.createElement('option');
            option.value = themeData.entry_template;
            option.textContent = themeData.entry_template;
            option.selected = true;
            templateSelect.appendChild(option);
        }
        
    } catch (e) {
        console.error('Error parsing theme data:', e);
    }
}
</script>