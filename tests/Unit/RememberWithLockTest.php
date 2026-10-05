<?php

namespace SmartCache\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use SmartCache\Facades\SmartCache as SmartCacheFacade;
use SmartCache\SmartCache;
use SmartCache\Tests\TestCase;

/**
 * rememberWithLock(): single-flight regeneration on a cache miss.
 *
 * Concurrency is simulated with a store whose lock can be scripted, so each
 * test is deterministic and never sleeps.
 */
class RememberWithLockTest extends TestCase
{
    protected ScriptedLockStore $store;

    protected SmartCache $smartCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ScriptedLockStore();
        $this->smartCache = $this->makeSmartCache($this->store);
    }

    protected function makeSmartCache(Store $store): SmartCache
    {
        return new SmartCache(new Repository($store), $this->app['cache'], $this->app['config']);
    }

    protected function lockName(string $key): string
    {
        return '_sc_remember_lock:' . sha1($key);
    }

    public function test_miss_runs_callback_once_and_caches_the_value(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return ['report' => 42];
        };

        $this->assertSame(['report' => 42], $this->smartCache->rememberWithLock('report', 60, $callback));
        $this->assertSame(['report' => 42], $this->smartCache->rememberWithLock('report', 60, $callback));
        $this->assertSame(['report' => 42], $this->smartCache->get('report'));
        $this->assertSame(1, $calls);
    }

    public function test_hit_does_not_touch_the_lock(): void
    {
        $this->smartCache->put('hot', 'cached', 60);

        $value = $this->smartCache->rememberWithLock('hot', 60, fn () => 'regenerated');

        $this->assertSame('cached', $value);
        $this->assertSame([], $this->store->lockRequests);
    }

    public function test_lock_is_requested_for_the_key_and_released_afterwards(): void
    {
        $this->smartCache->rememberWithLock('report', 60, fn () => 'fresh');

        $this->assertSame([$this->lockName('report')], $this->store->lockRequests);

        // Released: a new owner can take the lock immediately.
        $this->assertTrue($this->store->lock($this->lockName('report'), 10)->get());
    }

    public function test_waiting_process_reads_the_value_stored_by_the_lock_holder(): void
    {
        $holder = $this->makeSmartCache($this->store);

        // The lock is taken; meanwhile its holder regenerates and stores the value.
        $this->store->nextLock = new ScriptedLock(function () use ($holder) {
            $holder->put('report', 'from-lock-holder', 60);
            return false;
        });

        $calls = 0;
        $value = $this->smartCache->rememberWithLock('report', 60, function () use (&$calls) {
            $calls++;
            return 'regenerated';
        });

        $this->assertSame('from-lock-holder', $value);
        $this->assertSame(0, $calls);
        $this->assertSame(1, $this->store->nextLock->attempts, 'waiters must not queue for the lock once the value exists');
        $this->assertFalse($this->store->nextLock->released, 'a lock that was never acquired must not be released');
    }

    public function test_memoized_waiter_reads_the_value_stored_by_the_lock_holder(): void
    {
        $holder = $this->makeSmartCache($this->store);
        $memo = $this->smartCache->memo();

        $this->store->nextLock = new ScriptedLock(function () use ($holder) {
            $holder->put('report', 'from-lock-holder', 60);
            return false;
        });

        $calls = 0;
        $value = $memo->rememberWithLock('report', 60, function () use (&$calls) {
            $calls++;
            return 'regenerated';
        });

        $this->assertSame('from-lock-holder', $value);
        $this->assertSame(0, $calls);
    }

    public function test_waiter_takes_the_lock_once_it_is_released_and_regenerates(): void
    {
        $attempt = 0;
        $this->store->nextLock = new ScriptedLock(function () use (&$attempt) {
            return ++$attempt > 1;
        });

        $value = $this->smartCache->rememberWithLock('report', 60, fn () => 'regenerated');

        $this->assertSame('regenerated', $value);
        $this->assertSame(2, $this->store->nextLock->attempts);
        $this->assertTrue($this->store->nextLock->released);
    }

    public function test_wait_timeout_regenerates_instead_of_failing(): void
    {
        $this->store->nextLock = new ScriptedLock(fn () => false);

        $value = $this->smartCache->rememberWithLock('report', 60, fn () => 'regenerated', 10, 0);

        $this->assertSame('regenerated', $value);
        $this->assertSame('regenerated', $this->smartCache->get('report'));
        $this->assertFalse($this->store->nextLock->released, 'a lock that was never acquired must not be released');
    }

    public function test_zero_wait_does_not_block_and_leaves_a_foreign_lock_alone(): void
    {
        $foreign = $this->store->lock($this->lockName('report'), 10);
        $this->assertTrue($foreign->get());

        $value = $this->smartCache->rememberWithLock('report', 60, fn () => 'regenerated', 10, 0);

        $this->assertSame('regenerated', $value);
        $this->assertFalse(
            $this->store->lock($this->lockName('report'), 10)->get(),
            'the lock held by another owner must still be held'
        );
    }

    public function test_unavailable_lock_backend_falls_back_to_regenerating(): void
    {
        $this->store->lockException = new \RuntimeException('cache_locks table missing');

        $value = $this->smartCache->rememberWithLock('report', 60, fn () => 'regenerated');

        $this->assertSame('regenerated', $value);
        $this->assertSame('regenerated', $this->smartCache->get('report'));
    }

    public function test_store_without_lock_support_behaves_like_remember(): void
    {
        $smartCache = $this->makeSmartCache(new NonLockingStore());

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return 'value';
        };

        $this->assertSame('value', $smartCache->rememberWithLock('report', 60, $callback));
        $this->assertSame('value', $smartCache->rememberWithLock('report', 60, $callback));
        $this->assertSame(1, $calls);
    }

    public function test_callback_exception_propagates_and_releases_the_lock(): void
    {
        try {
            $this->smartCache->rememberWithLock('report', 60, function () {
                throw new \DomainException('upstream down');
            });
            $this->fail('The callback exception should propagate.');
        } catch (\DomainException $e) {
            $this->assertSame('upstream down', $e->getMessage());
        }

        $this->assertFalse($this->smartCache->has('report'));
        $this->assertTrue($this->store->lock($this->lockName('report'), 10)->get());
    }

    public function test_cached_null_is_a_hit(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return null;
        };

        $this->assertNull($this->smartCache->rememberWithLock('nothing', 60, $callback));
        $this->assertNull($this->smartCache->rememberWithLock('nothing', 60, $callback));
        $this->assertSame(1, $calls);
    }

    public function test_namespace_scopes_the_value_and_the_lock(): void
    {
        $value = $this->smartCache->namespace('tenant_1')->rememberWithLock('report', 60, fn () => 'tenant-1');

        $this->assertSame('tenant-1', $value);
        $this->assertSame([$this->lockName('tenant_1:report')], $this->store->lockRequests);
        $this->assertSame('tenant-1', $this->smartCache->withoutNamespace()->get('tenant_1:report'));
        $this->assertNull($this->smartCache->get('report'));
    }

    public function test_active_tags_are_applied_to_the_regenerated_value(): void
    {
        $this->smartCache->tags(['reports'])->rememberWithLock('report', 60, fn () => 'tagged');

        $this->assertTrue($this->smartCache->has('report'));

        $this->smartCache->flushTags(['reports']);

        $this->assertFalse($this->smartCache->has('report'));
    }

    public function test_large_values_are_still_optimized(): void
    {
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
        $data = $this->createChunkableData();

        $this->assertSame($data, $smartCache->rememberWithLock('large', 60, fn () => $data));
        $this->assertValueIsChunked($this->app['cache']->store()->get('large'));
        $this->assertSame($data, $smartCache->rememberWithLock('large', 60, fn () => []));
    }

    public function test_facade_exposes_remember_with_lock(): void
    {
        $this->assertSame('via-facade', SmartCacheFacade::rememberWithLock('facade_key', 60, fn () => 'via-facade'));
        $this->assertSame('via-facade', SmartCacheFacade::rememberWithLock('facade_key', 60, fn () => 'other'));
        $this->assertSame('named', SmartCacheFacade::rememberWithLock(
            'facade_named',
            60,
            fn () => 'named',
            lockSeconds: 30,
            waitSeconds: 5
        ));
    }
}

