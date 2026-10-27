<?php
/**
 * Dynamic Field Detector Class
 *
 * Automatically detects and maps form fields across all Gravity Forms
 * without requiring hardcoded field assignments
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
 * Dynamic field detection and mapping
 */
class BPM_Dynamic_Field_Detector {
    
    /**
     * Field type patterns for auto-detection
     */
    private static array $field_patterns = [
        'name_field' => [
            'types' => ['text', 'name'],
            'labels' => ['name', 'full name', 'first name', 'last name', 'your name'],
            'admin_labels' => ['name', 'fullname', 'firstname', 'lastname'],
            'css_classes' => ['name', 'fullname', 'first-name', 'last-name']
        ],
        'email_field' => [
            'types' => ['email'],
            'labels' => ['email', 'e-mail', 'email address', 'your email'],
            'admin_labels' => ['email', 'emailaddress', 'e-mail'],
            'css_classes' => ['email', 'email-address']
        ],
        'code_field' => [
            'types' => ['text', 'hidden'],
            'labels' => ['code', 'promo code', 'promotional code', 'entry code', 'unique code', 'product code'],
            'admin_labels' => ['code', 'promocode', 'entrycode', 'productcode'],
            'css_classes' => ['code', 'promo-code', 'entry-code']
        ],
        'phone_field' => [
            'types' => ['phone', 'text'],
            'labels' => ['phone', 'telephone', 'mobile', 'phone number', 'mobile number'],
            'admin_labels' => ['phone', 'telephone', 'mobile', 'phonenumber'],
            'css_classes' => ['phone', 'telephone', 'mobile', 'phone-number']
        ],
        'county_field' => [
            'types' => ['select', 'text'],
            'labels' => ['county', 'region', 'state', 'province', 'location', 'area'],
            'admin_labels' => ['county', 'region', 'state', 'province', 'location'],
            'css_classes' => ['county', 'region', 'state', 'location']
        ],
        'terms_field' => [
            'types' => ['checkbox'],
            'labels' => ['terms', 'terms and conditions', 'agree', 'accept terms', 'privacy policy'],
            'admin_labels' => ['terms', 'agree', 'accept', 'privacy', 'conditions'],
            'css_classes' => ['terms', 'agree', 'accept', 'privacy']
        ],
        'file_field' => [
            'types' => ['fileupload'],
            'labels' => ['upload', 'file', 'receipt', 'proof of purchase', 'image', 'photo'],
            'admin_labels' => ['upload', 'file', 'receipt', 'proof', 'image'],
            'css_classes' => ['upload', 'file', 'receipt', 'proof']
        ],
        'dob_field' => [
            'types' => ['date'],
            'labels' => ['date of birth', 'dob', 'birth date', 'birthday'],
            'admin_labels' => ['dob', 'dateofbirth', 'birthdate', 'birthday'],
            'css_classes' => ['dob', 'date-of-birth', 'birthdate']
        ],
        'prize_code_field' => [
            'types' => ['text', 'hidden'],
            'labels' => ['prize code', 'winning code', 'voucher code', 'ticket code'],
            'admin_labels' => ['prizecode', 'winningcode', 'vouchercode', 'ticketcode'],
            'css_classes' => ['prize-code', 'voucher-code', 'ticket-code']
        ],
        'prize_value_field' => [
            'types' => ['text', 'hidden', 'number'],
            'labels' => ['prize value', 'value', 'amount', 'worth'],
            'admin_labels' => ['prizevalue', 'value', 'amount', 'worth'],
            'css_classes' => ['prize-value', 'value', 'amount']
        ],
        'prize_id_field' => [
            'types' => ['text', 'hidden', 'number'],
            'labels' => ['prize id', 'prize identifier', 'prize number'],
            'admin_labels' => ['prizeid', 'prizeidentifier', 'prizenumber'],
            'css_classes' => ['prize-id', 'prize-identifier']
        ]
    ];
    
