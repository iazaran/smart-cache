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
 * @method static \Illuminate\Contracts\Cache\Repository store(string $name = null)
 * @method static bool clear()
 * @method static array getManagedKeys()
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