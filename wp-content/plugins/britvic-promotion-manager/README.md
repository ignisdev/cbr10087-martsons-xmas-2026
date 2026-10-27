# Britvic Promotion Manager

A comprehensive WordPress plugin for managing instant-win competitions, prize draws, and automated page publishing. Built with modern PHP 8.3 practices and extensive unit testing.

## Features

### 🎯 Core Functionality
- **Automated Prize Generation**: Replace manual Python scripts with WordPress-native prize date generation
- **Page Management**: Automate page publishing/unpublishing (replaces bash scripts)
- **Competition Scheduling**: Set start/end times for automatic competition state changes
- **CSV Import/Export**: Full compatibility with existing wp-all-import workflows
- **Comprehensive Audit Logging**: Track all activities for compliance and debugging

### 🔧 Technical Features
- **PHP 8.3 Compatible**: Uses modern PHP features including strict typing and match expressions
- **Type Safety**: Comprehensive interfaces and type declarations
- **Unit Testing**: 100% test coverage with PHPUnit
- **WordPress Standards**: Follows latest WordPress coding standards and best practices
- **Error Handling**: Robust exception handling and validation
- **Security**: Input sanitization, nonce verification, and permission checks

### 🎮 Competition Types
- **Instant Win**: Time-based prize distribution with configurable windows
- **Prize Draw**: Data collection with random winner selection

## Installation

1. Upload the plugin to `/wp-content/plugins/britvic-promotion-manager/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Promotions** in the WordPress admin

### Requirements
- WordPress 6.0+
- PHP 8.3+
- MySQL 5.7+ or MariaDB 10.2+

## Quick Start

### 1. Create a Competition
```php
// Via admin interface or programmatically
$competition_id = 1;
```

### 2. Generate Prize Dates
```php
$prize_generator = new BPM_Prize_Generator();
$result = $prize_generator->generate_prize_dates(
    '2025-01-01',    // Start date
    '2025-01-31',    // End date  
    '00:00',         // Start time daily
    '23:59',         // End time daily
    1000,            // Number of prizes
    [
        'competition_id' => $competition_id,
        'types' => [
            ['name' => 'drink', 'value' => '', 'code_prefix' => 'FS'],
            ['name' => 'voucher', 'value' => '10', 'code_prefix' => 'VCH']
        ]
    ]
);
```

### 3. Schedule Page Management
```php
$page_manager = new BPM_Page_Manager();

// Publish competition pages
$page_manager->toggle_competition_pages($competition_id, 'publish');

// Or schedule for later
$scheduler = new BPM_Competition_Scheduler();
$scheduler->schedule_competition(
    $competition_id,
    '2025-01-01 00:00:00',  // Start time
    '2025-01-31 23:59:59'   // End time
);
```

## Replacing Your Current Workflow

### Python Script Replacement
**Before:**
```python
# Your existing date-generator.py
def generate_dates_with_ids(start_date_str, end_date_str, start_time_str, end_time_str, num_dates):
    # ... existing code
```

**After:**
```php
$prize_generator = new BPM_Prize_Generator();
$result = $prize_generator->generate_prize_dates(
    $start_date, $end_date, $start_time, $end_time, $num_dates, $prizes_data
);
// Automatically creates WordPress posts AND generates CSV backup
```

### Bash Script Replacement
**Before:**
```bash
#!/bin/bash
wp post list --post_type=page --post_status=publish --format=ids | while read -r PAGE_ID; do
    wp post update "$PAGE_ID" --post_status=private
done
wp post update "$TARGET_PAGE_ID" --post_status=publish
```

**After:**
```php
$page_manager = new BPM_Page_Manager();
$page_manager->toggle_competition_pages($competition_id, 'publish');
// Handles all page state changes with proper error handling and logging
```

### wp-all-import Integration
The plugin maintains full compatibility with wp-all-import Pro:
- Generate CSV files in the same format
- Import existing CSV files with validation
- Export prize data for external processing

## Architecture

### Class Structure
```
BritvicPromotionManager (Main Plugin Class)
├── BPM_Prize_Generator (Prize date generation and management)
├── BPM_Page_Manager (Automated page publishing)
├── BPM_CSV_Handler (Import/export functionality)
├── BPM_Audit_Logger (Comprehensive activity logging)
└── BPM_Competition_Scheduler (Automated scheduling)
```

### Database Tables
- `wp_bpm_competitions`: Competition configuration and status
- `wp_bpm_audit_log`: Comprehensive activity and security logging

### Integration Points
- **Gravity Forms**: Winner data collection and validation
- **ACF**: Prize metadata and custom fields
- **WordPress Cron**: Automated scheduling
- **WP REST API**: Programmatic access

## Testing

### Running Unit Tests
```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run specific test class
./vendor/bin/phpunit tests/test-prize-generator.php

