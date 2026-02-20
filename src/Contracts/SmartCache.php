<?php

namespace SmartCache\Contracts;

use Illuminate\Contracts\Cache\Repository;

/**
 * SmartCache Contract
 *
 * This interface extends Laravel's Repository interface to ensure full compatibility
 * with Laravel's cache system while adding SmartCache-specific optimization features.
 */
interface SmartCache extends Repository
{
    /**
     * Get a SmartCache instance using a specific cache store.
     *
     * When called without arguments, returns the current instance.
     * When called with a store name, returns a new SmartCache instance
     * configured to use that store while maintaining all optimization strategies.
     *
     * @param string|null $name The cache store name (e.g., 'redis', 'file', 'memcached')
     * @return static
     */
    public function store(string|null $name = null): static;

    /**
     * Get the underlying cache repository directly.
     *
     * This provides raw access to Laravel's cache repository without SmartCache optimizations.
     * Use this when you need direct access to the cache driver.
     *
     * @param string|null $name The store name (null for current store)
     * @return Repository
     */
    public function repository(string|null $name = null): Repository;

    /**
     * Get all keys managed by SmartCache.
     *
     * @return array
     */
    public function getManagedKeys(): array;

    /**
     * Clean up expired keys from managed keys tracking.
     *
     * @return int Number of expired keys removed
     */
    public function cleanupExpiredManagedKeys(): int;

    /**
     * Check if a specific feature is available.
     *
     * @param string $feature The feature name to check
     * @return bool
     */
    public function hasFeature(string $feature): bool;

    /**
     * Get raw (unrestored) value from cache.
     *
     * Returns the value as stored in cache without applying optimization restoration
     * (e.g., decompression, dechunking). Useful for statistics and diagnostics.
     *
     * @param string $key
     * @return mixed
     */
    public function getRaw(string $key): mixed;

    /**
     * Flush the entire cache store (clears ALL keys, not just managed ones).
     *
     * @return bool
     */
    public function flush(): bool;

    /**
     * Get the value score for a cache key (cost-aware caching).
     *
     * Returns metadata about how valuable a cache key is based on regeneration cost,
     * access frequency, size, and time decay. Returns null if cost-aware caching is
     * disabled or if no metadata exists for the key.
     *
     * @param string $key
     * @return array|null
     */
    public function cacheValue(string $key): ?array;

    /**
     * Get a report of all tracked cache keys sorted by value score (highest first).
     *
     * @return array
     */
    public function getCacheValueReport(): array;

    /**
     * Get suggestions for which cache keys to evict based on lowest value.
     *
     * @param int $count Number of eviction suggestions
     * @return array
     */
    public function suggestEvictions(int $count = 10): array;

    /**
     * Tag cache entries for organized invalidation.
     *
     * @param string|array $tags
     * @return static
     */
    public function tags(string|array $tags): static;

    /**
     * Flush all cache entries associated with given tags.
     *
     * @param string|array $tags
     * @return bool
     */
    public function flushTags(string|array $tags): bool;

    /**
     * Add cache key dependency relationships.
     *
     * @param string $key
     * @param string|array $dependencies
     * @return static
     */
    public function dependsOn(string $key, string|array $dependencies): static;

    /**
     * Invalidate cache key and all dependent keys.
     *
     * @param string $key
     * @return bool
     */
    public function invalidate(string $key): bool;

    /**
     * Flush cache by patterns.
     *
     * @param array $patterns
     * @return int Number of keys invalidated
     */
    public function flushPatterns(array $patterns): int;

    /**
     * Invalidate model-related cache.
     *
     * @param string $modelClass
     * @param mixed $modelId
     * @param array $relationships
     * @return int Number of keys invalidated
     */
    public function invalidateModel(string $modelClass, mixed $modelId, array $relationships = []): int;

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStatistics(): array;

    /**
     * Perform health check and cleanup.
     *
     * @return array
     */
    public function healthCheck(): array;

    /**
     * Get the cache invalidation service.
     *
     * @return \SmartCache\Services\CacheInvalidationService
     */
    public function invalidationService(): \SmartCache\Services\CacheInvalidationService;

    /**
     * Flexible caching with stale-while-revalidate support.
     *
     * @param string $key
     * @param array $durations [freshTtl, staleTtl]
     * @param \Closure $callback
     * @return mixed
     */
    public function flexible(string $key, array $durations, \Closure $callback): mixed;

    /**
     * Stale-While-Revalidate (SWR) caching pattern.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl
     * @param int $staleTtl
     * @return mixed
     */
    public function swr(string $key, \Closure $callback, int $ttl = 3600, int $staleTtl = 7200): mixed;

