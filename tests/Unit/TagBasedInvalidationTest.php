<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;
use SmartCache\Events\TagFlushed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class TagBasedInvalidationTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCache::class);
    }

    public function test_can_cache_with_single_tag()
    {
        $key = 'tagged_key';
        $value = 'tagged_value';
        $tag = 'test_tag';

        // Cache with tag
        $result = $this->smartCache->tags($tag)->put($key, $value, 3600);
        $this->assertTrue($result);

        // Verify the value can be retrieved
        $this->assertEquals($value, $this->smartCache->get($key));

        // Verify tag association exists
        $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
        $this->assertContains($key, $taggedKeys);
    }

    public function test_can_cache_with_multiple_tags()
    {
        $key = 'multi_tagged_key';
        $value = 'multi_tagged_value';
        $tags = ['tag1', 'tag2', 'tag3'];

        // Cache with multiple tags
        $result = $this->smartCache->tags($tags)->put($key, $value, 3600);
        $this->assertTrue($result);

        // Verify the value can be retrieved
        $this->assertEquals($value, $this->smartCache->get($key));

        // Verify all tag associations exist
        foreach ($tags as $tag) {
            $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
            $this->assertContains($key, $taggedKeys);
        }
    }

    public function test_can_flush_single_tag()
    {
        $tag = 'flush_test';
        $keys = ['key1', 'key2', 'key3'];
        $values = ['value1', 'value2', 'value3'];

        // Cache multiple items with the same tag
        foreach ($keys as $i => $key) {
            $this->smartCache->tags($tag)->put($key, $values[$i], 3600);
        }

        // Verify all items are cached
        foreach ($keys as $i => $key) {
            $this->assertEquals($values[$i], $this->smartCache->get($key));
        }

        // Flush the tag
        $result = $this->smartCache->flushTags($tag);
        $this->assertTrue($result);

        // Verify all items are removed
        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }

        // Verify tag metadata is cleaned up
        $this->assertFalse(Cache::has("_sc_tag_{$tag}"));
    }

    public function test_can_flush_multiple_tags()
    {
        $tags = ['tag_a', 'tag_b'];
        
        // Cache items with different tags
        $this->smartCache->tags('tag_a')->put('key_a1', 'value_a1', 3600);
        $this->smartCache->tags('tag_a')->put('key_a2', 'value_a2', 3600);
        $this->smartCache->tags('tag_b')->put('key_b1', 'value_b1', 3600);
        $this->smartCache->tags(['tag_a', 'tag_b'])->put('key_ab', 'value_ab', 3600);

        // Verify all items are cached
        $this->assertTrue($this->smartCache->has('key_a1'));
        $this->assertTrue($this->smartCache->has('key_a2'));
        $this->assertTrue($this->smartCache->has('key_b1'));
        $this->assertTrue($this->smartCache->has('key_ab'));

        // Flush both tags
        $result = $this->smartCache->flushTags($tags);
        $this->assertTrue($result);

        // Verify all tagged items are removed
        $this->assertFalse($this->smartCache->has('key_a1'));
        $this->assertFalse($this->smartCache->has('key_a2'));
        $this->assertFalse($this->smartCache->has('key_b1'));
        $this->assertFalse($this->smartCache->has('key_ab'));
    }

    public function test_tags_work_with_optimization_strategies()
    {
        $tag = 'optimization_tag';
        $largeData = $this->createCompressibleData();
        $key = 'optimized_tagged_key';

        // Cache large data with tag (should trigger optimization)
        $this->smartCache->tags($tag)->put($key, $largeData, 3600);

        // Verify the data is optimized
        $rawCached = Cache::get($key);
        $this->assertValueIsCompressed($rawCached);

        // Verify we can still retrieve the original data
        $this->assertEquals($largeData, $this->smartCache->get($key));

        // Verify the key is tracked as managed
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($key, $managedKeys);

        // Verify tag association exists
        $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
        $this->assertContains($key, $taggedKeys);

        // Flush the tag and verify cleanup
        $this->smartCache->flushTags($tag);
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_tags_work_with_chunking_strategy()
    {
        $tag = 'chunking_tag';
        $largeArray = $this->createChunkableData();
        $key = 'chunked_tagged_key';

        // Cache large array with tag (should trigger chunking)
        $this->smartCache->tags($tag)->put($key, $largeArray, 3600);

        // Verify the data is chunked
        $rawCached = Cache::get($key);
        $this->assertValueIsChunked($rawCached);

        // Verify we can still retrieve the original data
        $this->assertEquals($largeArray, $this->smartCache->get($key));

        // Verify tag association exists
        $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
        $this->assertContains($key, $taggedKeys);

        // Flush the tag and verify all chunks are cleaned up
        $this->smartCache->flushTags($tag);
        $this->assertFalse($this->smartCache->has($key));
        
        // Verify individual chunks are also removed
        foreach ($rawCached['chunk_keys'] as $chunkKey) {
            $this->assertFalse(Cache::has($chunkKey));
        }
    }

    public function test_forever_method_works_with_tags()
    {
        $tag = 'forever_tag';
        $key = 'forever_tagged_key';
        $value = 'forever_value';

        // Cache forever with tag
        $result = $this->smartCache->tags($tag)->forever($key, $value);
        $this->assertTrue($result);

        // Verify the value is cached
        $this->assertEquals($value, $this->smartCache->get($key));

        // Verify tag association exists
        $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
        $this->assertContains($key, $taggedKeys);

        // Flush the tag
        $this->smartCache->flushTags($tag);
        $this->assertFalse($this->smartCache->has($key));
    }

    public function test_hierarchical_tag_structure()
    {
        // Create hierarchical tag structure: users -> user_123 -> profile
        $this->smartCache->tags(['users', 'user_123', 'profiles'])->put('user_123_profile', ['name' => 'John'], 3600);
        $this->smartCache->tags(['users', 'user_456', 'profiles'])->put('user_456_profile', ['name' => 'Jane'], 3600);
        $this->smartCache->tags(['users'])->put('users_count', 2, 3600);

        // Verify all data is cached
        $this->assertTrue($this->smartCache->has('user_123_profile'));
        $this->assertTrue($this->smartCache->has('user_456_profile'));
        $this->assertTrue($this->smartCache->has('users_count'));

        // Flush specific user
        $this->smartCache->flushTags('user_123');
        $this->assertFalse($this->smartCache->has('user_123_profile'));
        $this->assertTrue($this->smartCache->has('user_456_profile')); // Should still exist
        $this->assertTrue($this->smartCache->has('users_count')); // Should still exist

        // Flush all profiles
        $this->smartCache->flushTags('profiles');
        $this->assertFalse($this->smartCache->has('user_456_profile'));
        $this->assertTrue($this->smartCache->has('users_count')); // Should still exist

        // Flush all users
        $this->smartCache->flushTags('users');
        $this->assertFalse($this->smartCache->has('users_count'));
    }

    public function test_tags_are_cleared_after_use()
    {
        $key1 = 'test_key_1';
        $key2 = 'test_key_2';
        $tag = 'temp_tag';

        // Set tags and cache first item
        $this->smartCache->tags($tag)->put($key1, 'value1', 3600);

        // Cache second item without explicitly setting tags again
        // This should NOT be tagged
        $this->smartCache->put($key2, 'value2', 3600);

        // Verify only first key is tagged
        $taggedKeys = Cache::get("_sc_tag_{$tag}", []);
        $this->assertContains($key1, $taggedKeys);
        $this->assertNotContains($key2, $taggedKeys);

        // Flush tag should only remove first key
        $this->smartCache->flushTags($tag);
        $this->assertFalse($this->smartCache->has($key1));
        $this->assertTrue($this->smartCache->has($key2));
    }

    public function test_empty_tag_flush_does_nothing()
    {
        $key = 'untouched_key';
        $value = 'untouched_value';
        
        $this->smartCache->put($key, $value, 3600);
        
        // Flush non-existent tag
        $result = $this->smartCache->flushTags('non_existent_tag');
        $this->assertTrue($result);
        
        // Verify original data is untouched
        $this->assertEquals($value, $this->smartCache->get($key));
    }

    public function test_tag_flush_handles_missing_keys_gracefully()
    {
        $tag = 'broken_tag';
        $key = 'temp_key';
        
        // Manually create tag association
        Cache::forever("_sc_tag_{$tag}", [$key]);
        
        // Flush tag (key doesn't actually exist in cache)
        $result = $this->smartCache->flushTags($tag);
        $this->assertTrue($result);
        
        // Verify tag metadata is cleaned up even though key didn't exist
        $this->assertFalse(Cache::has("_sc_tag_{$tag}"));
    }

    public function test_tag_flush_works_with_active_namespace()
    {
        $this->smartCache->namespace('api')->tags('users')->put('user_1', 'namespaced_user', 3600);

        $this->assertTrue($this->smartCache->has('user_1'));

        $this->smartCache->flushTags('users');

        $this->assertFalse($this->smartCache->has('user_1'));

        $this->smartCache->withoutNamespace();
    }

    public function test_failed_add_does_not_leak_tags_to_next_write()
    {
        $this->smartCache->put('existing_key', 'original', 3600);

        $this->assertFalse(
            $this->smartCache->tags('failed_add_tag')->add('existing_key', 'replacement', 3600)
        );

        $this->smartCache->put('next_key', 'next_value', 3600);

        $taggedKeys = Cache::get('_sc_tag_failed_add_tag', []);

        $this->assertNotContains('next_key', $taggedKeys);
    }

    public function test_tag_index_prunes_dead_references_when_read()
    {
        $this->smartCache->put('live_key', 'live_value', 3600);
        Cache::forever('_sc_tag_prune_tag', ['missing_key', 'live_key']);

        $reflection = new \ReflectionClass($this->smartCache);
        $method = $reflection->getMethod('getKeysForTag');
        $method->setAccessible(true);

        $keys = $method->invoke($this->smartCache, 'prune_tag');

        $this->assertSame(['live_key'], $keys);
        $this->assertSame(['live_key'], Cache::get('_sc_tag_prune_tag'));
    }

    public function test_flush_tags_event_count_reflects_only_live_keys()
    {
        config(['smart-cache.events.enabled' => true]);
        Event::fake();

        $this->smartCache->put('live_payload', 'value', 3600);
        // Index references a key whose value no longer exists (expired/evicted).
        Cache::forever('_sc_tag_flush_count_tag', ['missing_payload', 'live_payload']);

        $this->smartCache->flushTags('flush_count_tag');

        $this->assertFalse($this->smartCache->has('live_payload'));
        $this->assertFalse(Cache::has('_sc_tag_flush_count_tag'));

        Event::assertDispatched(TagFlushed::class, function ($event) {
            return $event->tag === 'flush_count_tag' && $event->keyCount === 1;
        });
    }
}
