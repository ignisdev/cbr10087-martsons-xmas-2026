<?php
/**
 * Gravity Forms Integration Class
 *
 * Handles all Gravity Forms integration including validation, form submission handling, and dynamic form assignment
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
 * Gravity Forms integration and management
 */
class BPM_Form_Integration {
    
    /**
     * Initialize form integration
     */
    public static function init(): void {
        // Only initialize if Gravity Forms is active
        if (!class_exists('GFAPI')) {
            return;
        }
        
        // Add form validation hooks early
        add_action('wp_loaded', [__CLASS__, 'setup_validation_hooks']);
        
        // Add form submission hooks
        self::setup_submission_hooks(); // Call directly instead of hooking to 'init'
        
        // Also set up validation hooks on gform_loaded in case we need it earlier
        add_action('gform_loaded', [__CLASS__, 'setup_validation_hooks']);
        
        // Add AJAX handlers for form management
        add_action('wp_ajax_bpm_get_form_fields', [__CLASS__, 'handle_ajax_get_form_fields']);
        add_action('wp_ajax_bpm_save_form_mappings', [__CLASS__, 'handle_ajax_save_form_mappings']);
    }
    
    /**
     * Setup dynamic validation hooks based on competition settings
     */
    public static function setup_validation_hooks(): void {
        
        // Get all active competitions and their form settings
        global $wpdb;
        $competitions_table = $wpdb->prefix . 'bpm_competitions';
        
        $active_competitions = $wpdb->get_results(
            "SELECT id FROM $competitions_table WHERE status = 'active'"
        );
        
        
        foreach ($active_competitions as $competition) {
            $form_settings = get_option("bpm_form_settings_{$competition->id}", []);
            
            // Use dynamic field detector for all validation hooks
            if (class_exists('BPM_Dynamic_Field_Detector')) {
                BPM_Dynamic_Field_Detector::setup_validation_hooks((int) $competition->id);
            } else {
                // Fallback to legacy age validation only
                self::setup_legacy_age_validation((int) $competition->id, $form_settings);
            }
            
            // ALWAYS setup legacy validation as backup to ensure forms work
            self::setup_legacy_age_validation((int) $competition->id, $form_settings);

            // Setup code validation for code entry forms (maintain legacy support)
            if (isset($form_settings['code_entry_form'])) {
                $form_id = $form_settings['code_entry_form']['form_id'];
                $field_id = $form_settings['code_entry_form']['code_field_id'];
                
                if ($form_id && $field_id) {
                    add_filter("gform_field_validation_{$form_id}_{$field_id}", [__CLASS__, 'validate_code_field'], 10, 4);
                }
            }
        }
    }
    
    /**
     * Legacy age validation setup for backward compatibility
     */
    private static function setup_legacy_age_validation(int $competition_id, array $form_settings): void {
        // Setup age gate validation with dynamic detection of date fields
        $age_limit = 16; // Default age limit
        
        // Check if age gate settings exist and get custom age limit
        if (isset($form_settings['age_gate']) && isset($form_settings['age_gate']['age_limit'])) {
            $age_limit = (int) $form_settings['age_gate']['age_limit'];
        }
        
        // If specific field mapping exists, use it
        if (isset($form_settings['age_gate']) && 
            isset($form_settings['age_gate']['field_mapping']) && 
            isset($form_settings['age_gate']['field_mapping']['dob_field'])) {
            
            $form_id = $form_settings['age_gate']['form_id'] ?? null;
            $field_id = $form_settings['age_gate']['field_mapping']['dob_field'];
            
            if ($form_id && $field_id) {
                add_filter("gform_field_validation_{$form_id}_{$field_id}", function($result, $value, $form, $field) use ($age_limit, $competition_id) {
                    return self::validate_age_field_dynamic($result, $value, $form, $field, $age_limit, $competition_id);
                }, 10, 4);
                
            }
        } else {
            // Auto-detect date fields across all forms and register validation
            $forms = \RGFormsModel::get_forms(null, 'title');
            foreach ($forms as $form) {
                $form_meta = \RGFormsModel::get_form_meta($form->id);
                if (!$form_meta || !isset($form_meta['fields'])) continue;
                
                foreach ($form_meta['fields'] as $field) {
                    // Check if this is a date field that could be used for age validation
                    if (isset($field->type) && $field->type === 'date') {
                        // Register validation for this date field
                        add_filter("gform_field_validation_{$form->id}_{$field->id}", function($result, $value, $form, $field) use ($age_limit, $competition_id) {
                            return self::validate_age_field_dynamic($result, $value, $form, $field, $age_limit, $competition_id);
                        }, 10, 4);
                        
                    }
                }
            }
        }
    }
    
