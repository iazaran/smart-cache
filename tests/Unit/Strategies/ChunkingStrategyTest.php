<?php

namespace SmartCache\Tests\Unit\Strategies;

use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Tests\TestCase;
use Illuminate\Cache\Repository;
use Mockery;

class ChunkingStrategyTest extends TestCase
{
    protected ChunkingStrategy $strategy;
    protected Repository $mockCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ChunkingStrategy(2048, 100); // 2KB threshold, 100 items per chunk
        $this->mockCache = Mockery::mock(Repository::class);
    }

    public function test_get_identifier_returns_chunking()
    {
        $this->assertEquals('chunking', $this->strategy->getIdentifier());
    }

    public function test_should_apply_returns_true_for_large_array()
    {
        $largeArray = $this->createLargeTestData(150); // 150 items, should exceed both size and count thresholds
        $this->assertTrue($this->strategy->shouldApply($largeArray));
    }

    public function test_should_apply_returns_false_for_small_array()
    {
        $smallArray = range(1, 50); // Small array
        $this->assertFalse($this->strategy->shouldApply($smallArray));
    }

    public function test_should_apply_returns_false_for_non_array_types()
    {
        $this->assertFalse($this->strategy->shouldApply('string'));
        $this->assertFalse($this->strategy->shouldApply(123));
        $this->assertFalse($this->strategy->shouldApply(true));
        $this->assertFalse($this->strategy->shouldApply((object) ['key' => 'value']));
    }

    public function test_should_apply_respects_driver_configuration()
    {
        $largeArray = $this->createLargeTestData(150);
        
        $context = [
            'driver' => 'redis',
            'config' => [
                'drivers' => [
                    'redis' => [
                        'chunking' => false
                    ]
                ]
            ]
        ];

        $this->assertFalse($this->strategy->shouldApply($largeArray, $context));
    }

    public function test_should_apply_returns_false_if_array_count_below_chunk_size()
    {
        // Create array that's large in bytes but small in count
        $largeItemArray = [];
        for ($i = 0; $i < 50; $i++) { // Only 50 items (below chunk size of 100)
            $largeItemArray[$i] = str_repeat('large content ', 100); // Each item is large
        }

        // Should return false because count is below chunk size threshold
        $this->assertFalse($this->strategy->shouldApply($largeItemArray));
    }

    public function test_optimize_creates_chunks()
    {
        $largeArray = range(1, 250); // 250 items
        
        // Setup mock cache expectations
        $this->mockCache->shouldReceive('put')->times(3); // 250 items / 100 per chunk = 3 chunks
        
        $context = [
            'cache' => $this->mockCache,
            'key' => 'test-key',
            'ttl' => 3600,
            'driver' => 'array'
        ];

        $optimized = $this->strategy->optimize($largeArray, $context);

        $this->assertIsArray($optimized);
        $this->assertArrayHasKey('_sc_chunked', $optimized);
        $this->assertTrue($optimized['_sc_chunked']);
        $this->assertArrayHasKey('chunk_keys', $optimized);
        $this->assertArrayHasKey('total_items', $optimized);
        $this->assertArrayHasKey('is_collection', $optimized);
        $this->assertArrayHasKey('original_key', $optimized);
        
        $this->assertEquals(250, $optimized['total_items']);
        $this->assertFalse($optimized['is_collection']);
        $this->assertCount(3, $optimized['chunk_keys']); // 3 chunks
    }

    public function test_optimize_handles_collection_types()
    {
        // Skip if Collection class doesn't exist (in case illuminate/collections isn't available)
        if (!class_exists('\Illuminate\Support\Collection')) {
            $this->markTestSkipped('Illuminate\Support\Collection not available');
        }

        $collection = collect(range(1, 150));
        
        $this->mockCache->shouldReceive('put')->times(2); // 150 items / 100 per chunk = 2 chunks
        
        $context = [
            'cache' => $this->mockCache,
            'key' => 'collection-key',
            'ttl' => 3600
        ];

        $optimized = $this->strategy->optimize($collection, $context);

        $this->assertTrue($optimized['is_collection']);
        $this->assertCount(2, $optimized['chunk_keys']);
    }

    public function test_optimize_without_cache_in_context()
    {
        $largeArray = range(1, 150);
        
        $context = [
            'key' => 'test-key',
            'ttl' => 3600
        ];

        $optimized = $this->strategy->optimize($largeArray, $context);

        // Should still create the metadata structure even without cache
        $this->assertArrayHasKey('_sc_chunked', $optimized);
        $this->assertTrue($optimized['_sc_chunked']);
        $this->assertArrayHasKey('chunk_keys', $optimized);
    }

    public function test_restore_reconstructs_original_array()
    {
        $originalArray = range(1, 250);
        
        // Create mock chunk data
        $chunk1 = array_slice($originalArray, 0, 100, true);
        $chunk2 = array_slice($originalArray, 100, 100, true);
        $chunk3 = array_slice($originalArray, 200, 50, true);
        
        $chunkedMetadata = [
            '_sc_chunked' => true,
            'chunk_keys' => ['chunk_1', 'chunk_2', 'chunk_3'],
            'total_items' => 250,
            'is_collection' => false
        ];

        // Setup mock cache to return chunks
        $this->mockCache->shouldReceive('get')
            ->with('chunk_1')->andReturn($chunk1)
            ->shouldReceive('get')
            ->with('chunk_2')->andReturn($chunk2)
            ->shouldReceive('get')
            ->with('chunk_3')->andReturn($chunk3);

        $context = ['cache' => $this->mockCache];
        
        $restored = $this->strategy->restore($chunkedMetadata, $context);
        
        $this->assertEquals($originalArray, $restored);
    }

    public function test_restore_returns_collection_when_original_was_collection()
    {
        if (!class_exists('\Illuminate\Support\Collection')) {
            $this->markTestSkipped('Illuminate\Support\Collection not available');
        }

        $originalArray = range(1, 150);
        $chunk1 = array_slice($originalArray, 0, 100, true);
        $chunk2 = array_slice($originalArray, 100, 50, true);
        
        $chunkedMetadata = [
            '_sc_chunked' => true,
            'chunk_keys' => ['chunk_1', 'chunk_2'],
            'total_items' => 150,
            'is_collection' => true
        ];

        $this->mockCache->shouldReceive('get')
            ->with('chunk_1')->andReturn($chunk1)
            ->shouldReceive('get')
            ->with('chunk_2')->andReturn($chunk2);

        $context = ['cache' => $this->mockCache];
        
        $restored = $this->strategy->restore($chunkedMetadata, $context);
        
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $restored);
        $this->assertEquals($originalArray, $restored->all());
    }

    public function test_restore_returns_null_when_chunk_missing()
    {
        $chunkedMetadata = [
            '_sc_chunked' => true,
            'chunk_keys' => ['chunk_1', 'chunk_2'],
            'total_items' => 150,
            'is_collection' => false
        ];

        // Second chunk returns null (cache miss)
        $this->mockCache->shouldReceive('get')
            ->with('chunk_1')->andReturn(['some' => 'data'])
            ->shouldReceive('get')
            ->with('chunk_2')->andReturn(null);

        $context = ['cache' => $this->mockCache];
        
        $restored = $this->strategy->restore($chunkedMetadata, $context);
        
        $this->assertNull($restored);
    }

    public function test_restore_throws_exception_without_cache()
    {
        $chunkedMetadata = [
            '_sc_chunked' => true,
            'chunk_keys' => ['chunk_1'],
            'total_items' => 100,
            'is_collection' => false
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache repository is required to restore chunked data');
        
        $this->strategy->restore($chunkedMetadata, []); // No cache in context
    }

    public function test_restore_returns_unmodified_value_if_not_chunked()
    {
        $normalValue = ['not' => 'chunked'];
        $restored = $this->strategy->restore($normalValue, ['cache' => $this->mockCache]);
        $this->assertEquals($normalValue, $restored);
    }

    public function test_chunking_with_custom_chunk_size()
    {
        $strategy = new ChunkingStrategy(2048, 50); // 50 items per chunk
        $largeArray = range(1, 125); // 125 items
        
        $this->mockCache->shouldReceive('put')->times(3); // 125 / 50 = 3 chunks
        
        $context = [
            'cache' => $this->mockCache,
            'key' => 'custom-chunk-key',
            'ttl' => 3600
        ];

        $optimized = $strategy->optimize($largeArray, $context);
        
        $this->assertCount(3, $optimized['chunk_keys']);
    }

    public function test_chunking_preserves_array_keys()
    {
        $originalArray = [
            'first' => 'value1',
            'second' => 'value2',
            'third' => 'value3'
        ];
        
        // Add more items to exceed threshold
        for ($i = 0; $i < 200; $i++) {
            $originalArray["item_$i"] = "value_$i";
        }
        
        $this->mockCache->shouldReceive('put')->atLeast(1);
        
        // Also setup mock returns for restoration test
        $chunks = array_chunk($originalArray, 100, true);
        foreach ($chunks as $index => $chunk) {
            $this->mockCache->shouldReceive('get')
                ->with("_sc_chunk_test-key_$index")
                ->andReturn($chunk);
        }
        
        $context = [
            'cache' => $this->mockCache,
            'key' => 'test-key',
            'ttl' => 3600
        ];

        $optimized = $this->strategy->optimize($originalArray, $context);
        $restored = $this->strategy->restore($optimized, $context);
        
        $this->assertEquals($originalArray, $restored);
        $this->assertArrayHasKey('first', $restored);
        $this->assertArrayHasKey('second', $restored);
        $this->assertArrayHasKey('third', $restored);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
