<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\Facades\SmartCache;

class BatchOperationsTest extends TestCase
{
    public function test_many_retrieves_multiple_keys()
    {
        SmartCache::put('key1', 'value1', 60);
        SmartCache::put('key2', 'value2', 60);
        SmartCache::put('key3', 'value3', 60);
        
        $values = SmartCache::many(['key1', 'key2', 'key3']);
        
        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], $values);
    }

    public function test_many_handles_missing_keys()
    {
        SmartCache::put('key1', 'value1', 60);
        
        $values = SmartCache::many(['key1', 'key2', 'key3']);
        
        $this->assertEquals('value1', $values['key1']);
        $this->assertNull($values['key2']);
        $this->assertNull($values['key3']);
    }

    public function test_put_many_stores_multiple_keys()
    {
        $result = SmartCache::putMany([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], 60);
        
        $this->assertTrue($result);
        $this->assertEquals('value1', SmartCache::get('key1'));
        $this->assertEquals('value2', SmartCache::get('key2'));
        $this->assertEquals('value3', SmartCache::get('key3'));
    }

    public function test_put_many_with_large_data()
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data["key_{$i}"] = array_fill(0, 1000, "value_{$i}");
        }
        
        $result = SmartCache::putMany($data, 60);
        
        $this->assertTrue($result);
        
        // Verify a few keys
        $this->assertCount(1000, SmartCache::get('key_0'));
        $this->assertCount(1000, SmartCache::get('key_50'));
        $this->assertCount(1000, SmartCache::get('key_99'));
    }

    public function test_delete_multiple_removes_multiple_keys()
    {
        SmartCache::put('key1', 'value1', 60);
        SmartCache::put('key2', 'value2', 60);
        SmartCache::put('key3', 'value3', 60);
        
        $result = SmartCache::deleteMultiple(['key1', 'key2']);
        
        $this->assertTrue($result);
        $this->assertNull(SmartCache::get('key1'));
        $this->assertNull(SmartCache::get('key2'));
        $this->assertEquals('value3', SmartCache::get('key3'));
    }

    public function test_delete_multiple_handles_nonexistent_keys()
    {
        SmartCache::put('key1', 'value1', 60);

        $result = SmartCache::deleteMultiple(['key1', 'key2', 'key3']);

        // Returns false if any key doesn't exist
        $this->assertFalse($result);
        // But key1 should still be deleted
        $this->assertNull(SmartCache::get('key1'));
    }

    public function test_many_with_optimized_data()
    {
        // Store large data that will be optimized
        $largeData1 = array_fill(0, 10000, 'data1');
        $largeData2 = array_fill(0, 10000, 'data2');
        $largeData3 = array_fill(0, 10000, 'data3');
        
        SmartCache::put('large1', $largeData1, 60);
        SmartCache::put('large2', $largeData2, 60);
        SmartCache::put('large3', $largeData3, 60);
        
        $values = SmartCache::many(['large1', 'large2', 'large3']);
        
        $this->assertCount(10000, $values['large1']);
        $this->assertCount(10000, $values['large2']);
        $this->assertCount(10000, $values['large3']);
    }

    public function test_put_many_with_different_ttls()
    {
        SmartCache::putMany([
            'key1' => 'value1',
            'key2' => 'value2',
        ], 60);
        
        SmartCache::putMany([
            'key3' => 'value3',
            'key4' => 'value4',
        ], 120);
        
        $this->assertEquals('value1', SmartCache::get('key1'));
        $this->assertEquals('value2', SmartCache::get('key2'));
        $this->assertEquals('value3', SmartCache::get('key3'));
        $this->assertEquals('value4', SmartCache::get('key4'));
    }

    public function test_many_operations_performance()
    {
        // Prepare data
        $keys = [];
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $key = "key_{$i}";
            $keys[] = $key;
            $data[$key] = "value_{$i}";
        }
        
        // Store data
        SmartCache::putMany($data, 60);
        
        // Measure many() performance
        $start = microtime(true);
        $values = SmartCache::many($keys);
        $duration = microtime(true) - $start;
        
        $this->assertCount(100, $values);
        // Should be reasonably fast (< 100ms)
        $this->assertLessThan(0.1, $duration);
    }

    public function test_batch_operations_with_tags()
    {
        SmartCache::tags(['users'])->putMany([
            'user1' => 'John',
            'user2' => 'Jane',
            'user3' => 'Bob',
        ], 60);
        
        $values = SmartCache::tags(['users'])->many(['user1', 'user2', 'user3']);
        
        $this->assertEquals([
            'user1' => 'John',
            'user2' => 'Jane',
            'user3' => 'Bob',
        ], $values);
        
        // Flush by tag
        SmartCache::tags(['users'])->flush();
        
        $values = SmartCache::many(['user1', 'user2', 'user3']);
        $this->assertNull($values['user1']);
        $this->assertNull($values['user2']);
        $this->assertNull($values['user3']);
    }
}

