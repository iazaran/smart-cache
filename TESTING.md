# SmartCache Testing Guide

This guide explains how to run tests for the SmartCache Laravel package without needing a full Laravel installation.

## Overview

The test suite uses:
- **PHPUnit 10** - Testing framework
- **Orchestra Testbench** - Minimal Laravel environment for package testing
- **Mockery** - Mocking framework for isolated unit tests

## Installation

First, install the development dependencies:

```bash
composer install
```

## Running Tests

### Run All Tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Run Specific Test Suites
```bash
# Unit tests only
vendor/bin/phpunit --testsuite=Unit

# Feature tests only
vendor/bin/phpunit --testsuite=Feature

# Specific test file
vendor/bin/phpunit tests/Unit/SmartCacheTest.php

# Specific test method
vendor/bin/phpunit --filter test_can_store_and_retrieve_simple_value
```

### Generate Coverage Report
```bash
composer test-coverage
# Generates HTML coverage report in ./coverage/ directory
```

### Test New Features
```bash
# Test Laravel 12 SWR methods
vendor/bin/phpunit tests/Unit/Laravel12/Laravel12SWRTest.php

# Test HTTP command execution
vendor/bin/phpunit tests/Unit/Http/HttpCommandExecutionTest.php

# Test performance monitoring
vendor/bin/phpunit tests/Unit/Monitoring/PerformanceMonitoringTest.php

# Test complete feature integration
vendor/bin/phpunit tests/Feature/EnhancedSmartCacheControllerTest.php
```

## New Enhanced Features Testing

SmartCache v1.5+ includes comprehensive testing for new enterprise features:

### Laravel 12 SWR Methods Testing
- **SWR (Stale-While-Revalidate)** pattern validation
- **Stale serving** with extended TTL support
- **Refresh-ahead** proactive caching
- **Flexible caching** with custom duration arrays
- **Cross-compatibility** between facade and helper methods
- **Optimization integration** with SWR patterns

### HTTP Command Execution Testing
- **Command discovery** and metadata retrieval
- **Programmatic execution** of cache commands
- **Parameter handling** and validation
- **Error handling** and graceful failures
- **Security validation** for non-managed keys
- **Facade integration** for HTTP commands

### Performance Monitoring Testing
- **Metrics collection** for hits, misses, and writes
- **Cache efficiency** calculation and reporting
- **Optimization impact** tracking and analysis
- **Performance analysis** with automated recommendations
- **Metrics persistence** across application instances
- **Dashboard integration** capabilities

### Integration Testing
- **Real-world scenarios** like e-commerce platforms
- **Multi-pattern usage** combining SWR methods
- **Performance monitoring integration** with caching operations
- **HTTP command execution** in application context
- **Complete feature workflows** from cache to monitoring

## Testing Best Practices

When contributing to SmartCache, follow these testing guidelines:

### Unit Tests
- **Isolated functionality** - Test individual components
- **Mock external dependencies** - Use Laravel's testing mocks
- **Edge case coverage** - Test boundary conditions
- **Error scenarios** - Ensure graceful failure handling

### Feature Tests
- **Real Laravel environment** - Use Orchestra Testbench
- **Integration scenarios** - Test component interactions
- **End-to-end workflows** - Validate complete user journeys
- **Performance validation** - Ensure optimization benefits

### Test Data Organization
```bash
# Generate test data of different sizes
$smallData = range(1, 10);           # No optimization
$mediumData = range(1, 1000);        # May trigger compression
$largeData = range(1, 5000);         # Likely chunking + compression
```

### Test with Different PHP Versions
If you have multiple PHP versions installed:
```bash
php8.1 vendor/bin/phpunit
php8.2 vendor/bin/phpunit
php8.3 vendor/bin/phpunit
```

## Test Structure

SmartCache maintains **comprehensive test coverage** with **252 tests** organized for maintainability:

```
tests/
├── bootstrap.php                    # Test bootstrap (sets up environment)
├── TestCase.php                    # Base test class with common utilities
├── Unit/                           # Unit tests (isolated component testing)
│   ├── SmartCacheTest.php          # Core SmartCache functionality
│   ├── Console/                    # Console command tests
│   │   ├── ClearCommandTest.php
│   │   └── StatusCommandTest.php
│   ├── Http/                       # HTTP-related tests
│   │   └── HttpCommandExecutionTest.php  # HTTP command execution
│   ├── Laravel12/                  # Laravel 12+ features
│   │   └── Laravel12SWRTest.php    # SWR methods (swr, stale, refreshAhead)
│   ├── Monitoring/                 # Performance monitoring
│   │   └── PerformanceMonitoringTest.php
│   ├── Providers/                  # Service provider tests
│   │   └── SmartCacheServiceProviderTest.php
│   ├── Services/                   # Service tests
│   │   └── CacheInvalidationServiceTest.php
│   ├── Strategies/                 # Strategy tests
│   │   ├── CompressionStrategyTest.php
│   │   └── ChunkingStrategyTest.php
│   ├── Traits/                     # Trait tests
│   │   └── ModelIntegrationTest.php
│   ├── DependencyTrackingTest.php
│   ├── PatternBasedInvalidationTest.php
│   ├── StrategyOrderingTest.php
│   └── TagBasedInvalidationTest.php
├── Feature/                        # Integration tests
│   ├── SmartCacheIntegrationTest.php
│   ├── AdvancedInvalidationIntegrationTest.php
│   └── EnhancedSmartCacheControllerTest.php  # Full feature integration
└── Fixtures/                       # Test fixtures and data
```

