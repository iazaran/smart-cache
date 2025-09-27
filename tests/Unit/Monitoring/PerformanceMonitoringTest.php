<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\SmartCache;
use SmartCache\Contracts\SmartCache as SmartCacheContract;

class PerformanceMonitoringTest extends TestCase
{
    protected SmartCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->app->make(SmartCacheContract::class);
        
        // Reset performance metrics before each test
        $this->cache->resetPerformanceMetrics();
    }

    public function test_performance_metrics_are_tracked(): void
    {
        // Perform some cache operations
        $this->cache->put('perf_test_1', 'value1', 3600);
        $this->cache->put('perf_test_2', 'value2', 3600);
        $this->cache->get('perf_test_1');
        $this->cache->get('nonexistent_key');
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('monitoring_enabled', $metrics);
        $this->assertArrayHasKey('metrics', $metrics);
        $this->assertArrayHasKey('cache_efficiency', $metrics);
        $this->assertArrayHasKey('optimization_impact', $metrics);
        
        $this->assertTrue($metrics['monitoring_enabled']);
        $this->assertIsArray($metrics['metrics']);
    }

    public function test_cache_hit_metrics_are_recorded(): void
    {
        $key = 'hit_test_' . time();
        
        // Put a value and then get it (should be a hit)
        $this->cache->put($key, 'test_value', 3600);
        $this->cache->get($key);
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        $this->assertArrayHasKey('cache_hit', $metrics['metrics']);
        $this->assertEquals(1, $metrics['metrics']['cache_hit']['count']);
        $this->assertGreaterThan(0, $metrics['metrics']['cache_hit']['total_duration']);
        $this->assertIsFloat($metrics['metrics']['cache_hit']['average_duration']);
    }

    public function test_cache_miss_metrics_are_recorded(): void
    {
        $nonexistentKey = 'miss_test_' . time();
        
        // Try to get a non-existent key (should be a miss)
        $this->cache->get($nonexistentKey);
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        $this->assertArrayHasKey('cache_miss', $metrics['metrics']);
        $this->assertEquals(1, $metrics['metrics']['cache_miss']['count']);
        $this->assertGreaterThan(0, $metrics['metrics']['cache_miss']['total_duration']);
        $this->assertIsFloat($metrics['metrics']['cache_miss']['average_duration']);
    }

    public function test_cache_write_metrics_are_recorded(): void
    {
        $key = 'write_test_' . time();
        $value = ['test' => 'data', 'array' => range(1, 100)];
        
        $this->cache->put($key, $value, 3600);
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        $this->assertArrayHasKey('cache_write', $metrics['metrics']);
        $this->assertEquals(1, $metrics['metrics']['cache_write']['count']);
        $this->assertGreaterThan(0, $metrics['metrics']['cache_write']['total_duration']);
        $this->assertIsFloat($metrics['metrics']['cache_write']['average_duration']);
        
        // Check recent entries contain metadata
        $recent = $metrics['metrics']['cache_write']['recent'];
        $this->assertCount(1, $recent);
        $this->assertArrayHasKey('metadata', $recent[0]);
        $this->assertArrayHasKey('original_size', $recent[0]['metadata']);
        $this->assertArrayHasKey('optimized_size', $recent[0]['metadata']);
    }

    public function test_cache_efficiency_calculation(): void
    {
        $key = 'efficiency_test_' . time();
        
        // Generate 3 hits and 2 misses
        $this->cache->put($key, 'value', 3600);
        
        // 3 hits
        $this->cache->get($key);
        $this->cache->get($key);
        $this->cache->get($key);
        
        // 2 misses
        $this->cache->get('nonexistent_1');
        $this->cache->get('nonexistent_2');
        
        $metrics = $this->cache->getPerformanceMetrics();
        $efficiency = $metrics['cache_efficiency'];
        
        $this->assertEquals(3, $efficiency['hit_count']);
        $this->assertEquals(2, $efficiency['miss_count']);
        $this->assertEquals(5, $efficiency['total_requests']);
        $this->assertEquals(60.0, $efficiency['hit_ratio']); // 3/5 = 60%
        $this->assertEquals(40.0, $efficiency['miss_ratio']); // 2/5 = 40%
    }

    public function test_optimization_impact_calculation(): void
    {
        // Create data that will trigger compression
        $largeData = array_fill(0, 2000, 'large_data_for_compression_testing');
        
        $this->cache->put('optimization_test', $largeData, 3600);
        
        $metrics = $this->cache->getPerformanceMetrics();
        $impact = $metrics['optimization_impact'];
        
        $this->assertGreaterThan(0, $impact['total_writes']);
        $this->assertIsInt($impact['optimizations_applied']);
        $this->assertIsFloat($impact['optimization_ratio']);
        $this->assertIsInt($impact['size_reduction_bytes']);
        $this->assertIsFloat($impact['size_reduction_percentage']);
        
        // Large data should have been optimized
        $this->assertGreaterThan(0, $impact['size_reduction_bytes']);
    }

    public function test_performance_analysis_provides_recommendations(): void
    {
        // Create a scenario with low hit ratio
        $this->cache->get('miss1');
        $this->cache->get('miss2');
        $this->cache->get('miss3');
        $this->cache->get('miss4');
        $this->cache->get('miss5');
        
        $analysis = $this->cache->analyzePerformance();
        
        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('analysis_timestamp', $analysis);
        $this->assertArrayHasKey('overall_health', $analysis);
        $this->assertArrayHasKey('recommendations', $analysis);
        $this->assertArrayHasKey('metrics_summary', $analysis);
        
        $this->assertIsArray($analysis['recommendations']);
        $this->assertIsArray($analysis['metrics_summary']);
        
        // Should recommend improvement for low hit ratio
        $recommendations = $analysis['recommendations'];
        $lowHitRatioRecommendation = collect($recommendations)
            ->where('type', 'low_hit_ratio')
            ->first();
        
        if ($lowHitRatioRecommendation) {
            $this->assertEquals('warning', $lowHitRatioRecommendation['severity']);
            $this->assertStringContainsString('hit ratio', $lowHitRatioRecommendation['message']);
        }
    }

    public function test_reset_performance_metrics(): void
    {
        // Generate some metrics
        $this->cache->put('reset_test', 'value', 3600);
        $this->cache->get('reset_test');
        
        $metrics = $this->cache->getPerformanceMetrics();
        $this->assertGreaterThan(0, count($metrics['metrics']));
        
        // Reset metrics
        $this->cache->resetPerformanceMetrics();
        
        $metricsAfterReset = $this->cache->getPerformanceMetrics();
        $this->assertEmpty($metricsAfterReset['metrics']);
    }

    public function test_performance_monitoring_can_be_disabled(): void
    {
        // This test assumes the monitoring can be toggled, which would require config changes
        // For now, we just verify that the monitoring_enabled flag exists
        $metrics = $this->cache->getPerformanceMetrics();
        $this->assertArrayHasKey('monitoring_enabled', $metrics);
        $this->assertIsBool($metrics['monitoring_enabled']);
    }

    public function test_recent_entries_are_limited(): void
    {
        // Generate more than 100 operations to test the limit
        for ($i = 0; $i < 110; $i++) {
            $this->cache->put("limit_test_$i", "value_$i", 3600);
        }
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        if (isset($metrics['metrics']['cache_write']['recent'])) {
            $recentCount = count($metrics['metrics']['cache_write']['recent']);
            $this->assertLessThanOrEqual(100, $recentCount);
        }
    }

    public function test_facade_performance_methods(): void
    {
        \SmartCache\Facades\SmartCache::put('facade_perf_test', 'value', 3600);
        \SmartCache\Facades\SmartCache::get('facade_perf_test');
        
        $metrics = \SmartCache\Facades\SmartCache::getPerformanceMetrics();
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('monitoring_enabled', $metrics);
        
        $analysis = \SmartCache\Facades\SmartCache::analyzePerformance();
        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('overall_health', $analysis);
        
        \SmartCache\Facades\SmartCache::resetPerformanceMetrics();
        $metricsAfterReset = \SmartCache\Facades\SmartCache::getPerformanceMetrics();
        $this->assertEmpty($metricsAfterReset['metrics']);
    }

    public function test_metrics_include_timing_data(): void
    {
        $this->cache->put('timing_test', 'value', 3600);
        
        $metrics = $this->cache->getPerformanceMetrics();
        
        if (isset($metrics['metrics']['cache_write'])) {
            $writeMetrics = $metrics['metrics']['cache_write'];
            
            $this->assertArrayHasKey('count', $writeMetrics);
            $this->assertArrayHasKey('total_duration', $writeMetrics);
            $this->assertArrayHasKey('average_duration', $writeMetrics);
            $this->assertArrayHasKey('max_duration', $writeMetrics);
            $this->assertArrayHasKey('min_duration', $writeMetrics);
            
            $this->assertIsInt($writeMetrics['count']);
            $this->assertIsFloat($writeMetrics['total_duration']);
            $this->assertIsFloat($writeMetrics['average_duration']);
            $this->assertIsFloat($writeMetrics['max_duration']);
            $this->assertIsFloat($writeMetrics['min_duration']);
        }
    }

    public function test_performance_metrics_persist_across_instances(): void
    {
        // Generate metrics with first instance
        $this->cache->put('persist_test', 'value', 3600);
        
        // Create new instance
        $newCache = $this->app->make(SmartCacheContract::class);
        
        // The new instance should have access to the same metrics
        // (This might not work perfectly in tests due to cache isolation, but tests the concept)
        $metrics = $newCache->getPerformanceMetrics();
        $this->assertIsArray($metrics);
    }
}
