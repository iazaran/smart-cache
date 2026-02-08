# Laravel SmartCache

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg?style=flat-square)](https://packagist.org/packages/iazaran/smart-cache)
[![Tests](https://img.shields.io/badge/tests-415%20passed-brightgreen?style=flat-square)](https://github.com/iazaran/smart-cache/actions)

A drop-in replacement for Laravel's `Cache` facade that automatically compresses, chunks, and optimizes cached data. Implements `Illuminate\Contracts\Cache\Repository` and PSR-16 `SimpleCache` — your existing code works unchanged.

**PHP 8.1+ · Laravel 8–12 · All cache drivers**

## Installation

```bash
composer require iazaran/smart-cache
```

No configuration required. Works immediately with any cache driver (Redis, File, Database, Memcached, Array).

## Quick Start

```php
use SmartCache\Facades\SmartCache;

// Same API as Laravel's Cache facade — with automatic optimization
SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');

// Remember pattern
$users = SmartCache::remember('users', 3600, fn() => User::all());

// Helper function
smart_cache(['products' => $products], 3600);
$products = smart_cache('products');
```

Large data is automatically compressed and chunked behind the scenes. No code changes needed.

### Multiple Cache Drivers

```php
// Each store preserves all SmartCache optimizations
SmartCache::store('redis')->put('key', $value, 3600);
SmartCache::store('memcached')->remember('users', 3600, fn() => User::all());

// Bypass SmartCache when needed
SmartCache::repository('redis')->put('key', $value, 3600);
```

SmartCache implements `Illuminate\Contracts\Cache\Repository`, so it works anywhere Laravel's Cache contract is expected.

## Automatic Optimization

SmartCache selects the best strategy based on your data:

| Data Profile | Strategy | Effect |
|---|---|---|
| Arrays with 5 000+ items | Chunking | Lower memory, faster access |
| Serialized data > 50 KB | Compression | Significant size reduction (gzip) |
| API responses > 100 KB | Chunking + Compression | Best of both |
| Data < 50 KB | None | Zero overhead |

All thresholds are configurable. See [Configuration](#configuration).

## Advanced Features

Every feature below is **opt-in** and backward-compatible.

### Atomic Locks

```php
SmartCache::lock('expensive_operation', 10)->get(function () {
    return regenerateExpensiveData();
});
```

### In-Request Memoization

```php
$memo = SmartCache::memo();
$users = $memo->remember('users', 3600, fn() => User::all());
$users = $memo->get('users'); // instant — served from memory
```

### Batch Operations

```php
$values = SmartCache::many(['key1', 'key2', 'key3']);
SmartCache::putMany(['key1' => $a, 'key2' => $b], 3600);
SmartCache::deleteMultiple(['key1', 'key2', 'key3']);
```

### Adaptive Compression

```php
// Adjusts compression level per entry based on access frequency and compressibility
config(['smart-cache.strategies.compression.mode' => 'adaptive']);
```

### Lazy Loading

```php
config(['smart-cache.strategies.chunking.lazy_loading' => true]);

$dataset = SmartCache::get('100k_records'); // LazyChunkedCollection
foreach ($dataset as $record) { /* max 3 chunks in memory */ }
```

### Cache Events

```php
config(['smart-cache.events.enabled' => true]);

Event::listen(CacheHit::class, fn($e) => Log::info("Hit: {$e->key}"));
Event::listen(CacheMissed::class, fn($e) => Log::warning("Miss: {$e->key}"));
Event::listen(OptimizationApplied::class, fn($e) => Log::info("Optimized: {$e->key}"));
```

### Encryption at Rest

```php
// config/smart-cache.php
'encryption' => [
    'enabled' => true,
    'keys' => ['user_*', 'payment_*'],
],
```

### Namespacing

```php
SmartCache::namespace('api_v2')->put('users', $users, 3600);
SmartCache::flushNamespace('api_v2');
```

### TTL Jitter

```php
SmartCache::withJitter(0.1)->put('popular_data', $data, 3600);
// Actual TTL: 3240–3960 s (±10 %) — prevents thundering herd
```

### Circuit Breaker

```php
$data = SmartCache::withFallback(
    fn() => SmartCache::get('key'),
    fn() => $this->fallbackSource()
);
```

### Stampede Protection

```php
// XFetch algorithm — probabilistic early refresh
$data = SmartCache::rememberWithStampedeProtection('key', 3600, fn() => expensiveQuery());

// Rate-limited regeneration
SmartCache::throttle('api_call', 10, 60, fn() => expensiveApiCall());
```

### Cost-Aware Caching

Implements a GreedyDual-Size–inspired scoring model. Every `remember()` call tracks regeneration cost, access frequency, and entry size to compute a value score:

```
score = (cost × ln(1 + access_count) × decay) / size
```

```php
// Works transparently — just use remember()
SmartCache::remember('analytics', 3600, fn() => AnalyticsService::generateReport());

// Inspect value scores
SmartCache::getCacheValueReport();
SmartCache::cacheValue('analytics');
SmartCache::suggestEvictions(5); // lowest-value entries to remove first
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

### Cache Warming

```php
php artisan smart-cache:warm
php artisan smart-cache:warm --keys=products,categories
```

### Orphan Chunk Cleanup

```bash
php artisan smart-cache:cleanup-chunks
```

### Statistics Dashboard

```php
'dashboard' => ['enabled' => true, 'prefix' => 'smart-cache', 'middleware' => ['web', 'auth']],
// GET /smart-cache/dashboard | /smart-cache/stats | /smart-cache/health
```

## SWR Patterns (Laravel 12+)

```php
// Stale-While-Revalidate
$data = SmartCache::swr('github_repos', fn() => Http::get('...')->json(), 300, 900);

// Extended stale serving
$config = SmartCache::stale('site_config', fn() => Config::fromDatabase(), 3600, 86400);

// Refresh-ahead
$analytics = SmartCache::refreshAhead('daily_analytics', fn() => Analytics::generateReport(), 1800, 300);

// Queue-based background refresh — returns stale data immediately, refreshes asynchronously
$data = SmartCache::asyncSwr('dashboard_stats', fn() => Stats::generate(), 300, 900, 'cache-refresh');
```

## Invalidation

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

## Monitoring

```php
SmartCache::getPerformanceMetrics(); // hit_ratio, compression_savings, timing
SmartCache::analyzePerformance();    // health score, recommendations

SmartCache::executeCommand('status');
SmartCache::executeCommand('clear', ['key' => 'api_data']);
```

```bash
php artisan smart-cache:status
php artisan smart-cache:status --force
php artisan smart-cache:clear
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
    ],
    'monitoring'      => ['enabled' => true, 'metrics_ttl' => 3600],
    'circuit_breaker' => ['enabled' => true, 'failure_threshold' => 5, 'timeout' => 30],
    'rate_limiter'    => ['enabled' => true, 'default_limit' => 100, 'window' => 60],
    'encryption'      => ['enabled' => false, 'keys' => []],
    'jitter'          => ['enabled' => false, 'percentage' => 0.1],
    'dashboard'       => ['enabled' => false, 'prefix' => 'smart-cache', 'middleware' => ['web']],
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

[Full documentation](https://iazaran.github.io/smart-cache/) — Installation, API reference, SWR patterns, and more.

## Testing

```bash
composer test            # 415 tests, 1 732 assertions
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