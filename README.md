# Laravel SmartCache

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![GitHub Stars](https://img.shields.io/github/stars/iazaran/smart-cache?style=flat-square&label=stars)](https://github.com/iazaran/smart-cache/stargazers)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Tests](https://img.shields.io/github/actions/workflow/status/iazaran/smart-cache/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iazaran/smart-cache/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/github/actions/workflow/status/iazaran/smart-cache/code-analysis.yml?branch=main&label=coverage&style=flat-square)](https://github.com/iazaran/smart-cache/actions/workflows/code-analysis.yml)

**A Laravel cache optimizer for large payloads.** SmartCache wraps Laravel's familiar cache API with automatic compression, driver-safe chunking, write deduplication, self-healing recovery, and operational diagnostics.

SmartCache implements `Illuminate\Contracts\Cache\Repository`, which extends the PSR-16 interface. Most Laravel cache calls migrate with an import change; the [cache-clearing semantics](#cache-clearing-semantics) are intentionally explicit for SmartCache 1.x.

**PHP 8.1–8.5 · Laravel 8–13 · Redis, File, Database, Memcached, Array**

---

## Installation

**Recommended:** enable `ext-zlib` to use gzip compression. JSON support is built into PHP 8+.

```bash
composer require iazaran/smart-cache
```

No configuration is required to start. SmartCache uses your existing Laravel cache driver and ships with documented defaults.

### Compatibility and support

SmartCache tests Laravel 8–13 and PHP 8.1–8.5. Compatibility with an older framework or PHP release does not extend that dependency's upstream security lifetime. For new and enterprise deployments, use a [currently supported PHP release](https://www.php.net/supported-versions.php) and a Laravel version inside the [official Laravel support window](https://laravel.com/docs/releases#support-policy).

## Quick Start

```php
use SmartCache\Facades\SmartCache;

// Same API you already know
SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');

// Remember pattern — with automatic compression & cost tracking
$users = SmartCache::remember('users', 3600, fn() => User::all());

// Helper function
smart_cache(['products' => $products], 3600);
$products = smart_cache('products');
```

Large values are optimized behind the same familiar API. Adoption can be gradual: change the facade import only where SmartCache is useful.

## Why SmartCache?

| Problem | Without SmartCache | With SmartCache |
|---|---|---|
| Large payloads | Stored as one raw value | Auto-compressed or split into driver-safe chunks |
| Driver size limits | Application-managed splitting | Transparent chunking and key-preserving restore |
| Cross-driver invalidation | Driver-specific tags or custom indexes | Tags, dependencies, patterns, and model hooks |
| Redundant writes | Every `put()` hits the store | Skipped when unchanged (write deduplication) |
| Corrupted entries | Exception crashes the request | Auto-evicted and regenerated, including broken chunk sets |
| Eviction decisions | LRU / random | Cost-aware scoring — keeps high-value keys |
| Observability | DIY logging | Built-in dashboard, metrics, and health checks |

### How Automatic Optimization Works

SmartCache selects the best enabled strategy based on your data; the defaults work without publishing configuration:

| Data Profile | Strategy Applied | Effect |
|---|---|---|
| Large arrays or collections meeting the chunk threshold | Chunking | Driver-safe splitting with key preservation |
| Compressible serialized data above 50 KB | Compression | Reduced stored size with gzip |
| Data below the configured thresholds | No size strategy | Value is stored without compression or chunking |

All thresholds are [configurable](#configuration). Monitoring, managed-key tracking, cost metadata, and write deduplication can still add small metadata or CPU costs even when no size strategy is selected; benchmark your real payloads and driver before production rollout.

### When to use SmartCache

- Large Eloquent results, reports, dashboards, or API payloads put pressure on Redis, Memcached, database, or file cache storage.
- You need consistent tag or dependency invalidation across drivers.
- You want cache diagnostics, corruption recovery, and measurable optimization behavior without building a parallel cache layer.

### When not to use SmartCache

- Most values are already small and the native Laravel cache meets your latency and operational needs.
- Payloads are already compressed or encrypted and will not benefit from gzip.
- You cannot budget the extra metadata keys used for tracking, deduplication, metrics, tags, dependencies, or chunks.

### Production Safety for Large Data

SmartCache is built for the painful cases that appear after an application grows: large Eloquent result sets, API payloads, reports, dashboards, and Redis/Memcached entries that get too big to manage safely.

- **Data shape is preserved.** Chunked payloads keep associative keys and sparse numeric keys intact, so ID-keyed arrays do not come back reindexed.
- **Partial chunk loss is recoverable.** If a chunk is missing or corrupted, SmartCache treats the entry as a cache miss, evicts the broken metadata, and lets `remember()` regenerate a clean value.
- **Null remains a valid cached value.** Stored `null` is distinguished from a miss, preserving Laravel cache semantics while still enabling self-healing.
- **Raw repository access is still available.** Use `SmartCache::repository()` when a package or one-off operation needs the underlying Laravel cache store directly.

## Features

Compression, chunking, monitoring, cost tracking, write deduplication, and self-healing have documented enabled defaults. Advanced behavior such as encryption, serialization, TTL jitter, the circuit breaker, cache events, and the dashboard remains opt-in.

### Multiple Cache Drivers

```php
// Each store preserves all SmartCache optimizations
SmartCache::store('redis')->put('key', $value, 3600);
SmartCache::store('memcached')->remember('users', 3600, fn() => User::all());

// Bypass SmartCache when needed
SmartCache::repository('redis')->put('key', $value, 3600);
```

### SWR Patterns (Stale-While-Revalidate)

```php
// Due refreshes run synchronously before this method returns stale data
$data = SmartCache::swr('github_repos', fn() => Http::get('...')->json(), 300, 900);

// Extended stale serving (1 h fresh, 24 h stale)
$config = SmartCache::stale('site_config', fn() => Config::fromDatabase(), 3600, 86400);

// Proactive refresh before expiry
$analytics = SmartCache::refreshAhead('daily_analytics', fn() => Analytics::generateReport(), 1800, 300);

// Queue-based background refresh — use a serializable invokable class
$data = SmartCache::asyncSwr('dashboard_stats', RefreshDashboardStats::class, 300, 900, 'cache-refresh');
```

> **How refresh execution works.** `swr()`, `stale()` and `refreshAhead()` execute a due refresh callback **synchronously** in the current PHP process before the method returns the stale value. Use `asyncSwr()` with a Laravel queue worker when the caller must not wait for regeneration. **`asyncSwr()` does not accept `Closure` callbacks** — pass either a serializable invokable class or a `"Class@method"` string. Closures throw `InvalidArgumentException` since v1.12.0 so the failure is loud at dispatch time instead of inside the queue serializer.
>
> **Single-flight refresh (opt-in, v1.12.0+).** Set `smart-cache.swr.single_flight = true` to wrap the synchronous refresh in an opportunistic non-blocking lock keyed on `_sc_swr_refresh:{key}`. When the cache store implements `LockProvider` (redis, memcached, database, dynamodb, file) only one worker regenerates a stale entry; concurrent workers keep serving stale and return immediately. Default `false` preserves the historical "every worker refreshes" behaviour.

### Stampede Protection

```php
// Single-flight regeneration — on a miss, only one process runs the callback
$report = SmartCache::rememberWithLock('daily_report', 3600, fn() => Report::build());

// XFetch algorithm — probabilistic early refresh
$data = SmartCache::rememberWithStampedeProtection('key', 3600, fn() => expensiveQuery());

// Rate-limited regeneration
SmartCache::throttle('api_call', 10, 60, fn() => expensiveApiCall());

// TTL jitter — prevents thundering herd on expiry
SmartCache::withJitter(0.1)->put('popular_data', $data, 3600);
// Actual TTL: 3240–3960 s (±10 %)

// Explicit per-call jitter — applies regardless of the global jitter.enabled flag
SmartCache::putWithJitter('popular_data', $data, 3600, 0.2);
SmartCache::rememberWithJitter('report', 3600, 0.1, fn() => Analytics::generate());
```

> **Single-flight regeneration (v1.15.0+).** When a hot key is missing — after a deploy, a flush, or an invalidation — `remember()` lets every concurrent request run the callback at once. `rememberWithLock()` runs it under an atomic lock instead: one process regenerates, the others wait (10 s by default) and then read the stored value. Cache hits never touch the lock. If the store has no lock support (`LockProvider`) or the wait times out, the callback runs anyway, so the method never fails where `remember()` would succeed. On the database cache driver, locks need the `cache_locks` table that `php artisan cache:table` creates. Tune both limits per call: `rememberWithLock($key, $ttl, $callback, lockSeconds: 30, waitSeconds: 5)`.

### Write Deduplication (Cache DNA)

Hashes every value before writing. When the content is unchanged **and** the cached entry already outlives the TTL being requested, the value rewrite is skipped.

```php
SmartCache::put('app_config', Config::all(), 3600);

// Same content, and the stored entry already covers this window → rewrite skipped
SmartCache::put('app_config', Config::all(), 60);

// Same content, but this asks the entry to live longer → written, so the TTL is honoured
SmartCache::put('app_config', Config::all(), 7200);
```

A write that would extend the expiry is never skipped: deduplication must not shorten an entry's lifetime.

### Self-Healing Cache

Corrupted entries are auto-evicted instead of being returned. With `remember()`-style reads, the callback can regenerate the value on the next read. This includes missing chunks from large chunked payloads.

```php
$report = SmartCache::remember('report', 3600, fn() => Analytics::generate());
```

### Conditional Caching

```php
$data = SmartCache::rememberIf('external_api', 3600,
    fn() => Http::get('https://api.example.com/data')->json(),
    fn($value) => !empty($value) && isset($value['status'])
);
```

### Cost-Aware Eviction

GreedyDual-Size–inspired scoring: `score = (cost × ln(1 + access_count) × decay) / size`

```php
SmartCache::remember('analytics', 3600, fn() => AnalyticsService::generateReport());
SmartCache::getCacheValueReport();       // all entries ranked by value
SmartCache::suggestEvictions(5);         // lowest-value entries to remove
```

### Circuit Breaker & Fallback

```php
// With the circuit breaker enabled (circuit_breaker.enabled or withCircuitBreaker()),
// a failing or open circuit returns the fallback; a Closure fallback is called.
$data = SmartCache::withFallback(
    fn() => SmartCache::get('key'),
    fn() => $this->fallbackSource()
);
```

### In-Request Memoization

```php
$memo = SmartCache::memo();
$users = $memo->remember('users', 3600, fn() => User::all());
$users = $memo->get('users'); // instant — served from memory
```

### Atomic Locks

```php
SmartCache::lock('expensive_operation', 10)->get(function () {
    return regenerateExpensiveData();
});
```

### Namespacing

```php
SmartCache::namespace('api_v2')->put('users', $users, 3600);
SmartCache::flushNamespace('api_v2');
```

### Cache Invalidation

```php
// Pattern-based
SmartCache::flushPatterns(['user_*', 'api_v2_*', '/product_\d+/']);

// Dependency tracking
SmartCache::dependsOn('user_posts', 'user_profile');
SmartCache::invalidate('user_profile'); // also clears user_posts

// Tag-based
SmartCache::tags(['users'])->put('user_1', $user, 3600);
SmartCache::flushTags(['users']);
```

Tags set with `tags()` apply to the next write. After a tagged read such as `get()` or `has()`, they still apply to a write of the same key, so the "read, then write on a miss" pattern stays tagged, but they never tag a write of a different key. Use `flushTags()` to invalidate by tag; `flush()` clears the entire store.

### Model Auto-Invalidation

```php
use SmartCache\Traits\CacheInvalidation;

class User extends Model
{
    use CacheInvalidation;

    protected function cacheInvalidation(): array
    {
        return [
            'keys' => ['user_{id}_profile', 'user_{id}_posts'],
            'tags' => ['users', 'user_{id}', 'team_{team_id}'],
            'patterns' => ['users_list_*'],
            'dependencies' => ['team_{team_id}_summary'],
        ];
    }
}

// Event-blind writes can flush explicitly:
User::where('status', 'inactive')->update(['archived' => true]);
User::flushCacheTags(['users']);
```

Model invalidation is deferred until the current database transaction commits by default (`smart-cache.model_invalidation.after_commit = true`). This prevents another request from re-caching pre-commit data between an Eloquent event and the final commit. Set the flag to `false` if you need the historical immediate behavior.

Eloquent events do not fire for `saveQuietly()`, query-builder `update()` / `delete()`, `upsert()`, mass `insert()`, or raw SQL. For those paths, call `flushCacheTags()` or `SmartCache::flushTags()` explicitly.

### Choosing Cache Tags

Tags should describe the data used to build a response, not the controller that built it. Start with the tables or models read by the endpoint: list endpoints usually use coarse tags such as `products`, while item endpoints can add instance tags such as `product_123`. Over-tagging causes extra refreshes; under-tagging leaves stale data behind. For hard-to-map endpoints, enable Laravel's query log in a test and compare the tables read during response generation with the tags declared for that cache entry.

### Encryption at Rest

```php
// config/smart-cache.php → strategies.encryption
'encryption' => [
    'enabled' => true,
    'keys' => ['user_token_abc123'],          // exact cache-key match
    'patterns' => ['/^user_token_/', '/^payment_/'],  // regex match
],
```

Keys and patterns match with or without the active namespace. Matching values are always encrypted, whatever their size: encryption takes precedence over compression and chunking, so a large encrypted value is stored as a single entry.

### Adaptive Compression

```php
config(['smart-cache.strategies.compression.mode' => 'adaptive']);
// Hot data → fast compression (level 3–4), cold data → high compression (level 7–9)
```

### Lazy Loading

```php
config(['smart-cache.strategies.chunking.lazy_loading' => true]);
$dataset = SmartCache::get('100k_records'); // LazyChunkedCollection
foreach ($dataset as $record) { /* max 3 chunks in memory */ }
```

### Batch Operations

```php
$values = SmartCache::many(['key1', 'key2', 'key3']);
SmartCache::putMany(['key1' => $a, 'key2' => $b], 3600);
SmartCache::deleteMultiple(['key1', 'key2', 'key3']);
```

### Cache Events

```php
config(['smart-cache.events.enabled' => true]);
Event::listen(CacheHit::class, fn($e) => Log::info("Hit: {$e->key}"));
Event::listen(CacheMissed::class, fn($e) => Log::warning("Miss: {$e->key}"));
Event::listen(TagFlushed::class, fn($e) => Log::notice("Flushed {$e->tag}", [
    'keys' => $e->keyCount,
    'source' => $e->source, // manual, model, or model_helper
]));
```

### Monitoring & Dashboard

```php
SmartCache::getPerformanceMetrics(); // hit_ratio, compression_savings, timing
SmartCache::analyzePerformance();    // health score + recommendations
```

```php
// Enable web dashboard
'dashboard' => ['enabled' => true, 'prefix' => 'smart-cache', 'middleware' => ['web', 'auth']],
// GET /smart-cache | /smart-cache/statistics | /smart-cache/health | /smart-cache/keys | /smart-cache/commands
```

> **Protect these routes.** The shipped default is `['web']`, which provides session and CSRF handling but **no authentication**. The endpoints expose your managed cache key list, optimization statistics, and internal metrics — cache keys routinely embed user, tenant, or record identifiers. Add an authentication and authorization middleware (`['web', 'auth']`, `['web', 'auth', 'can:viewSmartCache']`, or your own gate) before enabling the dashboard outside local development. It is disabled by default.

```bash
php artisan smart-cache:status
php artisan smart-cache:audit --driver=redis
php artisan smart-cache:bench --driver=redis --iterations=5
php artisan smart-cache:clear
php artisan smart-cache:warm --warmer=products --warmer=categories
php artisan smart-cache:cleanup-chunks
```

### Cache Audit & Benchmarks

Use the audit command before production changes or after a cache incident:

```bash
php artisan smart-cache:audit
php artisan smart-cache:audit --format=json
php artisan smart-cache:audit --driver=redis --limit=50
```

It reports managed keys, missing tracked keys, broken chunked entries, orphan chunks, large unoptimized values, and cost-aware eviction suggestions without mutating the cache.

Use the benchmark command to measure your own driver and payload behavior:

```bash
php artisan smart-cache:bench
php artisan smart-cache:bench --profile=api-json --driver=redis --iterations=10
php artisan smart-cache:bench --format=json --output=storage/smart-cache-bench.json
```

A generated Redis report is included at [`docs/benchmark-report-redis.json`](docs/benchmark-report-redis.json). On the included PHP 8.4 / Laravel 13 / Redis run, the `api-json` profile compressed from 323,811 bytes to 7,829 bytes (97.58% smaller). Each profile includes a `goal`, `success_metric`, `goal_passed`, and `result_summary` field so compression is judged by byte reduction, chunking is judged by driver-safe splitting and key preservation, and small payloads are judged by avoiding unnecessary optimization.

### Operational Footprint

SmartCache stores internal metadata in the selected cache store. Depending on the enabled features, this can include `_sc_managed_keys`, `_sc_dna:{key}`, `_sc_meta:{key}`, `_sc_chunk_*`, `_sc_tag_*`, `_sc_dependencies`, `_sc_cost_metadata`, and `_sc_performance_metrics`. Use a dedicated cache store or Laravel cache prefix when applications share infrastructure, set `managed_keys.max_tracked` for high-cardinality workloads, and run `smart-cache:audit` before and after production changes.

## Best Practices & Troubleshooting

- **Binary Data:** Already-compressed payloads such as images, video, or encrypted data gain nothing from gzip. Write those keys through `SmartCache::repository()`, which bypasses SmartCache optimization.
- **Memory Limits with Chunking:** Large multi-megabyte datasets automatically trigger the 'chunking' strategy. For arrays over 100,000 items, verify `memory_limit` in `php.ini` or enable `lazy_loading` via config to avoid server crashes.
- **Provider Not Found:** Laravel aggressively caches service providers and aliases. Always run `php artisan optimize:clear` after upgrading or installing this package if encountering *"Class 'SmartCache' not found"*.
- **IDE Autocomplete:** Modern IDEs (PhpStorm, VSCode) completely resolve `SmartCache::` magical methods automatically via our included Facade PHPDocs without needing `ide-helper` generated files.

## Configuration

Publish the config file (optional — sensible defaults are applied automatically):

```bash
php artisan vendor:publish --tag=smart-cache-config
```

```php
// config/smart-cache.php (excerpt)
return [
    'thresholds' => [
        'compression' => 1024 * 50,  // 50 KB
        'chunking'    => 1024 * 100, // 100 KB
    ],
    'strategies' => [
        'compression' => ['enabled' => true, 'mode' => 'fixed', 'level' => 6],
        'chunking'    => ['enabled' => true, 'chunk_size' => 1000],
        'encryption'  => ['enabled' => false, 'keys' => []],
    ],
    'monitoring'      => ['enabled' => true, 'metrics_ttl' => 3600],
    'circuit_breaker' => [
        'enabled'           => false,
        'failure_threshold' => 5,
        'recovery_timeout'  => 30,
        'shared'            => false, // v1.12.0: share breaker state across workers via the cache
        'shared_ttl'        => 300,   // v1.12.0: TTL for the shared state key
    ],
    'rate_limiter'    => ['enabled' => true, 'window' => 60, 'max_attempts' => 10],
    'jitter'          => ['enabled' => false, 'percentage' => 0.1],
    'deduplication'   => ['enabled' => true],   // Write deduplication (Cache DNA)
    'self_healing'    => ['enabled' => true],   // Auto-evict corrupted entries
    'swr'             => ['single_flight' => false], // v1.12.0: opt-in single-flight refresh
    'managed_keys'    => ['max_tracked' => 0],       // v1.12.0: 0 = unlimited (default)
    'metadata_lock'   => ['enabled' => true, 'ttl' => 5, 'wait' => 1],
    'model_invalidation' => ['after_commit' => true],
    'dashboard'       => ['enabled' => false, 'prefix' => 'smart-cache', 'middleware' => ['web']],
    'warmers'         => [],                    // Cache warmer classes for smart-cache:warm
];
```

## Laravel Octane / Long-Running Workers

SmartCache is registered as a singleton, which means per-request state (active tags, namespaces, in-memory metric buffers) would normally leak between requests on **Laravel Octane**, **Swoole**, **FrankenPHP** or **RoadRunner**. Since **v1.12.0** the service provider's `terminating()` hook calls `SmartCache::reset()` and `OrphanChunkCleanupService::flush()` at the end of every request, so there is **nothing extra to configure**. If you embed SmartCache in your own long-running runtime, call `app(\SmartCache\Contracts\SmartCache::class)->reset()` between iterations.

## Cache Clearing Semantics

SmartCache 1.x preserves its historical managed-only behavior for `clear()`. Prefer the explicit method in new code:

```php
SmartCache::clearManaged(); // Remove SmartCache-tracked entries only
SmartCache::flush();        // Flush the entire underlying cache store
```

This is the one intentional behavioral difference to consider when using SmartCache through the PSR-16 interface, where `clear()` normally means clearing the entire cache pool. The existing behavior remains unchanged in 1.x for backward compatibility.

`clearManaged()` is available on the facade and concrete `SmartCache` class. It is intentionally not added to `SmartCache\Contracts\SmartCache` in the 1.x line, so existing third-party implementations of that contract do not break.

## Migration from Laravel Cache

Change one import for the cache operations you want SmartCache to optimize:

```php
- use Illuminate\Support\Facades\Cache;
+ use SmartCache\Facades\SmartCache;

SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');
```

Review calls to `clear()` during migration and choose `clearManaged()` or `flush()` explicitly. For incremental adoption, keep Laravel's `Cache` facade alongside SmartCache and migrate only large or operationally sensitive keys.

## Documentation

[Full documentation →](https://iazaran.github.io/smart-cache/) — Installation, API reference, SWR patterns, and more.

## Testing

```bash
composer test            # 593 tests, 2,231 assertions
composer test-coverage   # with code coverage
```

See [TESTING.md](TESTING.md) for details.

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).

## Links

- [Packagist](https://packagist.org/packages/iazaran/smart-cache)
- [GitHub Issues](https://github.com/iazaran/smart-cache/issues)
- [Documentation](https://iazaran.github.io/smart-cache/)