    /**
     * Get all field mappings for all forms
     *
     * @return array Comprehensive field mappings
     */
    public static function detect_all_field_mappings(): array {
        if (!class_exists('GFAPI')) {
            return [];
        }
        
        $all_mappings = [];
        $forms = \RGFormsModel::get_forms(null, 'title');
        
        foreach ($forms as $form) {
            $form_meta = \RGFormsModel::get_form_meta($form->id);
            if (!$form_meta || empty($form_meta['fields'])) {
                continue;
            }
            
            $form_mappings = self::detect_form_field_mappings((int) $form->id, $form_meta['fields']);
            if (!empty($form_mappings)) {
                $all_mappings[$form->id] = $form_mappings;
            }
        }
        
        // error_log('BPM DYNAMIC FIELD DETECTOR: All detected mappings: ' . print_r($all_mappings, true));
        return $all_mappings;
    }
    
    /**
     * Detect field mappings for a specific form
     *
     * @param int $form_id Form ID
     * @param array $fields Form fields
     * @return array Field mappings
     */
    public static function detect_form_field_mappings(int $form_id, array $fields): array {
        $mappings = [];
        
        foreach ($fields as $field) {
            if (!isset($field->type) || !isset($field->id)) {
                continue;
            }
            
            foreach (self::$field_patterns as $field_type => $patterns) {
                if (self::field_matches_pattern($field, $patterns)) {
                    // Store the first match for each field type per form
                    if (!isset($mappings[$field_type])) {
                        $mappings[$field_type] = [
                            'field_id' => $field->id,
                            'field_label' => $field->label ?? '',
                            'field_type' => $field->type,
                            'confidence' => self::calculate_match_confidence($field, $patterns)
                        ];
                        
                        // error_log("BPM DYNAMIC FIELD DETECTOR: Form {$form_id} - Detected {$field_type}: Field ID {$field->id} ({$field->label})");
                    }
                }
            }
        }
        
        return $mappings;
    }
    
    /**
     * Check if a field matches a pattern
     *
     * @param object $field Gravity Forms field object
     * @param array $patterns Field patterns to match against
     * @return bool Whether field matches pattern
     */
    private static function field_matches_pattern($field, array $patterns): bool {
        $field_type = strtolower($field->type ?? '');
        $field_label = strtolower($field->label ?? '');
        $field_admin_label = strtolower($field->adminLabel ?? '');
        $field_css_class = strtolower($field->cssClass ?? '');
        
        // Check field type match
        if (in_array($field_type, $patterns['types'], true)) {
            
            // Check label patterns
            foreach ($patterns['labels'] as $label_pattern) {
                if (strpos($field_label, strtolower($label_pattern)) !== false) {
                    return true;
                }
            }
            
            // Check admin label patterns
            foreach ($patterns['admin_labels'] as $admin_pattern) {
                if (strpos($field_admin_label, strtolower($admin_pattern)) !== false) {
                    return true;
                }
            }
            
            // Check CSS class patterns
            foreach ($patterns['css_classes'] as $css_pattern) {
                if (strpos($field_css_class, strtolower($css_pattern)) !== false) {
                    return true;
                }
            }
            
            // For specific field types, less strict matching
            if ($field_type === 'email') {
                return true; // Email fields are distinctive enough
            }
            
            if ($field_type === 'phone') {
                return true; // Phone fields are distinctive enough
            }
            
            if ($field_type === 'fileupload') {
                return true; // File upload fields are distinctive enough
            }
            
            if ($field_type === 'date') {
                return true; // Date fields are distinctive enough
            }
        }
        
        return false;
    }
    
    /**
     * Calculate confidence score for field match
     *
     * @param object $field Gravity Forms field object
     * @param array $patterns Field patterns
     * @return float Confidence score (0-1)
     */
    private static function calculate_match_confidence($field, array $patterns): float {
        $score = 0.0;
        $field_type = strtolower($field->type ?? '');
        $field_label = strtolower($field->label ?? '');
        $field_admin_label = strtolower($field->adminLabel ?? '');
        $field_css_class = strtolower($field->cssClass ?? '');
        
        // Type match gives base score
        if (in_array($field_type, $patterns['types'], true)) {
            $score += 0.3;
        }
        
        // Label matches
        foreach ($patterns['labels'] as $label_pattern) {
            if (strpos($field_label, strtolower($label_pattern)) !== false) {
                $score += 0.4;
                break;
            }
        }
        
        // Admin label matches
        foreach ($patterns['admin_labels'] as $admin_pattern) {
            if (strpos($field_admin_label, strtolower($admin_pattern)) !== false) {
                $score += 0.2;
                break;
            }
        }
        
        // CSS class matches
        foreach ($patterns['css_classes'] as $css_pattern) {
            if (strpos($field_css_class, strtolower($css_pattern)) !== false) {
                $score += 0.1;
                break;
            }
        }
        
        return min($score, 1.0);
    }
    
