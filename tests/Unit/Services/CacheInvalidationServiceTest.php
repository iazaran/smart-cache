<?php

namespace SmartCache\Tests\Unit\Services;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;
use SmartCache\Services\CacheInvalidationService;
use Illuminate\Support\Facades\Cache;

class CacheInvalidationServiceTest extends TestCase
{
    protected SmartCache $smartCache;
    protected CacheInvalidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCache::class);
        $this->service = new CacheInvalidationService($this->smartCache);
    }

    public function test_service_can_be_instantiated()
    {
        $this->assertInstanceOf(CacheInvalidationService::class, $this->service);
    }

    public function test_flush_patterns_method()
    {
        // Cache data with various patterns
        $keys = [
            'user_123_profile' => 'profile_data',
            'user_123_settings' => 'settings_data',
            'user_456_profile' => 'other_profile',
            'admin_permissions' => 'admin_data'
        ];

        foreach ($keys as $key => $value) {
            $this->smartCache->put($key, $value, 3600);
        }

        // Flush multiple patterns
        $patterns = ['user_123_*', 'admin_*'];
        $cleared = $this->service->flushPatterns($patterns);

        // Verify correct keys are removed
        $this->assertFalse($this->smartCache->has('user_123_profile'));
        $this->assertFalse($this->smartCache->has('user_123_settings'));
        $this->assertFalse($this->smartCache->has('admin_permissions'));

        // Verify other keys remain
        $this->assertTrue($this->smartCache->has('user_456_profile'));

        $this->assertEquals(3, $cleared);
    }

    public function test_invalidate_model_relations_basic()
    {
        $modelClass = 'App\Models\User';
        $modelId = 123;

        // Cache data that should match model patterns
        $keys = [
            'App\Models\User_123_profile' => 'user_profile',
            'App\Models\User_123_settings' => 'user_settings',
            'User_123_cache' => 'user_cache',
            'user_123_data' => 'user_data',
            'Other_456_data' => 'other_data'
        ];

        foreach ($keys as $key => $value) {
            $this->smartCache->put($key, $value, 3600);
        }

        // Invalidate model relations
        $cleared = $this->service->invalidateModelRelations($modelClass, $modelId);

        // Should clear keys matching the model patterns
        $this->assertGreaterThan(0, $cleared);
        
        // Other data should remain
        $this->assertTrue($this->smartCache->has('Other_456_data'));
    }

    public function test_invalidate_model_relations_with_relationships()
    {
        $modelClass = 'User';
        $modelId = 123;
        $relationships = ['posts', 'comments', 'settings'];

        // Cache relationship data
        $keys = [
            'User_123_profile' => 'user_data',
            'posts_456_User_123' => 'post_relation',
            'User_123_posts_789' => 'user_posts',
            'comments_User_123_active' => 'comment_relation',
            'settings_User_123_theme' => 'settings_relation',
            'unrelated_data' => 'other_data'
        ];

        foreach ($keys as $key => $value) {
            $this->smartCache->put($key, $value, 3600);
        }

        $cleared = $this->service->invalidateModelRelations($modelClass, $modelId, $relationships);

        // Should clear relationship-based patterns
        $this->assertGreaterThan(0, $cleared);
        
        // Unrelated data should remain
        $this->assertTrue($this->smartCache->has('unrelated_data'));
    }

    public function test_setup_cache_warming()
    {
        $warmingRules = [
            [
                'key' => 'warmed_key_1',
                'callback' => function() { return 'warmed_value_1'; },
                'ttl' => 3600
            ],
            [
                'key' => 'warmed_key_2', 
                'callback' => function() { return 'warmed_value_2'; },
                'ttl' => 7200
            ]
        ];

        // Initially keys should not exist
        $this->assertFalse($this->smartCache->has('warmed_key_1'));
        $this->assertFalse($this->smartCache->has('warmed_key_2'));

        // Setup cache warming
        $this->service->setupCacheWarming($warmingRules);

        // Keys should now be warmed
        $this->assertTrue($this->smartCache->has('warmed_key_1'));
        $this->assertTrue($this->smartCache->has('warmed_key_2'));
        $this->assertEquals('warmed_value_1', $this->smartCache->get('warmed_key_1'));
        $this->assertEquals('warmed_value_2', $this->smartCache->get('warmed_key_2'));
    }

    public function test_cache_warming_skips_existing_keys()
    {
        $callCount = 0;
        
        $warmingRules = [
            [
                'key' => 'existing_key',
                'callback' => function() use (&$callCount) {
                    $callCount++;
                    return 'new_value';
                },
                'ttl' => 3600
            ]
        ];

        // Pre-populate the key
        $this->smartCache->put('existing_key', 'existing_value', 3600);

        // Setup cache warming
        $this->service->setupCacheWarming($warmingRules);

        // Callback should not have been called
        $this->assertEquals(0, $callCount);
        
        // Original value should remain
        $this->assertEquals('existing_value', $this->smartCache->get('existing_key'));
    }

    public function test_create_cache_hierarchy()
    {
        $parentKey = 'hierarchy_parent';
        $childKeys = ['hierarchy_child_1', 'hierarchy_child_2', 'hierarchy_child_3'];

        // Create hierarchy
        $this->service->createCacheHierarchy($parentKey, $childKeys);

        // Cache all items
        $this->smartCache->put($parentKey, 'parent_data', 3600);
        foreach ($childKeys as $key) {
            $this->smartCache->put($key, "data_for_{$key}", 3600);
        }

        // Invalidate parent - should cascade to all children
        $this->smartCache->invalidate($parentKey);

        // Verify all are removed
        $this->assertFalse($this->smartCache->has($parentKey));
        foreach ($childKeys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_get_cache_statistics_basic()
    {
        // Cache some data with different optimizations
        $this->smartCache->put('small_key', 'small_data', 3600);
        
        $compressibleData = $this->createCompressibleData();
        $this->smartCache->put('compressed_key', $compressibleData, 3600);
        
        $chunkableData = $this->createChunkableData();
        $this->smartCache->put('chunked_key', $chunkableData, 3600);

        $stats = $this->service->getCacheStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('managed_keys_count', $stats);
        $this->assertArrayHasKey('tag_usage', $stats);
        $this->assertArrayHasKey('dependency_chains', $stats);
        $this->assertArrayHasKey('optimization_stats', $stats);

        $this->assertIsArray($stats['optimization_stats']);
        $this->assertArrayHasKey('compressed', $stats['optimization_stats']);
        $this->assertArrayHasKey('chunked', $stats['optimization_stats']);
        $this->assertArrayHasKey('unoptimized', $stats['optimization_stats']);

        // Should have at least some managed keys
        $this->assertGreaterThan(0, $stats['managed_keys_count']);
    }

    public function test_get_cache_statistics_with_optimization_detection()
    {
        // Clear any existing cache to get clean stats
        $this->smartCache->clear();

        // Cache data that will be compressed
        $compressibleData = $this->createCompressibleData();
        $this->smartCache->put('compressed_data', $compressibleData, 3600);

        // Cache data that will be chunked
        $chunkableData = $this->createChunkableData();
        $this->smartCache->put('chunked_data', $chunkableData, 3600);

        // Cache small unoptimized data
        $this->smartCache->put('small_data', 'small', 3600);

        $stats = $this->service->getCacheStatistics();

        // Debug output to see what we actually get
        $this->assertIsArray($stats['optimization_stats']);
        $this->assertArrayHasKey('compressed', $stats['optimization_stats']);
        $this->assertArrayHasKey('chunked', $stats['optimization_stats']);
        $this->assertArrayHasKey('unoptimized', $stats['optimization_stats']);
        
        // Should have 3 managed keys total
        $this->assertEquals(3, $stats['managed_keys_count']);
        
        // The sum of all optimization types should equal the total managed keys
        $totalOptimized = $stats['optimization_stats']['compressed'] + 
                         $stats['optimization_stats']['chunked'] + 
                         $stats['optimization_stats']['unoptimized'];
        $this->assertEquals(3, $totalOptimized);
    }

    public function test_health_check_and_cleanup()
    {
        // Create some normal cache entries
        $this->smartCache->put('healthy_key_1', 'data_1', 3600);
        $this->smartCache->put('healthy_key_2', 'data_2', 3600);

        // Create chunked data
        $largeArray = $this->createChunkableData();
        $this->smartCache->put('chunked_key', $largeArray, 3600);

        // Get the chunked metadata to simulate broken chunks
        $chunkedMeta = $this->smartCache->get('chunked_key');
        if (isset($chunkedMeta['chunk_keys'])) {
            // Manually remove one chunk to simulate orphaned chunks
            $firstChunkKey = $chunkedMeta['chunk_keys'][0];
            $this->smartCache->store()->forget($firstChunkKey);
        }

        $healthReport = $this->service->healthCheckAndCleanup();

        $this->assertIsArray($healthReport);
        $this->assertArrayHasKey('orphaned_chunks_cleaned', $healthReport);
        $this->assertArrayHasKey('broken_dependencies_fixed', $healthReport);
        $this->assertArrayHasKey('invalid_tags_removed', $healthReport);
        $this->assertArrayHasKey('total_keys_checked', $healthReport);

        $this->assertGreaterThan(0, $healthReport['total_keys_checked']);
        
        // Should have cleaned up the broken chunked key
        if (isset($chunkedMeta['chunk_keys'])) {
            $this->assertGreaterThan(0, $healthReport['orphaned_chunks_cleaned']);
            $this->assertFalse($this->smartCache->has('chunked_key'));
        }

        // Healthy keys should remain
        $this->assertTrue($this->smartCache->has('healthy_key_1'));
        $this->assertTrue($this->smartCache->has('healthy_key_2'));
    }

    public function test_advanced_pattern_matching_with_regex()
    {
        $keys = [
            'temp_1609459200_data' => 'timestamp_1',
            'temp_1609545600_data' => 'timestamp_2',
            'temp_abc_data' => 'non_timestamp',
            'other_key' => 'other'
        ];

        foreach ($keys as $key => $value) {
            $this->smartCache->put($key, $value, 3600);
        }

        // Test regex pattern matching
        $cleared = $this->service->flushPatterns(['/^temp_\d{10}_data$/']);

        // Should match timestamp patterns only
        $this->assertFalse($this->smartCache->has('temp_1609459200_data'));
        $this->assertFalse($this->smartCache->has('temp_1609545600_data'));

        // Should not match non-timestamp
        $this->assertTrue($this->smartCache->has('temp_abc_data'));
        $this->assertTrue($this->smartCache->has('other_key'));

        $this->assertEquals(2, $cleared);
    }

    public function test_advanced_pattern_matching_with_glob()
    {
        $keys = [
            'user_123_profile' => 'profile',
            'user_123_settings' => 'settings',
            'user_456_profile' => 'other_profile',
            'admin_profile' => 'admin'
        ];

        foreach ($keys as $key => $value) {
            $this->smartCache->put($key, $value, 3600);
        }

        // Test glob pattern matching
        $cleared = $this->service->flushPatterns(['user_*_profile']);

        // Should match user profile patterns
        $this->assertFalse($this->smartCache->has('user_123_profile'));
        $this->assertFalse($this->smartCache->has('user_456_profile'));

        // Should not match other patterns
        $this->assertTrue($this->smartCache->has('user_123_settings'));
        $this->assertTrue($this->smartCache->has('admin_profile'));

        $this->assertEquals(2, $cleared);
    }

    public function test_service_integration_with_smartcache()
    {
        // Test that service is accessible through SmartCache
        $serviceFromSmartCache = $this->smartCache->invalidationService();
        
        $this->assertInstanceOf(CacheInvalidationService::class, $serviceFromSmartCache);

        // Test that direct methods work
        $this->smartCache->put('integration_test', 'data', 3600);
        $cleared = $this->smartCache->flushPatterns(['integration_*']);
        
        $this->assertEquals(1, $cleared);
        $this->assertFalse($this->smartCache->has('integration_test'));
    }

    public function test_model_invalidation_through_service()
    {
        // Test the invalidateModel method that delegates to the service
        $this->smartCache->put('User_123_data', 'user_data', 3600);
        $this->smartCache->put('Post_456_data', 'post_data', 3600);

        $cleared = $this->smartCache->invalidateModel('User', 123, ['posts']);

        $this->assertGreaterThanOrEqual(0, $cleared);
        $this->assertTrue($this->smartCache->has('Post_456_data')); // Should remain
    }

    public function test_statistics_through_smartcache()
    {
        $this->smartCache->put('stats_test', 'data', 3600);

        $stats = $this->smartCache->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('managed_keys_count', $stats);
    }

    public function test_health_check_through_smartcache()
    {
        $this->smartCache->put('health_test', 'data', 3600);

        $health = $this->smartCache->healthCheck();

        $this->assertIsArray($health);
        $this->assertArrayHasKey('total_keys_checked', $health);
    }

    public function test_service_handles_empty_managed_keys_gracefully()
    {
        // Clear all cache to start with empty state
        $this->smartCache->clear();

        $cleared = $this->service->flushPatterns(['non_existent_*']);
        $this->assertEquals(0, $cleared);

        $stats = $this->service->getCacheStatistics();
        $this->assertEquals(0, $stats['managed_keys_count']);

        $health = $this->service->healthCheckAndCleanup();
        $this->assertEquals(0, $health['total_keys_checked']);
    }

    public function test_cache_warming_with_missing_parameters()
    {
        $warmingRules = [
            ['key' => 'incomplete_rule_1'], // Missing callback and ttl
            ['callback' => function() { return 'test'; }], // Missing key and ttl
            ['ttl' => 3600], // Missing key and callback
            [
                'key' => 'complete_rule',
                'callback' => function() { return 'complete_data'; },
                'ttl' => 3600
            ]
        ];

        // Should handle incomplete rules gracefully
        $this->service->setupCacheWarming($warmingRules);

        // Only complete rule should be processed
        $this->assertTrue($this->smartCache->has('complete_rule'));
        $this->assertEquals('complete_data', $this->smartCache->get('complete_rule'));
        
        // Incomplete rules should be ignored
        $this->assertFalse($this->smartCache->has('incomplete_rule_1'));
    }

    public function test_pattern_matching_performance_with_service()
    {
        $keyCount = 200;
        $matchingPattern = 'perf_match_';
        
        // Create many keys
        for ($i = 0; $i < $keyCount; $i++) {
            if ($i < $keyCount / 2) {
                $this->smartCache->put($matchingPattern . $i, "data_{$i}", 3600);
            } else {
                $this->smartCache->put("other_key_{$i}", "data_{$i}", 3600);
            }
        }

        $startTime = microtime(true);
        $cleared = $this->service->flushPatterns([$matchingPattern . '*']);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $this->assertEquals($keyCount / 2, $cleared);
        $this->assertLessThan(1.0, $executionTime, 'Service pattern matching should be performant');
    }
}
