<?php

namespace SmartCache\Tests\Unit\Services;

use SmartCache\Tests\TestCase;
use SmartCache\Services\SmartChunkSizeCalculator;

class SmartChunkSizeCalculatorTest extends TestCase
{
    public function test_calculates_optimal_size_for_small_data()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $smallData = array_fill(0, 100, 'test');
        $optimalSize = $calculator->calculateOptimalSize($smallData);
        
        // Small data should not be chunked
        $this->assertEquals(100, $optimalSize);
    }

    public function test_calculates_optimal_size_for_large_data()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $largeData = array_fill(0, 100000, str_repeat('test', 100));
        $optimalSize = $calculator->calculateOptimalSize($largeData);
        
        // Should return a reasonable chunk size
        $this->assertGreaterThan(100, $optimalSize);
        $this->assertLessThan(100000, $optimalSize);
    }

    public function test_respects_driver_limits()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $data = array_fill(0, 10000, str_repeat('x', 1000)); // ~10MB total
        
        // Memcached has 1MB limit
        $memcachedSize = $calculator->calculateOptimalSize($data, 'memcached');
        
        // Redis has 512MB limit
        $redisSize = $calculator->calculateOptimalSize($data, 'redis');
        
        // Redis should allow larger chunks
        $this->assertGreaterThan($memcachedSize, $redisSize);
    }

    public function test_get_driver_limit()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $this->assertEquals(512 * 1024 * 1024, $calculator->getDriverLimit('redis'));
        $this->assertEquals(1024 * 1024, $calculator->getDriverLimit('memcached'));
        $this->assertEquals(16 * 1024 * 1024, $calculator->getDriverLimit('database'));
        $this->assertEquals(400 * 1024, $calculator->getDriverLimit('dynamodb'));
    }

    public function test_get_driver_limit_returns_default_for_unknown()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $limit = $calculator->getDriverLimit('unknown_driver');
        
        $this->assertEquals(1024 * 1024, $limit); // 1MB default
    }

    public function test_calculate_chunk_count()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $this->assertEquals(10, $calculator->calculateChunkCount(1000, 100));
        $this->assertEquals(5, $calculator->calculateChunkCount(1000, 200));
        $this->assertEquals(1, $calculator->calculateChunkCount(100, 1000));
    }

    public function test_get_recommendations()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $data = array_fill(0, 10000, str_repeat('test', 100));
        $recommendations = $calculator->getRecommendations($data, 'redis');
        
        $this->assertArrayHasKey('total_items', $recommendations);
        $this->assertArrayHasKey('total_size', $recommendations);
        $this->assertArrayHasKey('avg_item_size', $recommendations);
        $this->assertArrayHasKey('optimal_chunk_size', $recommendations);
        $this->assertArrayHasKey('chunk_count', $recommendations);
        $this->assertArrayHasKey('driver', $recommendations);
        $this->assertArrayHasKey('should_chunk', $recommendations);
        
        $this->assertEquals(10000, $recommendations['total_items']);
        $this->assertEquals('redis', $recommendations['driver']);
    }

    public function test_should_chunk_recommendation()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        // Small data
        $smallData = array_fill(0, 100, 'test');
        $smallRec = $calculator->getRecommendations($smallData);
        $this->assertFalse($smallRec['should_chunk']);
        
        // Large data
        $largeData = array_fill(0, 10000, str_repeat('test', 100));
        $largeRec = $calculator->getRecommendations($largeData);
        $this->assertTrue($largeRec['should_chunk']);
    }

    public function test_is_chunk_size_safe()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        // Safe chunk size for memcached
        $this->assertTrue($calculator->isChunkSizeSafe(100, 1000, 'memcached'));
        
        // Unsafe chunk size for memcached (would exceed 1MB limit)
        $this->assertFalse($calculator->isChunkSizeSafe(10000, 1000, 'memcached'));
    }

    public function test_set_custom_driver_limit()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $calculator->setDriverLimit('custom_driver', 5 * 1024 * 1024); // 5MB
        
        $this->assertEquals(5 * 1024 * 1024, $calculator->getDriverLimit('custom_driver'));
    }

    public function test_get_all_driver_limits()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $limits = $calculator->getDriverLimits();
        
        $this->assertIsArray($limits);
        $this->assertArrayHasKey('redis', $limits);
        $this->assertArrayHasKey('memcached', $limits);
        $this->assertArrayHasKey('database', $limits);
    }

    public function test_optimal_size_for_very_large_dataset()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        // 100MB dataset
        $largeData = array_fill(0, 100000, str_repeat('x', 1000));
        $optimalSize = $calculator->calculateOptimalSize($largeData, 'redis');
        
        // Should use smaller chunks for very large datasets
        $this->assertLessThanOrEqual(500, $optimalSize);
    }

    public function test_optimal_size_for_medium_dataset()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        // 500KB dataset
        $mediumData = array_fill(0, 5000, str_repeat('x', 100));
        $optimalSize = $calculator->calculateOptimalSize($mediumData, 'redis');
        
        // Should use larger chunks for medium datasets
        $this->assertGreaterThan(500, $optimalSize);
        $this->assertLessThanOrEqual(5000, $optimalSize);
    }

    public function test_handles_empty_array()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $emptyData = [];
        $optimalSize = $calculator->calculateOptimalSize($emptyData);
        
        $this->assertEquals(0, $optimalSize);
    }

    public function test_minimum_chunk_size()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        // Even with very small items, should maintain minimum chunk size
        $data = array_fill(0, 10000, 'x');
        $optimalSize = $calculator->calculateOptimalSize($data, 'memcached');
        
        $this->assertGreaterThanOrEqual(100, $optimalSize);
    }

    public function test_chunk_size_with_different_default()
    {
        $calculator = new SmartChunkSizeCalculator();
        
        $data = array_fill(0, 10000, 'test');
        
        $size1 = $calculator->calculateOptimalSize($data, null, 1000);
        $size2 = $calculator->calculateOptimalSize($data, null, 5000);
        
        // Different defaults may affect the result
        $this->assertIsInt($size1);
        $this->assertIsInt($size2);
    }
}

