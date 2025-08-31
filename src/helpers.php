<?php

use SmartCache\Facades\SmartCache;

if (!function_exists('smart_cache')) {
    /**
     * Get / set the specified cache value using SmartCache.
     *
     * If an array is passed, we'll assume you want to put to the cache.
     *
     * @param  dynamic  key|key,default|data,ttl|null
     * @return mixed|\SmartCache\Contracts\SmartCache
     *
     * @throws \InvalidArgumentException
     */
    function smart_cache()
    {
        $arguments = func_get_args();

        if (empty($arguments)) {
            return function_exists('app') ? app('smart-cache') : \Illuminate\Container\Container::getInstance()->make('smart-cache');
        }

        if (is_string($arguments[0])) {
            return SmartCache::get(...$arguments);
        }

        if (!is_array($arguments[0])) {
            throw new InvalidArgumentException(
                'When using smart_cache(), the first argument must be an array of key / value pairs or a string.'
            );
        }

        return SmartCache::put(key($arguments[0]), reset($arguments[0]), $arguments[1] ?? null);
    }
} 