# Laravel SmartCache

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![GitHub Stars](https://img.shields.io/github/stars/iazaran/smart-cache?style=flat-square&label=stars)](https://github.com/iazaran/smart-cache/stargazers)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Tests](https://img.shields.io/github/actions/workflow/status/iazaran/smart-cache/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iazaran/smart-cache/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/github/actions/workflow/status/iazaran/smart-cache/code-analysis.yml?branch=main&label=coverage&style=flat-square)](https://github.com/iazaran/smart-cache/actions/workflows/code-analysis.yml)

**Laravel's cache, built for large payloads and high traffic.** SmartCache sits on top of the cache store you already run (Redis, Memcached, DynamoDB, database, or file). It adds automatic compression, driver-safe chunking, stampede protection, invalidation that works on every driver, self-healing, and observability, and you keep the `Cache` API you already know.

```diff
- use Illuminate\Support\Facades\Cache;
+ use SmartCache\Facades\SmartCache;
```

**PHP 8.1–8.5 · Laravel 8–13 · Redis, Memcached, DynamoDB, Database, File, Array**

---

## Why SmartCache?

Caching gets hard once an application grows. Payloads outgrow Memcached's 1 MB and DynamoDB's 400 KB item limits. Redis memory and bandwidth bills climb. A deploy or a flush sends every request to the database at once. File and database stores have no tags at all.

| Problem | Laravel cache alone | With SmartCache |
|---|---|---|
| Large payloads | Stored as one raw value | Compressed automatically (an API payload measured 97.6% smaller) |
| Driver item limits | You split values yourself | Transparent, key-preserving chunking of large lists |
| Cold-key stampedes | Every request regenerates | One process regenerates, the others wait (`rememberWithLock()`) |
| Stale-while-revalidate | `flexible()` on Laravel 11+ | `swr()`, `stale()`, `refreshAhead()`, and queued refreshes on Laravel 8+ |
| Invalidation | Tags only on Redis, Memcached, and array stores | Tags, dependencies, patterns, and model hooks on every driver |
| Corrupted entries | A broken value or an exception | The entry is evicted and regenerated |
| Redundant writes | Every `put()` hits the store | Unchanged values are skipped |
| Visibility | Build it yourself | Metrics, health score, events, audit, and benchmark commands |

## Built for production

- **Drop-in, and adopt it gradually.** SmartCache implements Laravel's `Illuminate\Contracts\Cache\Repository` (and so PSR-16), including enum keys, the typed getters, `flexible()`, and `tags()`. Switch one import where it helps, and keep `Cache` everywhere else. `SmartCache::repository()` gives raw store access when you need it.
- **Safe to upgrade.** It follows semantic versioning and needs no configuration. New features are opt-in, every release lists its upgrade notes, and adjacent releases read each other's entries, so rolling deploys work.
- **Tested across the support matrix.** CI runs 700+ tests on PHP 8.1–8.5 with Laravel 8–13, on every combination Laravel itself supports.
- **Correct under concurrency.** Readers never get a mix of two chunked versions. Shared indexes are updated under short atomic locks where the store supports them. Regeneration can be single-flight.
- **Ready for long-running processes.** On Octane, Swoole, FrankenPHP, RoadRunner, and queue workers, per-request state is reset automatically.
- **Secure by default.** Data stays in your own cache store: no external service and no telemetry. Optional encryption protects selected keys at rest, `serializable_classes` extends Laravel's deserialization hardening to optimized values, and the web dashboard is off by default.
- **Observable.** Hit ratios, compression savings, cost-aware eviction scores, events, and `smart-cache:audit` show what the cache is doing.

## Installation

```bash
composer require iazaran/smart-cache
```

That's it: the package auto-registers and uses your default cache store. Enable `ext-zlib` for gzip compression (recommended).

## Quick start

```php
use SmartCache\Facades\SmartCache;

SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');

$report = SmartCache::remember('report', 3600, fn () => Report::build());

smart_cache(['products' => $products], 3600);   // helper
$products = smart_cache('products');
```

Values over 50 KB are compressed. Arrays and collections with more than 1,000 items (`chunk_size`) and over 100 KB are split into chunks instead. Smaller values are stored as they are.

## Features

### Stampede protection

```php
// One process regenerates a missing key; the others wait, then read it
$report = SmartCache::rememberWithLock('daily_report', 3600, fn () => Report::build());

// Probabilistic early refresh (XFetch), rate-limited regeneration, TTL jitter
$data = SmartCache::rememberWithStampedeProtection('key', 3600, fn () => expensiveQuery());
SmartCache::throttle('api_call', 10, 60, fn () => expensiveApiCall());
SmartCache::putWithJitter('popular', $data, 3600, 0.1); // TTL ±10%
```

`rememberWithLock()` falls back to running the callback when the store has no lock support or the wait times out, so it never fails where `remember()` would succeed.

### Stale-while-revalidate

```php
$repos  = SmartCache::swr('github_repos', fn () => Http::get('...')->json(), 300, 900);
$config = SmartCache::stale('site_config', fn () => Config::fromDatabase(), 3600, 86400);
$stats  = SmartCache::refreshAhead('analytics', fn () => Analytics::report(), 1800, 300);

// Return stale data now and refresh on a queue worker (invokable class, not a Closure)
$data = SmartCache::asyncSwr('dashboard', RefreshDashboardStats::class, 300, 900, 'cache-refresh');
```

`swr()`, `stale()`, and `refreshAhead()` refresh in the current process before returning the stale value. Enable `swr.single_flight` to let only one worker refresh a key at a time.

### Invalidation on every driver

```php
SmartCache::tags(['users'])->put('user_1', $user, 3600);
SmartCache::flushTags(['users']);              // or SmartCache::tags(['users'])->flush()

SmartCache::dependsOn('user_posts', 'user_profile');
SmartCache::invalidate('user_profile');        // also clears user_posts

SmartCache::flushPatterns(['user_*', '/product_\d+/']);

SmartCache::namespace('tenant_42')->put('settings', $settings, 3600);
SmartCache::flushNamespace('tenant_42');
```

Models can invalidate their own entries:

```php
class User extends Model
{
    use \SmartCache\Traits\CacheInvalidation;

    protected function cacheInvalidation(): array
    {
        return [
            'keys' => ['user_{id}_profile'],
            'tags' => ['users', 'team_{team_id}'],
            'patterns' => ['users_list_*'],
        ];
    }
}
```

Model invalidation runs after the database transaction commits. Eloquent events do not fire for query-builder `update()`/`delete()`, `upsert()`, or raw SQL; call `User::flushCacheTags()` or `SmartCache::flushTags()` after those.

### Resilience

- **Self-healing.** A corrupted entry, or a chunked entry with a missing chunk, is evicted instead of returned, so `remember()` regenerates it.
- **Write deduplication.** A `put()` of unchanged content is skipped when the stored entry already lives long enough.
- **Circuit breaker and fallback.** `SmartCache::withFallback(fn () => SmartCache::get('key'), fn () => $default)` serves a fallback while the backend is failing (opt-in).
- **Conditional caching.** `rememberIf($key, $ttl, $callback, fn ($value) => !empty($value))` keeps error payloads out of the cache.

### In-request memoization

```php
$memo = SmartCache::memo();
$users = $memo->remember('users', 3600, fn () => User::all());
$users = $memo->get('users'); // served from memory
```

On Laravel 12.9+, set `memo.native = true` to share one memo layer per store with Laravel's `Cache::memo()`.

### Encryption at rest

```php
// config/smart-cache.php → strategies
'encryption' => [
    'enabled' => true,
    'keys' => ['user_token_abc123'],
    'patterns' => ['/^user_token_/', '/^payment_/'],   // PCRE regexes, not globs
],
```

Matching values are always encrypted with your application key, whatever their size.

### Observability

```php
SmartCache::getPerformanceMetrics();   // hit ratio, compression savings, timings
SmartCache::analyzePerformance();      // health score and recommendations
SmartCache::suggestEvictions(5);       // lowest-value entries (cost-aware scoring)
```

```bash
php artisan smart-cache:status
php artisan smart-cache:audit --driver=redis      # broken chunks, orphans, large unoptimized values
php artisan smart-cache:bench --profile=api-json  # measure your own driver and payloads
php artisan smart-cache:warm --warmer=products
php artisan smart-cache:cleanup-chunks
```

Events (`CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`, `OptimizationApplied`, `TagFlushed`) are available with `events.enabled = true`. There is also an optional web dashboard. It is disabled by default; if you enable it, protect it with authentication middleware such as `['web', 'auth']`, because cache keys often contain user or tenant identifiers.

### Benchmarks

Run `php artisan smart-cache:bench` against your own store and payloads. On the included PHP 8.4 / Laravel 13 / Redis run ([`docs/benchmark-report-redis.json`](docs/benchmark-report-redis.json)), the `api-json` profile went from 323,811 to 7,829 bytes (97.58% smaller), chunked payloads kept their keys, and small payloads were left untouched.

## Configuration

No configuration is needed to start. To change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=smart-cache-config
```

```php
// config/smart-cache.php (excerpt)
'thresholds'    => ['compression' => 1024 * 50, 'chunking' => 1024 * 100],
'strategies'    => [
    'compression' => ['enabled' => true, 'mode' => 'fixed', 'level' => 6],
    'chunking'    => ['enabled' => true, 'chunk_size' => 1000, 'lazy_loading' => false],
    'encryption'  => ['enabled' => false, 'keys' => [], 'patterns' => []],
],
'deduplication' => ['enabled' => true],
'self_healing'  => ['enabled' => true],
'swr'           => ['single_flight' => false],
'tags'          => ['scoped' => false],   // recommended: true for new applications
'memo'          => ['native' => false],
'serializable_classes' => null,          // 'cache' applies Laravel's cache.serializable_classes
'dashboard'     => ['enabled' => false, 'middleware' => ['web']],
```

See the [configuration reference](https://iazaran.github.io/smart-cache/) for every option.

## Good to know

### Cache clearing semantics

```php
SmartCache::clearManaged(); // remove the entries SmartCache tracks
SmartCache::flush();        // flush the entire underlying store
```

For backward compatibility, `clear()` behaves like `clearManaged()` in SmartCache 1.x, while PSR-16 `clear()` normally empties the whole pool. Choose `clearManaged()` or `flush()` explicitly when you migrate. This is the main intentional difference from Laravel's cache.

### Tags

Tags work on every store because SmartCache keeps its own tag index. Tags set with `tags()` stay pending until a write uses them. With the default `tags.scoped = false`, a tagged `get()` that misses therefore also tags the next write, which only causes extra invalidation. New applications should set `tags.scoped = true`: tags then apply only to the keys looked up while they were pending.

### Operational footprint

SmartCache stores small metadata entries (`_sc_*`) in the same store: the managed-key index, tag and dependency indexes, deduplication records, metrics, and chunks. If applications share infrastructure, use a dedicated store or cache prefix. For very large key spaces, cap the index with `managed_keys.max_tracked`.

- On the database cache driver, `rememberWithLock()` and SmartCache's metadata locks need the `cache_locks` table (`php artisan cache:table`). Without it they run without the lock, and `rememberWithLock()` logs a warning on every miss.
- `smart-cache:clear --force` deletes every key in the store that SmartCache does not manage (SmartCache's own `_sc_*` keys excepted), much like `cache:clear`. Check what shares the store before running it.

### When SmartCache is not a fit

If most of your values are small and Laravel's cache already meets your latency and cost goals, or your payloads are already compressed or encrypted, the native cache is enough. You can still use SmartCache only for the keys that need it.

## Upgrading

Every 1.x release keeps the same API and reads the entries the previous release wrote. Read the [CHANGELOG](CHANGELOG.md) upgrade notes for each release before a production rollout.

## Documentation

[Full documentation →](https://iazaran.github.io/smart-cache/): installation, the complete API reference, SWR patterns, invalidation, Octane and queue workers, and troubleshooting.

## Testing

```bash
composer test
```

See [TESTING.md](TESTING.md).

## Contributing and security

Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
