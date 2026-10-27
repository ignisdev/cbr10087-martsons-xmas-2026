<?php
/**
 * User Validator Class
 *
 * Handles user validation, blacklisting, and eligibility checking for competitions
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
 * User validation and blacklist management
 */
class BPM_User_Validator {
    
    /**
     * Default IP blacklist
     */
    private const DEFAULT_IP_BLACKLIST = [
        '89.242.151.10', '31.94.28.154', '81.159.189.105', '185.175.115.40', 
        '31.50.37.161', '81.158.91.242', '92.13.192.208', '86.168.17.138',
        '31.94.34.98', '82.30.107.195', '80.189.158.54', '86.146.239.11',
        '90.205.64.123', '84.69.40.251', '82.11.237.118', '151.231.144.117',
        '92.238.72.132', '86.6.88.6', '86.155.247.91', '151.226.160.249',
        '151.227.212.72', '193.160.247.161', '90.217.38.229', '86.26.250.70',
        '84.64.195.231', '82.32.27.224', '92.18.155.150', '80.46.94.102',
        '86.178.197.148', '90.217.38.229', '84.69.40.251', '95.144.58.50',
        '82.132.236.176', '82.132.239.139', '82.132.239.178', '82.132.246.232',
        '81.102.124.234', '82.132.238.141', '90.205.172.254', '82.132.244.137',
        '82.132.246.24', '90.208.188.7', '82.23.212.1720', '82.132.239.76',
        '92.238.105.61'
    ];
    
    /**
     * Default name/email blacklist patterns
     */
    private const DEFAULT_NAME_PATTERNS = [
        'armstrong', 'herminia', 'faydra', 'joslyn', 'anthony', 'carlos',
        'Mahler', 'middleton', 'davidson', 'cox', 'Reynold', 'bond',
        'kurt', 'bailey', 'barton', 'grogan', 'robson', 'kiley',
        'wootton', 'laflin', 'bailz'
    ];
    
    /**
     * Check if user is blocked
     *
     * @param string $email User email
     * @param string $name User name
     * @param string $ip User IP address
     * @param int $competition_id Competition ID
     * @return array Result with blocking status and reason
     */
    public static function is_user_blocked(string $email, string $name, string $ip, int $competition_id): array {
        // Sanitize inputs
        $email = sanitize_email($email);
        $name = sanitize_text_field($name);
        $ip = sanitize_text_field($ip);
        
        // Check IP blacklist
        $ip_blacklist = self::get_ip_blacklist($competition_id);
        if (in_array($ip, $ip_blacklist, true)) {
            self::log_blocked_attempt($email, $name, $ip, 'Blocked IP address', $competition_id);
            return [
                'blocked' => true,
                'reason' => 'IP address blocked',
                'can_play' => true // Can play but will always lose
            ];
        }
        
        // Check name/email patterns
        $name_patterns = self::get_name_blacklist_patterns($competition_id);
        error_log("BPM User Validator: Checking blacklist patterns - patterns: " . print_r($name_patterns, true));
        
        $user_data = [$name, $email];
        $pattern = '/(' . implode('|', array_map('preg_quote', $name_patterns)) . ')/i';
        error_log("BPM User Validator: Regex pattern: $pattern");
        error_log("BPM User Validator: User data to check: " . print_r($user_data, true));
        
        $matches = preg_grep($pattern, $user_data);
        error_log("BPM User Validator: Pattern matches found: " . print_r($matches, true));
        
        if (!empty($matches)) {
            error_log("BPM User Validator: BLOCKING - Name/email pattern match found");
            self::log_blocked_attempt($email, $name, $ip, 'Name/email pattern match', $competition_id);
            return [
                'blocked' => true,
                'reason' => 'Name/email blocked',
                'can_play' => true // Can play but will always lose
            ];
        }
        
        // Check for previous wins in this competition
        if (self::has_user_won_before($email, $name, $ip, $competition_id)) {
            self::log_blocked_attempt($email, $name, $ip, 'Previous winner', $competition_id);
            return [
                'blocked' => true,
                'reason' => 'Already won this competition',
                'can_play' => true // Can play but will always lose
            ];
        }
        
        // User is not blocked
        return [
            'blocked' => false,
            'reason' => '',
            'can_play' => true
        ];
    }
    
    /**
     * Get IP blacklist for competition
     *
     * @param int $competition_id Competition ID
     * @return array Array of blocked IP addresses
     */
    private static function get_ip_blacklist(int $competition_id): array {
        // Check for competition-specific blacklist
        $custom_blacklist = get_option("bpm_ip_blacklist_{$competition_id}", []);
        
        // Merge with default blacklist
        $blacklist = array_merge(self::DEFAULT_IP_BLACKLIST, $custom_blacklist);
        
        // Remove duplicates and return
        return array_unique($blacklist);
    }
    
