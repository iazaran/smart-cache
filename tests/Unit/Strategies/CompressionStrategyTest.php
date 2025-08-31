<?php

namespace SmartCache\Tests\Unit\Strategies;

use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Tests\TestCase;

class CompressionStrategyTest extends TestCase
{
    protected CompressionStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CompressionStrategy(1024, 6); // 1KB threshold, level 6
    }

    public function test_get_identifier_returns_compression()
    {
        $this->assertEquals('compression', $this->strategy->getIdentifier());
    }

    public function test_should_apply_returns_true_for_large_string()
    {
        $largeString = str_repeat('a', 2000); // 2KB string
        $this->assertTrue($this->strategy->shouldApply($largeString));
    }

    public function test_should_apply_returns_false_for_small_string()
    {
        $smallString = str_repeat('a', 500); // 500 bytes string
        $this->assertFalse($this->strategy->shouldApply($smallString));
    }

    public function test_should_apply_returns_true_for_large_array()
    {
        $largeArray = $this->createLargeTestData(50); // Creates large array
        $this->assertTrue($this->strategy->shouldApply($largeArray));
    }

    public function test_should_apply_returns_false_for_small_array()
    {
        $smallArray = ['key' => 'value'];
        $this->assertFalse($this->strategy->shouldApply($smallArray));
    }

    public function test_should_apply_returns_true_for_large_object()
    {
        $largeObject = (object) $this->createLargeTestData(50);
        $this->assertTrue($this->strategy->shouldApply($largeObject));
    }

    public function test_should_apply_returns_false_for_non_compressible_types()
    {
        $this->assertFalse($this->strategy->shouldApply(123));
        $this->assertFalse($this->strategy->shouldApply(123.45));
        $this->assertFalse($this->strategy->shouldApply(true));
        $this->assertFalse($this->strategy->shouldApply(null));
    }

    public function test_should_apply_respects_driver_configuration()
    {
        $largeString = str_repeat('a', 2000);
        
        $context = [
            'driver' => 'redis',
            'config' => [
                'drivers' => [
                    'redis' => [
                        'compression' => false
                    ]
                ]
            ]
        ];

        $this->assertFalse($this->strategy->shouldApply($largeString, $context));
    }

    public function test_optimize_compresses_string_data()
    {
        $originalString = str_repeat('Hello World! ', 200); // Repetitive string compresses well
        $optimized = $this->strategy->optimize($originalString);

        $this->assertIsArray($optimized);
        $this->assertArrayHasKey('_sc_compressed', $optimized);
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertArrayHasKey('data', $optimized);
        $this->assertArrayHasKey('is_string', $optimized);
        $this->assertTrue($optimized['is_string']);
        
        // Verify the compressed data is base64 encoded
        $this->assertNotFalse(base64_decode($optimized['data'], true));
    }

    public function test_optimize_compresses_array_data()
    {
        $originalArray = $this->createLargeTestData(50);
        $optimized = $this->strategy->optimize($originalArray);

        $this->assertIsArray($optimized);
        $this->assertArrayHasKey('_sc_compressed', $optimized);
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertArrayHasKey('data', $optimized);
        $this->assertArrayHasKey('is_string', $optimized);
        $this->assertFalse($optimized['is_string']);
    }

    public function test_optimize_compresses_object_data()
    {
        $originalObject = (object) $this->createLargeTestData(50);
        $optimized = $this->strategy->optimize($originalObject);

        $this->assertIsArray($optimized);
        $this->assertArrayHasKey('_sc_compressed', $optimized);
        $this->assertTrue($optimized['_sc_compressed']);
        $this->assertArrayHasKey('is_string', $optimized);
        $this->assertFalse($optimized['is_string']);
    }

    public function test_restore_returns_original_string()
    {
        $originalString = str_repeat('Hello World! ', 200);
        $optimized = $this->strategy->optimize($originalString);
        $restored = $this->strategy->restore($optimized);

        $this->assertEquals($originalString, $restored);
    }

    public function test_restore_returns_original_array()
    {
        $originalArray = $this->createLargeTestData(50);
        $optimized = $this->strategy->optimize($originalArray);
        $restored = $this->strategy->restore($optimized);

        $this->assertEquals($originalArray, $restored);
    }

    public function test_restore_returns_original_object()
    {
        $originalObject = (object) $this->createLargeTestData(50);
        $optimized = $this->strategy->optimize($originalObject);
        $restored = $this->strategy->restore($optimized);

        $this->assertEquals($originalObject, $restored);
    }

    public function test_restore_returns_unmodified_value_if_not_compressed()
    {
        $normalValue = 'not compressed';
        $restored = $this->strategy->restore($normalValue);
        $this->assertEquals($normalValue, $restored);

        $normalArray = ['not' => 'compressed'];
        $restored = $this->strategy->restore($normalArray);
        $this->assertEquals($normalArray, $restored);
    }

    public function test_restore_returns_unmodified_value_if_invalid_compressed_format()
    {
        $invalidCompressed = [
            '_sc_compressed' => false, // Wrong marker
            'data' => 'some-data',
            'is_string' => true
        ];

        $restored = $this->strategy->restore($invalidCompressed);
        $this->assertEquals($invalidCompressed, $restored);
    }

    public function test_compression_reduces_size()
    {
        $repetitiveString = str_repeat('This is a test string that should compress very well. ', 100);
        $originalSize = strlen($repetitiveString);
        
        $optimized = $this->strategy->optimize($repetitiveString);
        $compressedSize = strlen($optimized['data']);
        
        // Base64 adds some overhead, but the compressed data should still be significantly smaller
        // than the original for repetitive data
        $this->assertLessThan($originalSize, $compressedSize);
    }

    public function test_compression_with_different_levels()
    {
        $data = str_repeat('Test data for compression level testing. ', 100);
        
        $strategy1 = new CompressionStrategy(1024, 1); // Low compression
        $strategy9 = new CompressionStrategy(1024, 9); // High compression
        
        $optimized1 = $strategy1->optimize($data);
        $optimized9 = $strategy9->optimize($data);
        
        // Higher compression level should produce smaller result
        $size1 = strlen($optimized1['data']);
        $size9 = strlen($optimized9['data']);
        
        $this->assertLessThanOrEqual($size1, $size9);
        
        // Both should restore to the same original data
        $restored1 = $strategy1->restore($optimized1);
        $restored9 = $strategy9->restore($optimized9);
        
        $this->assertEquals($data, $restored1);
        $this->assertEquals($data, $restored9);
    }

    public function test_compression_with_custom_threshold()
    {
        $strategy = new CompressionStrategy(5000, 6); // 5KB threshold
        
        $smallData = str_repeat('a', 2000); // 2KB
        $largeData = str_repeat('a', 6000); // 6KB
        
        $this->assertFalse($strategy->shouldApply($smallData));
        $this->assertTrue($strategy->shouldApply($largeData));
    }

    public function test_round_trip_compression_preserves_data_integrity()
    {
        $testCases = [
            'Simple string',
            str_repeat('Repetitive content ', 100),
            ['array' => 'data', 'nested' => ['deep' => 'value']],
            $this->createLargeTestData(30),
            (object) ['property' => 'value', 'nested' => (object) ['deep' => 'data']]
        ];

        foreach ($testCases as $testCase) {
            $optimized = $this->strategy->optimize($testCase);
            $restored = $this->strategy->restore($optimized);
            
            $this->assertEquals($testCase, $restored, 
                'Round-trip compression should preserve data integrity for: ' . gettype($testCase));
        }
    }
}
