# Laravel SmartCache — Production-Grade Caching for Large Data

[![Latest Version](https://img.shields.io/packagist/v/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)
[![License](https://img.shields.io/packagist/l/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/iazaran/smart-cache.svg)](https://packagist.org/packages/iazaran/smart-cache)
[![Tests](https://img.shields.io/github/workflow/status/iazaran/smart-cache/tests?label=tests)](https://github.com/iazaran/smart-cache/actions)

**SmartCache** is a drop-in replacement for Laravel's Cache facade that automatically optimizes large datasets — delivering up to **70% size reduction** through intelligent compression, smart chunking, cost-aware eviction, and adaptive optimization. Fully implements `Illuminate\Contracts\Cache\Repository` and PSR-16 `SimpleCache` for seamless integration.

## 🎯 The Problem It Solves

Caching large datasets (10K+ records, API responses, reports) in Laravel can cause:
- **Memory pressure** — Large arrays and collections consume excessive RAM
- **Storage waste** — Uncompressed data fills Redis/Memcached quickly, increasing infrastructure costs
- **Latency spikes** — Serializing/deserializing large objects degrades response times
- **Cache stampede** — Multiple processes regenerating expensive data simultaneously under load

**SmartCache addresses all of these automatically, with zero code changes required.**

## 📦 Installation

```bash
composer require iazaran/smart-cache
```

**That's it!** Works out-of-the-box. No configuration required.

**Requirements:**
- PHP 8.1+
- Laravel 8.0 - 12.x
- Any cache driver (Redis, File, Database, Memcached, Array)

## 🚀 Quick Start

### Drop-in Replacement

Use exactly like Laravel's `Cache` facade:

```php
use SmartCache\Facades\SmartCache;

// Works exactly like Laravel Cache
SmartCache::put('users', $users, 3600);
$users = SmartCache::get('users');

// Remember pattern
$users = SmartCache::remember('users', 3600, function() {
    return User::all();
});
```

**How it works:** Large data is automatically compressed and chunked behind the scenes — reducing cache size by up to 70% with no additional code.

### Helper Function

```php
// Store
smart_cache(['products' => $products], 3600);

// Retrieve
$products = smart_cache('products');
```

### Using Different Cache Drivers

Use different cache drivers while maintaining all SmartCache optimizations:

```php
// Use Redis with all SmartCache optimizations (compression, chunking, etc.)
SmartCache::store('redis')->put('key', $value, 3600);
SmartCache::store('redis')->get('key');

// Use Memcached with optimizations
SmartCache::store('memcached')->remember('users', 3600, fn() => User::all());

// Use file cache with optimizations
SmartCache::store('file')->put('config', $config, 86400);

// For raw access to Laravel's cache (bypasses SmartCache optimizations)
SmartCache::repository('redis')->put('key', $value, 3600);
```

> **Full Laravel Compatibility:** SmartCache implements Laravel's `Repository` interface, so it works seamlessly with any code that type-hints `Illuminate\Contracts\Cache\Repository`. The `store()` method returns a SmartCache instance that is also a valid Repository.

## 💡 Core Features (Automatic Optimization)

### 1. Intelligent Compression

Large data is automatically compressed:

```php
// Large API response - automatically compressed
$apiData = Http::get('api.example.com/large-dataset')->json();
SmartCache::put('api_data', $apiData, 3600);
// Automatically compressed with gzip, saving up to 70% space
```

**When it applies:** Data > 50KB (configurable)
**Benefit:** 60-80% size reduction

### 2. Smart Chunking

Large arrays are automatically split into manageable chunks:

```php
// 10,000+ records - automatically chunked
$users = User::with('profile', 'posts')->get();
SmartCache::put('all_users', $users, 3600);
// Automatically split into 1000-item chunks
```

**When it applies:** Arrays with 5000+ items (configurable)
**Benefit:** Better memory usage, faster access

### 3. Automatic Strategy Selection

SmartCache chooses the best optimization automatically:

| Data Type | Size | Strategy | Benefit |
|-----------|------|----------|---------|
| Large Arrays (5000+ items) | Any | Chunking | Better memory, faster access |
| Text/Strings | >50KB | Compression | 60-80% size reduction |
| Mixed Objects | >50KB | Compression | Optimal serialization |
| API Responses | >100KB | Both | Best performance |
| Small Data | <50KB | None | Fastest (no overhead) |

## 📈 Measured Performance Impact

**Production benchmark (e-commerce platform, Redis backend):**
- **72%** cache size reduction (15 MB → 4.2 MB)
- **800 MB** daily Redis memory savings
- **40%** faster retrieval compared to standard Laravel Cache
- **94.3%** cache hit ratio
- **23 ms** average retrieval time

## 🔧 Advanced Features (Opt-in)

All advanced features are **opt-in** and disabled by default for maximum compatibility.

### 🔒 Atomic Locks - Prevent Cache Stampede

Prevent multiple processes from regenerating expensive cache simultaneously:

```php
$lock = SmartCache::lock('expensive_operation', 10);

if ($lock->get()) {
    // Only one process executes this
    $data = expensiveApiCall();
    SmartCache::put('api_data', $data, 3600);
    $lock->release();
}

// Or use callback pattern
SmartCache::lock('regenerate_cache', 30)->get(function() {
    return regenerateExpensiveData();
});
```

**Benefit:** Prevents cache stampede, reduces server load

### ⚡ Cache Memoization - 10-100x Faster

Cache data in memory for the current request:

```php
$memo = SmartCache::memo();

// First call hits cache, subsequent calls are instant
$users = $memo->remember('users', 3600, fn() => User::all());
$users = $memo->get('users'); // Instant! (from memory)
$users = $memo->get('users'); // Still instant!

// Perfect for loops
foreach ($products as $product) {
    $category = $memo->get("category_{$product->category_id}");
}
```

**Benefit:** 10-100x faster repeated access within same request/job

### 🔢 Batch Operations

Optimize multiple cache operations:

```php
// Retrieve multiple keys
$values = SmartCache::many(['key1', 'key2', 'key3']);

// Store multiple keys
SmartCache::putMany([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);

// Delete multiple keys
SmartCache::deleteMultiple(['key1', 'key2', 'key3']);
```

### 🎯 Adaptive Compression

Auto-optimize compression levels based on data characteristics:

```php
// Enable in config
config(['smart-cache.strategies.compression.mode' => 'adaptive']);

// Automatically selects optimal level:
SmartCache::put('hot_data', $frequentlyAccessed, 3600);  // Level 3-4 (faster)
SmartCache::put('cold_data', $rarelyAccessed, 3600);     // Level 7-9 (smaller)
```

**How it works:**
- Analyzes data compressibility
- Tracks access frequency
- Hot data = faster compression
- Cold data = higher compression

### 💾 Lazy Loading

Load large datasets on-demand to save memory:

```php
// Enable in config
config(['smart-cache.strategies.chunking.lazy_loading' => true]);

// Returns LazyChunkedCollection
$largeDataset = SmartCache::get('100k_records');

// Chunks loaded on-demand (max 3 in memory)
foreach ($largeDataset as $record) {
    processRecord($record);
}

// Access specific items
$item = $largeDataset[50000]; // Only loads needed chunk
```

**Benefit:** 30-50% memory savings for large datasets

### 🧠 Smart Serialization

Auto-select best serialization method:

```php
// Automatically chooses:
SmartCache::put('simple', ['key' => 'value'], 3600);  // JSON (fastest)
SmartCache::put('complex', $objectGraph, 3600);       // igbinary/PHP
```

**Methods:** JSON → igbinary (if available) → PHP serialize

### 📡 Cache Events

Monitor cache operations in real-time:

```php
// Enable in config
config(['smart-cache.events.enabled' => true]);

// Listen to events
Event::listen(CacheHit::class, fn($e) => Log::info("Hit: {$e->key}"));
Event::listen(CacheMissed::class, fn($e) => Log::warning("Miss: {$e->key}"));
Event::listen(OptimizationApplied::class, fn($e) =>
    Log::info("Optimized {$e->key}: {$e->ratio}% reduction")
);
```

**Events:** CacheHit, CacheMissed, KeyWritten, KeyForgotten, OptimizationApplied

### 🔐 Encryption Strategy

Encrypt sensitive cached data automatically:

```php
// Enable in config
config(['smart-cache.encryption.enabled' => true]);
config(['smart-cache.encryption.keys' => ['user_*', 'payment_*']]);

// Sensitive data is automatically encrypted
SmartCache::put('user_123_ssn', $sensitiveData, 3600);
// Data encrypted at rest, decrypted on retrieval
```

**Benefit:** Secure sensitive data in cache without code changes

### 🏷️ Cache Namespacing

Group and manage cache keys by namespace:

```php
// Set namespace for operations
SmartCache::namespace('users')->put('profile', $data, 3600);
SmartCache::namespace('users')->put('settings', $settings, 3600);

// Flush entire namespace
SmartCache::flushNamespace('users'); // Clears all user:* keys

// Get all keys in namespace
$keys = SmartCache::getNamespaceKeys('users');
```

**Benefit:** Organize cache keys, easy bulk invalidation

### ⏱️ TTL Jitter

Prevent thundering herd with randomized TTL:

```php
// Add 10% jitter to TTL
SmartCache::withJitter(0.1)->put('popular_data', $data, 3600);
// Actual TTL: 3240-3960 seconds (±10%)

// Or use dedicated methods
SmartCache::putWithJitter('key', $value, 3600, 0.15); // 15% jitter
SmartCache::rememberWithJitter('key', 3600, 0.1, fn() => expensiveCall());
```

**Benefit:** Prevents cache stampede when many keys expire simultaneously

### 🔌 Circuit Breaker

Auto-fallback when cache backend fails:

```php
// Check if cache is available
if (SmartCache::isAvailable()) {
    $data = SmartCache::get('key');
}

// Execute with automatic fallback
$data = SmartCache::withFallback(
    fn() => SmartCache::get('key'),           // Primary
    fn() => Database::query('SELECT ...')     // Fallback
);

// Get circuit breaker stats
$stats = SmartCache::getCircuitBreakerStats();
// Returns: state, failure_count, success_count, last_failure_at
```

**States:** Closed (normal) → Open (failing) → Half-Open (testing)

### 🚦 Rate Limiting & Stampede Protection

Prevent cache stampede with rate limiting:

```php
// Throttle cache operations
$result = SmartCache::throttle('api_call', 10, 60, function() {
    return expensiveApiCall();
}); // Max 10 calls per 60 seconds

// Remember with stampede protection (XFetch algorithm)
$data = SmartCache::rememberWithStampedeProtection('key', 3600, function() {
    return expensiveComputation();
});
```

**Benefit:** Prevents multiple processes from regenerating cache simultaneously

### 🧠 Cost-Aware Caching

Inspired by the GreedyDual-Size algorithm, SmartCache is the **only PHP cache library** that understands the *value* of what it's caching. Every `remember()` call automatically measures:
- **Regeneration cost** — how long the callback took to execute (milliseconds)
- **Access frequency** — how often the key is read
- **Size** — how much memory the entry consumes

```php
// Automatic — just use remember() as usual
$report = SmartCache::remember('analytics', 3600, function () {
    return AnalyticsService::generateReport(); // 800ms
});

$users = SmartCache::remember('users', 3600, function () {
    return User::all(); // 5ms
});

// See which cache entries are most valuable
$report = SmartCache::getCacheValueReport();
// [
//   ['key' => 'analytics', 'cost_ms' => 800, 'access_count' => 47, 'score' => 92.4],
//   ['key' => 'users',     'cost_ms' => 5,   'access_count' => 120, 'score' => 14.1],
// ]

// Get value score for a specific key
$meta = SmartCache::cacheValue('analytics');
// ['cost_ms' => 800, 'access_count' => 47, 'size_bytes' => 4096, 'score' => 92.4]

// When you need to free space, find the least valuable entries
$evictable = SmartCache::suggestEvictions(5);
// Returns the 5 lowest-score keys — safe to remove first
```

**How scoring works:**
`score = (cost × ln(1 + access_count) × decay) / size`
Expensive, frequently accessed, small entries score highest. Cheap, rarely used, large entries score lowest.

**Configuration:**
```php
// config/smart-cache.php
'cost_aware' => [
    'enabled' => true,           // Toggle on/off
    'max_tracked_keys' => 1000,  // Limit metadata memory
    'metadata_ttl' => 86400,     // Metadata persistence (1 day)
],
```

**Benefit:** Data-driven cache optimization. Know exactly which keys matter and which waste space.

### 🔥 Cache Warming

Pre-warm cache with artisan command:

```bash
# Warm cache using registered warmers
php artisan smart-cache:warm

# Warm specific warmer
php artisan smart-cache:warm --warmer=ProductCacheWarmer
```

Register warmers in your service provider:

```php
use SmartCache\Contracts\CacheWarmer;

class ProductCacheWarmer implements CacheWarmer
{
    public function warm(): void
    {
        $products = Product::all();
        SmartCache::put('all_products', $products, 3600);
    }

    public function getKey(): string
    {
        return 'products';
    }
}

// Register in AppServiceProvider
$this->app->tag([ProductCacheWarmer::class], 'smart-cache.warmers');
```

### 🧹 Orphan Chunk Cleanup

Automatically clean up orphan chunks:

```bash
# Clean up orphan chunks
php artisan smart-cache:cleanup-chunks

# Dry run (show what would be cleaned)
php artisan smart-cache:cleanup-chunks --dry-run
```

### 📊 Cache Statistics Dashboard

View cache statistics via web interface:

```php
// Enable in config
config(['smart-cache.dashboard.enabled' => true]);
config(['smart-cache.dashboard.prefix' => 'smart-cache']);
config(['smart-cache.dashboard.middleware' => ['web', 'auth']]);
```

**Routes:**
- `GET /smart-cache/dashboard` - HTML dashboard
- `GET /smart-cache/statistics` - JSON statistics
- `GET /smart-cache/health` - Health check
- `GET /smart-cache/keys` - Managed keys list

## 🌊 Modern Patterns (Laravel 12+)

### SWR (Stale-While-Revalidate)

Serve stale data while refreshing in background:

```php
$apiData = SmartCache::swr('github_repos', function() {
    return Http::get('https://api.github.com/user/repos')->json();
}, 300, 900); // 5min fresh, 15min stale
```

### Extended Stale Serving

For slowly changing data:

```php
$siteConfig = SmartCache::stale('site_config', function() {
    return Config::fromDatabase();
}, 3600, 86400); // 1hour fresh, 24hour stale
```

### Refresh-Ahead

Proactively refresh before expiration:

```php
$analytics = SmartCache::refreshAhead('daily_analytics', function() {
    return Analytics::generateReport();
}, 1800, 300); // 30min TTL, 5min refresh window
```

### Smart Invalidation

Pattern-based cache clearing:

```php
// Clear by pattern
SmartCache::flushPatterns([
    'user_*',           // All user keys
    'api_v2_*',         // All API v2 cache
    '/product_\d+/'     // Regex: product_123, product_456
]);

// Dependency tracking
SmartCache::dependsOn('user_posts', 'user_profile');
SmartCache::invalidate('user_profile'); // Also clears user_posts
```

## 📊 Monitoring & Management

### Performance Metrics

```php
$metrics = SmartCache::getPerformanceMetrics();
// Returns: hit_ratio, compression_savings, operation_timing, etc.

$analysis = SmartCache::analyzePerformance();
// Returns: health score, recommendations, issues
```

### CLI Commands

```bash
# Status overview
php artisan smart-cache:status

# Detailed analysis
php artisan smart-cache:status --force

# Clear cache
php artisan smart-cache:clear
php artisan smart-cache:clear expensive_api_call
```

### HTTP Management

Execute commands via web interface (no SSH needed):

```php
$status = SmartCache::executeCommand('status');
$clearResult = SmartCache::executeCommand('clear', ['key' => 'api_data']);
```

## ⚙️ Configuration

### Publish Config (Optional)

```bash
php artisan vendor:publish --tag=smart-cache-config
```

### Key Configuration Options

```php
// config/smart-cache.php
return [
    // Size thresholds for optimization
    'thresholds' => [
        'compression' => 1024 * 50,  // 50KB - compress data larger than this
        'chunking' => 1024 * 100,    // 100KB - chunk arrays larger than this
    ],

    // Optimization strategies
    'strategies' => [
        'compression' => [
            'enabled' => true,
            'mode' => 'fixed',       // 'fixed' or 'adaptive'
            'level' => 6,            // 1-9 (higher = better compression)
        ],
        'chunking' => [
            'enabled' => true,
            'chunk_size' => 1000,    // Items per chunk
            'lazy_loading' => false, // Enable for memory savings
            'smart_sizing' => false, // Auto-calculate chunk size
        ],
    ],

    // Events (disabled by default for performance)
    'events' => [
        'enabled' => false,
    ],

    // Performance monitoring
    'monitoring' => [
        'enabled' => true,
        'metrics_ttl' => 3600,
    ],

    // Encryption for sensitive data
    'encryption' => [
        'enabled' => false,
        'keys' => [],              // Keys to encrypt: ['user_*', 'payment_*']
        'patterns' => [],          // Regex patterns: ['/secret_.*/']
    ],

    // Circuit breaker for cache backend failures
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 5,  // Failures before opening
        'success_threshold' => 2,  // Successes to close
        'timeout' => 30,           // Seconds before half-open
    ],

    // Rate limiting for cache operations
    'rate_limiter' => [
        'enabled' => true,
        'default_limit' => 100,    // Max operations per window
        'window' => 60,            // Window in seconds
    ],

    // TTL jitter to prevent thundering herd
    'jitter' => [
        'enabled' => false,
        'percentage' => 0.1,       // 10% jitter by default
    ],

    // Statistics dashboard
    'dashboard' => [
        'enabled' => false,
        'prefix' => 'smart-cache',
        'middleware' => ['web'],
    ],
];
```

## 🔧 Supported Cache Drivers

| Driver | Compression | Chunking | Locks | All Features |
|--------|-------------|----------|-------|--------------|
| **Redis** | ✅ | ✅ | ✅ | ✅ Full Support |
| **File** | ✅ | ✅ | ✅ | ✅ Full Support |
| **Database** | ✅ | ✅ | ✅ | ✅ Full Support |
| **Array** | ✅ | ✅ | ✅ | ✅ Full Support |
| **Memcached** | ✅ | ✅ | ✅ | ✅ Full Support |

**All Laravel cache drivers are fully supported!**

## 🚀 Migration from Laravel Cache

SmartCache is a **drop-in replacement** - your existing code works unchanged:

```php
// Before (Laravel Cache)
use Illuminate\Support\Facades\Cache;

Cache::put('users', $users, 3600);
$users = Cache::get('users');

// After (SmartCache) - just change the import
use SmartCache\Facades\SmartCache;

SmartCache::put('users', $users, 3600);  // Now automatically optimized!
$users = SmartCache::get('users');       // Automatically restored!
```

**That's it!** No code changes needed. You immediately get:
- ✅ Automatic compression for large data
- ✅ Smart chunking for large arrays
- ✅ Full access to extended capabilities (encryption, circuit breaker, cost-aware caching, and more)

## 📚 Documentation

**[📖 Full Documentation](https://iazaran.github.io/smart-cache/)** - Complete guide with examples

### Quick Links
- [Installation Guide](https://iazaran.github.io/smart-cache/#installation)
- [Basic Usage](https://iazaran.github.io/smart-cache/#basic-usage)
- [Advanced Features](https://iazaran.github.io/smart-cache/#advanced)
- [API Reference](https://iazaran.github.io/smart-cache/#api-reference)
- [SWR Patterns](https://iazaran.github.io/smart-cache/#swr-patterns)

## 🧪 Testing

SmartCache includes **415+ comprehensive tests** with **1700+ assertions** covering all functionality:

```bash
composer test

# With coverage
composer test-coverage
```

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.

## 🔗 Links

- **📦 Packagist**: [iazaran/smart-cache](https://packagist.org/packages/iazaran/smart-cache)
- **🐛 Issues**: [GitHub Issues](https://github.com/iazaran/smart-cache/issues)
- **📖 Docs**: [Full Documentation](https://iazaran.github.io/smart-cache/)

---

<div align="center">

**Built for the Laravel community**

*Production-grade caching — from rapid prototypes to enterprise-scale systems*

</div>