    /**
     * Get name/email blacklist patterns for competition
     *
     * @param int $competition_id Competition ID
     * @return array Array of blocked patterns
     */
    private static function get_name_blacklist_patterns(int $competition_id): array {
        // Check for competition-specific patterns
        $custom_patterns = get_option("bpm_name_blacklist_{$competition_id}", []);
        
        // Merge with default patterns
        $patterns = array_merge(self::DEFAULT_NAME_PATTERNS, $custom_patterns);
        
        // Remove duplicates and return
        return array_unique($patterns);
    }
    
    /**
     * Check if user has won before in this competition
     *
     * @param string $email User email
     * @param string $name User name
     * @param string $ip User IP
     * @param int $competition_id Competition ID
     * @return bool True if user has won before
     */
    private static function has_user_won_before(string $email, string $name, string $ip, int $competition_id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bpm_prize_entries';
        
        // Check by email (primary method)
        $email_wins = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE competition_id = %d 
             AND claimed = 1 
             AND claimed_by_email = %s",
            $competition_id,
            $email
        ));
        
        error_log("BPM User Validator: Checking email '$email' in competition $competition_id - found $email_wins previous wins");
        
        if ($email_wins > 0) {
            error_log("BPM User Validator: User blocked - email '$email' has won before");
            return true;
        }

        // Check by name using the claimed_by_name column
        if (!empty($name)) {
            $name_wins = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table 
                 WHERE competition_id = %d 
                 AND claimed = 1 
                 AND claimed_by_name = %s",
                $competition_id,
                $name
            ));
            
            error_log("BPM User Validator: Checking name '$name' in competition $competition_id - found $name_wins previous wins");
            
            if ($name_wins > 0) {
                error_log("BPM User Validator: User blocked - name '$name' has won before");
                return true;
            }
        }
        
        // Check for actual previous winners in the prize entries table
        // (The legacy Gravity Forms check was causing false positives by blocking 
        // all previous participants instead of just actual winners)
        error_log("BPM User Validator: Previous winner check completed - user is not blocked based on wins");
        
        return false;
    }
    
    /**
     * Log blocked attempt
     *
     * @param string $email User email
     * @param string $name User name
     * @param string $ip User IP
     * @param string $reason Blocking reason
     * @param int $competition_id Competition ID
     */
    private static function log_blocked_attempt(string $email, string $name, string $ip, string $reason, int $competition_id): void {
        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                $competition_id,
                'user_blocked',
                sprintf('User blocked: %s (%s)', $email, $reason),
                'warning',
                [
                    'email' => $email,
                    'name' => $name,
                    'ip' => $ip,
                    'reason' => $reason,
                    'timestamp' => current_time('mysql', true)
                ]
            );
        }
    }
    
    /**
     * Add IP to blacklist
     *
     * @param string $ip IP address to block
     * @param int $competition_id Competition ID (0 for global)
     * @return bool Success status
     */
    public static function add_ip_to_blacklist(string $ip, int $competition_id = 0): bool {
        $ip = sanitize_text_field($ip);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        $option_name = $competition_id > 0 ? "bpm_ip_blacklist_{$competition_id}" : 'bpm_global_ip_blacklist';
        $current_list = get_option($option_name, []);
        
        if (!in_array($ip, $current_list, true)) {
            $current_list[] = $ip;
            update_option($option_name, $current_list);
            
            // Log the addition
            if (class_exists('BPM_Audit_Logger')) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'ip_blacklisted',
                    sprintf('IP address added to blacklist: %s', $ip),
                    'info',
                    ['ip' => $ip, 'competition_id' => $competition_id]
                );
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Remove IP from blacklist
     *
     * @param string $ip IP address to unblock
     * @param int $competition_id Competition ID (0 for global)
     * @return bool Success status
     */
    public static function remove_ip_from_blacklist(string $ip, int $competition_id = 0): bool {
        $ip = sanitize_text_field($ip);
        
        $option_name = $competition_id > 0 ? "bpm_ip_blacklist_{$competition_id}" : 'bpm_global_ip_blacklist';
        $current_list = get_option($option_name, []);
        
        $key = array_search($ip, $current_list, true);
        if ($key !== false) {
            unset($current_list[$key]);
            $current_list = array_values($current_list); // Re-index array
            update_option($option_name, $current_list);
            
            // Log the removal
            if (class_exists('BPM_Audit_Logger')) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'ip_unblacklisted',
                    sprintf('IP address removed from blacklist: %s', $ip),
                    'info',
                    ['ip' => $ip, 'competition_id' => $competition_id]
                );
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all blacklisted IPs for a competition
     *
     * @param int $competition_id Competition ID
     * @return array Array of blocked IP addresses
     */
    public static function get_blacklisted_ips(int $competition_id = 0): array {
        if ($competition_id > 0) {
            $custom_list = get_option("bpm_ip_blacklist_{$competition_id}", []);
            return array_merge(self::DEFAULT_IP_BLACKLIST, $custom_list);
        }
        
        $global_list = get_option('bpm_global_ip_blacklist', []);
        return array_merge(self::DEFAULT_IP_BLACKLIST, $global_list);
    }
}