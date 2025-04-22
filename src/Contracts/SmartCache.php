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
    public function store(string $name = null): \Illuminate\Contracts\Cache\Repository;

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
} 