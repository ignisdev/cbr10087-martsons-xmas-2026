<?php
/**
 * Theme Manager Class
 *
 * Handles theme registration, validation, and competition mapping
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
 * Theme management and registration
 */
class BPM_Theme_Manager {
    
    /**
     * Registered themes
     */
    private static array $registered_themes = [];
    
    /**
     * Register a theme with the plugin
     *
     * @param string $theme_id Unique theme identifier
     * @param array $theme_config Theme configuration
     * @return bool Success status
     */
    public static function register_theme(string $theme_id, array $theme_config): bool {
        // Validate required configuration (description is optional)
        $required_fields = ['name', 'version', 'template_path'];
        foreach ($required_fields as $field) {
            if (empty($theme_config[$field])) {
                return false;
            }
        }
        
        // Validate template path exists
        if (!file_exists(get_template_directory() . '/' . $theme_config['template_path'])) {
            return false;
        }
        
        // Set default values
        $theme_config = array_merge([
            'supports' => ['instant_win'], // Default support
            'requires_forms' => [1, 2], // Default required forms
            'pages' => [
                'game' => 'template/game.php',
                'winner' => 'template/winner.php', 
                'loser' => 'template/non-winner.php'
            ],
            'active' => true
        ], $theme_config);
        
        // Ensure we have the latest themes from database before updating
        self::$registered_themes = get_option('bpm_registered_themes', []);
        
        // Set the new theme configuration  
        self::$registered_themes[$theme_id] = $theme_config;
        
        // Store in database for persistence
        update_option('bpm_registered_themes', self::$registered_themes);
        
        // Log registration
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                0,
                'theme_registered',
                sprintf('Theme registered: %s (%s)', $theme_config['name'], $theme_id),
                'info',
                ['theme_id' => $theme_id, 'config' => $theme_config]
            );
        }
        
        return true;
    }
    
    /**
     * Get all registered themes
     *
     * @return array Array of registered themes
     */
    public static function get_registered_themes(): array {
        if (empty(self::$registered_themes)) {
            self::$registered_themes = get_option('bpm_registered_themes', []);
        }
        
        return self::$registered_themes;
    }
    
    /**
     * Get specific theme configuration
     *
     * @param string $theme_id Theme identifier
     * @return array|null Theme configuration or null if not found
     */
    public static function get_theme(string $theme_id): ?array {
        $themes = self::get_registered_themes();
        return $themes[$theme_id] ?? null;
    }
    
    /**
     * Check if theme is compatible with competition type
     *
     * @param string $theme_id Theme identifier
     * @param string $competition_type Competition type (instant_win, prize_draw, etc.)
     * @return bool Compatibility status
     */
    public static function is_theme_compatible(string $theme_id, string $competition_type): bool {
        $theme = self::get_theme($theme_id);
        if (!$theme) {
            return false;
        }
        
        return in_array($competition_type, $theme['supports'] ?? [], true);
    }
    
    /**
     * Set theme for a competition
     *
     * @param int $competition_id Competition ID
     * @param string $theme_id Theme identifier
     * @return bool Success status
     */
    public static function set_competition_theme(int $competition_id, string $theme_id): bool {
        // Validate theme exists
        $theme = self::get_theme($theme_id);
        if (!$theme) {
            return false;
        }
        
        // Get competition type for compatibility check
        global $wpdb;
        $competition_table = $wpdb->prefix . 'bpm_competitions';
        $competition = $wpdb->get_row($wpdb->prepare(
            "SELECT type FROM $competition_table WHERE id = %d",
            $competition_id
        ));
        
        if (!$competition) {
            return false;
        }
        
        // Check compatibility
        if (!self::is_theme_compatible($theme_id, $competition->type)) {
            return false;
        }
        
        // Store mapping
        update_option("bpm_competition_theme_{$competition_id}", $theme_id);
        
        // Log assignment
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                $competition_id,
                'theme_assigned',
                sprintf('Theme %s assigned to competition %d', $theme_id, $competition_id),
                'info',
                ['theme_id' => $theme_id, 'competition_id' => $competition_id]
            );
        }
        
        return true;
    }
    
    /**
     * Get theme assigned to a competition
     *
     * @param int $competition_id Competition ID
     * @return string|null Theme ID or null if not set
     */
    public static function get_competition_theme(int $competition_id): ?string {
        return get_option("bpm_competition_theme_{$competition_id}") ?: null;
    }
    
    /**
     * Get active competition based on current theme context
     *
     * @return int|null Active competition ID or null
     */
    public static function get_active_competition_for_current_theme(): ?int {
        // Detect current theme context
        $current_theme_id = self::detect_current_theme_context();
        
        if (!$current_theme_id) {
            return null;
        }
        
        // Find active competition using this theme
        global $wpdb;
        $competitions_table = $wpdb->prefix . 'bpm_competitions';
        $now = current_time('mysql', true);
        
        $active_competitions = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $competitions_table 
             WHERE status = 'active' 
             AND (start_date IS NULL OR start_date <= %s)
             AND (end_date IS NULL OR end_date >= %s)
             ORDER BY id DESC",
            $now,
            $now
        ));
        
        foreach ($active_competitions as $competition) {
            $theme_id = self::get_competition_theme((int) $competition->id);
            if ($theme_id === $current_theme_id) {
                return (int) $competition->id;
            }
        }
        
        return null;
    }
    
    /**
     * Detect current theme context based on page template
     *
     * @return string|null Current theme ID or null
     */
    private static function detect_current_theme_context(): ?string {
        // Get current theme ID dynamically
        $current_theme_id = self::get_current_theme_id();
        
        if (is_page_template('page-age-gate.php')) {
            return $current_theme_id;
        }

        // Check if we're on a game page
        if (is_page_template('template/game.php')) {
            return $current_theme_id;
        }
        
        // Check for other template patterns
        $page_template = get_page_template_slug();
        
        // Get all registered themes and check if current template belongs to any
        $registered_themes = self::get_registered_themes();
        
        foreach ($registered_themes as $theme_id => $theme_config) {
            if (isset($theme_config['pages'])) {
                foreach ($theme_config['pages'] as $page_type => $template_path) {
                    if ($page_template === $template_path) {
                        return $theme_id;
                    }
                }
            }
        }
        
        // Fallback to static mapping for non-registered themes
        $template_mapping = [
            'template/game.php' => $current_theme_id,
            'template/scratch.php' => 'scratch_card',
            'template/slots.php' => 'slot_machine',
        ];
        
        return $template_mapping[$page_template] ?? null;
    }
    
    /**
     * Get current theme ID based on WordPress theme
     */
    private static function get_current_theme_id(): string {
        $theme_data = wp_get_theme();
        $theme_name = $theme_data->get('Name') ?: 'Unknown Theme';
        return sanitize_key(strtolower(str_replace([' ', '-'], '_', $theme_name)));
    }
    
    /**
     * Validate all theme requirements are met
     *
     * @param string $theme_id Theme identifier
     * @return array Validation results
     */
    public static function validate_theme(string $theme_id): array {
        $theme = self::get_theme($theme_id);
        $errors = [];
        $warnings = [];
        
        if (!$theme) {
            return [
                'valid' => false,
                'errors' => ['Theme not found'],
                'warnings' => []
            ];
        }
        
        // Check primary template file exists
        $primary_template = get_template_directory() . '/' . $theme['template_path'];
        if (!file_exists($primary_template)) {
            $errors[] = "Primary template file missing: {$theme['template_path']}";
        }
        
        // Check all page template files exist
        foreach ($theme['pages'] as $page_type => $template_path) {
            $full_path = get_template_directory() . '/' . $template_path;
            if (!file_exists($full_path)) {
                $errors[] = "Missing {$page_type} template file: {$template_path}";
            }
        }
        
        // Check required forms exist (if Gravity Forms active)
        if (class_exists('GFAPI')) {
            foreach ($theme['requires_forms'] as $form_id) {
                $form = GFAPI::get_form($form_id);
                if (!$form) {
                    $warnings[] = "Required form {$form_id} not found";
                }
            }
        }
        
        // Check theme functions exist
        if (isset($theme['required_functions'])) {
            foreach ($theme['required_functions'] as $function_name) {
                if (!function_exists($function_name)) {
                    $errors[] = "Required function missing: {$function_name}";
                }
            }
        }
        
        // Check theme assets exist (if specified)
        if (isset($theme['assets'])) {
            $theme_dir = get_template_directory();
            
            if (isset($theme['assets']['css'])) {
                foreach ($theme['assets']['css'] as $css_file) {
                    $css_path = $theme_dir . '/' . $css_file;
                    if (!file_exists($css_path)) {
                        $warnings[] = "CSS asset not found: {$css_file}";
                    }
                }
            }
            
            if (isset($theme['assets']['js'])) {
                foreach ($theme['assets']['js'] as $js_file) {
                    $js_path = $theme_dir . '/' . $js_file;
                    if (!file_exists($js_path)) {
                        $warnings[] = "JavaScript asset not found: {$js_file}";
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Get themes available for a competition type
     *
     * @param string $competition_type Competition type
     * @return array Available themes
     */
    public static function get_themes_for_competition_type(string $competition_type): array {
        $themes = self::get_registered_themes();
        $compatible_themes = [];
        
        foreach ($themes as $theme_id => $theme_config) {
            if (self::is_theme_compatible($theme_id, $competition_type)) {
                $compatible_themes[$theme_id] = $theme_config;
            }
        }
        
        return $compatible_themes;
    }
    
    /**
     * Update redirect settings for a specific theme
     *
     * @param string $theme_id Theme identifier
     * @param string $after_entry URL to redirect after entry form
     * @param string $after_claim URL to redirect after claim form
     * @return bool Success status
     */
    public static function update_theme_redirect_settings(string $theme_id, string $after_entry, string $after_claim): bool {
        // Get current registered themes
        $registered_themes = get_option('bpm_registered_themes', []);
        
        // Check if theme exists
        if (!isset($registered_themes[$theme_id])) {
            return false;
        }
        
        // Update the redirect settings
        $registered_themes[$theme_id]['redirect_settings'] = [
            'after_entry' => $after_entry,
            'after_claim' => $after_claim
        ];
        
        // Save back to database
        $result = update_option('bpm_registered_themes', $registered_themes);
        
        // Update the in-memory cache as well
        self::$registered_themes = $registered_themes;
        
        // Log the change
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                0,
                'theme_redirect_settings_updated',
                sprintf('Theme redirect settings updated for %s: after_entry=%s, after_claim=%s', $theme_id, $after_entry, $after_claim),
                'info',
                ['theme_id' => $theme_id, 'after_entry' => $after_entry, 'after_claim' => $after_claim]
            );
        }
        
        return $result;
    }

    /**
     * Delete a registered theme
     *
     * @param string $theme_id Theme identifier
     * @return bool Success status
     */
    public static function delete_theme(string $theme_id): bool {
        // Get current registered themes
        $registered_themes = get_option('bpm_registered_themes', []);
        
        // Check if theme exists
        if (!isset($registered_themes[$theme_id])) {
            return false;
        }
        
        // Remove the theme
        unset($registered_themes[$theme_id]);
        
        // Save back to database
        $result = update_option('bpm_registered_themes', $registered_themes);
        
        // Update the in-memory cache as well
        self::$registered_themes = $registered_themes;
        
        // Log the deletion
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                0,
                'theme_deleted',
                sprintf('Theme deleted: %s', $theme_id),
                'info',
                ['theme_id' => $theme_id]
            );
        }
        
        return $result;
    }

    /**
     * Initialize default themes if none registered
     */
    public static function init_default_themes(): void {
        // Don't register default themes - let themes register themselves
        // This prevents conflicts with dynamic theme registration
    }
}