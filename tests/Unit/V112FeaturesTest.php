<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Services\OrphanChunkCleanupService;
use SmartCache\SmartCache;
use SmartCache\Tests\TestCase;

/**
 * Tests for the v1.12.0 hardening and Octane-friendliness features.
 *
 * Every feature exercised here is opt-in or BC-safe — these tests also
 * serve as the executable specification for the new configuration keys.
 */
class V112FeaturesTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The base TestCase disables self-healing for legacy fallback tests;
        // this suite explicitly opts into the v1.12.0 hardening behaviours.
        $app['config']->set('smart-cache.self_healing.enabled', true);
        $app['config']->set('smart-cache.swr.single_flight', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCache = $this->app->make(SmartCacheContract::class);
    }

    public function test_self_healing_evicts_chunked_wrapper_when_chunks_are_missing(): void
    {
        // Manually plant a chunked wrapper that references chunks which do not exist
        // — simulating partial cache loss on a multi-chunk payload.
        $store = $this->app['cache']->store();
        $store->put('broken_chunked', [
            '_sc_chunked' => true,
            'chunk_keys' => ['broken_chunked_chunk_0', 'broken_chunked_chunk_1'],
            'total_items' => 200,
            'chunk_size' => 100,
            'is_collection' => false,
        ], 60);
        // Leftover orphan chunk that must also be evicted on self-heal.
        $store->put('broken_chunked_chunk_0', ['only' => 'one-chunk-left'], 60);

        $this->assertNull($this->smartCache->get('broken_chunked'));
        $this->assertFalse($store->has('broken_chunked'), 'wrapper key must be evicted');
        $this->assertFalse($store->has('broken_chunked_chunk_0'), 'leftover chunk must be evicted');
    }

    public function test_self_healing_evicts_corrupted_compressed_payload(): void
    {
        // Plant a compressed wrapper whose base64 payload is garbage so CompressionStrategy throws.
        $store = $this->app['cache']->store();
        $store->put('broken_compressed', [
            '_sc_compressed' => true,
            'data' => 'NOT_BASE64!!!@@@',
            'is_string' => true,
        ], 60);

        $this->assertNull($this->smartCache->get('broken_compressed'));
        $this->assertFalse($store->has('broken_compressed'), 'corrupted wrapper must be evicted');
    }

    public function test_swr_single_flight_lock_serialises_concurrent_refreshes_on_lock_provider(): void
    {
        // Array store implements LockProvider, so single-flight kicks in here.
        $this->app['config']->set('smart-cache.swr.single_flight', true);

        // Seed a stale entry.
        $store = $this->app['cache']->store();
        $store->put('swr_lock_key', 'stale-value', 600);
        $store->put('_sc_meta:swr_lock_key', [
            'stored_at' => time() - 7200,
            'created_at' => time() - 7200,
            'fresh_ttl' => 60,
        ], 600);

        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            // Hold the lock long enough that the second call would race in a non-locked world.
            return 'refreshed-' . $callCount;
        };

        // First call performs refresh under lock; second call (same process, lock still held)
        // sees the lock as busy via a manual pre-acquisition.
        $store = $this->app['cache']->store()->getStore();
        $lock = $store->lock('_sc_swr_refresh:swr_lock_key', 30);
        $this->assertTrue($lock->get(), 'precondition: outer test must hold the refresh lock');

        try {
            $value = $this->smartCache->swr('swr_lock_key', $callback, 60, 7200);
            // Stale value is served and the callback is NOT invoked because the lock is busy.
            $this->assertSame('stale-value', $value);
            $this->assertSame(0, $callCount, 'callback must be skipped while another worker holds the lock');
        } finally {
            $lock->release();
        }
    }

    public function test_swr_single_flight_can_be_disabled(): void
    {
        $this->app['config']->set('smart-cache.swr.single_flight', false);

        $store = $this->app['cache']->store();
        $store->put('swr_nolock_key', 'stale-value', 600);
        $store->put('_sc_meta:swr_nolock_key', [
            'stored_at' => time() - 7200,
            'created_at' => time() - 7200,
            'fresh_ttl' => 60,
        ], 600);

        $callCount = 0;
        $value = $this->smartCache->swr('swr_nolock_key', function () use (&$callCount) {
            $callCount++;
            return 'refreshed';
        }, 60, 7200);

        // Without single-flight, the refresh runs synchronously even if a lock would have existed.
        $this->assertSame('stale-value', $value);
        $this->assertSame(1, $callCount);
    }

    public function test_reset_clears_per_request_state_for_octane(): void
    {
        $this->smartCache->namespace('tenant_a')->put('foo', 'bar', 60);
        $this->smartCache->tags(['users']);

        $this->assertSame('tenant_a', $this->smartCache->getNamespace());

        $this->smartCache->reset();

        $this->assertNull($this->smartCache->getNamespace(), 'namespace must be cleared after reset()');
        // tags() must not leak — confirm by reading the un-prefixed key works.
        $this->smartCache->put('after_reset', 'value', 60);
        $this->assertSame('value', $this->smartCache->get('after_reset'));
    }

    public function test_background_cache_refresh_job_rejects_closures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept Closures');

        new BackgroundCacheRefreshJob('some_key', fn () => 'value');
    }

    public function test_managed_keys_index_is_bounded_when_max_tracked_is_configured(): void
    {
        $this->app['config']->set('smart-cache.managed_keys.max_tracked', 10);
        // Re-resolve so the SmartCache reads the new config (cap is read per-call).
        $cache = $this->app->make(SmartCacheContract::class);

        for ($i = 0; $i < 25; $i++) {
            $cache->put("cap_key_{$i}", $i, 60);
        }

        $managed = $cache->getManagedKeys();
        $this->assertLessThanOrEqual(10, \count($managed),
            'managed_keys.max_tracked must cap the in-memory index');
        // Newest writes win: the most recent key must be present.
        $this->assertContains('cap_key_24', $managed);
    }

    public function test_managed_keys_cap_defaults_to_unlimited_for_backwards_compatibility(): void
    {
        // No max_tracked configured — historical behaviour must be preserved.
        $this->app['config']->set('smart-cache.managed_keys.max_tracked', 0);
        $cache = $this->app->make(SmartCacheContract::class);

        for ($i = 0; $i < 50; $i++) {
            $cache->put("uncapped_key_{$i}", $i, 60);
        }

        $this->assertGreaterThanOrEqual(50, \count($cache->getManagedKeys()));
    }

    public function test_orphan_chunk_cleanup_service_debounces_registry_writes(): void
    {
        $store = $this->app['cache']->store();
        $service = new OrphanChunkCleanupService($store, persistEvery: 5);

        // First four registrations buffer in memory; nothing persists yet.
        for ($i = 0; $i < 4; $i++) {
            $service->registerChunks("debounce_key_{$i}", ["debounce_key_{$i}_chunk_0"]);
        }
        $this->assertNull($store->get('_sc_chunk_registry'),
            'registry must not flush before the debounce threshold is reached');

        // Fifth registration triggers a persist.
        $service->registerChunks('debounce_key_4', ['debounce_key_4_chunk_0']);
        $persisted = $store->get('_sc_chunk_registry');
        $this->assertIsArray($persisted);
        $this->assertCount(5, $persisted);

        // flush() drains any remaining pending changes on shutdown.
        $service->registerChunks('debounce_key_5', ['debounce_key_5_chunk_0']);
        $service->flush();
        $persisted = $store->get('_sc_chunk_registry');
        $this->assertCount(6, $persisted);
    }

    public function test_circuit_breaker_shared_state_is_visible_to_other_instances(): void
    {
        $store = $this->app['cache']->store();
        $key = '_sc_circuit_breaker:test';

        $writer = (new CircuitBreaker(failureThreshold: 2, recoveryTimeout: 30, successThreshold: 1))
            ->enableSharedState($store, $key, 300);

        // Two failures trip the breaker.
        $writer->recordFailure(new \RuntimeException('one'));
        $writer->recordFailure(new \RuntimeException('two'));
        $this->assertSame(CircuitBreaker::STATE_OPEN, $writer->getState());

        // A fresh instance pointed at the same shared key must see the OPEN state.
        $reader = (new CircuitBreaker(failureThreshold: 2, recoveryTimeout: 30, successThreshold: 1))
            ->enableSharedState($store, $key, 300);

        $this->assertSame(CircuitBreaker::STATE_OPEN, $reader->getState());
        $this->assertFalse($reader->isAvailable(), 'reader must honour the shared OPEN state');
    }

    public function test_circuit_breaker_without_shared_state_remains_per_instance(): void
    {
        $instanceA = new CircuitBreaker(failureThreshold: 2, recoveryTimeout: 30, successThreshold: 1);
        $instanceB = new CircuitBreaker(failureThreshold: 2, recoveryTimeout: 30, successThreshold: 1);

        $instanceA->recordFailure();
        $instanceA->recordFailure();

        $this->assertSame(CircuitBreaker::STATE_OPEN, $instanceA->getState());
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $instanceB->getState(),
            'without shared state, breakers stay independent (historical behaviour)');
    }

    public function test_smart_cache_swr_metrics_ttl_is_honoured(): void
    {
        // SWR sets the data and meta key with the totalTtl so the wrapper does not
        // outlive the metadata.  Verify both keys exist after the SWR call.
        $key = 'metrics_ttl_key';

        $this->smartCache->swr($key, fn () => 'fresh-value', 60, 600);

        $store = $this->app['cache']->store();
        $this->assertNotNull($store->get($key));
        $this->assertNotNull($store->get("_sc_meta:{$key}"),
            'SWR metadata key must be present so subsequent calls can detect staleness');
    }
}