    /**
     * Setup dynamic submission hooks based on competition settings
     */
    public static function setup_submission_hooks(): void {
        // Get all active competitions and their form settings
        global $wpdb;
        $competitions_table = $wpdb->prefix . 'bpm_competitions';
        
        $active_competitions = $wpdb->get_results(
            "SELECT id FROM $competitions_table WHERE status = 'active'"
        );
        
        if (empty($active_competitions)) {
            // No active competitions, no hooks to set up
            return;
        }

        foreach ($active_competitions as $competition) {
            $form_settings = get_option("bpm_form_settings_{$competition->id}", []);
            
            if (empty($form_settings)) {
                // No form settings for this competition, skip
                continue;
            }

            // Setup submission handlers for each form type
            foreach ($form_settings as $form_type => $form_config) {
                if (isset($form_config['form_id'])) {
                    $form_id = $form_config['form_id'];
                    
                    // Add submission handler for this form
                    add_action("gform_after_submission_{$form_id}", function($entry, $form) use ($competition, $form_type) {
                        self::handle_form_submission($entry, $form, (int) $competition->id, $form_type);
                    }, 10, 2);
                    
                    // Add dynamic confirmation handler for this form (high priority to override default settings)
                    add_filter("gform_confirmation_{$form_id}", function($confirmation, $form, $entry, $ajax) use ($competition, $form_type) {
                        return self::handle_dynamic_confirmation($confirmation, $form, $entry, $ajax, (int) $competition->id, $form_type);
                    }, 99, 4);
                }
            }
        }
    }
    
    /**
     * Validate code field against competition codes
     */
    public static function validate_code_field($result, $value, $form, $field): array {
        $value = strtoupper(trim($value));
        
        if (!$result['is_valid'] || empty($value)) {
            return $result;
        }
        
        $competition_id = bpm_get_active_competition_id();
        if (!$competition_id) {
            $result['is_valid'] = false;
            $result['message'] = 'No active competition found';
            return $result;
        }
        
        // Check if code exists in competition's code list
        $valid_codes = self::get_competition_codes($competition_id);
        
        if (!in_array($value, $valid_codes, true)) {
            $result['is_valid'] = false;
            $result['message'] = 'Please enter a valid code';
            
            // Log invalid code attempt
            BPM_Audit_Logger::log(
                $competition_id,
                'invalid_code_attempt',
                "Invalid code attempted: {$value}",
                'warning',
                ['code' => $value, 'form_id' => $form['id'], 'field_id' => $field->id]
            );
        }
        
        return $result;
    }
    
    public static function validate_age_field($result, $value, $form, $field): array {
        if (!$result['is_valid']) {
            return $result;
        }

        $competition_id = bpm_get_active_competition_id();
        if (!$competition_id) {
            return $result;
        }

        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $age_limit = $form_settings['age_gate']['age_limit'] ?? 16;

        if (is_array($value)) {
            $value = array_values($value);
        }
        $date_value = GFFormsModel::prepare_date($field->dateFormat, $value);
  
        $today = new DateTime();
        $diff  = $today->diff(new DateTime($date_value));
        $age   = $diff->y;
  
        if ($age < $age_limit) {
            $result['is_valid'] = false;
            $result['message']  = 'PLEASE ENTER A VALID DATE OF BIRTH';
        }
  
        return $result;
    }
    
    /**
     * Dynamic age field validation with specific age limit
     */
    public static function validate_age_field_dynamic($result, $value, $form, $field, int $age_limit, int $competition_id): array {
        
        if (!$result['is_valid']) {
            return $result;
        }

        try {
            if (is_array($value)) {
                $value = array_values($value);
            }
            
            $date_value = GFFormsModel::prepare_date($field->dateFormat, $value);
      
            $today = new DateTime();
            $birth_date = new DateTime($date_value);
            $diff = $today->diff($birth_date);
            $age = $diff->y;
            
      
            if ($age < $age_limit) {
                $result['is_valid'] = false;
                $result['message'] = "You must be at least {$age_limit} years old to participate in this promotion.";
                
                
                // Log the age validation attempt
                BPM_Audit_Logger::log(
                    $competition_id,
                    'age_validation_failed',
                    "Age validation failed: {$age} years old (minimum: {$age_limit})",
                    'warning',
                    ['age' => $age, 'age_limit' => $age_limit, 'form_id' => $form['id'], 'field_id' => $field->id, 'birth_date' => $date_value]
                );
            } else {
                
                // Log successful age validation
                BPM_Audit_Logger::log(
                    $competition_id,
                    'age_validation_passed',
                    "Age validation passed: {$age} years old (minimum: {$age_limit})",
                    'info',
                    ['age' => $age, 'age_limit' => $age_limit, 'form_id' => $form['id'], 'field_id' => $field->id, 'birth_date' => $date_value]
                );
            }
        } catch (Exception $e) {
            // If there's any error in date processing, fail validation
            $result['is_valid'] = false;
            $result['message'] = 'PLEASE ENTER A VALID DATE OF BIRTH';
            
            BPM_Audit_Logger::log(
                $competition_id,
                'age_validation_error',
                "Age validation error: {$e->getMessage()}",
                'error',
                ['error' => $e->getMessage(), 'form_id' => $form['id'], 'field_id' => $field->id]
            );
        }
  
        return $result;
    }

