<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Support\Carbon;
use SmartCache\Tests\TestCase;
use SmartCache\SmartCache;
use SmartCache\Drivers\MemoizedCacheDriver;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Traits\CacheInvalidation;

/**
 * Regression coverage for defects found in the 1.13.x audit pass.
 *
 * Each test fails against the pre-fix implementation.
 */
class BugFixRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function makeCache(array $overrides = [], array $strategies = []): SmartCache
    {
        $config = $this->app['config'];

        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }

        return new SmartCache(
            $this->app['cache']->store('array'),
            $this->app['cache'],
            $config,
            $strategies
        );
    }

    // -----------------------------------------------------------------
    // Namespace handling
    // -----------------------------------------------------------------

    public function test_clear_removes_keys_written_under_an_active_namespace(): void
    {
        $cache = $this->makeCache();

        $cache->namespace('tenant1');
        $cache->put('report', 'data', 3600);

        $this->assertContains('tenant1:report', $cache->getManagedKeys());

        // clear() ran while the namespace was still active; managed keys are
        // already fully qualified, so re-applying it targeted 'tenant1:tenant1:report'.
        $cache->clear();

        $this->assertNull($this->app['cache']->store('array')->get('tenant1:report'));
    }

    public function test_cleanup_expired_managed_keys_keeps_live_namespaced_keys(): void
    {
        $cache = $this->makeCache();

        $cache->namespace('tenant1');
        $cache->put('alive', 'data', 3600);

        // Every live key was previously reported as expired and dropped from the
        // index, so later invalidation could never find it again.
        $this->assertSame(0, $cache->cleanupExpiredManagedKeys());
        $this->assertContains('tenant1:alive', $cache->getManagedKeys());
    }

    public function test_flush_patterns_matches_namespaced_keys(): void
    {
        $cache = $this->makeCache();

        $cache->namespace('tenant1');
        $cache->put('report', 'data', 3600);

        $this->assertSame(1, $cache->flushPatterns(['tenant1:*']));
        $this->assertNull($this->app['cache']->store('array')->get('tenant1:report'));
    }

    // -----------------------------------------------------------------
    // Write deduplication (Cache DNA)
    // -----------------------------------------------------------------

    public function test_repeated_put_with_unchanged_value_still_extends_ttl(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        $store = $this->app['cache']->store('array')->getStore();
        $storage = new \ReflectionProperty(get_class($store), 'storage');

        $cache->put('heartbeat', 'alive', 3600);
        $first = $storage->getValue($store)['heartbeat']['expiresAt'];

        $cache->put('heartbeat', 'alive', 7200);
        $second = $storage->getValue($store)['heartbeat']['expiresAt'];

        $this->assertGreaterThan($first, $second);
        $this->assertSame('alive', $cache->get('heartbeat'));
    }

    public function test_dedup_still_skips_a_write_already_covered_by_the_stored_expiry(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        $cache->put('cfg', ['a' => 1], 7200);

        // Asking for a shorter window than what is already stored is a genuine
        // no-op, so the write is still skipped.
        $this->assertTrue($cache->put('cfg', ['a' => 1], 60));

        $metrics = $cache->getPerformanceMetrics()['metrics'];
        $this->assertArrayHasKey('cache_write_dedup', $metrics);
    }

    public function test_rewriting_the_same_value_and_ttl_later_still_moves_the_expiry(): void
    {
        // The headline case: a job that refreshes a key on every pass with the
        // same TTL and unchanged content. The window has to restart each time.
        Carbon::setTestNow('2026-01-01 00:00:00');

        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);
        $store = $this->app['cache']->store('array');

        $cache->put('heartbeat', 'alive', 3600);
        $firstExpiry = $store->get('_sc_dna:heartbeat')['e'];

        Carbon::setTestNow('2026-01-01 00:00:30');
        $cache->put('heartbeat', 'alive', 3600);
        $secondExpiry = $store->get('_sc_dna:heartbeat')['e'];

        $this->assertSame(30, $secondExpiry - $firstExpiry);
        $this->assertSame('alive', $cache->get('heartbeat'));
    }

    public function test_put_with_a_non_positive_ttl_still_deletes(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        // Laravel treats ttl <= 0 as a delete; dedup must not swallow it because
        // a live entry trivially "covers" a past expiry.
        $cache->put('zero', 'v', 3600);
        $cache->put('zero', 'v', 0);
        $this->assertNull($cache->get('zero'));

        $cache->put('past', 'v', 3600);
        $cache->put('past', 'v', new \DateTime('-10 seconds'));
        $this->assertNull($cache->get('past'));
    }

    public function test_forever_invalidates_the_dedup_record(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        $cache->put('k', 'Y', 3600);   // records the hash of 'Y'
        $cache->forever('k', 'X');     // value is now 'X'

        // Writing 'Y' again must not be skipped against the stale 'Y' hash.
        $cache->put('k', 'Y', 60);

        $this->assertSame('Y', $cache->get('k'));
    }

    public function test_touch_invalidates_the_dedup_record(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);
        $store = $this->app['cache']->store('array')->getStore();
        $storage = new \ReflectionProperty(get_class($store), 'storage');

        $cache->put('t', 'v', 3600);
        $cache->touch('t', 30);        // expiry moved; the recorded expiry is now stale

        $cache->put('t', 'v', 1800);

        $remaining = $storage->getValue($store)['t']['expiresAt'] - time();
        $this->assertGreaterThan(1000, $remaining);
    }

    public function test_increment_invalidates_the_dedup_record(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        $cache->put('counter', 1, 3600);
        $this->assertSame(2, $cache->increment('counter'));

        // The DNA record still described the original value, so this write was
        // incorrectly skipped and left the incremented value in the store.
        $cache->put('counter', 1, 3600);

        $this->assertSame(1, $cache->get('counter'));
    }

    public function test_decrement_invalidates_the_dedup_record(): void
    {
        $cache = $this->makeCache(['smart-cache.deduplication.enabled' => true]);

        $cache->put('counter', 2, 3600);
        $this->assertSame(1, $cache->decrement('counter'));

        $cache->put('counter', 2, 3600);

        $this->assertSame(2, $cache->get('counter'));
    }

    public function test_new_internal_helpers_do_not_expand_the_subclass_contract(): void
    {
        $cache = new class(
            $this->app['cache']->store('array'),
            $this->app['cache'],
            $this->app['config'],
            []
        ) extends SmartCache {
            // Existing downstream subclasses may already use generic helper
            // names. Private implementation details in the parent must not
            // impose signature or property compatibility on them.
            protected string $skipNextConfiguredJitter = 'downstream';

            protected function putValue(): string
            {
                return 'downstream';
            }

            protected function currentTimestamp(): string
            {
                return 'downstream';
            }
        };

        $this->assertTrue($cache->put('subclass-safe', 'value', 60));
    }

    // -----------------------------------------------------------------
    // TTL jitter
    // -----------------------------------------------------------------

    public function test_put_with_jitter_applies_jitter_when_global_flag_is_off(): void
    {
        $cache = $this->makeCache([
            'smart-cache.jitter.enabled' => false,
            'smart-cache.deduplication.enabled' => false,
        ]);

        $store = $this->app['cache']->store('array')->getStore();
        $storage = new \ReflectionProperty(get_class($store), 'storage');

        $expiries = [];
        for ($i = 0; $i < 25; $i++) {
            $cache->putWithJitter("jitter_{$i}", 'v', 1000, 0.5);
            $expiries[] = (int) round($storage->getValue($store)["jitter_{$i}"]['expiresAt']);
        }

        // Calling putWithJitter() is itself the opt-in; the global flag must not
        // silently turn it into a plain put().
        $this->assertGreaterThan(1, count(array_unique($expiries)));
    }

    public function test_remember_with_jitter_still_returns_the_value(): void
    {
        $cache = $this->makeCache(['smart-cache.jitter.enabled' => false]);

        $this->assertSame('generated', $cache->rememberWithJitter('rj', 1000, 0.5, fn () => 'generated'));
        $this->assertSame('generated', $cache->get('rj'));
    }

    public function test_put_with_jitter_is_not_jittered_again_by_the_global_setting(): void
    {
        Carbon::setTestNow('2030-01-01 00:00:00');
        mt_srand(1234);

        $cache = $this->makeCache([
            'smart-cache.jitter.enabled' => true,
            'smart-cache.jitter.percentage' => 1.0,
            'smart-cache.deduplication.enabled' => false,
        ]);

        $cache->putWithJitter('single-jitter', 'v', 1000, 0.0);

        $store = $this->app['cache']->store('array')->getStore();
        $storage = new \ReflectionProperty(get_class($store), 'storage');
        $expiresAt = $storage->getValue($store)['single-jitter']['expiresAt'];

        $this->assertSame(1000, (int) $expiresAt - Carbon::now()->timestamp);
    }

    public function test_remember_with_jitter_is_not_jittered_again_by_the_global_setting(): void
    {
        Carbon::setTestNow('2030-01-01 00:00:00');
        mt_srand(1234);

        $cache = $this->makeCache([
            'smart-cache.jitter.enabled' => true,
            'smart-cache.jitter.percentage' => 1.0,
            'smart-cache.deduplication.enabled' => false,
        ]);

        $cache->rememberWithJitter('single-remember-jitter', 1000, 0.0, fn () => 'v');

        $store = $this->app['cache']->store('array')->getStore();
        $storage = new \ReflectionProperty(get_class($store), 'storage');
        $expiresAt = $storage->getValue($store)['single-remember-jitter']['expiresAt'];

        $this->assertSame(1000, (int) $expiresAt - Carbon::now()->timestamp);
    }

    // -----------------------------------------------------------------
    // Corrupted internal metadata must not fatal
    // -----------------------------------------------------------------

    public function test_corrupt_performance_metrics_entry_does_not_fatal(): void
    {
        $this->app['cache']->store('array')->forever('_sc_performance_metrics', 'corrupted-string');

        $cache = $this->makeCache(['smart-cache.monitoring.enabled' => true]);

        $this->assertNull($cache->get('anything'));
    }

    public function test_health_check_removes_a_chunk_marker_with_no_chunk_list(): void
    {
        $store = $this->app['cache']->store('array');
        $store->forever('_sc_managed_keys', ['broken-chunk']);
        $store->forever('broken-chunk', ['_sc_chunked' => true]);

        $cache = $this->makeCache();
        $result = $cache->healthCheck();

        $this->assertSame(1, $result['orphaned_chunks_cleaned']);
        $this->assertFalse($store->has('broken-chunk'));
    }

    // -----------------------------------------------------------------
    // Wildcard pattern matching in the model trait
    // -----------------------------------------------------------------

    public function test_trait_wildcard_pattern_matches_like_the_invalidation_service(): void
    {
        $subject = new class {
            use CacheInvalidation;

            public function match(string $key, string $pattern): bool
            {
                return $this->matchesPattern($key, $pattern);
            }
        };

        // preg_quote escapes the wildcard first; replacing the bare '*' turned
        // 'user_*' into '/^user_\.*$/' (a literal dot) and matched nothing.
        $this->assertTrue($subject->match('user_123', 'user_*'));
        $this->assertTrue($subject->match('user_', 'user_*'));
        $this->assertTrue($subject->match('user_1', 'user_?'));
        $this->assertFalse($subject->match('other_123', 'user_*'));
        $this->assertFalse($subject->match('user_12', 'user_?'));

        // Regex metacharacters in the literal part stay escaped.
        $this->assertTrue($subject->match('a.b_1', 'a.b_*'));
        $this->assertFalse($subject->match('axb_1', 'a.b_*'));
    }

    // -----------------------------------------------------------------
    // Memoization driver
    // -----------------------------------------------------------------

    public function test_memoized_driver_does_not_treat_a_value_equal_to_the_default_as_a_miss(): void
    {
        $repository = $this->app['cache']->store('array');
        $repository->put('flag', false, 3600);

        $memo = new MemoizedCacheDriver($repository);

        // Reading with a default equal to the stored value used to record a miss.
        $this->assertFalse($memo->get('flag', false));
        $this->assertTrue($memo->has('flag'));
        $this->assertFalse($memo->get('flag'));
    }

    public function test_memoized_driver_releases_access_order_entries_on_write(): void
    {
        $repository = $this->app['cache']->store('array');
        $memo = new MemoizedCacheDriver($repository, 10);

        for ($i = 0; $i < 500; $i++) {
            $repository->put("k{$i}", $i, 3600);
            $memo->get("k{$i}");
            $memo->put("k{$i}", $i, 3600);
        }

        $order = new \ReflectionProperty(MemoizedCacheDriver::class, 'accessOrder');

        // accessOrder was never cleared on write, so it grew unbounded in
        // long-running workers.
        $this->assertLessThanOrEqual(10, count($order->getValue($memo)));
    }

    public function test_memoized_driver_bounds_the_missing_key_map(): void
    {
        $memo = new MemoizedCacheDriver($this->app['cache']->store('array'), 10);

        for ($i = 0; $i < 500; $i++) {
            $memo->get("absent_{$i}");
        }

        $missing = new \ReflectionProperty(MemoizedCacheDriver::class, 'memoizedMissing');

        $this->assertLessThanOrEqual(10, count($missing->getValue($memo)));
    }

    // -----------------------------------------------------------------
    // Compression strategy error-handler balance
    // -----------------------------------------------------------------

    public function test_compression_restore_leaves_the_error_handler_stack_balanced(): void
    {
        $strategy = new CompressionStrategy(10, 6);
        $envelope = $strategy->optimize(str_repeat('abc', 500), []);

        $marker = static fn (): bool => true;
        set_error_handler($marker);

        for ($i = 0; $i < 5; $i++) {
            $strategy->restore($envelope, []);
        }

        // Our handler must still be the active one: restore() previously pushed a
        // second frame per call instead of popping its own.
        $active = set_error_handler(null);
        restore_error_handler();

        restore_error_handler();

        $this->assertSame($marker, $active);
    }

    // -----------------------------------------------------------------
    // Lazy chunked collection
    // -----------------------------------------------------------------

    public function test_lazy_chunked_collection_returns_every_item(): void
    {
        $cache = $this->makeCache(
            ['smart-cache.deduplication.enabled' => false],
            [new ChunkingStrategy(threshold: 100, chunkSize: 10, lazyLoading: true)]
        );

        $data = range(0, 34);
        $cache->put('rows', $data, 3600);

        $restored = $cache->get('rows');

        // Chunks preserve the original keys, but access is positional; indexing a
        // chunk by absolute position returned null for everything past chunk 0.
        $this->assertSame($data, iterator_to_array($restored, false));
        $this->assertSame(12, $restored[12]);
        $this->assertSame([10, 11, 12, 13, 14], $restored->slice(10, 5));
        $this->assertSame($data, $restored->toArray());
    }

    public function test_to_array_keeps_every_item_for_chunk_relative_keys(): void
    {
        $cache = $this->app['cache']->store('array');

        // A collection assembled by hand from 0-based chunks (the class has a
        // public constructor). Preserving keys alone would drop the first chunk.
        $cache->put('rel_0', ['a', 'b'], 60);
        $cache->put('rel_1', ['c', 'd'], 60);

        $collection = new \SmartCache\Collections\LazyChunkedCollection(
            $cache,
            ['rel_0', 'rel_1'],
            2,
            4
        );

        $this->assertSame(['a', 'b', 'c', 'd'], $collection->toArray());
    }
}
