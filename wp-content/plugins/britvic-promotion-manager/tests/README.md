# Time Slots & Prize Tracking Tests

## Running the Tests

### Prerequisites
1. WordPress test suite must be installed
2. PHPUnit must be available
3. Database access for testing

### Installation
```bash
# Install WordPress testing suite
cd wp-content/plugins/britvic-promotion-manager
bash bin/install-wp-tests.sh wordpress_test root password localhost latest

# Or if using different database credentials:
bash bin/install-wp-tests.sh wordpress_test db_user db_password db_host latest
```

### Running Tests

#### Run All Tests
```bash
cd wp-content/plugins/britvic-promotion-manager
phpunit
```

#### Run Specific Test File
```bash
phpunit tests/test-time-slots-and-tracking.php
```

#### Run Specific Test Method
```bash
phpunit --filter test_time_slot_validation tests/test-time-slots-and-tracking.php
```

#### Run with Coverage
```bash
phpunit --coverage-html tests/coverage/html
```

#### Run with Verbose Output
```bash
phpunit --testdox
```

## What the Tests Cover

### Time Slot Testing
- ✅ **Valid Time Slots**: 9:00-12:00 (morning), 13:00-17:00 (afternoon), 18:00-21:00 (evening)
- ✅ **Invalid Time Slots**: Outside allowed windows
- ✅ **Boundary Testing**: Exact start/end times
- ✅ **Edge Cases**: Just before/after valid slots

### Prize Tracking Testing
- ✅ **Database Storage**: WordPress posts + custom prize entries table
- ✅ **Data Integrity**: All metadata properly stored and retrieved
- ✅ **Dual Table Consistency**: Posts and entries tables synchronized
- ✅ **Claim Status**: Unclaimed → Claimed state transitions
- ✅ **Winner Information**: Email and timestamp tracking

### Full Flow Testing
- ✅ **Prize Generation**: Creating prizes with time slots
- ✅ **Code Validation**: Looking up prizes by code
- ✅ **Time Validation**: Ensuring prizes are in valid time slots
- ✅ **Result Tracking**: Complete prize state management

## Sample Test Output

```
PHPUnit 9.x.x by Sebastian Bergmann and contributors.

Time Slots And Tracking
 ✓ Prize creation with time slots
 ✓ Prize tracking database
 ✓ Time slot validation
 ✓ Prize claim tracking  
 ✓ Full prize flow

Time: 00:02.34, Memory: 45.00MB

OK (5 tests, 23 assertions)
```

## Comparison: PHPUnit vs Manual Page Testing

### ✅ **PHPUnit Tests (Recommended)**
- **Automated**: Run entire test suite in seconds
- **Comprehensive**: Tests all edge cases and scenarios
- **Reliable**: Consistent environment and data
- **Regression Safe**: Catches breaking changes
- **CI/CD Ready**: Integrates with deployment pipelines
- **Coverage Reports**: Shows exactly what's tested

### ⚠️ **Manual Page Testing** 
- **Time Consuming**: Manual clicks and validation
- **Inconsistent**: Human error and missed cases
- **Limited Scope**: Hard to test edge cases
- **No History**: No record of previous test results
- **Environment Dependent**: Different results on different setups

## Best Practice: Use Both

1. **PHPUnit for Core Logic**: Time slots, prize tracking, database operations
2. **Manual Testing for UI**: User experience, visual elements, form behavior
3. **Integration Tests**: End-to-end user flows

## Quick Test Commands

```bash
# Test time slots only
phpunit --filter time_slot tests/test-time-slots-and-tracking.php

# Test prize tracking only  
phpunit --filter tracking tests/test-time-slots-and-tracking.php

# Test with debug output
phpunit --debug tests/test-time-slots-and-tracking.php
```