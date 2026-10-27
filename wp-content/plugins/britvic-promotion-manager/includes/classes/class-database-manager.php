<?php
/**
 * Database Manager Class
 *
 * Handles database table creation, updates, and maintenance
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
 * Database Manager implementation
 */
class BPM_Database_Manager {
    
    /**
     * Current database schema version
     */
    private const SCHEMA_VERSION = '1.0.0';
    
    /**
     * Database version option name
     */
    private const VERSION_OPTION = 'bpm_db_version';
    
    /**
     * Check if database needs setup or updates
     *
     * @return bool True if database needs setup
     */
    public static function needs_setup(): bool {
        $installed_version = get_option(self::VERSION_OPTION, '0');
        return version_compare($installed_version, self::SCHEMA_VERSION, '<');
    }
    
    /**
     * Setup database tables and initial data
     *
     * @return array Result with success status and messages
     */
    public static function setup_database(): array {
        try {
            // Create tables
            $table_results = self::create_tables();
            
            // Create indexes
            $index_results = self::create_indexes();
            
            // Insert default data
            $data_results = self::insert_default_data();
            
            // Update version
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
            
            // Log successful setup
            BPM_Audit_Logger::log(
                0,
                'database_setup',
                sprintf('Database setup completed - Schema version %s', self::SCHEMA_VERSION)
            );
            
            return [
                'success' => true,
                'message' => __('Database setup completed successfully', 'britvic-promo'),
                'details' => array_merge($table_results, $index_results, $data_results)
            ];
            
        } catch (Exception $e) {
            error_log('BPM Database Setup Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => __('Database setup failed', 'britvic-promo'),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create all database tables
     *
     * @return array Results for each table creation
     */
    private static function create_tables(): array {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $results = [];
        
        // Define all tables
        $tables = [
            'competitions' => self::get_competitions_table_sql($charset_collate),
            'audit_log' => self::get_audit_log_table_sql($charset_collate),
            'prize_entries' => self::get_prize_entries_table_sql($charset_collate),
            'competition_settings' => self::get_settings_table_sql($charset_collate),
            'email_log' => self::get_email_log_table_sql($charset_collate),
            'user_blocks' => self::get_user_blocks_table_sql($charset_collate),
            'daily_plays' => self::get_daily_plays_table_sql($charset_collate)
        ];
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        foreach ($tables as $table_name => $sql) {
            $result = dbDelta($sql);
            $results[$table_name] = [
                'table' => $wpdb->prefix . 'bpm_' . $table_name,
                'result' => $result,
                'exists' => self::table_exists($wpdb->prefix . 'bpm_' . $table_name)
            ];
        }
        
        return $results;
    }
    
    /**
     * Get competitions table SQL
     */
    private static function get_competitions_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_competitions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            type enum('instant_win','prize_draw') NOT NULL DEFAULT 'instant_win',
            status enum('draft','scheduled','active','closed') NOT NULL DEFAULT 'draft',
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            live_page_id bigint(20) DEFAULT NULL,
            closed_page_id bigint(20) DEFAULT NULL,
            prize_page_id bigint(20) DEFAULT NULL,
            terms_page_id bigint(20) DEFAULT NULL,
            settings longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_type (type)
        ) $charset_collate;";
    }
    
    /**
     * Get audit log table SQL
     */
    private static function get_audit_log_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_audit_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            competition_id mediumint(9) DEFAULT NULL,
            action varchar(100) NOT NULL,
            details longtext,
            level enum('info','warning','error','critical') NOT NULL DEFAULT 'info',
            user_id bigint(20) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_competition (competition_id),
            INDEX idx_action (action),
            INDEX idx_level (level),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) $charset_collate;";
    }
    
    /**
     * Get prize entries table SQL
     */
    private static function get_prize_entries_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_prize_entries (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            competition_id mediumint(9) NOT NULL,
            prize_post_id bigint(20) NOT NULL,
            unique_id varchar(255) NOT NULL,
            code varchar(255) DEFAULT NULL,
            prize_type varchar(100) DEFAULT NULL,
            prize_name varchar(255) DEFAULT NULL,
            pin varchar(50) DEFAULT NULL,
            date_available datetime NOT NULL,
            claimed tinyint(1) NOT NULL DEFAULT 0,
            claimed_at datetime DEFAULT NULL,
            claimed_by_user_id bigint(20) DEFAULT NULL,
            claimed_by_email varchar(255) DEFAULT NULL,
            claimed_by_name varchar(255) DEFAULT NULL,
            gravity_form_entry_id bigint(20) DEFAULT NULL,
            reserved_at datetime DEFAULT NULL,
            reservation_expires_at datetime DEFAULT NULL,
            reservation_key varchar(64) DEFAULT NULL,
            stripe_account_id varchar(255) DEFAULT NULL,
            stripe_transfer_id varchar(255) DEFAULT NULL,
            stripe_payout_id varchar(255) DEFAULT NULL,
            stripe_payout_status varchar(50) DEFAULT NULL,
            source_route varchar(16) NOT NULL DEFAULT 'both',
            original_data longtext DEFAULT NULL,
            audit_trail longtext DEFAULT NULL,
            reseeded tinyint(1) NOT NULL DEFAULT 0,
            reseeded_count int NOT NULL DEFAULT 0,
            last_reseeded datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_unique_id (unique_id),
            INDEX idx_code (code),
            INDEX idx_competition (competition_id),
            INDEX idx_prize_post (prize_post_id),
            INDEX idx_date_available (date_available),
            INDEX idx_claimed (claimed),
            INDEX idx_prize_type (prize_type),
            INDEX idx_claimed_email (claimed_by_email),
            INDEX idx_reservation_expires (reservation_expires_at),
            INDEX idx_stripe_payout_status (stripe_payout_status),
            INDEX idx_reseeded (reseeded),
            INDEX idx_source_route (source_route)
        ) $charset_collate;";
    }
    
    /**
     * Get settings table SQL
     */
    private static function get_settings_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_competition_settings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            competition_id mediumint(9) NOT NULL,
            setting_key varchar(100) NOT NULL,
            setting_value longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_competition_setting (competition_id, setting_key),
            INDEX idx_setting_key (setting_key)
        ) $charset_collate;";
    }
    
    /**
     * Get email log table SQL
     */
    private static function get_email_log_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_email_log (
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
    }
    
    /**
     * Get daily plays table SQL
     *
     * Tracks per-player daily play counts. One row per (email_hash, play_date).
     * Used by promos that enforce a maximum number of plays per day per email
     * (Pepsi Football's "3 plays/day" rule). SHA-256 of the email is stored
     * instead of the raw address so no PII sits in this auxiliary table.
     */
    private static function get_daily_plays_table_sql(string $charset_collate): string {
        global $wpdb;

        return "CREATE TABLE {$wpdb->prefix}bpm_daily_plays (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            email_hash char(64) NOT NULL,
            play_date date NOT NULL,
            play_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_email_date (email_hash, play_date),
            KEY idx_play_date (play_date)
        ) $charset_collate;";
    }

    /**
     * Get user blocks table SQL
     */
    private static function get_user_blocks_table_sql(string $charset_collate): string {
        global $wpdb;
        
        return "CREATE TABLE {$wpdb->prefix}bpm_user_blocks (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            competition_id mediumint(9) NOT NULL,
            block_type varchar(50) NOT NULL,
            block_value varchar(255) NOT NULL,
            block_reason text DEFAULT NULL,
            block_behavior varchar(50) DEFAULT 'deny',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_competition (competition_id),
            INDEX idx_type_value (block_type, block_value),
            INDEX idx_created_at (created_at),
            UNIQUE KEY unique_block (competition_id, block_type, block_value)
        ) $charset_collate;";
    }
    
    /**
     * Create additional indexes for performance
     */
    private static function create_indexes(): array {
        global $wpdb;
        
        $results = [];
        
        // Additional indexes for common queries
        $indexes = [
            'audit_log_composite' => "CREATE INDEX idx_audit_composite ON {$wpdb->prefix}bpm_audit_log (competition_id, level, created_at)",
            'prize_entries_available' => "CREATE INDEX idx_prize_available ON {$wpdb->prefix}bpm_prize_entries (competition_id, claimed, date_available)",
        ];
        
        foreach ($indexes as $index_name => $sql) {
            $result = $wpdb->query($sql);
            $results[$index_name] = [
                'sql' => $sql,
                'result' => $result !== false
            ];
        }
        
        return $results;
    }
    
    /**
     * Insert default data
     */
    private static function insert_default_data(): array {
        global $wpdb;
        
        $results = [];
        
        // Insert default competition types configuration
        $default_settings = [
            [
                'competition_id' => 0, // Global settings
                'setting_key' => 'default_prize_types',
                'setting_value' => json_encode([])  // No default prize types - must be configured per competition
            ],
            [
                'competition_id' => 0,
                'setting_key' => 'email_templates',
                'setting_value' => json_encode([
                    'winner_drink' => [
                        'subject' => 'You\'re a winner with Fruit Shoot!',
                        'template' => 'You have won a free Fruit Shoot in our Summer Wheels competition.'
                    ]
                ])
            ]
        ];
        
        foreach ($default_settings as $setting) {
            $wpdb->replace($wpdb->prefix . 'bpm_competition_settings', $setting);
        }
        
        $results['default_data'] = [
            'settings_inserted' => count($default_settings),
            'success' => true
        ];
        
        return $results;
    }
    
    /**
     * Check if table exists
     */
    private static function table_exists(string $table_name): bool {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        
        return $result === $table_name;
    }
    
    /**
     * Get database status information
     *
     * @return array Database status details
     */
    public static function get_database_status(): array {
        global $wpdb;
        
        $tables = [
            'bpm_competitions',
            'bpm_audit_log', 
            'bpm_prize_entries',
            'bpm_competition_settings',
            'bpm_email_log'
        ];
        
        $status = [
            'schema_version' => get_option(self::VERSION_OPTION, '0'),
            'current_version' => self::SCHEMA_VERSION,
            'needs_update' => self::needs_setup(),
            'tables' => []
        ];
        
        foreach ($tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            $exists = self::table_exists($full_table_name);
            
            $table_status = [
                'name' => $table,
                'exists' => $exists,
                'row_count' => 0
            ];
            
            if ($exists) {
                $table_status['row_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $full_table_name");
            }
            
            $status['tables'][$table] = $table_status;
        }
        
        return $status;
    }
    
    /**
     * Run database maintenance tasks
     *
     * @return array Maintenance results
     */
    public static function run_maintenance(): array {
        global $wpdb;
        
        $results = [];
        
        // Clean up old audit logs (older than 1 year)
        $deleted_logs = BPM_Audit_Logger::cleanup_old_logs(365);
        $results['audit_cleanup'] = [
            'deleted_count' => $deleted_logs,
            'success' => true
        ];
        
        // Optimize tables
        $tables = [
            $wpdb->prefix . 'bpm_competitions',
            $wpdb->prefix . 'bpm_audit_log',
            $wpdb->prefix . 'bpm_prize_entries',
            $wpdb->prefix . 'bpm_competition_settings',
            $wpdb->prefix . 'bpm_email_log',
            $wpdb->prefix . 'bpm_user_blocks'
        ];
        
        foreach ($tables as $table) {
            if (self::table_exists($table)) {
                $wpdb->query("OPTIMIZE TABLE $table");
                $results['optimize'][$table] = true;
            }
        }
        
        // Log maintenance completion
        BPM_Audit_Logger::log(
            0,
            'database_maintenance',
            sprintf('Database maintenance completed - %d audit logs cleaned', $deleted_logs)
        );
        
        return $results;
    }
    
    /**
     * Create database backup information
     *
     * @return array Backup information
     */
    public static function get_backup_info(): array {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'bpm_competitions',
            $wpdb->prefix . 'bpm_audit_log',
            $wpdb->prefix . 'bpm_prize_entries', 
            $wpdb->prefix . 'bpm_competition_settings',
            $wpdb->prefix . 'bpm_email_log',
            $wpdb->prefix . 'bpm_user_blocks'
        ];
        
        $backup_info = [
            'timestamp' => current_time('mysql'),
            'tables' => []
        ];
        
        foreach ($tables as $table) {
            if (self::table_exists($table)) {
                $row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
                $table_size = $wpdb->get_var("
                    SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
                    FROM information_schema.TABLES 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '$table'
                ");
                
                $backup_info['tables'][$table] = [
                    'row_count' => $row_count,
                    'size_mb' => (float) $table_size
                ];
            }
        }
        
        return $backup_info;
    }
    
    /**
     * Run database migrations for new features
     */
    public static function run_migrations(): bool {
        global $wpdb;
        
        $migrations_run = get_option('bpm_migrations_run', []);
        
        // Migration: Add prize_page_id to competitions table
        if (!in_array('add_prize_page_id', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_competitions';
            
            // Check if column already exists
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s",
                'prize_page_id'
            ));
            
            if (empty($column_exists)) {
                $result = $wpdb->query(
                    "ALTER TABLE $table_name ADD COLUMN prize_page_id bigint(20) DEFAULT NULL AFTER closed_page_id"
                );
                
                if ($result !== false) {
                    $migrations_run[] = 'add_prize_page_id';
                    update_option('bpm_migrations_run', $migrations_run);
                }
            } else {
                // Column already exists, mark as run
                $migrations_run[] = 'add_prize_page_id';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }
        
        // Migration: Add terms_page_id to competitions table
        if (!in_array('add_terms_page_id', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_competitions';
            
            // Check if column already exists
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s",
                'terms_page_id'
            ));
            
            if (empty($column_exists)) {
                $result = $wpdb->query(
                    "ALTER TABLE $table_name ADD COLUMN terms_page_id bigint(20) DEFAULT NULL AFTER prize_page_id"
                );
                
                if ($result !== false) {
                    $migrations_run[] = 'add_terms_page_id';
                    update_option('bpm_migrations_run', $migrations_run);
                }
            } else {
                // Column already exists, mark as run
                $migrations_run[] = 'add_terms_page_id';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }
        
        // Migration: Create email_log table
        if (!in_array('create_email_log_table', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_email_log';
            
            // Check if table already exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));
            
            if (!$table_exists) {
                $sql = self::get_email_log_table_sql($wpdb->get_charset_collate());
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $result = dbDelta($sql);
                
                if ($result) {
                    $migrations_run[] = 'create_email_log_table';
                    update_option('bpm_migrations_run', $migrations_run);
                }
            } else {
                // Table already exists, mark as run
                $migrations_run[] = 'create_email_log_table';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }
        
        // Migration: Create user_blocks table
        if (!in_array('create_user_blocks_table', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_user_blocks';
            
            // Check if table already exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));
            
            if (!$table_exists) {
                $sql = self::get_user_blocks_table_sql($wpdb->get_charset_collate());
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $result = dbDelta($sql);
                
                if ($result) {
                    $migrations_run[] = 'create_user_blocks_table';
                    update_option('bpm_migrations_run', $migrations_run);
                }
            } else {
                // Table already exists, mark as run
                $migrations_run[] = 'create_user_blocks_table';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }

        // Migration: Add claimed_by_name column to prize entries
        if (!in_array('add_claimed_by_name_to_prize_entries', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s",
                'claimed_by_name'
            ));

            if (empty($column_exists)) {
                $result = $wpdb->query(
                    "ALTER TABLE $table_name ADD COLUMN claimed_by_name varchar(255) DEFAULT NULL AFTER claimed_by_email"
                );
                if ($result !== false) {
                    $migrations_run[] = 'add_claimed_by_name_to_prize_entries';
                    update_option('bpm_migrations_run', $migrations_run);
                }
            } else {
                $migrations_run[] = 'add_claimed_by_name_to_prize_entries';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }

        // Migration: Add reservation fields to prize entries
        if (!in_array('add_reservation_fields_to_prize_entries', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $required_columns = [
                'reserved_at' => "ADD COLUMN reserved_at datetime DEFAULT NULL AFTER gravity_form_entry_id",
                'reservation_expires_at' => "ADD COLUMN reservation_expires_at datetime DEFAULT NULL AFTER reserved_at",
                'reservation_key' => "ADD COLUMN reservation_key varchar(64) DEFAULT NULL AFTER reservation_expires_at",
            ];

            $all_present = true;
            foreach ($required_columns as $column => $sql_fragment) {
                $column_exists = $wpdb->get_results($wpdb->prepare(
                    "SHOW COLUMNS FROM $table_name LIKE %s",
                    $column
                ));
                if (empty($column_exists)) {
                    $all_present = false;
                    $wpdb->query("ALTER TABLE $table_name $sql_fragment");
                }
            }

            $wpdb->query("CREATE INDEX idx_reservation_expires ON $table_name (reservation_expires_at)");

            if ($all_present || !empty($required_columns)) {
                $migrations_run[] = 'add_reservation_fields_to_prize_entries';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }

        // Migration: Add Stripe tracking fields to prize entries
        if (!in_array('add_stripe_fields_to_prize_entries', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $required_columns = [
                'stripe_account_id' => "ADD COLUMN stripe_account_id varchar(255) DEFAULT NULL AFTER reservation_key",
                'stripe_transfer_id' => "ADD COLUMN stripe_transfer_id varchar(255) DEFAULT NULL AFTER stripe_account_id",
                'stripe_payout_id' => "ADD COLUMN stripe_payout_id varchar(255) DEFAULT NULL AFTER stripe_transfer_id",
                'stripe_payout_status' => "ADD COLUMN stripe_payout_status varchar(50) DEFAULT NULL AFTER stripe_payout_id",
            ];

            $all_present = true;
            foreach ($required_columns as $column => $sql_fragment) {
                $column_exists = $wpdb->get_results($wpdb->prepare(
                    "SHOW COLUMNS FROM $table_name LIKE %s",
                    $column
                ));
                if (empty($column_exists)) {
                    $all_present = false;
                    $wpdb->query("ALTER TABLE $table_name $sql_fragment");
                }
            }

            $wpdb->query("CREATE INDEX idx_stripe_payout_status ON $table_name (stripe_payout_status)");

            if ($all_present || !empty($required_columns)) {
                $migrations_run[] = 'add_stripe_fields_to_prize_entries';
                update_option('bpm_migrations_run', $migrations_run);
            }
        }

        // Migration: Add source_route column for multi-domain prize segmentation
        if (!in_array('add_source_route_to_prize_entries', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s",
                'source_route'
            ));

            if (empty($column_exists)) {
                $wpdb->query(
                    "ALTER TABLE $table_name ADD COLUMN source_route varchar(16) NOT NULL DEFAULT 'both' AFTER stripe_payout_status"
                );
                $wpdb->query("CREATE INDEX idx_source_route ON $table_name (source_route)");
            }

            $migrations_run[] = 'add_source_route_to_prize_entries';
            update_option('bpm_migrations_run', $migrations_run);
        }

        // Migration: Create bpm_daily_plays table for per-player daily play limits.
        // Used by promos that cap how many times a player can enter per day.
        if (!in_array('create_bpm_daily_plays', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_daily_plays';
            if (!self::table_exists($table_name)) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta(self::get_daily_plays_table_sql($wpdb->get_charset_collate()));
            }
            $migrations_run[] = 'create_bpm_daily_plays';
            update_option('bpm_migrations_run', $migrations_run);
        }

        // Migration: add Stripe v2 Global Payouts columns to prize_entries
        // (separate from v1 fields so in-flight v1 records keep settling).
        if (!in_array('add_v2_global_payouts_columns', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $required_columns = [
                'stripe_account_id_v2'       => "ADD COLUMN stripe_account_id_v2 varchar(64) DEFAULT NULL AFTER stripe_payout_status",
                'stripe_outbound_payment_id' => "ADD COLUMN stripe_outbound_payment_id varchar(64) DEFAULT NULL AFTER stripe_account_id_v2",
                'stripe_gb_bank_account_id'  => "ADD COLUMN stripe_gb_bank_account_id varchar(64) DEFAULT NULL AFTER stripe_outbound_payment_id",
                'stripe_cop_result'          => "ADD COLUMN stripe_cop_result varchar(32) DEFAULT NULL AFTER stripe_gb_bank_account_id",
            ];

            foreach ($required_columns as $column => $sql_fragment) {
                $column_exists = $wpdb->get_results($wpdb->prepare(
                    "SHOW COLUMNS FROM $table_name LIKE %s",
                    $column
                ));
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE $table_name $sql_fragment");
                }
            }

            // Index supports the webhook lookup by OutboundPayment id.
            $wpdb->query("CREATE INDEX idx_stripe_outbound_payment_id ON $table_name (stripe_outbound_payment_id)");

            $migrations_run[] = 'add_v2_global_payouts_columns';
            update_option('bpm_migrations_run', $migrations_run);
        }

        // Migration: normalise legacy stripe_payout_status values to match
        // Stripe's verbatim OutboundPayment status names. Earlier code wrote
        // `submitted` (immediately after create) and `paid` (on the webhook);
        // we now store Stripe's `processing` / `posted` verbatim. This
        // one-shot UPDATE brings any in-flight or historical rows in line so
        // the BPM admin Status column reads consistently.
        if (!in_array('normalize_stripe_payout_status_v2', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $wpdb->query("UPDATE $table_name SET stripe_payout_status = 'processing' WHERE stripe_payout_status = 'submitted'");
            $wpdb->query("UPDATE $table_name SET stripe_payout_status = 'posted'     WHERE stripe_payout_status = 'paid'");
            $migrations_run[] = 'normalize_stripe_payout_status_v2';
            update_option('bpm_migrations_run', $migrations_run);
        }

        // Migration: add Stripe-side bank identity columns to prize_entries.
        // Captured from the v2 vault gb_bank_account object at payout time; the
        // holder name is the name given to Stripe with the bank details (which
        // may differ from the claim-form name) and is the key the payout
        // blocklist / one-payout-per-bank-account rule matches on.
        if (!in_array('add_bank_identity_to_bpm_prize_entries', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $required_columns = [
                'stripe_bank_holder_name' => "ADD COLUMN stripe_bank_holder_name varchar(191) DEFAULT NULL AFTER stripe_cop_result",
                'stripe_bank_last4'       => "ADD COLUMN stripe_bank_last4 varchar(8) DEFAULT NULL AFTER stripe_bank_holder_name",
                'stripe_bank_fingerprint' => "ADD COLUMN stripe_bank_fingerprint varchar(64) DEFAULT NULL AFTER stripe_bank_last4",
            ];

            foreach ($required_columns as $column => $sql_fragment) {
                $column_exists = $wpdb->get_results($wpdb->prepare(
                    "SHOW COLUMNS FROM $table_name LIKE %s",
                    $column
                ));
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE $table_name $sql_fragment");
                }
            }

            // Index supports the duplicate-bank-account lookup at payout time.
            $wpdb->query("CREATE INDEX idx_stripe_bank_fingerprint ON $table_name (stripe_bank_fingerprint)");

            $migrations_run[] = 'add_bank_identity_to_bpm_prize_entries';
            update_option('bpm_migrations_run', $migrations_run);
        }

        // Migration: rename prize_value → prize_name in prize_entries table
        if (!in_array('rename_prize_value_to_prize_name', $migrations_run)) {
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            $col_exists = $wpdb->get_results( $wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s", 'prize_value'
            ) );
            if ( !empty( $col_exists ) ) {
                $wpdb->query( "ALTER TABLE $table_name RENAME COLUMN prize_value TO prize_name" );
            }
            $migrations_run[] = 'rename_prize_value_to_prize_name';
            update_option( 'bpm_migrations_run', $migrations_run );
        }

        return true;
    }
}
