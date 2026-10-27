<?php
/**
 * Prize Generator Tests
 *
 * Tests for the prize generation functionality and winning outcomes
 *
 * @package BritvicPromotionManager
 * @subpackage Tests
 */

declare(strict_types=1);

class Test_Prize_Generator extends WP_UnitTestCase {
    
    private BPM_Prize_Generator $prize_generator;
    private int $test_competition_id;
    
    public function setUp(): void {
        parent::setUp();
        
        $this->prize_generator = new BPM_Prize_Generator();
        $this->test_competition_id = 999; // Test competition ID
        
        // Create test competition in database
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'bpm_competitions',
            [
                'id' => $this->test_competition_id,
                'name' => 'Test Competition',
                'type' => 'instant_win',
                'status' => 'active'
            ],
            ['%d', '%s', '%s', '%s']
        );
    }
    
    public function tearDown(): void {
        // Clean up test data
        global $wpdb;
        
        // Delete test competition
        $wpdb->delete(
            $wpdb->prefix . 'bpm_competitions',
            ['id' => $this->test_competition_id],
            ['%d']
        );
        
        // Delete test prizes
        $test_prizes = get_posts([
            'post_type' => 'prize',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'competition_id',
                    'value' => $this->test_competition_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        foreach ($test_prizes as $prize) {
            wp_delete_post($prize->ID, true);
        }
        
        parent::tearDown();
    }
    
    /**
     * Test basic prize date generation
     */
    public function test_generate_prize_dates_basic(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-02';
        $start_time = '00:00';
        $end_time = '23:59';
        $num_dates = 10;
        
        $prizes_data = [
            'competition_id' => $this->test_competition_id,
            'types' => [
                ['name' => 'drink', 'value' => '', 'code_prefix' => 'FS']
            ]
        ];
        
        $result = $this->prize_generator->generate_prize_dates(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $num_dates,
            $prizes_data
        );
        
        $this->assertTrue($result['success']);
        $this->assertEquals($num_dates, $result['created_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertNotEmpty($result['created_ids']);
    }
    
    /**
     * Test timestamp generation with various time ranges
     */
    public function test_generate_random_timestamps(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-01';
        $start_time = '10:00';
        $end_time = '12:00';
        $count = 60; // Should have 121 possible minutes (10:00 to 12:00 inclusive)
        
        $timestamps = $this->prize_generator->generate_random_timestamps(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $count
        );
        
        $this->assertCount($count, $timestamps);
        $this->assertTrue($this->are_timestamps_unique($timestamps));
        $this->assertTrue($this->are_timestamps_in_range($timestamps, $start_date, $end_date, $start_time, $end_time));
    }
    
    /**
     * Test available slots calculation
     */
    public function test_get_available_slots(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-01';
        $start_time = '10:00';
        $end_time = '10:59'; // 60 minutes = 60 slots
        
        $slots = $this->prize_generator->get_available_slots(
            $start_date,
            $end_date,
            $start_time,
            $end_time
        );
        
        $this->assertEquals(60, $slots);
    }
    
    /**
     * Test parameter validation
     */
    public function test_parameter_validation(): void {
        // Test invalid date format
        $this->expectException(InvalidArgumentException::class);
        $this->prize_generator->validate_parameters(
            'invalid-date',
            '2025-01-02',
            '00:00',
            '23:59',
            10,
            []
        );
    }
    
    /**
     * Test parameter validation for negative dates count
     */
    public function test_parameter_validation_negative_count(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->prize_generator->validate_parameters(
            '2025-01-01',
            '2025-01-02',
            '00:00',
            '23:59',
            -1,
            []
        );
    }
    
    /**
     * Test parameter validation for invalid time format
     */
    public function test_parameter_validation_invalid_time(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->prize_generator->validate_parameters(
            '2025-01-01',
            '2025-01-02',
            'invalid-time',
            '23:59',
            10,
            []
        );
    }
    
    /**
     * Test creating prize posts
     */
    public function test_create_prize_posts(): void {
        $prize_data = [
            [
                'unique_id' => 'test-001',
                'timestamp' => strtotime('2025-01-01 12:00:00'),
                'date_formatted' => '01/01/2025 12:00',
                'date_mysql' => '2025-01-01 12:00:00',
                'prize' => 'drink',
                'value' => '',
                'code' => 'FS12345',
                'claimed' => false
            ]
        ];
        
        $result = $this->prize_generator->create_prize_posts($prize_data, $this->test_competition_id);
        
        $this->assertCount(1, $result['created']);
        $this->assertEmpty($result['failed']);
        
        // Verify the post was created correctly
        $post_id = $result['created'][0];
        $this->assertIsInt($post_id);
        $this->assertEquals('prize', get_post_type($post_id));
        $this->assertEquals('test-001', get_post_meta($post_id, 'unique_id', true));
        $this->assertEquals('drink', get_post_meta($post_id, 'prize', true));
    }
    
    /**
     * Test prize outcome distribution
     */
    public function test_prize_outcome_distribution(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-01';
        $start_time = '00:00';
        $end_time = '00:59';
        $num_dates = 50;
        
        $prizes_data = [
            'competition_id' => $this->test_competition_id,
            'types' => [
                ['name' => 'drink', 'value' => '', 'code_prefix' => 'FS'],
                ['name' => 'voucher', 'value' => '10', 'code_prefix' => 'VCH']
            ]
        ];
        
        $result = $this->prize_generator->generate_prize_dates(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $num_dates,
            $prizes_data
        );
        
        $this->assertTrue($result['success']);
        
        // Verify prize distribution
        $created_prizes = $this->get_created_prizes($result['created_ids']);
        $prize_types = array_count_values(array_column($created_prizes, 'prize'));
        
        $this->assertGreaterThan(0, count($prize_types));
        $this->assertArrayHasKey('drink', $prize_types);
    }
    
    /**
     * Test CSV export functionality
     */
    public function test_export_to_csv(): void {
        // First create some test prizes
        $this->create_test_prizes();
        
        $result = $this->prize_generator->export_to_csv($this->test_competition_id);
        
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('download_url', $result);
        $this->assertTrue(file_exists($result['file_path']));
        
        // Verify CSV content
        $csv_content = file_get_contents($result['file_path']);
        $this->assertStringContainsString('unique_id,date,prize', $csv_content);
        
        // Clean up
        unlink($result['file_path']);
    }
    
    /**
     * Test edge case: requesting more dates than available slots
     */
    public function test_insufficient_slots_error(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-01';
        $start_time = '10:00';
        $end_time = '10:01'; // Only 2 minutes = 2 slots
        $num_dates = 5; // Requesting more than available
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Requested 5 dates but only 2 slots available');
        
        $this->prize_generator->generate_random_timestamps(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $num_dates
        );
    }
    
    /**
     * Test multi-day prize generation
     */
    public function test_multi_day_generation(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-03'; // 3 days
        $start_time = '10:00';
        $end_time = '10:59'; // 60 minutes per day = 180 total slots
        $num_dates = 150;
        
        $prizes_data = [
            'competition_id' => $this->test_competition_id,
            'types' => [
                ['name' => 'drink', 'value' => '', 'code_prefix' => 'FS']
            ]
        ];
        
        $result = $this->prize_generator->generate_prize_dates(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $num_dates,
            $prizes_data
        );
        
        $this->assertTrue($result['success']);
        $this->assertEquals($num_dates, $result['created_count']);
        
        // Verify dates span multiple days
        $created_prizes = $this->get_created_prizes($result['created_ids']);
        $unique_dates = array_unique(array_map(function($prize) {
            return date('Y-m-d', strtotime($prize['date']));
        }, $created_prizes));
        
        $this->assertGreaterThan(1, count($unique_dates));
    }
    
    /**
     * Test prize code generation
     */
    public function test_prize_code_generation(): void {
        $start_date = '2025-01-01';
        $end_date = '2025-01-01';
        $start_time = '10:00';
        $end_time = '10:09';
        $num_dates = 5;
        
        $prizes_data = [
            'competition_id' => $this->test_competition_id,
            'types' => [
                ['name' => 'drink', 'value' => '', 'code_prefix' => 'TEST']
            ]
        ];
        
        $result = $this->prize_generator->generate_prize_dates(
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $num_dates,
            $prizes_data
        );
        
        $created_prizes = $this->get_created_prizes($result['created_ids']);
        
        foreach ($created_prizes as $prize) {
            $this->assertStringStartsWith('TEST', $prize['code']);
            $this->assertEquals(12, strlen($prize['code'])); // TEST + 8 random chars
        }
        
        // Verify all codes are unique
        $codes = array_column($created_prizes, 'code');
        $this->assertEquals(count($codes), count(array_unique($codes)));
    }
    
    /**
     * Helper method to check if timestamps are unique
     */
    private function are_timestamps_unique(array $timestamps): bool {
        return count($timestamps) === count(array_unique($timestamps));
    }
    
    /**
     * Helper method to check if timestamps are within expected range
     */
    private function are_timestamps_in_range(
        array $timestamps,
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time
    ): bool {
        $start_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$start_date} {$start_time}");
        $end_datetime = DateTime::createFromFormat('Y-m-d H:i', "{$end_date} {$end_time}");
        
        $start_timestamp = $start_datetime->getTimestamp();
        $end_timestamp = $end_datetime->getTimestamp();
        
        foreach ($timestamps as $timestamp) {
            if ($timestamp < $start_timestamp || $timestamp > $end_timestamp) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Helper method to create test prizes
     */
    private function create_test_prizes(): void {
        $test_data = [
            [
                'unique_id' => 'test-csv-001',
                'timestamp' => strtotime('2025-01-01 12:00:00'),
                'date_formatted' => '01/01/2025 12:00',
                'date_mysql' => '2025-01-01 12:00:00',
                'prize' => 'drink',
                'value' => '',
                'code' => 'FS12345',
                'claimed' => false
            ],
            [
                'unique_id' => 'test-csv-002',
                'timestamp' => strtotime('2025-01-01 13:00:00'),
                'date_formatted' => '01/01/2025 13:00',
                'date_mysql' => '2025-01-01 13:00:00',
                'prize' => 'voucher',
                'value' => '10',
                'code' => 'VCH67890',
                'claimed' => false
            ]
        ];
        
        $this->prize_generator->create_prize_posts($test_data, $this->test_competition_id);
    }
    
    /**
     * Helper method to get created prizes data
     */
    private function get_created_prizes(array $post_ids): array {
        $prizes = [];
        
        foreach ($post_ids as $post_id) {
            $prizes[] = [
                'id' => $post_id,
                'unique_id' => get_post_meta($post_id, 'unique_id', true),
                'date' => get_post_meta($post_id, 'date', true),
                'prize' => get_post_meta($post_id, 'prize', true),
                'value' => get_post_meta($post_id, 'value', true),
                'code' => get_post_meta($post_id, 'code', true),
                'claimed' => get_post_meta($post_id, 'claimed', true)
            ];
        }
        
        return $prizes;
    }
}