    /**
     * Get field mapping for a specific form and field type
     *
     * @param int $form_id Form ID
     * @param string $field_type Field type to find
     * @return array|null Field mapping or null if not found
     */
    public static function get_field_mapping(int $form_id, string $field_type): ?array {
        $all_mappings = self::detect_all_field_mappings();
        return $all_mappings[$form_id][$field_type] ?? null;
    }
    
    /**
     * Get all field IDs for a specific field type across all forms
     *
     * @param string $field_type Field type to find
     * @return array Array of form_id => field_id mappings
     */
    public static function get_all_field_ids_by_type(string $field_type): array {
        $all_mappings = self::detect_all_field_mappings();
        $field_ids = [];
        
        foreach ($all_mappings as $form_id => $form_mappings) {
            if (isset($form_mappings[$field_type])) {
                $field_ids[$form_id] = $form_mappings[$field_type]['field_id'];
            }
        }
        
        return $field_ids;
    }
    
    /**
     * Setup validation hooks for all detected fields
     *
     * @param int $competition_id Competition ID for configuration
     */
    public static function setup_validation_hooks(int $competition_id): void {
        // error_log("BPM DYNAMIC FIELD DETECTOR: setup_validation_hooks called for competition {$competition_id}");
        $all_mappings = self::detect_all_field_mappings();
        // error_log("BPM DYNAMIC FIELD DETECTOR: Detected mappings: " . print_r($all_mappings, true));
        
        // Get competition configuration
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $age_limit = 16; // Default age limit
        
        if (isset($form_settings['age_gate']['age_limit'])) {
            $age_limit = (int) $form_settings['age_gate']['age_limit'];
        }
        
        // Only apply validation to specific form types that need it
        $forms_requiring_validation = [];
        
        // Age gate forms need age validation
        if (isset($form_settings['age_gate']['form_id'])) {
            $forms_requiring_validation['age_gate'] = $form_settings['age_gate']['form_id'];
        }
        
        // Winner forms need age validation when claiming prizes
        if (isset($form_settings['winner_form']['form_id'])) {
            $forms_requiring_validation['winner_form'] = $form_settings['winner_form']['form_id'];
        }
        
        foreach ($all_mappings as $form_id => $form_mappings) {
            // Check if this form needs validation
            $form_type = null;
            foreach ($forms_requiring_validation as $type => $mapped_form_id) {
                if ((int) $mapped_form_id === (int) $form_id) {
                    $form_type = $type;
                    break;
                }
            }
            
            // Skip validation for forms that don't need it
            if (!$form_type) {
                // error_log("BPM DYNAMIC VALIDATION: Skipping validation for form {$form_id} - not in validation list");
                continue;
            }
            
            // error_log("BPM DYNAMIC VALIDATION: Setting up validation for form {$form_id} as {$form_type}");
            
            // Only setup age validation for DOB fields (the only validation we actually need)
            if (isset($form_mappings['dob_field'])) {
                $field_id = $form_mappings['dob_field']['field_id'];
                
                add_filter("gform_field_validation_{$form_id}_{$field_id}", function($result, $value, $form, $field) use ($age_limit, $competition_id) {
                    return self::validate_age_field_dynamic($result, $value, $form, $field, $age_limit, $competition_id);
                }, 10, 4);
                
                // error_log("BPM DYNAMIC VALIDATION: Age validation registered for form {$form_id} field {$field_id} with age limit {$age_limit}");
            }
            
            // DO NOT setup validation for email, phone, or terms fields
            // Let Gravity Forms handle these with its built-in validation
        }
    }
    
