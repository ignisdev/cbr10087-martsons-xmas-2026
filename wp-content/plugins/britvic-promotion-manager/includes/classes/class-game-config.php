<?php
/**
 * Game Configuration Class
 *
 * Handles dynamic game configuration for competitions including
 * wheel sections, prize mapping, and game behavior
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
 * Game configuration management
 */
class BPM_Game_Config {
    
    /**
     * Get game configuration for a competition
     *
     * @param int $competition_id Competition ID
     * @return array Game configuration
     */
    public static function get_game_config(int $competition_id): array {
        // Get stored configuration
        $config = get_option("bpm_game_config_{$competition_id}", []);
        
        // Return default configuration if none exists
        if (empty($config)) {
            $config = self::get_default_config();
            // Save default config for this competition
            update_option("bpm_game_config_{$competition_id}", $config);
        }
        
        return $config;
    }
    
    /**
     * Save game configuration for a competition
     *
     * @param int $competition_id Competition ID
     * @param array $config Game configuration
     * @return bool Success status
     */
    public static function save_game_config(int $competition_id, array $config): bool {
        // Validate configuration
        $validated_config = self::validate_config($config);
        
        if ($validated_config === false) {
            return false;
        }
        
        // Save configuration
        $result = update_option("bpm_game_config_{$competition_id}", $validated_config);
        
        // Log the configuration update
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                $competition_id,
                'game_config_updated',
                'Game configuration updated',
                'info',
                ['config' => $validated_config, 'user_id' => get_current_user_id()]
            );
        }
        
        return $result;
    }
    
    /**
     * Get default game configuration
     *
     * @return array Default configuration
     */
    private static function get_default_config(): array {
        return [
            'wheel_sections' => [
                1 => [
                    'type' => 'loser',
                    'prize_types' => ['loser'],
                    'angle' => 25,
                    'speed' => 1.2,
                    'label' => 'Try Again'
                ],
                2 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 55,
                    'speed' => 1.0,
                    'label' => 'Free Drink'
                ],
                3 => [
                    'type' => 'winner',
                    'prize_types' => ['voucher', 'Summer Wheels Voucher', 'Family Wheels Voucher', 'Vintage Inns Voucher'],
                    'angle' => 85,
                    'speed' => 1.1,
                    'label' => 'Voucher'
                ],
                4 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 115,
                    'speed' => 1.3,
                    'label' => 'Free Drink'
                ],
                5 => [
                    'type' => 'loser',
                    'prize_types' => ['loser'],
                    'angle' => 145,
                    'speed' => 1.2,
                    'label' => 'Try Again'
                ],
                6 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 175,
                    'speed' => 1.4,
                    'label' => 'Free Drink'
                ],
                7 => [
                    'type' => 'winner',
                    'prize_types' => ['voucher', 'Summer Wheels Voucher', 'Family Wheels Voucher', 'Vintage Inns Voucher'],
                    'angle' => 205,
                    'speed' => 1.5,
                    'label' => 'Voucher'
                ],
                8 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 235,
                    'speed' => 1.6,
                    'label' => 'Free Drink'
                ],
                9 => [
                    'type' => 'loser',
                    'prize_types' => ['loser'],
                    'angle' => 265,
                    'speed' => 1.2,
                    'label' => 'Try Again'
                ],
                10 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 295,
                    'speed' => 1.7,
                    'label' => 'Free Drink'
                ],
                11 => [
                    'type' => 'winner',
                    'prize_types' => ['voucher', 'Summer Wheels Voucher', 'Family Wheels Voucher', 'Vintage Inns Voucher'],
                    'angle' => 325,
                    'speed' => 1.8,
                    'label' => 'Voucher'
                ],
                12 => [
                    'type' => 'winner',
                    'prize_types' => ['drink'],
                    'angle' => 355,
                    'speed' => 1.8,
                    'label' => 'Free Drink'
                ]
            ],
            'game_settings' => [
                'spin_duration' => 1.5,
                'final_spin_duration_min' => 1.0,
                'final_spin_duration_max' => 1.8,
                'celebration_duration' => 6000,
                'enable_sound' => false,
                'enable_animations' => true
            ],
            'redirect_urls' => [
                'winner' => '/winner',
                'loser' => '/non-winner',
                'error' => '/error'
            ]
        ];
    }
    
    /**
     * Validate game configuration
     *
     * @param array $config Configuration to validate
     * @return array|false Validated configuration or false if invalid
     */
    private static function validate_config(array $config): array|false {
        // Ensure required keys exist
        $required_keys = ['wheel_sections', 'game_settings', 'redirect_urls'];
        foreach ($required_keys as $key) {
            if (!isset($config[$key])) {
                return false;
            }
        }
        
        // Validate wheel sections
        if (!is_array($config['wheel_sections']) || empty($config['wheel_sections'])) {
            return false;
        }
        
        // Validate each wheel section
        foreach ($config['wheel_sections'] as $section_id => $section) {
            if (!isset($section['type'], $section['prize_types'], $section['angle'], $section['speed'])) {
                return false;
            }
            
            // Sanitize section data
            $config['wheel_sections'][$section_id] = [
                'type' => sanitize_text_field($section['type']),
                'prize_types' => array_map('sanitize_text_field', (array) $section['prize_types']),
                'angle' => (float) $section['angle'],
                'speed' => (float) $section['speed'],
                'label' => sanitize_text_field($section['label'] ?? '')
            ];
        }
        
        // Validate game settings
        $config['game_settings'] = [
            'spin_duration' => (float) ($config['game_settings']['spin_duration'] ?? 1.5),
            'final_spin_duration_min' => (float) ($config['game_settings']['final_spin_duration_min'] ?? 1.0),
            'final_spin_duration_max' => (float) ($config['game_settings']['final_spin_duration_max'] ?? 1.8),
            'celebration_duration' => (int) ($config['game_settings']['celebration_duration'] ?? 6000),
            'enable_sound' => (bool) ($config['game_settings']['enable_sound'] ?? false),
            'enable_animations' => (bool) ($config['game_settings']['enable_animations'] ?? true)
        ];
        
        // Validate redirect URLs
        $config['redirect_urls'] = [
            'winner' => sanitize_text_field($config['redirect_urls']['winner'] ?? '/winner'),
            'loser' => sanitize_text_field($config['redirect_urls']['loser'] ?? '/non-winner'),
            'error' => sanitize_text_field($config['redirect_urls']['error'] ?? '/error')
        ];
        
        return $config;
    }
    
    /**
     * Get wheel section for a specific prize type
     *
     * @param string $prize_type Prize type to find section for
     * @param int $competition_id Competition ID
     * @return array|null Wheel section configuration or null if not found
     */
    public static function get_section_for_prize(string $prize_type, int $competition_id): ?array {
        $config = self::get_game_config($competition_id);
        
        // Find matching sections
        $matching_sections = [];
        foreach ($config['wheel_sections'] as $section_id => $section) {
            if (in_array($prize_type, $section['prize_types'], true)) {
                $matching_sections[] = array_merge($section, ['section_id' => $section_id]);
            }
        }
        
        if (empty($matching_sections)) {
            // Fallback to first loser section
            foreach ($config['wheel_sections'] as $section_id => $section) {
                if ($section['type'] === 'loser') {
                    return array_merge($section, ['section_id' => $section_id]);
                }
            }
            return null;
        }
        
        // Return random matching section
        return $matching_sections[array_rand($matching_sections)];
    }
    
    /**
     * Get available prize types for a competition
     *
     * @param int $competition_id Competition ID
     * @return array Array of available prize types
     */
    public static function get_available_prize_types(int $competition_id): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bpm_prize_entries';
        
        $prize_types = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT prize_type FROM $table 
             WHERE competition_id = %d 
             AND prize_type IS NOT NULL 
             AND prize_type != ''
             ORDER BY prize_type",
            $competition_id
        ));
        
        return $prize_types ?: [];
    }
    
    /**
     * Generate JavaScript configuration for frontend
     *
     * @param string $prize_type Won prize type
     * @param int $competition_id Competition ID
     * @return array JavaScript configuration
     */
    public static function generate_js_config(string $prize_type, int $competition_id): array {
        $config = self::get_game_config($competition_id);
        $target_section = self::get_section_for_prize($prize_type, $competition_id);
        
        return [
            'wheel_sections' => $config['wheel_sections'],
            'game_settings' => $config['game_settings'],
            'redirect_urls' => $config['redirect_urls'],
            'target_section' => $target_section,
            'competition_id' => $competition_id
        ];
    }
}