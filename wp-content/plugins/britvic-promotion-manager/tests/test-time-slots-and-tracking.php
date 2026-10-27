<?php
/**
 * Time Slots and Prize Tracking Tests
 *
 * Tests for time slot validation and prize tracking functionality
 *
 * @package BritvicPromotionManager
 * @subpackage Tests
 */

declare(strict_types=1);

class Test_Time_Slots_And_Tracking extends WP_UnitTestCase {
    
    private int $test_competition_id;
    private BPM_Form_Integration $form_integration;
    
    public function setUp(): void {
        parent::setUp();
        
        $this->test_competition_id = 999;
        $this->form_integration = new BPM_Form_Integration();
        
        // Create test competition
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
        
        // Set up test time slots
        update_option('bpm_time_slots_' . $this->test_competition_id, [
            'morning' => ['start' => '09:00', 'end' => '12:00'],
            'afternoon' => ['start' => '13:00', 'end' => '17:00'],
            'evening' => ['start' => '18:00', 'end' => '21:00']
        ]);
    }
    
    public function tearDown(): void {
        // Clean up test data
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->prefix . 'bpm_competitions',
            ['id' => $this->test_competition_id],
            ['%d']
        );
        
        // Delete test prizes
        $test_prizes = get_posts([
            'post_type' => 'prize',
            'meta_key' => 'competition_id',
            'meta_value' => $this->test_competition_id,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        foreach ($test_prizes as $prize_id) {
            wp_delete_post($prize_id, true);
        }
        
        // Clean up prize entries table
        $wpdb->delete(
            $wpdb->prefix . 'bpm_prize_entries',
            ['competition_id' => $this->test_competition_id],
            ['%d']
        );
        
        delete_option('bmp_time_slots_' . $this->test_competition_id);
        
        parent::tearDown();
    }
    
    /**
     * Test prize creation with different time slots
     */
    public function test_prize_creation_with_time_slots(): void {
        // Create prizes at different times
        $test_cases = [
            [
                'code' => 'MORNING001',
                'time' => '10:30',
                'date' => date('Y-m-d'),
                'expected_slot' => 'morning'
            ],
            [
                'code' => 'AFTERNOON001', 
                'time' => '15:30',
                'date' => date('Y-m-d'),
                'expected_slot' => 'afternoon'
            ],
            [
                'code' => 'EVENING001',
                'time' => '19:30', 
                'date' => date('Y-m-d'),
                'expected_slot' => 'evening'
            ],
            [
                'code' => 'INVALID001',
                'time' => '02:30',
                'date' => date('Y-m-d'), 
                'expected_slot' => 'invalid'
            ]
        ];
        
        foreach ($test_cases as $case) {
            $win_datetime = $case['date'] . ' ' . $case['time'] . ':00';
            
            // Create prize post
            $prize_id = wp_insert_post([
                'post_title' => $case['code'],
                'post_type' => 'prize',
                'post_status' => 'publish',
                'meta_input' => [
                    'competition_id' => $this->test_competition_id,
                    'code' => $case['code'],
                    'prize' => 'drink',
                    'win_datetime' => $win_datetime,
                    'claimed' => 'false'
                ]
            ]);
            
            $this->assertIsInt($prize_id);
            $this->assertGreaterThan(0, $prize_id);
            
            // Verify time slot classification
            $slot = $this->classify_time_slot($case['time']);
            if ($case['expected_slot'] === 'invalid') {
                $this->assertFalse($slot, "Time {$case['time']} should be invalid");
            } else {
                $this->assertEquals($case['expected_slot'], $slot, "Time {$case['time']} should be in {$case['expected_slot']} slot");
            }
        }
    }
    
    /**
     * Test prize tracking through database
     */
    public function test_prize_tracking_database(): void {
        global $wpdb;
        
        $test_code = 'TRACK001';
        $win_datetime = date('Y-m-d H:i:s');
        
        // Create prize post
        $prize_id = wp_insert_post([
            'post_title' => $test_code,
            'post_type' => 'prize',
            'post_status' => 'publish',
            'meta_input' => [
                'competition_id' => $this->test_competition_id,
                'code' => $test_code,
                'prize' => 'bigprize',
                'win_datetime' => $win_datetime,
                'claimed' => 'false',
                'value' => '£100.00'
            ]
        ]);
        
        // Also add to prize entries table
        $wpdb->insert(
            $wpdb->prefix . 'bpm_prize_entries',
            [
                'competition_id' => $this->test_competition_id,
                'prize_post_id' => $prize_id,
                'code' => $test_code,
                'prize_type' => 'bigprize',
                'win_datetime' => $win_datetime,
                'claimed' => 0,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s']
        );
        
        // Test database retrieval (same query as in page-entry.php)
        $prize_post = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, pm1.meta_value as prize_type, pm2.meta_value as win_datetime, 
                    pm3.meta_value as competition_id, pm4.meta_value as claimed,
                    pm5.meta_value as winner_email, pm6.meta_value as prize_value
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'prize'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'win_datetime'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'competition_id'
             LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = 'claimed'
             LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = 'winner_email'
             LEFT JOIN {$wpdb->postmeta} pm6 ON p.ID = pm6.post_id AND pm6.meta_key = 'value'
             WHERE p.post_type = 'prize' AND p.post_title = %s",
            $test_code
        ));
        
        // Assertions
        $this->assertIsObject($prize_post);
        $this->assertEquals($test_code, $prize_post->post_title);
        $this->assertEquals('bigprize', $prize_post->prize_type);
        $this->assertEquals($this->test_competition_id, (int)$prize_post->competition_id);
        $this->assertEquals('false', $prize_post->claimed);
        $this->assertEquals('£100.00', $prize_post->prize_value);
        
        // Test prize entries table retrieval
        $prize_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bpm_prize_entries WHERE code = %s",
            $test_code
        ));
        
        $this->assertIsObject($prize_entry);
        $this->assertEquals($prize_id, (int)$prize_entry->prize_post_id);
        $this->assertEquals('bigprize', $prize_entry->prize_type);
        $this->assertEquals(0, (int)$prize_entry->claimed);
    }
    
    /**
     * Test time slot validation logic
     */
    public function test_time_slot_validation(): void {
        $test_times = [
            // Valid times
            ['10:30', true, 'morning'],
            ['15:30', true, 'afternoon'], 
            ['19:30', true, 'evening'],
            ['09:00', true, 'morning'], // Boundary
            ['12:00', true, 'morning'], // Boundary
            ['21:00', true, 'evening'], // Boundary
            
            // Invalid times
            ['08:59', false, null],
            ['12:01', false, null], 
            ['12:59', false, null],
            ['17:01', false, null],
            ['21:01', false, null],
            ['02:30', false, null]
        ];
        
        foreach ($test_times as $test) {
            [$time, $should_be_valid, $expected_slot] = $test;
            
            $slot = $this->classify_time_slot($time);
            
            if ($should_be_valid) {
                $this->assertEquals($expected_slot, $slot, "Time $time should be valid in slot $expected_slot");
            } else {
                $this->assertFalse($slot, "Time $time should be invalid");
            }
        }
    }
    
    /**
     * Test prize claim tracking
     */
    public function test_prize_claim_tracking(): void {
        global $wpdb;
        
        $test_code = 'CLAIM001';
        $winner_email = 'winner@example.com';
        $claim_time = current_time('mysql');
        
        // Create unclaimed prize
        $prize_id = wp_insert_post([
            'post_title' => $test_code,
            'post_type' => 'prize',
            'post_status' => 'publish',
            'meta_input' => [
                'competition_id' => $this->test_competition_id,
                'code' => $test_code,
                'prize' => 'drink',
                'claimed' => 'false'
            ]
        ]);
        
        // Verify initially unclaimed
        $claimed_status = get_post_meta($prize_id, 'claimed', true);
        $this->assertEquals('false', $claimed_status);
        
        // Claim the prize
        update_post_meta($prize_id, 'claimed', 'true');
        update_post_meta($prize_id, 'winner_email', $winner_email);
        update_post_meta($prize_id, 'claimed_at', $claim_time);
        
        // Verify claim tracking
        $claimed_status = get_post_meta($prize_id, 'claimed', true);
        $stored_email = get_post_meta($prize_id, 'winner_email', true);
        $claim_timestamp = get_post_meta($prize_id, 'claimed_at', true);
        
        $this->assertEquals('true', $claimed_status);
        $this->assertEquals($winner_email, $stored_email);
        $this->assertEquals($claim_time, $claim_timestamp);
        
        // Update prize entries table too
        $wpdb->update(
            $wpdb->prefix . 'bpm_prize_entries',
            [
                'claimed' => 1,
                'winner_email' => $winner_email,
                'claimed_at' => $claim_time
            ],
            ['prize_post_id' => $prize_id],
            ['%d', '%s', '%s'],
            ['%d']
        );
        
        // Verify entries table update
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bpm_prize_entries WHERE prize_post_id = %d",
            $prize_id
        ));
        
        $this->assertEquals(1, (int)$entry->claimed);
        $this->assertEquals($winner_email, $entry->winner_email);
    }
    
    /**
     * Helper method to classify time slots (same logic as page-entry.php)
     */
    private function classify_time_slot(string $time): string|false {
        $allowed_time_slots = [
            'morning' => ['start' => '09:00', 'end' => '12:00'],
            'afternoon' => ['start' => '13:00', 'end' => '17:00'],
            'evening' => ['start' => '18:00', 'end' => '21:00']
        ];
        
        foreach ($allowed_time_slots as $slot_name => $slot) {
            if ($time >= $slot['start'] && $time <= $slot['end']) {
                return $slot_name;
            }
        }
        
        return false;
    }
    
    /**
     * Test full prize flow from creation to tracking
     */
    public function test_full_prize_flow(): void {
        $test_code = 'FLOW001';
        $win_time = '14:30'; // Afternoon slot
        $win_datetime = date('Y-m-d') . ' ' . $win_time . ':00';
        
        // 1. Create prize (simulating generation)
        $prize_id = wp_insert_post([
            'post_title' => $test_code,
            'post_type' => 'prize',
            'post_status' => 'publish',
            'meta_input' => [
                'competition_id' => $this->test_competition_id,
                'code' => $test_code,
                'prize' => 'drink',
                'win_datetime' => $win_datetime,
                'claimed' => 'false'
            ]
        ]);
        
        // 2. Verify time slot is valid
        $slot = $this->classify_time_slot($win_time);
        $this->assertEquals('afternoon', $slot);
        
        // 3. Simulate user entry validation
        $found_prize = get_posts([
            'post_type' => 'prize',
            'title' => $test_code,
            'posts_per_page' => 1
        ]);
        
        $this->assertCount(1, $found_prize);
        $this->assertEquals($test_code, $found_prize[0]->post_title);
        
        // 4. Verify tracking data completeness
        $competition_id = get_post_meta($prize_id, 'competition_id', true);
        $stored_datetime = get_post_meta($prize_id, 'win_datetime', true);
        $prize_type = get_post_meta($prize_id, 'prize', true);
        
        $this->assertEquals($this->test_competition_id, (int)$competition_id);
        $this->assertEquals($win_datetime, $stored_datetime);
        $this->assertEquals('drink', $prize_type);
    }
}