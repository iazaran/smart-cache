<?php

namespace SmartCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\Contracts\SmartCache as SmartCacheContract;

class SmartCache implements SmartCacheContract
{
    /**
     * @var Repository
     */
    protected Repository $cache;

    /**
     * @var CacheManager
     */
    protected CacheManager $cacheManager;

    /**
     * @var ConfigRepository
     */
    protected ConfigRepository $config;

    /**
     * @var OptimizationStrategy[]
     */
    protected array $strategies = [];

    /**
     * @var string|null
     */
    protected ?string $driver = null;

    /**
     * @var array
     */
    protected array $managedKeys = [];

    /**
     * SmartCache constructor.
     *
     * @param Repository $cache
     * @param CacheManager $cacheManager
     * @param ConfigRepository $config
     * @param array $strategies
     */
    public function __construct(Repository $cache, CacheManager $cacheManager, ConfigRepository $config, array $strategies = [])
    {
        $this->cache = $cache;
        $this->cacheManager = $cacheManager;
        $this->config = $config;
        
        foreach ($strategies as $strategy) {
            $this->addStrategy($strategy);
        }
        
        // Determine cache driver
        $store = $cache->getStore();
        $this->driver = $this->determineCacheDriver($store);
        
        // Load tracked keys
        $this->loadManagedKeys();
    }

    /**
     * Add an optimization strategy.
     *
     * @param OptimizationStrategy $strategy
     * @return $this
     */
    public function addStrategy(OptimizationStrategy $strategy): self
    {
        $this->strategies[$strategy->getIdentifier()] = $strategy;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->cache->get($key, null);
        
        if ($value === null) {
            return $default;
        }
        
        return $this->maybeRestoreValue($value, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value, $ttl = null): bool
    {
        $optimizedValue = $this->maybeOptimizeValue($value, $key, $ttl);
        
        // Track the key if it was optimized
        if ($value !== $optimizedValue) {
            $this->trackKey($key);
        }
        
        return $this->cache->put($key, $optimizedValue, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): bool
    {
        $value = $this->cache->get($key);
        
        // If value is chunked, clean up all chunk keys
        if (is_array($value) && isset($value['_sc_chunked']) && $value['_sc_chunked'] === true) {
            foreach ($value['chunk_keys'] as $chunkKey) {
                $this->cache->forget($chunkKey);
            }
        }
        
        // Remove from tracked keys
        $this->untrackKey($key);
        
        return $this->cache->forget($key);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        $optimizedValue = $this->maybeOptimizeValue($value, $key, null);
        
        // Track the key if it was optimized
        if ($value !== $optimizedValue) {
            $this->trackKey($key);
        }
        
        return $this->cache->forever($key, $optimizedValue);
    }

    /**
     * {@inheritdoc}
     */
    public function remember(string $key, $ttl, \Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        
        $value = $callback();
        $this->put($key, $value, $ttl);
        
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function rememberForever(string $key, \Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        
        $value = $callback();
        $this->forever($key, $value);
        
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $name = null): Repository
    {
        if ($name === null) {
            return $this->cache;
        }
        
        return $this->cacheManager->store($name);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $success = true;
        
        // Get all tracked keys
        $keys = $this->getManagedKeys();
        
        foreach ($keys as $key) {
            $success = $this->forget($key) && $success;
        }
        
        // Clear managed keys tracking
        $this->cache->forget('_sc_managed_keys');
        $this->managedKeys = [];
        
        return $success;
    }

    /**
     * Apply optimization strategies if applicable.
     *
     * @param mixed $value
     * @param string $key
     * @param mixed $ttl
     * @return mixed
     */
    protected function maybeOptimizeValue(mixed $value, string $key, mixed $ttl = null): mixed
    {
        $context = [
            'key' => $key,
            'ttl' => $ttl,
            'driver' => $this->driver,
            'cache' => $this->cache,
            'config' => $this->config->get('smart-cache'),
        ];

        $optimizedValue = $value;
        $strategyApplied = false;

        // Try each strategy
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldApply($optimizedValue, $context)) {
                try {
                    $optimizedValue = $strategy->optimize($optimizedValue, $context);
                    $strategyApplied = true;
                } catch (\Throwable $e) {
                    if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                        Log::warning("SmartCache optimization failed for {$key}: " . $e->getMessage());
                    }
                    
                    if ($this->config->get('smart-cache.fallback.enabled', true)) {
                        continue;
                    }
                    
                    throw $e;
                }
            }
        }

        return $optimizedValue;
    }

