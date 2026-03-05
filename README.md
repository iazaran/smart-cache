# Laravel SmartCache

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![GitHub Stars](https://img.shields.io/github/stars/iazaran/smart-cache?style=flat-square)](https://github.com/iazaran/smart-cache)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Tests](https://img.shields.io/badge/tests-425%20passed-brightgreen?style=flat-square)](https://github.com/iazaran/smart-cache/actions)

**Drop-in replacement for Laravel's `Cache` facade** that automatically compresses, chunks, and optimizes cached data — with write deduplication, self-healing recovery, and cost-aware eviction built in.

Implements `Illuminate\Contracts\Cache\Repository` and PSR-16 `SimpleCache`. Your existing code works unchanged.

**PHP 8.1+ · Laravel 8–12 · Redis, File, Database, Memcached, Array**

---

## Installation

```bash
composer require iazaran/smart-cache
```

That's it. No configuration required — works immediately with your existing cache driver.

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

Large data is automatically compressed and chunked behind the scenes. No code changes needed.

## Why SmartCache?

| Problem | Without SmartCache | With SmartCache |
|---|---|---|
| Large payloads (100 KB+) | Stored as-is, slow reads | Auto-compressed & chunked |
| Redundant writes | Every `put()` hits the store | Skipped when unchanged (write deduplication) |
| Corrupted entries | Exception crashes the request | Auto-evicted and regenerated (self-healing) |
| Eviction decisions | LRU / random | Cost-aware scoring — keeps high-value keys |
| Cache stampede | Thundering herd on expiry | XFetch, jitter, and rate limiting |
| Conditional caching | Manual `if` around `put()` | `rememberIf()` — one-liner |
| Stale data serving | Not available | SWR, stale, refresh-ahead, async queue refresh |
| Observability | DIY logging | Built-in dashboard, metrics, and health checks |

### How Automatic Optimization Works

SmartCache selects the best strategy based on your data — zero configuration:

| Data Profile | Strategy Applied | Effect |
|---|---|---|
| Arrays with 5 000+ items | Chunking | Lower memory, faster access |
| Serialized data > 50 KB | Compression | Significant size reduction (gzip) |
| API responses > 100 KB | Chunking + Compression | Best of both |
| Data < 50 KB | None | Zero overhead |

All thresholds are [configurable](#configuration).

## Features

Every feature below is **opt-in** and backward-compatible.

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
// Serve stale data while refreshing in background
$data = SmartCache::swr('github_repos', fn() => Http::get('...')->json(), 300, 900);

// Extended stale serving (1 h fresh, 24 h stale)
$config = SmartCache::stale('site_config', fn() => Config::fromDatabase(), 3600, 86400);

// Proactive refresh before expiry
$analytics = SmartCache::refreshAhead('daily_analytics', fn() => Analytics::generateReport(), 1800, 300);

// Queue-based background refresh — returns stale immediately
$data = SmartCache::asyncSwr('dashboard_stats', fn() => Stats::generate(), 300, 900, 'cache-refresh');
```

### Stampede Protection

```php
// XFetch algorithm — probabilistic early refresh
$data = SmartCache::rememberWithStampedeProtection('key', 3600, fn() => expensiveQuery());

// Rate-limited regeneration
SmartCache::throttle('api_call', 10, 60, fn() => expensiveApiCall());

// TTL jitter — prevents thundering herd on expiry
SmartCache::withJitter(0.1)->put('popular_data', $data, 3600);
// Actual TTL: 3240–3960 s (±10 %)
```

### Write Deduplication (Cache DNA)

Hashes every value before writing. Identical content → write skipped entirely.

```php
SmartCache::put('app_config', Config::all(), 3600);
SmartCache::put('app_config', Config::all(), 3600); // no I/O — data unchanged
```

### Self-Healing Cache

Corrupted entries are auto-evicted and regenerated on next read — zero downtime.

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

### Model Auto-Invalidation

```php
use SmartCache\Traits\CacheInvalidation;

class User extends Model
{
    use CacheInvalidation;

    public function getCacheKeysToInvalidate(): array
    {
        return ["user_{$this->id}_profile", "user_{$this->id}_posts", 'users_list_*'];
    }
}
```

### Encryption at Rest

```php
// config/smart-cache.php → strategies.encryption
'encryption' => [
    'enabled' => true,
    'keys' => ['user_token_abc123'],          // exact cache-key match
    'patterns' => ['/^user_token_/', '/^payment_/'],  // regex match
],
```

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
```

### Monitoring & Dashboard

```php
SmartCache::getPerformanceMetrics(); // hit_ratio, compression_savings, timing
SmartCache::analyzePerformance();    // health score + recommendations
```

```php
// Enable web dashboard
'dashboard' => ['enabled' => true, 'prefix' => 'smart-cache', 'middleware' => ['web', 'auth']],
// GET /smart-cache/dashboard | /smart-cache/stats | /smart-cache/health
```

```bash
php artisan smart-cache:status
php artisan smart-cache:clear
php artisan smart-cache:warm --warmer=products --warmer=categories
php artisan smart-cache:cleanup-chunks
```

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
    'circuit_breaker' => ['enabled' => false, 'failure_threshold' => 5, 'recovery_timeout' => 30],
    'rate_limiter'    => ['enabled' => true, 'window' => 60, 'max_attempts' => 10],
    'jitter'          => ['enabled' => false, 'percentage' => 0.1],
    'deduplication'   => ['enabled' => true],   // Write deduplication (Cache DNA)
    'self_healing'    => ['enabled' => true],   // Auto-evict corrupted entries
    'dashboard'       => ['enabled' => false, 'prefix' => 'smart-cache', 'middleware' => ['web']],
    'warmers'         => [],                    // Cache warmer classes for smart-cache:warm
];
```

## Migration from Laravel Cache

Change one import — everything else stays the same:

```php
- use Illuminate\Support\Facades\Cache;
+ use SmartCache\Facades\SmartCache;

SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');
```

## Documentation

[Full documentation →](https://iazaran.github.io/smart-cache/) — Installation, API reference, SWR patterns, and more.

## Testing

```bash
composer test            # 425 tests, 1 780+ assertions
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