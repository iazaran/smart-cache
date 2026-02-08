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

    public function test_store_returns_smart_cache_instance()
    {
        // Test getting default store returns self
        $defaultStore = $this->smartCache->store();
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $defaultStore);
        $this->assertSame($this->smartCache, $defaultStore);

        // Test getting named store returns a new SmartCache instance
        $arrayStore = $this->smartCache->store('array');
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $arrayStore);
        $this->assertNotSame($this->smartCache, $arrayStore);
    }

    public function test_store_method_preserves_optimization_strategies()
    {
        // Create a SmartCache instance with compression enabled
        $smartCache = new SmartCache(
            $this->getCacheStore(),
            $this->getCacheManager(),
            $this->app['config'],
            [new CompressionStrategy(1024, 6)]
        );

        // Get a store instance for array driver
        $arraySmartCache = $smartCache->store('array');

        // Store large data through the new store instance
        $key = 'store-optimization-test';
        $value = $this->createCompressibleData();

        $arraySmartCache->put($key, $value);

        // Verify the value is compressed in the raw cache
        $rawCached = $this->getCacheStore('array')->get($key);
        $this->assertValueIsCompressed($rawCached);

        // Verify we can retrieve the original value
        $retrieved = $arraySmartCache->get($key);
        $this->assertEquals($value, $retrieved);
    }

    public function test_store_method_allows_chaining_operations()
    {
        // Test that store() allows chaining cache operations
        $key = 'chained-store-key';
        $value = 'chained-value';

        // Put using chained store
        $this->smartCache->store('array')->put($key, $value, 3600);

        // Get using chained store
        $retrieved = $this->smartCache->store('array')->get($key);
        $this->assertEquals($value, $retrieved);

        // Remember using chained store
        $rememberValue = $this->smartCache->store('array')->remember('remember-chain-key', 3600, fn() => 'remembered');
        $this->assertEquals('remembered', $rememberValue);
    }

    public function test_store_method_uses_correct_driver()
    {
        $key = 'driver-test-key';
        $value = 'test-value';

        // Store in array driver via store() method
        $this->smartCache->store('array')->put($key, $value);

        // Value should be in array store
        $this->assertTrue($this->smartCache->store('array')->has($key));

        // Value should not be in file store (different driver)
        $this->assertFalse($this->smartCache->store('file')->has($key));
    }

    public function test_repository_method_returns_raw_cache()
    {
        // Test getting default repository
        $defaultRepo = $this->smartCache->repository();
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Repository::class, $defaultRepo);
        $this->assertEquals($this->getCacheStore(), $defaultRepo);

        // Test getting named repository
        $arrayRepo = $this->smartCache->repository('array');
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Repository::class, $arrayRepo);
    }

    public function test_repository_bypasses_optimization()
    {
        $key = 'repository-bypass-test';
        $value = $this->createCompressibleData(); // Large, compressible data

        // Store via repository (should bypass SmartCache optimization)
        $this->smartCache->repository()->put($key, $value);

        // Get raw value - should NOT be compressed since we used repository
        $rawCached = $this->getCacheStore()->get($key);
        $this->assertEquals($value, $rawCached);
        $this->assertIsString($rawCached); // Not wrapped in optimization array
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

    // ========================================
    // Repository Interface Compliance Tests
    // ========================================

    public function test_smart_cache_implements_repository_interface()
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Repository::class, $this->smartCache);
    }

    public function test_pull_retrieves_and_deletes_item()
    {
        $key = 'pull-test-key';
        $value = 'pull-test-value';

        $this->smartCache->put($key, $value);
        $this->assertTrue($this->smartCache->has($key));

        $pulled = $this->smartCache->pull($key);
        $this->assertEquals($value, $pulled);
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_pull_returns_default_when_key_missing()
    {
        $default = 'default-value';
        $pulled = $this->smartCache->pull('non-existent-key', $default);
        $this->assertEquals($default, $pulled);
    }

    public function test_add_stores_item_only_if_not_exists()
    {
        $key = 'add-test-key';

        // Add should succeed when key doesn't exist
        $result = $this->smartCache->add($key, 'first-value');
        $this->assertTrue($result);
        $this->assertEquals('first-value', $this->smartCache->get($key));

        // Add should fail when key already exists
        $result = $this->smartCache->add($key, 'second-value');
        $this->assertFalse($result);
        $this->assertEquals('first-value', $this->smartCache->get($key)); // Still first value
    }

    public function test_increment_increases_value()
    {
        $key = 'increment-test-key';
        $this->getCacheStore()->put($key, 5);

        $result = $this->smartCache->increment($key);
        $this->assertEquals(6, $result);

        $result = $this->smartCache->increment($key, 3);
        $this->assertEquals(9, $result);
    }

    public function test_decrement_decreases_value()
    {
        $key = 'decrement-test-key';
        $this->getCacheStore()->put($key, 10);

        $result = $this->smartCache->decrement($key);
        $this->assertEquals(9, $result);

        $result = $this->smartCache->decrement($key, 4);
        $this->assertEquals(5, $result);
    }

    public function test_sear_is_alias_for_remember_forever()
    {
        $key = 'sear-test-key';
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'seared-value';
        };

        // First call should execute callback
        $value = $this->smartCache->sear($key, $callback);
        $this->assertEquals('seared-value', $value);
        $this->assertEquals(1, $callCount);

        // Second call should return cached value without executing callback
        $value = $this->smartCache->sear($key, $callback);
        $this->assertEquals('seared-value', $value);
        $this->assertEquals(1, $callCount); // Callback should not be called again
    }

    public function test_set_is_alias_for_put()
    {
        $key = 'set-test-key';
        $value = 'set-test-value';

        $result = $this->smartCache->set($key, $value);
        $this->assertTrue($result);
        $this->assertEquals($value, $this->smartCache->get($key));
    }

    public function test_delete_is_alias_for_forget()
    {
        $key = 'delete-test-key';
        $value = 'delete-test-value';

        $this->smartCache->put($key, $value);
        $this->assertTrue($this->smartCache->has($key));

        $result = $this->smartCache->delete($key);
        $this->assertTrue($result);
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_get_store_returns_underlying_store()
    {
        $store = $this->smartCache->getStore();
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Store::class, $store);
    }

    public function test_get_multiple_retrieves_multiple_items()
    {
        $this->smartCache->put('multi1', 'value1');
        $this->smartCache->put('multi2', 'value2');
        $this->smartCache->put('multi3', 'value3');

        $results = $this->smartCache->getMultiple(['multi1', 'multi2', 'multi3']);

        $this->assertIsIterable($results);
        $this->assertEquals('value1', $results['multi1']);
        $this->assertEquals('value2', $results['multi2']);
        $this->assertEquals('value3', $results['multi3']);
    }

    public function test_get_multiple_returns_default_for_missing_keys()
    {
        $this->smartCache->put('existing', 'exists');

        $results = $this->smartCache->getMultiple(['existing', 'missing'], 'default');

        $this->assertEquals('exists', $results['existing']);
        $this->assertEquals('default', $results['missing']);
    }

    public function test_set_multiple_stores_multiple_items()
    {
        $values = [
            'setmulti1' => 'val1',
            'setmulti2' => 'val2',
            'setmulti3' => 'val3',
        ];

        $result = $this->smartCache->setMultiple($values);
        $this->assertTrue($result);

        $this->assertEquals('val1', $this->smartCache->get('setmulti1'));
        $this->assertEquals('val2', $this->smartCache->get('setmulti2'));
        $this->assertEquals('val3', $this->smartCache->get('setmulti3'));
    }

    public function test_delete_multiple_removes_multiple_items()
    {
        $this->smartCache->put('del1', 'value1');
        $this->smartCache->put('del2', 'value2');
        $this->smartCache->put('del3', 'value3');

        $result = $this->smartCache->deleteMultiple(['del1', 'del2']);
        $this->assertTrue($result);

        $this->assertFalse($this->smartCache->has('del1'));
        $this->assertFalse($this->smartCache->has('del2'));
        $this->assertTrue($this->smartCache->has('del3')); // This one was not deleted
    }

    public function test_store_returns_repository_compatible_instance()
    {
        $storeInstance = $this->smartCache->store('array');

        // Should be both SmartCache and Repository
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $storeInstance);
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Repository::class, $storeInstance);

        // Should have getStore() method working
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Store::class, $storeInstance->getStore());
    }

    // ====================================================================
    // Null value support (sentinel pattern)
    // ====================================================================

    public function test_get_returns_null_as_valid_cached_value()
    {
        $this->smartCache->put('null-key', null);
        $this->assertTrue($this->smartCache->has('null-key'));
        $this->assertNull($this->smartCache->get('null-key'));
    }

    public function test_get_returns_default_for_missing_key_not_for_null_value()
    {
        $this->smartCache->put('null-key', null);
        // Should return null (the cached value), not the default
        $this->assertNull($this->smartCache->get('null-key', 'default'));
    }

    public function test_get_returns_default_only_when_key_truly_missing()
    {
        $this->assertEquals('default', $this->smartCache->get('missing-key', 'default'));
    }

    public function test_remember_caches_null_value()
    {
        $callCount = 0;
        $result = $this->smartCache->remember('null-remember', 3600, function () use (&$callCount) {
            $callCount++;
            return null;
        });

        $this->assertNull($result);
        $this->assertEquals(1, $callCount);

        // Second call should return cached null without re-executing callback
        $result2 = $this->smartCache->remember('null-remember', 3600, function () use (&$callCount) {
            $callCount++;
            return 'should not be called';
        });

        $this->assertNull($result2);
        $this->assertEquals(1, $callCount);
    }

    public function test_remember_forever_caches_null_value()
    {
        $callCount = 0;
        $result = $this->smartCache->rememberForever('null-forever', function () use (&$callCount) {
            $callCount++;
            return null;
        });

        $this->assertNull($result);
        $this->assertEquals(1, $callCount);

        // Second call should return cached null
        $result2 = $this->smartCache->rememberForever('null-forever', function () use (&$callCount) {
            $callCount++;
            return 'should not be called';
        });

        $this->assertNull($result2);
        $this->assertEquals(1, $callCount);
    }

    // ====================================================================
    // flush() method
    // ====================================================================

    public function test_flush_clears_entire_store()
    {
        $this->smartCache->put('flush-key1', 'value1');
        $this->smartCache->put('flush-key2', 'value2');

        $result = $this->smartCache->flush();
        $this->assertTrue($result);

        $this->assertFalse($this->smartCache->has('flush-key1'));
        $this->assertFalse($this->smartCache->has('flush-key2'));
    }

    // ====================================================================
    // getRaw() method
    // ====================================================================

    public function test_get_raw_returns_unrestored_value()
    {
        $this->smartCache->put('raw-key', 'simple-value');
        $rawValue = $this->smartCache->getRaw('raw-key');
        $this->assertEquals('simple-value', $rawValue);
    }

    public function test_get_raw_returns_null_for_missing_key()
    {
        $this->assertNull($this->smartCache->getRaw('non-existent-raw'));
    }

    // ====================================================================
    // Cost-Aware Caching
    // ====================================================================

    public function test_cost_aware_manager_is_available()
    {
        $manager = $this->smartCache->getCostAwareManager();
        $this->assertNotNull($manager);
        $this->assertInstanceOf(\SmartCache\Services\CostAwareCacheManager::class, $manager);
    }

    public function test_remember_records_cost_for_new_values()
    {
        $this->smartCache->remember('cost-test', 3600, function () {
            usleep(10000); // 10ms delay to ensure measurable cost
            return 'expensive-value';
        });

        $metadata = $this->smartCache->cacheValue('cost-test');
        $this->assertNotNull($metadata);
        $this->assertGreaterThan(0, $metadata['cost_ms']);
        $this->assertGreaterThan(0, $metadata['size_bytes']);
        $this->assertEquals(1, $metadata['access_count']);
        $this->assertGreaterThan(0, $metadata['score']);
    }

    public function test_remember_records_access_on_cache_hit()
    {
        // First call: records cost
        $this->smartCache->remember('access-test', 3600, fn() => 'value');
        $meta1 = $this->smartCache->cacheValue('access-test');
        $this->assertEquals(1, $meta1['access_count']);

        // Second call: records access (cache hit)
        $this->smartCache->remember('access-test', 3600, fn() => 'new-value');
        $meta2 = $this->smartCache->cacheValue('access-test');
        $this->assertEquals(2, $meta2['access_count']);
    }

    public function test_cache_value_returns_null_for_untracked_key()
    {
        $this->assertNull($this->smartCache->cacheValue('untracked-key'));
    }

    public function test_get_cache_value_report_returns_sorted_results()
    {
        // Create entries with different costs
        $this->smartCache->remember('cheap-key', 3600, fn() => 'cheap');
        $this->smartCache->remember('expensive-key', 3600, function () {
            usleep(20000); // 20ms
            return 'expensive';
        });

        $report = $this->smartCache->getCacheValueReport();
        $this->assertNotEmpty($report);
        $this->assertCount(2, $report);

        // Should be sorted by score (highest first)
        $this->assertGreaterThanOrEqual($report[1]['score'], $report[0]['score']);
    }

    public function test_suggest_evictions_returns_lowest_value_keys()
    {
        $this->smartCache->remember('keep-me', 3600, function () {
            usleep(20000);
            return 'expensive';
        });
        $this->smartCache->remember('evict-me', 3600, fn() => 'cheap');

        $suggestions = $this->smartCache->suggestEvictions(1);
        $this->assertCount(1, $suggestions);
        // The cheap key should be suggested for eviction
        $this->assertEquals('evict-me', $suggestions[0]['key']);
    }

    public function test_cost_aware_caching_disabled_returns_empty()
    {
        // Override config to disable cost-aware caching
        $this->app['config']->set('smart-cache.cost_aware.enabled', false);

        // Rebuild SmartCache instance
        $this->app->forgetInstance(\SmartCache\Contracts\SmartCache::class);
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);

        $this->assertNull($smartCache->getCostAwareManager());
        $this->assertNull($smartCache->cacheValue('any-key'));
        $this->assertEmpty($smartCache->getCacheValueReport());
        $this->assertEmpty($smartCache->suggestEvictions());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
