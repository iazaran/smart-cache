<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Services\OrphanChunkCleanupService;
use SmartCache\Services\RateLimiter;
use SmartCache\Services\SmartChunkSizeCalculator;
use SmartCache\SmartCache;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\SmartSerializationStrategy;
use SmartCache\Tests\TestCase;

/**
 * An array store that lists its keys and can fail chosen writes: it throws
 * on the nth chunk write, or on writes of one key, before or after storing.
 */
class V1171FailingStore extends ArrayStore
{
    public ?int $throwOnChunk = null;
    public ?string $throwOnKey = null;
    public bool $storeBeforeThrowing = false;
    private int $chunkWrites = 0;

    public function put($key, $value, $seconds)
    {
        if ($this->throwOnChunk !== null
            && \str_starts_with($key, '_sc_chunk_')
            && $key !== OrphanChunkCleanupService::REGISTRY_KEY
            && ++$this->chunkWrites === $this->throwOnChunk
        ) {
            throw new \RuntimeException('Item size has exceeded the maximum allowed size');
        }

        if ($this->throwOnKey !== null && $key === $this->throwOnKey) {
            if ($this->storeBeforeThrowing) {
                parent::put($key, $value, $seconds);
            }

            throw new \RuntimeException('Connection lost');
        }

        return parent::put($key, $value, $seconds);
    }

    /**
     * @return array<int, string>
     */
    public function chunkKeys(): array
    {
        return \array_values(\array_filter(
            \array_map('strval', \array_keys($this->storage)),
            static fn (string $key): bool => \str_starts_with($key, '_sc_chunk_')
                && $key !== OrphanChunkCleanupService::REGISTRY_KEY
        ));
    }
}

/**
 * Lets the rate-limit window end between attempt()'s add() and increment(),
 * as a request that arrives right at the boundary would see it.
 */
class V1171WindowEndingRepository extends Repository
{
    public int $secondsToSkip = 0;

    public function add($key, $value, $ttl = null)
    {
        $added = parent::add($key, $value, $ttl);
        Carbon::setTestNow(Carbon::now()->addSeconds($this->secondsToSkip));

        return $added;
    }
}

class V1171Rows
{
    public function __invoke(): array
    {
        return \range(5001, 5300);
    }
}

class V1171Warmer
{
    public static bool $warmed = false;

    public function warm(): array
    {
        self::$warmed = true;

        return ['keys' => 1];
    }
}

/**
 * Regression coverage for 1.17.1.
 *
 * Each test fails against the 1.17.0 implementation, except those marked as a
 * guard. A guard pins behavior that must not change.
 */