            /**

             * Handle form submission based on form type and competition

             */

            public static function handle_form_submission($entry, $form, int $competition_id, string $form_type): void {

                try {

                    switch ($form_type) {

                        case 'initial_entry':

                
                    self::handle_initial_entry_submission($entry, $form, $competition_id);
                    break;
                    
                case 'code_entry':
                    self::handle_code_entry_submission($entry, $form, $competition_id);
                    break;
                    
                case 'age_gate':
                    self::handle_age_gate_submission($entry, $form, $competition_id);
                    break;
                    
                case 'winner_form':
                    self::handle_winner_form_submission($entry, $form, $competition_id);
                    break;
                    
                case 'prize_draw':
                    self::handle_prize_draw_submission($entry, $form, $competition_id);
                    break;
                    
                default:
                    // Log unknown form type
                    BPM_Audit_Logger::log(
                        $competition_id,
                        'unknown_form_submission',
                        "Unknown form type: {$form_type}",
                        'warning',
                        ['form_id' => $form['id'], 'form_type' => $form_type]
                    );
            }
        } catch (Exception $e) {
            // Log submission error
            BPM_Audit_Logger::log(
                $competition_id,
                'form_submission_error',
                "Form submission error: {$e->getMessage()}",
                'error',
                [
                    'form_id' => $form['id'],
                    'form_type' => $form_type,
                    'entry_id' => $entry['id'],
                    'error' => $e->getMessage()
                ]
            );
        }
    }
    
    /**
    * Handle initial entry form submission
    */
    
