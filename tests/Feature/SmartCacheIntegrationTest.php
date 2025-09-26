<?php

namespace SmartCache\Tests\Feature;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;
use Illuminate\Support\Facades\Cache;

/**
 * Integration tests for SmartCache functionality
 * 
 * These tests verify that SmartCache works correctly in a complete Laravel environment
 */
class SmartCacheIntegrationTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCache::class);
    }

    public function test_full_workflow_with_compression_and_retrieval()
    {
        // Test the complete workflow: store large data, ensure it's compressed, retrieve it
        $key = 'integration-compression-test';
        $largeData = $this->createCompressibleData();
        
        // Store the data
        $this->assertTrue($this->smartCache->put($key, $largeData, 3600));
        
        // Verify it's tracked as optimized
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);
        
        // Verify we can retrieve the original data
        $retrieved = $this->smartCache->get($key);
        $this->assertEquals($largeData, $retrieved);
        
        // Verify the raw cache entry is compressed
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_compressed', $rawCached);
        $this->assertTrue($rawCached['_sc_compressed']);
        
        // Clean up
        $this->smartCache->forget($key);
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_full_workflow_with_chunking_and_retrieval()
    {
        // Disable compression for this test to ensure chunking is applied
        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        
        // Create a fresh SmartCache instance with chunking only
        $chunkingSmartCache = new \SmartCache\SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new \SmartCache\Strategies\ChunkingStrategy(2048, 100)]
        );
        
        // Test chunking workflow
        $key = 'integration-chunking-test';
        $largeArray = $this->createChunkableData();
        
        // Store the data
        $this->assertTrue($chunkingSmartCache->put($key, $largeArray, 3600));
        
        // Verify it's tracked as optimized
        $managedKeys = $chunkingSmartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);
        
        // Verify we can retrieve the original data
        $retrieved = $chunkingSmartCache->get($key);
        $this->assertEquals($largeArray, $retrieved);
        
        // Verify the raw cache entry is chunked
        $rawCached = Cache::get($key);
        $this->assertIsArray($rawCached);
        $this->assertArrayHasKey('_sc_chunked', $rawCached);
        $this->assertTrue($rawCached['_sc_chunked']);
        
        // Verify chunk keys exist in cache
        foreach ($rawCached['chunk_keys'] as $chunkKey) {
            $this->assertTrue(Cache::has($chunkKey));
        }
        
        // Clean up
        $chunkingSmartCache->forget($key);
        $this->assertFalse($chunkingSmartCache->has($key));
        
        // Verify chunk keys are also cleaned up
        foreach ($rawCached['chunk_keys'] as $chunkKey) {
            $this->assertFalse(Cache::has($chunkKey));
        }
    }

    public function test_remember_method_integration()
    {
        $key = 'integration-remember-test';
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return $this->createCompressibleData(); // Large data that should be compressed
        };
        
        // First call should execute callback and store compressed data
        $result1 = $this->smartCache->remember($key, 3600, $callback);
        $this->assertEquals(1, $callCount);
        
        // Verify it's optimized
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);
        
        // Second call should retrieve from cache without executing callback
        $result2 = $this->smartCache->remember($key, 3600, $callback);
        $this->assertEquals(1, $callCount); // Should not increment
        $this->assertEquals($result1, $result2);
        
        // Clean up
        $this->smartCache->forget($key);
    }

    public function test_remember_forever_integration()
    {
        $key = 'integration-remember-forever-test';
        
        $result = $this->smartCache->rememberForever($key, function () {
            return $this->createLargeTestData(100);
        });
        
        // Should be retrievable
        $retrieved = $this->smartCache->get($key);
        $this->assertEquals($result, $retrieved);
        
        // Should be optimized
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);
        
        // Clean up
        $this->smartCache->forget($key);
    }

    public function test_mixed_data_types_integration()
    {
        // Test storing different types of data
        $testCases = [
            'string-small' => 'small string',
            'string-large' => $this->createCompressibleData(),
            'array-small' => ['key' => 'value'],
            'array-large' => $this->createLargeTestData(50),
            'array-chunked' => $this->createChunkableData(),
            'integer' => 42,
            'float' => 3.14159,
            'boolean' => true,
            'null' => null,
        ];
        
        // Store all test cases
        foreach ($testCases as $key => $value) {
            $this->assertTrue($this->smartCache->put($key, $value, 3600));
        }
        
        // Verify all can be retrieved correctly
        foreach ($testCases as $key => $originalValue) {
            $retrievedValue = $this->smartCache->get($key);
            $this->assertEquals($originalValue, $retrievedValue, "Failed for key: $key");
        }
        
        // Check which ones are managed (optimized)
        $managedKeys = $this->smartCache->getManagedKeys();
        
        // Large string and arrays should be managed
        $this->assertContains('string-large', $managedKeys);
        $this->assertContains('array-large', $managedKeys);
        $this->assertContains('array-chunked', $managedKeys);
        
        // All values are now managed for advanced invalidation features
        $this->assertContains('string-small', $managedKeys);
        $this->assertContains('array-small', $managedKeys);
        $this->assertContains('integer', $managedKeys);
        
        // Clean up
        foreach (array_keys($testCases) as $key) {
            $this->smartCache->forget($key);
        }
    }

    public function test_cache_clear_integration()
    {
        // Store multiple optimized items
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $key = "clear-test-$i";
            $keys[] = $key;
            $this->smartCache->put($key, $this->createCompressibleData());
        }
        
        // Verify all are managed
        $managedKeys = $this->smartCache->getManagedKeys();
        foreach ($keys as $key) {
            $this->assertContains($key, $managedKeys);
        }
        
        // Clear all
        $this->assertTrue($this->smartCache->clear());
        
        // Verify all are gone
        $this->assertEmpty($this->smartCache->getManagedKeys());
        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_concurrent_access_simulation()
    {
        // Simulate concurrent access patterns
        $baseKey = 'concurrent-test';
        
        // Simulate multiple "processes" storing and retrieving data
        for ($process = 0; $process < 3; $process++) {
            $key = "{$baseKey}-{$process}";
            $data = $this->createLargeTestData(100 + $process * 10);
            
            // Store
            $this->assertTrue($this->smartCache->put($key, $data));
            
            // Immediate retrieval
            $retrieved = $this->smartCache->get($key);
            $this->assertEquals($data, $retrieved);
            
            // Verify optimization
            $this->assertTrue($this->smartCache->has($key));
        }
        
        // Clean up
        for ($process = 0; $process < 3; $process++) {
            $key = "{$baseKey}-{$process}";
            $this->smartCache->forget($key);
        }
    }

    public function test_fallback_behavior_integration()
    {
        // This tests the fallback behavior when strategies fail
        // Since we can't easily make strategies fail in integration tests,
        // we'll test that the system gracefully handles edge cases
        
        // Test with empty data
        $this->assertTrue($this->smartCache->put('empty-string', ''));
        $this->assertEquals('', $this->smartCache->get('empty-string'));
        
        // Test with empty array
        $this->assertTrue($this->smartCache->put('empty-array', []));
        $this->assertEquals([], $this->smartCache->get('empty-array'));
        
        // Test with very large single string (might challenge compression)
        $veryLargeString = str_repeat('x', 1000000); // 1MB of 'x'
        $this->assertTrue($this->smartCache->put('very-large', $veryLargeString));
        $this->assertEquals($veryLargeString, $this->smartCache->get('very-large'));
        
        // Clean up
        $this->smartCache->forget('empty-string');
        $this->smartCache->forget('empty-array');
        $this->smartCache->forget('very-large');
    }

    public function test_cache_store_switching()
    {
        // Test using different cache stores
        $defaultStore = $this->smartCache->store();
        $arrayStore = $this->smartCache->store('array');
        
        // Store in default store
        $defaultStore->put('default-key', 'default-value');
        
        // Store in array store
        $arrayStore->put('array-key', 'array-value');
        
        // Values should be accessible from their respective stores
        $this->assertTrue($defaultStore->has('default-key'));
        $this->assertTrue($arrayStore->has('array-key'));
        
        // Verify values are correct
        $this->assertEquals('default-value', $defaultStore->get('default-key'));
        $this->assertEquals('array-value', $arrayStore->get('array-key'));
        
        // Clean up
        $defaultStore->forget('default-key');
        $arrayStore->forget('array-key');
    }

    public function test_performance_characteristics()
    {
        // Simple performance test to ensure operations complete reasonably quickly
        $startTime = microtime(true);
        
        // Perform a series of operations
        for ($i = 0; $i < 10; $i++) {
            $key = "perf-test-$i";
            $data = $this->createLargeTestData(50); // Moderate size data
            
            $this->smartCache->put($key, $data);
            $retrieved = $this->smartCache->get($key);
            $this->assertEquals($data, $retrieved);
            $this->smartCache->forget($key);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should complete in reasonable time (adjust threshold as needed)
        $this->assertLessThan(5.0, $duration, 'Performance test took too long: ' . $duration . ' seconds');
    }

    public function test_memory_usage_with_large_datasets()
    {
        $initialMemory = memory_get_usage();
        
        // Store and retrieve several large datasets
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $key = "memory-test-$i";
            $keys[] = $key;
            
            $largeData = $this->createLargeTestData(200);
            $this->smartCache->put($key, $largeData);
            
            // Verify retrieval
            $retrieved = $this->smartCache->get($key);
            $this->assertEquals($largeData, $retrieved);
        }
        
        $afterStoreMemory = memory_get_usage();
        
        // Clean up
        foreach ($keys as $key) {
            $this->smartCache->forget($key);
        }
        
        $finalMemory = memory_get_usage();
        
        // Memory usage should be reasonable (this is more of a smoke test)
        $memoryIncrease = $afterStoreMemory - $initialMemory;
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease, 'Memory usage increased too much: ' . ($memoryIncrease / 1024 / 1024) . ' MB');
    }
}
