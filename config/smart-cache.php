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
            'level' => 6, // 0-9 (higher = better compression but slower)
        ],
        'chunking' => [
            'enabled' => true,
            'chunk_size' => 1000, // Items per chunk for arrays/collections
        ],
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
]; 