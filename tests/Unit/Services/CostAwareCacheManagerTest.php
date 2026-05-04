<?php

namespace SmartCache\Tests\Unit\Services;

use SmartCache\Tests\TestCase;
use SmartCache\Services\CostAwareCacheManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

class CostAwareCacheManagerTest extends TestCase
{
    protected CostAwareCacheManager $manager;
    protected Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore());
        // Set maxTrackedKeys to 10 for easy testing of eviction behavior
        $this->manager = new CostAwareCacheManager($this->cache, 10, 3600);
    }

    public function test_tracks_cost_and_calculates_score()
    {
        // Cost: 50ms, Size: 100 bytes
        $this->manager->recordCost('key1', 50.0, 100);
        $this->manager->recordAccess('key1');

        $score = $this->manager->getScore('key1');
        $this->assertGreaterThan(0, $score);

        // Higher cost should result in higher score (more valuable to keep cached)
        $this->manager->recordCost('key2', 500.0, 100);
        $this->assertGreaterThan($score, $this->manager->getScore('key2'));
    }

    public function test_trimming_evicts_lowest_score_items_down_to_90_percent_capacity()
    {
        // Max capacity is 10. We insert 11 items to trigger trim.
        // Trimming should reduce capacity to 90% of max (which is 9 items).

        for ($i = 1; $i <= 11; $i++) {
            // Gradually increasing cost so key1 has lowest score, key11 has highest
            $this->manager->recordCost("key{$i}", $i * 10, 100);
        }

        // After inserting 11, it should have trimmed down to exactly 9 items
        $this->assertEquals(9, $this->manager->trackedKeyCount());

        // key1 and key2 should have been evicted as they had the lowest scores
        $this->assertNull($this->manager->getKeyMetadata('key1'));
        $this->assertNull($this->manager->getKeyMetadata('key2'));

        // key11 should still be present
        $this->assertNotNull($this->manager->getKeyMetadata('key11'));
    }

    public function test_trimming_keeps_one_item_when_capacity_is_one()
    {
        $manager = new CostAwareCacheManager($this->cache, 1, 3600);

        $manager->recordCost('low-value', 10.0, 100);
        $manager->recordCost('high-value', 100.0, 100);

        $this->assertEquals(1, $manager->trackedKeyCount());
        $this->assertNull($manager->getKeyMetadata('low-value'));
        $this->assertNotNull($manager->getKeyMetadata('high-value'));
    }

    public function test_persist_and_load()
    {
        $this->manager->recordCost('key1', 50.0, 100);
        $this->manager->persist();

        // Create new manager instance using the same cache store
        $newManager = new CostAwareCacheManager($this->cache, 10, 3600);

        $this->assertEquals(1, $newManager->trackedKeyCount());
        $this->assertNotNull($newManager->getKeyMetadata('key1'));
    }
}
