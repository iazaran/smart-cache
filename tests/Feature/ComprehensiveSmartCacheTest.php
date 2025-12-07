<?php

namespace SmartCache\Tests\Feature;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;

/**
 * Comprehensive SmartCache Feature Tests
 * 
 * Based on the gist test controller: https://gist.github.com/iazaran/d0706e21db6a445c1e9a63de7fcbb2ad
 * Tests all SmartCache features including new v2.0 features.
 */
class ComprehensiveSmartCacheTest extends TestCase
{
    protected SmartCache $smartCache;
    protected string $testPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCache::class);
        $this->testPrefix = 'test_' . time() . '_' . mt_rand(1000, 9999);
    }

    protected function tearDown(): void
    {
        // Clean up test keys
        foreach ($this->smartCache->getManagedKeys() as $key) {
            if (str_starts_with($key, 'test_') || str_starts_with($key, $this->testPrefix)) {
                $this->smartCache->forget($key);
            }
        }
        parent::tearDown();
    }

    // ========================================
    // BASIC OPERATIONS
    // ========================================

    public function test_basic_put_and_get_operations(): void
    {
        $key = "{$this->testPrefix}_basic";
        $testData = ['data' => 'test_value', 'number' => 123, 'array' => range(1, 10)];

        $this->assertTrue($this->smartCache->put($key, $testData, 60));
        $retrieved = $this->smartCache->get($key);

        $this->assertEquals($testData, $retrieved);
        $this->assertTrue($this->smartCache->has($key));
    }

    public function test_forget_operation(): void
    {
        $key = "{$this->testPrefix}_forget";
        $this->smartCache->put($key, 'test_value', 60);

        $this->assertTrue($this->smartCache->has($key));
        $this->assertTrue($this->smartCache->forget($key));
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_forever_operation(): void
    {
        $key = "{$this->testPrefix}_forever";
        $testData = ['permanent' => true];

        $this->assertTrue($this->smartCache->forever($key, $testData));
        $this->assertEquals($testData, $this->smartCache->get($key));
    }

    public function test_remember_operation(): void
    {
        $key = "{$this->testPrefix}_remember";
        $callCount = 0;

        $result1 = $this->smartCache->remember($key, 60, function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });

        $result2 = $this->smartCache->remember($key, 60, function () use (&$callCount) {
            $callCount++;
            return 'should_not_be_called';
        });

        $this->assertEquals('computed_value', $result1);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    public function test_default_value_for_missing_key(): void
    {
        $result = $this->smartCache->get('non_existent_key_' . mt_rand(), 'default_value');
        $this->assertEquals('default_value', $result);
    }

    // ========================================
    // COMPRESSION TESTS
    // ========================================

    public function test_compression_with_large_data(): void
    {
        $key = "{$this->testPrefix}_compression";
        $largeData = $this->createLargeCompressibleData(2000);

        $this->smartCache->put($key, $largeData, 300);
        $retrieved = $this->smartCache->get($key);

        $this->assertEquals($largeData, $retrieved);
        $this->assertCount(2000, $retrieved);
    }

    // ========================================
    // CHUNKING TESTS
    // ========================================

    public function test_chunking_with_very_large_data(): void
    {
        $key = "{$this->testPrefix}_chunking";
        $veryLargeData = $this->createVeryLargeData(5000);

        $this->smartCache->put($key, $veryLargeData, 300);
        $retrieved = $this->smartCache->get($key);

        $this->assertEquals($veryLargeData, $retrieved);
        $this->assertCount(5000, $retrieved);
    }

    // ========================================
    // TAG MANAGEMENT
    // ========================================

    public function test_tag_based_caching(): void
    {
        $key1 = "{$this->testPrefix}_tagged1";
        $key2 = "{$this->testPrefix}_tagged2";

        $this->smartCache->tags(['products'])->put($key1, 'product1', 60);
        $this->smartCache->tags(['products'])->put($key2, 'product2', 60);

        $this->assertTrue($this->smartCache->has($key1));
        $this->assertTrue($this->smartCache->has($key2));

        $this->smartCache->flushTags('products');

        $this->assertFalse($this->smartCache->has($key1));
        $this->assertFalse($this->smartCache->has($key2));
    }

    // ========================================
    // DEPENDENCY INVALIDATION
    // ========================================

    public function test_dependency_invalidation(): void
    {
        $parentKey = "{$this->testPrefix}_parent";
        $childKey = "{$this->testPrefix}_child";

        $this->smartCache->put($parentKey, 'parent_data', 60);
        $this->smartCache->put($childKey, 'child_data', 60);
        $this->smartCache->dependsOn($childKey, $parentKey);

        $this->assertTrue($this->smartCache->has($parentKey));
        $this->assertTrue($this->smartCache->has($childKey));

        $this->smartCache->invalidate($parentKey);

        $this->assertFalse($this->smartCache->has($parentKey));
        $this->assertFalse($this->smartCache->has($childKey));
    }

    // ========================================
    // PATTERN INVALIDATION
    // ========================================

    public function test_pattern_invalidation(): void
    {
        $key1 = "{$this->testPrefix}_user_1";
        $key2 = "{$this->testPrefix}_user_2";
        $key3 = "{$this->testPrefix}_order_1";

        $this->smartCache->put($key1, 'user1', 60);
        $this->smartCache->put($key2, 'user2', 60);
        $this->smartCache->put($key3, 'order1', 60);

        $this->smartCache->flushPatterns(["{$this->testPrefix}_user_*"]);

        $this->assertFalse($this->smartCache->has($key1));
        $this->assertFalse($this->smartCache->has($key2));
        $this->assertTrue($this->smartCache->has($key3));
    }

    // ========================================
    // BATCH OPERATIONS
    // ========================================

    public function test_batch_put_and_get(): void
    {
        $data = [
            "{$this->testPrefix}_batch1" => ['value' => 1],
            "{$this->testPrefix}_batch2" => ['value' => 2],
            "{$this->testPrefix}_batch3" => ['value' => 3],
        ];

        $this->smartCache->putMany($data, 60);
        $retrieved = $this->smartCache->many(array_keys($data));

        $this->assertEquals($data, $retrieved);
    }

    public function test_delete_multiple(): void
    {
        $keys = [
            "{$this->testPrefix}_del1",
            "{$this->testPrefix}_del2",
        ];

        foreach ($keys as $key) {
            $this->smartCache->put($key, 'value', 60);
        }

        $this->smartCache->deleteMultiple($keys);

        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    // ========================================
    // MEMOIZATION
    // ========================================

    public function test_memoization(): void
    {
        $key = "{$this->testPrefix}_memo";

        $memo = $this->smartCache->memo();
        $memo->put($key, 'memoized_value', 60);

        $this->assertEquals('memoized_value', $memo->get($key));
    }

    // ========================================
    // STATISTICS AND HEALTH
    // ========================================

    public function test_statistics(): void
    {
        $this->smartCache->put("{$this->testPrefix}_stats", 'value', 60);

        $stats = $this->smartCache->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('managed_keys_count', $stats);
    }

    public function test_health_check(): void
    {
        $health = $this->smartCache->healthCheck();

        $this->assertIsArray($health);
        $this->assertArrayHasKey('orphaned_chunks_cleaned', $health);
        $this->assertArrayHasKey('expired_keys_cleaned', $health);
    }

    // ========================================
    // PERFORMANCE METRICS
    // ========================================

    public function test_performance_metrics(): void
    {
        $key = "{$this->testPrefix}_perf";
        $this->smartCache->put($key, 'value', 60);
        $this->smartCache->get($key);
        $this->smartCache->get('non_existent_' . mt_rand());

        $metrics = $this->smartCache->getPerformanceMetrics();

        $this->assertIsArray($metrics);
    }

    // ========================================
    // SWR / FLEXIBLE CACHING
    // ========================================

    public function test_swr_pattern(): void
    {
        $key = "{$this->testPrefix}_swr";
        $callCount = 0;

        $result = $this->smartCache->swr($key, function () use (&$callCount) {
            $callCount++;
            return 'swr_value';
        }, 60, 120);

        $this->assertEquals('swr_value', $result);
        $this->assertEquals(1, $callCount);
    }

    public function test_flexible_caching(): void
    {
        $key = "{$this->testPrefix}_flexible";

        $result = $this->smartCache->flexible($key, [60, 120], function () {
            return 'flexible_value';
        });

        $this->assertEquals('flexible_value', $result);
    }

    // ========================================
    // COMMAND EXECUTION
    // ========================================

    public function test_available_commands(): void
    {
        $commands = $this->smartCache->getAvailableCommands();

        $this->assertIsArray($commands);
        $this->assertArrayHasKey('smart-cache:clear', $commands);
        $this->assertArrayHasKey('smart-cache:status', $commands);
    }

    public function test_execute_status_command(): void
    {
        $result = $this->smartCache->executeCommand('smart-cache:status');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache_driver', $result);
    }

    // ========================================
    // NEW v2.0 FEATURES
    // ========================================

    public function test_namespace_prefixing(): void
    {
        $key = 'namespaced_key';
        $namespace = 'users';

        // Put with namespace - key becomes "users:namespaced_key"
        $this->smartCache->namespace($namespace)->put($key, 'namespaced_value', 60);

        // Check using the same namespace context
        $this->assertTrue($this->smartCache->namespace($namespace)->has($key));
        $this->assertEquals('namespaced_value', $this->smartCache->namespace($namespace)->get($key));

        // Flush the namespace
        $this->smartCache->flushNamespace($namespace);
        $this->assertFalse($this->smartCache->namespace($namespace)->has($key));
    }

    public function test_ttl_jitter(): void
    {
        $key = "{$this->testPrefix}_jitter";
        $baseTtl = 3600;

        $this->smartCache->withJitter(0.1)->put($key, 'jitter_value', $baseTtl);

        $this->assertTrue($this->smartCache->has($key));
        $this->assertEquals('jitter_value', $this->smartCache->get($key));
    }

    public function test_remember_with_jitter(): void
    {
        $key = "{$this->testPrefix}_remember_jitter";

        $result = $this->smartCache->rememberWithJitter($key, 3600, 0.1, function () {
            return 'jitter_remembered';
        });

        $this->assertEquals('jitter_remembered', $result);
    }

    // ========================================
    // CIRCUIT BREAKER
    // ========================================

    public function test_circuit_breaker_availability(): void
    {
        $this->assertTrue($this->smartCache->isAvailable());
    }

    public function test_circuit_breaker_stats(): void
    {
        $stats = $this->smartCache->getCircuitBreakerStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('state', $stats);
        $this->assertArrayHasKey('failure_count', $stats);
    }

    public function test_with_fallback(): void
    {
        $key = "{$this->testPrefix}_fallback";

        $result = $this->smartCache->withFallback(
            fn() => $this->smartCache->get($key, 'primary'),
            fn() => 'fallback_value'
        );

        $this->assertEquals('primary', $result);
    }

    // ========================================
    // RATE LIMITING
    // ========================================

    public function test_throttle(): void
    {
        $key = "{$this->testPrefix}_throttle";

        $result = $this->smartCache->throttle($key, 10, 60, function () {
            return 'throttled_result';
        });

        $this->assertEquals('throttled_result', $result);
    }

    public function test_remember_with_stampede_protection(): void
    {
        $key = "{$this->testPrefix}_stampede";

        $result = $this->smartCache->rememberWithStampedeProtection($key, 3600, function () {
            return 'stampede_protected';
        });

        $this->assertEquals('stampede_protected', $result);
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    protected function createLargeCompressibleData(int $count): array
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'id' => $i,
                'name' => 'User ' . $i,
                'email' => 'user' . $i . '@example.com',
                'description' => str_repeat('Description for user ' . $i . '. ', 10),
                'metadata' => array_fill(0, 20, 'meta_data_' . $i),
            ];
        }
        return $data;
    }

    protected function createVeryLargeData(int $count): array
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data['key_' . $i] = [
                'index' => $i,
                'data' => str_repeat('Large chunk data ' . $i . '. ', 20),
                'nested' => [
                    'level1' => array_fill(0, 10, 'nested_data_' . $i),
                    'level2' => array_fill(0, 15, 'more_nested_' . $i),
                ],
            ];
        }
        return $data;
    }
}
