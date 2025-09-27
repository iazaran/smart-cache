<?php

namespace SmartCache\Contracts;

interface SmartCache
{
    /**
     * Get an item from the cache.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function put(string $key, mixed $value, $ttl = null): bool;

    /**
     * Determine if an item exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool;

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, $ttl, \Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, \Closure $callback): mixed;

    /**
     * Get the underlying cache store.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function store(string|null $name = null): \Illuminate\Contracts\Cache\Repository;

    /**
     * Clear all cache keys managed by SmartCache.
     *
     * @return bool
     */
    public function clear(): bool;

    /**
     * Get all keys managed by SmartCache.
     *
     * @return array
     */
    public function getManagedKeys(): array;

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
} 