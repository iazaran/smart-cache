<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Drivers\MemoizedCacheDriver;
use SmartCache\Http\Controllers\StatisticsController;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Services\CostAwareCacheManager;
use SmartCache\Services\OrphanChunkCleanupService;
use SmartCache\Services\SmartChunkSizeCalculator;
use SmartCache\SmartCache;
use SmartCache\Strategies\AdaptiveCompressionStrategy;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\EncryptionStrategy;
use SmartCache\Tests\TestCase;

/**
 * An array store that counts writes per key. ArrayStore::forever() calls
 * put(), so only forever() is counted for entries stored without expiry.
 */
class V117CountingStore extends ArrayStore
{
    /** @var array<string, int> */
    public array $foreverWrites = [];

    public function forever($key, $value)
    {
        $this->foreverWrites[$key] = ($this->foreverWrites[$key] ?? 0) + 1;

        return parent::forever($key, $value);
    }
}

/**
 * An array store whose locks fail on release, like a Redis connection that
 * drops right after the locked update.
 */
class V117FailingReleaseStore extends V117CountingStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new V117FailingReleaseLock($this, $name, $seconds, $owner);
    }
}

class V117FailingReleaseLock extends ArrayLock
{
    public function release()
    {
        parent::release();

        throw new \RuntimeException('Connection lost while releasing the lock');
    }
}

/**
 * Counts how often instances are serialized.
 */
class V117SerializationCounter
{
    public static int $serializations = 0;

    public array $rows = [1, 2, 3];

    public function __serialize(): array
    {
        static::$serializations++;

        return ['rows' => $this->rows];
    }

    public function __unserialize(array $data): void
    {
        $this->rows = $data['rows'];
    }
}

/**
 * A repository that runs a callback the first time a chosen key is read, to
 * replay another process writing in between.
 */
class V117HookedRepository extends Repository
{
    public ?string $hookKey = null;

    public ?\Closure $hook = null;

    public function get($key, $default = null): mixed
    {
        if ($this->hook !== null && $key === $this->hookKey) {
            $hook = $this->hook;
            $this->hook = null;
            $hook();
        }

        return parent::get($key, $default);
    }
}

/**
 * Counts writes, to see how often shared state is persisted.
 */
class V117CountingRepository extends Repository
{
    public int $puts = 0;

    public function put($key, $value, $ttl = null): bool
    {
        $this->puts++;

        return parent::put($key, $value, $ttl);
    }
}

/**
 * Its name contains "ArrayStore", so the console commands enumerate it. Reading
 * one key fails, like a Redis key that is not a string value.
 */
class V117PoisonedArrayStore extends ArrayStore
{
    public function get($key)
    {
        if ($key === 'poison') {
            throw new \RuntimeException('WRONGTYPE Operation against a key holding the wrong kind of value');
        }

        return parent::get($key);
    }
}

/**
 * A store whose forget() always reports success, as the database store does.
 */
class V117AlwaysForgetsStore extends ArrayStore
{
    public function forget($key)
    {
        parent::forget($key);

        return true;
    }
}

class V117Collection extends Collection
{
}

class V117Payload
{
    public function __construct(public string $body = '')
    {
    }
}

/**
 * Regression coverage for 1.17.0.
 *
 * Each test fails against the 1.16.0 implementation, except those marked as a
 * guard. A guard pins behavior that must not change.
 */
