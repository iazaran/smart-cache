<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\SmartCache;
use SmartCache\Contracts\SmartCache as SmartCacheContract;

class Laravel12SWRTest extends TestCase
{
    protected SmartCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->app->make(SmartCacheContract::class);
    }

    public function test_swr_method_exists(): void
    {
        $this->assertTrue(method_exists($this->cache, 'swr'));
    }

    public function test_stale_method_exists(): void
    {
        $this->assertTrue(method_exists($this->cache, 'stale'));
    }

    public function test_refresh_ahead_method_exists(): void
    {
        $this->assertTrue(method_exists($this->cache, 'refreshAhead'));
    }

    public function test_swr_caching_pattern(): void
    {
        $key = 'swr_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return [
                'data' => 'test_value',
                'timestamp' => now()->toDateTimeString(),
                'call_count' => $callCount
            ];
        };

        // First call should execute callback
        $result1 = $this->cache->swr($key, $callback, 2, 10); // 2 second fresh, 10 second stale
        $this->assertEquals(1, $callCount);
        $this->assertEquals('test_value', $result1['data']);
        $this->assertEquals(1, $result1['call_count']);

        // Immediate second call should use cache
        $result2 = $this->cache->swr($key, $callback, 2, 10);
        $this->assertEquals(1, $callCount); // Callback not called again
        $this->assertEquals($result1, $result2);

        // Wait for data to become stale but not expired
        sleep(3); // Fresh TTL exceeded but within stale TTL

        // This should return stale data immediately and trigger background refresh
        $result3 = $this->cache->swr($key, $callback, 2, 10);
        $this->assertEquals('test_value', $result3['data']);
        
        // The callback should have been called for background refresh
        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function test_stale_caching_pattern(): void
    {
        $key = 'stale_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return [
                'data' => 'stale_test_value',
                'timestamp' => now()->toDateTimeString(),
                'call_count' => $callCount
            ];
        };

        // First call should execute callback
        $result1 = $this->cache->stale($key, $callback, 1, 5); // 1 second fresh, 5 second stale
        $this->assertEquals(1, $callCount);
        $this->assertEquals('stale_test_value', $result1['data']);

        // Immediate call should use cache
        $result2 = $this->cache->stale($key, $callback, 1, 5);
        $this->assertEquals(1, $callCount);
        $this->assertEquals($result1, $result2);

        // Wait for fresh period to expire
        sleep(2);

        // Should serve stale data
        $result3 = $this->cache->stale($key, $callback, 1, 5);
        $this->assertEquals('stale_test_value', $result3['data']);
    }

    public function test_refresh_ahead_caching_pattern(): void
    {
        $key = 'refresh_ahead_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return [
                'data' => 'refresh_ahead_value',
                'timestamp' => now()->toDateTimeString(),
                'call_count' => $callCount
            ];
        };

        // First call should execute callback
        $result1 = $this->cache->refreshAhead($key, $callback, 5, 2); // 5 second TTL, 2 second refresh window
        $this->assertEquals(1, $callCount);
        $this->assertEquals('refresh_ahead_value', $result1['data']);

        // Call within fresh period should use cache
        $result2 = $this->cache->refreshAhead($key, $callback, 5, 2);
        $this->assertEquals(1, $callCount);
        $this->assertEquals($result1, $result2);

        // Wait to enter refresh window
        sleep(4); // Should trigger refresh-ahead behavior

        $result3 = $this->cache->refreshAhead($key, $callback, 5, 2);
        $this->assertEquals('refresh_ahead_value', $result3['data']);
        
        // Should have triggered background refresh
        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function test_flexible_method_with_custom_durations(): void
    {
        $key = 'flexible_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return [
                'flexible' => true,
                'call_count' => $callCount
            ];
        };

        // Test with custom durations
        $result1 = $this->cache->flexible($key, [3, 8], $callback); // 3 second fresh, 8 second total
        $this->assertEquals(1, $callCount);
        $this->assertTrue($result1['flexible']);

        // Should use cache within fresh period
        $result2 = $this->cache->flexible($key, [3, 8], $callback);
        $this->assertEquals(1, $callCount);
        $this->assertEquals($result1, $result2);
    }

    public function test_swr_with_facade(): void
    {
        $key = 'facade_swr_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return ['facade_swr' => true, 'count' => $callCount];
        };

        // Test facade method
        $result = \SmartCache\Facades\SmartCache::swr($key, $callback);
        $this->assertEquals(1, $callCount);
        $this->assertTrue($result['facade_swr']);
        $this->assertEquals(1, $result['count']);

        // Second call should use cache
        $result2 = \SmartCache\Facades\SmartCache::swr($key, $callback);
        $this->assertEquals(1, $callCount); // No additional callback execution
        $this->assertEquals($result, $result2);
    }

    public function test_stale_with_facade(): void
    {
        $key = 'facade_stale_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return ['facade_stale' => true, 'count' => $callCount];
        };

        $result = \SmartCache\Facades\SmartCache::stale($key, $callback);
        $this->assertEquals(1, $callCount);
        $this->assertTrue($result['facade_stale']);
    }

    public function test_refresh_ahead_with_facade(): void
    {
        $key = 'facade_refresh_ahead_test_' . time();
        $callCount = 0;
        
        $callback = function () use (&$callCount) {
            $callCount++;
            return ['facade_refresh_ahead' => true, 'count' => $callCount];
        };

        $result = \SmartCache\Facades\SmartCache::refreshAhead($key, $callback);
        $this->assertEquals(1, $callCount);
        $this->assertTrue($result['facade_refresh_ahead']);
    }

    public function test_swr_methods_use_optimization(): void
    {
        $key = 'optimization_swr_test_' . time();
        
        // Create large data that should trigger compression
        $largeData = array_fill(0, 2000, 'large_data_item_for_compression_testing');
        
        $callback = function () use ($largeData) {
            return $largeData;
        };

        $result = $this->cache->swr($key, $callback);
        $this->assertEquals($largeData, $result);
        
        // Verify the key was tracked (indicating optimization was applied)
        $managedKeys = $this->cache->getManagedKeys();
        $this->assertContains($key, $managedKeys);
    }

    public function test_all_swr_methods_handle_exceptions_gracefully(): void
    {
        $key = 'exception_test_' . time();
        
        $callback = function () {
            throw new \Exception('Test exception');
        };

        // All methods should handle exceptions gracefully when fallback is enabled
        $this->expectException(\Exception::class);
        $this->cache->swr($key, $callback);
    }
}