## Test Categories

### Unit Tests
- Test individual components in isolation
- Use mocks to avoid external dependencies
- Fast execution
- Located in `tests/Unit/`

### Feature Tests  
- Test complete workflows and integration
- Use real Laravel components
- Test the package as end users would use it
- Located in `tests/Feature/`

## Key Testing Features

### 1. Orchestra Testbench Integration
The `TestCase` class extends Orchestra Testbench, providing:
- Minimal Laravel application environment
- Service container with Laravel services
- Configuration management
- Cache system simulation

### 2. Smart Test Data Helpers
Built-in helper methods for creating test data:
```php
$this->createCompressibleData();      // Large repetitive string
$this->createChunkableData();         // Large array for chunking
$this->createLargeTestData(100);      // Array with 100 complex items
```

### 3. Custom Assertions
Specialized assertions for testing optimizations:
```php
$this->assertValueIsOptimized($original, $cached);
$this->assertValueIsCompressed($value);
$this->assertValueIsChunked($value);
```

### 4. Configuration Testing
Tests cover various configuration scenarios:
- Strategies enabled/disabled
- Custom thresholds
- Different compression levels
- Custom chunk sizes

### 5. Console Command Testing
Command tests verify:
- Correct output formatting
- Proper exit codes
- Integration with SmartCache service
- Error handling

## Testing Without Laravel Installation

The package tests run completely independently using Orchestra Testbench:

1. **No Laravel Project Required** - Tests create a minimal Laravel environment
2. **Isolated Dependencies** - Only requires packages specified in `composer.json`
3. **Cross-Version Compatible** - Works with Laravel 8.0+ and PHP 8.1+
4. **Fast Execution** - No database migrations or complex setup

## Environment Configuration

Test environment is configured in `TestCase::defineEnvironment()`:
- Cache driver: `array` (in-memory)
- Debug mode: enabled
- Optimized thresholds for testing (lower than production)

## Continuous Integration

The test suite is designed to work well in CI environments:

```yaml
# Example GitHub Actions configuration
- name: Run Tests
  run: |
    composer install
    vendor/bin/phpunit --coverage-clover=coverage.xml
```

## Debugging Tests

### Enable Debug Output
```bash
vendor/bin/phpunit --debug
```

### Stop on First Failure
```bash
vendor/bin/phpunit --stop-on-failure
```

### Verbose Output
```bash
vendor/bin/phpunit --verbose
```

### Test Specific Cache Driver
The package can be tested against different cache drivers by modifying the test configuration.

## Writing New Tests

### Unit Test Example
```php
<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;

class MyNewTest extends TestCase
{
    public function test_my_functionality()
    {
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
        
        // Test your functionality
        $smartCache->put('test-key', 'test-value');
        $this->assertEquals('test-value', $smartCache->get('test-key'));
    }
}
```

### Feature Test Example
```php
<?php

namespace SmartCache\Tests\Feature;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;

class MyIntegrationTest extends TestCase
{
    public function test_complete_workflow()
    {
        $smartCache = $this->app->make(SmartCache::class);
        
        // Test complete user workflow
        $largeData = $this->createCompressibleData();
        $smartCache->put('integration-test', $largeData, 3600);
        
        $retrieved = $smartCache->get('integration-test');
        $this->assertEquals($largeData, $retrieved);
        
        // Verify optimization occurred
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('integration-test', $managedKeys);
    }
}
```

## Troubleshooting

### Common Issues

1. **Memory Limit Errors**
   ```bash
   php -dmemory_limit=1G vendor/bin/phpunit
   ```

2. **Missing Extensions**
   Ensure required PHP extensions are installed:
   - `ext-zlib` (for compression)
   - `ext-json`
   - `ext-mbstring`

3. **Composer Autoload Issues**
   ```bash
   composer dump-autoload
   ```

4. **Cache Permission Issues**
   The test bootstrap creates temporary cache directories automatically.

### Getting Help

- Check the test output for detailed error messages
- Use `--debug` flag for additional debugging information
- Review the test configuration in `phpunit.xml`
- Examine the base `TestCase` class for available helpers

## Performance Considerations

- Unit tests should complete in under 1 second each
- Feature tests may take longer due to data processing
- Use smaller test datasets when possible
- Clean up test data in tearDown methods

This testing setup ensures your SmartCache package works correctly across different environments without requiring a full Laravel installation.
