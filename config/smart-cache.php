<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Smart Cache Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration settings for the SmartCache package.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure size-based thresholds that trigger optimization strategies.
    |
    */
    'thresholds' => [
        'compression' => 1024 * 50, // 50KB
        'chunking' => 1024 * 100,   // 100KB
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategies
    |--------------------------------------------------------------------------
    |
    | Configure which optimization strategies are enabled and their options.
    |
    | Note on Adaptive Compression: Adaptive mode will sample chunks of
    | your data to determine if they are compressible. It uses more CPU
    | but saves time if data is incompressible (like pre-compressed images).
    |
    */
    'strategies' => [
        'compression' => [
            'enabled' => true,
            'mode' => 'fixed', // 'fixed' or 'adaptive'
            'level' => 6, // 0-9 (higher = better compression but slower) - used in fixed mode
            'adaptive' => [
                'sample_size' => 1024, // Bytes to sample for compressibility test
                'high_compression_threshold' => 0.5, // Ratio below which to use level 9
                'low_compression_threshold' => 0.7,  // Ratio above which to use level 3
                'frequency_threshold' => 100, // Access count for speed priority
            ],
        ],
        'chunking' => [
            'enabled' => true,
            'chunk_size' => 1000, // Items per chunk for arrays/collections
            'lazy_loading' => false, // Enable lazy loading for chunks
            'smart_sizing' => false, // Enable smart chunk size calculation
        ],
        'encryption' => [
            'enabled' => false, // Disabled by default
            'keys' => [], // Specific keys to encrypt
            'patterns' => [], // Regex patterns for keys to encrypt (e.g., '/^user_token_/')
            'encrypt_all' => false, // Encrypt all cached values
        ],
        'serialization' => [
            'enabled' => false, // Disabled by default
            'preferred_method' => 'auto', // auto, json, igbinary, php
            'auto_detect' => true, // Auto-detect best method per value
            'size_threshold' => 1024, // Minimum size in bytes to apply optimization
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Configure the circuit breaker for cache backend failures.
    | This is especially useful for distributed caches (like Redis) that
    | may go offline temporarily. If the threshold is reached, SmartCache
    | will immediately run closures without hitting the broken cache
    | and gracefully recover later.
    |
    */
    'circuit_breaker' => [
        'enabled' => false, // Disabled by default — enable with withCircuitBreaker()
        'failure_threshold' => 5, // Number of failures before opening circuit
        'recovery_timeout' => 30, // Seconds to wait before trying again
        'success_threshold' => 3, // Successful calls needed to close circuit
        'shared' => false, // When true, breaker state is persisted to the cache so it is
                           // shared across workers/processes. Default false preserves
                           // the historical per-instance behaviour.
        'shared_ttl' => 300, // Seconds to keep the shared state in cache (best-effort).
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiter
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for cache operations.
    |
    */
    'rate_limiter' => [
        'enabled' => true, // Informational: throttle() and rememberWithStampedeProtection()
                           // always rate-limit; this flag is not read.
        'window' => 60, // Default window in seconds
        'max_attempts' => 10, // Default maximum attempts per window
    ],

    /*
    |--------------------------------------------------------------------------
    | TTL Jitter
    |--------------------------------------------------------------------------
    |
    | Configure TTL jitter to prevent thundering herd problem.
    |
    */
    'jitter' => [
        'enabled' => false, // Disabled by default
        'percentage' => 0.1, // 10% jitter by default
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    |
    | Configure fallback behavior if optimizations fail or are incompatible.
    |
    */
    'fallback' => [
        'enabled' => true,
        'log_errors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost-Aware Caching
    |--------------------------------------------------------------------------
    |
    | Enable cost-aware caching to track the "value" of each cached key based on
    | regeneration cost, access frequency, and size. This allows SmartCache to
    | provide intelligent eviction suggestions and value-based scoring.
    |
    */
    'cost_aware' => [
        'enabled' => true,
        'max_tracked_keys' => 1000, // Maximum number of keys to track metadata for
        'metadata_ttl' => 86400, // How long to keep cost metadata (seconds)
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Deduplication (Cache DNA)
    |--------------------------------------------------------------------------
    |
    | When enabled, SmartCache hashes every value before writing and skips
    | the write when the stored content is identical.  This eliminates
    | redundant I/O for frequently refreshed but rarely changing data
    | (e.g., configuration, feature flags, rate-limit counters).
    |
    */
    'deduplication' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Healing Cache
    |--------------------------------------------------------------------------
    |
    | When enabled, corrupted or unrestorable cache entries are automatically
    | evicted instead of propagating an exception.  Combined with a
    | `remember()` or `rememberIf()` callback, the entry is transparently
    | regenerated on the next read.
    |
    */
    'self_healing' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-While-Revalidate (SWR)
    |--------------------------------------------------------------------------
    |
    | Configure SWR behaviour shared by `flexible()`, `swr()`, `stale()`, and
    | `refreshAhead()`.  When `single_flight` is enabled and the underlying
    | cache store implements `LockProvider`, only one process will execute
    | the in-process background refresh for a given key at a time.  Other
    | processes continue to serve the stale value without piling on the
    | upstream source.  Falls back to the historical behaviour when the
    | store does not support locks.
    |
    */
    'swr' => [
        'single_flight' => false, // Opportunistic non-blocking lock around refreshInBackground().
                                  // Default false preserves the historical behaviour where every
                                  // worker performs its own synchronous refresh.
        'lock_ttl' => 30,         // Seconds the refresh lock is held when single_flight is enabled.
    ],

    /*
    |--------------------------------------------------------------------------
    | Managed Keys Tracking
    |--------------------------------------------------------------------------
    |
    | SmartCache maintains an index of optimized keys (`_sc_managed_keys`)
    | so that pattern-based invalidation, audits, and the dashboard can work
    | without scanning the underlying store.  On very large key spaces the
    | index can grow unbounded.  Set `max_tracked` to a positive integer to
    | cap the index size; the oldest entries are dropped first.  `null` or
    | `0` keep the historical unlimited behaviour.
    |
    */
    'managed_keys' => [
        'max_tracked' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Locks
    |--------------------------------------------------------------------------
    |
    | Tag indexes and dependency metadata are small shared cache entries. When
    | the selected cache store supports Laravel atomic locks, SmartCache wraps
    | metadata mutations in a short lock to reduce lost updates during concurrent
    | writes. Stores without lock support keep the historical best-effort
    | behavior.
    |
    */
    'metadata_lock' => [
        'enabled' => true,
        'ttl' => 5,
        'wait' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Invalidation
    |--------------------------------------------------------------------------
    |
    | Eloquent model events fire while a database transaction is still open.
    | Deferring invalidation until commit prevents another request from
    | re-caching pre-commit data between the flush and the commit. Set
    | `after_commit` to false to restore immediate invalidation.
    |
    */
    'model_invalidation' => [
        'after_commit' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | By default, tags set with tags() stay active until a write uses them, as
    | in 1.14, so a tagged get() that misses also tags the next write, whatever
    | its key. That only causes extra invalidation, never stale data.
    |
    | 'scoped' => true (since 1.15.0, recommended for new applications) limits
    | them: after a tagged lookup, the tags only apply to a later write of a key
    | looked up while they were pending. In that mode, compute a value before
    | calling tags(): a cache lookup inside tags('t')->put('k', $service->build())
    | runs after tags(). Or use tags('t')->remember().
    |
    | Since 1.16.0, tags('t')->flush() removes only the entries tagged 't' (like
    | flushTags('t') and Laravel's Cache::tags('t')->flush()); a plain flush()
    | still clears the whole store.
    |
    */
    'tags' => [
        'scoped' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Memoization
    |--------------------------------------------------------------------------
    |
    | SmartCache::memo() keeps values read during a request or job in memory.
    | By default every memo() call creates its own memo layer.
    |
    | 'native' => true (Laravel 12.9+, since 1.16.0) builds memo() on Laravel's
    | Cache::memo() instead: one memo layer per store, shared with Cache::memo()
    | and with every other memo() call, which Laravel clears between requests
    | and queued jobs. On older Laravel versions memo() keeps its own layer.
    | In that mode add() writes to the store directly (it stays atomic), and
    | SmartCache then reads that key from the store for as long as the memo
    | layer lives.
    |
    */
    'memo' => [
        'native' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | Laravel's cache.serializable_classes (the Laravel 13 application skeleton
    | sets it to false) is applied by the store, but SmartCache unserializes the
    | values it compresses, encrypts, or serializes itself, so the setting does
    | not reach them: a large object comes back intact where a small one would
    | not. Since 1.17.0 you can apply a restriction to them too (opt-in):
    |
    |   null     no restriction (default, the behavior before 1.17.0)
    |   'cache'  use the value of cache.serializable_classes
    |   false    allow no classes (objects come back as __PHP_Incomplete_Class)
    |   [...]    allow only these classes
    |
    | Chunked values are read by the store itself and already follow Laravel's
    | setting. igbinary payloads of the opt-in serialization strategy cannot
    | be restricted.
    |
    */
    'serializable_classes' => null,

    /*
    |--------------------------------------------------------------------------
    | Chunk Registry
    |--------------------------------------------------------------------------
    |
    | Since 1.16.0 SmartCache registers the chunks of every chunked value stored
    | without expiry, and `smart-cache:cleanup-chunks` removes the ones whose
    | entry is gone or was replaced. Chunks of values with a TTL expire with
    | them. SmartCache updates the registry right away, under a metadata lock;
    | `persist_every` only batches OrphanChunkCleanupService::registerChunks()
    | calls made by your own code. Default `1` persists every change.
    |
    */
    'chunk_registry' => [
        'persist_every' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable performance monitoring to track cache hit/miss ratios,
    | optimization impact, and operation durations.
    |
    */
    'monitoring' => [
        'enabled' => true,
        'metrics_ttl' => 3600, // How long to keep metrics in cache (seconds)
        'recent_entries_limit' => 100, // Number of recent operations to track per type
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Warnings
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for performance warnings and recommendations.
    |
    */
    'warnings' => [
        'hit_ratio_threshold' => 70, // Percentage below which to warn about low hit ratio
        'optimization_ratio_threshold' => 20, // Percentage below which to warn about low optimization usage
        'slow_write_threshold' => 0.1, // Seconds above which to warn about slow writes
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Drivers
    |--------------------------------------------------------------------------
    |
    | Configure which cache drivers should use which optimization strategies.
    | Set to null to use the global strategies configuration. Keys are Laravel
    | driver names (redis, memcached, file, database, dynamodb, array); these
    | settings apply since 1.15.0.
    |
    */
    'drivers' => [
        'redis' => null, // Use global settings
        'file' => [
            'compression' => true,
            'chunking' => true,
        ],
        'memcached' => [
            'compression' => true, // false = rely on php-memcached's own (weaker) compression
            'chunking' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Events
    |--------------------------------------------------------------------------
    |
    | Enable cache events to track cache operations and optimization effectiveness.
    | Events can be used for monitoring, logging, and debugging.
    |
    */
    'events' => [
        'enabled' => false, // Disabled by default for backward compatibility
        'dispatch' => [
            'cache_hit' => true,
            'cache_missed' => true,
            'key_written' => true,
            'key_forgotten' => true,
            'optimization_applied' => true,
            'tag_flushed' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Configure the web dashboard for viewing cache statistics.
    |
    | SECURITY: the default middleware is ['web'], which gives you session and
    | CSRF handling but NO authentication. These routes expose the managed cache
    | key list, optimization statistics, and internal metrics, and cache keys
    | commonly embed user, tenant, or record identifiers. Before enabling the
    | dashboard outside local development, add authentication and authorization
    | middleware, e.g. ['web', 'auth'] or ['web', 'auth', 'can:viewSmartCache'].
    |
    */
    'dashboard' => [
        'enabled' => false, // Disabled by default for security
        'prefix' => 'smart-cache', // URL prefix for dashboard routes
        'middleware' => ['web'], // Add 'auth' (and a gate) before exposing this
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warmers
    |--------------------------------------------------------------------------
    |
    | Register cache warmer classes to pre-warm the cache on demand via
    | the `smart-cache:warm` Artisan command. Each warmer must implement
    | a `warm(): array` method returning ['keys' => int].
    |
    | Example:
    |   'users' => App\CacheWarmers\UserCacheWarmer::class,
    |
    */
    'warmers' => [
        // 'users'    => App\CacheWarmers\UserCacheWarmer::class,
        // 'products' => App\CacheWarmers\ProductCacheWarmer::class,
    ],
];
