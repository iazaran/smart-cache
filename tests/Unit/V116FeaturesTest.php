<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Drivers\MemoizedCacheDriver;
use SmartCache\Events\KeyWritten;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Services\OrphanChunkCleanupService;
use SmartCache\Services\RateLimiter;
use SmartCache\Services\SmartChunkSizeCalculator;
use SmartCache\SmartCache;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\RestoreOnlyStrategy;
use SmartCache\Tests\TestCase;
use SmartCache\Traits\CacheInvalidation;

enum V116BackedKey: string
{
    case Users = 'users_key';
}

enum V116PureKey
{
    case Settings;
}

enum V116Status: string
{
    case Active = 'active';
    case Archived = 'archived';
}

class V116Product extends Model
{
    use CacheInvalidation;

    protected $table = 'sc_v116_products';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['status' => V116Status::class];

    protected function cacheInvalidation(): array
    {
        return [
            'keys' => ['category_{category_id}_products', 'status_{status}_products'],
        ];
    }
}

class V116RefreshCallback
{
    public function __invoke(): string
    {
        return 'refreshed';
    }
}

/**
 * A store that refuses chunk writes, like Memcached refusing items over its
 * size limit.
 */
class V116RejectingChunkStore extends ArrayStore
{
    public function put($key, $value, $seconds)
    {
        if (\str_starts_with((string) $key, '_sc_chunk_')) {
            return false;
        }

        return parent::put($key, $value, $seconds);
    }
}

/**
 * A store without lock support (like APC).
 */
class V116NoLockStore implements \Illuminate\Contracts\Cache\Store
{
    private array $items = [];

    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    public function many(array $keys)
    {
        return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key] = ((int) ($this->items[$key] ?? 0)) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }

    public function touch($key, $seconds)
    {
        return isset($this->items[$key]);
    }
}

/**
 * A repository whose next reads of chosen keys return queued values, to replay
 * what a process would see while other processes write in between.
 */
class V116ScriptedReadRepository extends Repository
{
    /** @var array<string, array<int, mixed>> */
    public array $scriptedReads = [];

    public function get($key, $default = null): mixed
    {
        if (\is_string($key) && ($this->scriptedReads[$key] ?? []) !== []) {
            return \array_shift($this->scriptedReads[$key]);
        }

        return parent::get($key, $default);
    }
}

/**
 * Regression coverage for 1.16.0.
 *
 * Each test fails against the 1.15.0 implementation, except those marked as a
 * guard: they pin behavior that must not change, or check the opt-in native
 * memo mode, which 1.15.0 does not have.
 */
class V116FeaturesTest extends TestCase
{
    protected SmartCache $smartCache;

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

    /**
     * A second SmartCache instance on the same store, standing in for another
     * process.
     */
    protected function otherProcess(array $strategies = []): SmartCache
    {
        return new SmartCache($this->rawStore(), $this->app['cache'], $this->app['config'], $strategies);
    }

