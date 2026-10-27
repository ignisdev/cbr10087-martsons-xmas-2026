<?php
/**
 * XLSX Handler Class
 *
 * Handles XLSX file import with column mapping functionality
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
 * XLSX Handler implementation
 */
class BPM_XLSX_Handler {
    
    /**
     * Maximum file size for XLSX uploads (10MB)
     */
    private const MAX_FILE_SIZE = 10485760;
    
    /**
     * Allowed MIME types for XLSX files
     */
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream'
    ];
    
    /**
     * Create secure promotions directory if it doesn't exist
     */
    private function ensure_promotions_directory(): string {
        $promotions_dir = WP_CONTENT_DIR . '/promotions';
        
        if (!file_exists($promotions_dir)) {
            wp_mkdir_p($promotions_dir);
            
            // Create .htaccess to deny direct access
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($promotions_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($promotions_dir . '/index.php', '<?php // Silence is golden');
        }
        
        return $promotions_dir;
    }
    
    /**
     * Store uploaded file securely
     */
    private function store_uploaded_file(array $file): string {
        $promotions_dir = $this->ensure_promotions_directory();
        
        // Generate unique filename with timestamp
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safe_filename = 'upload_' . time() . '_' . wp_generate_password(8, false) . '.' . $file_extension;
        $target_path = $promotions_dir . '/' . $safe_filename;
        
        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            throw new Exception(__('Failed to store uploaded file securely', 'britvic-promo'));
        }
        
        return $target_path;
    }
    
    /**
     * Import prizes from XLSX file (with CSV fallback)
     */
    public function import_prizes(array $file, int $competition_id, array $column_mapping = []): array {
        // Check if we can process XLSX files
        if (!class_exists('ZipArchive')) {
            throw new Exception(__('Excel file processing is not available on this server. Please save your Excel file as CSV format: File > Save As > CSV (Comma delimited), then upload the CSV file instead.', 'britvic-promo'));
        }
        
        $this->validate_file($file);
        
        // Store file securely first
        $stored_file_path = $this->store_uploaded_file($file);
        
        try {
            // Try to convert XLSX to CSV automatically to avoid formatting issues
            $csv_path = $this->convert_xlsx_to_csv($stored_file_path);
            
            if ($csv_path) {
                // Use CSV handler for reliable import
                $csv_handler = new BPM_CSV_Handler();
                $csv_file_array = [
                    'tmp_name' => $csv_path,
                    'name' => pathinfo($file['name'], PATHINFO_FILENAME) . '.csv',
                    'size' => filesize($csv_path),
                    'error' => UPLOAD_ERR_OK,
                    'type' => 'text/csv'
                ];
                
                $result = $csv_handler->import_prizes($csv_file_array, $competition_id);
                
                // Clean up converted CSV file
                unlink($csv_path);
            } else {
                // Fallback to direct XLSX processing if conversion fails
                $data = $this->read_xlsx_file($stored_file_path);
                
                if (empty($data)) {
                    throw new Exception(__('No data found in XLSX file', 'britvic-promo'));
                }
                
                // Process the data with column mapping
                $processed_data = $this->process_data_with_mapping($data, $column_mapping);
                
                // Import the processed data as prizes
                $result = $this->import_processed_data($processed_data, $competition_id);
            }
            
            // Clean up stored file after successful processing
            if (file_exists($stored_file_path)) {
                unlink($stored_file_path);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // Clean up stored files on error
            if (file_exists($stored_file_path)) {
                unlink($stored_file_path);
            }
            throw $e;
        }
    }
    
    /**
     * Convert XLSX to CSV automatically to prevent formatting issues
     */
    private function convert_xlsx_to_csv(string $xlsx_path): ?string {
        if (!class_exists('ZipArchive')) {
            // Cannot convert without ZipArchive, will use fallback
            return null;
        }
        
        try {
            $data = $this->read_xlsx_file($xlsx_path);
            
            if (empty($data)) {
                return null;
            }
            
            // Create temporary CSV file in secure directory
            $promotions_dir = $this->ensure_promotions_directory();
            $csv_path = $promotions_dir . '/converted_' . time() . '_' . wp_generate_password(8, false) . '.csv';
            
            $csv_handle = fopen($csv_path, 'w');
            if (!$csv_handle) {
                return null;
            }
            
            // Write all data to CSV with enhanced formatting preservation
            foreach ($data as $row) {
                // Ensure all values are properly formatted and preserve long varchar data
                $processed_row = array_map(function($value) {
                    // Handle various Excel data types and formatting issues
                    if (is_null($value) || $value === '') {
                        return '';
                    }
                    
                    // Convert to string and preserve original formatting
                    $string_value = (string) $value;
                    
                    // Handle scientific notation that Excel sometimes applies to long numbers
                    if (is_numeric($value) && strlen($string_value) > 10 && strpos($string_value, 'E') !== false) {
                        // Convert scientific notation back to full number
                        $string_value = number_format((float) $value, 0, '', '');
                    }
                    
                    // Preserve leading zeros (common in codes like "000123")
                    if (is_numeric($value) && strpos((string) $value, '0') === 0) {
                        $string_value = (string) $value;
                    }
                    
                    // Remove Excel formatting artifacts but preserve actual content
                    $clean_value = trim($string_value);
                    
                    // Ensure long varchar fields are preserved
                    return $clean_value;
                }, $row);
                
                fputcsv($csv_handle, $processed_row);
            }
            
            fclose($csv_handle);
            
            return $csv_path;
            
        } catch (Exception $e) {
            // Conversion failed, return null to use fallback
            return null;
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validate_file(array $file): void {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(__('File upload failed', 'britvic-promo'));
        }
        
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new Exception(__('File too large. Maximum size is 10MB', 'britvic-promo'));
        }
        
        $mime_type = mime_content_type($file['tmp_name']);
        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES, true)) {
            // Check if ZipArchive is available to provide better error message
            if (!class_exists('ZipArchive')) {
                throw new Exception(__('This server does not support XLSX files. Please use CSV format instead. You can export your Excel file as CSV: File > Save As > CSV (Comma delimited).', 'britvic-promo'));
            }
            throw new Exception(__('Invalid file type. Please upload an XLSX file', 'britvic-promo'));
        }
    }
    
    /**
     * Read XLSX file using multiple methods (with pure PHP fallback)
     */
    public function read_xlsx_file(string $file_path): array {
        // Try ZipArchive method first if available
        if (class_exists('ZipArchive')) {
            try {
                return $this->read_xlsx_with_zip($file_path);
            } catch (Exception $e) {
                // Fall through to alternative methods
            }
        }
        
        // Try alternative parsing methods
        return $this->read_xlsx_fallback($file_path);
    }
    
    /**
     * Read XLSX using ZipArchive (original method)
     */
    private function read_xlsx_with_zip(string $file_path): array {
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            throw new Exception(__('Cannot open XLSX file', 'britvic-promo'));
        }
        
        // Read the shared strings (if any)
        $shared_strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared_strings_xml) {
                $shared_strings = $this->parse_shared_strings($shared_strings_xml);
            }
        }
        
        // Read the first worksheet
        $worksheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$worksheet_xml) {
            throw new Exception(__('Cannot read worksheet data', 'britvic-promo'));
        }
        
        $zip->close();
        
        return $this->parse_worksheet($worksheet_xml, $shared_strings);
    }
    
    /**
     * Fallback XLSX reader without ZipArchive - suggest user save as CSV
     */
    private function read_xlsx_fallback(string $file_path): array {
        // Since we can't parse XLSX without ZipArchive, provide helpful error
        throw new Exception(__('Excel file processing requires additional server extensions. Please save your Excel file as CSV format: File > Save As > CSV (Comma delimited), then upload the CSV file instead.', 'britvic-promo'));
    }
    
    /**
     * Parse shared strings XML
     */
    private function parse_shared_strings(string $xml): array {
        $strings = [];
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        $si_nodes = $dom->getElementsByTagName('si');
        foreach ($si_nodes as $index => $si_node) {
            $t_node = $si_node->getElementsByTagName('t')->item(0);
            if ($t_node) {
                $strings[$index] = $t_node->nodeValue;
            }
        }
        
        return $strings;
    }
    
    /**
     * Parse worksheet XML and extract data
     */
    private function parse_worksheet(string $xml, array $shared_strings): array {
        $data = [];
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        $row_nodes = $dom->getElementsByTagName('row');
        foreach ($row_nodes as $row_node) {
            $row_data = [];
            $cell_nodes = $row_node->getElementsByTagName('c');
            
            foreach ($cell_nodes as $cell_node) {
                $cell_reference = $cell_node->getAttribute('r');
                $cell_type = $cell_node->getAttribute('t');
                
                $value_node = $cell_node->getElementsByTagName('v')->item(0);
                if ($value_node) {
                    $value = $value_node->nodeValue;
                    
                    // If it's a shared string, look it up
                    if ($cell_type === 's' && isset($shared_strings[(int)$value])) {
                        $value = $shared_strings[(int)$value];
                    }
                    
                    // Extract column letter and convert to index
                    $column = preg_replace('/[0-9]+/', '', $cell_reference);
                    $column_index = $this->column_letter_to_index($column);
                    
                    $row_data[$column_index] = $value;
                }
            }
            
            if (!empty($row_data)) {
                $data[] = $row_data;
            }
        }
        
        return $data;
    }
    
    /**
     * Convert column letter (A, B, C...) to numeric index (0, 1, 2...)
     */
    private function column_letter_to_index(string $column): int {
        $index = 0;
        $length = strlen($column);
        
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - ord('A') + 1);
        }
        
        return $index - 1; // Convert to 0-based index
    }
    
    /**
     * Process raw data with column mapping
     */
    private function process_data_with_mapping(array $data, array $column_mapping): array {
        if (empty($data)) {
            return [];
        }
        
        $header_row = array_shift($data); // First row is headers
        $processed_data = [];
        
        foreach ($data as $row) {
            $mapped_row = [];
            
            foreach ($column_mapping as $source_column => $target_field) {
                if ($target_field && $target_field !== 'ignore') {
                    $column_index = is_numeric($source_column) ? (int)$source_column : array_search($source_column, $header_row);
                    
                    if ($column_index !== false && isset($row[$column_index])) {
                        $mapped_row[$target_field] = $row[$column_index];
                    }
                }
            }
            
            if (!empty($mapped_row)) {
                $processed_data[] = $mapped_row;
            }
        }
        
        return $processed_data;
    }
    
    /**
     * Import processed data as prizes
     */
    private function import_processed_data(array $data, int $competition_id): array {
        $created_count = 0;
        $errors = [];
        
        foreach ($data as $row_index => $row) {
            try {
                // Create prize post
                $prize_data = [
                    'post_type' => 'prize',
                    'post_status' => 'publish',
                    'post_title' => $row['code'] ?? 'Prize ' . ($row_index + 1),
                ];
                
                $prize_id = wp_insert_post($prize_data);
                
                if (is_wp_error($prize_id)) {
                    throw new Exception($prize_id->get_error_message());
                }
                
                // Add meta fields
                update_post_meta($prize_id, 'competition_id', $competition_id);
                
                // Map common fields
                $field_mapping = [
                    'code' => 'code',
                    'prize' => 'prize',
                    'value' => 'value',
                    'date' => 'date',
                    'winner_id' => 'claimed_by_user_id',
                    'winner_email' => 'claimed_by_email',
                    'claimed' => 'claimed'
                ];
                
                foreach ($field_mapping as $source_field => $meta_key) {
                    if (isset($row[$source_field])) {
                        update_post_meta($prize_id, $meta_key, sanitize_text_field($row[$source_field]));
                    }
                }
                
                // If winner info is provided, mark as claimed
                if (!empty($row['winner_id']) || !empty($row['winner_email'])) {
                    update_post_meta($prize_id, 'claimed', 'true');
                    update_post_meta($prize_id, 'claimed_at', current_time('mysql', true));
                }

                // Mirror every alias location (postmeta date / win_date_*,
                // mapped_data JSON, bpm_prize_entries) so the row is
                // immediately consistent post-import.
                if ( class_exists( 'BPM_Prize_Sync' ) ) {
                    BPM_Prize_Sync::backfill_post( (int) $prize_id );
                }

                $created_count++;
                
            } catch (Exception $e) {
                $errors[] = sprintf(__('Row %d: %s', 'britvic-promo'), $row_index + 1, $e->getMessage());
            }
        }
        
        return [
            'message' => sprintf(__('Import completed. %d prizes created.', 'britvic-promo'), $created_count),
            'created_count' => $created_count,
            'errors' => $errors,
            'total_rows' => count($data)
        ];
    }
    
    /**
     * Get column headers from XLSX file for mapping interface
     */
    public function get_column_headers(array $file): array {
        $this->validate_file($file);
        
        // Store file securely first
        $stored_file_path = $this->store_uploaded_file($file);
        
        try {
            $data = $this->read_xlsx_file($stored_file_path);
            
            if (empty($data)) {
                return [];
            }
            
            // Return first row as headers
            return array_values($data[0]);
            
        } finally {
            // Always clean up the stored file
            if (file_exists($stored_file_path)) {
                unlink($stored_file_path);
            }
        }
    }
    
    /**
     * Get column headers and sample data for analysis (with auto CSV conversion)
     */
    public function analyze_file(array $file): array {
        $this->validate_file($file);
        
        // Store file securely first
        $stored_file_path = $this->store_uploaded_file($file);
        
        try {
            // Try to convert to CSV first for better data preservation
            $csv_path = $this->convert_xlsx_to_csv($stored_file_path);
            
            if ($csv_path) {
                // Use CSV analysis for more reliable results
                $csv_handle = fopen($csv_path, 'r');
                if ($csv_handle) {
                    // Read headers
                    $headers = fgetcsv($csv_handle);
                    
                    // Read sample data
                    $sample_data = [];
                    for ($i = 0; $i < 5; $i++) {
                        $row = fgetcsv($csv_handle);
                        if ($row !== false) {
                            $sample_data[] = $row;
                        }
                    }
                    
                    fclose($csv_handle);
                    unlink($csv_path); // Clean up converted file
                    
                    return [
                        'headers' => $headers ?: [],
                        'sample_data' => $sample_data
                    ];
                }
                
                unlink($csv_path); // Clean up if reading failed
            }
            
            // Fallback to direct XLSX processing
            $data = $this->read_xlsx_file($stored_file_path);
            
            if (empty($data)) {
                return ['headers' => [], 'sample_data' => []];
            }
            
            // First row is headers, next 5 rows are sample data
            $headers = array_values($data[0]);
            $sample_data = array_slice($data, 1, 5);
            
            return [
                'headers' => $headers,
                'sample_data' => $sample_data
            ];
            
        } finally {
            // Always clean up the stored file
            if (file_exists($stored_file_path)) {
                unlink($stored_file_path);
            }
        }
    }
}