class V117FixesTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smartCache = $this->app->make(SmartCacheContract::class);
        $this->smartCache->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function smartCacheOn(ArrayStore $store): SmartCache
    {
        return new SmartCache(new Repository($store), $this->app['cache'], $this->app['config']);
    }

    private function rawStore(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->app['cache']->store();
    }

    private function chunkingCache(bool $lazy = false, ?\Illuminate\Contracts\Cache\Repository $repository = null): SmartCache
    {
        return new SmartCache($repository ?? $this->rawStore(), $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100, $lazy),
            new CompressionStrategy(1024),
        ]);
    }

    private function rows(int $count, string $label = 'row'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $i, 'label' => "{$label}_{$i}", 'payload' => \str_repeat('x', 20)];
        }

        return $rows;
    }

    private function chunkKeysOf(string $key): array
    {
        $raw = $this->rawStore()->get($key);

        $this->assertIsArray($raw);
        $this->assertTrue($raw['_sc_chunked'] ?? false, "{$key} should be chunked");

        return $raw['chunk_keys'];
    }

    private function rebuiltSmartCache(): SmartCache
    {
        $this->app->forgetInstance(SmartCacheContract::class);
        \SmartCache\Facades\SmartCache::clearResolvedInstances();

        return $this->app->make(SmartCacheContract::class);
    }

    // ------------------------------------------------------------------
    // Bulk invalidation writes the managed-key index once
    // ------------------------------------------------------------------

    public function test_flush_tags_writes_the_managed_key_index_once(): void
    {
        $store = new V117CountingStore();
        $cache = $this->smartCacheOn($store);

        for ($i = 0; $i < 100; $i++) {
            $cache->tags(['bulk'])->put("bulk_{$i}", $i, 600);
        }
        $cache->put('unrelated', 'kept', 600);
        $cache->persistManagedKeys();
        $store->foreverWrites = [];

        $cache->flushTags('bulk');

        // 1.16 rewrote the whole index every 10 removed keys.
        $this->assertSame(1, $store->foreverWrites['_sc_managed_keys'] ?? 0);
        $this->assertSame(['unrelated'], $cache->getManagedKeys());
        $this->assertSame(['unrelated'], $store->get('_sc_managed_keys'));
        $this->assertNull($cache->get('bulk_50'));
        $this->assertSame('kept', $cache->get('unrelated'));
    }

    public function test_flush_patterns_and_delete_multiple_write_the_index_once(): void
    {
        $store = new V117CountingStore();
        $cache = $this->smartCacheOn($store);

        for ($i = 0; $i < 30; $i++) {
            $cache->put("report_{$i}", $i, 600);
            $cache->put("feed_{$i}", $i, 600);
        }
        $cache->put('kept', 'yes', 600);
        $cache->persistManagedKeys();
        $store->foreverWrites = [];

        $this->assertSame(30, $cache->flushPatterns(['report_*']));
        $this->assertSame(1, $store->foreverWrites['_sc_managed_keys'] ?? 0);

        $store->foreverWrites = [];
        $this->assertTrue($cache->deleteMultiple(\array_map(static fn (int $i): string => "feed_{$i}", \range(0, 29))));
        $this->assertSame(1, $store->foreverWrites['_sc_managed_keys'] ?? 0);

        $this->assertSame(['kept'], $cache->getManagedKeys());
        $this->assertSame(['kept'], $store->get('_sc_managed_keys'));
    }

    public function test_guard_bulk_removal_that_throws_still_persists_its_changes_later(): void
    {
        $store = new V117CountingStore();
        $cache = $this->smartCacheOn($store);

        $cache->put('first', 1, 600);
        $cache->put('second', 2, 600);
        $cache->persistManagedKeys();

        // A KeyForgotten listener (or anything else) failing midway must not
        // leave the index batching forever.
        $this->app['config']->set('smart-cache.events.enabled', true);
        $calls = 0;
        $this->app['events']->listen(\SmartCache\Events\KeyForgotten::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('listener failed');
            }
        });

        try {
            $cache->deleteMultiple(['first', 'second']);
            $this->fail('The listener exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->app['config']->set('smart-cache.events.enabled', false);
        $cache->forget('second');

        $cache->persistManagedKeys();
        $this->assertSame([], $store->get('_sc_managed_keys') ?? []);
    }

    // ------------------------------------------------------------------
    // flushNamespace() starts from the stored index
    // ------------------------------------------------------------------

    public function test_flush_namespace_flushes_keys_other_processes_tracked(): void
    {
        $store = new ArrayStore();
        $worker = $this->smartCacheOn($store);
        $other = $this->smartCacheOn($store);

        // This process loads the index...
        $worker->namespace('tenant')->put('profile', 'mine', 600);
        $worker->persistManagedKeys();

        // ...then another process tracks a key in the same namespace.
        $other->namespace('tenant')->put('orders', 'theirs', 600);
        $other->persistManagedKeys();

        $this->assertSame(2, $worker->withoutNamespace()->flushNamespace('tenant'));
        $this->assertNull($other->namespace('tenant')->get('orders'));
        $this->assertNull($other->get('profile'));
        $this->assertNull($store->get('_sc_managed_keys'));
    }

    public function test_guard_flush_namespace_keeps_the_callers_namespace_and_other_keys(): void
    {
        $this->smartCache->put('global', 'g', 600);
        $this->smartCache->namespace('other')->put('k', 'o', 600);
        $this->smartCache->namespace('tenant')->put('k', 't', 600);

        $this->assertSame(1, $this->smartCache->flushNamespace('tenant'));

        $this->assertSame('tenant', $this->smartCache->getNamespace());
        $this->assertNull($this->smartCache->get('k'));
        $this->assertSame('o', $this->smartCache->namespace('other')->get('k'));
        $this->assertSame('g', $this->smartCache->withoutNamespace()->get('global'));
    }

    // ------------------------------------------------------------------
    // Metadata locks never apply an update twice
    // ------------------------------------------------------------------

    public function test_metadata_update_is_not_repeated_when_releasing_the_lock_fails(): void
    {
        $store = new V117FailingReleaseStore();
        $cache = $this->smartCacheOn($store);

        $cache->dependsOn('posts', 'profile');

        // 1.16 caught the release error and ran the update again without the lock.
        $this->assertSame(1, $store->foreverWrites['_sc_dependencies'] ?? 0);
        $this->assertSame(['posts' => ['profile']], $store->get('_sc_dependencies'));
    }

    public function test_metadata_update_is_not_repeated_without_waiting_for_the_lock(): void
    {
        $this->app['config']->set('smart-cache.metadata_lock.wait', 0);
        $store = new V117FailingReleaseStore();
        $cache = $this->smartCacheOn($store);

        $cache->tags(['t'])->put('k', 'v', 600);

        $this->assertSame(1, $store->foreverWrites['_sc_tag_t'] ?? 0);
        $this->assertSame(['k'], $store->get('_sc_tag_t'));
    }

    // ------------------------------------------------------------------
    // SWR and stampede protection use the framework clock
    // ------------------------------------------------------------------

    public function test_flexible_follows_the_framework_clock(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12));
        $calls = 0;
        $callback = function () use (&$calls): string {
            return 'v' . (++$calls);
        };

        $this->assertSame('v1', $this->smartCache->flexible('clocked', [10, 100], $callback));

        // 20 s later the entry is stale (but within the stale window), as with
        // Laravel's Cache::flexible() under Carbon::setTestNow() or travel().
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12, 0, 20));
        $this->assertSame('v1', $this->smartCache->flexible('clocked', [10, 100], $callback));
        $this->assertSame(2, $calls);
        $this->assertSame('v2', $this->smartCache->flexible('clocked', [10, 100], $callback));
    }

    public function test_stampede_metadata_uses_the_framework_clock(): void
    {
        $now = Carbon::create(2026, 1, 1, 12);
        Carbon::setTestNow($now);

        $this->smartCache->rememberWithStampedeProtection('xfetch', 60, fn () => 'value');

        $meta = $this->smartCache->repository()->get('_sc_meta:xfetch');
        $this->assertSame($now->getTimestamp(), $meta['created_at']);
    }

    public function test_async_swr_staleness_follows_the_framework_clock(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12));
        $this->smartCache->asyncSwr('async_clocked', V117RefreshCallback::class, 10, 100);

        \Illuminate\Support\Facades\Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12, 0, 30));

        $this->assertSame('fresh', $this->smartCache->asyncSwr('async_clocked', V117RefreshCallback::class, 10, 100));
        \Illuminate\Support\Facades\Queue::assertPushed(\SmartCache\Jobs\BackgroundCacheRefreshJob::class, 1);
    }

    // ------------------------------------------------------------------
    // remember() does not serialize a value again for cost-aware scoring
    // ------------------------------------------------------------------

    public function test_remember_measures_the_written_value_once(): void
    {
        $this->app['config']->set('smart-cache.deduplication.enabled', false);
        $cache = $this->app->make(SmartCacheContract::class);
        $this->assertNotNull($cache->getCostAwareManager());

        $value = new V117SerializationCounter();
        V117SerializationCounter::$serializations = 0;
        $cache->put('measured_put', $value, 600);
        $perPut = V117SerializationCounter::$serializations;

        V117SerializationCounter::$serializations = 0;
        $cache->remember('measured_remember', 600, fn () => $value);

        // 1.16 serialized it again for the cost-aware size, and once more for
        // the unchanged "optimized" size.
        $this->assertSame($perPut, V117SerializationCounter::$serializations);
        $this->assertSame(
            \strlen(\serialize($value)),
            $cache->cacheValue('measured_remember')['size_bytes']
        );
    }

    public function test_guard_cost_aware_size_is_measured_when_monitoring_is_off(): void
    {
        $this->app['config']->set('smart-cache.monitoring.enabled', false);
        $cache = $this->app->make(SmartCacheContract::class);
        $cache->put('earlier_write', \str_repeat('x', 10), 600);

        $value = ['rows' => \range(1, 50)];
        $cache->remember('unmonitored', 600, fn () => $value);

        $this->assertSame(\strlen(\serialize($value)), $cache->cacheValue('unmonitored')['size_bytes']);
    }
    // ------------------------------------------------------------------
    // Orphan-chunk cleanup keeps registrations it did not process
    // ------------------------------------------------------------------

    public function test_a_second_cleanup_pass_in_the_same_process_keeps_newer_registrations(): void
    {
        $cache = $this->chunkingCache();
        $cache->forever('catalog', $this->rows(300, 'v1'));
        // The cleanup service now holds a copy of the registry.
        $cache->cleanupOrphanChunks();

        $cache->forever('catalog', $this->rows(300, 'v2'));
        $live = $this->chunkKeysOf('catalog');

        // A concurrent writer that lost the race registered its chunks meanwhile.
        $this->rawStore()->forever('_sc_chunk_catalog_lost_0', ['x']);
        $registry = $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY);
        $registry['catalog']['chunk_keys'][] = '_sc_chunk_catalog_lost_0';
        $this->rawStore()->forever(OrphanChunkCleanupService::REGISTRY_KEY, $registry);

        $stats = $cache->cleanupOrphanChunks();

        // 1.16 replaced the stored entry with its stale copy, so the lost chunk
        // was dropped from the registry without being deleted.
        $this->assertSame(1, $stats['cleaned_chunks']);
        $this->assertNull($this->rawStore()->get('_sc_chunk_catalog_lost_0'));
        $this->assertSame($live, $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY)['catalog']['chunk_keys']);
        $this->assertSame($this->rows(300, 'v2'), $cache->get('catalog'));
    }

    public function test_cleanup_keeps_chunks_registered_while_it_runs(): void
    {
        $repository = new V117HookedRepository($this->rawStore()->getStore());
        $cache = $this->chunkingCache(false, $repository);

        $cache->forever('evicted', $this->rows(300));
        $this->rawStore()->forget('evicted');

        // While the pass reads the evicted entry, another process (whose
        // metadata lock wait ran out) registers a new chunked value.
        $repository->hookKey = 'evicted';
        $repository->hook = function (): void {
            $registry = $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY, []);
            $registry['arrived'] = ['chunk_keys' => ['_sc_chunk_arrived_v_0'], 'registered_at' => \time()];
            $this->rawStore()->forever(OrphanChunkCleanupService::REGISTRY_KEY, $registry);
        };

        $stats = $cache->cleanupOrphanChunks();

        $this->assertSame(1, $stats['orphaned_main_keys']);
        $registry = $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY);
        // 1.16 wrote back the registry it read before the pass.
        $this->assertSame(['_sc_chunk_arrived_v_0'], $registry['arrived']['chunk_keys'] ?? null);
        $this->assertArrayNotHasKey('evicted', $registry);
    }

    public function test_registered_chunks_are_merged_into_the_stored_registry(): void
    {
        $service = new OrphanChunkCleanupService($this->rawStore());

        // Registered by SmartCache after this service loaded its copy.
        $this->rawStore()->forever(OrphanChunkCleanupService::REGISTRY_KEY, [
            'theirs' => ['chunk_keys' => ['_sc_chunk_theirs_0'], 'registered_at' => \time()],
        ]);

        $service->registerChunks('mine', ['_sc_chunk_mine_0']);

        $registry = $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY);
        $this->assertSame(['theirs', 'mine'], \array_keys($registry));

        $service->unregisterChunks('mine');
        $this->assertSame(['theirs'], \array_keys($this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY)));
    }

    // ------------------------------------------------------------------
    // Chunking and lazy loading
    // ------------------------------------------------------------------

    public function test_turning_lazy_loading_off_applies_to_existing_entries(): void
    {
        $this->chunkingCache(true)->forever('lazy_catalog', $this->rows(300));
        $this->assertInstanceOf(LazyChunkedCollection::class, $this->chunkingCache(true)->get('lazy_catalog'));

        // 1.16 kept returning a LazyChunkedCollection, for good for forever entries.
        $this->assertSame($this->rows(300), $this->chunkingCache(false)->get('lazy_catalog'));
    }

    /** Guard. */
    public function test_guard_eagerly_written_entries_stay_eager_with_lazy_loading_on(): void
    {
        $this->chunkingCache(false)->forever('eager_catalog', $this->rows(300));

        $this->assertSame($this->rows(300), $this->chunkingCache(true)->get('eager_catalog'));
    }

    public function test_large_arrays_holding_a_closure_can_be_stored_on_a_non_serializing_store(): void
    {
        $value = [fn () => 'called', ...\range(1, 1500)];

        // 1.16 threw "Serialization of 'Closure' is not allowed" while sizing it.
        $this->assertTrue($this->smartCache->put('closures', $value, 600));
        $this->assertSame('called', $this->smartCache->get('closures')[0]());

        // With the default deduplication and monitoring, small values too:
        // 1.16 failed hashing and measuring them, where Cache::put() works.
        $this->app['config']->set('smart-cache.deduplication.enabled', true);
        $cache = $this->rebuiltSmartCache();
        $this->assertTrue($cache->put('small_closure', ['handler' => fn () => 'small'], 600));
        $this->assertTrue($cache->put('small_closure', ['handler' => fn () => 'again'], 600));
        $this->assertSame('again', $cache->get('small_closure')['handler']());
        $this->assertNull($this->rawStore()->get('_sc_dna:small_closure'));
    }

    public function test_materialize_keeps_the_collection_class(): void
    {
        $cache = $this->chunkingCache(true);
        $cache->forever('typed', new V117Collection($this->rows(300)));

        $lazy = $cache->get('typed');
        $this->assertInstanceOf(LazyChunkedCollection::class, $lazy);

        $materialized = $lazy->materialize();
        $this->assertInstanceOf(V117Collection::class, $materialized);
        $this->assertSame($this->rows(300), $materialized->all());
    }

    public function test_lazy_collections_are_json_serializable(): void
    {
        $cache = $this->chunkingCache(true);
        $cache->forever('json_rows', $this->rows(150));
        $lazy = $cache->get('json_rows');

        // 1.16 encoded it as "{}".
        $this->assertSame(\json_encode($this->rows(150)), \json_encode($lazy));
        $this->assertSame($this->rows(150), collect($lazy)->all());
    }

    public function test_lazy_slice_accepts_negative_arguments_like_array_slice(): void
    {
        $rows = $this->rows(300);
        $cache = $this->chunkingCache(true);
        $cache->forever('sliced', $rows);
        $lazy = $cache->get('sliced');

        $this->assertSame(\array_slice($rows, -5), $lazy->slice(-5));
        $this->assertSame(\array_slice($rows, -150, 3), $lazy->slice(-150, 3));
        $this->assertSame(\array_slice($rows, 10, -285), $lazy->slice(10, -285));
        $this->assertSame(\array_slice($rows, 290, 50), $lazy->slice(290, 50));
    }

    public function test_a_chunk_that_is_not_an_array_is_reported_as_invalid(): void
    {
        $this->app['config']->set('smart-cache.fallback.enabled', false);
        $cache = $this->chunkingCache();
        $cache->put('corrupt_chunk', $this->rows(300), 600);
        $chunks = $this->chunkKeysOf('corrupt_chunk');
        $this->rawStore()->put($chunks[1], 'garbage', 600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache chunk');

        $cache->get('corrupt_chunk');
    }

    public function test_chunk_count_never_divides_by_zero(): void
    {
        $calculator = new SmartChunkSizeCalculator();

        $this->assertSame(0, $calculator->calculateChunkCount(0, 0));
        $this->assertSame(0, $calculator->getRecommendations([])['chunk_count']);
    }

    public function test_adaptive_compression_stats_never_divide_by_zero(): void
    {
        $stats = (new AdaptiveCompressionStrategy())->getCompressionStats([
            '_sc_adaptive' => true,
            'original_size' => 0,
            'compressed_size' => 10,
        ]);

        $this->assertSame(0, $stats['ratio']);
        $this->assertSame(0, $stats['savings_percent']);
    }

    // ------------------------------------------------------------------
    // Encryption and unserialization
    // ------------------------------------------------------------------

    public function test_an_invalid_encryption_pattern_is_logged_once(): void
    {
        Log::spy();
        $strategy = new EncryptionStrategy($this->app['encrypter'], ['patterns' => ['user_token_*']]);

        // A glob is not a regex: it matches nothing, as before, but now says so.
        $this->assertFalse($strategy->shouldApply('secret', ['key' => 'user_token_1']));
        $this->assertFalse($strategy->shouldApply('secret', ['key' => 'user_token_2']));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message) => \str_contains($message, '[user_token_*]'));
    }

    /** Guard. */
    public function test_guard_objects_are_unserialized_without_restriction_by_default(): void
    {
        $cache = $this->rebuiltSmartCache();
        $cache->put('object', new V117Payload(\str_repeat('x', 4000)), 600);

        $this->assertInstanceOf(CompressionStrategy::class, $cache->getStrategies()['compression']);
        $this->assertTrue($cache->getRaw('object')['_sc_compressed'] ?? false);
        $this->assertInstanceOf(V117Payload::class, $cache->get('object'));
    }

    public function test_serializable_classes_restricts_compressed_values(): void
    {
        $this->app['config']->set('smart-cache.serializable_classes', false);
        $cache = $this->rebuiltSmartCache();

        $cache->put('object', new V117Payload(\str_repeat('x', 4000)), 600);
        $cache->put('array', ['rows' => \str_repeat('y', 4000)], 600);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $cache->get('object'));
        $this->assertSame(['rows' => \str_repeat('y', 4000)], $cache->get('array'));
    }

    public function test_serializable_classes_can_follow_the_laravel_setting(): void
    {
        $this->app['config']->set('cache.serializable_classes', [V117Payload::class]);
        $this->app['config']->set('smart-cache.serializable_classes', 'cache');
        $cache = $this->rebuiltSmartCache();

        $cache->put('allowed', new V117Payload(\str_repeat('x', 4000)), 600);
        $cache->put('other', new V117Collection([\str_repeat('z', 4000)]), 600);

        $this->assertInstanceOf(V117Payload::class, $cache->get('allowed'));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $cache->get('other'));
    }

    public function test_serializable_classes_restricts_encrypted_values(): void
    {
        $this->app['config']->set('smart-cache.serializable_classes', false);
        $this->app['config']->set('smart-cache.strategies.encryption', ['enabled' => true, 'keys' => ['secret', 'secret_object']]);
        $cache = $this->rebuiltSmartCache();

        $cache->put('secret', ['token' => 'abc'], 600);
        $cache->put('secret_object', new V117Payload('abc'), 600);

        $this->assertTrue($cache->getRaw('secret')['_sc_encrypted'] ?? false);
        $this->assertSame(['token' => 'abc'], $cache->get('secret'));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $cache->get('secret_object'));
    }

    // ------------------------------------------------------------------
    // Queued refreshes write to the store they came from
    // ------------------------------------------------------------------

    public function test_refresh_async_writes_to_the_callers_store(): void
    {
        $this->app['config']->set('cache.stores.second', ['driver' => 'array', 'serialize' => false]);

        $this->smartCache->store('second')->refreshAsync('feed', V117RefreshCallback::class, 60);
        $this->smartCache->namespace('tenant')->store('second')->refreshAsync('feed', V117RefreshCallback::class, 60);
        $this->smartCache->withoutNamespace();

        // 1.16 wrote both to the default store.
        $this->assertSame('fresh', $this->smartCache->store('second')->get('feed'));
        $this->assertSame('fresh', $this->smartCache->store('second')->get('tenant:feed'));
        $this->assertNull($this->smartCache->get('feed'));
        $this->assertNull($this->smartCache->get('tenant:feed'));
    }

    /** Guard. */
    public function test_guard_a_refresh_job_without_a_store_writes_to_the_default_store(): void
    {
        // As a job queued by 1.16 unserializes: without the new property.
        $job = \unserialize(\serialize(new BackgroundCacheRefreshJob('legacy_feed', V117RefreshCallback::class, 60)));
        $job->handle();

        $this->assertSame('fresh', $this->smartCache->get('legacy_feed'));
    }

    // ------------------------------------------------------------------
    // Dashboard and console commands
    // ------------------------------------------------------------------

    public function test_the_dashboard_page_does_not_scan_or_prune_the_cache(): void
    {
        $this->app['config']->set('smart-cache.dashboard.enabled', true);
        $this->app['config']->set('smart-cache.self_healing.enabled', false);

        $this->smartCache->put('broken', \range(1, 1500), 600);
        $chunks = $this->rawStore()->get('broken')['chunk_keys'];
        $this->rawStore()->forget($chunks[0]);

        $response = $this->app->make(StatisticsController::class)->dashboard();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Managed Keys', $response->getContent());
        // 1.16 ran healthCheck() on every page view, which evicted it.
        $this->assertNotNull($this->rawStore()->get('broken'));
    }

    public function test_clear_force_clears_non_managed_keys_when_every_managed_key_expired(): void
    {
        $this->smartCache->put('short_lived', 'value', 1);
        $this->rawStore()->put('foreign', 'x', 600);
        $this->travel(5)->seconds();

        $this->artisan('smart-cache:clear', ['--force' => true])->assertExitCode(0);

        // 1.16 stopped after cleaning up the expired index entries.
        $this->assertNull($this->rawStore()->get('foreign'));
    }

    public function test_clear_finds_a_numeric_managed_key(): void
    {
        $this->smartCache->put('123', 'numeric', 600);

        // The index returns '123' as the integer 123; 1.16 reported it as not managed.
        $this->artisan('smart-cache:clear', ['key' => '123'])->assertExitCode(0);

        $this->assertNull($this->rawStore()->get('123'));

        // The dashboard's command endpoint had the same lookup.
        $this->smartCache->put('456', 'numeric', 600);
        $result = $this->smartCache->executeCommand('clear', ['key' => '456']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['was_managed']);
    }

    public function test_clear_force_continues_after_a_key_it_cannot_clear(): void
    {
        $store = new V117PoisonedArrayStore();
        $this->app->instance(SmartCacheContract::class, $this->smartCacheOn($store));
        $store->put('poison', 'x', 600);
        $store->put('after', 'y', 600);

        $this->artisan('smart-cache:clear', ['--force' => true])
            ->expectsOutput('1 non-managed cache keys could not be cleared.')
            ->assertExitCode(0);

        // 1.16 stopped at the first key that threw.
        $this->assertNull($store->get('after'));
    }

    public function test_warm_reports_an_unknown_warmer(): void
    {
        $this->app['config']->set('smart-cache.warmers', ['products' => 'App\\CacheWarmers\\ProductCacheWarmer']);

        $this->artisan('smart-cache:warm', ['--warmer' => ['prodcuts']])
            ->expectsOutput('Unknown warmer [prodcuts]. Run with --list to see the configured warmers.')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    // Pattern invalidation
    // ------------------------------------------------------------------

    public function test_flush_patterns_counts_a_key_matched_by_several_patterns_once(): void
    {
        $cache = $this->smartCacheOn(new V117AlwaysForgetsStore());
        $cache->put('Post_1_1', 'a', 600);
        $cache->put('Post_1_2', 'b', 600);

        // Post_1_1 matches both patterns; 1.16 forgot and counted it twice.
        $this->assertSame(2, $cache->flushPatterns(['Post_1_*', 'Post_*_1']));

        // invalidateModel() builds overlapping patterns of its own.
        $cache->put('Post_1_1', 'a', 600);
        $cache->put('Post_1_2', 'b', 600);
        $this->assertSame(2, $cache->invalidateModel('Post', 1));
    }

    // ------------------------------------------------------------------
    // memo() layer
    // ------------------------------------------------------------------

    public function test_memo_reads_a_remembered_key_from_the_store_after_eviction(): void
    {
        $driver = new MemoizedCacheDriver($this->rawStore(), 1);

        $this->assertNull($driver->get('k'));
        $this->assertSame('v', $driver->remember('k', 600, fn () => 'v'));

        // Memoizing another value evicts 'k' from the memo layer.
        $this->rawStore()->put('other', 'o', 600);
        $driver->get('other');

        // 1.16 still remembered the first miss and returned the default.
        $this->assertSame('v', $driver->get('k'));
    }

    /** Guard. */
    public function test_guard_memo_evicts_the_least_recently_used_value(): void
    {
        $driver = new MemoizedCacheDriver($this->rawStore(), 2);
        $this->rawStore()->put('a', 'A', 600);
        $this->rawStore()->put('b', 'B', 600);
        $this->rawStore()->put('c', 'C', 600);

        $driver->get('a');
        $driver->get('b');
        $driver->get('a');
        $driver->get('c'); // evicts b, the least recently used

        $this->rawStore()->put('a', 'A2', 600);
        $this->rawStore()->put('b', 'B2', 600);

        $this->assertSame('A', $driver->get('a'));
        $this->assertSame('B2', $driver->get('b'));
    }

    // ------------------------------------------------------------------
    // Circuit breaker shared state
    // ------------------------------------------------------------------

    public function test_shared_breaker_stats_report_the_shared_recovery_time(): void
    {
        $reader = (new CircuitBreaker(1, 30, 1))->enableSharedState($this->rawStore(), 'cb', 300);
        $writer = (new CircuitBreaker(1, 30, 1))->enableSharedState($this->rawStore(), 'cb', 300);

        $writer->recordFailure(new \RuntimeException('down'));

        $stats = $reader->getStats();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $stats['state']);
        // 1.16 computed it before reading the shared state: null.
        $this->assertNotNull($stats['time_until_recovery']);
    }

    public function test_a_shared_closed_state_clears_a_stale_opened_at(): void
    {
        $reader = (new CircuitBreaker(1, 30, 1))->enableSharedState($this->rawStore(), 'cb', 300);
        $writer = (new CircuitBreaker(1, 30, 1))->enableSharedState($this->rawStore(), 'cb', 300);

        $writer->recordFailure(new \RuntimeException('down'));
        $this->assertNotNull($reader->getStats()['opened_at']);

        $writer->reset();

        $stats = $reader->getStats();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $stats['state']);
        $this->assertNull($stats['opened_at']);
    }

    public function test_successes_on_a_healthy_shared_breaker_do_not_write(): void
    {
        $repository = new V117CountingRepository(new ArrayStore());
        $breaker = (new CircuitBreaker(5, 30, 1))->enableSharedState($repository, 'cb', 300);

        $breaker->recordSuccess();
        $breaker->recordSuccess();
        $this->assertSame(0, $repository->puts);

        // A success after a failure still resets the shared failure count.
        $breaker->recordFailure(new \RuntimeException('blip'));
        $breaker->recordSuccess();
        $this->assertSame(2, $repository->puts);
        $this->assertSame(0, $repository->get('cb')['failure_count']);
    }

    // ------------------------------------------------------------------
    // Cost-aware metadata in long-running workers
    // ------------------------------------------------------------------

    public function test_refresh_state_rereads_cost_aware_scores(): void
    {
        $this->smartCache->remember('scored', 600, fn () => 'value');

        $this->smartCache->refreshState();
        $this->assertArrayHasKey('scored', $this->rawStore()->get('_sc_cost_metadata'));

        // Another process records a score.
        $other = new CostAwareCacheManager($this->rawStore());
        $other->recordCost('theirs', 5.0, 10);
        $other->persist();

        // 1.16 kept the copy it loaded first, for the life of the worker.
        $this->assertNotNull($this->smartCache->cacheValue('theirs'));
        $this->assertNotNull($this->smartCache->cacheValue('scored'));
    }
}

class V117RefreshCallback
{
    public function __invoke(): string
    {
        return 'fresh';
    }
}
