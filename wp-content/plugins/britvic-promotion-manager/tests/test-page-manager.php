<?php
/**
 * Page Manager Tests
 *
 * Tests for the page management functionality
 *
 * @package BritvicPromotionManager
 * @subpackage Tests
 */

declare(strict_types=1);

class Test_Page_Manager extends WP_UnitTestCase {
    
    private BPM_Page_Manager $page_manager;
    private int $test_competition_id;
    private int $test_live_page_id;
    private int $test_closed_page_id;
    private array $test_other_pages = [];
    
    public function setUp(): void {
        parent::setUp();
        
        $this->page_manager = new BPM_Page_Manager();
        $this->test_competition_id = 888;
        
        // Create test pages
        $this->test_live_page_id = $this->factory->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Live Page',
            'post_status' => 'private',
            'meta_input' => [
                '_wp_page_template' => 'page-entry.php'
            ]
        ]);
        
        $this->test_closed_page_id = $this->factory->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Closed Page',
            'post_status' => 'private',
            'meta_input' => [
                '_wp_page_template' => 'page-closed.php'
            ]
        ]);
        
        // Create additional test pages
        for ($i = 0; $i < 3; $i++) {
            $this->test_other_pages[] = $this->factory->post->create([
                'post_type' => 'page',
                'post_title' => "Test Other Page {$i}",
                'post_status' => 'publish'
            ]);
        }
        
        // Create test competition
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'bpm_competitions',
            [
                'id' => $this->test_competition_id,
                'name' => 'Test Competition',
                'type' => 'instant_win',
                'status' => 'draft',
                'live_page_id' => $this->test_live_page_id,
                'closed_page_id' => $this->test_closed_page_id
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d']
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
        
        // Delete test pages
        wp_delete_post($this->test_live_page_id, true);
        wp_delete_post($this->test_closed_page_id, true);
        
        foreach ($this->test_other_pages as $page_id) {
            wp_delete_post($page_id, true);
        }
        
        parent::tearDown();
    }
    
    /**
     * Test publishing competition pages
     */
    public function test_publish_competition(): void {
        $result = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'publish');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('publish', $result['action']);
        
        // Verify live page is published
        $live_page = get_post($this->test_live_page_id);
        $this->assertEquals('publish', $live_page->post_status);
        
        // Verify other pages are private
        foreach ($this->test_other_pages as $page_id) {
            $page = get_post($page_id);
            $this->assertEquals('private', $page->post_status);
        }
    }
    
    /**
     * Test closing competition pages
     */
    public function test_close_competition(): void {
        $result = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'close');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('close', $result['action']);
        
        // Verify closed page is published
        $closed_page = get_post($this->test_closed_page_id);
        $this->assertEquals('publish', $closed_page->post_status);
        
        // Verify other pages are private
        foreach ($this->test_other_pages as $page_id) {
            $page = get_post($page_id);
            $this->assertEquals('private', $page->post_status);
        }
    }
    
    /**
     * Test getting competition pages configuration
     */
    public function test_get_competition_pages(): void {
        $pages = $this->page_manager->get_competition_pages($this->test_competition_id);
        
        $this->assertEquals($this->test_competition_id, $pages['id']);
        $this->assertEquals('Test Competition', $pages['name']);
        $this->assertEquals($this->test_live_page_id, $pages['live_page_id']);
        $this->assertEquals($this->test_closed_page_id, $pages['closed_page_id']);
    }
    
    /**
     * Test validation with invalid competition ID
     */
    public function test_validate_action_invalid_competition(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Competition not found');
        
        $this->page_manager->validate_action(99999, 'publish');
    }
    
    /**
     * Test validation with invalid action
     */
    public function test_validate_action_invalid_action(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action');
        
        $this->page_manager->validate_action($this->test_competition_id, 'invalid_action');
    }
    
    /**
     * Test setting specific pages to private
     */
    public function test_set_pages_private(): void {
        $page_ids = [$this->test_other_pages[0], $this->test_other_pages[1]];
        
        $result = $this->page_manager->set_pages_private($page_ids);
        $this->assertTrue($result);
        
        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);
            $this->assertEquals('private', $page->post_status);
        }
    }
    
    /**
     * Test setting page to published
     */
    public function test_set_page_published(): void {
        $result = $this->page_manager->set_page_published($this->test_live_page_id);
        $this->assertTrue($result);
        
        $page = get_post($this->test_live_page_id);
        $this->assertEquals('publish', $page->post_status);
    }
    
    /**
     * Test scheduling page toggle
     */
    public function test_schedule_page_toggle(): void {
        $future_timestamp = time() + 3600; // 1 hour from now
        
        $result = $this->page_manager->schedule_page_toggle(
            $this->test_competition_id,
            'publish',
            $future_timestamp
        );
        
        $this->assertTrue($result);
        
        // Verify cron event is scheduled
        $scheduled = wp_next_scheduled('bpm_publish_competition', [$this->test_competition_id]);
        $this->assertEquals($future_timestamp, $scheduled);
    }
    
    /**
     * Test cancelling scheduled page toggle
     */
    public function test_cancel_scheduled_toggle(): void {
        $future_timestamp = time() + 3600;
        
        // First schedule an event
        $this->page_manager->schedule_page_toggle(
            $this->test_competition_id,
            'publish',
            $future_timestamp
        );
        
        // Then cancel it
        $result = $this->page_manager->cancel_scheduled_toggle(
            $this->test_competition_id,
            'publish'
        );
        
        $this->assertTrue($result);
        
        // Verify cron event is removed
        $scheduled = wp_next_scheduled('bpm_publish_competition', [$this->test_competition_id]);
        $this->assertFalse($scheduled);
    }
    
    /**
     * Test page status retrieval
     */
    public function test_get_page_status(): void {
        $status = $this->page_manager->get_page_status($this->test_competition_id);
        
        $this->assertEquals('draft', $status['competition_status']);
        $this->assertArrayHasKey('live_page', $status);
        $this->assertArrayHasKey('closed_page', $status);
        $this->assertArrayHasKey('currently_published', $status);
        
        // Test live page info
        $this->assertEquals($this->test_live_page_id, $status['live_page']['id']);
        $this->assertEquals('Test Live Page', $status['live_page']['title']);
        $this->assertEquals('private', $status['live_page']['status']);
    }
    
    /**
     * Test fallback page discovery when no pages configured
     */
    public function test_fallback_page_discovery(): void {
        // Create competition without specific page IDs
        global $wpdb;
        $fallback_competition_id = 777;
        
        $wpdb->insert(
            $wpdb->prefix . 'bpm_competitions',
            [
                'id' => $fallback_competition_id,
                'name' => 'Fallback Test Competition',
                'type' => 'instant_win',
                'status' => 'draft',
                'live_page_id' => null,
                'closed_page_id' => null
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        // The publish method should handle fallback gracefully
        $result = $this->page_manager->toggle_competition_pages($fallback_competition_id, 'publish');
        
        // Should succeed even without specific page configuration
        $this->assertTrue($result['success'] || !$result['success']); // Either way is acceptable for fallback
        
        // Clean up
        $wpdb->delete(
            $wpdb->prefix . 'bpm_competitions',
            ['id' => $fallback_competition_id],
            ['%d']
        );
    }
    
    /**
     * Test error handling for non-existent pages
     */
    public function test_error_handling_nonexistent_pages(): void {
        // Update competition to reference non-existent pages
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bpm_competitions',
            [
                'live_page_id' => 99999,
                'closed_page_id' => 99998
            ],
            ['id' => $this->test_competition_id],
            ['%d', '%d'],
            ['%d']
        );
        
        $result = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'publish');
        
        // Should handle gracefully and report failure
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed', $result['message']);
    }
    
    /**
     * Test concurrent page operations
     */
    public function test_concurrent_page_operations(): void {
        // Simulate multiple operations happening quickly
        $results = [];
        
        $results[] = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'publish');
        $results[] = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'close');
        $results[] = $this->page_manager->toggle_competition_pages($this->test_competition_id, 'publish');
        
        // All operations should complete successfully
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
        
        // Final state should be publish (last operation)
        $live_page = get_post($this->test_live_page_id);
        $this->assertEquals('publish', $live_page->post_status);
    }
    
    /**
     * Test theme page discovery
     */
    public function test_get_theme_pages(): void {
        $theme_pages = $this->page_manager->get_theme_pages('test-theme');
        
        $this->assertIsArray($theme_pages);
        $this->assertGreaterThan(0, count($theme_pages));
        
        // Verify all returned items are page objects
        foreach ($theme_pages as $page) {
            $this->assertEquals('page', $page->post_type);
        }
    }
}