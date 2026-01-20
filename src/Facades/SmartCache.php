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
 * @method static mixed flexible(string $key, array $durations, \Closure $callback)
 * @method static mixed swr(string $key, \Closure $callback, int $ttl = 3600, int $staleTtl = 7200)
 * @method static mixed stale(string $key, \Closure $callback, int $ttl = 1800, int $staleTtl = 86400)
 * @method static mixed refreshAhead(string $key, \Closure $callback, int $ttl = 3600, int $refreshWindow = 600)
 * @method static \SmartCache\SmartCache store(string|null $name = null)
 * @method static \Illuminate\Contracts\Cache\Repository repository(string|null $name = null)
 * @method static bool clear()
 * @method static array getManagedKeys()
 * @method static static tags(string|array $tags)
 * @method static bool flushTags(string|array $tags)
 * @method static static dependsOn(string $key, string|array $dependencies)
 * @method static bool invalidate(string $key)
 * @method static int flushPatterns(array $patterns)
 * @method static int invalidateModel(string $modelClass, mixed $modelId, array $relationships = [])
 * @method static array getStatistics()
 * @method static array healthCheck()
 * @method static \SmartCache\Services\CacheInvalidationService invalidationService()
 * @method static array getAvailableCommands()
 * @method static array executeCommand(string $command, array $parameters = [])
 * @method static array getPerformanceMetrics()
 * @method static void resetPerformanceMetrics()
 * @method static array analyzePerformance()
 * @method static int cleanupExpiredManagedKeys()
 * @method static bool hasFeature(string $feature)
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