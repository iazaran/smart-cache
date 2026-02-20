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
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Configure the circuit breaker for cache backend failures.
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => 5, // Number of failures before opening circuit
        'recovery_timeout' => 30, // Seconds to wait before trying again
        'success_threshold' => 3, // Successful calls needed to close circuit
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
        'window' => 60, // Window in seconds
        'max_attempts' => 10, // Maximum attempts per window
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
    | Set to null to use the global strategies configuration.
    |
    */
    'drivers' => [
        'redis' => null, // Use global settings
        'file' => [
            'compression' => true,
            'chunking' => true,
        ],
        'memcached' => [
            'compression' => false, // Memcached has its own compression
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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Configure the web dashboard for viewing cache statistics.
    |
    */
    'dashboard' => [
        'enabled' => false, // Disabled by default for security
        'prefix' => 'smart-cache', // URL prefix for dashboard routes
        'middleware' => ['web'], // Middleware to apply to dashboard routes
    ],
];