class V1171FixesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        V1171Warmer::$warmed = false;

        parent::tearDown();
    }

    private function rawStore(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->app['cache']->store();
    }

    /**
     * The container's SmartCache, rebuilt with lazy chunking (100 items per
     * chunk), as the provider builds it from the config.
     */
    private function lazySmartCache(): SmartCache
    {
        $this->app['config']->set('smart-cache.strategies.chunking', [
            'enabled' => true,
            'chunk_size' => 100,
            'lazy_loading' => true,
        ]);
        $this->app->forgetInstance(SmartCacheContract::class);
        \SmartCache\Facades\SmartCache::clearResolvedInstances();

        return $this->app->make(SmartCacheContract::class);
    }

    private function chunkingCacheOn(\Illuminate\Contracts\Cache\Repository $repository, bool $lazy = false): SmartCache
    {
        return new SmartCache($repository, $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100, $lazy),
            new CompressionStrategy(1024),
        ]);
    }

    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $i, 'label' => "row_{$i}", 'payload' => \str_repeat('x', 20)];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Lazily loaded values whose chunks go away
    // ------------------------------------------------------------------

    public function test_pull_returns_a_readable_lazily_loaded_value(): void
    {
        $cache = $this->lazySmartCache();
        $cache->put('rows', \range(1, 1000), 600);

        $value = $cache->pull('rows');

        // 1.17.0 returned a LazyChunkedCollection whose chunks forget() had
        // just deleted: reading it threw "Missing cache chunk".
        $this->assertSame(\range(1, 1000), $value);
        $this->assertFalse($cache->has('rows'));

        $cache->put('rows', \range(1, 1000), 600);
        $this->assertSame(\range(1, 1000), $cache->memo()->pull('rows'));
    }

    public function test_async_swr_returns_a_readable_stale_lazily_loaded_value(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $cache = $this->lazySmartCache();
        Carbon::setTestNow(Carbon::now());

        $cache->asyncSwr('rows', V1171Rows::class, 60, 600);
        $fresh = $cache->asyncSwr('rows', V1171Rows::class, 60, 600);

        // Guard: a fresh value is still returned lazily.
        $this->assertInstanceOf(LazyChunkedCollection::class, $fresh);

        // Stale: on the sync queue the refresh job replaces the entry, and
        // deletes its chunks, before asyncSwr() returns.
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $stale = $cache->asyncSwr('rows', V1171Rows::class, 60, 600);

        $this->assertSame(\range(5001, 5300), $stale);
    }

    /** Guard. */
    public function test_guard_get_still_returns_a_lazily_loaded_value(): void
    {
        $cache = $this->lazySmartCache();
        $cache->put('rows', \range(1, 1000), 600);

        $value = $cache->get('rows');

        $this->assertInstanceOf(LazyChunkedCollection::class, $value);
        $this->assertSame(1, $value[0]);
        $this->assertCount(1000, $value);
    }

    public function test_a_lazily_loaded_value_can_be_cached_on_a_serializing_store(): void
    {
        // A store the cache manager builds, as Redis, file, or database
        // would be: its repository holds the event dispatcher.
        $this->app['config']->set('cache.stores.serialized', ['driver' => 'array', 'serialize' => true]);
        $cache = $this->chunkingCacheOn($this->app['cache']->store('serialized'), true);
        $cache->put('rows', \range(1, 1000), 600);

        // 1.17.0 threw "Serialization of 'Closure' is not allowed": the
        // collection holds the repository it reads its chunks from.
        $this->assertTrue($cache->put('copy', $cache->get('rows'), 600));
        $this->assertTrue($cache->forever('forever_copy', $cache->get('rows')));
        $this->assertTrue($cache->add('added_copy', $cache->get('rows'), 600));

        $cache->forget('rows');

        foreach (['copy', 'forever_copy', 'added_copy'] as $key) {
            $copy = $cache->get($key);
            $this->assertSame(\range(1, 1000), \iterator_to_array($copy), $key);
        }
    }

    public function test_a_cached_copy_of_a_lazily_loaded_value_outlives_its_source(): void
    {
        // Laravel's array store without serialization kept the collection
        // itself, still reading the source entry's chunks.
        $cache = $this->chunkingCacheOn($this->rawStore(), true);
        $cache->put('rows', \range(1, 1000), 600);
        $cache->put('copy', $cache->get('rows'), 600);

        $cache->forget('rows');

        $this->assertSame(\range(1, 1000), \iterator_to_array($cache->get('copy')));
    }

    // ------------------------------------------------------------------
    // Chunks left behind when the store throws
    // ------------------------------------------------------------------

    public function test_chunks_are_deleted_when_the_store_throws_on_a_chunk(): void
    {
        $store = new V1171FailingStore();
        $store->throwOnChunk = 3;
        $cache = $this->chunkingCacheOn(new Repository($store));
        $rows = $this->rows(1000);

        // The value falls back to compression, as when a chunk is rejected.
        $this->assertTrue($cache->forever('report', $rows));
        $this->assertSame($rows, $cache->get('report'));

        // 1.17.0 left the two chunks written before the failure, without
        // expiry and unregistered, so nothing ever removed them.
        $this->assertSame([], $store->chunkKeys());
    }

    public function test_chunks_of_a_failed_forever_write_are_registered_for_cleanup(): void
    {
        $store = new V1171FailingStore();
        $store->throwOnKey = 'report';
        $cache = $this->chunkingCacheOn(new Repository($store));

        try {
            $cache->forever('report', $this->rows(1000));
            $this->fail('The store error should reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Connection lost', $e->getMessage());
        }

        $this->assertNotSame([], $store->chunkKeys());

        // 1.17.0 did not register them, so cleanup never found them.
        $cache->cleanupOrphanChunks();

        $this->assertSame([], $store->chunkKeys());
    }

    /** Guard. */
    public function test_guard_cleanup_keeps_the_chunks_of_an_entry_stored_before_the_error(): void
    {
        // A dropped connection can report an error for a write the store made.
        $store = new V1171FailingStore();
        $store->throwOnKey = 'report';
        $store->storeBeforeThrowing = true;
        $cache = $this->chunkingCacheOn(new Repository($store));
        $rows = $this->rows(1000);

        try {
            $cache->forever('report', $rows);
        } catch (\RuntimeException $e) {
            // Expected.
        }

        $cache->cleanupOrphanChunks();

        $this->assertSame($rows, $cache->get('report'));
    }

    // ------------------------------------------------------------------
    // LazyChunkedCollection
    // ------------------------------------------------------------------

    private function lazyCollection(): LazyChunkedCollection
    {
        $repository = new Repository(new ArrayStore());
        $repository->put('c0', [1, 2, 3, 4, 5], 600);
        $repository->put('c1', [6, 7, 8, 9, 10], 600);

        return new LazyChunkedCollection($repository, ['c0', 'c1'], 5, 10);
    }

    public function test_lazy_methods_called_inside_a_foreach_do_not_end_it(): void
    {
        $lazy = $this->lazyCollection();

        foreach (['filter', 'map', 'each', 'json'] as $method) {
            $seen = [];

            foreach ($lazy as $item) {
                $seen[] = $item;

                match ($method) {
                    'filter' => $lazy->filter(fn ($value) => $value > $item),
                    'map' => $lazy->map(fn ($value) => $value * 2),
                    'each' => $lazy->each(fn ($value) => null),
                    'json' => \json_encode($lazy),
                };
            }

            // 1.17.0 rewound the collection, so the outer loop stopped after [1].
            $this->assertSame(\range(1, 10), $seen, $method);
        }

        $this->assertSame([2, 4, 6, 8, 10, 12, 14, 16, 18, 20], $lazy->map(fn ($value) => $value * 2));
        $this->assertSame([6, 7, 8, 9, 10], $lazy->filter(fn ($value) => $value > 5));
        $this->assertSame(\range(1, 10), \json_decode(\json_encode($lazy), true));
    }

    public function test_lazy_collections_read_integer_string_offsets(): void
    {
        $lazy = $this->lazyCollection();

        // As $array['5'] reads $array[5]; 1.17.0 returned null.
        $this->assertTrue(isset($lazy['5']));
        $this->assertSame(6, $lazy['5']);
        $this->assertSame(1, $lazy['0']);

        $this->assertFalse(isset($lazy['05']));
        $this->assertNull($lazy['05']);
        $this->assertNull($lazy['10']);
        $this->assertNull($lazy['first']);
    }

    // ------------------------------------------------------------------
    // Smart chunk sizing
    // ------------------------------------------------------------------

    public function test_smart_sizing_keeps_chunks_under_the_driver_limit(): void
    {
        $calculator = new SmartChunkSizeCalculator();
        $limits = ['dynamodb' => 400 * 1024, 'memcached' => 1024 * 1024];

        $datasets = [
            'ints' => \range(1, 300000),
            'rows' => \array_map(static fn (int $id): array => ['id' => $id], \range(1, 100000)),
        ];

        foreach ($limits as $driver => $limit) {
            foreach ($datasets as $name => $data) {
                $size = $calculator->calculateOptimalSize($data, $driver);
                $largest = \max(\array_map(
                    static fn (array $chunk): int => \strlen(\serialize($chunk)),
                    \array_chunk($data, $size, true)
                ));

                // 1.17.0 left keys out and sampled only the first items: the
                // integer chunks came out at 1.2 MB for DynamoDB's 400 KB.
                $this->assertLessThanOrEqual($limit, $largest, "{$name} on {$driver}");
            }
        }
    }

    // ------------------------------------------------------------------
    // Serialization strategy
    // ------------------------------------------------------------------

    public function test_a_corrupted_json_payload_is_evicted_instead_of_read_as_null(): void
    {
        $this->app['config']->set('smart-cache.self_healing.enabled', true);
        $cache = new SmartCache($this->rawStore(), $this->app['cache'], $this->app['config'], [
            new SmartSerializationStrategy('json', false, 16),
        ]);

        $this->rawStore()->put('profile', ['_sc_serialized' => true, 'method' => 'json', 'data' => '{"name":'], 600);

        // 1.17.0 returned null as a cache hit.
        $this->assertSame('regenerated', $cache->remember('profile', 600, fn () => 'regenerated'));
        $this->assertSame('regenerated', $cache->get('profile'));
    }

    /** Guard. */
    public function test_guard_json_payloads_still_round_trip(): void
    {
        $strategy = new SmartSerializationStrategy('json', false, 16);

        foreach ([['a' => 1, 'b' => [true, null, 1.0]], 'text', 0, []] as $value) {
            $this->assertSame($value, $strategy->restore($strategy->optimize($value)));
        }
    }

    // ------------------------------------------------------------------
    // Console commands
    // ------------------------------------------------------------------

    public function test_clear_with_key_zero_clears_only_that_key(): void
    {
        $cache = $this->app->make(SmartCacheContract::class);
        $cache->put('0', 'zero', 600);
        $cache->put('other', 'kept', 600);
        $this->rawStore()->put('foreign', 'kept', 600);

        // 1.17.0 read "0" as no key: it cleared every managed key, and with
        // --force every other key in the store too.
        $this->artisan('smart-cache:clear', ['key' => '0', '--force' => true])->assertExitCode(0);

        $this->assertFalse($cache->has('0'));
        $this->assertSame('kept', $cache->get('other'));
        $this->assertSame('kept', $this->rawStore()->get('foreign'));

        foreach (['0', 0] as $key) {
            $cache->put('0', 'zero', 600);
            $result = $cache->executeCommand('clear', ['key' => $key]);

            $this->assertTrue($result['success']);
            $this->assertSame(1, $result['cleared_count']);
            $this->assertSame('kept', $cache->get('other'));
        }
    }

    public function test_audit_does_not_report_the_chunk_registry_as_an_orphan(): void
    {
        $cache = $this->app->make(SmartCacheContract::class);
        $cache->forever('report', $this->rows(1500));
        $this->assertNotNull($this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY));

        $command = new \SmartCache\Console\Commands\AuditCommand();
        $command->setLaravel($this->app);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $this->assertSame(0, $tester->execute(['--format' => 'json']));
        $report = \json_decode($tester->getDisplay(), true);

        // 1.17.0 listed _sc_chunk_registry and reported a warning.
        $this->assertSame(0, $report['summary']['orphan_chunks']);
        $this->assertSame('good', $report['health']);
    }

    public function test_status_force_says_when_it_cannot_scan_the_store(): void
    {
        $this->app->instance(SmartCacheContract::class, new SmartCache(
            $this->app['cache']->store('file'),
            $this->app['cache'],
            $this->app['config']
        ));

        // 1.17.0 printed "No non-managed cache keys found." for a store it
        // could not list.
        $this->artisan('smart-cache:status', ['--force' => true])
            ->expectsOutput('Could not scan for non-managed keys with this cache driver.')
            ->assertExitCode(0);
    }

    public function test_warm_selects_a_warmer_from_a_list(): void
    {
        $this->app['config']->set('smart-cache.warmers', [V1171Warmer::class]);

        // 1.17.0 compared the integer key with the string option: nothing ran.
        $this->artisan('smart-cache:warm', ['--warmer' => ['0']])->assertExitCode(0);

        $this->assertTrue(V1171Warmer::$warmed);
    }

    // ------------------------------------------------------------------
    // Rate limiter
    // ------------------------------------------------------------------

    public function test_a_rate_limit_counter_recreated_at_the_window_boundary_expires(): void
    {
        Carbon::setTestNow(Carbon::now());
        $repository = new V1171WindowEndingRepository(new ArrayStore());
        $limiter = new RateLimiter($repository, 10, 2);

        $this->assertTrue($limiter->attempt('k'));

        // The window ends between add() and increment(): the store creates
        // the counter again, without expiry.
        $repository->secondsToSkip = 11;
        $this->assertTrue($limiter->attempt('k'));
        $repository->secondsToSkip = 0;

        Carbon::setTestNow(Carbon::now()->addSeconds(11));

        // 1.17.0 kept that counter forever: the key never got its attempts back.
        $this->assertSame(2, $limiter->remaining('k', 2));
    }

    /** Guard. */
    public function test_guard_the_rate_limiter_still_limits_within_the_window(): void
    {
        Carbon::setTestNow(Carbon::now());
        $limiter = new RateLimiter(new Repository(new ArrayStore()), 10, 2);

        $this->assertTrue($limiter->attempt('k'));
        $this->assertTrue($limiter->attempt('k'));
        $this->assertFalse($limiter->attempt('k'));

        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->assertTrue($limiter->attempt('k'));
    }

    // ------------------------------------------------------------------
    // Shared circuit breaker
    // ------------------------------------------------------------------

    private function sharedBreaker(): CircuitBreaker
    {
        return (new CircuitBreaker(3, 30, 1))->enableSharedState($this->rawStore(), 'cb', 300);
    }

    public function test_a_success_in_flight_does_not_close_a_breaker_another_process_opened(): void
    {
        $worker = $this->sharedBreaker();
        $other = $this->sharedBreaker();

        $worker->recordFailure(new \RuntimeException('blip'));
        $this->assertTrue($worker->isAvailable()); // its call starts here

        $other->isAvailable();
        $other->recordFailure(new \RuntimeException('down'));
        $other->recordFailure(new \RuntimeException('down'));
        $this->assertFalse($other->isAvailable());

        $worker->recordSuccess(); // the call that started earlier succeeds

        // 1.17.0 wrote "closed" over the open breaker.
        $this->assertFalse($this->sharedBreaker()->isAvailable());
    }

    public function test_a_failure_in_flight_does_not_close_a_breaker_another_process_opened(): void
    {
        $worker = $this->sharedBreaker();
        $other = $this->sharedBreaker();

        $this->assertTrue($worker->isAvailable()); // its call starts here

        foreach ([1, 2, 3] as $attempt) {
            $other->recordFailure(new \RuntimeException('down'));
        }
        $this->assertFalse($other->isAvailable());

        $worker->recordFailure(new \RuntimeException('down')); // and fails

        // 1.17.0 wrote "closed" with one failure over the open breaker.
        $this->assertFalse($this->sharedBreaker()->isAvailable());
    }

    // ------------------------------------------------------------------
    // Queued refresh callbacks
    // ------------------------------------------------------------------

    public function test_the_closure_error_names_the_array_callbacks_that_work(): void
    {
        try {
            new BackgroundCacheRefreshJob('k', fn () => 'v');
            $this->fail('A Closure should be rejected.');
        } catch (\InvalidArgumentException $e) {
            // An instance method array is not callable, so the type rejects it.
            $this->assertStringContainsString('[Class::class, "staticMethod"]', $e->getMessage());
        }
    }
}
