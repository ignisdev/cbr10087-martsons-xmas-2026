<?php
/**
 * Plugin Name: Britvic Promotion Manager
 * Plugin URI: https://britvic.com
 * Description: Comprehensive promotion management system for instant win competitions, prize draws, and automated page publishing.
 * Version: 1.0.0
 * Author: Britvic Development Team
 * License: GPL v2 or later
 * Text Domain: britvic-promo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.3
 * Network: false
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BPM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BPM_VERSION', '1.0.0');
define('BPM_MIN_PHP_VERSION', '8.3');
define('BPM_MIN_WP_VERSION', '6.0');

/**
 * Main plugin class following WordPress coding standards and PHP 8.3 best practices
 */
final class BritvicPromotionManager {
    
    private static ?BritvicPromotionManager $instance = null;
    
    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): BritvicPromotionManager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->check_requirements();
        $this->init_hooks();
    }
    
    /**
     * Check PHP and WordPress version requirements
     */
    private function check_requirements(): void {
        if (version_compare(PHP_VERSION, BPM_MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', [$this, 'php_version_notice']);
            return;
        }
        
        if (version_compare(get_bloginfo('version'), BPM_MIN_WP_VERSION, '<')) {
            add_action('admin_notices', [$this, 'wp_version_notice']);
            return;
        }
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'check_and_run_migrations']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX hooks with proper naming convention
        add_action('wp_ajax_bpm_generate_prizes', [$this, 'handle_ajax_generate_prizes']);
        add_action('wp_ajax_bpm_toggle_pages', [$this, 'handle_ajax_toggle_pages']);
        add_action('wp_ajax_bpm_export_csv', [$this, 'handle_ajax_export_csv']);
        add_action('wp_ajax_bpm_import_csv', [$this, 'handle_ajax_import_csv']);
        add_action('wp_ajax_bpm_import_xlsx', [$this, 'handle_ajax_import_xlsx']);
        add_action('wp_ajax_bpm_analyze_xlsx', [$this, 'handle_ajax_analyze_xlsx']);
        add_action('wp_ajax_bpm_analyze_csv', [$this, 'handle_ajax_analyze_csv']);
        add_action('wp_ajax_bpm_update_prize_field', [$this, 'handle_ajax_update_prize_field']);
        add_action('wp_ajax_bpm_toggle_prize_claim', [$this, 'handle_ajax_toggle_prize_claim']);
        add_action('wp_ajax_bpm_reset_prize_claim', [$this, 'handle_ajax_reset_prize_claim']);
        add_action('wp_ajax_bpm_get_prize_history', [$this, 'handle_ajax_get_prize_history']);
        add_action('wp_ajax_bpm_get_reseed_info', [$this, 'handle_ajax_get_reseed_info']);
        add_action('wp_ajax_bpm_reseed_prizes', [$this, 'handle_ajax_reseed_prizes']);
        add_action('wp_ajax_bpm_save_email_templates', [$this, 'handle_ajax_save_email_templates']);
        add_action('wp_ajax_bpm_send_test_email', [$this, 'handle_ajax_send_test_email']);
        add_action('wp_ajax_bpm_bulk_delete_prizes', [$this, 'handle_ajax_bulk_delete_prizes']);
        add_action('wp_ajax_bpm_delete_prize', [$this, 'handle_ajax_delete_prize']);
        add_action('wp_ajax_bpm_add_single_prize', [$this, 'handle_ajax_add_single_prize']);
        add_action('wp_ajax_bpm_save_generation_prize_types', [$this, 'handle_ajax_save_generation_prize_types']);
        add_action('wp_ajax_bpm_preview_email_template', [$this, 'handle_ajax_preview_email_template']);
        add_action('wp_ajax_bpm_reset_column_preferences', [$this, 'handle_ajax_reset_column_preferences']);
        add_action('wp_ajax_bpm_bulk_import_blocks', [$this, 'handle_ajax_bulk_import_blocks']);
        add_action('wp_ajax_bpm_prizes_fetch_fragment', [$this, 'handle_ajax_prizes_fetch_fragment']);
        add_action('wp_ajax_bpm_prizes_sync_aliases',   [$this, 'handle_ajax_prizes_sync_aliases']);
        add_action('wp_ajax_bpm_sync_prizes',           [$this, 'handle_ajax_bpm_sync_prizes']);
        add_action('wp_ajax_bpm_manual_sync',           [$this, 'handle_ajax_bpm_manual_sync']);
        add_action('wp_ajax_bpm_prizes_bulk_action',    [$this, 'handle_ajax_prizes_bulk_action']);
        
        // Cron hooks for scheduled page publishing
        add_action('bpm_publish_competition', [$this, 'publish_competition']);
        add_action('bpm_close_competition', [$this, 'close_competition']);
        add_action('bpm_check_competitions', [$this, 'check_scheduled_competitions']);
        
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Ensure promotions directory exists
        add_action('init', [$this, 'ensure_promotions_directory']);
        
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Scheduled action hooks
        add_action('bpm_publish_competition', [$this, 'handle_scheduled_publish']);
        add_action('bpm_close_competition', [$this, 'handle_scheduled_close']);
    }
    
    /**
     * Check and run database migrations if needed
     */
    public function check_and_run_migrations(): void {
        // Only run for admins and only once per session
        if (!current_user_can('administrator')) {
            return;
        }
        
        // Allow forcing migrations with URL parameter
        $force_migrations = isset($_GET['force_migrations']) && $_GET['force_migrations'] === '1';
        
        // Check if we've already run migrations this session (unless forced)
        if (!$force_migrations && get_transient('bpm_migrations_checked')) {
            return;
        }
        
        // Check if database needs setup or migrations
        if (class_exists('BPM_Database_Manager')) {
            if (BPM_Database_Manager::needs_setup()) {
                // Run setup (creates tables if needed)
                BPM_Database_Manager::setup_database();
            }
            
            // Always run migrations to catch any missing columns
            BPM_Database_Manager::run_migrations();
        }
        
        // Show success message if migrations were forced
        if ($force_migrations) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p><strong>BPM:</strong> Database migrations have been run successfully!</p></div>';
            });
        }
        
        // Set a transient to avoid running again this session (1 hour)
        set_transient('bpm_migrations_checked', true, HOUR_IN_SECONDS);
    }
    
    /**
     * Plugin initialization
     */
    public function init(): void {
        // Load plugin text domain
        load_plugin_textdomain('britvic-promo', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Initialize database tables
        try {
            $this->maybe_create_tables();
        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>' . 
                     sprintf(__('BPM Plugin database error: %s', 'britvic-promo'), esc_html($e->getMessage())) . 
                     '</p></div>';
            });
            return;
        }
        
        // Load required classes
        $this->load_dependencies();
        
        // Initialize form integration
        if (class_exists('BPM_Form_Integration')) {
            BPM_Form_Integration::init();
        }

        // Initialize brand config (registers wp_head CSS injection hook)
        if (class_exists('BPM_Brand_Config')) {
            BPM_Brand_Config::init();
        }

        // Initialize dashboard widgets (Entries by Route + Prizes Redeemed)
        if (class_exists('BPM_Dashboard_Widgets')) {
            BPM_Dashboard_Widgets::init();
        }
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies(): void {
        $classes = [
            'includes/interfaces/interface-prize-generator.php',
            'includes/interfaces/interface-page-manager.php',
            'includes/interfaces/interface-csv-handler.php',
            'includes/classes/class-database-manager.php',
            'includes/classes/class-prize-generator.php',
            'includes/classes/class-page-manager.php',
            'includes/classes/class-csv-handler.php',
            'includes/classes/class-xlsx-handler.php',
            'includes/classes/class-audit-logger.php',
            'includes/classes/class-competition-scheduler.php',
            'includes/classes/class-dynamic-field-detector.php',
            'includes/classes/class-form-integration.php',
            'includes/classes/class-prize-reseeder.php',
            'includes/classes/class-theme-manager.php',
            'includes/classes/class-brand-config.php',
            'includes/classes/class-play-limit.php',
            'includes/classes/class-access-control.php',
            'includes/classes/class-prize-sync.php',
            'includes/classes/class-dashboard-widgets.php',
        ];

        foreach ($classes as $class) {
            $file_path = BPM_PLUGIN_PATH . $class;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // Load global template helper functions
        $helpers = BPM_PLUGIN_PATH . 'includes/helpers.php';
        if (file_exists($helpers)) {
            require_once $helpers;
        }
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        add_menu_page(
            __('Promotion Manager', 'britvic-promo'),
            __('Promotions', 'britvic-promo'),
            'manage_options',
            'britvic-promotion-manager',
            [$this, 'render_dashboard_page'],
            'dashicons-tickets-alt',
            30
        );
        
        $subpages = [
            'competitions' => __('Competitions', 'britvic-promo'),
            'brand-config' => __('Brand Setup', 'britvic-promo'),
            'themes' => __('Theme Library', 'britvic-promo'),
            'form_assignment' => __('Form Mapping', 'britvic-promo'),
            'prizes' => __('Prizes', 'britvic-promo'),
            'notifications' => __('Email Notifications', 'britvic-promo'),
            'pages' => __('Page Control', 'britvic-promo'),
            'user_blocking' => __('User Rules', 'britvic-promo'),
            'audit' => __('Audit & Activity', 'britvic-promo'),
        ];
        
        foreach ($subpages as $slug => $title) {
            add_submenu_page(
                'britvic-promotion-manager',
                $title,
                $title,
                'manage_options',
                'britvic-' . $slug,
                [$this, 'render_' . str_replace('-', '_', $slug) . '_page']
            );
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts(string $hook): void {
        if (!str_contains($hook, 'britvic')) {
            return;
        }
        
        $dependencies = ['jquery', 'jquery-ui-core', 'jquery-ui-sortable'];
        
        // Add additional jQuery UI dependencies for prizes page
        if (str_contains($hook, 'prizes')) {
            $dependencies = array_merge($dependencies, [
                'jquery-ui-widget',
                'jquery-ui-mouse',
                'jquery-ui-draggable',
                'jquery-ui-droppable',
                'jquery-ui-dialog'
            ]);
        }
        
        wp_enqueue_script(
            'bpm-admin',
            BPM_PLUGIN_URL . 'assets/js/admin.js',
            $dependencies,
            BPM_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bpm-admin',
            BPM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BPM_VERSION
        );

        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
            [],
            '6.5.2'
        );
        
        // Enqueue jQuery UI CSS for dialog styling (for prizes page)
        if (str_contains($hook, 'prizes')) {
            wp_enqueue_style('wp-jquery-ui-dialog');
        }
        
        wp_localize_script('bpm-admin', 'bpmAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bpm_nonce'),
            'messages' => [
                'generating' => __('Generating prizes...', 'britvic-promo'),
                'success' => __('Operation completed successfully!', 'britvic-promo'),
                'error' => __('An error occurred. Please try again.', 'britvic-promo'),
            ],
        ]);
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('britvic-promo/v1', '/competitions', [
            'methods' => 'GET',
            'callback' => [$this, 'get_competitions_rest'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
        
        register_rest_route('britvic-promo/v1', '/prizes/(?P<competition_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_prizes_rest'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
    }
    
    /**
     * Check admin permissions for REST API
     */
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }
    
    /**
     * Handle AJAX prize generation with proper error handling
     */
    public function handle_ajax_generate_prizes(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $request_data = $this->validate_prize_generation_request();
            
            $generator = new BPM_Prize_Generator();
            $result = $generator->generate_prize_dates(
                $request_data['start_date'],
                $request_data['end_date'],
                $request_data['start_time'],
                $request_data['end_time'],
                $request_data['num_dates'],
                $request_data['prizes_data']
            );
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Validate prize generation request data
     */
    private function validate_prize_generation_request(): array {
        $required_fields = ['start_date', 'end_date', 'start_time', 'end_time', 'num_dates', 'prizes_data'];
        
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])) {
                throw new InvalidArgumentException(
                    sprintf(__('Missing required field: %s', 'britvic-promo'), $field)
                );
            }
        }
        
        return [
            'start_date' => sanitize_text_field($_POST['start_date']),
            'end_date' => sanitize_text_field($_POST['end_date']),
            'start_time' => sanitize_text_field($_POST['start_time']),
            'end_time' => sanitize_text_field($_POST['end_time']),
            'num_dates' => (int) $_POST['num_dates'],
            'prizes_data' => json_decode(wp_unslash($_POST['prizes_data']), true),
        ];
    }
    
    /**
     * Handle AJAX page toggling
     */
    public function handle_ajax_toggle_pages(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $action = sanitize_text_field($_POST['action_type'] ?? '');
            
            if (!$competition_id || !$action) {
                throw new InvalidArgumentException(__('Invalid request parameters', 'britvic-promo'));
            }
            
            $page_manager = new BPM_Page_Manager();
            $result = $page_manager->toggle_competition_pages($competition_id, $action);
            
            wp_send_json($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle CSV export
     */
    public function handle_ajax_export_csv(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }

            $competition_id = (int) ($_REQUEST['competition_id'] ?? 0);
            $export_type = sanitize_text_field($_REQUEST['export_type'] ?? '');

            $csv_handler = new BPM_CSV_Handler();
            $result = $csv_handler->export_data($competition_id, $export_type);

            // Stream the file for download
            $file_path = $result['file_path'];
            $filename = $result['filename'];

            if (!file_exists($file_path)) {
                throw new Exception(__('Export file not found', 'britvic-promo'));
            }

            // Clear any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set headers for file download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Output file contents
            readfile($file_path);
            exit;

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle CSV import
     */
    public function handle_ajax_import_csv(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            if (!isset($_FILES['csv_file'])) {
                throw new InvalidArgumentException(__('No file uploaded', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            
            // Fix double-escaped JSON by unescaping it first
            $column_mapping_raw = stripslashes($_POST['column_mapping'] ?? '{}');
            $custom_fields_raw = stripslashes($_POST['custom_fields'] ?? '{}');
            
            $column_mapping = json_decode($column_mapping_raw, true) ?: [];
            $custom_fields = json_decode($custom_fields_raw, true) ?: [];
            $import_type = sanitize_text_field($_POST['import_type'] ?? 'standard');
            
            $csv_handler = new BPM_CSV_Handler();
            $result = $csv_handler->import_prizes_with_mapping(
                $_FILES['csv_file'], 
                $competition_id,
                $column_mapping,
                $custom_fields,
                $import_type
            );
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
        wp_die();
    }
    
    /**
     * Handle XLSX file import with column mapping
     */
    public function handle_ajax_import_xlsx(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            if (!isset($_FILES['xlsx_file'])) {
                throw new InvalidArgumentException(__('No file uploaded', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $column_mapping = json_decode($_POST['column_mapping'] ?? '{}', true);
            
            $xlsx_handler = new BPM_XLSX_Handler();
            $result = $xlsx_handler->import_prizes($_FILES['xlsx_file'], $competition_id, $column_mapping);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
        wp_die();
    }
    
    /**
     * Analyze XLSX file and return headers and sample data for column mapping
     */
    public function handle_ajax_analyze_xlsx(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            if (!isset($_FILES['xlsx_file'])) {
                throw new InvalidArgumentException(__('No file uploaded', 'britvic-promo'));
            }
            
            $xlsx_handler = new BPM_XLSX_Handler();
            $analysis = $xlsx_handler->analyze_file($_FILES['xlsx_file']);
            
            wp_send_json_success($analysis);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
        wp_die();
    }
    
    /**
     * Analyze CSV file and return headers and sample data for column mapping
     */
    public function handle_ajax_analyze_csv(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            if (!isset($_FILES['csv_file'])) {
                throw new InvalidArgumentException(__('No file uploaded', 'britvic-promo'));
            }
            
            // Simple CSV analysis without external dependencies
            $file_path = $_FILES['csv_file']['tmp_name'];
            $file_handle = fopen($file_path, 'r');
            
            if (!$file_handle) {
                throw new Exception(__('Cannot read CSV file', 'britvic-promo'));
            }
            
            // Read headers (first row)
            $headers = fgetcsv($file_handle);
            if (!$headers) {
                throw new Exception(__('CSV file appears to be empty or invalid', 'britvic-promo'));
            }
            
            // Read sample data (next 5 rows)
            $sample_data = [];
            for ($i = 0; $i < 5; $i++) {
                $row = fgetcsv($file_handle);
                if ($row !== false) {
                    $sample_data[] = $row;
                }
            }
            
            // Count total rows (rewind and count all data rows)
            rewind($file_handle);
            fgetcsv($file_handle); // Skip header row
            $total_rows = 0;
            while (fgetcsv($file_handle) !== false) {
                $total_rows++;
            }
            
            fclose($file_handle);
            
            wp_send_json_success([
                'headers' => $headers,
                'sample_data' => $sample_data,
                'total_rows' => $total_rows
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
        wp_die();
    }
    
    /**
     * Handle updating individual prize fields
     */
    public function handle_ajax_update_prize_field(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $prize_id = (int) ($_POST['prize_id'] ?? 0);
            $field = sanitize_text_field($_POST['field'] ?? '');
            $value = sanitize_text_field($_POST['value'] ?? '');
            
            if (!$prize_id || !$field) {
                throw new Exception(__('Invalid parameters', 'britvic-promo'));
            }
            
            // Check if post exists
            $post = get_post($prize_id);
            if (!$post) {
                throw new Exception(__('Prize not found', 'britvic-promo'));
            }
            
            // Map frontend field names to meta keys
            $field_map = [
                'code' => 'code',
                'prize' => 'prize', 
                'value' => 'value',
                'date' => 'date',
                'win_date_auto' => 'date',
                'win_date_from_file' => 'date',
                'prize_date' => 'date',
                'date_available' => 'date_available', // This maps directly to prize_entries table column
                'winner_id' => 'claimed_by_user_id',
                'winner_email' => 'claimed_by_email'
            ];
            
            if (!isset($field_map[$field])) {
                throw new Exception(__('Invalid field', 'britvic-promo'));
            }
            
            $meta_key = $field_map[$field];
            $updated  = false;

            // Route any aliased field (date / prize name / code / value)
            // through BPM_Prize_Sync so every storage location (postmeta,
            // mapped_data JSON, bpm_prize_entries) stays in lockstep.
            $date_aliases       = ['date', 'win_date_auto', 'win_date_from_file', 'prize_date', 'date_available'];
            $prize_name_aliases = ['prize', 'prize_name_from_distribution', 'prize_name_from_file'];

            if ( in_array( $field, $date_aliases, true ) && $value ) {
                // BPM_Prize_Sync::write_date normalises to MySQL DATETIME.
                $updated = class_exists( 'BPM_Prize_Sync' )
                    && BPM_Prize_Sync::write_date( $prize_id, (string) $value );
            } elseif ( in_array( $field, $prize_name_aliases, true ) ) {
                $updated = class_exists( 'BPM_Prize_Sync' )
                    && BPM_Prize_Sync::write_prize_name( $prize_id, (string) $value );
            } elseif ( $field === 'code' ) {
                $updated = class_exists( 'BPM_Prize_Sync' )
                    && BPM_Prize_Sync::write_code( $prize_id, (string) $value );
            } elseif ( $field === 'value' ) {
                $updated = class_exists( 'BPM_Prize_Sync' )
                    && BPM_Prize_Sync::write_value( $prize_id, (string) $value );
            } else {
                $updated = update_post_meta( $prize_id, $meta_key, $value );
            }
            
            // For winner fields, also update related fields and claim status
            if ($field === 'winner_id' || $field === 'winner_email') {
                // If setting winner info, mark as claimed
                if ($value) {
                    update_post_meta($prize_id, 'claimed', 'true');
                    update_post_meta($prize_id, 'claimed_at', current_time('mysql', true));
                    if ($field === 'winner_id') {
                        update_post_meta($prize_id, 'gravity_form_entry_id', $value);
                    }
                }
            }

            // For date fields (except date_available which is already handled), explicitly sync to prize_entries table
            if (in_array($field, ['date', 'win_date_auto', 'win_date_from_file', 'prize_date'])) {
                if (function_exists('bpm_sync_prize_date')) {
                    bpm_sync_prize_date($prize_id);
                } elseif (function_exists('bmp_update_prize_date_available')) {
                    bmp_update_prize_date_available($prize_id, $value);
                }
            }

            // Mirror every supported inline edit into the bpm_prize_entries row
            // so the DB stays in sync with postmeta. Date fields are already
            // handled above; this block covers code / prize_type / prize_name
            // and the winner fields.
            $entries_field_map = [
                'code'          => 'code',
                'prize'         => 'prize_type',
                'value'         => 'prize_name',
                'winner_id'     => 'gravity_form_entry_id',
                'winner_email'  => 'claimed_by_email',
            ];

            if (isset($entries_field_map[$field])) {
                $entries_table  = $wpdb->prefix . 'bpm_prize_entries';
                $entries_column = $entries_field_map[$field];

                $entries_data    = [ $entries_column => $value, 'updated_at' => current_time('mysql') ];
                $entries_formats = [ '%s', '%s' ];

                // Setting a winner via inline edit should also mark the row claimed,
                // matching the postmeta side.
                if (in_array($field, ['winner_id', 'winner_email'], true) && $value !== '') {
                    $entries_data['claimed']    = 1;
                    $entries_data['claimed_at'] = current_time('mysql');
                    $entries_formats[]          = '%d';
                    $entries_formats[]          = '%s';
                }

                $wpdb->update(
                    $entries_table,
                    $entries_data,
                    [ 'prize_post_id' => $prize_id ],
                    $entries_formats,
                    [ '%d' ]
                );
            }
            
            wp_send_json_success([
                'message' => __('Field updated successfully', 'britvic-promo'),
                'field' => $field,
                'value' => $value
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
        wp_die();
    }
    
    /**
     * Handle toggling prize claim status
     */
    public function handle_ajax_toggle_prize_claim(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $prize_id = (int) ($_POST['prize_id'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? '');
            
            if (!$prize_id || !in_array($status, ['claimed', 'available'])) {
                throw new Exception(__('Invalid parameters', 'britvic-promo'));
            }
            
            // Update the claim status
            if ($status === 'claimed') {
                update_post_meta($prize_id, 'claimed', 'true');
                update_post_meta($prize_id, 'claimed_at', current_time('mysql', true));
            } else {
                update_post_meta($prize_id, 'claimed', 'false');
                delete_post_meta($prize_id, 'claimed_at');
                delete_post_meta($prize_id, 'claimed_by_user_id');
                delete_post_meta($prize_id, 'claimed_by_email');
                delete_post_meta($prize_id, 'gravity_form_entry_id');
            }
            
            // Get updated claimed count for this competition
            $competition_id = get_post_meta($prize_id, 'competition_id', true);
            $claimed_count = 0;
            if ($competition_id) {
                $claimed_prizes = get_posts([
                    'post_type' => 'prize',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        'relation' => 'AND',
                        [
                            'key' => 'competition_id',
                            'value' => $competition_id,
                            'compare' => '='
                        ],
                        [
                            'key' => 'claimed',
                            'value' => 'true',
                            'compare' => '='
                        ]
                    ],
                    'fields' => 'ids'
                ]);
                $claimed_count = count($claimed_prizes);
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Prize marked as %s', 'britvic-promo'), $status),
                'status' => $status,
                'claimed_count' => $claimed_count
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
        wp_die();
    }
    
    /**
     * Handle resetting prize claims
     */
    public function handle_ajax_reset_prize_claim(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $prize_id = (int) ($_POST['prize_id'] ?? 0);
            
            if (!$prize_id) {
                throw new InvalidArgumentException(__('Invalid prize ID', 'britvic-promo'));
            }
            
            // Reset claim status and clear winner data in wp_postmeta
            update_post_meta($prize_id, 'claimed', 'false');
            delete_post_meta($prize_id, 'claimed_at');
            delete_post_meta($prize_id, 'claimed_by_user_id');
            delete_post_meta($prize_id, 'claimed_by_email');
            delete_post_meta($prize_id, 'gravity_form_entry_id');
            
            // Also clear claim data in wp_bpm_prize_entries table
            global $wpdb;
            $table_name = $wpdb->prefix . 'bpm_prize_entries';
            
            $wpdb->update(
                $table_name,
                [
                    'claimed' => 0,
                    'claimed_at' => null,
                    'claimed_by_user_id' => null,
                    'claimed_by_email' => null,
                    'gravity_form_entry_id' => null
                ],
                ['prize_post_id' => $prize_id],
                ['%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            // Log the reset
            BPM_Audit_Logger::log(
                (int) (get_post_meta($prize_id, 'competition_id', true) ?: 0),
                'prize_claim_reset',
                sprintf('Prize %d claim reset', $prize_id),
                'info',
                ['prize_id' => $prize_id, 'user_id' => get_current_user_id()]
            );
            
            wp_send_json_success([
                'message' => __('Prize claim reset successfully', 'britvic-promo')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle resetting column preferences
     */
    public function handle_ajax_reset_column_preferences(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            // Delete the user column preferences for this competition
            $option_name = 'bpm_column_prefs_' . get_current_user_id() . '_' . $competition_id;
            delete_option($option_name);
            
            wp_send_json_success([
                'message' => __('Column preferences reset successfully', 'britvic-promo')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle getting prize history
     */
    public function handle_ajax_get_prize_history(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $prize_id = (int) ($_POST['prize_id'] ?? 0);
            
            if (!$prize_id) {
                throw new InvalidArgumentException(__('Invalid prize ID', 'britvic-promo'));
            }
            
            $reseeder = new BPM_Prize_Reseeder();
            $history = $reseeder->get_prize_history($prize_id);
            
            wp_send_json_success($history);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle AJAX request for getting reseed information (unclaimed count and current date ranges)
     */
    public function handle_ajax_get_reseed_info(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            $reseeder = new BPM_Prize_Reseeder();
            $unclaimed_prizes = $reseeder->get_unclaimed_prizes($competition_id);
            $reseeding_stats = $reseeder->get_reseeding_stats($competition_id);
            
            // Get competition details for default date range suggestions
            global $wpdb;
            $competition = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}bpm_competitions WHERE id = %d",
                    $competition_id
                ),
                ARRAY_A
            );
            
            $result = [
                'unclaimed_count' => count($unclaimed_prizes),
                'total_prizes' => $reseeding_stats['total_prizes'],
                'reseeded_count' => $reseeding_stats['reseeded_prizes'],
                'competition_name' => $competition['name'] ?? 'Unknown',
                'competition_start' => $competition['start_date'] ?? date('Y-m-d'),
                'competition_end' => $competition['end_date'] ?? date('Y-m-d', strtotime('+30 days')),
                'suggested_start' => date('Y-m-d'),
                'suggested_end' => date('Y-m-d', strtotime('+30 days'))
            ];
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Handle prize reseeding
     */
    public function handle_ajax_reseed_prizes(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            // Get date range parameters from the dialog
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            $end_date = sanitize_text_field($_POST['end_date'] ?? '');
            $start_time = sanitize_text_field($_POST['start_time'] ?? '09:00');
            $end_time = sanitize_text_field($_POST['end_time'] ?? '17:00');
            
            if (empty($start_date) || empty($end_date)) {
                throw new InvalidArgumentException(__('Start and end dates are required', 'britvic-promo'));
            }
            
            // Prepare options for reseeder
            $reseed_options = [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'start_time' => $start_time,
                'end_time' => $end_time
            ];

            $reseeder = new BPM_Prize_Reseeder();

            // If specific prize IDs were supplied, reseed only those; otherwise reseed all unclaimed.
            $raw_ids  = isset( $_POST['prize_ids'] ) && is_array( $_POST['prize_ids'] ) ? $_POST['prize_ids'] : [];
            $prize_ids = array_filter( array_map( 'intval', $raw_ids ) );

            if ( ! empty( $prize_ids ) ) {
                $partial = $reseeder->reseed_prize_ids( $prize_ids, $competition_id, $reseed_options );
                $result  = array_merge( [ 'success' => true ], $partial );
            } else {
                $result = $reseeder->reseed_unclaimed_prizes( $competition_id, $reseed_options );
            }
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
    
    /**
     * Publish competition via cron
     */
    public function publish_competition(int $competition_id): void {
        try {
            $page_manager = new BPM_Page_Manager();
            $page_manager->toggle_competition_pages($competition_id, 'publish');
            
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_published',
                'Competition automatically published via scheduled cron'
            );
            
        } catch (Exception $e) {
        }
    }
    
    /**
     * Close competition via cron
     */
    public function close_competition(int $competition_id): void {
        try {
            $page_manager = new BPM_Page_Manager();
            $page_manager->toggle_competition_pages($competition_id, 'close');
            
            BPM_Audit_Logger::log(
                $competition_id,
                'competition_closed',
                'Competition automatically closed via scheduled cron'
            );
            
        } catch (Exception $e) {
        }
    }
    
    /**
     * Check for scheduled competitions
     */
    public function check_scheduled_competitions(): void {
        $scheduler = new BPM_Competition_Scheduler();
        $scheduler->process_scheduled_competitions();
    }
    
    /**
     * Render admin pages
     */
    public function render_dashboard_page(): void {
        $this->render_admin_page('dashboard');
    }
    
    public function render_competitions_page(): void {
        $this->render_admin_page('competitions');
    }
    
    public function render_form_assignment_page(): void {
        $this->render_admin_page('form_assignment');
    }
    
    public function render_prizes_page(): void {
        $this->render_admin_page('prizes');
    }
    
    public function render_pages_page(): void {
        $this->render_admin_page('pages');
    }
    

    public function render_notifications_page(): void {
        $this->render_admin_page('notifications');
    }
    
    public function render_user_blocking_page(): void {
        $this->render_admin_page('user_blocking');
    }
    
    public function render_audit_page(): void {
        $this->render_admin_page('audit');
    }
    
    public function render_themes_page(): void {
        $this->render_admin_page('themes');
    }

    public function render_brand_config_page(): void {
        // Ensure media uploader is available
        wp_enqueue_media();
        $this->render_admin_page('brand-config');
    }
    
    /**
     * Generic admin page renderer
     */
    private function render_admin_page(string $page): void {
        $file_path = BPM_PLUGIN_PATH . "includes/admin/{$page}.php";
        if (file_exists($file_path)) {
            include_once $file_path;
        } else {
            wp_die(__('Admin page not found.', 'britvic-promo'));
        }
    }
    
    /**
     * REST API endpoints
     */
    public function get_competitions_rest(WP_REST_Request $request): WP_REST_Response {
        // Implementation for REST endpoint
        return new WP_REST_Response(['competitions' => []], 200);
    }
    
    public function get_prizes_rest(WP_REST_Request $request): WP_REST_Response {
        $competition_id = $request->get_param('competition_id');
        // Implementation for REST endpoint
        return new WP_REST_Response(['prizes' => []], 200);
    }
    
    /**
     * Create database tables if they don't exist
     */
    private function maybe_create_tables(): void {
        global $wpdb;
        
        $installed_version = get_option('bpm_db_version', '0');
        
        if (version_compare($installed_version, BPM_VERSION, '<')) {
            $this->create_tables();
            update_option('bpm_db_version', BPM_VERSION);
        }
    }
    
    /**
     * Create plugin database tables
     */
    private function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Competitions table
        $competitions_table = $wpdb->prefix . 'bpm_competitions';
        $competitions_sql = "CREATE TABLE $competitions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            type enum('instant_win','prize_draw') NOT NULL DEFAULT 'instant_win',
            status enum('draft','scheduled','active','closed') NOT NULL DEFAULT 'draft',
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            live_page_id bigint(20) DEFAULT NULL,
            closed_page_id bigint(20) DEFAULT NULL,
            settings longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_type (type)
        ) $charset_collate;";
        
        // Audit log table
        $audit_table = $wpdb->prefix . 'bpm_audit_log';
        $audit_sql = "CREATE TABLE $audit_table (
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
            INDEX idx_created (created_at),
            FOREIGN KEY fk_competition (competition_id) REFERENCES $competitions_table(id) ON DELETE SET NULL
        ) $charset_collate;";
        
        // Prize entries table (for tracking individual prize entries)
        $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';
        $prize_entries_sql = "CREATE TABLE $prize_entries_table (
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
            original_data longtext DEFAULT NULL,
            audit_trail longtext DEFAULT NULL,
            reseeded tinyint(1) NOT NULL DEFAULT 0,
            reseeded_count int NOT NULL DEFAULT 0,
            last_reseeded datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_unique_id (unique_id),
            UNIQUE KEY idx_code (code),
            INDEX idx_competition (competition_id),
            INDEX idx_prize_post (prize_post_id),
            INDEX idx_date_available (date_available),
            INDEX idx_claimed (claimed),
            INDEX idx_prize_type (prize_type),
            INDEX idx_claimed_email (claimed_by_email),
            INDEX idx_reservation_expires (reservation_expires_at),
            INDEX idx_stripe_payout_status (stripe_payout_status),
            INDEX idx_reseeded (reseeded),
            FOREIGN KEY fk_prize_competition (competition_id) REFERENCES $competitions_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Competition settings table (for flexible configuration storage)
        $settings_table = $wpdb->prefix . 'bpm_competition_settings';
        $settings_sql = "CREATE TABLE $settings_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            competition_id mediumint(9) NOT NULL,
            setting_key varchar(100) NOT NULL,
            setting_value longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_competition_setting (competition_id, setting_key),
            INDEX idx_setting_key (setting_key),
            FOREIGN KEY fk_setting_competition (competition_id) REFERENCES $competitions_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Create tables
        dbDelta($competitions_sql);
        dbDelta($audit_sql);
        dbDelta($prize_entries_sql);
        dbDelta($settings_sql);
        
        // Log table creation
    }
    
    /**
     * Ensure promotions directory exists with security
     */
    public function ensure_promotions_directory(): void {
        $promotions_dir = WP_CONTENT_DIR . '/promotions';
        
        if (!file_exists($promotions_dir)) {
            wp_mkdir_p($promotions_dir);
            
            // Create .htaccess to deny direct access
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($promotions_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($promotions_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate(): void {
        $this->maybe_create_tables();
        
        // Run database migrations
        BPM_Database_Manager::run_migrations();
        
        // Schedule cron events
        if (!wp_next_scheduled('bpm_check_competitions')) {
            wp_schedule_event(time(), 'hourly', 'bpm_check_competitions');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Create secure promotions directory
        $this->ensure_promotions_directory();
        
        // Log activation
        BPM_Audit_Logger::log(0, 'plugin_activated', 'Britvic Promotion Manager plugin activated');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('bpm_check_competitions');
        wp_clear_scheduled_hook('bpm_publish_competition');
        wp_clear_scheduled_hook('bpm_close_competition');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        BPM_Audit_Logger::log(0, 'plugin_deactivated', 'Britvic Promotion Manager plugin deactivated');
    }
    
    /**
     * PHP version notice
     */
    public function php_version_notice(): void {
        $message = sprintf(
            __('Britvic Promotion Manager requires PHP %s or higher. You are running PHP %s.', 'britvic-promo'),
            BPM_MIN_PHP_VERSION,
            PHP_VERSION
        );
        
        printf('<div class="error"><p>%s</p></div>', esc_html($message));
    }
    
    /**
     * WordPress version notice
     */
    public function wp_version_notice(): void {
        $message = sprintf(
            __('Britvic Promotion Manager requires WordPress %s or higher. You are running WordPress %s.', 'britvic-promo'),
            BPM_MIN_WP_VERSION,
            get_bloginfo('version')
        );
        
        printf('<div class="error"><p>%s</p></div>', esc_html($message));
    }
    
    /**
     * Handle saving email templates for a competition
     */
    public function handle_ajax_save_email_templates(): void {
        try {
            check_ajax_referer('bpm_save_email_templates', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $templates_data = $_POST['templates'] ?? [];
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            // Handle both JSON string and array formats
            if (is_string($templates_data)) {
                $templates = json_decode(wp_unslash($templates_data), true);
            } else {
                $templates = $templates_data; // Already an array
            }
            
            if (!is_array($templates)) {
                throw new InvalidArgumentException(__('Invalid templates data', 'britvic-promo'));
            }
            
            // Validate templates
            $validated_templates = [];
            foreach ($templates as $template) {
                if (empty($template['prize_match']) || empty($template['email_subject']) || empty($template['email_template'])) {
                    continue;
                }
                
                $validated_templates[] = [
                    'prize_match' => sanitize_text_field($template['prize_match']),
                    'email_subject' => sanitize_text_field($template['email_subject']),
                    'email_template' => wp_kses_post($template['email_template'])
                ];
            }
            
            // Save templates as option specific to competition
            $option_name = "bpm_email_templates_{$competition_id}";
            $updated = update_option($option_name, $validated_templates);
            
            // Log the update
            BPM_Audit_Logger::log(
                $competition_id,
                'email_templates_updated',
                sprintf('Email templates updated for competition %d (%d templates)', $competition_id, count($validated_templates)),
                'info',
                [
                    'competition_id' => $competition_id,
                    'template_count' => count($validated_templates),
                    'templates' => $validated_templates,
                    'user_id' => get_current_user_id()
                ]
            );
            
            wp_send_json_success([
                'message' => __('Email templates saved successfully', 'britvic-promo'),
                'template_count' => count($validated_templates)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle sending test email
     */
    public function handle_ajax_send_test_email(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $test_prize = sanitize_text_field($_POST['test_prize'] ?? '');
            $test_email = sanitize_email($_POST['test_email'] ?? '');
            
            if (!$competition_id || !$test_prize || !$test_email) {
                throw new InvalidArgumentException(__('Missing required parameters', 'britvic-promo'));
            }
            
            // Get email templates for this competition
            $templates = get_option("bpm_email_templates_{$competition_id}", []);
            
            $matched_template = null;
            foreach ($templates as $template) {
                if (stripos($test_prize, $template['prize_match']) !== false) {
                    $matched_template = $template;
                    break;
                }
            }
            
            if (!$matched_template) {
                throw new Exception(__('No matching email template found for this prize', 'britvic-promo'));
            }
            
            // Create test placeholders
            $placeholders = [
                '{prize_name}' => $test_prize,
                '{prize_value}' => '£10.00',
                '{winner_name}' => 'Test Winner',
                '{code}' => 'TEST-12345',
                '{claim_instructions}' => 'Please visit our website to claim your prize.',
                '{winner_email}' => $test_email,
                '{entry_date}' => date('d/m/Y H:i'),
                '{competition_name}' => 'Test Competition',
                '{site_name}' => get_bloginfo('name'),
                '{site_url}' => get_bloginfo('url')
            ];
            
            // Replace placeholders in subject and body
            $subject = str_replace(array_keys($placeholders), array_values($placeholders), $matched_template['email_subject']);
            $body = str_replace(array_keys($placeholders), array_values($placeholders), $matched_template['email_template']);
            
            // Send test email
            $sent = wp_mail($test_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            
            if ($sent) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'test_email_sent',
                    sprintf('Test email sent to %s for prize: %s', $test_email, $test_prize),
                    'info',
                    [
                        'competition_id' => $competition_id,
                        'test_email' => $test_email,
                        'test_prize' => $test_prize,
                        'template_used' => $matched_template['prize_match'],
                        'user_id' => get_current_user_id()
                    ]
                );
                
                wp_send_json_success([
                    'message' => __('Test email sent successfully', 'britvic-promo')
                ]);
            } else {
                throw new Exception(__('Failed to send test email', 'britvic-promo'));
            }
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle bulk delete prizes AJAX request
     */
    public function handle_ajax_bulk_delete_prizes(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $delete_type = sanitize_text_field($_POST['delete_type'] ?? '');
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            if (!in_array($delete_type, ['all', 'claimed', 'unclaimed'])) {
                throw new InvalidArgumentException(__('Invalid delete type', 'britvic-promo'));
            }
            
            $args = [
                'post_type' => 'prize',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'competition_id',
                        'value' => $competition_id,
                        'compare' => '='
                    ]
                ]
            ];
            
            // Add additional filters based on delete type
            if ($delete_type === 'claimed') {
                $args['meta_query'][] = [
                    'key' => 'claimed',
                    'value' => 'true',
                    'compare' => '='
                ];
            } elseif ($delete_type === 'unclaimed') {
                $args['meta_query']['relation'] = 'AND';
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    [
                        'key' => 'claimed',
                        'value' => 'false',
                        'compare' => '='
                    ],
                    [
                        'key' => 'claimed',
                        'compare' => 'NOT EXISTS'
                    ]
                ];
            }
            
            $prizes = get_posts($args);
            $deleted_count = 0;
            
            foreach ($prizes as $prize) {
                // Also delete from custom table
                global $wpdb;
                $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';
                $wpdb->delete($prize_entries_table, ['prize_post_id' => $prize->ID]);
                
                // Delete the post
                if (wp_delete_post($prize->ID, true)) {
                    $deleted_count++;
                }
            }
            
            // Log the bulk delete
            BPM_Audit_Logger::log(
                $competition_id,
                'prizes_bulk_deleted',
                sprintf('Bulk deleted %d %s prizes', $deleted_count, $delete_type),
                'warning',
                [
                    'competition_id' => $competition_id,
                    'delete_type' => $delete_type,
                    'deleted_count' => $deleted_count
                ]
            );
            
            wp_send_json_success([
                'message' => sprintf(__('Successfully deleted %d prizes', 'britvic-promo'), $deleted_count),
                'deleted_count' => $deleted_count
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX: delete a single prize by its prize_entries.id (primary key).
     * Removes both the prize_entries row and the corresponding WP prize post.
     */
    public function handle_ajax_delete_prize(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }

            $prize_post_id = (int) ($_POST['prize_id'] ?? 0);
            if ($prize_post_id <= 0) {
                throw new InvalidArgumentException(__('Invalid prize ID', 'britvic-promo'));
            }

            global $wpdb;
            $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, prize_post_id, competition_id, code FROM {$prize_entries_table} WHERE prize_post_id = %d",
                $prize_post_id
            ), ARRAY_A);

            $entry_id      = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            $competition_id = is_array($row) ? (int) ($row['competition_id'] ?? 0) : 0;
            $code          = is_array($row) ? (string) ($row['code'] ?? '') : '';

            $post = get_post($prize_post_id);
            if (!$row && (!$post || $post->post_type !== 'prize')) {
                throw new Exception(__('Prize not found', 'britvic-promo'));
            }

            if ($entry_id > 0) {
                $deleted = $wpdb->delete($prize_entries_table, ['id' => $entry_id], ['%d']);
                if ($deleted === false) {
                    throw new Exception(__('Failed to delete prize entry', 'britvic-promo'));
                }
            }

            if ($post && $post->post_type === 'prize') {
                wp_delete_post($prize_post_id, true);
            }

            BPM_Audit_Logger::log(
                $competition_id,
                'prize_deleted',
                sprintf('Deleted prize entry id=%d post_id=%d code=%s', $entry_id, $prize_post_id, $code),
                'warning',
                [
                    'competition_id' => $competition_id,
                    'prize_id'       => $entry_id,
                    'prize_post_id'  => $prize_post_id,
                    'code'           => $code,
                    'user_id'        => get_current_user_id(),
                ]
            );

            wp_send_json_success([
                'message'  => __('Prize deleted', 'britvic-promo'),
                'prize_id' => $prize_post_id,
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle manual single prize creation AJAX request
     */
    public function handle_ajax_add_single_prize(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }

            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            if ($competition_id <= 0) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }

            $prize_codes_raw = $_POST['prize_codes'] ?? [];
            $prize_codes = [];
            if (is_array($prize_codes_raw)) {
                foreach ($prize_codes_raw as $code) {
                    $clean_code = sanitize_text_field((string) $code);
                    if ($clean_code !== '') {
                        $prize_codes[] = $clean_code;
                    }
                }
            }

            if (empty($prize_codes)) {
                throw new InvalidArgumentException(__('Please provide at least one prize code', 'britvic-promo'));
            }

            $prize_category = sanitize_text_field((string) ($_POST['prize_category'] ?? ''));
            $prize_name_raw = sanitize_text_field((string) ($_POST['prize_type'] ?? ''));
            $win_datetime_raw = sanitize_text_field((string) ($_POST['win_datetime'] ?? ''));
            $initial_status = sanitize_text_field((string) ($_POST['initial_status'] ?? 'available'));
            $source_route_raw = strtolower(trim((string) ($_POST['source_route'] ?? 'both')));
            $source_route = in_array($source_route_raw, ['main', 'alt', 'both'], true) ? $source_route_raw : 'both';
            $winner_email = sanitize_email((string) ($_POST['winner_email'] ?? ''));
            $winner_id = (int) ($_POST['winner_id'] ?? 0);

            if ($prize_category === '') {
                throw new InvalidArgumentException(__('Prize type is required', 'britvic-promo'));
            }

            if ($prize_name_raw === '') {
                throw new InvalidArgumentException(__('Prize name is required', 'britvic-promo'));
            }

            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $win_datetime_raw);
            if (!$dt) {
                throw new InvalidArgumentException(__('Invalid win date/time format', 'britvic-promo'));
            }
            $win_datetime = $dt->format('Y-m-d H:i:s');

            $is_claimed = $initial_status === 'claimed';

            global $wpdb;
            $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';
            $created_count = 0;
            $created_codes = [];

            foreach ($prize_codes as $code) {
                $existing_code = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prize_entries_table} WHERE code = %s",
                    $code
                ));
                if ($existing_code > 0) {
                    throw new InvalidArgumentException(
                        sprintf(__('Prize code already exists: %s', 'britvic-promo'), $code)
                    );
                }

                $unique_id = wp_generate_uuid4();
                $prize_label = $prize_name_raw;

                $post_id = wp_insert_post([
                    'post_title' => sprintf('Prize %s - %s', $unique_id, $dt->format('d/m/Y H:i')),
                    'post_type' => 'prize',
                    'post_status' => 'publish',
                    'meta_input' => [
                        'date' => $win_datetime,
                        'prize' => $prize_label,
                        'code' => $code,
                        'claimed' => $is_claimed ? 'true' : 'false',
                        'competition_id' => $competition_id,
                        'unique_id' => $unique_id,
                        'claimed_at' => $is_claimed ? current_time('mysql') : '',
                        'claimed_by_email' => $is_claimed ? $winner_email : '',
                        'claimed_by_user_id' => $is_claimed ? $winner_id : '',
                        'original_timestamp' => $dt->getTimestamp(),
                    ],
                ], true);

                if (is_wp_error($post_id)) {
                    throw new Exception('Failed to create prize post: ' . $post_id->get_error_message());
                }

                $original_data = [
                    'unique_id' => $unique_id,
                    'code' => $code,
                    'prize_type_from_file' => $prize_category,
                    'prize_name_from_file' => $prize_label,
                    'win_date_auto' => $win_datetime,
                    'date' => $win_datetime,
                ];

                $insert_result = $wpdb->insert(
                    $prize_entries_table,
                    [
                        'competition_id' => $competition_id,
                        'prize_post_id' => $post_id,
                        'unique_id' => $unique_id,
                        'code' => $code,
                        'prize_type' => $prize_category,
                        'prize_name' => $prize_label,
                        'date_available' => $win_datetime,
                        'claimed' => $is_claimed ? 1 : 0,
                        'claimed_at' => $is_claimed ? current_time('mysql') : null,
                        'claimed_by_user_id' => $is_claimed ? $winner_id : null,
                        'claimed_by_email' => $is_claimed ? $winner_email : null,
                        'source_route' => $source_route,
                        'original_data' => wp_json_encode($original_data),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ],
                    [
                        '%d', '%d', '%s', '%s', '%s', '%s', '%s',
                        '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s'
                    ]
                );

                if ($insert_result === false) {
                    wp_delete_post($post_id, true);
                    throw new Exception('Failed to create prize entry: ' . ($wpdb->last_error ?: 'unknown database error'));
                }

                $created_count++;
                $created_codes[] = $code;
            }

            BPM_Audit_Logger::log(
                $competition_id,
                'single_prize_added',
                sprintf('Added %d manual prize(s)', $created_count),
                'info',
                [
                    'competition_id' => $competition_id,
                    'created_count' => $created_count,
                    'prize_type' => $prize_category,
                    'prize_name' => $prize_label,
                    'codes' => $created_codes,
                    'claimed' => $is_claimed,
                    'user_id' => get_current_user_id(),
                ]
            );

            wp_send_json_success([
                'message' => sprintf(__('Added %d prize(s) successfully', 'britvic-promo'), $created_count),
                'created_count' => $created_count,
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle preview email template AJAX request
     */
    public function handle_ajax_preview_email_template(): void {
        try {
            check_ajax_referer('bpm_preview_email', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $test_prize = sanitize_text_field($_POST['test_prize'] ?? '');
            $test_email = sanitize_email($_POST['test_email'] ?? '');
            
            if (!$competition_id || !$test_prize || !$test_email) {
                throw new InvalidArgumentException(__('Missing required parameters', 'britvic-promo'));
            }
            
            // Get email templates for this competition
            $templates = get_option("bpm_email_templates_{$competition_id}", []);
            
            $matched_template = null;
            foreach ($templates as $template) {
                if (stripos($test_prize, $template['prize_match']) !== false) {
                    $matched_template = $template;
                    break;
                }
            }
            
            if (!$matched_template) {
                throw new Exception(__('No matching email template found for this prize', 'britvic-promo'));
            }
            
            // Get dynamic placeholders based on prize data
            $placeholders = [];
            
            // Get sample prize data for placeholders
            $sample_prizes = get_posts([
                'post_type' => 'prize',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => 'competition_id',
                        'value' => $competition_id,
                        'compare' => '='
                    ]
                ]
            ]);
            
            if (!empty($sample_prizes)) {
                $sample_prize = $sample_prizes[0];
                
                // Get all meta data
                $meta_data = get_post_meta($sample_prize->ID);
                foreach ($meta_data as $key => $value) {
                    $placeholders['{' . $key . '}'] = is_array($value) ? $value[0] : $value;
                }
                
                // Get mapped data
                $mapped_data_json = get_post_meta($sample_prize->ID, 'mapped_data', true);
                if ($mapped_data_json) {
                    $mapped_data = json_decode($mapped_data_json, true);
                    if (is_array($mapped_data)) {
                        foreach ($mapped_data as $key => $value) {
                            if (!in_array($key, ['win_date_from_file', 'win_date_auto'])) {
                                $placeholders['{' . $key . '}'] = $value;
                            }
                        }
                    }
                }
            }
            
            // Add fallback placeholders with sample data
            $default_placeholders = [
                '{code}' => 'SAMPLE-12345',
                '{prize}' => $test_prize,
                '{value}' => '£10.00',
                '{date}' => date('d/m/Y H:i'),
                '{winner_name}' => 'Sample Winner',
                '{winner_email}' => $test_email,
                '{entry_date}' => date('d/m/Y H:i'),
                '{competition_name}' => 'Sample Competition',
                '{site_name}' => get_bloginfo('name'),
                '{site_url}' => get_bloginfo('url')
            ];
            
            // Merge placeholders (actual data takes precedence)
            $placeholders = array_merge($default_placeholders, $placeholders);
            
            // Replace placeholders in subject and body and track which ones were actually used
            $original_subject = $matched_template['email_subject'];
            $original_body = $matched_template['email_template'];
            
            $subject = str_replace(array_keys($placeholders), array_values($placeholders), $original_subject);
            $body = str_replace(array_keys($placeholders), array_values($placeholders), $original_body);
            
            // Find which placeholders were actually used
            $used_placeholders = [];
            foreach ($placeholders as $placeholder => $value) {
                if (strpos($original_subject, $placeholder) !== false || strpos($original_body, $placeholder) !== false) {
                    $used_placeholders[] = $placeholder;
                }
            }
            
            wp_send_json_success([
                'subject' => $subject,
                'body' => $body,
                'placeholders_used' => $used_placeholders
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle saving generation prize types AJAX request
     */
    public function handle_ajax_save_generation_prize_types(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            $prize_types_raw = $_POST['prize_types'] ?? [];
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            if (!is_array($prize_types_raw)) {
                throw new InvalidArgumentException(__('Invalid prize types data', 'britvic-promo'));
            }
            
            // Validate and sanitize prize types
            $prize_types = [];
            foreach ($prize_types_raw as $prize_type) {
                if (isset($prize_type['name']) && !empty(trim($prize_type['name']))) {
                    $prize_types[] = [
                        'name' => sanitize_text_field(trim($prize_type['name']))
                    ];
                }
            }
            
            if (empty($prize_types)) {
                throw new InvalidArgumentException(__('Please add at least one prize type', 'britvic-promo'));
            }
            
            // Save prize types as option specific to competition
            $option_name = "bpm_generation_prize_types_{$competition_id}";
            $updated = update_option($option_name, $prize_types);
            
            // Log the update
            BPM_Audit_Logger::log(
                $competition_id,
                'generation_prize_types_updated',
                sprintf('Generation prize types updated for competition %d (%d types)', $competition_id, count($prize_types)),
                'info',
                [
                    'competition_id' => $competition_id,
                    'prize_types_count' => count($prize_types),
                    'prize_types' => $prize_types,
                    'user_id' => get_current_user_id()
                ]
            );
            
            wp_send_json_success([
                'message' => __('Prize types saved successfully', 'britvic-promo'),
                'prize_types' => $prize_types,
                'count' => count($prize_types)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle AJAX bulk import of user blocks
     */
    public function handle_ajax_bulk_import_blocks(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions', 'britvic-promo'));
            }
            
            $competition_id = (int) ($_POST['competition_id'] ?? 0);
            
            if (!$competition_id) {
                throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
            }
            
            if (!isset($_FILES['blocks_csv']) || $_FILES['blocks_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(__('No file uploaded or upload error occurred', 'britvic-promo'));
            }
            
            $file_path = $_FILES['blocks_csv']['tmp_name'];
            $file_type = $_FILES['blocks_csv']['type'];
            
            // Validate file type
            if (!in_array($file_type, ['text/csv', 'application/csv', 'text/plain'])) {
                throw new Exception(__('Invalid file type. Please upload a CSV file.', 'britvic-promo'));
            }
            
            // Read and process CSV
            $imported_count = 0;
            $errors = [];
            
            if (($handle = fopen($file_path, 'r')) !== FALSE) {
                global $wpdb;
                $table = $wpdb->prefix . 'bpm_user_blocks';
                
                // Read header row
                $headers = fgetcsv($handle);
                if (!$headers) {
                    throw new Exception(__('Empty CSV file or invalid format', 'britvic-promo'));
                }
                
                // Validate required columns
                $required_columns = ['block_type', 'block_value', 'block_behavior'];
                $header_map = array_flip($headers);
                
                foreach ($required_columns as $col) {
                    if (!isset($header_map[$col])) {
                        throw new Exception(sprintf(__('Missing required column: %s', 'britvic-promo'), $col));
                    }
                }
                
                $line_number = 2; // Start from 2 (after header)
                while (($data = fgetcsv($handle)) !== FALSE) {
                    try {
                        if (count($data) < count($required_columns)) {
                            $errors[] = sprintf(__('Line %d: Not enough columns', 'britvic-promo'), $line_number);
                            continue;
                        }
                        
                        $block_type = trim($data[$header_map['block_type']]);
                        $block_value = trim($data[$header_map['block_value']]);
                        $block_behavior = trim($data[$header_map['block_behavior']]);
                        $block_reason = isset($header_map['block_reason']) ? trim($data[$header_map['block_reason']] ?? '') : '';
                        
                        // Validate values
                        if (!in_array($block_type, ['email', 'ip', 'name', 'domain'])) {
                            $errors[] = sprintf(__('Line %d: Invalid block_type "%s"', 'britvic-promo'), $line_number, $block_type);
                            continue;
                        }
                        
                        if (!in_array($block_behavior, ['prevent_entry', 'always_lose'])) {
                            $errors[] = sprintf(__('Line %d: Invalid block_behavior "%s"', 'britvic-promo'), $line_number, $block_behavior);
                            continue;
                        }
                        
                        if (empty($block_value)) {
                            $errors[] = sprintf(__('Line %d: Empty block_value', 'britvic-promo'), $line_number);
                            continue;
                        }
                        
                        // Check if block already exists
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table WHERE competition_id = %d AND block_type = %s AND block_value = %s",
                            $competition_id, $block_type, $block_value
                        ));
                        
                        if ($existing) {
                            $errors[] = sprintf(__('Line %d: Block already exists for %s: %s', 'britvic-promo'), $line_number, $block_type, $block_value);
                            continue;
                        }
                        
                        // Insert the block
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
                            $imported_count++;
                            
                            // Log the action
                            BPM_Audit_Logger::log(
                                $competition_id,
                                'user_blocked_bulk',
                                "User blocked via bulk import: {$block_type} = {$block_value}",
                                'info',
                                [
                                    'block_type' => $block_type,
                                    'block_value' => $block_value,
                                    'block_reason' => $block_reason,
                                    'block_behavior' => $block_behavior,
                                    'import_line' => $line_number
                                ]
                            );
                        } else {
                            $errors[] = sprintf(__('Line %d: Database error - %s', 'britvic-promo'), $line_number, $wpdb->last_error);
                        }
                        
                    } catch (Exception $e) {
                        $errors[] = sprintf(__('Line %d: %s', 'britvic-promo'), $line_number, $e->getMessage());
                    }
                    
                    $line_number++;
                }
                
                fclose($handle);
            } else {
                throw new Exception(__('Could not read uploaded file', 'britvic-promo'));
            }
            
            // Prepare response
            $message = sprintf(__('Import completed. %d blocks imported successfully.', 'britvic-promo'), $imported_count);
            
            if (!empty($errors)) {
                $message .= ' ' . sprintf(__('%d errors occurred:', 'britvic-promo'), count($errors));
            }
            
            wp_send_json_success([
                'message' => $message,
                'imported_count' => $imported_count,
                'errors' => $errors,
                'error_count' => count($errors)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Debug admin notice to verify plugin is loading
     */
    public function debug_admin_notice(): void {
        // Removed excessive debug notice
    }

    /**
     * AJAX: bulk-actions selector on the Prizes admin page.
     *
     * Accepts one of: mark_available | mark_claimed | reseed | delete.
     * Operates on the supplied `prize_ids[]` OR — when `select_all=1` — on
     * every prize matching the current competition + search_email filter
     * (i.e. the whole filtered set across all pages).
     */
    public function handle_ajax_prizes_bulk_action(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'britvic-promo' ) ], 403 );
                return;
            }

            $bulk_action    = isset( $_POST['bulk_action'] ) ? sanitize_text_field( (string) $_POST['bulk_action'] ) : '';
            $competition_id = isset( $_POST['competition_id'] ) ? (int) $_POST['competition_id'] : 0;
            $search_email   = isset( $_POST['search_email'] ) ? sanitize_text_field( (string) $_POST['search_email'] ) : '';
            $select_all     = ! empty( $_POST['select_all'] );
            $raw_ids        = isset( $_POST['prize_ids'] ) && is_array( $_POST['prize_ids'] ) ? $_POST['prize_ids'] : [];
            $prize_ids      = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

            $allowed_actions = [ 'mark_available', 'mark_claimed', 'reseed', 'delete' ];
            if ( ! in_array( $bulk_action, $allowed_actions, true ) ) {
                wp_send_json_error( [ 'message' => __( 'Invalid bulk action', 'britvic-promo' ) ], 400 );
                return;
            }
            if ( $competition_id <= 0 ) {
                wp_send_json_error( [ 'message' => __( 'Invalid competition ID', 'britvic-promo' ) ], 400 );
                return;
            }

            // Resolve the full ID set if "select all across pages" is on.
            if ( $select_all ) {
                $args = [
                    'post_type'      => 'prize',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => [
                        [ 'key' => 'competition_id', 'value' => $competition_id, 'compare' => '=' ],
                    ],
                ];
                if ( $search_email !== '' ) {
                    $args['meta_query'][] = [
                        'key'     => 'claimed_by_email',
                        'value'   => $search_email,
                        'compare' => 'LIKE',
                    ];
                }
                $prize_ids = array_map( 'intval', get_posts( $args ) );
            }

            if ( empty( $prize_ids ) ) {
                wp_send_json_error( [ 'message' => __( 'No rows selected', 'britvic-promo' ) ], 400 );
                return;
            }

            global $wpdb;
            $entries_table = $wpdb->prefix . 'bpm_prize_entries';
            $updated = 0;
            $errors  = [];

            switch ( $bulk_action ) {

                case 'mark_available':
                    // NOTE: bypassing bpm_reset_prize_claim() because it
                    // references a `winner_data` column that's missing in some
                    // BPM installs — would emit a wpdb error on every row.
                    // Inline reset covers postmeta + mapped_data + table.
                    foreach ( $prize_ids as $pid ) {
                        update_post_meta( $pid, 'claimed', 'false' );
                        delete_post_meta( $pid, 'claimed_at' );
                        delete_post_meta( $pid, 'claimed_by_email' );
                        delete_post_meta( $pid, 'claimed_by_user_id' );
                        delete_post_meta( $pid, 'gravity_form_entry_id' );

                        $md_json = get_post_meta( $pid, 'mapped_data', true );
                        if ( is_string( $md_json ) && $md_json !== '' ) {
                            $md = json_decode( $md_json, true );
                            if ( is_array( $md ) ) {
                                $md['claimed'] = 'false';
                                update_post_meta( $pid, 'mapped_data', wp_json_encode( $md ) );
                            }
                        }

                        $wpdb->update(
                            $entries_table,
                            [
                                'claimed'                => 0,
                                'claimed_at'             => null,
                                'claimed_by_email'       => '',
                                'claimed_by_name'        => '',
                                'claimed_by_user_id'     => null,
                                'gravity_form_entry_id'  => null,
                                'reservation_key'        => null,
                                'reserved_at'            => null,
                                'reservation_expires_at' => null,
                                'updated_at'             => current_time( 'mysql' ),
                            ],
                            [ 'prize_post_id' => $pid ],
                            null,
                            [ '%d' ]
                        );
                        $updated++;
                    }
                    break;

                case 'mark_claimed':
                    $now = current_time( 'mysql', true );
                    foreach ( $prize_ids as $pid ) {
                        update_post_meta( $pid, 'claimed', 'true' );
                        update_post_meta( $pid, 'claimed_at', $now );
                        // Mirror into mapped_data if present (no admin email here).
                        $md_json = get_post_meta( $pid, 'mapped_data', true );
                        if ( is_string( $md_json ) && $md_json !== '' ) {
                            $md = json_decode( $md_json, true );
                            if ( is_array( $md ) ) {
                                $md['claimed'] = 'true';
                                update_post_meta( $pid, 'mapped_data', wp_json_encode( $md ) );
                            }
                        }
                        $wpdb->update(
                            $entries_table,
                            [ 'claimed' => 1, 'claimed_at' => $now, 'updated_at' => current_time( 'mysql' ) ],
                            [ 'prize_post_id' => $pid ],
                            null, [ '%d' ]
                        );
                        $updated++;
                    }
                    break;

                case 'reseed':
                    if ( ! class_exists( 'BPM_Prize_Reseeder' ) ) {
                        wp_send_json_error( [ 'message' => 'Reseeder not available' ], 500 );
                        return;
                    }
                    $reseeder = new BPM_Prize_Reseeder();
                    $report   = $reseeder->reseed_prize_ids( $prize_ids, $competition_id );
                    $updated  = (int) $report['reseeded_count'];
                    $errors   = (array) $report['errors'];
                    break;

                case 'delete':
                    foreach ( $prize_ids as $pid ) {
                        $wpdb->delete( $entries_table, [ 'prize_post_id' => $pid ], [ '%d' ] );
                        if ( wp_delete_post( $pid, true ) ) {
                            $updated++;
                        } else {
                            $errors[] = sprintf( 'Failed to delete prize %d', $pid );
                        }
                    }
                    break;
            }

            if ( class_exists( 'BPM_Audit_Logger' ) ) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'prize_bulk_action',
                    sprintf( 'Bulk %s: %d updated', $bulk_action, $updated ),
                    empty( $errors ) ? 'info' : 'warning',
                    [
                        'action'      => $bulk_action,
                        'updated'     => $updated,
                        'total'       => count( $prize_ids ),
                        'select_all'  => $select_all,
                        'errors'      => $errors,
                    ]
                );
            }

            wp_send_json_success( [
                'updated' => $updated,
                'total'   => count( $prize_ids ),
                'errors'  => $errors,
            ] );

        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: legacy "Sync Prizes" button (step 1 — sync wp_posts → table).
     * Was historically calling an unregistered action; now routes through the
     * canonical BPM_Prize_Sync helper so postmeta + mapped_data + the
     * bpm_prize_entries row all agree per prize.
     */
    public function handle_ajax_bpm_sync_prizes(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'britvic-promo' ) ], 403 );
                return;
            }
            $competition_id = isset( $_POST['competition_id'] ) ? (int) $_POST['competition_id'] : 0;
            if ( ! class_exists( 'BPM_Prize_Sync' ) ) {
                wp_send_json_error( [ 'message' => 'Sync helper not loaded' ], 500 );
                return;
            }
            $report = BPM_Prize_Sync::backfill_competition( $competition_id > 0 ? $competition_id : null );
            wp_send_json_success( [
                'message' => sprintf( 'Synced %d of %d prizes.', (int) $report['synced'], (int) $report['total'] ),
                'report'  => $report,
            ] );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: legacy "Sync Prizes" button (step 2 — sync claims back to posts).
     * The legacy button calls this immediately after the step-1 action above.
     * Since BPM_Prize_Sync covers the full data-alias backfill in step 1, this
     * is now a no-op success so the existing JS workflow completes cleanly
     * without firing a second pointless pass over the data.
     */
    public function handle_ajax_bpm_manual_sync(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'britvic-promo' ) ], 403 );
                return;
            }
            wp_send_json_success( [
                'message' => 'Claims already in sync (handled by step 1).',
            ] );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: backfill prize-data aliases for every prize in a competition.
     * Brings postmeta + mapped_data + bpm_prize_entries into sync so the
     * admin sort, edit, and live game all read the same value per field.
     */
    public function handle_ajax_prizes_sync_aliases(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'britvic-promo' ) ], 403 );
                return;
            }

            $competition_id = isset( $_POST['competition_id'] ) ? (int) $_POST['competition_id'] : 0;

            if ( ! class_exists( 'BPM_Prize_Sync' ) ) {
                wp_send_json_error( [ 'message' => 'Sync helper not loaded' ], 500 );
                return;
            }

            $report = BPM_Prize_Sync::backfill_competition( $competition_id > 0 ? $competition_id : null );

            if ( class_exists( 'BPM_Audit_Logger' ) ) {
                BPM_Audit_Logger::log(
                    $competition_id,
                    'prize_aliases_synced',
                    sprintf( 'Synced prize-data aliases: %d/%d', (int) $report['synced'], (int) $report['total'] ),
                    'info',
                    $report
                );
            }

            wp_send_json_success( $report );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: return only the prizes-table fragment HTML for the supplied
     * sort / pagination / search state.
     *
     * Strategy: set up the request superglobals as if this were a normal admin
     * page render, then `include` the existing prizes admin file with a flag
     * that suppresses everything outside the fragment markers. Output buffer
     * captures the full render; we extract just the fragment between the
     * BPM_FRAGMENT_START / BPM_FRAGMENT_END markers and stream it back.
     *
     * This intentionally reuses the existing render path so we don't duplicate
     * the column-config / query / pagination logic — sort behaviour is
     * guaranteed to match a full page load.
     */
    public function handle_ajax_prizes_fetch_fragment(): void {
        try {
            check_ajax_referer('bpm_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Insufficient permissions', 'britvic-promo')], 403);
                return;
            }

            // Map AJAX params onto $_GET so the included admin file picks them
            // up via its existing $_GET reads — no signature change required.
            $params = [
                'competition_id' => isset($_POST['competition_id']) ? (int) $_POST['competition_id'] : 0,
                'orderby'        => isset($_POST['orderby']) ? sanitize_text_field($_POST['orderby']) : 'date',
                'order'          => (isset($_POST['order']) && strtolower((string) $_POST['order']) === 'desc') ? 'desc' : 'asc',
                'paged'          => isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1,
                'search_email'   => isset($_POST['search_email']) ? sanitize_text_field($_POST['search_email']) : '',
            ];
            $_GET = array_merge($_GET, [
                'page'           => 'britvic-prizes',
                'competition_id' => $params['competition_id'],
                'orderby'        => $params['orderby'],
                'order'          => $params['order'],
                'paged'          => $params['paged'],
                'search_email'   => $params['search_email'],
            ]);

            // Tell the admin file we only want the fragment portion.
            $GLOBALS['bpm_ajax_fragment_only'] = true;

            // The included admin file uses wp-admin helpers (submit_button,
            // get_admin_page_title, …) which aren't auto-loaded for admin-ajax
            // requests. Load the minimum surface so the include doesn't fatal.
            if ( ! function_exists( 'submit_button' ) ) {
                require_once ABSPATH . 'wp-admin/includes/template.php';
            }
            if ( ! function_exists( 'get_admin_page_title' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if ( ! function_exists( 'wp_enqueue_media' ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            ob_start();
            include BPM_PLUGIN_PATH . 'includes/admin/prizes.php';
            $rendered = ob_get_clean();

            // Extract the content between the fragment markers.
            $start_marker = '<!-- BPM_FRAGMENT_START -->';
            $end_marker   = '<!-- BPM_FRAGMENT_END -->';
            $start        = strpos($rendered, $start_marker);
            $end          = $start !== false ? strpos($rendered, $end_marker, $start) : false;

            if ($start === false || $end === false) {
                wp_send_json_error(['message' => 'Fragment markers not found in render output.'], 500);
                return;
            }

            $fragment = substr($rendered, $start + strlen($start_marker), $end - $start - strlen($start_marker));
            wp_send_json_success(['html' => $fragment]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }
}

/**
 * Placeholder for user eligibility check.
 *
 * @param string $email User email.
 * @param string $name User name.
 * @param string $ip User IP address.
 * @return array Eligibility result.
 */
function bpm_check_user_eligibility(string $email, string $name, string $ip): array {
    $competition_id = bpm_get_active_competition_id();
    
    if (!$competition_id) {
        return [
            'can_play' => false,
            'blocked' => false,
            'reason' => 'No active competition found'
        ];
    }
    
    // Check if user has already won a prize (claimed prizes)
    $existing_prizes = get_posts([
        'post_type' => 'prize',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => 'competition_id',
                'value' => $competition_id,
                'compare' => '='
            ],
            [
                'key' => 'claimed',
                'value' => 'true',
                'compare' => '='
            ],
            [
                'key' => 'claimed_by_email',
                'value' => $email,
                'compare' => '='
            ]
        ]
    ]);
    
    if (!empty($existing_prizes)) {
        return [
            'can_play' => true,
            'blocked' => true,
            'reason' => 'You have already won a prize in this competition'
        ];
    }
    
    // Check manual blocks table
    global $wpdb;
    $blocks_table = $wpdb->prefix . 'bpm_user_blocks';
    $blocked = false;
    
    
    // First, let's see what blocks exist for this competition
    $all_blocks = $wpdb->get_results($wpdb->prepare(
        "SELECT id, block_type, block_value FROM $blocks_table WHERE competition_id = %d",
        $competition_id
    ));
    
    
    // Check each block type separately for better debugging
    
    // Check email blocks
    $email_blocked = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $blocks_table 
         WHERE competition_id = %d AND block_type = 'email' AND LOWER(block_value) = LOWER(%s)",
        $competition_id,
        $email
    ));
    
    if ($email_blocked) {
        $blocked = $email_blocked;
    }
    
    // Check IP blocks
    if (!$blocked) {
        $ip_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $blocks_table 
             WHERE competition_id = %d AND block_type = 'ip' AND block_value = %s",
            $competition_id,
            $ip
        ));
        
        if ($ip_blocked) {
            $blocked = $ip_blocked;
        }
    }
    
    // Check name blocks - use exact match instead of LIKE for more precision
    if (!$blocked && !empty($name)) {
        $name_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $blocks_table 
             WHERE competition_id = %d AND block_type = 'name' AND LOWER(block_value) = LOWER(%s)",
            $competition_id,
            $name
        ));
        
        if ($name_blocked) {
            $blocked = $name_blocked;
        }
    }
    
    
    if ($blocked) {
        // Get the specific block that matched for better debugging
        $matched_block = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $blocks_table WHERE id = %d",
            $blocked
        ));
        
        return [
            'can_play' => true,
            'blocked' => true,
            'reason' => 'You are not eligible to participate in this competition'
        ];
    }
    
    // User is eligible to play
    return [
        'can_play' => true,
        'blocked' => false,
        'reason' => 'User is eligible to play'
    ];
}

/**
 * Initialize the plugin
 */
function britvic_promotion_manager_init(): BritvicPromotionManager {
    return BritvicPromotionManager::get_instance();
}

// Bootstrap the plugin
add_action('plugins_loaded', 'britvic_promotion_manager_init');

/**
 * Get available email template placeholders based on actual prize data columns
 *
 * @param int $competition_id Competition ID to check for prize columns
 * @return array Array of placeholder => description pairs
 */
function bpm_get_email_placeholders(int $competition_id = 0): array {
    $placeholders = [];
    
    // Get sample prize to determine available columns
    if ($competition_id > 0) {
        $sample_prizes = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!empty($sample_prizes)) {
            $sample_prize = $sample_prizes[0];
            
            // Get all meta data for this prize
            $meta_data = get_post_meta($sample_prize->ID);
            
            // Standard prize columns that are always available
            $standard_columns = [
                'unique_id' => __('Unique prize identifier', 'britvic-promo'),
                'code' => __('Prize code for verification', 'britvic-promo'),
                'prize' => __('Prize type/name', 'britvic-promo'),
                'value' => __('Prize value', 'britvic-promo'),
                'date' => __('Win date', 'britvic-promo'),
                'pin' => __('Prize PIN number', 'britvic-promo'),
                'claimed' => __('Whether prize is claimed (true/false)', 'britvic-promo'),
                'claimed_by_email' => __('Email of winner', 'britvic-promo'),
                'claimed_by_user_id' => __('User ID of winner', 'britvic-promo'),
                'claimed_at' => __('When prize was claimed', 'britvic-promo')
            ];
            
            foreach ($standard_columns as $column => $description) {
                if (isset($meta_data[$column])) {
                    $placeholders['{' . $column . '}'] = $description;
                }
            }
            
            // Check for mapped data (custom columns from CSV import)
            $mapped_data_json = get_post_meta($sample_prize->ID, 'mapped_data', true);
            if ($mapped_data_json) {
                $mapped_data = json_decode($mapped_data_json, true);
                if (is_array($mapped_data)) {
                    foreach ($mapped_data as $column => $value) {
                        // Skip internal columns
                        if (!in_array($column, ['win_date_from_file', 'win_date_auto'])) {
                            $placeholders['{' . $column . '}'] = sprintf(__('Custom field: %s', 'britvic-promo'), $column);
                        }
                    }
                }
            }
        }
    }
    
    // Always include basic placeholders even if no prizes exist yet
    if (empty($placeholders)) {
        $placeholders = [
            '{code}' => __('Prize code for verification', 'britvic-promo'),
            '{prize}' => __('Prize type/name', 'britvic-promo'),
            '{value}' => __('Prize value', 'britvic-promo'),
            '{date}' => __('Win date', 'britvic-promo')
        ];
    }
    
    // Add site-specific placeholders
    $placeholders['{site_name}'] = __('Website name', 'britvic-promo');
    $placeholders['{site_url}'] = __('Website URL', 'britvic-promo');
    
    return $placeholders;
}

/**
 * Get the active competition ID
 * This function checks for the currently active competition
 *
 * @return int Active competition ID, or 1 as fallback
 */
function bpm_get_active_competition_id(): int {
    // Check if competition ID is passed in URL parameters
    if (isset($_GET['competition_id']) && is_numeric($_GET['competition_id'])) {
        return (int) $_GET['competition_id'];
    }
    
    // Check for active competition in options (fix typo: bmp -> bpm)
    $active_competition = get_option('bpm_active_competition_id', 0);
    if ($active_competition && is_numeric($active_competition)) {
        return (int) $active_competition;
    }
    
    // Safe fallback: get first competition from database with error handling
    global $wpdb;
    
    try {
        $competitions_table = $wpdb->prefix . 'bpm_competitions';
        
        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$competitions_table'") == $competitions_table;
        
        if ($table_exists && !$wpdb->last_error) {
            $competition_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$competitions_table} ORDER BY id ASC LIMIT %d", 1)
            );
            
            if ($competition_id && !$wpdb->last_error) {
                return (int) $competition_id;
            }
        }
    } catch (Exception $e) {
    }
    
    // Final fallback
    return 1;
}

/**
 * Get available prizes within a time window
 *
 * @param int $start_time Start timestamp
 * @param int $end_time End timestamp  
 * @param int $competition_id Competition ID
 * @return array Array of available prize IDs
 */
function bpm_get_time_window_prizes($start_time, $end_time, $competition_id = null) {
    global $wpdb;
    
    if (!$competition_id) {
        $competition_id = bpm_get_active_competition_id();
    }
    
    
    $args = [
        'post_type' => 'prize',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'competition_id',
                'value' => $competition_id,
                'compare' => '='
            ],
            [
                'key' => 'claimed',
                'value' => 'true',
                'compare' => '!='
            ]
        ]
    ];
    
    $prizes = get_posts($args);
    $available_prizes = [];
    
    foreach ($prizes as $prize) {
        // First check if there's an entry in the prize_entries table
        $entries_data = $wpdb->get_row($wpdb->prepare(
            "SELECT date_available, claimed FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
            $prize->ID
        ));
        
        $prize_date = null;
        if ($entries_data && $entries_data->date_available) {
            // Use date_available from entries table as authoritative source
            $prize_date = $entries_data->date_available;
        } else {
            // Fallback to post meta if no entries table data
            $prize_date = get_post_meta($prize->ID, 'date', true);
            
            // Also check mapped data for date fields
            $mapped_data_json = get_post_meta($prize->ID, 'mapped_data', true);
            if ($mapped_data_json) {
                $mapped_data = json_decode($mapped_data_json, true);
                if (isset($mapped_data['win_date_auto'])) {
                    $prize_date = $mapped_data['win_date_auto'];
                } elseif (isset($mapped_data['win_date_from_file'])) {
                    $prize_date = $mapped_data['win_date_from_file'];
                }
            }
        }
        
        
        if ($prize_date) {
            // Convert prize date to timestamp using WordPress timezone
            $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string', 'UTC');
            if (empty($timezone_string)) {
                $timezone_string = 'UTC';
            }
            
            $prize_datetime = new DateTime($prize_date, new DateTimeZone($timezone_string));
            $prize_timestamp = $prize_datetime->getTimestamp();
            
            
            if ($prize_timestamp >= $start_time && $prize_timestamp <= $end_time) {
                $available_prizes[] = $prize->ID;
            } else {
            }
        } else {
        }
    }
    
    return $available_prizes;
}
/**
 * Handle prize result logic - determines if user wins a prize
 *
 * @param string $email User email
 * @param string $name User name  
 * @param string $ip User IP address
 * @return array Prize result array
 */

function bpm_handle_prize_result(string $email, string $name, string $ip): array { // Added type hints
    
    // Start session if not already started
    if (!session_id()) {
        session_start();
    }
    
    // Check if there's already a valid winning prize in session - preserve it
    if (isset($_SESSION['britvic_prize']) && 
        isset($_SESSION['britvic_prize']['prize']) && 
        $_SESSION['britvic_prize']['prize'] === 'winner' &&
        isset($_SESSION['britvic_prize']['prize_code'])) {
        
        return $_SESSION['britvic_prize'];
    }
    
    $competition_id = bpm_get_active_competition_id();
    $current_time = current_time('timestamp');
    
    // Retrieve prize window duration from competition settings (in minutes)
    // Fallback to 10 minutes if not found
    $prize_window_minutes = (int) get_option("bpm_prize_window_{$competition_id}", 10);
    $prize_window_duration_seconds = $prize_window_minutes * 60;
    
    // Define time window based on Prize Window Duration setting
    // Only past prizes are available - prizes scheduled between (current_time - window) and current_time
    $before_time = $current_time - $prize_window_duration_seconds; // Earliest available time
    $after_time = $current_time; // Current time is the latest available time (no future prizes)
    
    
    // Get available prizes in current time window
    $available_prize_ids = bpm_get_time_window_prizes($before_time, $after_time, $competition_id);

    if (empty($available_prize_ids)) {
        
        // No prizes available in time window
        return [
            'prize' => 'loser',
            'reason' => 'No prizes available in current time window'
        ];
    }

    // Randomly select a prize from available ones
    $selected_prize_id = $available_prize_ids[array_rand($available_prize_ids)];
    $prize = get_post($selected_prize_id);

    if (!$prize) {
        return [
            'prize' => 'loser', 
            'reason' => 'Selected prize not found'
        ];
    }

    // Get prize data
    $prize_code = get_post_meta($prize->ID, 'code', true);
    $prize_name = get_post_meta($prize->ID, 'prize', true);
    $prize_value = get_post_meta($prize->ID, 'value', true);
    

    // Check mapped data for additional fields

    $mapped_data_json = get_post_meta($prize->ID, 'mapped_data', true);

    if ($mapped_data_json) {

        $mapped_data = json_decode($mapped_data_json, true);

        if (isset($mapped_data['prize_name_from_distribution'])) {
            $prize_name = $mapped_data['prize_name_from_distribution'];
        } elseif (isset($mapped_data['prize_name_from_file'])) {
            $prize_name = $mapped_data['prize_name_from_file'];
        }
        
    } else {
    }
    

    // Mark prize as claimed temporarily (will be finalized when user completes claim form)
    update_post_meta($prize->ID, 'claimed', 'true');
    update_post_meta($prize->ID, 'claimed_by_email', $email);
    update_post_meta($prize->ID, 'claimed_by_name', $name);
    update_post_meta($prize->ID, 'claimed_at', current_time('mysql'));
    
    // Also update the wp_bpm_prize_entries table
    global $wpdb;
    $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';
    
    $wpdb->update(
        $prize_entries_table,
        [
            'claimed' => 1,
            'claimed_at' => current_time('mysql'),
            'claimed_by_email' => $email,
            'claimed_by_name' => $name
        ],
        ['prize_post_id' => $prize->ID],
        ['%d', '%s', '%s', '%s'],
        ['%d']
    );
    

            // Log the prize selection
            BPM_Audit_Logger::log(
                $competition_id,
                'prize_assigned', // Assuming this is the action
                sprintf('Prize %s assigned to %s', $prize_name, $email),
                'info',
                [
                    'prize_id' => $prize->ID,    
                    'prize_code' => $prize_code,
                    'prize_name' => $prize_name,
                    'prize_value' => $prize_value,
                    'winner_email' => $email,
                    'winner_name' => $name,
                    'winner_ip' => $ip,
                ]

            );
    
    // Get original_data for placeholders
    $original_data = $wpdb->get_var($wpdb->prepare(
        "SELECT original_data FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s LIMIT 1",
        $prize_code
    ));
    
    $mapped_data = [];
    if ($original_data) {
        $mapped_data = json_decode($original_data, true) ?: [];
    }
    
    // Prepare prize data for session
    $prize_data = [
        'prize' => 'winner',
        'prize_id' => $prize->ID,
        'prize_code' => $prize_code,
        'prize_name' => $prize_name,
        'prize_value' => $prize_value ?: '£0.00',
        'prize_description' => "You have won: {$prize_name}",
        'reason' => 'Prize assigned successfully',
        'mapped_data' => $mapped_data  // Add the original prize data for placeholders
    ];
    
    
    // Start session if not already started
    if (!session_id()) {
        session_start();
    } else {
    }
    
    // Store prize data in session
    $_SESSION['britvic_prize'] = $prize_data;
    
    // Return the same data structure that was stored in session
    return $prize_data;
}

/**
 * Sync prize dates between post meta and prize entries table
 * @param int $prize_id Prize post ID
 */
function bpm_sync_prize_date($prize_id) {
    global $wpdb;
    
    // Only sync for 'prize' post type
    if (get_post_type($prize_id) !== 'prize') {
        return;
    }
    
    
    // Get the current date from post meta
    $mapped_data_json = get_post_meta($prize_id, 'mapped_data', true);
    $prize_date = null;
    
    if ($mapped_data_json) {
        $mapped_data = json_decode($mapped_data_json, true);
        if (isset($mapped_data['win_date_auto'])) {
            $prize_date = $mapped_data['win_date_auto'];
        } elseif (isset($mapped_data['win_date_from_file'])) {
            $prize_date = $mapped_data['win_date_from_file'];
        }
    }
    
    // Fallback to 'date' meta key
    if (!$prize_date) {
        $prize_date = get_post_meta($prize_id, 'date', true);
        if ($prize_date) {
        }
    }
    
    if ($prize_date) {
        // Get additional sync data
        $unique_id = get_post_meta($prize_id, 'unique_id', true);
        $code = get_post_meta($prize_id, 'code', true);
        $claimed_status = get_post_meta($prize_id, 'claimed', true);
        $claimed_by_email = get_post_meta($prize_id, 'claimed_by_email', true);
        $claimed_by_name = get_post_meta($prize_id, 'claimed_by_name', true);
        $claimed_at = get_post_meta($prize_id, 'claimed_at', true);
        
        
        // Prepare update data
        $update_data = [
            'date_available' => $prize_date,
            'updated_at' => current_time('mysql')
        ];
        $format = ['%s', '%s'];
        
        // Update original_data to reflect the new date_available
        $current_original_data = $wpdb->get_var($wpdb->prepare(
            "SELECT original_data FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
            $prize_id
        ));
        
        if ($current_original_data) {
            $original_data_array = json_decode($current_original_data, true);
            if (is_array($original_data_array)) {
                // Update the date fields in original_data to match the new date_available
                $original_data_array['win_date_auto'] = $prize_date;
                $original_data_array['date'] = $prize_date;
                $update_data['original_data'] = json_encode($original_data_array);
                $format[] = '%s';
            }
        }
        
        // Add claimed status if set
        if ($claimed_status === 'true') {
            $update_data['claimed'] = 1;
            $update_data['claimed_by_email'] = $claimed_by_email;
            $update_data['claimed_by_name'] = $claimed_by_name;
            $update_data['claimed_at'] = $claimed_at ?: current_time('mysql');
            $format = array_merge($format, ['%d', '%s', '%s', '%s']);
        } elseif ($claimed_status === 'pending') {
            $update_data['claimed'] = 0;
            $format[] = '%d';
        }
        
        if ($unique_id) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'bpm_prize_entries',
                $update_data,
                ['unique_id' => $unique_id],
                $format,
                ['%s']
            );
            
            
            // Fallback to code if unique_id didn't work
            if ($updated === 0 && $code) {
                $updated = $wpdb->update(
                    $wpdb->prefix . 'bpm_prize_entries',
                    $update_data,
                    ['code' => $code],
                    $format, 
                    ['%s']
                );
            }
            
            // Final fallback to prize_post_id
            if ($updated === 0) {
                $updated = $wpdb->update(
                    $wpdb->prefix . 'bpm_prize_entries',
                    $update_data,
                    ['prize_post_id' => $prize_id],
                    $format,
                    ['%d']
                );
            }
            
            if ($updated > 0) {
            } else {
            }
        } else {
        }
    } else {
    }
}

/**
 * Hook to automatically sync prize data when post meta is updated
 */
function bpm_auto_sync_prize_on_meta_update($meta_id, $object_id, $meta_key, $meta_value) {
    // Only sync for prize-related meta keys
    $sync_trigger_keys = [
        'date', 'mapped_data', 'win_date_auto', 'win_date_from_file',
        'claimed', 'claimed_by_email', 'claimed_by_name', 'claimed_at'
    ];
    
    if (in_array($meta_key, $sync_trigger_keys)) {
        bpm_sync_prize_date($object_id);
    }
}

// Hook into WordPress meta update actions
add_action('updated_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10, 4);
add_action('added_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10, 4);

/**
 * Manually sync a specific prize from postmeta to prize_entries table
 * Useful when the tables get out of sync
 * @param int $prize_post_id The post ID of the prize to sync
 * @return bool Success status
 */
function bmp_manual_sync_prize($prize_post_id) {
    // Get the date from post meta (the authoritative source after manual edits)
    $date_from_meta = get_post_meta($prize_post_id, 'date', true);
    
    if (empty($date_from_meta)) {
        // Check mapped_data for win_date_auto
        $mapped_data_json = get_post_meta($prize_post_id, 'mapped_data', true);
        if ($mapped_data_json) {
            $mapped_data = json_decode($mapped_data_json, true);
            $date_from_meta = $mapped_data['win_date_auto'] ?? null;
        }
    }
    
    if ($date_from_meta) {
        return bmp_update_prize_date_available($prize_post_id, $date_from_meta);
    } else {
        return false;
    }
}

/**
 * Sync all prizes that are out of sync between postmeta and prize_entries
 * This is useful for fixing data inconsistencies
 * @param int $competition_id Optional competition ID to limit sync scope
 * @return array Results of sync operation
 */
function bmp_sync_all_prizes($competition_id = null) {
    global $wpdb;
    
    $results = ['synced' => 0, 'errors' => 0, 'skipped' => 0];
    
    // Get all prize posts
    $args = [
        'post_type' => 'prize',
        'post_status' => 'publish', 
        'posts_per_page' => -1,
    ];
    
    if ($competition_id) {
        $args['meta_query'] = [
            [
                'key' => 'competition_id',
                'value' => $competition_id,
                'compare' => '='
            ]
        ];
    }
    
    $prizes = get_posts($args);
    
    foreach ($prizes as $prize) {
        $sync_result = bmp_manual_sync_prize($prize->ID);
        if ($sync_result) {
            $results['synced']++;
        } elseif ($sync_result === false) {
            $results['errors']++;
        } else {
            $results['skipped']++;
        }
    }
    
    return $results;
}

/**
 * WordPress admin action to trigger prize sync via URL
 * Call via: /wp-admin/admin.php?action=bpm_sync_prizes&competition_id=1
 */
add_action('wp_loaded', function() {
    if (is_admin() && current_user_can('manage_options') && isset($_GET['action']) && $_GET['action'] === 'bmp_sync_prizes') {
        $competition_id = isset($_GET['competition_id']) ? (int)$_GET['competition_id'] : null;
        
        echo "<h2>BPM Prize Sync Results</h2>";
        echo "<p>Starting sync for competition ID: " . ($competition_id ?: 'all') . "</p>";
        
        $results = bmp_sync_all_prizes($competition_id);
        
        echo "<p><strong>Results:</strong></p>";
        echo "<ul>";
        echo "<li>Synced: {$results['synced']} prizes</li>";
        echo "<li>Errors: {$results['errors']} prizes</li>";
        echo "<li>Skipped: {$results['skipped']} prizes</li>";
        echo "</ul>";
        
        if ($results['synced'] > 0) {
            echo "<p style='color: green;'>✓ Sync completed successfully!</p>";
        } else {
            echo "<p style='color: orange;'>⚠ No prizes were synced. Check logs for details.</p>";
        }
        
        exit;
    }
});


/**
 * Update prize date_available in entries table and sync with post meta
 * @param int $prize_post_id Prize post ID
 * @param string $new_date New date in 'Y-m-d H:i:s' format
 * @return bool Success status
 */
function bmp_update_prize_date_available($prize_post_id, $new_date) {
    global $wpdb;
    
    
    // Update the prize entries table
    $updated = $wpdb->update(
        $wpdb->prefix . 'bpm_prize_entries',
        ['date_available' => $new_date, 'updated_at' => current_time('mysql')],
        ['prize_post_id' => $prize_post_id],
        ['%s', '%s'],
        ['%d']
    );
    
    if ($updated === false) {
        return false;
    }
    
    // Update post meta to keep it in sync
    update_post_meta($prize_post_id, 'date', $new_date);
    
    // Update mapped_data if it exists
    $mapped_data_json = get_post_meta($prize_post_id, 'mapped_data', true);
    if ($mapped_data_json) {
        $mapped_data = json_decode($mapped_data_json, true);
        if (is_array($mapped_data)) {
            $mapped_data['win_date_auto'] = $new_date;
            $mapped_data['date'] = $new_date;
            update_post_meta($prize_post_id, 'mapped_data', json_encode($mapped_data));
        }
    }
    
    // Trigger the sync function to update original_data
    bpm_sync_prize_date($prize_post_id);
    
    return true;
}

/**
 * Reset a prize to unclaimed status for testing
 * @param int $prize_post_id Prize post ID
 * @return bool Success status
 */
function bpm_reset_prize_claim($prize_post_id) {
    global $wpdb;
    
    
    // First check if the record exists
    $existing_record = $wpdb->get_row($wpdb->prepare(
        "SELECT id, claimed, claimed_at, claimed_by_email FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
        $prize_post_id
    ));
    
    if (!$existing_record) {
        return false;
    }
    
    
    // Update the prize entries table using raw SQL for more control
    $sql = $wpdb->prepare(
        "UPDATE {$wpdb->prefix}bpm_prize_entries 
        SET claimed = 0, 
            claimed_at = NULL, 
            claimed_by_email = '', 
            claimed_by_name = '',
            claimed_by_user_id = NULL,
            gravity_form_entry_id = NULL,
            winner_data = NULL,
            updated_at = %s 
        WHERE prize_post_id = %d",
        current_time('mysql'),
        $prize_post_id
    );
    
    
    $result = $wpdb->query($sql);
    
    if ($result === false) {
        return false;
    }
    
    
    // Verify the update worked
    $updated_record = $wpdb->get_row($wpdb->prepare(
        "SELECT claimed, claimed_at, claimed_by_email FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
        $prize_post_id
    ));
    
    
    // Update post meta to keep it in sync
    update_post_meta($prize_post_id, 'claimed', 'false');
    delete_post_meta($prize_post_id, 'claimed_at');
    delete_post_meta($prize_post_id, 'claimed_by_email');
    delete_post_meta($prize_post_id, 'claimed_by_name');
    
    return true;
}

/**
 * Force reset a prize using direct SQL - for debugging
 * @param int $prize_post_id Prize post ID
 */
function bpm_force_reset_prize($prize_post_id) {
    global $wpdb;
    
    echo "<pre>BPM: Starting force reset for prize post ID $prize_post_id</pre>";
    
    // Check current state before reset
    $current_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
        $prize_post_id
    ));
    
    echo "<pre>Current record: " . print_r($current_record, true) . "</pre>";
    
    // Direct SQL update - no prepared statement issues
    $table = $wpdb->prefix . 'bpm_prize_entries';
    $current_time = current_time('mysql');
    
    $sql = "UPDATE {$table} SET 
        claimed = 0, 
        claimed_at = NULL, 
        claimed_by_email = '', 
        claimed_by_name = '',
        claimed_by_user_id = NULL,
        gravity_form_entry_id = NULL,
        winner_data = NULL,
        updated_at = '{$current_time}'
    WHERE prize_post_id = {$prize_post_id}";
    
    echo "<pre>Executing SQL: $sql</pre>";
    
    $result = $wpdb->query($sql);
    
    echo "<pre>SQL result: $result</pre>";
    
    // CRITICAL: Update post meta to keep it in sync
    update_post_meta($prize_post_id, 'claimed', 'false');
    delete_post_meta($prize_post_id, 'claimed_at');
    delete_post_meta($prize_post_id, 'claimed_by_email');
    delete_post_meta($prize_post_id, 'claimed_by_name');
    delete_post_meta($prize_post_id, 'claimed_by_user_id');
    
    echo "<pre>Updated post meta - set claimed to 'false' and removed claim data</pre>";
    
    // Verify the update worked in both places
    $updated_record = $wpdb->get_row($wpdb->prepare(
        "SELECT claimed, claimed_at, claimed_by_email FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
        $prize_post_id
    ));
    
    $post_meta_claimed = get_post_meta($prize_post_id, 'claimed', true);
    
    echo "<pre>After reset:</pre>";
    echo "<pre>Database record: " . print_r($updated_record, true) . "</pre>";
    echo "<pre>Post meta 'claimed': $post_meta_claimed</pre>";
    
    return $result;
}


/**
 * Get the current competition's theme configuration
 *
 * @param int $competition_id Optional competition ID
 * @return array|null Theme configuration or null if not found
 */
function bpm_get_current_theme_config($competition_id = null) {
    if (!$competition_id) {
        $competition_id = bpm_get_active_competition_id();
    }
    
    if (!class_exists('BPM_Theme_Manager')) {
        return null;
    }
    
    $theme_id = BPM_Theme_Manager::get_competition_theme($competition_id);
    if (!$theme_id) {
        return null;
    }
    
    return BPM_Theme_Manager::get_theme($theme_id);
}

/**
 * Get the redirect URL for a specific page type based on current theme
 *
 * @param string $page_type Page type (entry, reveal, winner, loser)
 * @param int $competition_id Optional competition ID
 * @return string URL to redirect to
 */
function bmp_get_theme_page_url($page_type, $competition_id = null) {
    
    if (!$competition_id) {
        $competition_id = bpm_get_active_competition_id();
    }
    
    
    // Check for custom prize page setting first
    if (($page_type === 'reveal' || $page_type === 'winner') && $competition_id) {
        global $wpdb;
        $competition = $wpdb->get_row($wpdb->prepare(
            "SELECT prize_page_id FROM {$wpdb->prefix}bpm_competitions WHERE id = %d",
            $competition_id
        ));
        
        if ($competition && $competition->prize_page_id) {
            $page = get_post($competition->prize_page_id);
            if ($page && $page->post_status === 'publish') {
                $custom_url = get_permalink($competition->prize_page_id);
                return $custom_url;
            }
        }
    }
    
    $theme_config = null;
    
    // Check if we have a theme manager and get the theme config
    if (class_exists('BPM_Theme_Manager')) {
        $theme_id = BPM_Theme_Manager::get_competition_theme($competition_id);
        
        if ($theme_id) {
            $theme_config = BPM_Theme_Manager::get_theme($theme_id);
            
            // If theme not found in registered themes, check if it's the current active theme
            if (!$theme_config) {
                $current_theme_slug = get_option('stylesheet');
                
                if ($theme_id === $current_theme_slug) {
                    // Use default settings for current active theme
                    $theme_config = [
                        'redirect_settings' => [
                            'after_entry' => '/reveal',
                            'after_claim' => '/thanks'
                        ]
                    ];
                }
            }
        }
    } else {
    }
    
    
    if (!$theme_config || !isset($theme_config['redirect_settings'])) {
        // Fallback to default URLs
        switch ($page_type) {
            case 'entry':
                return home_url('/entry');
            case 'reveal':
            case 'winner':
            case 'loser':
                return home_url('/reveal');
            case 'claim_complete':
                $fallback_url = home_url('/thanks');
                return $fallback_url;
            default:
                return home_url('/');
        }
    }
    
    $redirect_settings = $theme_config['redirect_settings'];
    
    switch ($page_type) {
        case 'entry':
            $url = home_url($redirect_settings['entry'] ?? '/entry');
            break;
        case 'reveal':
            $url = home_url($redirect_settings['after_entry'] ?? '/reveal');
            break;
        case 'claim_complete':
            $url = home_url($redirect_settings['after_claim'] ?? '/thanks');
            break;
        default:
            $url = home_url('/');
    }
    
    return $url;
}

/**
 * Get competition terms and conditions content
 *
 * @param int|null $competition_id Competition ID (defaults to active competition)
 * @return string Terms content HTML or empty string if not found
 */
function bpm_get_competition_terms($competition_id = null) {
    if (!$competition_id) {
        $competition_id = bpm_get_active_competition_id();
    }
    
    if (!$competition_id) {
        return '';
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'bpm_competitions';
    
    // Check if terms_page_id column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SHOW COLUMNS FROM $table_name LIKE %s",
        'terms_page_id'
    ));
    
    if (empty($column_exists)) {
        return '';
    }
    
    // Get the terms page ID for this competition
    $terms_page_id = $wpdb->get_var($wpdb->prepare(
        "SELECT terms_page_id FROM {$table_name} WHERE id = %d",
        $competition_id
    ));
    
    if (!$terms_page_id) {
        return '';
    }
    
    // Get the page content
    $terms_page = get_post($terms_page_id);
    
    if (!$terms_page || $terms_page->post_status !== 'publish') {
        return '';
    }
    
    // Apply content filters (like shortcodes, etc.)
    $content = apply_filters('the_content', $terms_page->post_content);
    
    return $content;
}