# Generate coverage report
./vendor/bin/phpunit --coverage-html tests/coverage/
```

### Test Coverage
- **Prize Generation**: Timestamp generation, validation, edge cases
- **Page Management**: Publishing/unpublishing, scheduling, error handling
- **CSV Handling**: Import/export, validation, large file processing
- **Security**: Input validation, permission checks, audit logging

### Example Test
```php
public function test_prize_outcome_distribution(): void {
    $result = $this->prize_generator->generate_prize_dates(
        '2025-01-01', '2025-01-01', '00:00', '00:59', 50,
        ['types' => [['name' => 'drink'], ['name' => 'voucher']]]
    );
    
    $this->assertTrue($result['success']);
    $this->assertEquals(50, $result['created_count']);
    
    // Verify prize distribution
    $prizes = $this->get_created_prizes($result['created_ids']);
    $this->assertGreaterThan(0, count(array_unique(array_column($prizes, 'prize'))));
}
```

## API Reference

### Prize Generation
```php
interface BPM_Prize_Generator_Interface {
    public function generate_prize_dates(
        string $start_date,
        string $end_date, 
        string $start_time,
        string $end_time,
        int $num_dates,
        array $prizes_data
    ): array;
    
    public function export_to_csv(int $competition_id): array;
}
```

### Page Management
```php
interface BPM_Page_Manager_Interface {
    public function toggle_competition_pages(int $competition_id, string $action): array;
    public function schedule_page_toggle(int $competition_id, string $action, int $timestamp): bool;
}
```

### CSV Handling
```php
interface BPM_CSV_Handler_Interface {
    public function export_data(int $competition_id, string $export_type): array;
    public function import_prizes(array $file_data, int $competition_id): array;
}
```

## Security Features

### Input Validation
- All parameters type-checked and sanitized
- CSV uploads validated for size, type, and content
- SQL injection prevention with prepared statements

### Permission Management
- WordPress capability checks (`manage_options`)
- Nonce verification for all AJAX requests
- User action logging with IP tracking

### Audit Trail
```php
BPM_Audit_Logger::log(
    $competition_id,
    'prize_awarded',
    'Prize awarded to user@example.com',
    'info',
    ['prize_id' => 123, 'user_data' => [...]]
);
```

## Configuration

### Competition Types
```php
// Instant Win Configuration
$instant_win_config = [
    'type' => 'instant_win',
    'prize_window_minutes' => 10,  // Time window for prize wins
    'distribution_algorithm' => 'time_based'
];

// Prize Draw Configuration  
$prize_draw_config = [
    'type' => 'prize_draw',
    'entry_limit_per_user' => 1,
    'draw_date' => '2025-02-01 12:00:00'
];
```

### Prize Types
```php
$prize_types = [
    [
        'name' => 'drink',
        'value' => '',
        'code_prefix' => 'FS',
        'weight' => 80  // 80% probability
    ],
    [
        'name' => 'voucher', 
        'value' => '10',
        'code_prefix' => 'VCH',
        'weight' => 15  // 15% probability
    ],
    [
        'name' => 'grand_prize',
        'value' => '100',
        'code_prefix' => 'GP',
        'weight' => 5   // 5% probability
    ]
];
```

## Troubleshooting

### Common Issues

**1. Prize Generation Fails**
```bash
# Check available time slots
$slots = $prize_generator->get_available_slots('2025-01-01', '2025-01-01', '10:00', '11:00');
echo "Available slots: " . $slots; // Should be 61 for 1 hour
```

**2. Page Publishing Not Working**
```php
// Check competition configuration
$pages = $page_manager->get_competition_pages($competition_id);
var_dump($pages); // Verify live_page_id and closed_page_id are set
```

**3. CSV Import Errors**
```php
// Validate CSV before import
$validation = $csv_handler->validate_csv_file($file_path, 'prizes');
if (!$validation['valid']) {
    print_r($validation['errors']);
}
```

### Debug Mode
```php
// Enable debug logging
define('BPM_DEBUG', true);

// Check audit logs
$logs = BPM_Audit_Logger::get_audit_logs(['level' => 'error'], 50);
```

## Migration Guide

### From Manual Scripts

1. **Backup existing data**: Export current prizes and competition settings
2. **Install plugin**: Follow installation instructions above
3. **Import data**: Use CSV import functionality for existing prizes
4. **Configure competitions**: Set up competitions in admin interface
5. **Test workflow**: Run through complete competition lifecycle
6. **Replace scripts**: Remove manual bash/Python scripts once verified

### Database Migration
The plugin automatically creates required tables on activation. Existing prize posts remain compatible.

## Contributing

### Development Setup
```bash
# Clone repository
git clone [repository-url]

# Install dependencies
composer install

# Set up WordPress test environment
./bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run tests
./vendor/bin/phpunit
```

### Code Standards
- PHP 8.3+ with strict typing
- WordPress Coding Standards
- PSR-12 compliance
- 100% test coverage for new features

## Support

### Documentation
- Plugin admin interface includes contextual help
- Audit logs provide detailed activity tracking
- Error messages include actionable guidance

### Monitoring
- Built-in health checks for competition setup
- Pre-launch validation warnings
- Automated cleanup of old data

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- Complete prize generation system
- Automated page management
- CSV import/export functionality
- Comprehensive audit logging
- Competition scheduling
- Full unit test coverage