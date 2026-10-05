<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Drivers\MemoizedCacheDriver;
use SmartCache\Events\OptimizationApplied;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\SmartCache;
use SmartCache\Strategies\AdaptiveCompressionStrategy;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\EncryptionStrategy;
use SmartCache\Tests\TestCase;
use SmartCache\Traits\CacheInvalidation;

/**
 * Regression coverage for the defects fixed in 1.15.0.
 *
 * Each test fails against the 1.14.0 implementation.
 */
class V115FixesTest extends TestCase
{
    protected SmartCache $smartCache;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('smart-cache.strategies.encryption', [
            'enabled' => true,
            'keys' => ['secret_report'],
            'patterns' => ['/^token_/'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->smartCache = $this->app->make(SmartCacheContract::class);
    }

    protected function tearDown(): void
    {
        $this->smartCache->withoutNamespace();

        parent::tearDown();
    }

    protected function rawStore()
    {
        return $this->app['cache']->store();
    }

    protected function selfHealingCache(array $strategies): SmartCache
    {
        $this->app['config']->set('smart-cache.self_healing.enabled', true);

        return new SmartCache($this->rawStore(), $this->app['cache'], $this->app['config'], $strategies);
    }

    // -----------------------------------------------------------------
    // Laravel Repository parity
    // -----------------------------------------------------------------

    public function test_get_calls_a_closure_default_on_a_miss(): void
    {
        $this->assertSame('computed', $this->smartCache->get('missing', fn () => 'computed'));

        $this->smartCache->put('present', 'stored', 60);
        $this->assertSame('stored', $this->smartCache->get('present', function () {
            $this->fail('The default must not be called on a hit.');
        }));
    }

    public function test_pull_calls_a_closure_default_on_a_miss(): void
    {
        $this->assertSame('computed', $this->smartCache->pull('missing', fn () => 'computed'));
    }

    public function test_get_with_an_array_of_keys_behaves_like_many(): void
    {
        $this->smartCache->put('a', 1, 60);

        $this->assertSame(['a' => 1, 'b' => null], $this->smartCache->get(['a', 'b']));
    }

    public function test_put_with_an_array_behaves_like_put_many(): void
    {
        $this->assertTrue($this->smartCache->put(['x' => 1, 'y' => 2], 60));

        $this->assertSame(1, $this->smartCache->get('x'));
        $this->assertSame(2, $this->smartCache->get('y'));
    }

    public function test_many_accepts_key_default_pairs(): void
    {
        $this->smartCache->put('a', 1, 60);

        $this->assertSame(
            ['a' => 1, 'missing' => 'fallback', 'b' => null],
            $this->smartCache->many(['a' => 'unused', 'missing' => 'fallback', 'b'])
        );
    }

    public function test_memoized_driver_honours_defaults_in_batch_reads(): void
    {
        $memo = new MemoizedCacheDriver($this->rawStore());
        $memo->put('a', 1, 60);

        $this->assertSame(['a' => 1, 'missing' => 'def'], $memo->getMultiple(['a', 'missing'], 'def'));
        $this->assertSame(['missing' => 'def'], $memo->many(['missing' => 'def']));
    }

    // -----------------------------------------------------------------
    // add() must not touch a live entry
    // -----------------------------------------------------------------

    public function test_failed_add_leaves_an_existing_chunked_entry_intact(): void
    {
        $original = range(1, 2500);
        $replacement = range(5001, 7100);

        $this->smartCache->put('big', $original, 60);
        $this->assertValueIsChunked($this->rawStore()->get('big'));

        $this->assertFalse($this->smartCache->add('big', $replacement, 60));
        $this->assertSame($original, $this->smartCache->get('big'));
    }

    // -----------------------------------------------------------------
    // Namespaces
    // -----------------------------------------------------------------

    public function test_memo_keeps_the_active_namespace(): void
    {
        $this->smartCache->put('profile', 'global', 60);
        $this->smartCache->namespace('tenant1')->put('profile', 'tenant', 60);

        $this->assertSame('tenant', $this->smartCache->namespace('tenant1')->memo()->get('profile'));
    }

    public function test_async_refresh_job_writes_the_namespaced_key_in_a_queue_worker(): void
    {
        Bus::fake();

        $this->smartCache->namespace('tenant1')->refreshAsync('feed', RefreshFeed::class, 600);
        $job = Bus::dispatched(BackgroundCacheRefreshJob::class)->first();
        $this->assertNotNull($job);

        // A queue worker runs the job without any namespace.
        $this->smartCache->withoutNamespace();
        $job->handle();

        $this->assertFalse($this->smartCache->has('feed'), 'the global key must not be written');
        $this->assertSame('fresh', $this->smartCache->namespace('tenant1')->get('feed'));
    }

    public function test_async_refresh_job_restores_the_callers_namespace(): void
    {
        $job = new BackgroundCacheRefreshJob('feed', RefreshFeed::class, 600, [], 'tenant1');

        // A sync-queue job runs inside a request that may use another namespace.
        $this->smartCache->namespace('tenant2');
        $job->handle();

        $this->assertSame('tenant2', $this->smartCache->getNamespace());
        $this->assertFalse($this->smartCache->has('feed'));
        $this->assertSame('fresh', $this->smartCache->namespace('tenant1')->get('feed'));
    }

    public function test_async_swr_queues_one_refresh_per_stale_window(): void
    {
        Bus::fake();

        $this->smartCache->put('feed', 'stale', 900);
        $this->rawStore()->put('_sc_meta:feed', ['created_at' => time() - 1000, 'stored_at' => time() - 1000], 900);

        $this->assertSame('stale', $this->smartCache->asyncSwr('feed', RefreshFeed::class, 300, 900));
        $this->assertSame('stale', $this->smartCache->asyncSwr('feed', RefreshFeed::class, 300, 900));

        Bus::assertDispatchedTimes(BackgroundCacheRefreshJob::class, 1);
    }

    public function test_model_pattern_invalidation_works_under_a_namespace(): void
    {
        $this->smartCache->namespace('tenant1')->put('users_list_1', 'cached', 60);

        $model = new class {
            use CacheInvalidation;

            public function flushPattern(string $pattern): void
            {
                $this->invalidatePattern($pattern);
            }
        };
        $model->flushPattern('*users_list_*');

        $this->assertSame('tenant1', $this->smartCache->getNamespace());
        $this->assertFalse($this->smartCache->has('users_list_1'));
    }

    // -----------------------------------------------------------------
    // Encryption
    // -----------------------------------------------------------------

    public function test_large_values_for_encrypted_keys_are_encrypted_not_compressed(): void
    {
        $secret = str_repeat('confidential report line ', 500);

        $this->smartCache->put('secret_report', $secret, 60);

        $raw = $this->rawStore()->get('secret_report');
        $this->assertIsArray($raw);
        $this->assertTrue($raw['_sc_encrypted'] ?? false);
        $this->assertArrayNotHasKey('_sc_compressed', $raw);
        $this->assertSame($secret, $this->smartCache->get('secret_report'));
    }

    public function test_large_arrays_for_encrypted_keys_are_not_chunked_in_plain_text(): void
    {
        $tokens = array_map(fn ($i) => "token-value-{$i}", range(1, 1500));

        $this->smartCache->put('token_batch', $tokens, 60);

        $this->assertTrue($this->rawStore()->get('token_batch')['_sc_encrypted'] ?? false);
        $this->assertFalse($this->rawStore()->has('_sc_chunk_token_batch_0'));
        $this->assertSame($tokens, $this->smartCache->get('token_batch'));
    }

    public function test_encryption_keys_and_patterns_match_under_a_namespace(): void
    {
        $this->smartCache->namespace('tenant1')->put('token_abc', 'secret', 60);
        $this->smartCache->namespace('tenant1')->put('secret_report', 'secret', 60);

        $this->assertTrue($this->rawStore()->get('tenant1:token_abc')['_sc_encrypted'] ?? false);
        $this->assertTrue($this->rawStore()->get('tenant1:secret_report')['_sc_encrypted'] ?? false);
        $this->assertSame('secret', $this->smartCache->namespace('tenant1')->get('token_abc'));
    }

    public function test_undecryptable_entry_is_regenerated_instead_of_served_as_null(): void
    {
        $cache = $this->selfHealingCache([
            new EncryptionStrategy($this->app['encrypter'], ['encrypt_all' => true]),
        ]);

        // E.g. written before an APP_KEY rotation.
        $this->rawStore()->put('tok', ['_sc_encrypted' => true, 'data' => 'not-a-valid-payload'], 60);

        $this->assertSame('DEFAULT', $cache->get('tok', 'DEFAULT'));

        $this->rawStore()->put('tok', ['_sc_encrypted' => true, 'data' => 'not-a-valid-payload'], 60);
        $this->assertSame('regenerated', $cache->remember('tok', 60, fn () => 'regenerated'));
    }

    // -----------------------------------------------------------------
    // Strategies
    // -----------------------------------------------------------------

    public function test_corrupted_adaptive_compression_entry_is_regenerated(): void
    {
        $cache = $this->selfHealingCache([new AdaptiveCompressionStrategy(1024)]);

        // Decompresses cleanly to an empty string, which unserialize() turns into
        // false without raising any warning.
        $this->rawStore()->put('report', [
            '_sc_compressed' => true,
            '_sc_adaptive' => true,
            'data' => base64_encode(gzencode('')),
            'is_string' => false,
        ], 60);

        $this->assertSame('regenerated', $cache->remember('report', 60, fn () => 'regenerated'));
    }

    public function test_large_array_with_few_top_level_keys_is_compressed(): void
    {
        $cache = new SmartCache($this->rawStore(), $this->app['cache'], $this->app['config'], [
            new CompressionStrategy(51200, 6),
        ]);
        $payload = [
            'data' => $this->createLargeTestData(200),
            'meta' => ['total' => 200],
        ];

        $cache->put('api_payload', $payload, 60);

        $this->assertValueIsCompressed($this->rawStore()->get('api_payload'));
        $this->assertSame($payload, $cache->get('api_payload'));
    }

    public function test_chunked_eloquent_collection_keeps_its_class(): void
    {
        $collection = new EloquentCollection(array_map(fn ($i) => "row-{$i}", range(1, 1500)));

        $this->smartCache->put('rows', $collection, 60);
        $this->assertValueIsChunked($this->rawStore()->get('rows'));

        $restored = $this->smartCache->get('rows');
        $this->assertInstanceOf(EloquentCollection::class, $restored);
        $this->assertSame($collection->all(), $restored->all());
    }

    public function test_chunked_entries_written_before_the_class_was_recorded_still_restore(): void
    {
        $this->smartCache->put('rows', new Collection(range(1, 1500)), 60);

        $wrapper = $this->rawStore()->get('rows');
        unset($wrapper['collection_class']);
        $this->rawStore()->put('rows', $wrapper, 60);

        $restored = $this->smartCache->get('rows');
        $this->assertSame(Collection::class, get_class($restored));
        $this->assertSame(range(1, 1500), $restored->all());
    }

    // -----------------------------------------------------------------
    // Fallback, events, and monitoring
    // -----------------------------------------------------------------

    public function test_with_fallback_calls_a_closure_fallback(): void
    {
        $result = $this->smartCache->withCircuitBreaker()->withFallback(
            function () {
                throw new \RuntimeException('cache down');
            },
            fn () => 'fallback value'
        );

        $this->smartCache->withoutCircuitBreaker();

        $this->assertSame('fallback value', $result);
    }

    public function test_optimization_applied_event_is_dispatched(): void
    {
        $events = [];
        Event::listen(OptimizationApplied::class, function (OptimizationApplied $event) use (&$events) {
            $events[] = $event;
        });
        $this->app['config']->set('smart-cache.events.enabled', true);

        $this->smartCache->put('compressible', str_repeat('compress me ', 500), 60);

        $this->assertCount(1, $events);
        $this->assertSame('compressible', $events[0]->key);
        $this->assertSame('compression', $events[0]->strategy);
        $this->assertLessThan($events[0]->originalSize, $events[0]->optimizedSize);
    }

    public function test_recent_entries_limit_is_honoured(): void
    {
        $this->app['config']->set('smart-cache.monitoring.recent_entries_limit', 3);

        foreach (range(1, 5) as $i) {
            $this->smartCache->get("missing_{$i}");
        }

        $recent = $this->smartCache->getPerformanceMetrics()['metrics']['cache_miss']['recent'];
        $this->assertCount(3, $recent);
        $this->assertSame('missing_5', end($recent)['key']);
    }
}

/**
 * Invokable refresh callback for the queued refresh tests.
 */
class RefreshFeed
{
    public function __invoke(): string
    {
        return 'fresh';
    }
}