    /**
     * Restore value if it was optimized.
     *
     * @param mixed $value
     * @param string $key
     * @return mixed
     */
    protected function maybeRestoreValue(mixed $value, string $key): mixed
    {
        $context = [
            'key' => $key,
            'driver' => $this->driver,
            'cache' => $this->cache,
            'config' => $this->config->get('smart-cache'),
        ];

        $restoredValue = $value;

        // Try each strategy to restore
        foreach ($this->strategies as $strategy) {
            try {
                $newValue = $strategy->restore($restoredValue, $context);
                if ($newValue !== $restoredValue) {
                    $restoredValue = $newValue;
                    // If a strategy was applied, we're done (assuming only one is applied at a time)
                    break;
                }
            } catch (\Throwable $e) {
                if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                    Log::warning("SmartCache restoration failed for {$key}: " . $e->getMessage());
                }
                
                if ($this->config->get('smart-cache.fallback.enabled', true)) {
                    // Return the original value as fallback
                    return $value;
                }
                
                throw $e;
            }
        }

        return $restoredValue;
    }

    /**
     * Track a key that has been optimized.
     *
     * @param string $key
     * @return void
     */
    protected function trackKey(string $key): void
    {
        if (!in_array($key, $this->managedKeys)) {
            $this->managedKeys[] = $key;
            $this->cache->forever('_sc_managed_keys', $this->managedKeys);
        }
    }

    /**
     * Untrack a key.
     *
     * @param string $key
     * @return void
     */
    protected function untrackKey(string $key): void
    {
        $index = array_search($key, $this->managedKeys);
        if ($index !== false) {
            unset($this->managedKeys[$index]);
            $this->managedKeys = array_values($this->managedKeys);
            $this->cache->forever('_sc_managed_keys', $this->managedKeys);
        }
    }

    /**
     * Load managed keys from cache.
     *
     * @return void
     */
    protected function loadManagedKeys(): void
    {
        $this->managedKeys = $this->cache->get('_sc_managed_keys', []);
    }

    /**
     * Get all managed keys.
     *
     * @return array
     */
    public function getManagedKeys(): array
    {
        return $this->managedKeys;
    }

    /**
     * Determines the cache driver from the store instance.
     *
     * @param mixed $store
     * @return string|null
     */
    protected function determineCacheDriver($store): ?string
    {
        $class = get_class($store);
        $parts = explode('\\', $class);
        $storeName = end($parts);
        
        // Convert StoreName to store_name (e.g., RedisStore to redis)
        return strtolower(preg_replace('/Store$/', '', preg_replace('/(?<!^)[A-Z]/', '_$0', $storeName)));
    }

    /**
     * Stale-while-revalidate cache with optimization support.
     *
     * @param string $key
     * @param array $durations
     * @param \Closure $callback
     * @return mixed
     */
    public function flexible(string $key, array $durations, \Closure $callback): mixed
    {
        // Check if we have a cached value first
        $cachedValue = $this->cache->get($key);
        
        if ($cachedValue !== null) {
            // If we have a cached value, restore it and return
            return $this->maybeRestoreValue($cachedValue, $key);
        }
        
        // No cached value, so execute callback and optimize
        $value = $callback();
        $optimizedValue = $this->maybeOptimizeValue($value, $key, $durations['ttl'] ?? null);
        
        // Track the key if it was optimized
        if ($value !== $optimizedValue) {
            $this->trackKey($key);
        }
        
        // Store using Laravel's flexible method if available, otherwise use regular put
        if (method_exists($this->cache, 'flexible')) {
            $this->cache->flexible($key, $durations, function () use ($optimizedValue) {
                return $optimizedValue;
            });
        } else {
            // Fallback to regular cache put with TTL
            $this->cache->put($key, $optimizedValue, $durations['ttl'] ?? null);
        }
        
        return $value;
    }

    /**
     * Handle dynamic method calls to the underlying cache repository.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        return $this->cache->{$method}(...$arguments);
    }
} 