    /**
     * Dynamic age field validation
     */
    public static function validate_age_field_dynamic($result, $value, $form, $field, int $age_limit, int $competition_id) {
        if (!$result['is_valid']) {
            return $result;
        }
        
        try {
            if (is_array($value)) {
                $value = array_values($value);
            }
            
            $date_value = \GFFormsModel::prepare_date($field->dateFormat, $value);
            
            if (empty($date_value)) {
                $result['is_valid'] = false;
                $result['message'] = 'Please enter a valid date of birth';
                return $result;
            }
            
            $today = new DateTime();
            $birth_date = new DateTime($date_value);
            $diff = $today->diff($birth_date);
            $age = $diff->y;
            
            if ($age < $age_limit) {
                $result['is_valid'] = false;
                $result['message'] = 'PLEASE ENTER A VALID DATE OF BIRTH';
            }
            
            // error_log("BPM DYNAMIC AGE VALIDATION: Form {$form['id']} Field {$field->id} - Age: {$age}, Limit: {$age_limit}, Valid: " . ($result['is_valid'] ? 'Yes' : 'No'));
            
        } catch (Exception $e) {
            // error_log("BPM DYNAMIC AGE VALIDATION ERROR: " . $e->getMessage());
            $result['is_valid'] = false;
            $result['message'] = 'Please enter a valid date of birth';
        }
        
        return $result;
    }
    
    /**
     * Dynamic email field validation
     */
    public static function validate_email_field_dynamic($result, $value, $form, $field, int $competition_id) {
        if (!$result['is_valid'] || empty($value)) {
            return $result;
        }
        
        // Additional email validation if needed
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $result['is_valid'] = false;
            $result['message'] = 'Please enter a valid email address';
        }
        
        return $result;
    }
    
    /**
     * Dynamic phone field validation
     */
    public static function validate_phone_field_dynamic($result, $value, $form, $field, int $competition_id) {
        if (!$result['is_valid'] || empty($value)) {
            return $result;
        }
        
        // Basic phone number validation
        $clean_phone = preg_replace('/[^0-9+]/', '', $value);
        if (strlen($clean_phone) < 10) {
            $result['is_valid'] = false;
            $result['message'] = 'Please enter a valid phone number';
        }
        
        return $result;
    }
    
    /**
     * Dynamic terms field validation
     */
    public static function validate_terms_field_dynamic($result, $value, $form, $field, int $competition_id) {
        if (!$result['is_valid']) {
            return $result;
        }
        
        // Debug logging
        error_log('BPM TERMS VALIDATION: Field ' . $field->id . ' received value: ' . print_r($value, true));
        error_log('BPM TERMS VALIDATION: Field type: ' . $field->type);
        error_log('BPM TERMS VALIDATION: Field choices: ' . print_r($field->choices ?? [], true));
        
        // Handle different field types
        $is_accepted = false;
        
        if ($field->type === 'checkbox') {
            // For checkbox fields, value is an array of selected choice values
            if (is_array($value) && !empty($value)) {
                $is_accepted = true; // Any checked checkbox means accepted
            }
        } elseif ($field->type === 'consent') {
            // For consent fields, check if consent is given
            $is_accepted = !empty($value);
        } else {
            // For other field types, check for common "accepted" values
            $accepted_values = ['1', 'true', 'yes', 'accept', 'accepted'];
            $is_accepted = in_array(strtolower($value), $accepted_values, true);
        }
        
        error_log('BPM TERMS VALIDATION: Is accepted: ' . ($is_accepted ? 'YES' : 'NO'));
        
        if (!$is_accepted) {
            $result['is_valid'] = false;
            $result['message'] = 'You must accept the terms and conditions to continue';
        }
        
        return $result;
    }
    
    /**
     * Get field value from entry data using dynamic detection
     *
     * @param array $entry_data Entry data
     * @param string $field_type Field type to get value for
     * @param int $form_id Form ID (optional, for optimization)
     * @return mixed Field value or null
     */
    public static function get_field_value_dynamic(array $entry_data, string $field_type, int $form_id = null) {
        if ($form_id) {
            // Optimized path - check specific form first
            $mapping = self::get_field_mapping($form_id, $field_type);
            if ($mapping && isset($entry_data[$mapping['field_id']])) {
                return $entry_data[$mapping['field_id']];
            }
        }
        
        // Fallback - check all possible field IDs for this type
        $all_field_ids = self::get_all_field_ids_by_type($field_type);
        
        foreach ($all_field_ids as $check_form_id => $field_id) {
            if (isset($entry_data[$field_id])) {
                return $entry_data[$field_id];
            }
        }
        
        return null;
    }
}