/**
 * Handle scheduled competition publish action
 *
 * @param int $competition_id Competition ID
 */
function handle_scheduled_publish(int $competition_id): void {
    try {
        if (!class_exists('BPM_Page_Manager')) {
            return;
        }
        
        $page_manager = new BPM_Page_Manager();
        $result = $page_manager->publish_competition($competition_id);
        
        if ($result) {
            BPM_Audit_Logger::log(
                $competition_id,
                'scheduled_publish_executed',
                'Scheduled publish action executed successfully',
                'info',
                ['executed_at' => current_time('mysql')]
            );
        }
    } catch (Exception $e) {
        BPM_Audit_Logger::log(
            $competition_id,
            'scheduled_publish_failed',
            'Scheduled publish action failed: ' . $e->getMessage(),
            'error',
            ['executed_at' => current_time('mysql'), 'error' => $e->getMessage()]
        );
    }
}

/**
 * Handle scheduled competition close action
 *
 * @param int $competition_id Competition ID
 */
function handle_scheduled_close(int $competition_id): void {
    try {
        if (!class_exists('BPM_Page_Manager')) {
            return;
        }
        
        $page_manager = new BPM_Page_Manager();
        $result = $page_manager->close_competition($competition_id);
        
        if ($result) {
            BPM_Audit_Logger::log(
                $competition_id,
                'scheduled_close_executed',
                'Scheduled close action executed successfully',
                'info',
                ['executed_at' => current_time('mysql')]
            );
        }
    } catch (Exception $e) {
        BPM_Audit_Logger::log(
            $competition_id,
            'scheduled_close_failed',
            'Scheduled close action failed: ' . $e->getMessage(),
            'error',
            ['executed_at' => current_time('mysql'), 'error' => $e->getMessage()]
        );
    }
}
