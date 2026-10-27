<?php
/**
 * CSV Handler Interface
 *
 * Defines the contract for CSV import/export functionality
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
 * Interface for CSV handling functionality
 */
interface BPM_CSV_Handler_Interface {
    
    /**
     * Export competition data to CSV
     *
     * @param int $competition_id Competition ID
     * @param string $export_type Type of data to export ('prizes', 'entries', 'winners')
     * @return array Result array with file path and download URL
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If export fails
     */
    public function export_data(int $competition_id, string $export_type): array;
    
    /**
     * Import prizes from CSV file
     *
     * @param array $file_data Uploaded file data from $_FILES
     * @param int $competition_id Competition ID to associate prizes with
     * @return array Result array with import statistics and errors
     * @throws InvalidArgumentException If file is invalid
     * @throws Exception If import fails
     */
    public function import_prizes(array $file_data, int $competition_id): array;
    
    /**
     * Validate CSV file structure and content
     *
     * @param string $file_path Path to CSV file
     * @param string $expected_type Expected data type ('prizes', 'entries')
     * @return array Validation result with errors if any
     * @throws Exception If file cannot be read
     */
    public function validate_csv_file(string $file_path, string $expected_type): array;
    
    /**
     * Parse CSV file and return structured data
     *
     * @param string $file_path Path to CSV file
     * @param array $column_mapping Mapping of CSV columns to data fields
     * @return array Parsed data array
     * @throws Exception If parsing fails
     */
    public function parse_csv_file(string $file_path, array $column_mapping = []): array;
    
    /**
     * Generate CSV content from data array
     *
     * @param array $data Data to convert to CSV
     * @param array $headers Column headers
     * @return string CSV content
     * @throws InvalidArgumentException If data structure is invalid
     */
    public function generate_csv_content(array $data, array $headers): string;
    
    /**
     * Save CSV content to file
     *
     * @param string $content CSV content
     * @param string $filename Filename for the CSV file
     * @param string $directory Directory to save file (optional)
     * @return array File information with path and URL
     * @throws Exception If file cannot be saved
     */
    public function save_csv_file(string $content, string $filename, string $directory = ''): array;
    
    /**
     * Get CSV column mapping for data type
     *
     * @param string $data_type Type of data ('prizes', 'entries', 'winners')
     * @return array Column mapping configuration
     */
    public function get_column_mapping(string $data_type): array;
    
    /**
     * Sanitize CSV data for database insertion
     *
     * @param array $data Raw CSV data
     * @param string $data_type Type of data being sanitized
     * @return array Sanitized data
     */
    public function sanitize_csv_data(array $data, string $data_type): array;
    
    /**
     * Get maximum file size allowed for uploads
     *
     * @return int Maximum file size in bytes
     */
    public function get_max_file_size(): int;
    
    /**
     * Get allowed MIME types for CSV files
     *
     * @return array Array of allowed MIME types
     */
    public function get_allowed_mime_types(): array;
}