/**
 * Array store whose next lock can be replaced, and which records lock requests.
 */
class ScriptedLockStore extends ArrayStore
{
    /** @var array<int, string> */
    public array $lockRequests = [];

    public ?ScriptedLock $nextLock = null;

    public ?\Throwable $lockException = null;

    public function lock($name, $seconds = 0, $owner = null)
    {
        if (str_starts_with((string) $name, '_sc_remember_lock:')) {
            $this->lockRequests[] = $name;

            if ($this->lockException !== null) {
                throw $this->lockException;
            }

            if ($this->nextLock !== null) {
                return $this->nextLock;
            }
        }

        return parent::lock($name, $seconds, $owner);
    }
}

/**
 * Lock whose acquisition attempts run a script, standing in for another process.
 */
class ScriptedLock implements Lock
{
    public bool $released = false;

    public int $attempts = 0;

    /** @var \Closure */
    protected \Closure $onAttempt;

    public function __construct(\Closure $onAttempt)
    {
        $this->onAttempt = $onAttempt;
    }

    public function get($callback = null)
    {
        $this->attempts++;

        return ($this->onAttempt)();
    }

    public function block($seconds, $callback = null)
    {
        return $this->get($callback);
    }

    public function release()
    {
        $this->released = true;

        return true;
    }

    public function owner()
    {
        return 'scripted';
    }

    public function forceRelease()
    {
        $this->released = true;
    }
}

/**
 * A store with no atomic lock support (does not implement LockProvider).
 */
class NonLockingStore implements Store
{
    protected ArrayStore $inner;

    public function __construct()
    {
        $this->inner = new ArrayStore();
    }

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        $value = $this->inner->get($key);

        return $value !== null && $this->inner->put($key, $value, $seconds);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function getPrefix()
    {
        return '';
    }
}
