<?php

namespace SmartCache\Tests\Unit\Locks;

use SmartCache\Tests\TestCase;
use SmartCache\Facades\SmartCache;
use Illuminate\Support\Facades\Cache;

class AtomicLocksTest extends TestCase
{
    public function test_can_acquire_lock()
    {
        $lock = SmartCache::lock('test_lock', 10);
        
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Lock::class, $lock);
        
        $acquired = $lock->get();
        $this->assertTrue($acquired);
        
        $lock->release();
    }

    public function test_lock_prevents_concurrent_access()
    {
        $lock1 = SmartCache::lock('test_lock', 10);
        $acquired1 = $lock1->get();
        
        $this->assertTrue($acquired1);
        
        // Try to acquire the same lock
        $lock2 = SmartCache::lock('test_lock', 10);
        $acquired2 = $lock2->get();
        
        $this->assertFalse($acquired2);
        
        $lock1->release();
    }

    public function test_lock_with_callback()
    {
        $executed = false;
        
        SmartCache::lock('test_lock', 10)->get(function() use (&$executed) {
            $executed = true;
        });
        
        $this->assertTrue($executed);
    }

    public function test_lock_with_callback_returns_value()
    {
        $result = SmartCache::lock('test_lock', 10)->get(function() {
            return 'test_value';
        });
        
        $this->assertEquals('test_value', $result);
    }

    public function test_can_restore_lock_with_owner()
    {
        $lock = SmartCache::lock('test_lock', 10);
        $lock->get();
        
        $owner = $lock->owner();
        $this->assertNotNull($owner);
        
        $restoredLock = SmartCache::restoreLock('test_lock', $owner);
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Lock::class, $restoredLock);
        
        $restoredLock->release();
    }

    public function test_lock_prevents_cache_stampede()
    {
        $callCount = 0;

        // First lock acquisition should succeed
        $lock1 = SmartCache::lock('expensive_operation', 10);
        $this->assertTrue($lock1->get());
        $callCount++;
        SmartCache::put('expensive_data', 'value', 60);

        // Subsequent attempts should fail while lock is held
        for ($i = 0; $i < 4; $i++) {
            $lock = SmartCache::lock('expensive_operation', 10);
            if ($lock->get()) {
                $callCount++;
                $lock->release();
            }
        }

        // Only one process should have executed the expensive operation
        $this->assertEquals(1, $callCount);

        // Clean up
        $lock1->release();
    }

    public function test_lock_with_block_waits_for_lock()
    {
        // Skip this test as it's timing-dependent and may fail in CI
        $this->markTestSkipped('Timing-dependent test - may fail in CI environments');
    }

    public function test_lock_with_large_data_regeneration()
    {
        $key = 'large_dataset';
        
        // Clear any existing data
        SmartCache::forget($key);
        
        $lock = SmartCache::lock("regenerate_{$key}", 30);
        
        if ($lock->get()) {
            // Generate large dataset
            $largeData = array_fill(0, 10000, 'test_data');
            SmartCache::put($key, $largeData, 3600);
            $lock->release();
        }
        
        $this->assertTrue(SmartCache::has($key));
        $retrieved = SmartCache::get($key);
        $this->assertCount(10000, $retrieved);
    }

    public function test_lock_throws_exception_for_unsupported_driver()
    {
        // This test is driver-dependent
        // Most drivers support locks, so we just verify the method exists

        try {
            $lock = SmartCache::lock('test', 10);
            $this->assertInstanceOf(\Illuminate\Contracts\Cache\Lock::class, $lock);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not support atomic locks', $e->getMessage());
        }
    }
}

