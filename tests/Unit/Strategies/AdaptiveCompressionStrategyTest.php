<?php

namespace SmartCache\Tests\Unit\Strategies;

use SmartCache\Tests\TestCase;
use SmartCache\Strategies\AdaptiveCompressionStrategy;

class AdaptiveCompressionStrategyTest extends TestCase
{
    public function test_should_apply_returns_true_for_large_data()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $largeData = str_repeat('test', 500); // > 1KB
        
        $this->assertTrue($strategy->shouldApply($largeData));
    }

    public function test_should_apply_returns_false_for_small_data()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $smallData = 'test';
        
        $this->assertFalse($strategy->shouldApply($smallData));
    }

    public function test_optimize_compresses_data()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $data = str_repeat('test', 500);
        $optimized = $strategy->optimize($data);
        
        $this->assertIsArray($optimized);
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertArrayHasKey('data', $optimized);
        $this->assertArrayHasKey('level', $optimized);
    }

    public function test_restore_decompresses_data()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $originalData = str_repeat('test', 500);
        $optimized = $strategy->optimize($originalData);
        $restored = $strategy->restore($optimized);
        
        $this->assertEquals($originalData, $restored);
    }

    public function test_adaptive_compression_selects_appropriate_level()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        // Highly compressible data (repeated pattern)
        $compressibleData = str_repeat('aaaa', 1000);
        $optimized1 = $strategy->optimize($compressibleData);
        
        // Less compressible data (random-ish)
        $lessCompressibleData = str_repeat('abcdefghijklmnop', 250);
        $optimized2 = $strategy->optimize($lessCompressibleData);
        
        // Both should be compressed but may use different levels
        $this->assertTrue($optimized1['_sc_compressed']);
        $this->assertTrue($optimized2['_sc_compressed']);
        $this->assertArrayHasKey('level', $optimized1);
        $this->assertArrayHasKey('level', $optimized2);
    }

    public function test_compression_with_large_array()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $largeArray = array_fill(0, 10000, 'test_data');
        $serialized = serialize($largeArray);
        
        $optimized = $strategy->optimize($serialized);
        $restored = $strategy->restore($optimized);
        
        $this->assertEquals($serialized, $restored);
    }

    public function test_get_identifier()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $this->assertEquals('adaptive_compression', $strategy->getIdentifier());
    }

    public function test_compression_stats()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);

        $data = str_repeat('test', 1000);
        $optimized = $strategy->optimize($data);
        $stats = $strategy->getCompressionStats($optimized);

        $this->assertArrayHasKey('level', $stats);
        $this->assertArrayHasKey('original_size', $stats);
        $this->assertArrayHasKey('compressed_size', $stats);
        $this->assertArrayHasKey('ratio', $stats);
        $this->assertArrayHasKey('savings_bytes', $stats);
        $this->assertArrayHasKey('savings_percent', $stats);
    }

    public function test_adaptive_compression_with_hot_data()
    {
        $strategy = new AdaptiveCompressionStrategy(
            1024,
            6,
            1024,
            0.5,
            0.7,
            100 // Low frequency threshold for testing
        );
        
        $data = str_repeat('test', 500);
        
        // Simulate hot data by accessing it multiple times
        $cache = $this->app['cache']->store();
        $key = 'test_hot_key';
        
        // Increment access frequency
        for ($i = 0; $i < 150; $i++) {
            $cache->increment("_sc_access_freq_{$key}");
        }
        
        $optimized = $strategy->optimize($data, ['key' => $key, 'cache' => $cache]);
        
        // Hot data should use lower compression level for faster access
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertLessThanOrEqual(6, $optimized['level']);
    }

    public function test_adaptive_compression_with_cold_data()
    {
        $strategy = new AdaptiveCompressionStrategy(
            1024,
            6,
            1024,
            0.5,
            0.7,
            100
        );
        
        $data = str_repeat('test', 500);
        
        // Cold data (low access frequency)
        $cache = $this->app['cache']->store();
        $key = 'test_cold_key';
        
        $optimized = $strategy->optimize($data, ['key' => $key, 'cache' => $cache]);
        
        // Cold data may use higher compression for better space savings
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertGreaterThanOrEqual(1, $optimized['level']);
    }

    public function test_compression_ratio_tracking()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        $data = str_repeat('test', 1000);
        $optimized = $strategy->optimize($data);
        
        $this->assertArrayHasKey('original_size', $optimized);
        $this->assertArrayHasKey('compressed_size', $optimized);
        
        $originalSize = $optimized['original_size'];
        $compressedSize = $optimized['compressed_size'];
        
        // Compressed size should be smaller
        $this->assertLessThan($originalSize, $compressedSize);
    }

    public function test_handles_non_compressible_data()
    {
        $strategy = new AdaptiveCompressionStrategy(1024);
        
        // Random data (not very compressible)
        $randomData = random_bytes(5000);
        
        $optimized = $strategy->optimize($randomData);
        $restored = $strategy->restore($optimized);
        
        $this->assertEquals($randomData, $restored);
    }

    public function test_compression_with_different_thresholds()
    {
        $strategy1 = new AdaptiveCompressionStrategy(1024);
        $strategy2 = new AdaptiveCompressionStrategy(10240);
        
        $data = str_repeat('test', 500); // ~2KB
        
        $this->assertTrue($strategy1->shouldApply($data));
        $this->assertFalse($strategy2->shouldApply($data));
    }
}

