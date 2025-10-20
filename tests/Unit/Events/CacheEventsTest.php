<?php

namespace SmartCache\Tests\Unit\Events;

use SmartCache\Tests\TestCase;
use SmartCache\Facades\SmartCache;
use SmartCache\Events\CacheHit;
use SmartCache\Events\CacheMissed;
use SmartCache\Events\KeyWritten;
use SmartCache\Events\KeyForgotten;
use Illuminate\Support\Facades\Event;

class CacheEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable events for testing
        config(['smart-cache.events.enabled' => true]);
    }

    public function test_cache_hit_event_is_dispatched()
    {
        Event::fake();
        
        SmartCache::put('test_key', 'test_value', 60);
        SmartCache::get('test_key');
        
        Event::assertDispatched(CacheHit::class, function ($event) {
            return $event->key === 'test_key' && $event->value === 'test_value';
        });
    }

    public function test_cache_missed_event_is_dispatched()
    {
        Event::fake();
        
        SmartCache::get('nonexistent_key');
        
        Event::assertDispatched(CacheMissed::class, function ($event) {
            return $event->key === 'nonexistent_key';
        });
    }

    public function test_key_written_event_is_dispatched()
    {
        Event::fake();
        
        SmartCache::put('test_key', 'test_value', 60);
        
        Event::assertDispatched(KeyWritten::class, function ($event) {
            return $event->key === 'test_key' 
                && $event->value === 'test_value'
                && $event->seconds === 60;
        });
    }

    public function test_key_forgotten_event_is_dispatched()
    {
        Event::fake();
        
        SmartCache::put('test_key', 'test_value', 60);
        SmartCache::forget('test_key');
        
        Event::assertDispatched(KeyForgotten::class, function ($event) {
            return $event->key === 'test_key';
        });
    }

    public function test_events_include_tags()
    {
        Event::fake();
        
        SmartCache::tags(['users', 'posts'])->put('test_key', 'test_value', 60);
        SmartCache::tags(['users', 'posts'])->get('test_key');
        
        Event::assertDispatched(CacheHit::class, function ($event) {
            return $event->key === 'test_key' 
                && in_array('users', $event->tags)
                && in_array('posts', $event->tags);
        });
    }

    public function test_events_can_be_disabled()
    {
        config(['smart-cache.events.enabled' => false]);
        
        Event::fake();
        
        SmartCache::put('test_key', 'test_value', 60);
        SmartCache::get('test_key');
        
        Event::assertNotDispatched(CacheHit::class);
        Event::assertNotDispatched(KeyWritten::class);
    }

    public function test_specific_events_can_be_disabled()
    {
        config(['smart-cache.events.dispatch.cache_hit' => false]);
        
        Event::fake();
        
        SmartCache::put('test_key', 'test_value', 60);
        SmartCache::get('test_key');
        
        Event::assertNotDispatched(CacheHit::class);
        Event::assertDispatched(KeyWritten::class);
    }

    public function test_events_with_large_data()
    {
        Event::fake();
        
        $largeData = array_fill(0, 10000, 'test_data');
        
        SmartCache::put('large_key', $largeData, 60);
        SmartCache::get('large_key');
        
        Event::assertDispatched(KeyWritten::class, function ($event) use ($largeData) {
            return $event->key === 'large_key' && $event->value === $largeData;
        });
        
        Event::assertDispatched(CacheHit::class, function ($event) use ($largeData) {
            return $event->key === 'large_key' && $event->value === $largeData;
        });
    }

    public function test_can_listen_to_events()
    {
        $hitCount = 0;
        $missCount = 0;
        
        Event::listen(CacheHit::class, function ($event) use (&$hitCount) {
            $hitCount++;
        });
        
        Event::listen(CacheMissed::class, function ($event) use (&$missCount) {
            $missCount++;
        });
        
        SmartCache::put('test_key', 'test_value', 60);
        SmartCache::get('test_key'); // Hit
        SmartCache::get('nonexistent'); // Miss
        SmartCache::get('test_key'); // Hit
        
        $this->assertEquals(2, $hitCount);
        $this->assertEquals(1, $missCount);
    }

    public function test_events_with_remember()
    {
        Event::fake();
        
        SmartCache::remember('test_key', 60, function() {
            return 'computed_value';
        });
        
        // Should dispatch KeyWritten (first time)
        Event::assertDispatched(KeyWritten::class);
        
        Event::fake(); // Reset
        
        SmartCache::remember('test_key', 60, function() {
            return 'computed_value';
        });
        
        // Should dispatch CacheHit (second time)
        Event::assertDispatched(CacheHit::class);
    }

    public function test_events_with_many_operations()
    {
        Event::fake();
        
        SmartCache::putMany([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], 60);
        
        // Should dispatch KeyWritten for each key
        Event::assertDispatched(KeyWritten::class, 3);
    }

    public function test_events_with_delete_multiple()
    {
        Event::fake();
        
        SmartCache::put('key1', 'value1', 60);
        SmartCache::put('key2', 'value2', 60);
        SmartCache::put('key3', 'value3', 60);
        
        Event::fake(); // Reset
        
        SmartCache::deleteMultiple(['key1', 'key2', 'key3']);
        
        // Should dispatch KeyForgotten for each key
        Event::assertDispatched(KeyForgotten::class, 3);
    }
}