    protected function chunkingCache(bool $lazy = false): SmartCache
    {
        return new SmartCache($this->rawStore(), $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100, $lazy),
            new CompressionStrategy(1024),
        ]);
    }

    protected function rows(int $count, string $label = 'row'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $i, 'label' => "{$label}_{$i}", 'payload' => str_repeat('x', 20)];
        }

        return $rows;
    }

    protected function chunkKeysOf(string $key): array
    {
        $raw = $this->rawStore()->get($key);

        $this->assertIsArray($raw);
        $this->assertTrue($raw['_sc_chunked'] ?? false, "{$key} should be chunked");

        return $raw['chunk_keys'];
    }

    // -----------------------------------------------------------------
    // Laravel Repository parity
    // -----------------------------------------------------------------

    public function test_enum_keys_work_like_strings(): void
    {
        $this->assertTrue($this->smartCache->put(V116BackedKey::Users, 'a', 60));
        $this->assertSame('a', $this->smartCache->get(V116BackedKey::Users));
        $this->assertSame('a', $this->rawStore()->get('users_key'));
        $this->assertTrue($this->smartCache->has(V116BackedKey::Users));
        $this->assertSame(['users_key' => 'a'], $this->smartCache->many([V116BackedKey::Users]));
        $this->assertSame(['users_key' => 'a'], $this->smartCache->getMultiple([V116BackedKey::Users]));
        $this->assertSame('a', $this->smartCache->memo()->get(V116BackedKey::Users));
        $this->assertTrue($this->smartCache->forget(V116BackedKey::Users));

        $this->assertSame('b', $this->smartCache->remember(V116PureKey::Settings, 60, fn () => 'b'));
        $this->assertSame('b', $this->rawStore()->get('Settings'));
        $this->assertFalse($this->smartCache->add(V116PureKey::Settings, 'c', 60));
        $this->assertTrue($this->smartCache->touch(V116PureKey::Settings, 120));
        $this->assertSame('b', $this->smartCache->pull(V116PureKey::Settings));

        $this->smartCache->forever(V116BackedKey::Users, 1);
        $this->assertSame(2, $this->smartCache->increment(V116BackedKey::Users));
        $this->assertSame(1, $this->smartCache->decrement(V116BackedKey::Users));
    }

    public function test_enum_keys_respect_the_namespace(): void
    {
        $this->smartCache->namespace('t1')->put(V116BackedKey::Users, 'tenant', 60);

        $this->assertSame('tenant', $this->rawStore()->get('t1:users_key'));
        $this->assertNull($this->rawStore()->get('users_key'));
    }

    public function test_enum_tags_are_normalized(): void
    {
        $this->smartCache->tags([V116BackedKey::Users])->put('tagged', 'v', 60);
        $this->assertContains('tagged', $this->rawStore()->get('_sc_tag_users_key'));

        $this->smartCache->flushTags([V116BackedKey::Users]);
        $this->assertFalse($this->smartCache->has('tagged'));
    }

    public function test_typed_getters_restore_optimized_values(): void
    {
        $text = str_repeat('compressible text ', 200);
        $this->smartCache->put('text', $text, 60);
        $this->assertTrue($this->rawStore()->get('text')['_sc_compressed'] ?? false);
        $this->assertSame($text, $this->smartCache->string('text'));

        $rows = $this->createChunkableData();
        $this->smartCache->put('rows', $rows, 60);
        $this->assertSame($rows, $this->smartCache->array('rows'));

        $this->smartCache->put('count', 41, 60);
        $this->assertSame(41, $this->smartCache->integer('count'));
        $this->smartCache->put('ratio', 0.5, 60);
        $this->assertSame(0.5, $this->smartCache->float('ratio'));
        $this->smartCache->put('flag', false, 60);
        $this->assertFalse($this->smartCache->boolean('flag'));
        $this->assertSame('fallback', $this->smartCache->string('missing', fn () => 'fallback'));
    }

    public function test_typed_getters_use_the_namespace_and_reject_other_types(): void
    {
        $this->rawStore()->put('count', 'global', 60);
        $this->smartCache->namespace('t1')->put('count', 7, 60);

        $this->assertSame(7, $this->smartCache->integer('count'));

        $this->expectException(\InvalidArgumentException::class);
        $this->smartCache->array('count');
    }

    public function test_stored_null_is_not_returned_as_the_null_marker(): void
    {
        $this->smartCache->put('nothing', null, 60);

        $this->expectException(\InvalidArgumentException::class);
        $this->smartCache->array('nothing');
    }

    public function test_missing_uses_the_namespace(): void
    {
        $this->smartCache->namespace('t1')->put('count', 1, 60);

        $this->assertFalse($this->smartCache->missing('count'));
        $this->assertTrue($this->smartCache->withoutNamespace()->missing('count'));
    }

    public function test_remember_with_warmth_reports_cache_hits(): void
    {
        $this->assertSame(['fresh', false], $this->smartCache->rememberWithWarmth('warm', 60, fn () => 'fresh'));
        $this->assertSame(['fresh', true], $this->smartCache->rememberWithWarmth('warm', 60, fn () => 'other'));
        $this->assertContains('warm', $this->smartCache->getManagedKeys());
    }

    public function test_remember_accepts_a_closure_ttl_that_receives_the_value(): void
    {
        $seen = null;
        $this->smartCache->remember('closure_ttl', function ($value) use (&$seen) {
            $seen = $value;

            return 60;
        }, fn () => 'computed');

        $this->assertSame('computed', $seen);
        $this->assertSame('computed', $this->smartCache->get('closure_ttl'));
    }

    public function test_variadic_tags_tag_every_name(): void
    {
        $this->smartCache->tags('a', 'b')->put('both', 1, 60);

        $this->smartCache->flushTags('b');

        $this->assertFalse($this->smartCache->has('both'));
    }

    public function test_tagged_put_many_tags_every_key(): void
    {
        $this->smartCache->tags(['users'])->putMany(['u1' => 1, 'u2' => 2, 'u3' => 3], 60);
        $this->smartCache->put('unrelated', 'kept', 60);

        $this->smartCache->flushTags('users');

        $this->assertSame(['u1' => null, 'u2' => null, 'u3' => null], $this->smartCache->many(['u1', 'u2', 'u3']));
        $this->assertSame('kept', $this->smartCache->get('unrelated'));
    }

    public function test_flexible_accepts_date_interval_durations(): void
    {
        $durations = [new \DateInterval('PT1H'), new \DateInterval('PT2H')];

        $this->assertSame('first', $this->smartCache->flexible('interval', $durations, fn () => 'first'));
        $this->assertSame('first', $this->smartCache->flexible('interval', $durations, fn () => 'second'));
    }

    public function test_tags_are_supported_on_every_store(): void
    {
        $this->assertTrue($this->smartCache->supportsTags());
        $this->assertTrue($this->smartCache->store('file')->supportsTags());
    }

    // -----------------------------------------------------------------
    // tags(...)->flush()
    // -----------------------------------------------------------------

    public function test_tags_flush_removes_only_the_tagged_entries_and_their_chunks(): void
    {
        $rows = $this->createChunkableData();
        $this->smartCache->tags(['reports'])->put('heavy_analytics', $rows, 60);
        $chunks = $this->chunkKeysOf('heavy_analytics');
        $this->smartCache->put('unrelated', 'kept', 60);
        $this->rawStore()->put('laravel_key', 'kept', 60);

        $this->assertTrue($this->smartCache->tags(['reports'])->flush());

        $this->assertFalse($this->smartCache->has('heavy_analytics'));
        foreach ($chunks as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertSame('kept', $this->smartCache->get('unrelated'));
        $this->assertSame('kept', $this->rawStore()->get('laravel_key'));
    }

    /** Guard. */
    public function test_flushing_an_empty_tag_list_removes_nothing(): void
    {
        $this->smartCache->put('unrelated', 'kept', 60);

        $this->assertTrue($this->smartCache->tags([])->flush());

        $this->assertSame('kept', $this->smartCache->get('unrelated'));
    }

    public function test_flush_after_an_empty_bulk_call_still_clears_the_store(): void
    {
        foreach (['many', 'getMultiple', 'deleteMultiple'] as $method) {
            $this->rawStore()->put('laravel_key', 'gone', 60);

            $this->smartCache->tags(['t'])->{$method}([]);
            $this->smartCache->flush();

            $this->assertNull($this->rawStore()->get('laravel_key'), "flush() after {$method}([])");
        }
    }

    /** Guard. */
    public function test_plain_flush_still_clears_the_store(): void
    {
        $this->smartCache->put('a', 1, 60);
        $this->rawStore()->put('laravel_key', 'gone', 60);

        $this->smartCache->flush();

        $this->assertNull($this->rawStore()->get('a'));
        $this->assertNull($this->rawStore()->get('laravel_key'));
    }

    /** Guard. */
    public function test_flush_after_a_tagged_lookup_still_clears_the_store(): void
    {
        $this->rawStore()->put('laravel_key', 'gone', 60);

        // A tagged miss leaves the tags pending for the next write, as in 1.14;
        // a later flush() is not tags(...)->flush().
        $this->smartCache->tags(['users'])->get('missing');
        $this->smartCache->flush();

        $this->assertNull($this->rawStore()->get('laravel_key'));
    }

    public function test_memo_flush_clears_the_memo_layer(): void
    {
        $memo = $this->smartCache->memo();
        $memo->put('memo_key', 'value', 60);
        $this->assertSame('value', $memo->get('memo_key'));

        $memo->flush();

        $this->assertNull($memo->get('memo_key'));
    }

    // -----------------------------------------------------------------
    // memo(): bookkeeping and native mode
    // -----------------------------------------------------------------

    public function test_memo_does_not_skip_a_write_on_a_stale_deduplication_record(): void
    {
        $this->app['config']->set('smart-cache.deduplication.enabled', true);
        $memo = $this->otherProcess()->memo();
        $other = $this->otherProcess();

        $memo->put('d', 'mine', 3600);
        $memo->put('d', 'mine', 3600);
        $other->put('d', 'theirs', 3600);

        $this->assertTrue($memo->put('d', 'mine', 3600));
        $this->assertSame('mine', $this->rawStore()->get('d'));
    }

    public function test_memo_tag_index_updates_keep_other_processes_entries(): void
    {
        $memo = $this->smartCache->memo();
        $other = $this->otherProcess();

        $memo->tags(['shared'])->put('first', 1, 60);
        $this->assertSame(['first'], $this->rawStore()->get('_sc_tag_shared'));
        // The memo layer has now seen the tag index.
        $memo->get('_sc_tag_shared');

        $other->tags(['shared'])->put('second', 2, 60);
        $memo->tags(['shared'])->put('third', 3, 60);

        $this->assertEqualsCanonicalizing(['first', 'second', 'third'], $this->rawStore()->get('_sc_tag_shared'));
    }

    public function test_memo_passes_other_repository_methods_through(): void
    {
        $repository = new MemoizedCacheDriver($this->rawStore());

        $this->assertSame($this->rawStore()->getStore(), $repository->getStore());
        $this->assertSame($this->rawStore()->getPrefix(), $repository->getPrefix());
        $this->assertTrue($repository->supportsTags() === $this->rawStore()->supportsTags());
    }

    public function test_memoized_touch_with_a_non_positive_ttl_forgets_the_memoized_value(): void
    {
        $repository = new MemoizedCacheDriver($this->rawStore());
        $repository->put('touched', 'v', 60);
        $this->assertSame('v', $repository->get('touched'));

        $repository->touch('touched', 0);

        $this->assertNull($repository->get('touched'));
    }

    protected function nativeMemoAvailable(): bool
    {
        return \method_exists($this->app['cache'], 'memo');
    }

    public function test_native_memo_shares_one_layer_with_cache_memo(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['config']->set('smart-cache.memo.native', true);
        $this->smartCache->put('shared', 'v1', 60);
        $this->assertSame('v1', $this->smartCache->memo()->get('shared'));

        $this->rawStore()->put('shared', 'v2', 60);

        // A new memo() call and Laravel's own Cache::memo() see the same memory.
        $this->assertSame('v1', $this->smartCache->memo()->get('shared'));
        $this->assertSame('v1', $this->app['cache']->memo()->get('shared'));
    }

    /** Guard. */
    public function test_memo_layers_are_per_call_by_default(): void
    {
        $this->smartCache->put('per_call', 'v1', 60);
        $this->assertSame('v1', $this->smartCache->memo()->get('per_call'));

        $this->rawStore()->put('per_call', 'v2', 60);

        $this->assertSame('v2', $this->smartCache->memo()->get('per_call'));
    }

    /** Guard. */
    public function test_native_memo_add_is_atomic_against_the_store(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['config']->set('smart-cache.memo.native', true);
        $memo = $this->smartCache->memo();
        $this->assertNull($memo->get('marker'));

        // Another process adds the key after this request remembered the miss.
        $this->rawStore()->put('marker', 'theirs', 60);

        $this->assertFalse($memo->add('marker', 'mine', 60));
        $this->assertSame('theirs', $this->rawStore()->get('marker'));
    }

    public function test_native_memo_reads_a_key_it_added_after_remembering_a_miss(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['config']->set('smart-cache.memo.native', true);
        $memo = $this->smartCache->memo();
        $this->assertNull($memo->get('added'));

        $this->assertTrue($memo->add('added', 'mine', 60));

        $this->assertSame('mine', $memo->get('added'));
        $this->assertTrue($memo->has('added'));
        $this->assertSame('mine', $this->smartCache->memo()->get('added'), 'Other memo() instances share the layer');
    }

    /** Guard. */
    public function test_native_memo_keeps_the_driver_of_the_store_below_it(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['config']->set('smart-cache.memo.native', true);
        $memo = $this->smartCache->memo();

        $driver = (new \ReflectionProperty(SmartCache::class, 'driver'))->getValue($memo);
        $this->assertSame('array', $driver);
    }

    /** Guard. */
    public function test_native_memo_reads_a_value_another_process_stored_while_waiting_for_the_lock(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['config']->set('smart-cache.memo.native', true);
        $memo = $this->smartCache->memo();
        $lock = $this->rawStore()->getStore()->lock('_sc_remember_lock:' . sha1('locked'), 10);
        $this->assertTrue($lock->get());

        $calls = 0;
        $this->rawStore()->put('locked', 'theirs', 60);
        $value = $memo->rememberWithLock('locked', 60, function () use (&$calls) {
            $calls++;

            return 'mine';
        }, 10, 1);

        $lock->release();
        $this->assertSame(0, $calls);
        $this->assertSame('theirs', $value);
        $this->assertSame('theirs', $memo->get('locked'), 'The memo layer must not keep the earlier miss');
    }

    public function test_native_memo_on_a_store_without_locks_does_not_try_to_lock(): void
    {
        if (!$this->nativeMemoAvailable()) {
            $this->markTestSkipped('Cache::memo() needs Laravel 12.9+.');
        }

        $this->app['cache']->extend('v116_nolock', fn () => $this->app['cache']->repository(new V116NoLockStore()));
        $this->app['config']->set('cache.stores.v116_nolock', ['driver' => 'v116_nolock']);
        $this->app['config']->set('smart-cache.memo.native', true);
        $this->app['config']->set('smart-cache.fallback.log_errors', true);
        \Illuminate\Support\Facades\Log::spy();

        $value = $this->smartCache->memo('v116_nolock')->rememberWithLock('nolock', 60, fn () => 'built');

        $this->assertSame('built', $value);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    // -----------------------------------------------------------------
    // Versioned chunks
    // -----------------------------------------------------------------

    public function test_each_chunked_write_uses_new_chunk_keys_and_removes_the_old_ones(): void
    {
        $cache = $this->chunkingCache();

        $cache->put('big', $this->rows(300, 'old'), 60);
        $old = $this->chunkKeysOf('big');
        $cache->put('big', $this->rows(300, 'new'), 60);
        $new = $this->chunkKeysOf('big');

        $this->assertSame([], array_intersect($old, $new));
        foreach ($old as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertSame($this->rows(300, 'new'), $cache->get('big'));
    }

    public function test_a_reader_that_keeps_finding_replaced_entries_never_evicts_the_newest(): void
    {
        $this->app['config']->set('smart-cache.self_healing.enabled', true);
        $writer = $this->chunkingCache();

        $writer->put('big', $this->rows(300, 'a'), 60);
        $a = $this->rawStore()->get('big');
        $writer->put('big', $this->rows(300, 'b'), 60);
        $b = $this->rawStore()->get('big');
        $writer->put('big', $this->rows(300, 'c'), 60);
        $c = $this->rawStore()->get('big');

        // The reader holds A; its re-read finds B, which is replaced by C
        // before B's chunks are read.
        $repository = new V116ScriptedReadRepository($this->rawStore()->getStore());
        $repository->scriptedReads['big'] = [$b, $c];
        $reader = new SmartCache($repository, $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100),
        ]);

        $restored = (new \ReflectionMethod(SmartCache::class, 'maybeRestoreValue'))->invoke($reader, $a, 'big');

        $this->assertSame($this->rows(300, 'c'), $restored);
        $this->assertSame($c, $this->rawStore()->get('big'), 'The newest entry must not be evicted');
    }

    /** Guard. */
    public function test_a_reader_of_the_replaced_entry_gets_the_new_value(): void
    {
        $this->app['config']->set('smart-cache.self_healing.enabled', true);
        $cache = $this->chunkingCache();

        $cache->put('big', $this->rows(300, 'old'), 60);
        $staleWrapper = $this->rawStore()->get('big');
        $cache->put('big', $this->rows(300, 'new'), 60);

        $restore = new \ReflectionMethod(SmartCache::class, 'maybeRestoreValue');
        $restored = $restore->invoke($cache, $staleWrapper, 'big');

        $this->assertSame($this->rows(300, 'new'), $restored);
        $this->assertNotNull($this->rawStore()->get('big'), 'The new entry must not be evicted');
    }

    public function test_shrinking_a_forever_chunked_value_removes_its_trailing_chunks(): void
    {
        $cache = $this->chunkingCache();

        $cache->forever('config', $this->rows(500));
        $before = $this->chunkKeysOf('config');
        $cache->forever('config', $this->rows(150));

        foreach ($before as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertCount(2, $this->chunkKeysOf('config'));
    }

    public function test_replacing_a_forever_chunked_value_with_a_small_array_removes_its_chunks(): void
    {
        $cache = $this->chunkingCache();

        $cache->forever('config', $this->rows(500));
        $chunks = $this->chunkKeysOf('config');
        $cache->forever('config', ['small' => true]);

        foreach ($chunks as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertSame(['small' => true], $cache->get('config'));
    }

    public function test_chunks_written_by_1_15_are_read_and_removed_on_overwrite(): void
    {
        $cache = $this->chunkingCache();
        $rows = $this->rows(150);
        $this->rawStore()->forever('_sc_chunk_legacy_0', array_slice($rows, 0, 100, true));
        $this->rawStore()->forever('_sc_chunk_legacy_1', array_slice($rows, 100, null, true));
        $this->rawStore()->forever('legacy', [
            '_sc_chunked' => true,
            'chunk_keys' => ['_sc_chunk_legacy_0', '_sc_chunk_legacy_1'],
            'total_items' => 150,
            'is_collection' => false,
            'original_key' => 'legacy',
            'driver' => 'array',
            'lazy_loading' => false,
            'chunk_size' => 100,
        ]);

        $this->assertSame($rows, $cache->get('legacy'));

        $cache->forever('legacy', $this->rows(150, 'new'));

        $this->assertNull($this->rawStore()->get('_sc_chunk_legacy_0'));
        $this->assertNull($this->rawStore()->get('_sc_chunk_legacy_1'));
    }

    public function test_a_store_that_rejects_chunks_falls_back_to_a_readable_entry(): void
    {
        $repository = new Repository(new V116RejectingChunkStore());
        $cache = new SmartCache($repository, $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100),
            new CompressionStrategy(1024),
        ]);
        $rows = $this->rows(300);

        $this->assertTrue($cache->put('big', $rows, 60));

        $this->assertSame($rows, $cache->get('big'));
        $this->assertTrue($repository->get('big')['_sc_compressed'] ?? false);
        // ArrayStore::all() is missing on older Laravel versions.
        $storage = (new \ReflectionProperty(ArrayStore::class, 'storage'))->getValue($repository->getStore());
        foreach (array_keys($storage) as $key) {
            $this->assertStringStartsNotWith('_sc_chunk_', (string) $key);
        }
    }

    public function test_deduplication_never_skips_a_chunked_write(): void
    {
        $this->app['config']->set('smart-cache.deduplication.enabled', true);
        $cache = $this->chunkingCache();

        $cache->put('dedup_big', $this->rows(300), 3600);
        $cache->put('dedup_big', $this->rows(300), 60);

        $this->travel(61)->seconds();

        // The main entry must expire with the TTL of the last write, together
        // with its chunks, instead of outliving them as a broken entry.
        $this->assertNull($this->rawStore()->get('dedup_big'));
    }

    public function test_a_non_positive_ttl_deletes_the_entry_and_its_chunks(): void
    {
        $cache = $this->chunkingCache();
        $cache->put('expiring', $this->rows(300), 60);
        $chunks = $this->chunkKeysOf('expiring');

        $cache->put('expiring', $this->rows(300), 0);

        $this->assertNull($this->rawStore()->get('expiring'));
        foreach ($chunks as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertNotContains('expiring', $cache->getManagedKeys());
    }

    // -----------------------------------------------------------------
    // Orphan-chunk registry
    // -----------------------------------------------------------------

    public function test_forever_chunked_values_are_registered_and_unregistered(): void
    {
        $cache = $this->chunkingCache();

        $cache->forever('forever_big', $this->rows(300));
        $registry = $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY);
        $this->assertSame($this->chunkKeysOf('forever_big'), $registry['forever_big']['chunk_keys']);

        $cache->forget('forever_big');
        $this->assertNull($this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY));
    }

    public function test_chunked_values_with_a_ttl_are_not_registered(): void
    {
        $this->chunkingCache()->put('ttl_big', $this->rows(300), 60);

        $this->assertNull($this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY));
    }

    public function test_cleanup_removes_the_chunks_of_an_evicted_forever_entry(): void
    {
        $cache = $this->chunkingCache();
        $cache->forever('evicted', $this->rows(300));
        $chunks = $this->chunkKeysOf('evicted');

        // Evicted by the store, or deleted outside SmartCache.
        $this->rawStore()->forget('evicted');

        $stats = $cache->cleanupOrphanChunks();

        $this->assertSame(1, $stats['orphaned_main_keys']);
        $this->assertSame(count($chunks), $stats['cleaned_chunks']);
        foreach ($chunks as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
    }

    public function test_cleanup_removes_registered_chunks_the_entry_no_longer_uses(): void
    {
        $cache = $this->chunkingCache();
        $cache->forever('replaced', $this->rows(300));
        $live = $this->chunkKeysOf('replaced');

        // A concurrent writer's chunks that lost the race.
        $this->rawStore()->forever('_sc_chunk_replaced_lost_0', ['x']);
        $this->rawStore()->forever(OrphanChunkCleanupService::REGISTRY_KEY, [
            'replaced' => ['chunk_keys' => [...$live, '_sc_chunk_replaced_lost_0'], 'registered_at' => time()],
        ]);

        $stats = $cache->cleanupOrphanChunks();

        $this->assertSame(1, $stats['cleaned_chunks']);
        $this->assertNull($this->rawStore()->get('_sc_chunk_replaced_lost_0'));
        $this->assertSame($this->rows(300), $cache->get('replaced'));
        $this->assertSame($live, $this->rawStore()->get(OrphanChunkCleanupService::REGISTRY_KEY)['replaced']['chunk_keys']);
    }

    public function test_concurrent_forever_writes_keep_the_losers_chunks_registered(): void
    {
        $winner = $this->chunkingCache();
        $winner->forever('raced', $this->rows(300, 'old'));
        $old = $this->rawStore()->get('raced');

        // B stores its value; A, which read the old entry before B wrote, then
        // overwrites it and deletes only the old entry's chunks.
        $winner->forever('raced', $this->rows(300, 'b'));
        $loserChunks = $this->chunkKeysOf('raced');

        $repository = new V116ScriptedReadRepository($this->rawStore()->getStore());
        $repository->scriptedReads['raced'] = [$old];
        (new SmartCache($repository, $this->app['cache'], $this->app['config'], [
            new ChunkingStrategy(2048, 100),
        ]))->forever('raced', $this->rows(300, 'a'));

        $stats = $winner->cleanupOrphanChunks();

        $this->assertSame(count($loserChunks), $stats['cleaned_chunks']);
        foreach ($loserChunks as $chunkKey) {
            $this->assertNull($this->rawStore()->get($chunkKey));
        }
        $this->assertSame($this->rows(300, 'a'), $winner->get('raced'));
    }

    // -----------------------------------------------------------------
    // Lazy loading
    // -----------------------------------------------------------------

    public function test_lazy_collection_throws_on_a_missing_chunk_instead_of_returning_partial_data(): void
    {
        $cache = $this->chunkingCache(true);
        $cache->put('lazy', $this->rows(300), 60);
        $lazy = $cache->get('lazy');
        $this->assertInstanceOf(LazyChunkedCollection::class, $lazy);

        $chunks = $this->chunkKeysOf('lazy');
        $this->rawStore()->forget($chunks[1]);

        $this->expectException(\RuntimeException::class);
        $lazy->toArray();
    }

    public function test_flexible_returns_the_complete_stale_value_with_lazy_loading(): void
    {
        $cache = $this->chunkingCache(true);
        $cache->flexible('lazy_swr', [10, 3600], fn () => $this->rows(300, 'old'));

        // flexible() measures age with time(), so age the entry directly.
        $this->rawStore()->put('_sc_meta:lazy_swr', ['stored_at' => time() - 20, 'created_at' => time() - 20], 3600);

        $stale = $cache->flexible('lazy_swr', [10, 3600], fn () => $this->rows(350, 'new'));

        $this->assertSame($this->rows(300, 'old'), $stale);
    }

    // -----------------------------------------------------------------
    // Disabled strategies still decode
    // -----------------------------------------------------------------

    protected function rebuildSmartCache(): SmartCache
    {
        $this->app->forgetInstance(SmartCacheContract::class);
        $this->app->forgetInstance('smart-cache');
        \SmartCache\Facades\SmartCache::clearResolvedInstances();

        return $this->app->make(SmartCacheContract::class);
    }

    public function test_entries_written_before_a_strategy_was_disabled_still_restore(): void
    {
        $text = str_repeat('compressible text ', 200);
        $rows = $this->createChunkableData();
        $this->smartCache->put('compressed', $text, 60);
        $this->smartCache->put('chunked', $rows, 60);

        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        $this->app['config']->set('smart-cache.strategies.chunking.enabled', false);
        $cache = $this->rebuildSmartCache();

        $this->assertSame($text, $cache->get('compressed'));
        $this->assertSame($rows, $cache->get('chunked'));
        $this->assertSame([], $cache->getStrategies(), 'Disabled strategies must not encode');
    }

    public function test_entries_encrypted_before_encryption_was_disabled_still_restore(): void
    {
        $this->app['config']->set('smart-cache.strategies.encryption', ['enabled' => true, 'keys' => ['secret']]);
        $this->rebuildSmartCache()->put('secret', 'classified', 60);
        $this->assertTrue($this->rawStore()->get('secret')['_sc_encrypted'] ?? false);

        $this->app['config']->set('smart-cache.strategies.encryption.enabled', false);

        $this->assertSame('classified', $this->rebuildSmartCache()->get('secret'));
    }

    /** Guard. */
    public function test_restore_only_strategies_never_encode_and_build_lazily(): void
    {
        $built = 0;
        $strategy = new RestoreOnlyStrategy('compression', '_sc_compressed', function () use (&$built) {
            $built++;

            return new CompressionStrategy();
        });

        $this->assertFalse($strategy->shouldApply(str_repeat('x', 100000)));
        $this->assertSame('plain', $strategy->restore('plain'));
        $this->assertSame(['a' => 1], $strategy->restore(['a' => 1]));
        $this->assertSame(0, $built);

        $compressed = (new CompressionStrategy(10))->optimize(str_repeat('y', 500));
        $this->assertSame(str_repeat('y', 500), $strategy->restore($compressed));
        $this->assertSame(1, $built);
    }

    // -----------------------------------------------------------------
    // Shared bookkeeping across processes
    // -----------------------------------------------------------------

    public function test_persisting_the_managed_key_index_keeps_keys_other_processes_tracked(): void
    {
        $first = $this->otherProcess();
        $second = $this->otherProcess();

        $first->getManagedKeys();          // loads the (empty) index
        $second->put('from_second', 1, 60);
        $second->persistManagedKeys();
        $first->put('from_first', 1, 60);
        $first->persistManagedKeys();

        $this->assertEqualsCanonicalizing(['from_first', 'from_second'], $this->rawStore()->get('_sc_managed_keys'));
    }

    public function test_rewriting_a_key_another_process_untracked_keeps_it_in_the_index(): void
    {
        $writer = $this->otherProcess();
        $remover = $this->otherProcess();
        $writer->put('k', 1, 60);
        $writer->persistManagedKeys();

        $stale = $this->otherProcess();
        $stale->getManagedKeys();          // loaded while k was listed
        $remover->forget('k');
        $remover->persistManagedKeys();

        $stale->put('k', 2, 60);           // already listed in its stale copy
        $stale->put('l', 3, 60);
        $stale->persistManagedKeys();

        $this->assertEqualsCanonicalizing(['k', 'l'], $this->rawStore()->get('_sc_managed_keys'));
    }

    public function test_clear_managed_clears_keys_tracked_after_the_index_was_loaded(): void
    {
        $first = $this->otherProcess();
        $second = $this->otherProcess();

        $first->getManagedKeys();
        $second->put('late', 1, 60);
        $second->persistManagedKeys();

        $first->clearManaged();

        $this->assertNull($this->rawStore()->get('late'));
        $this->assertSame([], $this->rawStore()->get('_sc_managed_keys', []));
    }

    public function test_queue_worker_loop_refreshes_the_loaded_index_and_keeps_the_namespace(): void
    {
        $this->smartCache->namespace('worker')->getManagedKeys();
        $this->smartCache->tags(['pending'])->get('missing');

        $other = $this->otherProcess();
        $other->put('added_by_web', 1, 60);
        $other->persistManagedKeys();

        Event::dispatch(new Looping('redis', 'default'));

        $this->assertContains('added_by_web', $this->smartCache->getManagedKeys());
        $this->assertSame('worker', $this->smartCache->getNamespace());

        $this->smartCache->withoutNamespace()->put('after_loop', 1, 60);
        $this->assertSame([], $this->rawStore()->get('_sc_tag_pending', []));
    }

    public function test_refresh_job_without_namespace_ignores_a_leaked_namespace(): void
    {
        $this->smartCache->namespace('leaked');

        (new BackgroundCacheRefreshJob('feed', V116RefreshCallback::class, 60))->handle();

        $this->assertSame('refreshed', $this->rawStore()->get('feed'));
        $this->assertNull($this->rawStore()->get('leaked:feed'));
        $this->assertSame('leaked', $this->smartCache->getNamespace());
    }

    // -----------------------------------------------------------------
    // Invalidation
    // -----------------------------------------------------------------

    public function test_namespaced_flush_patterns_reaches_the_namespace_keys(): void
    {
        $this->smartCache->put('user_1', 'global', 60);
        $this->smartCache->namespace('t1')->put('user_1', 'tenant', 60);

        $this->smartCache->flushPatterns(['user_*']);

        $this->assertNull($this->rawStore()->get('t1:user_1'));
        $this->assertNull($this->rawStore()->get('user_1'));
    }

    public function test_namespaced_model_invalidation_reaches_the_namespace_keys(): void
    {
        $this->smartCache->namespace('t1')->put('App\Models\User_1_profile', 'tenant', 60);

        $this->smartCache->invalidateModel('App\Models\User', 1);

        $this->assertNull($this->rawStore()->get('t1:App\Models\User_1_profile'));
    }

    public function test_model_pattern_invalidation_matches_inside_the_namespace(): void
    {
        $this->smartCache->namespace('t1')->put('users_list_1', 'tenant', 60);

        $model = new class {
            use CacheInvalidation;

            public function flushPattern(string $pattern): void
            {
                $this->invalidatePattern($pattern);
            }
        };
        $model->flushPattern('users_list_*');

        $this->assertNull($this->rawStore()->get('t1:users_list_1'));
    }

    protected function createProductsTable(): void
    {
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('sc_v116_products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('category_id');
            $table->string('status');
        });
    }

    public function test_updating_a_model_invalidates_the_entries_of_its_original_values(): void
    {
        $this->createProductsTable();
        $product = V116Product::create(['category_id' => 1, 'status' => V116Status::Active]);
        $this->smartCache->put('category_1_products', ['p1'], 60);
        $this->smartCache->put('category_2_products', ['p9'], 60);

        $product->update(['category_id' => 2]);

        $this->assertNull($this->rawStore()->get('category_1_products'));
        $this->assertNull($this->rawStore()->get('category_2_products'));
    }

    public function test_original_values_are_invalidated_after_commit(): void
    {
        $this->createProductsTable();
        $product = V116Product::create(['category_id' => 1, 'status' => V116Status::Active]);
        $this->smartCache->put('category_1_products', ['p1'], 60);

        $product->getConnection()->transaction(function () use ($product) {
            $product->update(['category_id' => 2]);
        });

        $this->assertNull($this->rawStore()->get('category_1_products'));
    }

    public function test_enum_cast_attributes_resolve_in_placeholders(): void
    {
        $this->createProductsTable();
        $this->smartCache->put('status_active_products', ['p1'], 60);

        V116Product::create(['category_id' => 1, 'status' => V116Status::Active]);

        $this->assertNull($this->rawStore()->get('status_active_products'));
    }

    // -----------------------------------------------------------------
    // Events, circuit breaker, stampede protection, chunk sizing
    // -----------------------------------------------------------------

    public function test_key_written_carries_the_write_tags_and_fires_for_forever_and_add(): void
    {
        $this->app['config']->set('smart-cache.events.enabled', true);
        Event::fake([KeyWritten::class]);

        $this->smartCache->tags(['users'])->put('tagged', 1, 60);
        $this->smartCache->forever('forever', 1);
        $this->smartCache->add('added', 1, 60);

        Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === 'tagged' && $event->tags === ['users']);
        Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === 'forever');
        Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === 'added');
    }

    public function test_circuit_breaker_state_read_restarts_the_success_count(): void
    {
        $breaker = new CircuitBreaker(1, 0, 3);

        $breaker->recordFailure();               // open
        $this->assertTrue($breaker->isAvailable()); // half-open
        $breaker->recordSuccess();
        $breaker->recordSuccess();               // 2 of 3
        $breaker->recordFailure();               // re-opened

        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->getState());
        $breaker->recordSuccess();

        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->getState(), 'One success must not close it');
    }

    public function test_stampede_protection_does_not_refresh_right_after_a_write(): void
    {
        $limiter = new RateLimiter($this->rawStore());
        $refreshes = 0;

        for ($i = 0; $i < 1000; $i++) {
            $refreshes += $limiter->shouldRefreshProbabilistically(3600, time(), 1.0, 0.05) ? 1 : 0;
        }

        $this->assertSame(0, $refreshes);
        $this->assertTrue($limiter->shouldRefreshProbabilistically(60, time() - 61));
        $this->assertFalse($limiter->shouldRefreshProbabilistically(60, time(), 0.0));
    }

    public function test_stampede_protection_runs_the_callback_once_per_ttl(): void
    {
        $calls = 0;
        for ($i = 0; $i < 20; $i++) {
            $this->smartCache->rememberWithStampedeProtection('xfetch', 3600, function () use (&$calls) {
                $calls++;

                return 'v';
            });
        }

        $this->assertSame(1, $calls);
        $this->assertArrayHasKey('delta', $this->rawStore()->get('_sc_meta:xfetch'));
    }

    public function test_smart_sizing_keeps_chunks_within_the_driver_limit_for_large_items(): void
    {
        $items = array_fill(0, 200, str_repeat('m', 20 * 1024));

        $size = (new SmartChunkSizeCalculator())->calculateOptimalSize($items, 'memcached');

        $this->assertLessThan(1024 * 1024, $size * 20 * 1024);
    }
}
