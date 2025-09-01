<?php

namespace SmartCache\Tests\Unit;

use SmartCache\SmartCache;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Tests for strategy ordering and interaction behavior
 * 
 * These tests verify that the strategy ordering fix works correctly
 * and that strategies evaluate against the original value independently.
 */
class StrategyOrderingTest extends TestCase
{
    public function test_chunking_strategy_takes_priority_for_large_arrays()
    {
        // Create a large array that meets both chunking and compression thresholds
        $largeArray = $this->createLargeTestData(1200); // Large enough for both strategies
        
        // Create SmartCache instance with both strategies enabled
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new ChunkingStrategy(2048, 100),     // Chunking first (more specific)
                new CompressionStrategy(1024, 6)     // Compression second (more general)
            ]
        );
        
        $key = 'priority-test-array';
        $smartCache->put($key, $largeArray);
        
        // Verify chunking was applied, not compression
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_chunked', $rawCached);
        $this->assertTrue($rawCached['_sc_chunked']);
        
        // Verify compression was NOT applied
        $this->assertArrayNotHasKey('_sc_compressed', $rawCached);
        
        // Verify we can retrieve the original data
        $retrieved = $smartCache->get($key);
        $this->assertEquals($largeArray, $retrieved);
        
        // Clean up
        $smartCache->forget($key);
    }

    public function test_compression_strategy_applies_to_large_strings()
    {
        // Create a large string that meets compression threshold but not chunking (since it's not an array)
        $largeString = $this->createCompressibleData();
        
        // Create SmartCache instance with both strategies
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new ChunkingStrategy(2048, 100),     // Chunking first
                new CompressionStrategy(1024, 6)     // Compression second
            ]
        );
        
        $key = 'priority-test-string';
        $smartCache->put($key, $largeString);
        
        // Verify compression was applied
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_compressed', $rawCached);
        $this->assertTrue($rawCached['_sc_compressed']);
        
        // Verify chunking was NOT applied (strings can't be chunked)
        $this->assertArrayNotHasKey('_sc_chunked', $rawCached);
        
        // Verify we can retrieve the original data
        $retrieved = $smartCache->get($key);
        $this->assertEquals($largeString, $retrieved);
        
        // Clean up
        $smartCache->forget($key);
    }

    public function test_compression_strategy_applies_when_chunking_disabled()
    {
        // Create large array that would normally trigger chunking
        $largeArray = $this->createLargeTestData(1200);
        
        // Create SmartCache instance with only compression (chunking disabled)
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new CompressionStrategy(1024, 6)     // Only compression
            ]
        );
        
        $key = 'compression-only-test';
        $smartCache->put($key, $largeArray);
        
        // Verify compression was applied
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_compressed', $rawCached);
        $this->assertTrue($rawCached['_sc_compressed']);
        
        // Verify we can retrieve the original data
        $retrieved = $smartCache->get($key);
        $this->assertEquals($largeArray, $retrieved);
        
        // Clean up
        $smartCache->forget($key);
    }

    public function test_strategy_ordering_with_different_data_sizes()
    {
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new ChunkingStrategy(5000, 50),      // 5KB threshold, 50 items
                new CompressionStrategy(2000, 6)     // 2KB threshold
            ]
        );
        
        $testCases = [
            // Small array - no optimization
            'small_array' => [
                'data' => array_fill(0, 10, 'small item'),
                'expected_strategy' => null
            ],
            
            // Medium array - compression only (meets compression threshold but not chunking item count)
            'medium_array' => [
                'data' => array_fill(0, 30, str_repeat('medium item content ', 20)), // ~30 items with large content
                'expected_strategy' => 'compression'
            ],
            
            // Large array - chunking (meets both thresholds, chunking should win)
            'large_array' => [
                'data' => $this->createLargeTestData(100), // 100 items, definitely large
                'expected_strategy' => 'chunking'
            ],
            
            // Large string - compression only
            'large_string' => [
                'data' => str_repeat('Large string content ', 500),
                'expected_strategy' => 'compression'
            ]
        ];
        
        foreach ($testCases as $caseName => $testCase) {
            $key = "ordering-test-{$caseName}";
            $data = $testCase['data'];
            $expectedStrategy = $testCase['expected_strategy'];
            
            $smartCache->put($key, $data);
            
            $rawCached = Cache::get($key);
            
            if ($expectedStrategy === 'chunking') {
                $this->assertIsArray($rawCached);
                $this->assertArrayHasKey('_sc_chunked', $rawCached);
                $this->assertTrue($rawCached['_sc_chunked'], "Chunking should be applied for {$caseName}");
                $this->assertArrayNotHasKey('_sc_compressed', $rawCached, "Compression should NOT be applied for {$caseName}");
                
            } elseif ($expectedStrategy === 'compression') {
                $this->assertIsArray($rawCached);
                $this->assertArrayHasKey('_sc_compressed', $rawCached);
                $this->assertTrue($rawCached['_sc_compressed'], "Compression should be applied for {$caseName}");
                $this->assertArrayNotHasKey('_sc_chunked', $rawCached, "Chunking should NOT be applied for {$caseName}");
                
            } else {
                // No optimization expected - raw cached should equal original
                $this->assertEquals($data, $rawCached, "No optimization should be applied for {$caseName}");
            }
            
            // Verify we can always retrieve the original data
            $retrieved = $smartCache->get($key);
            $this->assertEquals($data, $retrieved, "Data integrity should be preserved for {$caseName}");
            
            // Clean up
            $smartCache->forget($key);
        }
    }

    public function test_strategy_evaluation_against_original_value_not_chained()
    {
        // This test verifies the fix: strategies should pick the best optimization for the original value
        // rather than chaining transformations
        
        // Test case 1: Array that meets both thresholds - chunking should apply first and win
        $largeArray = $this->createLargeTestData(200);
        
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new ChunkingStrategy(2048, 50),      // Should apply to large arrays
                new CompressionStrategy(1024, 6)     // Should apply to large data
            ]
        );
        
        $key1 = 'evaluation-test-array';
        $smartCache->put($key1, $largeArray);
        
        // Chunking should be applied (first strategy that matches)
        $rawCached1 = Cache::get($key1);
        $this->assertArrayHasKey('_sc_chunked', $rawCached1);
        $this->assertArrayNotHasKey('_sc_compressed', $rawCached1);
        
        // Test case 2: String that only compression can handle
        $largeString = $this->createCompressibleData();
        
        $key2 = 'evaluation-test-string';
        $smartCache->put($key2, $largeString);
        
        // Compression should be applied (chunking doesn't apply to strings)
        $rawCached2 = Cache::get($key2);
        $this->assertArrayHasKey('_sc_compressed', $rawCached2);
        $this->assertArrayNotHasKey('_sc_chunked', $rawCached2);
        
        // Test case 3: Medium array that only meets compression threshold
        $mediumArray = array_fill(0, 30, str_repeat('content ', 50)); // Large content but few items
        
        $key3 = 'evaluation-test-medium';
        $smartCache->put($key3, $mediumArray);
        
        // Should apply compression (doesn't meet chunking item count threshold)
        $rawCached3 = Cache::get($key3);
        $this->assertArrayHasKey('_sc_compressed', $rawCached3);
        $this->assertArrayNotHasKey('_sc_chunked', $rawCached3);
        
        // Verify all can be retrieved correctly
        $this->assertEquals($largeArray, $smartCache->get($key1));
        $this->assertEquals($largeString, $smartCache->get($key2));
        $this->assertEquals($mediumArray, $smartCache->get($key3));
        
        // Clean up
        $smartCache->forget($key1);
        $smartCache->forget($key2);
        $smartCache->forget($key3);
    }

    public function test_fallback_behavior_with_strategy_ordering()
    {
        // Create a strategy that always fails
        $alwaysFailStrategy = new class extends ChunkingStrategy {
            public function shouldApply(mixed $value, array $context = []): bool {
                return parent::shouldApply($value, $context);
            }
            
            public function optimize(mixed $value, array $context = []): mixed {
                throw new \Exception('Strategy intentionally failed');
            }
        };
        
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                $alwaysFailStrategy,                    // This will fail
                new CompressionStrategy(1024, 6)       // This should be tried as fallback
            ]
        );
        
        $largeArray = $this->createLargeTestData(200);
        $key = 'fallback-test';
        
        // Should succeed with compression as fallback
        $this->assertTrue($smartCache->put($key, $largeArray));
        
        // Verify compression was applied as fallback
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_compressed', $rawCached);
        $this->assertTrue($rawCached['_sc_compressed']);
        
        // Verify data integrity
        $retrieved = $smartCache->get($key);
        $this->assertEquals($largeArray, $retrieved);
        
        // Clean up
        $smartCache->forget($key);
    }

    public function test_strategy_priority_with_threshold_differences()
    {
        // Test edge cases where thresholds create interesting scenarios
        
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [
                new ChunkingStrategy(10000, 100),    // High threshold - 10KB
                new CompressionStrategy(2000, 6)     // Low threshold - 2KB
            ]
        );
        
        // Medium-sized array: meets compression threshold but not chunking threshold
        $mediumArray = $this->createLargeTestData(50); // ~50 items, likely 3-8KB
        
        $key = 'threshold-test';
        $smartCache->put($key, $mediumArray);
        
        // Should apply compression since chunking threshold is higher
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_compressed', $rawCached);
        $this->assertTrue($rawCached['_sc_compressed']);
        $this->assertArrayNotHasKey('_sc_chunked', $rawCached);
        
        // Verify data integrity
        $retrieved = $smartCache->get($key);
        $this->assertEquals($mediumArray, $retrieved);
        
        // Clean up
        $smartCache->forget($key);
    }
}
