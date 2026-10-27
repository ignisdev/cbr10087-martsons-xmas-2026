<?php
/**
 * CSV Handler Tests
 *
 * Tests for CSV import/export functionality
 *
 * @package BritvicPromotionManager
 * @subpackage Tests
 */

declare(strict_types=1);

class Test_CSV_Handler extends WP_UnitTestCase {
    
    private BPM_CSV_Handler $csv_handler;
    private int $test_competition_id;
    private string $temp_dir;
    
    public function setUp(): void {
        parent::setUp();
        
        $this->csv_handler = new BPM_CSV_Handler();
        $this->test_competition_id = 777;
        $this->temp_dir = sys_get_temp_dir() . '/bpm-tests/';
        
        // Create temp directory
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
        
        // Create test competition
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'bpm_competitions',
            [
                'id' => $this->test_competition_id,
                'name' => 'CSV Test Competition',
                'type' => 'instant_win',
                'status' => 'active'
            ],
            ['%d', '%s', '%s', '%s']
        );
    }
    
    public function tearDown(): void {
        // Clean up test data
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->prefix . 'bpm_competitions',
            ['id' => $this->test_competition_id],
            ['%d']
        );
        
        // Clean up test files
        $this->cleanup_temp_files();
        
        parent::tearDown();
    }
    
    /**
     * Test CSV content generation
     */
    public function test_generate_csv_content(): void {
        $data = [
            ['name' => 'John', 'email' => 'john@example.com', 'age' => '25'],
            ['name' => 'Jane', 'email' => 'jane@example.com', 'age' => '30']
        ];
        
        $headers = ['name', 'email', 'age'];
        
        $csv_content = $this->csv_handler->generate_csv_content($data, $headers);
        
        $this->assertStringContainsString('name,email,age', $csv_content);
        $this->assertStringContainsString('John,john@example.com,25', $csv_content);
        $this->assertStringContainsString('Jane,jane@example.com,30', $csv_content);
    }
    
    /**
     * Test CSV file saving
     */
    public function test_save_csv_file(): void {
        $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";
        $filename = 'test_export.csv';
        
        $result = $this->csv_handler->save_csv_file($content, $filename, $this->temp_dir);
        
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('download_url', $result);
        $this->assertArrayHasKey('file_size', $result);
        $this->assertTrue(file_exists($result['file_path']));
        $this->assertEquals($content, file_get_contents($result['file_path']));
    }
    
    /**
     * Test CSV file parsing
     */
    public function test_parse_csv_file(): void {
        $csv_content = "unique_id,date,prize,value,code\ntest-001,01/01/2025 12:00,drink,,FS12345\ntest-002,01/01/2025 13:00,voucher,10,VCH67890";
        $csv_file = $this->temp_dir . 'test_parse.csv';
        file_put_contents($csv_file, $csv_content);
        
        $parsed_data = $this->csv_handler->parse_csv_file($csv_file);
        
        $this->assertCount(2, $parsed_data);
        $this->assertEquals('test-001', $parsed_data[0]['unique_id']);
        $this->assertEquals('drink', $parsed_data[0]['prize']);
        $this->assertEquals('test-002', $parsed_data[1]['unique_id']);
        $this->assertEquals('voucher', $parsed_data[1]['prize']);
    }
    
    /**
     * Test CSV file validation
     */
    public function test_validate_csv_file(): void {
        $valid_csv = "unique_id,date,prize,value,code\ntest-001,01/01/2025 12:00,drink,,FS12345";
        $csv_file = $this->temp_dir . 'valid_test.csv';
        file_put_contents($csv_file, $valid_csv);
        
        $validation_result = $this->csv_handler->validate_csv_file($csv_file, 'prizes');
        
        $this->assertTrue($validation_result['valid']);
        $this->assertEmpty($validation_result['errors']);
        $this->assertEquals(1, $validation_result['row_count']);
    }
    
    /**
     * Test CSV file validation with errors
     */
    public function test_validate_csv_file_with_errors(): void {
        $invalid_csv = "wrong_header,date,prize\ntest-001,invalid-date,drink";
        $csv_file = $this->temp_dir . 'invalid_test.csv';
        file_put_contents($csv_file, $invalid_csv);
        
        $validation_result = $this->csv_handler->validate_csv_file($csv_file, 'prizes');
        
        $this->assertFalse($validation_result['valid']);
        $this->assertNotEmpty($validation_result['errors']);
        $this->assertStringContainsString('Missing required columns', implode(' ', $validation_result['errors']));
    }
    
    /**
     * Test prize data sanitization
     */
    public function test_sanitize_csv_data(): void {
        $raw_data = [
            [
                'unique_id' => 'test-001',
                'date' => '01/01/2025 12:00',
                'prize' => 'drink',
                'value' => '',
                'code' => 'FS12345',
                'claimed' => 'false'
            ],
            [
                'unique_id' => 'test-002',
                'date' => '01/01/2025 13:00',
                'prize' => 'voucher',
                'value' => '10',
                'code' => 'VCH67890',
                'claimed' => 'true'
            ],
            [
                'unique_id' => '', // Should be filtered out
                'date' => '',
                'prize' => '',
                'value' => '',
                'code' => '',
                'claimed' => 'false'
            ]
        ];
        
        $sanitized = $this->csv_handler->sanitize_csv_data($raw_data, 'prizes');
        
        $this->assertCount(2, $sanitized); // Third row should be filtered out
        $this->assertEquals('test-001', $sanitized[0]['unique_id']);
        $this->assertEquals('false', $sanitized[0]['claimed']);
        $this->assertEquals('true', $sanitized[1]['claimed']);
    }
    
    /**
     * Test column mapping retrieval
     */
    public function test_get_column_mapping(): void {
        $prize_mapping = $this->csv_handler->get_column_mapping('prizes');
        
        $this->assertArrayHasKey('unique_id', $prize_mapping);
        $this->assertArrayHasKey('date', $prize_mapping);
        $this->assertArrayHasKey('prize', $prize_mapping);
        $this->assertArrayHasKey('value', $prize_mapping);
        $this->assertArrayHasKey('code', $prize_mapping);
        $this->assertArrayHasKey('claimed', $prize_mapping);
    }
    
    /**
     * Test maximum file size validation
     */
    public function test_get_max_file_size(): void {
        $max_size = $this->csv_handler->get_max_file_size();
        
        $this->assertIsInt($max_size);
        $this->assertGreaterThan(0, $max_size);
        $this->assertLessThanOrEqual(10485760, $max_size); // Should not exceed 10MB
    }
    
    /**
     * Test allowed MIME types
     */
    public function test_get_allowed_mime_types(): void {
        $mime_types = $this->csv_handler->get_allowed_mime_types();
        
        $this->assertIsArray($mime_types);
        $this->assertContains('text/csv', $mime_types);
        $this->assertContains('text/plain', $mime_types);
    }
    
    /**
     * Test prize import with valid data
     */
    public function test_import_prizes_success(): void {
        $csv_content = "unique_id,date,prize,value,code,claimed\ntest-001,01/01/2025 12:00,drink,,FS12345,false\ntest-002,01/01/2025 13:00,voucher,10,VCH67890,false";
        $csv_file = $this->temp_dir . 'import_test.csv';
        file_put_contents($csv_file, $csv_content);
        
        // Simulate $_FILES array
        $file_data = [
            'tmp_name' => $csv_file,
            'name' => 'import_test.csv',
            'size' => filesize($csv_file),
            'error' => UPLOAD_ERR_OK,
            'type' => 'text/csv'
        ];
        
        // Mock the file upload process
        copy($csv_file, $csv_file . '_uploaded');
        $file_data['tmp_name'] = $csv_file . '_uploaded';
        
        $result = $this->csv_handler->import_prizes($file_data, $this->test_competition_id);
        
        $this->assertEquals(2, $result['success_count']);
        $this->assertEquals(0, $result['error_count']);
        $this->assertEquals(0, $result['skipped_count']);
    }
    
    /**
     * Test export data for prizes
     */
    public function test_export_data_prizes(): void {
        // First create some test prizes
        $this->create_test_prizes();
        
        $result = $this->csv_handler->export_data($this->test_competition_id, 'prizes');
        
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('download_url', $result);
        $this->assertTrue(file_exists($result['file_path']));
        
        // Verify CSV content
        $csv_content = file_get_contents($result['file_path']);
        $this->assertStringContainsString('unique_id', $csv_content);
        $this->assertStringContainsString('test-export-001', $csv_content);
    }
    
    /**
     * Test export with invalid parameters
     */
    public function test_export_invalid_parameters(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->csv_handler->export_data(0, 'invalid_type');
    }
    
    /**
     * Test import with invalid file
     */
    public function test_import_invalid_file(): void {
        $this->expectException(InvalidArgumentException::class);
        
        $file_data = [
            'tmp_name' => '/nonexistent/file.csv',
            'name' => 'test.csv',
            'size' => 1000,
            'error' => UPLOAD_ERR_NO_FILE,
            'type' => 'text/csv'
        ];
        
        $this->csv_handler->import_prizes($file_data, $this->test_competition_id);
    }
    
    /**
     * Test handling of large CSV files
     */
    public function test_large_csv_handling(): void {
        // Create a CSV with many rows
        $csv_content = "unique_id,date,prize,value,code,claimed\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csv_content .= sprintf("test-%03d,01/01/2025 %02d:00,drink,,FS%03d,false\n", $i, ($i % 24), $i);
        }
        
        $csv_file = $this->temp_dir . 'large_test.csv';
        file_put_contents($csv_file, $csv_content);
        
        $validation_result = $this->csv_handler->validate_csv_file($csv_file, 'prizes');
        
        $this->assertTrue($validation_result['valid']);
        $this->assertEquals(1000, $validation_result['row_count']);
        $this->assertNotEmpty($validation_result['warnings']); // Should warn about large file
    }
    
    /**
     * Test CSV parsing with malformed rows
     */
    public function test_csv_parsing_malformed_rows(): void {
        $csv_content = "unique_id,date,prize,value,code\ntest-001,01/01/2025 12:00,drink,,FS12345\ntest-002,01/01/2025 13:00\ntest-003,01/01/2025 14:00,voucher,10,VCH67890";
        $csv_file = $this->temp_dir . 'malformed_test.csv';
        file_put_contents($csv_file, $csv_content);
        
        $parsed_data = $this->csv_handler->parse_csv_file($csv_file);
        
        // Should skip malformed row (test-002) and return only valid rows
        $this->assertCount(2, $parsed_data);
        $this->assertEquals('test-001', $parsed_data[0]['unique_id']);
        $this->assertEquals('test-003', $parsed_data[1]['unique_id']);
    }
    
    /**
     * Helper method to create test prizes
     */
    private function create_test_prizes(): void {
        $prize_generator = new BPM_Prize_Generator();
        
        $test_data = [
            [
                'unique_id' => 'test-export-001',
                'timestamp' => strtotime('2025-01-01 12:00:00'),
                'date_formatted' => '01/01/2025 12:00',
                'date_mysql' => '2025-01-01 12:00:00',
                'prize' => 'drink',
                'value' => '',
                'code' => 'FS12345',
                'claimed' => false
            ],
            [
                'unique_id' => 'test-export-002',
                'timestamp' => strtotime('2025-01-01 13:00:00'),
                'date_formatted' => '01/01/2025 13:00',
                'date_mysql' => '2025-01-01 13:00:00',
                'prize' => 'voucher',
                'value' => '10',
                'code' => 'VCH67890',
                'claimed' => false
            ]
        ];
        
        $prize_generator->create_prize_posts($test_data, $this->test_competition_id);
    }
    
    /**
     * Helper method to clean up temporary files
     */
    private function cleanup_temp_files(): void {
        if (file_exists($this->temp_dir)) {
            $files = glob($this->temp_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->temp_dir);
        }
    }
}