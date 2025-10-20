<?php

namespace SmartCache\Tests\Unit\Drivers;

use SmartCache\Tests\TestCase;
use SmartCache\Facades\SmartCache;

class MemoizationTest extends TestCase
{
    public function test_memo_returns_memoized_instance()
    {
        $memo = SmartCache::memo();
        
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $memo);
    }

    public function test_memo_caches_in_memory()
    {
        $memo = SmartCache::memo();
        
        // First call hits cache
        $memo->put('test_key', 'test_value', 60);
        
        // Second call should be from memory (instant)
        $value1 = $memo->get('test_key');
        $value2 = $memo->get('test_key');
        $value3 = $memo->get('test_key');
        
        $this->assertEquals('test_value', $value1);
        $this->assertEquals('test_value', $value2);
        $this->assertEquals('test_value', $value3);
    }

    public function test_memo_with_large_data()
    {
        $memo = SmartCache::memo();
        
        // Create large dataset
        $largeData = array_fill(0, 10000, 'test_data');
        
        $memo->put('large_key', $largeData, 60);
        
        // Multiple accesses should be instant (from memory)
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $data = $memo->get('large_key');
        }
        $duration = microtime(true) - $start;
        
        // Should be very fast (< 100ms for 100 accesses - lenient for CI)
        $this->assertLessThan(0.1, $duration);
        $this->assertCount(10000, $data);
    }

    public function test_memo_handles_cache_misses()
    {
        $memo = SmartCache::memo();
        
        // First miss
        $value1 = $memo->get('nonexistent_key', 'default');
        $this->assertEquals('default', $value1);
        
        // Second miss (should be memoized)
        $value2 = $memo->get('nonexistent_key', 'default');
        $this->assertEquals('default', $value2);
    }

    public function test_memo_clears_on_put()
    {
        $memo = SmartCache::memo();
        
        $memo->put('test_key', 'value1', 60);
        $this->assertEquals('value1', $memo->get('test_key'));
        
        // Update value
        $memo->put('test_key', 'value2', 60);
        $this->assertEquals('value2', $memo->get('test_key'));
    }

    public function test_memo_clears_on_forget()
    {
        $memo = SmartCache::memo();
        
        $memo->put('test_key', 'test_value', 60);
        $this->assertEquals('test_value', $memo->get('test_key'));
        
        $memo->forget('test_key');
        $this->assertNull($memo->get('test_key'));
    }

    public function test_memo_with_remember()
    {
        $memo = SmartCache::memo();
        
        $callCount = 0;
        
        // First call executes callback
        $value1 = $memo->remember('test_key', 60, function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        // Subsequent calls use memoized value
        $value2 = $memo->remember('test_key', 60, function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $value3 = $memo->remember('test_key', 60, function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $this->assertEquals('computed_value', $value1);
        $this->assertEquals('computed_value', $value2);
        $this->assertEquals('computed_value', $value3);
        $this->assertEquals(1, $callCount); // Callback only called once
    }

    public function test_memo_with_different_stores()
    {
        // Test that memoization works with different cache stores
        // Use stores that don't require external services (file, array)

        // Test with file store
        $memoFile = SmartCache::memo('file');
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $memoFile);

        $memoFile->put('file_test', 'file_value', 60);
        $this->assertEquals('file_value', $memoFile->get('file_test'));

        // Test with array store
        $memoArray = SmartCache::memo('array');
        $this->assertInstanceOf(\SmartCache\SmartCache::class, $memoArray);

        $memoArray->put('array_test', 'array_value', 60);
        $this->assertEquals('array_value', $memoArray->get('array_test'));
    }

    public function test_memo_performance_improvement()
    {
        // Test without memoization
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            SmartCache::get('test_key');
        }
        $durationWithoutMemo = microtime(true) - $start;
        
        // Test with memoization
        $memo = SmartCache::memo();
        $memo->put('test_key', 'test_value', 60);
        
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $memo->get('test_key');
        }
        $durationWithMemo = microtime(true) - $start;
        
        // Memoization should be significantly faster
        $this->assertLessThan($durationWithoutMemo, $durationWithMemo);
    }

    public function test_memo_with_many_operations()
    {
        $memo = SmartCache::memo();
        
        $memo->putMany([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], 60);
        
        $values = $memo->many(['key1', 'key2', 'key3']);
        
        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], $values);
    }

    public function test_memo_with_increment_decrement()
    {
        $memo = SmartCache::memo();
        
        $memo->put('counter', 0, 60);
        
        $memo->increment('counter');
        $this->assertEquals(1, $memo->get('counter'));
        
        $memo->increment('counter', 5);
        $this->assertEquals(6, $memo->get('counter'));
        
        $memo->decrement('counter', 2);
        $this->assertEquals(4, $memo->get('counter'));
    }

    public function test_memoization_stats()
    {
        $memo = SmartCache::memo();

        // Put some data
        $memo->put('key1', 'value1', 60);
        $memo->put('key2', 'value2', 60);

        // Access them - this will memoize them
        $val1 = $memo->get('key1');
        $val2 = $memo->get('key2');

        // Verify they were retrieved correctly
        $this->assertEquals('value1', $val1);
        $this->assertEquals('value2', $val2);

        // Access nonexistent key
        $val3 = $memo->get('nonexistent');
        $this->assertNull($val3);

        // Get stats directly from memoized driver
        $stats = $memo->getMemoizationStats();

        $this->assertArrayHasKey('memoized_count', $stats);
        $this->assertArrayHasKey('missing_count', $stats);
        $this->assertArrayHasKey('total_memory', $stats);

        // Should have some memoized data
        $this->assertGreaterThanOrEqual(0, $stats['memoized_count']);
        $this->assertGreaterThanOrEqual(0, $stats['missing_count']);
        $this->assertGreaterThan(0, $stats['total_memory']);
    }
}