    private static function handle_initial_entry_submission($entry, $form, int $competition_id): void {
    
            // Start session if not already started
            if (!session_id()) {
                session_start();
            }
    
        // Store entry data in session
        $_SESSION['britvic_entry_data'] = $entry;
        
        // Get client IP address
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Store user data for form pre-population later
        $_SESSION['britvic_user_data'] = [
            'initial_entry_name' => '',
            'initial_entry_email' => '',
            'ip' => $ip,
            'entry_date' => $entry['date_created']
        ];

        // Get form mapping for this competition
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $mapping = $form_settings['initial_entry']['field_mapping'] ?? [];
        
        // Extract user data based on dynamic field mapping
        $user_data = [];
        foreach ($mapping as $field_type => $field_id) {
            if (isset($entry[$field_id])) {
                $user_data[$field_type] = $entry[$field_id];
            }
        }
        
        // Store uploaded file URL if file field is mapped
        if (isset($mapping['file_field']) && !empty($entry[$mapping['file_field']])) {
            $_SESSION['britvic_file_url'] = $entry[$mapping['file_field']];
        }
        
        // Dynamically collect user details using field detector
        $email = '';
        $name = '';
        
        // Use dynamic field detection if available, otherwise fallback to mapping
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $email = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $form['id']) ?: '';
            $name = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'name_field', (int) $form['id']) ?: '';
        } else {
            // Fallback to legacy mapping
            if (isset($mapping['email_field']) && !empty($entry[$mapping['email_field']])) {
                $email = $entry[$mapping['email_field']];
            }
            if (isset($mapping['name_field']) && !empty($entry[$mapping['name_field']])) {
                $name = $entry[$mapping['name_field']];
            }
        }
        
        
        // Update session with extracted user data
        $_SESSION['britvic_user_data']['initial_entry_name'] = $name;
        $_SESSION['britvic_user_data']['initial_entry_email'] = $email;
        
        // Check user eligibility using the BPM eligibility system
        $eligibility = bpm_check_user_eligibility($email, $name, $ip);
        
        if (!$eligibility['can_play']) {
            // User cannot even play - set error result
            $error_result = [
                'prize' => 'error',
                'message' => $eligibility['reason']
            ];
            $_SESSION['britvic_prize'] = $error_result;
            
            // Log the error
            BPM_Audit_Logger::log(
                $competition_id,
                'initial_entry_error',
                "Initial entry error: " . $eligibility['reason'],
                'error',
                [
                    'entry_id' => $entry['id'],
                    'email' => $email,
                    'name' => $name,
                    'ip' => $ip,
                    'reason' => $eligibility['reason'],
                    'mapped_fields' => $mapping
                ]
            );
            return;
        }
        
        if ($eligibility['blocked'] && $eligibility['can_play']) {
            // Check if a winning prize has already been assigned in this session
            // If so, don't override with loser result (prevents false positive from prize claiming)
            if (isset($_SESSION['britvic_prize']) && 
                $_SESSION['britvic_prize']['prize'] === 'winner') {
                return; // Don't override the winning result
            }
            
            // User can play but will always lose (already won or blacklisted)
            $losing_result = [
                'prize' => 'loser',
                'reason' => $eligibility['reason']
            ];
            $_SESSION['britvic_prize'] = $losing_result;
            
            // Log the blocking action
            BPM_Audit_Logger::log(
                $competition_id,
                'initial_entry_blocked',
                "Initial entry blocked: " . $eligibility['reason'],
                'warning',
                [
                    'entry_id' => $entry['id'],
                    'email' => $email,
                    'name' => $name,
                    'ip' => $ip,
                    'reason' => $eligibility['reason'],
                    'mapped_fields' => $mapping
                ]
            );
        } else {
            // User is eligible - run the prize logic
            $result = bpm_handle_prize_result($email, $name, $ip);
            
            // The prize function now handles session setting internally, 
            // but we'll set it here as backup to ensure it's set
            if (isset($result['prize'])) {
                $_SESSION['britvic_prize'] = $result;
            } else {
                // Set losing result if prize handling returns error
                $losing_result = [
                    'prize' => 'loser'
                ];
                $_SESSION['britvic_prize'] = $losing_result;
            }
        }
        
        // Log the entry with prize result
        BPM_Audit_Logger::log(
            $competition_id,
            'initial_entry_submitted',
            "Initial entry submitted by {$email} - Prize: " . ($_SESSION['britvic_prize']['prize'] ?? 'unknown'),
            'info',
            [
                'entry_id' => $entry['id'],
                'email' => $email,
                'name' => $name,
                'ip' => $ip,
                'prize_result' => $_SESSION['britvic_prize'] ?? null,
                'mapped_fields' => $mapping,
                'form_id' => $form['id']
            ]
        );
    }
    
    /**
     * Handle age gate form submission
     */
    private static function handle_age_gate_submission($entry, $form, int $competition_id): void {
        // Start session if not already started
        if (!session_id()) {
            session_start();
        }
        
        // Get form mapping
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $mapping = $form_settings['age_gate']['field_mapping'] ?? [];
        
        // Extract DOB from form entry using dynamic detection
        $dob = '';
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $dob_value = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'dob_field', (int) $form['id']);
            if ($dob_value) {
                // Convert to standard format if needed
                $dob = is_array($dob_value) ? implode('/', $dob_value) : $dob_value;
            }
        } else {
            // Fallback to legacy mapping
            if (isset($mapping['dob_field']) && !empty($entry[$mapping['dob_field']])) {
                $dob = $entry[$mapping['dob_field']];
            }
        }
        
        // Store age gate data in session
        $_SESSION['britvic_age_gate'] = [
            'dob' => $dob,
            'submission_date' => $entry['date_created'],
            'form_id' => $form['id'],
            'entry_id' => $entry['id']
        ];
        
        
        // Log age gate submission
        BPM_Audit_Logger::log(
            $competition_id,
            'age_gate_submitted',
            "Age gate form submitted with DOB: {$dob}",
            'info',
            ['entry_id' => $entry['id'], 'form_id' => $form['id'], 'dob' => $dob]
        );

        // If this age gate form also contains name/email (single-form two-step flow),
        // run initial entry logic on the same submission.
        $name = '';
        $email = '';
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $email = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $form['id']) ?: '';
            $name = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'name_field', (int) $form['id']) ?: '';
        }

        if ($name !== '' && $email !== '') {
            self::handle_initial_entry_submission($entry, $form, $competition_id);
        }
    }

    /**
     * Handle code entry form submission
     */
    private static function handle_code_entry_submission($entry, $form, int $competition_id): void {
        // Set session data for game flow
                    
        $_SESSION['britvic_entry_data'] = 'entered';
        
        // Log code entry
        BPM_Audit_Logger::log(
            $competition_id,
            'code_entry_submitted',
            "Code entry form submitted",
            'info',
            ['entry_id' => $entry['id'], 'form_id' => $form['id']]
        );
    }
    
    /**
     * Handle winner form submission (when user claims prize)
     */
    private static function handle_winner_form_submission($entry, $form, int $competition_id): void {
        // Get prize information from session
        $prize_data = $_SESSION['britvic_prize'] ?? null;
        
        if (!$prize_data) {
            // Try to get prize data from database using entry data
            if (class_exists('BPM_Dynamic_Field_Detector')) {
                $winner_email = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $form['id']) ?: '';
            } else {
                // Fallback to legacy mapping
                $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
                $mapping = $form_settings['winner_form']['field_mapping'] ?? [];
                $winner_email = rgar($entry, $mapping['email_field'] ?? '3');
            }
            
            if ($winner_email) {
                // Try to find recent prize entry for this email
                global $wpdb;
                $prize_entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}bmp_prize_entries 
                     WHERE claimed_by_email = %s 
                     ORDER BY claimed_at DESC LIMIT 1",
                    $winner_email
                ));
                
                if ($prize_entry) {
                    // Reconstruct prize data from database
                    $prize_data = [
                        'prize' => $prize_entry->prize_type,
                        'code' => $prize_entry->code,
                        'post_id' => $prize_entry->prize_post_id,
                        'mapped_data' => $prize_entry->original_data ? json_decode($prize_entry->original_data, true) : []
                    ];
                } else {
                    return;
                }
            } else {
                return;
            }
        }
        
        // Get form mapping
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $mapping = $form_settings['winner_form']['field_mapping'] ?? [];
        
        // Extract winner information from form entry using dynamic detection
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $winner_email = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $form['id']) ?: '';
            $winner_name = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'name_field', (int) $form['id']) ?: '';
        } else {
            // Fallback to legacy mapping
            $winner_email = rgar($entry, $mapping['email_field'] ?? '3');
            $winner_name = rgar($entry, $mapping['name_field'] ?? '2');
        }
        $winner_user_id = get_current_user_id() ?: null;
        
        // Mark prize as claimed in database and post meta
        self::mark_prize_as_claimed($prize_data, $winner_email, $winner_name, $winner_user_id, $entry['id']);
        
        // Update entry with prize data
        $updates = [];
        if (isset($mapping['prize_field']) && $mapping['prize_field']) {
            $updates[$mapping['prize_field']] = $prize_data['prize'];
        }
        if (isset($mapping['code_field']) && $mapping['code_field']) {
            $updates[$mapping['code_field']] = $prize_data['code'] ?? $prize_data['prize_code'] ?? '';
        }
        if (isset($mapping['file_field']) && $mapping['file_field'] && isset($_SESSION['britvic_file_url'])) {
            $updates[$mapping['file_field']] = $_SESSION['britvic_file_url'];
        }
        
        // Update the entry
        foreach ($updates as $field_id => $value) {
            $entry[$field_id] = $value;
        }
        
        if (!empty($updates)) {
            GFAPI::update_entry($entry);
        }
        
        // Send prize email if configured
        self::send_prize_email($entry, $prize_data, $competition_id);
        
        // Clear session
        self::clear_game_session();
        
        // Log prize claim
        BPM_Audit_Logger::log(
            $competition_id,
            'prize_claimed_via_form',
            "Prize claimed via form: {$prize_data['prize']} by {$winner_email}",
            'info',
            [
                'entry_id' => $entry['id'],
                'prize' => $prize_data['prize'],
                'code' => $prize_data['code'] ?? $prize_data['prize_code'] ?? null,
                'email' => $winner_email,
                'name' => $winner_name,
                'prize_post_id' => $prize_data['post_id'] ?? $prize_data['prize_id'] ?? null
            ]
        );
    }
    
    /**
     * Handle prize draw form submission
     */
    private static function handle_prize_draw_submission($entry, $form, int $competition_id): void {
        // Get form mapping
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        $mapping = $form_settings['prize_draw']['field_mapping'] ?? [];
        
        // Extract user data using dynamic detection
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $email = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $form['id']) ?: '';
            $name = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'name_field', (int) $form['id']) ?: '';
        } else {
            // Fallback to legacy mapping
            $email = rgar($entry, $mapping['email_field'] ?? '');
            $name = rgar($entry, $mapping['name_field'] ?? '');
        }
        
        // Log prize draw entry
        BPM_Audit_Logger::log(
            $competition_id,
            'prize_draw_entry_submitted',
            "Prize draw entry submitted by {$email}",
            'info',
            [
                'entry_id' => $entry['id'],
                'email' => $email,
                'name' => $name,
                'form_id' => $form['id']
            ]
        );
    }
    
    /**
     * Send prize email based on templates and form-specific settings
     */
    private static function send_prize_email($entry, $prize_data, int $competition_id): void {
        // Check if automatic email sending is enabled for this competition
        $email_sending_enabled = get_option("bpm_email_sending_enabled_{$competition_id}", false);
        if (!$email_sending_enabled) {
            BPM_Audit_Logger::log(
                $competition_id,
                'email_sending_disabled',
                'Email sending is disabled for this competition',
                'info',
                ['entry_id' => $entry['id']]
            );
            return;
        }
        
        $form_id = (int) $entry['form_id'];
        $form_type = self::get_form_type_by_form_id($competition_id, $form_id);
        
        if (!$form_type) {
            BPM_Audit_Logger::log(
                $competition_id,
                'form_type_not_found',
                "Form type not found for form ID {$form_id}",
                'warning',
                ['form_id' => $form_id, 'entry_id' => $entry['id']]
            );
            return;
        }
        
        // Get form template assignments
        $form_template_assignments = get_option("bpm_form_template_assignments_{$competition_id}", []);
        
        // Check if email is enabled for this specific form type
        if (isset($form_template_assignments[$form_type]) && 
            !$form_template_assignments[$form_type]['email_enabled']) {
            BPM_Audit_Logger::log(
                $competition_id,
                'form_email_disabled',
                "Email sending disabled for form type {$form_type}",
                'info',
                ['form_type' => $form_type, 'form_id' => $form_id, 'entry_id' => $entry['id']]
            );
            return;
        }
        
        $templates = get_option("bpm_email_templates_{$competition_id}", []);
        $matched_template = null;
        
        // Only use form-specific template - no fallback to prize-based matching
        $matched_template = null;
        
        if (isset($form_template_assignments[$form_type]) && 
            isset($form_template_assignments[$form_type]['template_index']) &&
            $form_template_assignments[$form_type]['template_index'] !== null) {
            
            $template_index = $form_template_assignments[$form_type]['template_index'];
            if (isset($templates[$template_index])) {
                $matched_template = $templates[$template_index];
                
                BPM_Audit_Logger::log(
                    $competition_id,
                    'form_template_selected',
                    "Using form-specific template for {$form_type}",
                    'info',
                    ['form_type' => $form_type, 'template_index' => $template_index, 'entry_id' => $entry['id']]
                );
            } else {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'template_not_found',
                    "Template index {$template_index} not found for {$form_type}",
                    'error',
                    ['form_type' => $form_type, 'template_index' => $template_index, 'entry_id' => $entry['id']]
                );
                return;
            }
        } else {
            BPM_Audit_Logger::log(
                $competition_id,
                'no_template_assigned',
                "No email template assigned for form type {$form_type}. Email not sent.",
                'error',
                ['form_type' => $form_type, 'form_id' => $form_id, 'entry_id' => $entry['id'], 'prize' => $prize_data['prize']]
            );
            return;
        }
        
        // Get email using dynamic detection
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $to = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $entry['form_id']) ?: '';
        } else {
            // Fallback to legacy mapping
            $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
            $mapping = $form_settings['winner_form']['field_mapping'] ?? [];
            $email_field = $mapping['email_field'] ?? '3';
            $to = rgar($entry, $email_field);
        }
        if (!$to) {
            return;
        }
        
        
        // Prepare dynamic placeholders based on prize data
        $placeholders = [
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => get_bloginfo('url')
        ];
        
        // Add all prize meta data as placeholders
        foreach ($prize_data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $placeholders['{' . $key . '}'] = $value;
            }
        }
        
        // Add mapped data placeholders if available
        if (isset($prize_data['mapped_data']) && is_array($prize_data['mapped_data'])) {
            
            foreach ($prize_data['mapped_data'] as $key => $value) {
                // Include all fields for maximum flexibility
                $placeholders['{' . $key . '}'] = $value;
            }
        } else {
        }
        
        
        // Add form entry data placeholders using dynamic detection
        if (class_exists('BPM_Dynamic_Field_Detector')) {
            $placeholders['{winner_name}'] = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'name_field', (int) $entry['form_id']) ?: '';
            $placeholders['{winner_email}'] = BPM_Dynamic_Field_Detector::get_field_value_dynamic($entry, 'email_field', (int) $entry['form_id']) ?: '';
        } else {
            // Fallback to legacy mapping (need to ensure $mapping is available)
            $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
            $mapping = $form_settings['winner_form']['field_mapping'] ?? [];
            $placeholders['{winner_name}'] = rgar($entry, $mapping['name_field'] ?? '2');
            $placeholders['{winner_email}'] = rgar($entry, $mapping['email_field'] ?? '1');
        }
        $placeholders['{entry_date}'] = date('d/m/Y H:i', strtotime($entry['date_created']));
        $placeholders['{competition_name}'] = $competition->name ?? 'Competition';
        
        // Replace placeholders
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $matched_template['email_subject']);
        $message = str_replace(array_keys($placeholders), array_values($placeholders), $matched_template['email_template']);
        
        // Check if recipient is on the competition block list
        $is_blocked = self::is_email_blocked($competition_id, $to);
        if ($is_blocked) {
            
            // Log blocked email attempt
            self::log_email_attempt($competition_id, $to, $placeholders['{winner_name}'] ?? '', 
                                  $subject, $message, $prize_data, false, 'Email address is blocked');
            return;
        }
        
        // Send email
        add_filter('wp_mail_content_type', function() { return 'text/html'; });
        $sent = wp_mail($to, $subject, $message);
        remove_filter('wp_mail_content_type', 'wp_mail_content_type');
        
        // Log email attempt (success or failure)
        $status = $sent ? 'sent' : 'failed';
        $error_message = $sent ? null : 'wp_mail function returned false';
        
        self::log_email_attempt($competition_id, $to, $placeholders['{winner_name}'] ?? '', 
                              $subject, $message, $prize_data, $sent, $error_message);
        
        if ($sent) {
            BPM_Audit_Logger::log(
                $competition_id,
                'prize_email_sent',
                "Prize email sent to {$to}",
                'info',
                ['email' => $to, 'prize' => $prize_data['prize'], 'winner_name' => $placeholders['{winner_name}'] ?? '']
            );
        } else {
            BPM_Audit_Logger::log(
                $competition_id,
                'prize_email_failed',
                "Prize email failed to send to {$to}",
                'error',
                ['email' => $to, 'prize' => $prize_data['prize'], 'winner_name' => $placeholders['{winner_name}'] ?? '']
            );
        }
    }
    
    /**
     * Get valid codes for a competition
     */
    private static function get_competition_codes(int $competition_id): array {
        // Check if codes are stored in the plugin
        $codes = get_option("bpm_competition_codes_{$competition_id}", []);
        
        if (empty($codes)) {
            // Fallback to theme file for backward compatibility
            $codes_file = get_template_directory() . '/includes/codes/barcodes.txt';
            if (file_exists($codes_file)) {
                $codes_content = file_get_contents($codes_file);
                $codes = array_filter(array_map('trim', explode("\n", $codes_content)));
            }
        }
        
        return array_map('strtoupper', $codes);
    }
    
    /**
     * Check if user is blocked based on IP, email, or name
     */
    private static function check_user_blocked(int $competition_id, string $email, string $name, string $ip): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bpm_user_blocks';
        
        // Check IP blocks
        if (!empty($ip)) {
            $ip_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE competition_id = %d AND block_type = 'ip' AND block_value = %s",
                $competition_id,
                $ip
            ));
            
            if ($ip_blocked) {
                return true;
            }
        }
        
        // Check email blocks (case insensitive)
        if (!empty($email)) {
            $email_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE competition_id = %d AND block_type = 'email' AND LOWER(block_value) = LOWER(%s)",
                $competition_id,
                $email
            ));
            
            if ($email_blocked) {
                return true;
            }
        }
        
        // Check name blocks (case insensitive, exact match)
        if (!empty($name)) {
            $name_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE competition_id = %d AND block_type = 'name' AND LOWER(block_value) = LOWER(%s)",
                $competition_id,
                $name
            ));
            
            if ($name_blocked) {
                return true;
            }
        }
        
        
        return false;
    }
    
    /**
     * Mark a prize as claimed in both database and post meta
     */
    private static function mark_prize_as_claimed($prize_data, $winner_email, $winner_name, $winner_user_id, $gravity_form_entry_id): bool {
        global $wpdb;
        
        // Get prize post ID from prize data - handle different key formats
        $prize_post_id = $prize_data['post_id'] ?? $prize_data['prize_id'] ?? null;
        $code = $prize_data['code'] ?? $prize_data['prize_code'] ?? null;
        
        if (!$prize_post_id && $code) {
            // Check for duplicate codes first
            $duplicate_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s",
                $code
            ));
            
            
            // Try to find unclaimed prize post by code
            $prize_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT prize_post_id FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s AND claimed = 0 LIMIT 1",
                $code
            ));
            
            // If no unclaimed prize found, get any prize with this code (might be already claimed)
            if (!$prize_post_id) {
                $prize_post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT prize_post_id FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s LIMIT 1",
                    $code
                ));
            }
        }
        
        if (!$prize_post_id) {
            return false;
        }
        
        // Prepare winner data
        $winner_data = json_encode([
            'email' => $winner_email,
            'name' => $winner_name,
            'claimed_at' => current_time('mysql'),
            'gravity_form_entry_id' => $gravity_form_entry_id
        ]);
        
        // Update ALL prize entries with this code (handles duplicates)
        $result = $wpdb->update(
            $wpdb->prefix . 'bpm_prize_entries',
            [
                'claimed' => 1,
                'claimed_at' => current_time('mysql'),
                'claimed_by_email' => $winner_email,
                'claimed_by_name' => $winner_name,
                'claimed_by_user_id' => $winner_user_id,
                'gravity_form_entry_id' => $gravity_form_entry_id,
                'winner_data' => $winner_data,
                'updated_at' => current_time('mysql')
            ],
            ['code' => $code],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'],
            ['%s']
        );
        
        // Also update all post meta for prizes with this code
        if ($code) {
            $all_prize_posts = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT prize_post_id FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s",
                $code
            ));
            
            foreach ($all_prize_posts as $post_id) {
                update_post_meta($post_id, 'claimed', 'true');
                update_post_meta($post_id, 'claimed_by_email', $winner_email);
                update_post_meta($post_id, 'claimed_by_name', $winner_name);
                update_post_meta($post_id, 'claimed_at', current_time('mysql'));
                update_post_meta($post_id, 'gravity_form_entry_id', $gravity_form_entry_id);
            }
            
        }
        
        if ($result === false) {
            return false;
        }
        
        
        return true;
    }
    
    /**
     * Check if email is blocked for this competition
     */
    private static function is_email_blocked(int $competition_id, string $email): bool {
        global $wpdb;
        
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bpm_user_blocks 
             WHERE competition_id = %d AND block_type = 'email' 
             AND LOWER(block_value) = LOWER(%s)",
            $competition_id,
            $email
        ));
        
        return !empty($blocked);
    }
    
    /**
     * Log email attempt to database
     */
    private static function log_email_attempt(int $competition_id, string $to_email, string $to_name, 
                                            string $subject, string $message, array $prize_data, 
                                            bool $sent, ?string $error_message): void {
        global $wpdb;
        
        // Ensure email log table exists
        $email_log_table = $wpdb->prefix . 'bpm_email_log';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $email_log_table
        ));
        
        if (!$table_exists) {
            // Try to create the table
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $email_log_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                competition_id mediumint(9) NOT NULL,
                recipient_email varchar(255) NOT NULL,
                winner_name varchar(255) DEFAULT NULL,
                subject varchar(500) NOT NULL,
                prize_type varchar(255) DEFAULT NULL,
                prize_codes text DEFAULT NULL,
                template_used varchar(255) DEFAULT NULL,
                status enum('sent','failed') NOT NULL DEFAULT 'sent',
                error_message text DEFAULT NULL,
                sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_competition (competition_id),
                INDEX idx_recipient (recipient_email),
                INDEX idx_status (status),
                INDEX idx_sent_at (sent_at),
                INDEX idx_competition_status (competition_id, status)
            ) $charset_collate;";
            
            dbDelta($sql);
        }
        
        // Extract relevant prize information
        $prize_codes = '';
        if (isset($prize_data['mapped_data'])) {
            $codes = [];
            if (isset($prize_data['mapped_data']['code_1'])) {
                $codes[] = $prize_data['mapped_data']['code_1'];
            }
            if (isset($prize_data['mapped_data']['code_2'])) {
                $codes[] = $prize_data['mapped_data']['code_2'];
            }
            $prize_codes = implode(', ', $codes);
        }
        
        $result = $wpdb->insert(
            $email_log_table,
            [
                'competition_id' => $competition_id,
                'recipient_email' => $to_email,
                'winner_name' => $to_name,
                'subject' => $subject,
                'prize_type' => $prize_data['prize_name'] ?? $prize_data['prize'] ?? '',
                'prize_codes' => $prize_codes,
                'template_used' => 'Dynamic Template', // We could track which template was used
                'status' => $sent ? 'sent' : 'failed',
                'error_message' => $error_message
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        // Log any database insertion errors
        if ($result === false) {
            error_log('BPM: Failed to log email attempt to database: ' . $wpdb->last_error);
        }
        
    }
    
    /**
     * Get form type by form ID for a specific competition
     */
    private static function get_form_type_by_form_id(int $competition_id, int $form_id): ?string {
        $form_settings = get_option("bpm_form_settings_{$competition_id}", []);
        
        foreach ($form_settings as $form_type => $settings) {
            if (isset($settings['form_id']) && (int) $settings['form_id'] === $form_id) {
                return $form_type;
            }
        }
        
        return null;
    }
    
    /**
     * Clear game session variables
     */
    private static function clear_game_session(): void {
        unset($_SESSION['britvic_entry_data']);
        unset($_SESSION['britvic_prize']);
        unset($_SESSION['britvic_file_url']);
        
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * AJAX handler to get form fields for mapping
     */
    public static function handle_ajax_get_form_fields(): void {
        check_ajax_referer('bpm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $form_id = (int) ($_POST['form_id'] ?? 0);
        
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
            return;
        }
        
        $form = GFAPI::get_form($form_id);
        if (!$form) {
            wp_send_json_error('Form not found');
            return;
        }
        
        $fields = [];
        foreach ($form['fields'] as $field) {
            $fields[] = [
                'id' => $field->id,
                'label' => $field->label,
                'type' => $field->type
            ];
        }
        
        wp_send_json_success(['fields' => $fields]);
    }
    
    /**
     * AJAX handler to save form mappings
     */
    public static function handle_ajax_save_form_mappings(): void {
        check_ajax_referer('bpm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $competition_id = (int) ($_POST['competition_id'] ?? 0);
        $form_settings = json_decode(wp_unslash($_POST['form_settings'] ?? ''), true);
        
        if (!$competition_id || !is_array($form_settings)) {
            wp_send_json_error('Invalid data');
            return;
        }
        
        // Save form settings
        update_option("bpm_form_settings_{$competition_id}", $form_settings);
        
        // Re-initialize hooks with new settings
        self::setup_validation_hooks();
        self::setup_submission_hooks();
        
        wp_send_json_success('Form mappings saved successfully');
    }
    
    /**
     * Handle dynamic confirmation/redirect based on theme settings
     */
    public static function handle_dynamic_confirmation($confirmation, $form, $entry, $ajax, $competition_id, $form_type) {
        
        // Only handle specific form types with dynamic redirects
        if ($form_type !== 'initial_entry' && $form_type !== 'age_gate' && $form_type !== 'winner_form') {
            return $confirmation;
        }
        
        // Get theme-specific redirect URL
        $redirect_url = null;
        
        if ($form_type === 'initial_entry' || $form_type === 'age_gate') {
            // After initial entry, redirect to reveal/result page
            $redirect_url = bmp_get_theme_page_url('reveal', $competition_id);
        } elseif ($form_type === 'winner_form') {
            // After winner claim, redirect to thank you page
            $redirect_url = bmp_get_theme_page_url('claim_complete', $competition_id);
        }
        
        
        if ($redirect_url) {
            // Ensure session is properly saved before redirect
            if (session_id()) {
                // Force session write without closing it completely
                session_write_close();
                // Restart session to keep it available for immediate operations
                session_start();
            }
            
            
            // Override confirmation with redirect
            $confirmation = [
                'redirect' => $redirect_url
            ];
        }
        
        return $confirmation;
    }
}

// Initialize the form integration
BPM_Form_Integration::init();
