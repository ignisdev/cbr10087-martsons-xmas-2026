<?php
/**
 * CSV Handler Class
 *
 * Handles CSV import/export functionality with robust validation
 * Replaces wp-all-import workflow for prize data
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
 * CSV Handler implementation
 */
class BPM_CSV_Handler implements BPM_CSV_Handler_Interface {
    
    /**
     * Maximum file size for CSV uploads (10MB)
     */
    private const MAX_FILE_SIZE = 10485760;
    
    /**
     * Maximum number of rows to process in one batch
     */
    private const MAX_BATCH_SIZE = 5000;
    
    /**
     * Allowed MIME types for CSV files
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel'
    ];
    
    /**
     * Upload directory for CSV files
     */
    private string $upload_dir;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/britvic-csv/';
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Add .htaccess for security
            $htaccess_content = "Options -Indexes\nDeny from all\n";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
        }
    }
    
    /**
     * Export competition data to CSV
     *
     * @param int $competition_id Competition ID
     * @param string $export_type Type of data to export ('prizes', 'entries', 'winners')
     * @return array Result array with file path and download URL
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If export fails
     */
    public function export_data(int $competition_id, string $export_type): array {
        $this->validate_export_parameters($competition_id, $export_type);
        
        $data = match($export_type) {
            'prizes' => $this->get_prizes_data($competition_id),
            'entries' => $this->get_entries_data($competition_id),
            'winners' => $this->get_winners_data($competition_id),
            default => throw new InvalidArgumentException('Invalid export type')
        };
        
        if (empty($data)) {
            throw new Exception(sprintf(__('No %s data found for export', 'britvic-promo'), $export_type));
        }
        
        $headers = $this->get_column_mapping($export_type);
        $csv_content = $this->generate_csv_content($data, array_keys($headers));
        
        $filename = sprintf(
            '%s_competition_%d_%s.csv',
            $export_type,
            $competition_id,
            date('Y-m-d_H-i-s')
        );
        
        $file_info = $this->save_csv_file($csv_content, $filename);
        
        BPM_Audit_Logger::log(
            $competition_id,
            'csv_export',
            sprintf('Exported %s data: %d records', $export_type, count($data))
        );
        
        return $file_info;
    }
    
    /**
     * Import prizes from CSV file with column mapping support
     *
     * @param array $file_data Uploaded file data from $_FILES
     * @param int $competition_id Competition ID to associate prizes with
     * @param array $column_mapping Column mapping from frontend
     * @param array $custom_fields Custom field values
     * @param string $import_type Import type (standard/custom)
     * @return array Result array with import statistics and errors
     * @throws InvalidArgumentException If file is invalid
     * @throws Exception If import fails
     */
    public function import_prizes_with_mapping(array $file_data, int $competition_id, array $column_mapping = [], array $custom_fields = [], string $import_type = 'standard'): array {
        // Validate file upload
        $this->validate_uploaded_file($file_data);
        
        // Move file to secure location
        $temp_file = $this->handle_file_upload($file_data);
        
        try {
            // Validate CSV structure (skip unique_id validation since we auto-generate)
            $validation_result = $this->validate_csv_file($temp_file, 'prizes');
            if (!$validation_result['valid']) {
                throw new Exception(
                    sprintf(__('CSV validation failed: %s', 'britvic-promo'), implode(', ', $validation_result['errors']))
                );
            }
            
            // Parse CSV data with column mapping
            $parsed_data = $this->parse_csv_file_with_mapping($temp_file, $column_mapping, $custom_fields, $import_type);
            
            // Apply Prize Type Distribution if configured
            // error_log("BPM CSV Import: About to apply prize type distribution...');
            $parsed_data = $this->apply_prize_type_distribution($parsed_data, $competition_id);
            
            // Apply Auto Date Generation if configured
            // error_log("BPM CSV Import: About to apply auto date generation...');
            $parsed_data = $this->apply_auto_date_generation($parsed_data, $competition_id);
            
            // Sanitize data
            $sanitized_data = $this->sanitize_csv_data($parsed_data, 'prizes');
            
            // Process in batches
            $import_result = $this->process_prize_import_batches($sanitized_data, $competition_id);
            
            // Clean up temporary file
            unlink($temp_file);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'csv_import',
                sprintf(
                    'Imported prizes: %d successful, %d failed, %d skipped',
                    $import_result['success_count'],
                    $import_result['error_count'],
                    $import_result['skipped_count']
                )
            );
            
            return $import_result;
            
        } catch (Exception $e) {
            // Clean up temporary file on error
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            throw $e;
        }
    }
    
    /**
     * Import prizes from CSV file
     *
     * @param array $file_data Uploaded file data from $_FILES
     * @param int $competition_id Competition ID to associate prizes with
     * @return array Result array with import statistics and errors
     * @throws InvalidArgumentException If file is invalid
     * @throws Exception If import fails
     */
    public function import_prizes(array $file_data, int $competition_id): array {
        // Validate file upload
        $this->validate_uploaded_file($file_data);
        
        // Move file to secure location
        $temp_file = $this->handle_file_upload($file_data);
        
        try {
            // Validate CSV structure
            $validation_result = $this->validate_csv_file($temp_file, 'prizes');
            if (!$validation_result['valid']) {
                throw new Exception(
                    sprintf(__('CSV validation failed: %s', 'britvic-promo'), implode(', ', $validation_result['errors']))
                );
            }
            
            // Parse CSV data
            $column_mapping = $this->get_column_mapping('prizes');
            $parsed_data = $this->parse_csv_file($temp_file, $column_mapping);
            
            // Sanitize data
            $sanitized_data = $this->sanitize_csv_data($parsed_data, 'prizes');
            
            // Process in batches
            $import_result = $this->process_prize_import_batches($sanitized_data, $competition_id);
            
            // Clean up temporary file
            unlink($temp_file);
            
            BPM_Audit_Logger::log(
                $competition_id,
                'csv_import',
                sprintf(
                    'Imported prizes: %d successful, %d failed, %d skipped',
                    $import_result['success_count'],
                    $import_result['error_count'],
                    $import_result['skipped_count']
                )
            );
            
            return $import_result;
            
        } catch (Exception $e) {
            // Clean up temporary file on error
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            throw $e;
        }
    }
    
    /**
     * Validate CSV file structure and content
     *
     * @param string $file_path Path to CSV file
     * @param string $expected_type Expected data type ('prizes', 'entries')
     * @return array Validation result with errors if any
     * @throws Exception If file cannot be read
     */
    public function validate_csv_file(string $file_path, string $expected_type): array {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new Exception(__('CSV file not found or not readable', 'britvic-promo'));
        }
        
        $errors = [];
        $warnings = [];
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot open CSV file for reading', 'britvic-promo'));
        }
        
        // Read and validate headers
        $headers = fgetcsv($handle);
        if ($headers === false) {
            $errors[] = __('CSV file appears to be empty', 'britvic-promo');
            fclose($handle);
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }
        
        // Clean headers (remove BOM and trim whitespace)
        $headers = array_map(function($header) {
            // Remove BOM characters and trim whitespace - improved BOM handling
            $header = trim($header);
            // Remove UTF-8 BOM if present at start of header
            if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
                $header = substr($header, 3);
            }
            // Remove other control characters
            $header = str_replace(["\x00", "\x0B"], '', $header);
            return trim($header);
        }, $headers);
        
        // Flexible validation - check for suggested columns but don't require specific ones
        $suggested_columns = $this->get_suggested_columns($expected_type);
        $found_columns = array_intersect($suggested_columns, $headers);
        
        if (empty($found_columns) && $expected_type === 'prizes') {
            $warnings[] = sprintf(
                __('No standard prize columns found. Suggested columns: %s', 'britvic-promo'),
                implode(', ', $suggested_columns)
            );
        }
        
        // Validate data rows
        $row_count = 0;
        $max_rows_to_check = 100; // Sample validation
        
        while (($row = fgetcsv($handle)) !== false && $row_count < $max_rows_to_check) {
            $row_count++;
            
            if (count($row) !== count($headers)) {
                $warnings[] = sprintf(__('Row %d has incorrect number of columns', 'britvic-promo'), $row_count + 1);
            }
            
            // Validate specific fields based on type
            $row_errors = $this->validate_csv_row($row, $headers, $expected_type, $row_count + 1);
            $errors = array_merge($errors, $row_errors);
        }
        
        fclose($handle);
        
        // Check file size constraints
        $total_rows = $this->count_csv_rows($file_path) - 1; // Subtract header row
        if ($total_rows > self::MAX_BATCH_SIZE) {
            $warnings[] = sprintf(
                __('File contains %d rows. Large files may take longer to process.', 'britvic-promo'),
                $total_rows
            );
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'row_count' => $total_rows
        ];
    }
    
    /**
     * Parse CSV file with frontend column mapping
     *
     * @param string $file_path Path to CSV file
     * @param array $column_mapping Column mapping from frontend
     * @param array $custom_fields Custom field values
     * @param string $import_type Import type
     * @return array Parsed data array
     * @throws Exception If parsing fails
     */
    public function parse_csv_file_with_mapping(string $file_path, array $column_mapping = [], array $custom_fields = [], string $import_type = 'standard'): array {
        error_log('BPM CSV Parse - Column mapping received: ' . print_r($column_mapping, true));
        error_log('BPM CSV Parse - Custom fields received: ' . print_r($custom_fields, true));
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot open CSV file for parsing', 'britvic-promo'));
        }
        
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new Exception(__('Cannot read CSV headers', 'britvic-promo'));
        }
        
        error_log('BPM CSV Parse - Raw CSV headers: ' . print_r($headers, true));
        
        // Clean headers (remove BOM and trim whitespace)
        $headers = array_map(function($header) {
            // Remove BOM characters and trim whitespace - improved BOM handling
            $header = trim($header);
            // Remove UTF-8 BOM if present at start of header
            if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
                $header = substr($header, 3);
            }
            // Remove other control characters
            $header = str_replace(["\x00", "\x0B"], '', $header);
            return trim($header);
        }, $headers);
        
        $parsed_data = [];
        $row_count = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_count++;
            
            if (count($row) !== count($headers)) {
                continue; // Skip malformed rows
            }
            
            $row_data = array_combine($headers, $row);
            $mapped_data = [];
            
            // Apply column mapping or use raw data if no mapping provided
            if (!empty($column_mapping)) {
                $code_counter = 1; // Track multiple code mappings
                foreach ($column_mapping as $csv_column => $field_name) {
                    // Convert string keys to integers for array access
                    $column_index = is_numeric($csv_column) ? (int)$csv_column : $csv_column;
                    if ($field_name && $field_name !== 'none') {
                        // Use the header name to access row_data (since it's an associative array)
                        $header_key = isset($headers[$column_index]) ? $headers[$column_index] : null;
                        if ($header_key && isset($row_data[$header_key])) {
                            // Special handling for 'code' field to preserve multiple codes
                            if ($field_name === 'code') {
                                $mapped_data["code_{$code_counter}"] = $row_data[$header_key];
                                error_log("BPM CSV Parse - Mapped column {$column_index} ({$header_key}) to field code_{$code_counter} with value: {$row_data[$header_key]}");
                                $code_counter++;
                            } else {
                                $mapped_data[$field_name] = $row_data[$header_key];
                                error_log("BPM CSV Parse - Mapped column {$column_index} ({$header_key}) to field {$field_name} with value: {$row_data[$header_key]}");
                            }
                        }
                    }
                }
            } else {
                // No mapping provided - use raw CSV data with sanitized keys
                foreach ($row_data as $column => $value) {
                    $clean_key = $this->sanitize_csv_column_key(trim($column));
                    if (!empty($clean_key)) {
                        $mapped_data[$clean_key] = trim($value); // Include even if value is empty
                    }
                }
            }
            
            // Add custom fields
            // error_log("BPM CSV Import: Processing custom fields for row {$row_count}: " . print_r($custom_fields, true));
            foreach ($custom_fields as $field_name => $value) {
                if (!empty($value)) {
                    // Handle complex custom field objects from JavaScript
                    if (is_array($value) && isset($value['default_value'])) {
                        $mapped_data[$field_name] = $value['default_value'];
                        // error_log("BPM CSV Import: Added custom field {$field_name} = {$value['default_value']} (from object)");
                    } else {
                        $mapped_data[$field_name] = $value;
                        // error_log("BPM CSV Import: Added custom field {$field_name} = {$value} (direct)");
                    }
                }
            }
            // error_log("BPM CSV Import: Row {$row_count} mapped data after custom fields: " . print_r($mapped_data, true));
            
            // Ensure unique_id is auto-generated (will be handled in sanitize_prize_row)
            // Don't require it from mapping or CSV
            
            if (!empty($mapped_data)) {
                $parsed_data[] = $mapped_data;
            }
        }
        
        fclose($handle);
        return $parsed_data;
    }
    
    /**
     * Parse CSV file and return structured data
     *
     * @param string $file_path Path to CSV file
     * @param array $column_mapping Mapping of CSV columns to data fields
     * @return array Parsed data array
     * @throws Exception If parsing fails
     */
    public function parse_csv_file(string $file_path, array $column_mapping = []): array {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot open CSV file for parsing', 'britvic-promo'));
        }
        
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new Exception(__('Cannot read CSV headers', 'britvic-promo'));
        }
        
        $parsed_data = [];
        $row_count = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_count++;
            
            if (count($row) !== count($headers)) {
                continue; // Skip malformed rows
            }
            
            $row_data = array_combine($headers, $row);
            
            // Apply column mapping if provided
            if (!empty($column_mapping)) {
                $mapped_data = [];
                foreach ($column_mapping as $csv_column => $data_field) {
                    $mapped_data[$data_field] = $row_data[$csv_column] ?? '';
                }
                $parsed_data[] = $mapped_data;
            } else {
                $parsed_data[] = $row_data;
            }
        }
        
        fclose($handle);
        return $parsed_data;
    }
    
    /**
     * Generate CSV content from data array
     *
     * @param array $data Data to convert to CSV
     * @param array $headers Column headers
     * @return string CSV content
     * @throws InvalidArgumentException If data structure is invalid
     */
    public function generate_csv_content(array $data, array $headers): string {
        if (empty($data)) {
            throw new InvalidArgumentException(__('No data provided for CSV generation', 'britvic-promo'));
        }
        
        if (empty($headers)) {
            throw new InvalidArgumentException(__('No headers provided for CSV generation', 'britvic-promo'));
        }
        
        $output = fopen('php://temp', 'w');
        if (!$output) {
            throw new Exception(__('Cannot create temporary output stream', 'britvic-promo'));
        }
        
        // Write headers
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($data as $row) {
            if (is_array($row)) {
                // Ensure row has same number of columns as headers
                $ordered_row = [];
                foreach ($headers as $header) {
                    $ordered_row[] = $row[$header] ?? '';
                }
                fputcsv($output, $ordered_row);
            } else {
                fputcsv($output, (array) $row);
            }
        }
        
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    /**
     * Save CSV content to file
     *
     * @param string $content CSV content
     * @param string $filename Filename for the CSV file
     * @param string $directory Directory to save file (optional)
     * @return array File information with path and URL
     * @throws Exception If file cannot be saved
     */
    public function save_csv_file(string $content, string $filename, string $directory = ''): array {
        $save_directory = $directory ?: $this->upload_dir;
        
        // Ensure directory exists
        if (!file_exists($save_directory)) {
            wp_mkdir_p($save_directory);
        }
        
        // Sanitize filename
        $filename = sanitize_file_name($filename);
        $file_path = $save_directory . $filename;
        
        // Write content to file
        $bytes_written = file_put_contents($file_path, $content);
        
        if ($bytes_written === false) {
            throw new Exception(__('Failed to save CSV file', 'britvic-promo'));
        }
        
        // Generate download URL
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $download_url = $upload_dir['baseurl'] . $relative_path;
        
        return [
            'file_path' => $file_path,
            'filename' => $filename,
            'download_url' => $download_url,
            'file_size' => $bytes_written,
            'created_at' => current_time('mysql')
        ];
    }
    
    /**
     * Get CSV column mapping for data type
     *
     * @param string $data_type Type of data ('prizes', 'entries', 'winners')
     * @return array Column mapping configuration
     */
    public function get_column_mapping(string $data_type): array {
        return match($data_type) {
            'prizes' => [
                'code' => 'Prize Code',
                'code_2' => 'Prize Code 2',
                'prize' => 'Prize Type',
                'value' => 'Prize Value',
                'pin' => 'Prize PIN',
                'date' => 'Date',
                'claimed' => 'Claimed',
                'unique_id' => 'Unique ID',
                'source_route' => 'Source Route'
            ],
            'entries' => [
                'id' => 'Entry ID',
                'name' => 'Name',
                'email' => 'Email',
                'date_created' => 'Entry Date',
                'ip' => 'IP Address',
                'status' => 'Status'
            ],
            'winners' => [
                'id' => 'Entry ID',
                'name' => 'Winner Name',
                'email' => 'Winner Email',
                'prize' => 'Prize Won',
                'prize_name' => 'Prize Name',
                'code' => 'Prize Code',
                'date_won' => 'Date Won'
            ],
            default => []
        };
    }
    
    /**
     * Sanitize CSV data for database insertion
     *
     * @param array $data Raw CSV data
     * @param string $data_type Type of data being sanitized
     * @return array Sanitized data
     */
    public function sanitize_csv_data(array $data, string $data_type): array {
        $sanitized = [];
        
        foreach ($data as $row) {
            $sanitized_row = match($data_type) {
                'prizes' => $this->sanitize_prize_row($row),
                'entries' => $this->sanitize_entry_row($row),
                default => $row
            };
            
            if ($sanitized_row) {
                $sanitized[] = $sanitized_row;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get maximum file size allowed for uploads
     *
     * @return int Maximum file size in bytes
     */
    public function get_max_file_size(): int {
        return min(
            self::MAX_FILE_SIZE,
            wp_max_upload_size()
        );
    }
    
    /**
     * Get allowed MIME types for CSV files
     *
     * @return array Array of allowed MIME types
     */
    public function get_allowed_mime_types(): array {
        return self::ALLOWED_MIME_TYPES;
    }
    
    /**
     * Validate export parameters
     */
    private function validate_export_parameters(int $competition_id, string $export_type): void {
        if ($competition_id <= 0) {
            throw new InvalidArgumentException(__('Invalid competition ID', 'britvic-promo'));
        }
        
        $valid_types = ['prizes', 'entries', 'winners'];
        if (!in_array($export_type, $valid_types, true)) {
            throw new InvalidArgumentException(
                sprintf(__('Invalid export type. Allowed: %s', 'britvic-promo'), implode(', ', $valid_types))
            );
        }
    }
    
    /**
     * Get prizes data for export
     */
    private function get_prizes_data(int $competition_id): array {
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

        $prizes = get_posts($args);
        $data = [];

        foreach ($prizes as $prize) {
            $data[] = [
                'unique_id' => get_post_meta($prize->ID, 'unique_id', true),
                'date' => get_post_meta($prize->ID, 'date', true),
                'prize' => get_post_meta($prize->ID, 'prize', true),
                'value' => get_post_meta($prize->ID, 'value', true),
                'code' => get_post_meta($prize->ID, 'code', true),
                'code_2' => get_post_meta($prize->ID, 'code_2', true),
                'claimed' => get_post_meta($prize->ID, 'claimed', true)
            ];
        }

        return $data;
    }
    
    /**
     * Get entries data for export
     */
    private function get_entries_data(int $competition_id): array {
        // This would integrate with Gravity Forms
        // Implementation depends on how you want to link competition_id with form entries
        return [];
    }
    
    /**
     * Get winners data for export
     */
    private function get_winners_data(int $competition_id): array {
        // Get winners from Gravity Forms entries where prizes were awarded
        return [];
    }
    
    /**
     * Validate uploaded file
     */
    private function validate_uploaded_file(array $file_data): void {
        if (!isset($file_data['tmp_name']) || !is_uploaded_file($file_data['tmp_name'])) {
            throw new InvalidArgumentException(__('No valid file uploaded', 'britvic-promo'));
        }
        
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(__('File upload error', 'britvic-promo'));
        }
        
        if ($file_data['size'] > $this->get_max_file_size()) {
            throw new InvalidArgumentException(
                sprintf(__('File too large. Maximum size: %s', 'britvic-promo'), size_format($this->get_max_file_size()))
            );
        }
        
        $mime_type = mime_content_type($file_data['tmp_name']);
        if (!in_array($mime_type, $this->get_allowed_mime_types(), true)) {
            throw new InvalidArgumentException(__('Invalid file type. Please upload a CSV file.', 'britvic-promo'));
        }
    }
    
    /**
     * Handle file upload and move to secure location
     */
    private function handle_file_upload(array $file_data): string {
        $filename = 'temp_' . uniqid() . '.csv';
        $temp_file = $this->upload_dir . $filename;
        
        if (!move_uploaded_file($file_data['tmp_name'], $temp_file)) {
            throw new Exception(__('Failed to move uploaded file', 'britvic-promo'));
        }
        
        return $temp_file;
    }
    
    /**
     * Get suggested columns for data type (flexible recommendations)
     */
    private function get_suggested_columns(string $data_type): array {
        return match($data_type) {
            'prizes' => ['code', 'prize', 'value', 'pin', 'date', 'claimed'], // Common prize columns
            'entries' => ['name', 'email', 'date_created', 'ip'],
            default => []
        };
    }
    
    /**
     * Get required columns for data type (minimal requirements)
     */
    private function get_required_columns(string $data_type): array {
        return match($data_type) {
            'prizes' => [], // No strict requirements - be completely flexible
            'entries' => [], // No strict requirements
            default => []
        };
    }
    
    /**
     * Validate individual CSV row
     */
    private function validate_csv_row(array $row, array $headers, string $data_type, int $row_number): array {
        $errors = [];
        $row_data = array_combine($headers, $row);
        
        if ($data_type === 'prizes') {
            // Validate date format
            if (isset($row_data['date']) && !empty($row_data['date'])) {
                $date = DateTime::createFromFormat('d/m/Y H:i', $row_data['date']);
                if (!$date) {
                    $errors[] = sprintf(__('Row %d: Invalid date format', 'britvic-promo'), $row_number);
                }
            }
            
            // Note: unique_id is auto-generated in sanitize_prize_row, so no validation needed
        }
        
        return $errors;
    }
    
    /**
     * Count total rows in CSV file
     */
    private function count_csv_rows(string $file_path): int {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return 0;
        }
        
        $count = 0;
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        
        fclose($handle);
        return $count;
    }
    
    /**
     * Sanitize prize row data (completely dynamic)
     */
    private function sanitize_prize_row(array $row): ?array {
        $sanitized = [];
        
        // Dynamically sanitize all columns in the row
        foreach ($row as $column => $value) {
            $clean_column = sanitize_key($column);
            
            // Special handling for known fields
            if (in_array($clean_column, ['claimed', 'is_claimed'])) {
                $sanitized[$clean_column] = in_array(strtolower($value ?? ''), ['true', '1', 'yes', 'claimed']) ? 'true' : 'false';
            } elseif (in_array($clean_column, ['value', 'prize_value', 'amount'])) {
                $sanitized[$clean_column] = sanitize_text_field($value ?? '');
            } elseif (in_array($clean_column, ['date', 'prize_date', 'win_date'])) {
                $sanitized[$clean_column] = sanitize_text_field($value ?? '');
            } else {
                // Generic text field sanitization
                $sanitized[$clean_column] = sanitize_text_field($value ?? '');
            }
        }
        
        // Ensure we have at least some data in the row (excluding unique_id)
        $non_empty_values = array_filter($sanitized, function($value, $key) {
            return $key !== 'unique_id' && !empty(trim($value));
        }, ARRAY_FILTER_USE_BOTH);
        
        if (empty($non_empty_values)) {
            return null; // Skip completely empty rows
        }
        
        // Generate hash-based unique_id if not present (only for valid rows)
        if (empty($sanitized['unique_id'])) {
            // Use auto-generated date if available (from apply_auto_date_generation)
            // This aligns with business logic: prizes are distributed over time
            $date_component = $sanitized['win_date_auto'] ?? $sanitized['date'] ?? null;
            
            if (!empty($date_component)) {
                // Use the auto-generated date as primary uniqueness (prizes are time-distributed)
                $timestamp = strtotime($date_component);
                if ($timestamp === false) {
                    $timestamp = microtime(true); // Fallback if date parsing fails
                }
                
                // Primary uniqueness from auto-generated timestamp + code
                $base_id = $timestamp . '_' . ($sanitized['code'] ?? 'NOCODE');
                
                // Minimal entropy for edge cases
                $collision_protection = mt_rand(100, 999);
                
                $sanitized['unique_id'] = 'BPM_' . hash('md5', $base_id . '_' . $collision_protection);
            } else {
                // Fallback for rows without auto-generated dates
                $unique_components = [
                    microtime(true),               // Microsecond precision timestamp
                    $sanitized['code'] ?? '',      // Primary code from dynamic extraction
                    $sanitized['prize'] ?? '',     // Prize type for additional distinction
                    mt_rand(1000000, 9999999)      // Large random number for collision prevention
                ];
                
                $hash_base = implode('_', $unique_components);
                $sanitized['unique_id'] = 'BPM_' . hash('sha256', $hash_base);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize entry row data
     */
    private function sanitize_entry_row(array $row): ?array {
        $sanitized = [
            'name' => sanitize_text_field($row['name'] ?? ''),
            'email' => sanitize_email($row['email'] ?? ''),
            'date_created' => sanitize_text_field($row['date_created'] ?? ''),
            'ip' => sanitize_text_field($row['ip'] ?? ''),
            'status' => sanitize_text_field($row['status'] ?? '')
        ];
        
        // Skip rows with missing required data
        if (empty($sanitized['name']) || empty($sanitized['email'])) {
            return null;
        }
        
        return $sanitized;
    }
    
    /**
     * Process prize import in batches
     */
    private function process_prize_import_batches(array $data, int $competition_id): array {
        $batches = array_chunk($data, self::MAX_BATCH_SIZE);
        $total_success = 0;
        $total_errors = 0;
        $total_skipped = 0;
        $all_errors = [];
        
        foreach ($batches as $batch_index => $batch) {
            $batch_result = $this->process_prize_batch($batch, $competition_id, $batch_index);
            $total_success += $batch_result['success_count'];
            $total_errors += $batch_result['error_count'];
            $total_skipped += $batch_result['skipped_count'];
            $all_errors = array_merge($all_errors, $batch_result['errors']);
        }
        
        return [
            'success_count' => $total_success,
            'error_count' => $total_errors,
            'skipped_count' => $total_skipped,
            'errors' => $all_errors,
            'total_processed' => count($data)
        ];
    }
    
    /**
     * Process single batch of prize imports
     */
    private function process_prize_batch(array $batch, int $competition_id, int $batch_index): array {
        $success_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        $errors = [];
        
        foreach ($batch as $row_index => $prize_data) {
            try {
                // Check if prize already exists by unique_id or code
                $primary_code = $this->extract_primary_code($prize_data);
                if ($this->prize_exists($prize_data['unique_id'], $competition_id) || $this->prize_exists_by_code($primary_code, $competition_id)) {
                    $skipped_count++;
                    continue;
                }
                
                // Create prize post
                $post_id = $this->create_prize_from_csv($prize_data, $competition_id);
                if ($post_id) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = sprintf('Failed to create prize for row %d', $row_index + 1);
                }
                
            } catch (Exception $e) {
                $error_count++;
                $errors[] = sprintf('Row %d: %s', $row_index + 1, $e->getMessage());
            }
        }
        
        return [
            'success_count' => $success_count,
            'error_count' => $error_count,
            'skipped_count' => $skipped_count,
            'errors' => $errors
        ];
    }
    
    /**
     * Check if prize already exists
     */
    private function prize_exists(string $unique_id, int $competition_id): bool {
        $existing = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'unique_id',
                    'value' => $unique_id,
                    'compare' => '='
                ],
                [
                    'key' => 'competition_id',
                    'value' => $competition_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        return !empty($existing);
    }

    /**
     * Check if prize already exists by code
     */
    private function prize_exists_by_code(string $code, int $competition_id): bool {
        // If code is empty, don't consider it a duplicate - allow the import
        if (empty(trim($code))) {
            return false;
        }
        
        global $wpdb;
        $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prize_entries_table} WHERE code = %s AND competition_id = %d",
            $code,
            $competition_id
        ));

        return !empty($existing);
    }
    
    /**
     * Create prize post from CSV data (completely dynamic)
     */
    private function create_prize_from_csv(array $prize_data, int $competition_id): int|false {
        // error_log("BPM CSV Import: Creating prize from data: " . print_r($prize_data, true));
        
        // Determine post title from available data (using new mapped field names)
        $title_parts = [];
        
        // Use prize name from distribution if available
        if (!empty($prize_data['prize_name_from_distribution'])) {
            $title_parts[] = $prize_data['prize_name_from_distribution'];
        }
        
        // Add value if available
        if (!empty($prize_data['value'])) {
            $title_parts[] = '£' . $prize_data['value'];
        }
        
        // Add date if available (use auto-generated date)
        if (!empty($prize_data['win_date_auto'])) {
            $title_parts[] = date('d/m/Y', strtotime($prize_data['win_date_auto']));
        }
        
        $post_title = !empty($title_parts) ? implode(' - ', $title_parts) : 'Prize ' . $prize_data['unique_id'];
        
        // Create basic post structure
        $post_data = [
            'post_title' => $post_title,
            'post_type' => 'prize',
            'post_status' => 'publish',
            'meta_input' => [
                'unique_id' => $prize_data['unique_id'],
                'competition_id' => $competition_id,
                'imported_at' => current_time('mysql'),
                'csv_source' => 'csv_import'
            ]
        ];
        
        // Add date if available (use auto-generated date - already in Y-m-d H:i:s format)
        if (!empty($prize_data['win_date_auto'])) {
            // Auto-generated dates are already in the correct MySQL format, no conversion needed
            $post_data['meta_input']['date'] = $prize_data['win_date_auto'];
            // error_log("BPM CSV Import: Setting date for prize {$prize_data['unique_id']}: {$prize_data['win_date_auto']}");
        }
        
        // Add primary code if available 
        if (!empty($prize_data['code'])) {
            $post_data['meta_input']['code'] = $prize_data['code'];
        }
        
        // Add value if available
        if (!empty($prize_data['value'])) {
            $post_data['meta_input']['value'] = $prize_data['value'];
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create prize: ' . $post_id->get_error_message());
        }
        
        // Debug logging
        // error_log("BPM CSV Import: Created prize post ID {$post_id} for competition {$competition_id}");
        
        // Disable auto sync during import to prevent date overwriting
        remove_action('updated_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10);
        remove_action('added_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10);
        
        // Store ALL CSV columns as meta fields (dynamic approach)
        foreach ($prize_data as $column => $value) {
            if (!empty($value) && $column !== 'unique_id') {
                // Use ACF update_field if ACF is available, otherwise use standard meta
                if (function_exists('update_field')) {
                    update_field($column, $value, $post_id);
                } else {
                    update_post_meta($post_id, $column, $value);
                }
            }
        }
        
        // Map specific fields that the display expects
        $this->set_display_meta_fields($post_id, $prize_data, $competition_id);
        
        // Create ACF field groups dynamically if ACF is available
        $this->maybe_create_acf_fields($prize_data, $competition_id);
        
        // Sync to prize entries table
        $this->sync_to_prize_entries_table($post_id, $prize_data, $competition_id);
        
        // Re-enable auto sync after import is complete
        add_action('updated_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10, 4);
        add_action('added_post_meta', 'bpm_auto_sync_prize_on_meta_update', 10, 4);
        
        return $post_id;
    }
    
    /**
     * Import prizes in batch for progress bar functionality
     */
    public function import_prizes_batch(string $temp_file, int $competition_id, array $column_mapping, array $custom_fields, string $import_type, int $batch_number, int $batch_size): array {
        // Parse CSV data with column mapping
        $parsed_data = $this->parse_csv_file_with_mapping($temp_file, $column_mapping, $custom_fields, $import_type);
        
        // Apply Prize Type Distribution if configured
        $parsed_data = $this->apply_prize_type_distribution($parsed_data, $competition_id);
        
        // Apply Auto Date Generation if configured
        $parsed_data = $this->apply_auto_date_generation($parsed_data, $competition_id);
        
        // Get the batch slice
        $start = $batch_number * $batch_size;
        $batch_data = array_slice($parsed_data, $start, $batch_size);
        
        // Sanitize batch data
        $sanitized_data = $this->sanitize_csv_data($batch_data, 'prizes');
        
        // Process batch
        $import_result = $this->process_prize_import_batches($sanitized_data, $competition_id);
        
        return $import_result;
    }
    
    /**
     * Prepare uploaded file for batch processing
     */
    public function prepare_file_for_batch(array $file_data): string {
        $this->validate_uploaded_file($file_data);
        return $this->handle_file_upload($file_data);
    }
    
    /**
     * Count data rows in CSV file (excluding header)
     */
    public function count_data_rows(string $file_path): int {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return 0;
        }
        
        // Skip header row
        fgetcsv($handle);
        
        $count = 0;
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        
        fclose($handle);
        return $count;
    }
    
    /**
     * Maybe create ACF fields dynamically based on CSV structure
     *
     * @param array $prize_data Sample prize data to determine fields
     * @param int $competition_id Competition ID for field group naming
     */
    private function maybe_create_acf_fields(array $prize_data, int $competition_id): void {
        // Only proceed if ACF Pro is available
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        
        $field_group_key = "group_prizes_competition_{$competition_id}";
        
        // Check if field group already exists
        if (acf_get_field_group($field_group_key)) {
            return;
        }
        
        $fields = [];
        $field_position = 0;
        
        foreach ($prize_data as $column => $value) {
            if (empty($column) || $column === 'unique_id') {
                continue;
            }
            
            $field_key = "field_prize_{$column}_{$competition_id}";
            $field_type = $this->determine_acf_field_type($column, $value);
            
            $fields[] = [
                'key' => $field_key,
                'label' => ucwords(str_replace(['_', '-'], ' ', $column)),
                'name' => $column,
                'type' => $field_type,
                'instructions' => '',
                'required' => in_array($column, ['code', 'prize']) ? 1 : 0,
                'conditional_logic' => 0,
                'wrapper' => [
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ],
                'menu_order' => $field_position++,
            ];
        }
        
        if (!empty($fields)) {
            acf_add_local_field_group([
                'key' => $field_group_key,
                'title' => "Prize Fields - Competition {$competition_id}",
                'fields' => $fields,
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'prize',
                        ],
                    ],
                ],
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
                'description' => "Dynamically generated fields for Competition {$competition_id} prizes",
            ]);
        }
    }
    
    /**
     * Determine appropriate ACF field type based on column name and sample value
     *
     * @param string $column Column name
     * @param string $value Sample value
     * @return string ACF field type
     */
    private function determine_acf_field_type(string $column, string $value): string {
        // Date fields
        if (in_array($column, ['date', 'prize_date', 'win_date', 'expiry_date'])) {
            return 'date_time_picker';
        }
        
        // Number fields
        if (in_array($column, ['value', 'prize_value', 'amount', 'pin']) || is_numeric($value)) {
            return 'number';
        }
        
        // Boolean fields
        if (in_array($column, ['claimed', 'is_claimed', 'active', 'enabled'])) {
            return 'true_false';
        }
        
        // URL fields
        if (in_array($column, ['url', 'link', 'website']) || filter_var($value, FILTER_VALIDATE_URL)) {
            return 'url';
        }
        
        // Email fields
        if (in_array($column, ['email', 'contact_email']) || filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        // Long text fields
        if (in_array($column, ['description', 'details', 'notes', 'comments']) || strlen($value) > 100) {
            return 'textarea';
        }
        
        // Default to text
        return 'text';
    }
    
    /**
     * Sync imported prize to the prize entries table
     *
     * @param int $post_id WordPress post ID
     * @param array $prize_data Prize data from CSV
     * @param int $competition_id Competition ID
     */
    private function sync_to_prize_entries_table(int $post_id, array $prize_data, int $competition_id): void {
        global $wpdb;
        
        $prize_entries_table = $wpdb->prefix . 'bpm_prize_entries';
        
        // Determine win date based on mapping
        $win_date = current_time('mysql'); // default fallback
        
        if (!empty($prize_data['win_date_auto'])) {
            // Use auto-generated date (already processed by apply_auto_date_generation)
            // error_log("BPM CSV Import: Sync table - Prize {$post_id} - Raw win_date_auto: " . $prize_data['win_date_auto']);
            $win_date = $this->convert_date_format($prize_data['win_date_auto']);
            // error_log("BPM CSV Import: Sync table - Prize {$post_id} - Converted win_date: " . $win_date);
        } elseif (!empty($prize_data['win_date_from_file']) || !empty($prize_data['date'])) {
            // Use date from file
            $date_value = $prize_data['win_date_from_file'] ?? $prize_data['date'];
            $win_date = $this->convert_date_format($date_value);
            // error_log("BPM CSV Import: Sync table - Prize {$post_id} - Using file date: " . $win_date);
        }
        
        // Prepare data for prize entries table (match actual table schema)
        $primary_code = $this->extract_primary_code($prize_data);
        
        // Note: The 'code' field in prize_entries table is a normalized primary identifier
        // that stores whatever the dynamically detected primary code is (from code1, voucher_id, etc.)
        // This allows all existing prize lookup queries to work regardless of original column naming
        
        $route_raw = strtolower( trim( (string) ( $prize_data['source_route'] ?? '' ) ) );
        $source_route = in_array( $route_raw, [ 'main', 'alt', 'both' ], true ) ? $route_raw : 'both';

        // prize_type = 'cash' or 'merch' (drives Stripe-vs-merch routing in the theme).
        // Source key is `prize_type_from_file` — set by the admin import UI's
        // "Prize Type (cash/merch)" mapping option (see prizes.php field-name-select).
        // prize_name = human-readable label (e.g. 'Pepsi Max Pint', 'T-shirt') for display.
        $raw_prize_type = strtolower( trim( (string) ( $prize_data['prize_type_from_file'] ?? '' ) ) );
        $resolved_prize_type = in_array( $raw_prize_type, [ 'cash', 'merch' ], true ) ? $raw_prize_type : 'merch';

        $entry_data = [
            'competition_id' => $competition_id,
            'prize_post_id' => $post_id,
            'unique_id' => $prize_data['unique_id'],
            'code' => !empty($primary_code) ? $primary_code : null, // Normalized primary identifier
            'prize_type' => $resolved_prize_type,
            'prize_name' => $this->determine_prize_name($prize_data),
            'date_available' => $win_date,
            'claimed' => 0,
            'source_route' => $source_route,
            'original_data' => json_encode($prize_data), // Store all original data as JSON
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        // Insert into prize entries table
        $result = $wpdb->insert(
            $prize_entries_table,
            $entry_data,
            [
                '%d', // competition_id
                '%d', // prize_post_id
                '%s', // unique_id
                '%s', // code
                '%s', // prize_type
                '%s', // prize_name
                '%s', // date_available
                '%d', // claimed
                '%s', // source_route
                '%s', // original_data
                '%s', // created_at
                '%s'  // updated_at
            ]
        );
        
        if ($result === false) {
            $error_msg = $wpdb->last_error ?: 'Unknown database error';
            error_log("BPM CSV Import ERROR: Failed to sync prize to entries table for post ID {$post_id}. Error: {$error_msg}");
            error_log("BPM CSV Import ERROR: Entry data was: " . print_r($entry_data, true));
            
            // Check for specific constraint violations
            if (strpos($error_msg, 'idx_unique_id') !== false) {
                error_log("BPM CSV Import ERROR: Duplicate unique_id detected: {$entry_data['unique_id']}");
            }
            if (strpos($error_msg, 'idx_code') !== false || strpos($error_msg, 'code') !== false) {
                error_log("BPM CSV Import ERROR: Duplicate code detected: {$entry_data['code']}");
            }
            
            // Throw exception to be caught by the batch processor
            throw new Exception("Database sync failed: {$error_msg}");
        }
    }
    
    /**
     * Set meta fields that the prizes display expects
     * Maps CSV data to the specific fields the frontend looks for
     */
    private function set_display_meta_fields(int $post_id, array $prize_data, int $competition_id): void {
        // Map dynamically detected primary identifier to normalized 'code' field
        // This could be from code1, voucher_id, ticket_serial, reference_number, etc.
        $primary_code = $this->extract_primary_code($prize_data);
        if (!empty($primary_code)) {
            update_post_meta($post_id, 'code', $primary_code);
        }
        
        // Persist the explicit prize value if mapped
        if (!empty($prize_data['value'])) {
            update_post_meta($post_id, 'value', $prize_data['value']);
        }

        // Display name (e.g. "Pepsi Max Pint") — what admin lists & templates read.
        $prize_name = $this->determine_prize_name($prize_data);
        if (!empty($prize_name)) {
            update_post_meta($post_id, 'prize', $prize_name);
        }

        // Cash/merch kind — used by the Stripe-vs-merch routing in the theme.
        // Sourced from the import UI's "Prize Type (cash/merch)" mapping.
        $raw_kind = strtolower( trim( (string) ( $prize_data['prize_type_from_file'] ?? '' ) ) );
        if ( in_array( $raw_kind, [ 'cash', 'merch' ], true ) ) {
            update_post_meta($post_id, 'prize_type', $raw_kind);
        }
        
        // Store the original prize name from distribution for reference
        if (!empty($prize_data['prize_name_from_distribution'])) {
            update_post_meta($post_id, 'prize_name', $prize_data['prize_name_from_distribution']);
        }
        
        // Store prize name from file if available
        if (!empty($prize_data['prize_name_from_file'])) {
            update_post_meta($post_id, 'prize_name_from_file', $prize_data['prize_name_from_file']);
        }
        
        // Set default status as unclaimed
        update_post_meta($post_id, 'claimed', 'false');
        
        // Map date if available
        if (!empty($prize_data['date']) || !empty($prize_data['win_date_from_file'])) {
            $date_value = $prize_data['date'] ?? $prize_data['win_date_from_file'];
            $formatted_date = $this->convert_date_format($date_value);
            update_post_meta($post_id, 'date', $formatted_date);
        } elseif (!empty($prize_data['win_date_auto'])) {
            // Use the already-generated auto date from apply_auto_date_generation()
            // error_log("BPM CSV Import: Prize {$post_id} - Raw win_date_auto value: " . $prize_data['win_date_auto']);
            $formatted_date = $this->convert_date_format($prize_data['win_date_auto']);
            // error_log("BPM CSV Import: Prize {$post_id} - Formatted date: " . $formatted_date);
            update_post_meta($post_id, 'date', $formatted_date);
        }
        
        // Store ALL mapped data as JSON for flexible access
        // Include ALL fields including date and prize mapping fields that the display needs
        $mapped_data = [];
        
        foreach ($prize_data as $field_name => $field_value) {
            // Store all fields, including the important mapping fields like win_date_from_file, etc.
            if (!empty($field_value) || $field_value === '0') { // Include zero values but not empty strings
                $mapped_data[$field_name] = $field_value;
            }
        }
        
        // Always store the complete mapped data as JSON - this is what the display uses
        if (!empty($mapped_data)) {
            update_post_meta($post_id, 'mapped_data', json_encode($mapped_data, JSON_UNESCAPED_UNICODE));
        }

        // Backfill every alias location (postmeta date / win_date_*,
        // mapped_data, bpm_prize_entries) from the just-imported values so the
        // admin sort + display agree and no row is left desynced post-import.
        if ( class_exists( 'BPM_Prize_Sync' ) ) {
            BPM_Prize_Sync::backfill_post( (int) $post_id );
        }
    }
    
    /**
     * Sanitize CSV column names to valid field keys
     * Handles spaces and special characters properly for your CSV format
     */
    private function sanitize_csv_column_key(string $column): string {
        // Handle your specific column names like "code 1", "code 2", etc.
        $clean = strtolower(trim($column));
        $clean = preg_replace('/[^a-z0-9\s]/', '', $clean); // Remove special chars except spaces
        $clean = preg_replace('/\s+/', '_', $clean); // Replace spaces with underscores
        $clean = trim($clean, '_'); // Remove leading/trailing underscores
        
        return $clean;
    }
    
    /**
     * Extract primary code from any identifier column
     * 
     * This function dynamically detects the primary identifier from any column that could
     * contain codes, vouchers, tickets, etc. The result is stored in the normalized 'code'
     * field in both post meta and the prize_entries table for consistent lookups.
     * 
     * Supports any naming convention: code1, voucher_id, ticket_serial, reference_number, etc.
     */
    private function extract_primary_code(array $prize_data): string {
        // Fully dynamic approach - find any fields that could contain codes/vouchers/identifiers
        $potential_code_fields = [];
        
        // Get all available fields from the data
        foreach (array_keys($prize_data) as $field_name) {
            // Skip obviously non-code fields
            $skip_patterns = [
                'prize', 'value', 'amount', 'date', 'time', 'claimed', 'status', 
                'name', 'description', 'category', 'type', 'unique_id', 'id'
            ];
            
            $should_skip = false;
            foreach ($skip_patterns as $pattern) {
                if (stripos($field_name, $pattern) !== false) {
                    // Don't skip if it contains identifier keywords
                    $identifier_keywords = ['code', 'voucher', 'ticket', 'coupon', 'token', 'serial', 'number', 'ref'];
                    $has_identifier = false;
                    foreach ($identifier_keywords as $keyword) {
                        if (stripos($field_name, $keyword) !== false) {
                            $has_identifier = true;
                            break;
                        }
                    }
                    
                    if (!$has_identifier) {
                        $should_skip = true;
                        break;
                    }
                }
            }
            
            if (!$should_skip) {
                $potential_code_fields[] = $field_name;
            }
        }
        
        // Sort fields to prioritize likely code fields and lower numbers
        usort($potential_code_fields, function($a, $b) {
            // Priority scoring system
            $score_a = $this->calculate_code_field_priority($a);
            $score_b = $this->calculate_code_field_priority($b);
            
            return $score_a - $score_b;
        });
        
        // Return the first non-empty field
        foreach ($potential_code_fields as $field) {
            if (!empty($prize_data[$field])) {
                return $prize_data[$field];
            }
        }
        
        return ''; // No code found
    }
    
    /**
     * Calculate priority score for code field selection
     * Lower score = higher priority
     */
    private function calculate_code_field_priority(string $field_name): int {
        $score = 1000; // Base score
        
        // High priority keywords (lower scores = higher priority)
        $high_priority = ['code', 'voucher', 'ticket', 'coupon', 'token', 'serial', 'ref'];
        foreach ($high_priority as $keyword) {
            if (stripos($field_name, $keyword) !== false) {
                $score -= 500;
                break;
            }
        }
        
        // Medium priority for generic identifiers
        $medium_priority = ['number', 'num', 'id'];
        foreach ($medium_priority as $keyword) {
            if (stripos($field_name, $keyword) !== false) {
                $score -= 200;
                break;
            }
        }
        
        // Extract numbers for ordering (code1 before code2, etc.)
        preg_match('/(\d+)/', $field_name, $matches);
        if (isset($matches[1])) {
            $score += (int)$matches[1]; // Lower numbers get lower scores
        }
        
        // Exact matches get highest priority
        if (in_array(strtolower($field_name), ['code', 'voucher', 'ticket'])) {
            $score = 1;
        }
        
        // Prefer shorter field names (likely to be primary identifiers)
        $score += strlen($field_name);
        
        return $score;
    }
    
    /**
     * Determine the human-readable prize name (e.g. "Pepsi Max Pint", "T-shirt").
     * This is the display label, NOT the cash/merch kind — that's `prize_type`.
     */
    private function determine_prize_name(array $prize_data): string {
        if (!empty($prize_data['prize_name_from_distribution'])) {
            return $prize_data['prize_name_from_distribution'];
        }

        if (!empty($prize_data['prize_name_from_file'])) {
            return $prize_data['prize_name_from_file'];
        }

        if (!empty($prize_data['prize'])) {
            return $prize_data['prize'];
        }

        if (!empty($prize_data['value'])) {
            return $prize_data['value'];
        }

        return 'Unknown Prize';
    }
    
    /**
     * Apply Prize Type Distribution to imported data
     * This distributes configured prize types among imported prizes
     */
    private function apply_prize_type_distribution(array $parsed_data, int $competition_id): array {
        // Get saved prize types for this competition
        $saved_prize_types = get_option("bpm_generation_prize_types_{$competition_id}", []);
        
        // error_log("BPM CSV Import: Saved prize types for competition {$competition_id}: " . print_r($saved_prize_types, true));
        
        if (empty($saved_prize_types)) {
            // error_log("BPM CSV Import: No prize types configured for competition {$competition_id}, skipping distribution");
            return $parsed_data; // No distribution to apply
        }
        
        // Check if any field is mapped to 'prize_name_from_distribution'
        $has_distribution_mapping = false;
        foreach ($parsed_data as $row) {
            if (array_key_exists('prize_name_from_distribution', $row)) {
                $has_distribution_mapping = true;
                break;
            }
        }
        
        // error_log("BPM CSV Import: Has distribution mapping: ' . ($has_distribution_mapping ? 'YES' : 'NO'));
        
        if (!$has_distribution_mapping) {
            // error_log("BPM CSV Import: No prize distribution mapping found, skipping prize type generation");
            return $parsed_data; // No distribution mapping found
        }
        
        // error_log("BPM CSV Import: Applying prize type distribution for " . count($parsed_data) . " rows");
        
        // Get prize type distribution configuration
        $distribution = [];
        foreach ($saved_prize_types as $type) {
            $dist_value = get_option("bpm_distribution_{$competition_id}_{$type['name']}", 0);
            // error_log("BPM CSV Import: Distribution setting for {$type['name']}: {$dist_value}");
            if ($dist_value > 0) {
                $distribution[$type['name']] = (int)$dist_value;
            }
        }
        
        // error_log("BPM CSV Import: Distribution array after settings: " . print_r($distribution, true));
        
        if (empty($distribution)) {
            // If no specific distribution set, distribute evenly
            $total_rows = count($parsed_data);
            $types_count = count($saved_prize_types);
            $per_type = floor($total_rows / $types_count);
            $remainder = $total_rows % $types_count;
            
            // error_log("BPM CSV Import: No specific distribution, distributing {$total_rows} rows evenly among {$types_count} types ({$per_type} per type, {$remainder} remainder)");
            
            foreach ($saved_prize_types as $i => $type) {
                $distribution[$type['name']] = $per_type + ($i < $remainder ? 1 : 0);
            }
        }
        
        // error_log("BPM CSV Import: Final distribution: " . print_r($distribution, true));
        
        // Apply distribution
        $type_counters = array_fill_keys(array_keys($distribution), 0);
        $type_limits = $distribution;
        
        foreach ($parsed_data as $index => &$row) {
            if (array_key_exists('prize_name_from_distribution', $row)) {
                // Find next available prize type
                $assigned_type = null;
                foreach ($type_limits as $type_name => $limit) {
                    if ($type_counters[$type_name] < $limit) {
                        $assigned_type = $type_name;
                        $type_counters[$type_name]++;
                        break;
                    }
                }
                
                // If no type available, leave empty (no fallback)
                if (!$assigned_type) {
                    $assigned_type = null;
                }
                
                $row['prize_name_from_distribution'] = $assigned_type;
                // error_log("BPM CSV Import: Assigned prize type '{$assigned_type}' to row {$index}");
            }
        }
        
        // error_log("BPM CSV Import: Completed prize type distribution");
        return $parsed_data;
    }
    
    /**
     * Apply auto date generation to parsed data
     * Replaces 'win_date_auto' placeholder values with actual generated dates
     */
    private function apply_auto_date_generation(array $parsed_data, int $competition_id): array {
        // Check if any field is mapped to 'win_date_auto'
        $has_auto_date_mapping = false;
        foreach ($parsed_data as $row) {
            if (array_key_exists('win_date_auto', $row)) {
                $has_auto_date_mapping = true;
                break;
            }
        }
        
        if (!$has_auto_date_mapping) {
            // error_log("BPM CSV Import: No auto date mapping found, skipping date generation");
            return $parsed_data; // No auto date mapping found
        }
        
        // error_log("BPM CSV Import: Applying auto date generation for " . count($parsed_data) . " rows");
        
        // Generate random dates for all rows that need them
        $generated_dates = $this->generate_random_dates_for_import(count($parsed_data), $competition_id);
        
        // Apply generated dates
        $date_index = 0;
        foreach ($parsed_data as $index => &$row) {
            if (array_key_exists('win_date_auto', $row)) {
                $generated_date = $generated_dates[$date_index] ?? $this->generate_auto_date($competition_id);
                $row['win_date_auto'] = $generated_date;
                // error_log("BPM CSV Import: Generated date for row {$index}: {$generated_date}");
                $date_index++;
            }
        }
        
        // error_log("BPM CSV Import: Completed auto date generation for {$date_index} rows");
        return $parsed_data;
    }
    
    /**
     * Generate multiple random dates for import based on date range settings
     */
    private function generate_random_dates_for_import(int $count, int $competition_id): array {
        // Get Prize Date Generation settings
        $start_date = get_option("bpm_date_range_start_{$competition_id}", '');
        $end_date = get_option("bpm_date_range_end_{$competition_id}", '');
        
        if (empty($start_date) || empty($end_date)) {
            // Use default date range if none configured (past 30 days to future 60 days)
            $start_timestamp = strtotime('-30 days');
            $end_timestamp = strtotime('+60 days');
            // error_log("BPM CSV Import: No date range configured for competition {$competition_id}, using default range");
        } else {
            $start_timestamp = strtotime($start_date);
            $end_timestamp = strtotime($end_date);
            // error_log("BPM CSV Import: Using configured date range for competition {$competition_id}: {$start_date} to {$end_date}");
        }
        
        if ($start_timestamp === false || $end_timestamp === false) {
            throw new Exception("Invalid date range configuration for competition {$competition_id}");
        }
        
        if ($start_timestamp > $end_timestamp) {
            throw new Exception("Start date must be before end date for competition {$competition_id}");
        }
        
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $random_timestamp = rand($start_timestamp, $end_timestamp);
            $dates[] = date('Y-m-d H:i:s', $random_timestamp);
        }
        
        return $dates;
    }
    
    /**
     * Generate auto date based on competition's Prize Date Generation settings
     */
    private function generate_auto_date(int $competition_id): string {
        // Get Prize Date Generation settings for this competition
        $start_date = get_option("bpm_date_range_start_{$competition_id}", '');
        $end_date = get_option("bpm_date_range_end_{$competition_id}", '');
        
        if (empty($start_date) || empty($end_date)) {
            // If no date range configured, generate random dates within a reasonable default range
            // Use past 30 days to future 60 days as default range
            $start_timestamp = strtotime('-30 days');
            $end_timestamp = strtotime('+60 days');
            $random_timestamp = mt_rand($start_timestamp, $end_timestamp);
            // error_log("BPM CSV Import: No date range configured, using default range. Generated: " . date('Y-m-d H:i:s', $random_timestamp));
            return date('Y-m-d H:i:s', $random_timestamp);
        }
        
        // Generate a random date within the configured range
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        
        if ($start_timestamp && $end_timestamp && $end_timestamp > $start_timestamp) {
            $random_timestamp = mt_rand($start_timestamp, $end_timestamp);
            // error_log("BPM CSV Import: Using configured date range. Generated: " . date('Y-m-d H:i:s', $random_timestamp));
            return date('Y-m-d H:i:s', $random_timestamp);
        }
        
        // Final fallback - should rarely hit this
        // error_log("BPM CSV Import: Date range configuration invalid, using current time");
        return current_time('mysql');
    }
    
    /**
     * Convert date format from CSV to MySQL format (flexible parsing)
     */
    private function convert_date_format(string $date): string {
        if (empty($date)) {
            // error_log("BPM CSV Import: convert_date_format received empty date, returning current time");
            return current_time('mysql');
        }
        
        // error_log("BPM CSV Import: convert_date_format processing date: " . $date);
        
        // If the date is already in MySQL format, return it as-is
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)) {
            // error_log("BPM CSV Import: Date already in MySQL format, returning as-is: " . $date);
            return $date;
        }
        
        // Try multiple date formats. UK formats (d-m-Y, d/m/Y) are listed BEFORE
        // the US fallbacks so ambiguous values like "05-06-2026" resolve to UK
        // (5 June), matching the WP-wide DD-MM-YYYY convention. Falling through
        // to strtotime() at the end is risky — it interprets dashed dates as
        // YYYY-DD-MM in some locales — so make sure every expected input has an
        // explicit format here.
        $formats = [
            'Y-m-d H:i:s',   // 2025-01-01 12:00:00 (MySQL canonical - check first)
            'd-m-Y H:i:s',   // 02-02-2024 20:59:00 (UK with seconds, dashes)
            'd-m-Y H:i',     // 02-02-2024 20:59    (UK no seconds, dashes)
            'd-m-Y',         // 02-02-2024          (UK date only, dashes)
            'd/m/Y H:i',     // 02/02/2024 20:59    (UK with slashes)
            'd/m/Y',         // 02/02/2024          (UK date only, slashes)
            'Y-m-d',         // 2025-01-01          (ISO date only)
            'm/d/Y H:i',     // 01/01/2025 12:00    (US format)
            'm/d/Y',         // 01/01/2025          (US format)
        ];
        
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        // Last resort - return current time
        return current_time('mysql');
    }
}