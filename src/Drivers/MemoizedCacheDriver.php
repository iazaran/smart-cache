<?php

namespace SmartCache\Drivers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

/**
 * Memoized Cache Driver
 *
 * Wraps a cache repository and adds in-memory memoization for the current request.
 * This prevents repeated cache hits for the same key within a single request/job execution.
 * Includes LRU eviction to prevent memory leaks in long-running processes.
 */
class MemoizedCacheDriver implements Repository
{
    /**
     * @var Repository
     */
    protected Repository $repository;

    /**
     * @var array
     */
    protected array $memoized = [];

    /**
     * @var array
     */
    protected array $memoizedMissing = [];

    /**
     * @var array LRU tracking - keys in order of last access
     */
    protected array $accessOrder = [];

    /**
     * @var int Maximum number of items to keep in memory
     */
    protected int $maxSize;

    /**
     * Create a new memoized cache driver.
     *
     * @param Repository $repository
     * @param int $maxSize Maximum items to memoize (default: 1000)
     */
    public function __construct(Repository $repository, int $maxSize = 1000)
    {
        $this->repository = $repository;
        $this->maxSize = $maxSize;
    }

    /**
     * Set the maximum size of the memoization cache.
     *
     * @param int $maxSize
     * @return void
     */
    public function setMaxSize(int $maxSize): void
    {
        $this->maxSize = $maxSize;
        $this->evictIfNeeded();
    }

    /**
     * Touch a key to mark it as recently used.
     *
     * @param string $key
     * @return void
     */
    protected function touchKey(string $key): void
    {
        // Remove from current position
        $index = array_search($key, $this->accessOrder, true);
        if ($index !== false) {
            unset($this->accessOrder[$index]);
        }
        // Add to end (most recently used)
        $this->accessOrder[] = $key;
    }

    /**
     * Evict least recently used items if over capacity.
     *
     * @return void
     */
    protected function evictIfNeeded(): void
    {
        while (count($this->memoized) > $this->maxSize) {
            // Re-index array to ensure we get the first element
            $this->accessOrder = array_values($this->accessOrder);

            if (empty($this->accessOrder)) {
                break;
            }

            // Remove the least recently used (first in array)
            $lruKey = array_shift($this->accessOrder);
            unset($this->memoized[$lruKey]);
        }
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        if (array_key_exists($key, $this->memoized)) {
            return true;
        }

        if (isset($this->memoizedMissing[$key])) {
            return false;
        }

        return $this->repository->has($key);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null): mixed
    {
        // Check if already memoized
        if (array_key_exists($key, $this->memoized)) {
            $this->touchKey($key);
            return $this->memoized[$key];
        }

        // Check if we know it's missing
        if (isset($this->memoizedMissing[$key])) {
            return value($default);
        }

        // Get from underlying cache
        $value = $this->repository->get($key, $default);

        // Memoize the result
        if ($value !== $default) {
            $this->memoized[$key] = $value;
            $this->touchKey($key);
            $this->evictIfNeeded();
        } else {
            $this->memoizedMissing[$key] = true;
        }

        return $value;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @param array $keys
     * @return array
     */
    public function many(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull($key, $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function put($key, $value, $ttl = null): bool
    {
        // Clear memoization for this key
        unset($this->memoized[$key], $this->memoizedMissing[$key]);

        return $this->repository->put($key, $value, $ttl);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param array $values
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, $ttl = null): bool
    {
        foreach (array_keys($values) as $key) {
            unset($this->memoized[$key], $this->memoizedMissing[$key]);
        }

        return $this->repository->putMany($values, $ttl);
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function add($key, $value, $ttl = null): bool
    {
        $result = $this->repository->add($key, $value, $ttl);

        if ($result) {
            unset($this->memoized[$key], $this->memoizedMissing[$key]);
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int|bool
     */
    public function increment($key, $value = 1): int|bool
    {
        unset($this->memoized[$key], $this->memoizedMissing[$key]);
        return $this->repository->increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int|bool
     */
    public function decrement($key, $value = 1): int|bool
    {
        unset($this->memoized[$key], $this->memoizedMissing[$key]);
        return $this->repository->decrement($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever($key, $value): bool
    {
        unset($this->memoized[$key], $this->memoizedMissing[$key]);
        return $this->repository->forever($key, $value);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember($key, $ttl, \Closure $callback): mixed
    {
        // Check if already memoized
        if (array_key_exists($key, $this->memoized)) {
            $this->touchKey($key);
            return $this->memoized[$key];
        }

        $value = $this->repository->remember($key, $ttl, $callback);
        $this->memoized[$key] = $value;
        $this->touchKey($key);
        $this->evictIfNeeded();

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    public function sear($key, \Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberForever($key, \Closure $callback): mixed
    {
        // Check if already memoized
        if (array_key_exists($key, $this->memoized)) {
            $this->touchKey($key);
            return $this->memoized[$key];
        }

        $value = $this->repository->rememberForever($key, $callback);
        $this->memoized[$key] = $value;
        $this->touchKey($key);
        $this->evictIfNeeded();

        return $value;
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget($key): bool
    {
        $index = array_search($key, $this->accessOrder, true);
        if ($index !== false) {
            unset($this->accessOrder[$index]);
        }
        unset($this->memoized[$key], $this->memoizedMissing[$key]);
        return $this->repository->forget($key);
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->memoized = [];
        $this->memoizedMissing = [];
        $this->accessOrder = [];
        return $this->repository->flush();
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->repository->getPrefix();
    }

    /**
     * Get the cache store implementation.
     *
     * @return Store
     */
    public function getStore(): Store
    {
        return $this->repository->getStore();
    }

    /**
     * Get the underlying cache repository.
     *
     * @return Repository
     */
    public function getRepository(): Repository
    {
        return $this->repository;
    }

    /**
     * Clear the memoization cache.
     *
     * @return void
     */
    public function clearMemoization(): void
    {
        $this->memoized = [];
        $this->memoizedMissing = [];
        $this->accessOrder = [];
    }

    /**
     * Get memoization statistics.
     *
     * @return array
     */
    public function getMemoizationStats(): array
    {
        return [
            'memoized_count' => \count($this->memoized),
            'missing_count' => \count($this->memoizedMissing),
            'total_memory' => \strlen(serialize($this->memoized)),
            'max_size' => $this->maxSize,
        ];
    }

    /**
     * PSR-16 set method (alias for put).
     *
     * @param string $key
     * @param mixed $value
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * PSR-16 delete method (alias for forget).
     *
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        return $this->forget($key);
    }

    /**
     * PSR-16 clear method (alias for flush).
     *
     * @return bool
     */
    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * PSR-16 getMultiple method (alias for many).
     *
     * @param iterable $keys
     * @param mixed $default
     * @return iterable
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        return $this->many($keys);
    }

    /**
     * PSR-16 setMultiple method (alias for putMany).
     *
     * @param iterable $values
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);
        return $this->putMany($values, $ttl);
    }

    /**
     * PSR-16 deleteMultiple method.
     *
     * @param iterable $keys
     * @return bool
     */
    public function deleteMultiple($keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keys as $key) {
            $this->forget($key);
        }

        return true;
    }
}

