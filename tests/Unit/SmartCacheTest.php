<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\SmartCache;
use SmartCache\Tests\TestCase;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\ChunkingStrategy;
use Mockery;

class SmartCacheTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
    }

    public function test_can_store_and_retrieve_simple_value()
    {
        $key = 'test-key';
        $value = 'Simple test value';

        $this->assertTrue($this->smartCache->put($key, $value));
        $this->assertEquals($value, $this->smartCache->get($key));
        $this->assertTrue($this->smartCache->has($key));
    }

    public function test_can_store_and_retrieve_array_value()
    {
        $key = 'test-array';
        $value = ['name' => 'John', 'age' => 30, 'city' => 'New York'];

        $this->assertTrue($this->smartCache->put($key, $value));
        $this->assertEquals($value, $this->smartCache->get($key));
    }

    public function test_get_returns_default_when_key_not_exists()
    {
        $default = 'default-value';
        $this->assertEquals($default, $this->smartCache->get('non-existent-key', $default));
    }

    public function test_can_use_remember_method()
    {
        $key = 'remember-key';
        $expectedValue = 'remembered-value';

        $result = $this->smartCache->remember($key, 3600, function () use ($expectedValue) {
            return $expectedValue;
        });

        $this->assertEquals($expectedValue, $result);
        $this->assertEquals($expectedValue, $this->smartCache->get($key));
    }

    public function test_remember_returns_cached_value_on_subsequent_calls()
    {
        $key = 'remember-cached-key';
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'value-' . $callCount;
        };

        // First call should execute callback
        $result1 = $this->smartCache->remember($key, 3600, $callback);
        $this->assertEquals('value-1', $result1);
        $this->assertEquals(1, $callCount);

        // Second call should return cached value without executing callback
        $result2 = $this->smartCache->remember($key, 3600, $callback);
        $this->assertEquals('value-1', $result2);
        $this->assertEquals(1, $callCount); // Count shouldn't increase
    }

    public function test_can_use_remember_forever_method()
    {
        $key = 'remember-forever-key';
        $expectedValue = 'forever-value';

        $result = $this->smartCache->rememberForever($key, function () use ($expectedValue) {
            return $expectedValue;
        });

        $this->assertEquals($expectedValue, $result);
        $this->assertEquals($expectedValue, $this->smartCache->get($key));
    }

    public function test_can_store_value_forever()
    {
        $key = 'forever-key';
        $value = 'forever-value';

        $this->assertTrue($this->smartCache->forever($key, $value));
        $this->assertEquals($value, $this->smartCache->get($key));
    }

    public function test_can_forget_value()
    {
        $key = 'forget-key';
        $value = 'forget-value';

        $this->smartCache->put($key, $value);
        $this->assertTrue($this->smartCache->has($key));

        $this->assertTrue($this->smartCache->forget($key));
        $this->assertFalse($this->smartCache->has($key));
        $this->assertNull($this->smartCache->get($key));
    }

    public function test_compression_strategy_is_applied_to_large_string()
    {
        // Create a fresh SmartCache instance with only compression strategy
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new CompressionStrategy(1024, 6)] // 1KB threshold, level 6
        );
        
        $key = 'large-string-key';
        $value = $this->createCompressibleData(); // Creates string > 1KB

        $smartCache->put($key, $value);

        // Get the raw cached value to check if it's compressed
        $cached = $this->getCacheStore()->get($key);
        $this->assertValueIsCompressed($cached);

        // Verify we can still retrieve the original value
        $retrieved = $smartCache->get($key);
        $this->assertEquals($value, $retrieved);
    }

    public function test_compression_strategy_is_applied_to_large_array()
    {
        // Create a fresh SmartCache instance with only compression strategy
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new CompressionStrategy(1024, 6)] // 1KB threshold, level 6
        );
        
        $key = 'large-array-key';
        $value = $this->createLargeTestData(50); // Creates array that should exceed threshold

        $smartCache->put($key, $value);

        // Get the raw cached value to check if it's compressed
        $cached = $this->getCacheStore()->get($key);
        $this->assertValueIsCompressed($cached);

        // Verify we can still retrieve the original value
        $retrieved = $smartCache->get($key);
        $this->assertEquals($value, $retrieved);
    }

    public function test_chunking_strategy_is_applied_to_very_large_array()
    {
        // Disable compression for this test to ensure chunking is applied
        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        
        // Create a fresh SmartCache instance with the new configuration
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new ChunkingStrategy(2048, 100)]
        );
        
        $key = 'chunked-array-key';
        $value = $this->createChunkableData(); // Creates array that should trigger chunking

        $smartCache->put($key, $value);

        // Get the raw cached value to check if it's chunked
        $cached = $this->getCacheStore()->get($key);
        $this->assertValueIsChunked($cached);

        // Verify we can still retrieve the original value
        $retrieved = $smartCache->get($key);
        $this->assertEquals($value, $retrieved);
    }

    public function test_small_values_are_not_optimized()
    {
        $key = 'small-value-key';
        $value = 'small value';

        $this->smartCache->put($key, $value);

        // Get the raw cached value - should not be optimized
        $cached = $this->getCacheStore()->get($key);
        $this->assertEquals($value, $cached);
    }

    public function test_can_clear_all_managed_keys()
    {
        // Store multiple values that will be optimized (use large data)
        $keys = ['key1', 'key2', 'key3'];
        $largeValue = $this->createCompressibleData(); // Use compressible data to ensure optimization

        foreach ($keys as $key) {
            $this->smartCache->put($key, $largeValue);
        }

        // Verify all are stored
        foreach ($keys as $key) {
            $this->assertTrue($this->smartCache->has($key));
        }

        // Clear all
        $this->assertTrue($this->smartCache->clear());

        // Verify all are removed
        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_managed_keys_tracking()
    {
        $key1 = 'tracked-key-1';
        $key2 = 'tracked-key-2';
        
        // Store a large value that triggers optimization
        $largeValue = $this->createCompressibleData();
        $this->smartCache->put($key1, $largeValue);
        
        // Store a small value (still tracked for pattern matching and invalidation)
        $this->smartCache->put($key2, 'small value');

        $managedKeys = $this->smartCache->getManagedKeys();
        
        // Both keys should now be tracked for advanced invalidation features
        $this->assertContains($key1, $managedKeys);
        $this->assertContains($key2, $managedKeys);
    }

    public function test_can_get_different_cache_stores()
    {
        // Test getting default store
        $defaultStore = $this->smartCache->store();
        $this->assertEquals($this->getCacheStore(), $defaultStore);

        // Test getting named store
        $arrayStore = $this->smartCache->store('array');
        $this->assertNotNull($arrayStore);
    }

    public function test_chunked_data_cleanup_on_forget()
    {
        // Disable compression for this test to ensure chunking is applied
        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        
        // Create a fresh SmartCache instance with the new configuration
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new ChunkingStrategy(2048, 100)]
        );
        
        $key = 'chunked-cleanup-key';
        $value = $this->createChunkableData();

        $smartCache->put($key, $value);
        
        // Get the chunked metadata
        $cached = $this->getCacheStore()->get($key);
        $this->assertValueIsChunked($cached);
        
        // Verify chunk keys exist
        foreach ($cached['chunk_keys'] as $chunkKey) {
            $this->assertTrue($this->getCacheStore()->has($chunkKey));
        }

        // Forget the main key
        $smartCache->forget($key);

        // Verify main key and all chunk keys are removed
        $this->assertFalse($smartCache->has($key));
        foreach ($cached['chunk_keys'] as $chunkKey) {
            $this->assertFalse($this->getCacheStore()->has($chunkKey));
        }
    }

    public function test_optimization_fallback_on_strategy_failure()
    {
        // Create a SmartCache instance with a failing strategy
        $failingStrategy = Mockery::mock(OptimizationStrategy::class);
        $failingStrategy->shouldReceive('getIdentifier')->andReturn('failing');
        $failingStrategy->shouldReceive('shouldApply')->andReturn(true);
        $failingStrategy->shouldReceive('optimize')->andThrow(new \Exception('Strategy failed'));

        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [$failingStrategy]
        );

        $key = 'fallback-key';
        $value = 'test value';

        // Should not throw exception due to fallback
        $this->assertTrue($smartCache->put($key, $value));
        $this->assertEquals($value, $smartCache->get($key));
    }

    public function test_restoration_fallback_on_strategy_failure()
    {
        // First store a value normally
        $key = 'restoration-fallback-key';
        $value = 'test value';
        $this->smartCache->put($key, $value);

        // Create a SmartCache instance with a failing restoration strategy
        $failingStrategy = Mockery::mock(OptimizationStrategy::class);
        $failingStrategy->shouldReceive('getIdentifier')->andReturn('failing');
        $failingStrategy->shouldReceive('restore')->andThrow(new \Exception('Restoration failed'));

        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [$failingStrategy]
        );

        // Should return the original cached value as fallback
        $retrieved = $smartCache->get($key);
        $this->assertEquals($value, $retrieved);
    }

    public function test_flexible_method_works_with_optimization()
    {
        $key = 'flexible-test-key';
        $largeValue = $this->createCompressibleData();
        $callCount = 0;

        $callback = function () use ($largeValue, &$callCount) {
            $callCount++;
            return $largeValue;
        };

        // Laravel format: [freshTtl, staleTtl] 
        $durations = [3600, 7200]; // Fresh for 1 hour, stale until 2 hours

        // Test the flexible method with real SmartCache instance
        $result = $this->smartCache->flexible($key, $durations, $callback);
        
        $this->assertEquals($largeValue, $result);
        $this->assertEquals(1, $callCount);

        // Verify the key was tracked (optimization applied)
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);

        // Verify we can retrieve the cached value
        $cachedResult = $this->smartCache->get($key);
        $this->assertEquals($largeValue, $cachedResult);
    }

    public function test_flexible_method_fresh_data_retrieval()
    {
        $key = 'flexible-fresh-key';
        $expectedValue = 'fresh-value';
        $callCount = 0;

        $callback = function () use ($expectedValue, &$callCount) {
            $callCount++;
            return $expectedValue . '-' . $callCount;
        };

        // Fresh for 10 seconds, stale until 20 seconds
        $durations = [10, 20];

        // First call should execute callback and cache result
        $result1 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('fresh-value-1', $result1);
        $this->assertEquals(1, $callCount);

        // Immediate second call should return fresh cached value without executing callback
        $result2 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('fresh-value-1', $result2);
        $this->assertEquals(1, $callCount); // Count shouldn't increase - data is fresh
    }

    public function test_flexible_method_stale_while_revalidate_behavior()
    {
        $key = 'flexible-stale-key';
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'value-' . $callCount;
        };

        // Fresh for 1 second, stale until 5 seconds
        $durations = [1, 5];

        // Initial call
        $result1 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('value-1', $result1);
        $this->assertEquals(1, $callCount);

        // Wait for data to become stale (but not expired)
        sleep(2); // Data is now stale but within stale period

        // This call should return stale data and trigger background refresh
        $result2 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('value-1', $result2); // Should still get original value (stale but served)
        $this->assertEquals(2, $callCount); // Callback should have been called for background refresh

        // Immediate next call should get the refreshed data
        $result3 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('value-2', $result3); // Should get the refreshed value
        $this->assertEquals(2, $callCount); // No additional callback execution needed
    }

    public function test_flexible_method_expired_data_regeneration()
    {
        $key = 'flexible-expired-key';
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'value-' . $callCount;
        };

        // Very short durations for testing: fresh for 1 second, stale until 1 second (no stale period)
        $durations = [1, 1];

        // Initial call
        $result1 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('value-1', $result1);
        $this->assertEquals(1, $callCount);

        // Wait for data to expire completely (beyond stale period)
        sleep(3); // Data is now completely expired

        // This call should regenerate fresh data (blocking)
        $result2 = $this->smartCache->flexible($key, $durations, $callback);
        $this->assertEquals('value-2', $result2); // Should get completely new value
        $this->assertEquals(2, $callCount); // Callback should have been called for fresh generation
    }

    public function test_magic_method_delegates_to_underlying_cache()
    {
        // Test that an unknown method gets delegated to the underlying cache
        $key = 'magic-method-key';
        $value = 'magic value';
        
        // Use a method that exists on Laravel's cache but not explicitly on SmartCache
        // We'll use the many() method as an example
        $result = $this->smartCache->many([$key]);
        
        // Should return array with null values since key doesn't exist
        $this->assertIsArray($result);
        $this->assertArrayHasKey($key, $result);
        $this->assertNull($result[$key]);

        // Store a value first
        $this->smartCache->put($key, $value);
        
        // Now many() should return the value
        $result = $this->smartCache->many([$key]);
        $this->assertArrayHasKey($key, $result);
        $this->assertEquals($value, $result[$key]);
    }

    public function test_magic_method_with_new_hypothetical_laravel_method()
    {
        // Test that a completely unknown method gets delegated
        // This simulates what would happen with new Laravel 12+ methods
        
        // We'll mock the cache store to have a new method
        $mockCache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $mockCache->shouldReceive('hypotheticalNewMethod')
                  ->with('param1', 'param2')
                  ->once()
                  ->andReturn('new method result');
        
        // Also mock other necessary methods that SmartCache constructor might call
        $mockCache->shouldReceive('getStore')->andReturn($mockCache);
        $mockCache->shouldReceive('get')->with('_sc_managed_keys', [])->andReturn([]);
        $mockCache->shouldReceive('get')->with('_sc_dependencies', [])->andReturn([]);
        $mockCache->shouldReceive('get')->with('_sc_performance_metrics', [])->andReturn([]);
        
        $smartCache = new SmartCache(
            $mockCache,
            $this->getCacheManager(),
            $this->app['config'],
            []
        );

        $result = $smartCache->hypotheticalNewMethod('param1', 'param2');
        $this->assertEquals('new method result', $result);
    }

    public function test_flexible_method_with_optimization_integration()
    {
        // Test that flexible method properly applies SmartCache optimizations
        $key = 'flexible-optimization-key';
        $largeValue = $this->createCompressibleData();
        
        $callback = function () use ($largeValue) {
            return $largeValue;
        };

        $durations = [3600, 7200]; // Fresh 1h, stale until 2h

        // Test with real SmartCache instance
        $result = $this->smartCache->flexible($key, $durations, $callback);
        
        // Verify the method works correctly
        $this->assertEquals($largeValue, $result);
        
        // Verify key tracking works (optimization was applied)
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);

        // Test the flexible method interacts properly with other SmartCache methods
        $this->assertTrue($this->smartCache->has($key));
        $this->assertEquals($largeValue, $this->smartCache->get($key));
        
        // Test cleanup works (should also clean up metadata)
        $this->assertTrue($this->smartCache->forget($key));
        $this->assertFalse($this->smartCache->has($key));
        
        // Verify metadata was also cleaned up
        $metaKey = $key . '_sc_meta';
        $this->assertNull($this->smartCache->get($metaKey));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
