<?php

namespace SmartCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool has(string $key)
 * @method static bool forget(string $key)
 * @method static bool forever(string $key, mixed $value)
 * @method static mixed remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback)
 * @method static mixed rememberForever(string $key, \Closure $callback)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static bool add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static int|bool increment(string $key, int $value = 1)
 * @method static int|bool decrement(string $key, int $value = 1)
 * @method static bool clear()
 * @method static array many(array $keys)
 * @method static bool putMany(array $values, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static \SmartCache\SmartCache store(string|null $name = null)
 * @method static \Illuminate\Contracts\Cache\Repository repository(string|null $name = null)
 * @method static mixed getRaw(string $key)
 * @method static \Illuminate\Contracts\Cache\Store getStore()
 * @method static mixed flexible(string $key, array $durations, \Closure $callback)
 * @method static mixed swr(string $key, \Closure $callback, int $ttl = 3600, int $staleTtl = 7200)
 * @method static mixed stale(string $key, \Closure $callback, int $ttl = 1800, int $staleTtl = 86400)
 * @method static mixed refreshAhead(string $key, \Closure $callback, int $ttl = 3600, int $refreshWindow = 600)
 * @method static mixed asyncSwr(string $key, callable|string $callback, int $ttl = 3600, int $staleTtl = 7200, ?string $queue = null)
 * @method static mixed rememberIf(string $key, mixed $ttl, \Closure $callback, callable $condition)
 * @method static mixed rememberWithStampedeProtection(string $key, int $ttl, \Closure $callback, float $beta = 1.0)
 * @method static \SmartCache\SmartCache memo(?string $store = null)
 * @method static \SmartCache\SmartCache namespace(string $namespace)
 * @method static \SmartCache\SmartCache withoutNamespace()
 * @method static int flushNamespace(string $namespace)
 * @method static array getNamespaceKeys(string $namespace)
 * @method static \SmartCache\SmartCache withJitter(float $percentage = 0.1)
 * @method static \SmartCache\SmartCache withoutJitter()
 * @method static int|null applyJitter(?int $ttl, ?float $jitterPercentage = null)
 * @method static bool putWithJitter(string $key, mixed $value, int $ttl, float $jitterPercentage = 0.1)
 * @method static mixed rememberWithJitter(string $key, int $ttl, float $jitterPercentage, \Closure $callback)
 * @method static \SmartCache\SmartCache withCircuitBreaker()
 * @method static \SmartCache\SmartCache withoutCircuitBreaker()
 * @method static bool isAvailable()
 * @method static array getCircuitBreakerStats()
 * @method static mixed withFallback(callable $callback, mixed $fallback = null)
 * @method static mixed throttle(string $key, int $maxAttempts, int $decaySeconds, callable $callback)
 * @method static static tags(string|array $tags)
 * @method static bool flushTags(string|array $tags)
 * @method static static dependsOn(string $key, string|array $dependencies)
 * @method static bool invalidate(string $key)
 * @method static int flushPatterns(array $patterns)
 * @method static int invalidateModel(string $modelClass, mixed $modelId, array $relationships = [])
 * @method static array|null cacheValue(string $key)
 * @method static array getCacheValueReport()
 * @method static array suggestEvictions(int $count = 10)
 * @method static void persistCostMetadata()
 * @method static array getManagedKeys()
 * @method static int cleanupExpiredManagedKeys()
 * @method static void persistManagedKeys()
 * @method static array getStatistics()
 * @method static array healthCheck()
 * @method static \SmartCache\Services\CacheInvalidationService invalidationService()
 * @method static array getAvailableCommands()
 * @method static array executeCommand(string $command, array $parameters = [])
 * @method static array getPerformanceMetrics()
 * @method static void resetPerformanceMetrics()
 * @method static array analyzePerformance()
 * @method static bool hasFeature(string $feature)
 * @method static \Illuminate\Contracts\Cache\Lock lock(string $name, int $seconds = 0, ?string $owner = null)
 * @method static \Illuminate\Contracts\Cache\Lock restoreLock(string $name, string $owner)
 * @method static bool flush()
 * @method static bool set(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool delete(string $key)
 * @method static mixed sear(string $key, \Closure $callback)
 * @method static iterable getMultiple(iterable $keys, mixed $default = null)
 * @method static bool setMultiple(iterable $values, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool deleteMultiple(iterable $keys)
 * @method static void refreshAsync(string $key, callable|string $callback, ?int $ttl = null, ?string $queue = null)
 * @method static string|null getNamespace()
 * @method static array cleanupOrphanChunks()
 * @method static \SmartCache\Services\OrphanChunkCleanupService chunkCleanupService()
 * @method static \SmartCache\Services\CircuitBreaker circuitBreaker()
 * @method static \SmartCache\Services\RateLimiter rateLimiter()
 * @method static self addStrategy(\SmartCache\Contracts\OptimizationStrategy $strategy)
 * @method static array getStrategies()
 * @method static \SmartCache\Services\CostAwareCacheManager|null getCostAwareManager()
 *
 * @see \SmartCache\SmartCache
 */
class SmartCache extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'smart-cache';
    }
} 