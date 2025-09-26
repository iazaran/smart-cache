<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\Contracts\SmartCache;
use Illuminate\Support\Facades\Cache;

class DependencyTrackingTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCache::class);
    }

    public function test_can_create_simple_dependency()
    {
        $parentKey = 'parent_key';
        $childKey = 'child_key';
        
        // Create dependency
        $this->smartCache->dependsOn($childKey, $parentKey);
        
        // Cache both items
        $this->smartCache->put($parentKey, 'parent_value', 3600);
        $this->smartCache->put($childKey, 'child_value', 3600);
        
        // Verify both are cached
        $this->assertTrue($this->smartCache->has($parentKey));
        $this->assertTrue($this->smartCache->has($childKey));
        
        // Invalidate parent - should cascade to child
        $result = $this->smartCache->invalidate($parentKey);
        $this->assertTrue($result);
        
        // Verify both are removed
        $this->assertFalse($this->smartCache->has($parentKey));
        $this->assertFalse($this->smartCache->has($childKey));
    }

    public function test_can_create_multiple_dependencies()
    {
        $parentKey = 'parent';
        $childKeys = ['child1', 'child2', 'child3'];
        
        // Create dependencies
        $this->smartCache->dependsOn('child1', $parentKey)
                         ->dependsOn('child2', $parentKey)
                         ->dependsOn('child3', $parentKey);
        
        // Cache all items
        $this->smartCache->put($parentKey, 'parent_value', 3600);
        foreach ($childKeys as $key) {
            $this->smartCache->put($key, "value_for_{$key}", 3600);
        }
        
        // Verify all are cached
        $this->assertTrue($this->smartCache->has($parentKey));
        foreach ($childKeys as $key) {
            $this->assertTrue($this->smartCache->has($key));
        }
        
        // Invalidate parent
        $this->smartCache->invalidate($parentKey);
        
        // Verify all are removed
        $this->assertFalse($this->smartCache->has($parentKey));
        foreach ($childKeys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_dependency_chains()
    {
        // Create chain: A -> B -> C -> D
        $this->smartCache->dependsOn('B', 'A')
                         ->dependsOn('C', 'B') 
                         ->dependsOn('D', 'C');
        
        // Cache all items
        $keys = ['A', 'B', 'C', 'D'];
        foreach ($keys as $key) {
            $this->smartCache->put($key, "value_{$key}", 3600);
        }
        
        // Verify all are cached
        foreach ($keys as $key) {
            $this->assertTrue($this->smartCache->has($key));
        }
        
        // Invalidate A - should cascade through entire chain
        $this->smartCache->invalidate('A');
        
        // Verify all are removed
        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_complex_dependency_graph()
    {
        // Create complex dependency graph:
        //     A
        //   /   \
        //  B     C
        // / \   /
        // D  E  F
        
        $this->smartCache->dependsOn('B', 'A')
                         ->dependsOn('C', 'A')
                         ->dependsOn('D', 'B')
                         ->dependsOn('E', 'B')
                         ->dependsOn('F', 'C');
        
        // Cache all items
        $keys = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($keys as $key) {
            $this->smartCache->put($key, "value_{$key}", 3600);
        }
        
        // Verify all are cached
        foreach ($keys as $key) {
            $this->assertTrue($this->smartCache->has($key));
        }
        
        // Invalidate A - should cascade to all
        $this->smartCache->invalidate('A');
        
        // Verify all are removed
        foreach ($keys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_partial_dependency_invalidation()
    {
        // Create partial dependency structure:
        //   A     G
        //  / \    |
        // B   C   H
        //     |
        //     D
        
        $this->smartCache->dependsOn('B', 'A')
                         ->dependsOn('C', 'A')
                         ->dependsOn('D', 'C')
                         ->dependsOn('H', 'G');
        
        // Cache all items
        $keys = ['A', 'B', 'C', 'D', 'G', 'H'];
        foreach ($keys as $key) {
            $this->smartCache->put($key, "value_{$key}", 3600);
        }
        
        // Invalidate A - should only affect A, B, C, D (not G, H)
        $this->smartCache->invalidate('A');
        
        // Verify A branch is removed
        $this->assertFalse($this->smartCache->has('A'));
        $this->assertFalse($this->smartCache->has('B'));
        $this->assertFalse($this->smartCache->has('C'));
        $this->assertFalse($this->smartCache->has('D'));
        
        // Verify G branch is preserved
        $this->assertTrue($this->smartCache->has('G'));
        $this->assertTrue($this->smartCache->has('H'));
    }

    public function test_array_dependencies()
    {
        $parentKey = 'parent';
        $childKeys = ['child1', 'child2', 'child3'];
        
        // Create dependencies using array syntax
        $this->smartCache->dependsOn('child1', [$parentKey])
                         ->dependsOn('child2', [$parentKey])
                         ->dependsOn('child3', [$parentKey]);
        
        // Cache all items
        $this->smartCache->put($parentKey, 'parent_value', 3600);
        foreach ($childKeys as $key) {
            $this->smartCache->put($key, "value_{$key}", 3600);
        }
        
        // Invalidate parent
        $this->smartCache->invalidate($parentKey);
        
        // Verify all children are removed
        foreach ($childKeys as $key) {
            $this->assertFalse($this->smartCache->has($key));
        }
    }

    public function test_multiple_parent_dependencies()
    {
        // Child depends on multiple parents
        $parentKeys = ['parent1', 'parent2'];
        $childKey = 'child';
        
        $this->smartCache->dependsOn($childKey, $parentKeys);
        
        // Cache all items
        foreach ($parentKeys as $key) {
            $this->smartCache->put($key, "value_{$key}", 3600);
        }
        $this->smartCache->put($childKey, 'child_value', 3600);
        
        // Verify all are cached
        foreach ($parentKeys as $key) {
            $this->assertTrue($this->smartCache->has($key));
        }
        $this->assertTrue($this->smartCache->has($childKey));
        
        // Invalidate first parent - should remove child but not second parent
        $this->smartCache->invalidate('parent1');
        
        $this->assertFalse($this->smartCache->has('parent1'));
        $this->assertFalse($this->smartCache->has($childKey));
        $this->assertTrue($this->smartCache->has('parent2')); // Should remain
    }

    public function test_dependencies_work_with_optimization()
    {
        $parentKey = 'optimized_parent';
        $childKey = 'optimized_child';
        
        // Create dependency
        $this->smartCache->dependsOn($childKey, $parentKey);
        
        // Cache large data that will be optimized
        $largeData = $this->createCompressibleData();
        $this->smartCache->put($parentKey, $largeData, 3600);
        $this->smartCache->put($childKey, $largeData, 3600);
        
        // Verify data is optimized but still accessible
        $this->assertEquals($largeData, $this->smartCache->get($parentKey));
        $this->assertEquals($largeData, $this->smartCache->get($childKey));
        
        // Verify keys are managed
        $managedKeys = $this->smartCache->getManagedKeys();
        $this->assertContains($parentKey, $managedKeys);
        $this->assertContains($childKey, $managedKeys);
        
        // Invalidate parent - should cascade to child
        $this->smartCache->invalidate($parentKey);
        
        $this->assertFalse($this->smartCache->has($parentKey));
        $this->assertFalse($this->smartCache->has($childKey));
    }

    public function test_dependencies_work_with_chunked_data()
    {
        $parentKey = 'chunked_parent';
        $childKey = 'chunked_child';
        
        // Create dependency
        $this->smartCache->dependsOn($childKey, $parentKey);
        
        // Cache large array that will be chunked
        $largeArray = $this->createChunkableData();
        $this->smartCache->put($parentKey, $largeArray, 3600);
        $this->smartCache->put($childKey, $largeArray, 3600);
        
        // Verify data is chunked but still accessible
        $this->assertEquals($largeArray, $this->smartCache->get($parentKey));
        $this->assertEquals($largeArray, $this->smartCache->get($childKey));
        
        // Get raw cached values to verify chunking
        $rawParent = Cache::get($parentKey);
        $rawChild = Cache::get($childKey);
        $this->assertValueIsChunked($rawParent);
        $this->assertValueIsChunked($rawChild);
        
        // Invalidate parent - should cascade and clean up all chunks
        $this->smartCache->invalidate($parentKey);
        
        $this->assertFalse($this->smartCache->has($parentKey));
        $this->assertFalse($this->smartCache->has($childKey));
        
        // Verify all chunks are cleaned up
        foreach ($rawParent['chunk_keys'] as $chunkKey) {
            $this->assertFalse(Cache::has($chunkKey));
        }
        foreach ($rawChild['chunk_keys'] as $chunkKey) {
            $this->assertFalse(Cache::has($chunkKey));
        }
    }

    public function test_dependency_persistence()
    {
        $parentKey = 'persistent_parent';
        $childKey = 'persistent_child';
        
        // Create dependency
        $this->smartCache->dependsOn($childKey, $parentKey);
        
        // Create new SmartCache instance to test persistence
        $newSmartCache = $this->app->make(SmartCache::class);
        
        // Cache items using new instance
        $newSmartCache->put($parentKey, 'parent_value', 3600);
        $newSmartCache->put($childKey, 'child_value', 3600);
        
        // Invalidate using original instance - dependency should still work
        $this->smartCache->invalidate($parentKey);
        
        // Verify both are removed
        $this->assertFalse($newSmartCache->has($parentKey));
        $this->assertFalse($newSmartCache->has($childKey));
    }

    public function test_circular_dependency_prevention()
    {
        // This tests that we don't get into infinite loops
        // Even though we don't explicitly prevent circular deps in the current implementation,
        // we should handle them gracefully
        
        $keyA = 'circular_a';
        $keyB = 'circular_b';
        
        // Create circular dependency (A depends on B, B depends on A)
        $this->smartCache->dependsOn($keyA, $keyB)
                         ->dependsOn($keyB, $keyA);
        
        // Cache both items
        $this->smartCache->put($keyA, 'value_a', 3600);
        $this->smartCache->put($keyB, 'value_b', 3600);
        
        // This should not cause infinite recursion
        $result = $this->smartCache->invalidate($keyA);
        $this->assertTrue($result);
        
        // Both keys should be removed (order doesn't matter due to circular nature)
        $this->assertFalse($this->smartCache->has($keyA));
        $this->assertFalse($this->smartCache->has($keyB));
    }

    public function test_invalidate_non_existent_key()
    {
        $nonExistentKey = 'does_not_exist';
        
        // Should return true even for non-existent keys
        $result = $this->smartCache->invalidate($nonExistentKey);
        $this->assertTrue($result);
    }

    public function test_dependency_cleanup_on_invalidation()
    {
        $parentKey = 'cleanup_parent';
        $childKey = 'cleanup_child';
        
        // Create dependency
        $this->smartCache->dependsOn($childKey, $parentKey);
        
        // Cache items
        $this->smartCache->put($parentKey, 'parent', 3600);
        $this->smartCache->put($childKey, 'child', 3600);
        
        // Invalidate parent - this should cascade and remove both
        $this->smartCache->invalidate($parentKey);
        
        // Verify dependency metadata is cleaned up
        $dependencies = Cache::get('_sc_dependencies', []);
        $this->assertArrayNotHasKey($parentKey, $dependencies);
        
        // Since invalidation cascaded, child should also be removed from dependencies
        $this->assertArrayNotHasKey($childKey, $dependencies);
    }

    public function test_adding_dependencies_to_existing_key()
    {
        $childKey = 'accumulative_child';
        $parent1 = 'parent1';
        $parent2 = 'parent2';
        $parent3 = 'parent3';
        
        // Add dependencies incrementally
        $this->smartCache->dependsOn($childKey, $parent1);
        $this->smartCache->dependsOn($childKey, $parent2);
        $this->smartCache->dependsOn($childKey, [$parent3]);
        
        // Cache all items
        $this->smartCache->put($parent1, 'value1', 3600);
        $this->smartCache->put($parent2, 'value2', 3600);
        $this->smartCache->put($parent3, 'value3', 3600);
        $this->smartCache->put($childKey, 'child_value', 3600);
        
        // Invalidate parent1 - should remove child
        $this->smartCache->invalidate($parent1);
        
        $this->assertFalse($this->smartCache->has($parent1));
        $this->assertFalse($this->smartCache->has($childKey));
        
        // Parent2 and Parent3 should still exist
        $this->assertTrue($this->smartCache->has($parent2));
        $this->assertTrue($this->smartCache->has($parent3));
    }
}
