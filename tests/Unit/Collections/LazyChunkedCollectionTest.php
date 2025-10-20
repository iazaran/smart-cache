<?php

namespace SmartCache\Tests\Unit\Collections;

use SmartCache\Tests\TestCase;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Facades\SmartCache;

class LazyChunkedCollectionTest extends TestCase
{
    public function test_lazy_collection_loads_chunks_on_demand()
    {
        $cache = $this->app['cache']->store();
        
        // Create chunks
        $cache->put('chunk_0', range(0, 99), 60);
        $cache->put('chunk_1', range(100, 199), 60);
        $cache->put('chunk_2', range(200, 299), 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1', 'chunk_2'],
            100,
            300
        );
        
        $this->assertCount(300, $collection);
    }

    public function test_lazy_collection_iteration()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', ['a', 'b', 'c'], 60);
        $cache->put('chunk_1', ['d', 'e', 'f'], 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            3,
            6
        );
        
        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }
        
        $this->assertEquals(['a', 'b', 'c', 'd', 'e', 'f'], $items);
    }

    public function test_lazy_collection_array_access()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', range(0, 99), 60);
        $cache->put('chunk_1', range(100, 199), 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            100,
            200
        );
        
        $this->assertEquals(0, $collection[0]);
        $this->assertEquals(50, $collection[50]);
        $this->assertEquals(100, $collection[100]);
        $this->assertEquals(150, $collection[150]);
    }

    public function test_lazy_collection_to_array()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', ['a', 'b'], 60);
        $cache->put('chunk_1', ['c', 'd'], 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            2,
            4
        );
        
        $array = $collection->toArray();
        
        $this->assertEquals(['a', 'b', 'c', 'd'], $array);
    }

    public function test_lazy_collection_slice()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', range(0, 99), 60);
        $cache->put('chunk_1', range(100, 199), 60);
        $cache->put('chunk_2', range(200, 299), 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1', 'chunk_2'],
            100,
            300
        );
        
        $slice = $collection->slice(50, 10);
        
        $this->assertEquals(range(50, 59), $slice);
    }

    public function test_lazy_collection_each()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', [1, 2, 3], 60);
        $cache->put('chunk_1', [4, 5, 6], 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            3,
            6
        );
        
        $sum = 0;
        $collection->each(function($item) use (&$sum) {
            $sum += $item;
        });
        
        $this->assertEquals(21, $sum); // 1+2+3+4+5+6
    }

    public function test_lazy_collection_filter()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', range(1, 10), 60);
        $cache->put('chunk_1', range(11, 20), 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            10,
            20
        );
        
        $filtered = $collection->filter(function($item) {
            return $item % 2 === 0; // Even numbers only
        });
        
        $this->assertEquals([2, 4, 6, 8, 10, 12, 14, 16, 18, 20], array_values($filtered));
    }

    public function test_lazy_collection_map()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', [1, 2, 3], 60);
        $cache->put('chunk_1', [4, 5, 6], 60);
        
        $collection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            3,
            6
        );
        
        $mapped = $collection->map(function($item) {
            return $item * 2;
        });
        
        $this->assertEquals([2, 4, 6, 8, 10, 12], $mapped);
    }

    public function test_lazy_collection_memory_efficiency()
    {
        $cache = $this->app['cache']->store();
        
        // Create many large chunks
        for ($i = 0; $i < 10; $i++) {
            $cache->put("chunk_{$i}", array_fill(0, 1000, "data_{$i}"), 60);
        }
        
        $chunkKeys = array_map(fn($i) => "chunk_{$i}", range(0, 9));
        
        $collection = new LazyChunkedCollection(
            $cache,
            $chunkKeys,
            1000,
            10000
        );
        
        // Access only a few items
        $item1 = $collection[0];
        $item2 = $collection[5000];
        
        // Get memory stats
        $stats = $collection->getMemoryStats();
        
        // Should have loaded only a few chunks, not all 10
        $this->assertLessThan(10, $stats['loaded_chunks']);
        $this->assertEquals(10, $stats['total_chunks']);
    }

    public function test_lazy_collection_with_smart_cache()
    {
        // This test requires lazy loading to be enabled at service provider level
        // For now, we'll test the LazyChunkedCollection directly
        $cache = $this->app['cache']->store();

        // Create chunks manually
        $largeData = array_fill(0, 10000, 'test_data');
        $chunks = array_chunk($largeData, 1000);
        $chunkKeys = [];

        foreach ($chunks as $index => $chunk) {
            $key = "large_key_chunk_{$index}";
            $cache->put($key, $chunk, 60);
            $chunkKeys[] = $key;
        }

        // Create lazy collection
        $retrieved = new LazyChunkedCollection($cache, $chunkKeys, 1000, 10000);

        // Should be able to access items
        $this->assertEquals('test_data', $retrieved[0]);
        $this->assertEquals('test_data', $retrieved[5000]);
        $this->assertCount(10000, $retrieved);
    }

    public function test_lazy_collection_to_collection()
    {
        $cache = $this->app['cache']->store();
        
        $cache->put('chunk_0', ['a', 'b'], 60);
        $cache->put('chunk_1', ['c', 'd'], 60);
        
        $lazyCollection = new LazyChunkedCollection(
            $cache,
            ['chunk_0', 'chunk_1'],
            2,
            4,
            true // is_collection
        );
        
        $collection = $lazyCollection->toCollection();
        
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $collection);
        $this->assertEquals(['a', 'b', 'c', 'd'], $collection->toArray());
    }

    public function test_lazy_collection_chunk_limit()
    {
        $cache = $this->app['cache']->store();
        
        // Create 10 chunks
        for ($i = 0; $i < 10; $i++) {
            $cache->put("chunk_{$i}", range($i * 100, ($i + 1) * 100 - 1), 60);
        }
        
        $chunkKeys = array_map(fn($i) => "chunk_{$i}", range(0, 9));
        
        $collection = new LazyChunkedCollection(
            $cache,
            $chunkKeys,
            100,
            1000,
            false,
            3 // Max 3 chunks in memory
        );
        
        // Access items from different chunks
        $collection[0];   // Chunk 0
        $collection[250]; // Chunk 2
        $collection[500]; // Chunk 5
        $collection[750]; // Chunk 7
        
        $stats = $collection->getMemoryStats();
        
        // Should keep only 3 chunks in memory
        $this->assertLessThanOrEqual(3, $stats['loaded_chunks']);
    }
}