    /**
     * Stale cache pattern - allows serving stale data beyond TTL.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl
     * @param int $staleTtl
     * @return mixed
     */
    public function stale(string $key, \Closure $callback, int $ttl = 1800, int $staleTtl = 86400): mixed;

    /**
     * Refresh-Ahead caching pattern.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl
     * @param int $refreshWindow
     * @return mixed
     */
    public function refreshAhead(string $key, \Closure $callback, int $ttl = 3600, int $refreshWindow = 600): mixed;

    /**
     * Get available Artisan commands information.
     *
     * @return array
     */
    public function getAvailableCommands(): array;

    /**
     * Execute a command programmatically.
     *
     * @param string $command
     * @param array $parameters
     * @return array
     */
    public function executeCommand(string $command, array $parameters = []): array;

    /**
     * Get performance metrics.
     *
     * @return array
     */
    public function getPerformanceMetrics(): array;

    /**
     * Reset performance metrics.
     *
     * @return void
     */
    public function resetPerformanceMetrics(): void;

    /**
     * Analyze cache performance and provide recommendations.
     *
     * @return array
     */
    public function analyzePerformance(): array;

    /**
     * Get a lock instance.
     *
     * @param string $name
     * @param int $seconds
     * @param string|null $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): \Illuminate\Contracts\Cache\Lock;

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param string $name
     * @param string $owner
     * @return \Illuminate\Contracts\Cache\Lock
     */
    public function restoreLock(string $name, string $owner): \Illuminate\Contracts\Cache\Lock;

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @param array $keys
     * @return array
     */
    public function many(array $keys): array;

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param array $values
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, $ttl = null): bool;

    /**
     * Get a memoized cache instance.
     *
     * @param string|null $store
     * @return static
     */
    public function memo(?string $store = null): static;

    /**
     * Set the active namespace for cache keys.
     *
     * @param string $namespace
     * @return static
     */
    public function namespace(string $namespace): static;

    /**
     * Clear the active namespace.
     *
     * @return static
     */
    public function withoutNamespace(): static;

    /**
     * Flush all keys in a namespace.
     *
     * @param string $namespace
     * @return int Number of keys flushed
     */
    public function flushNamespace(string $namespace): int;

    /**
     * Get all keys in a namespace.
     *
     * @param string $namespace
     * @return array
     */
    public function getNamespaceKeys(string $namespace): array;

    /**
     * Enable TTL jitter with specified percentage.
     *
     * @param float $percentage Jitter percentage (0.0 to 1.0)
     * @return static
     */
    public function withJitter(float $percentage = 0.1): static;

    /**
     * Disable TTL jitter.
     *
     * @return static
     */
    public function withoutJitter(): static;

    /**
     * Apply jitter to a TTL value.
     *
     * @param int|null $ttl
     * @param float|null $jitterPercentage Optional override for jitter percentage
     * @return int|null
     */
    public function applyJitter(?int $ttl, ?float $jitterPercentage = null): ?int;

    /**
     * Store an item with TTL jitter applied.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param float $jitterPercentage
     * @return bool
     */
    public function putWithJitter(string $key, mixed $value, int $ttl, float $jitterPercentage = 0.1): bool;

    /**
     * Remember with TTL jitter applied.
     *
     * @param string $key
     * @param int $ttl
     * @param float $jitterPercentage
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberWithJitter(string $key, int $ttl, float $jitterPercentage, \Closure $callback): mixed;

    /**
     * Check if the cache is available (circuit breaker).
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get circuit breaker statistics.
     *
     * @return array
     */
    public function getCircuitBreakerStats(): array;

    /**
     * Execute with fallback on circuit breaker open.
     *
     * @param callable $primary
     * @param callable $fallback
     * @return mixed
     */
    public function withFallback(callable $primary, callable $fallback): mixed;

    /**
     * Throttle cache operations.
     *
     * @param string $key
     * @param int $maxAttempts
     * @param int $decaySeconds
     * @param callable $callback
     * @return mixed
     */
    public function throttle(string $key, int $maxAttempts, int $decaySeconds, callable $callback): mixed;

    /**
     * Remember with stampede protection using probabilistic early expiration.
     *
     * @param string $key
     * @param int $ttl
     * @param \Closure $callback
     * @param float $beta XFetch beta parameter
     * @return mixed
     */
    public function rememberWithStampedeProtection(string $key, int $ttl, \Closure $callback, float $beta = 1.0): mixed;

    /**
     * Cache a value only when a condition is satisfied.
     *
     * The callback is always executed, but the result is stored in cache
     * only if $condition($value) returns true.  This keeps invalid or
     * empty data out of cache while still returning the value to the caller.
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback   Value generator
     * @param callable $condition  Receives generated value; return true to cache
     * @return mixed
     */
    public function rememberIf(string $key, mixed $ttl, \Closure $callback, callable $condition): mixed;
}