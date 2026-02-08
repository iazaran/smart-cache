<?php

namespace SmartCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Cache\Factory as CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Services\CacheInvalidationService;
use SmartCache\Services\CostAwareCacheManager;
use SmartCache\Services\OrphanChunkCleanupService;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Services\RateLimiter;
use SmartCache\Jobs\BackgroundCacheRefreshJob;
use SmartCache\Traits\HasLocks;
use SmartCache\Traits\DispatchesCacheEvents;

class SmartCache implements SmartCacheContract, Repository
{
    use HasLocks, DispatchesCacheEvents;

    /**
     * Sentinel object used to distinguish between a cache miss and a stored null value.
     * This ensures full Laravel contract compliance where null is a valid cached value.
     */
    protected static ?object $sentinel = null;

    /**
     * Get the sentinel object (lazy-initialized).
     */
    protected static function sentinel(): object
    {
        if (static::$sentinel === null) {
            static::$sentinel = new \stdClass();
        }

        return static::$sentinel;
    }

    /**
     * Wrap a null value so it can be stored and distinguished from a cache miss.
     *
     * Laravel's Repository treats null returns from the store as cache misses,
     * so we wrap null values in a marker array before storing.
     */
    protected static function wrapNullValue(mixed $value): mixed
    {
        return $value === null ? ['_sc_null' => true] : $value;
    }

    /**
     * Unwrap a null marker back to an actual null value.
     */
    protected static function unwrapNullValue(mixed $value): mixed
    {
        if (\is_array($value) && \array_key_exists('_sc_null', $value) && $value['_sc_null'] === true && \count($value) === 1) {
            return null;
        }
        return $value;
    }

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
     * @var array
     */
    protected array $activeTags = [];

    /**
     * @var array
     */
    protected array $dependencies = [];

    /**
     * @var string|null Active namespace prefix
     */
    protected ?string $activeNamespace = null;

    /**
     * @var CacheInvalidationService|null
     */
    protected ?CacheInvalidationService $invalidationService = null;

    /**
     * @var array
     */
    protected array $performanceMetrics = [];

    /**
     * @var bool
     */
    protected bool $enablePerformanceMonitoring = true;

    /**
     * @var bool Whether managed keys have been loaded from cache.
     */
    protected bool $managedKeysLoaded = false;

    /**
     * @var bool Whether dependencies have been loaded from cache.
     */
    protected bool $dependenciesLoaded = false;

    /**
     * @var bool Whether performance metrics have been loaded from cache.
     */
    protected bool $performanceMetricsLoaded = false;

    /**
     * @var bool
     */
    protected bool $managedKeysDirty = false;

    /**
     * @var int
     */
    protected int $managedKeysPersistThreshold = 10;

    /**
     * @var int
     */
    protected int $managedKeysChangeCount = 0;

    /**
     * @var OrphanChunkCleanupService|null
     */
    protected ?OrphanChunkCleanupService $chunkCleanupService = null;

    /**
     * @var CircuitBreaker|null
     */
    protected ?CircuitBreaker $circuitBreaker = null;

    /**
     * @var bool Whether circuit breaker is enabled
     */
    protected bool $circuitBreakerEnabled = false;

    /**
     * @var RateLimiter|null
     */
    protected ?RateLimiter $rateLimiter = null;

    /**
     * @var bool Whether TTL jitter is enabled
     */
    protected bool $jitterEnabled = false;

    /**
     * @var float Jitter percentage (0.0 to 1.0)
     */
    protected float $jitterPercentage = 0.1;

    /**
     * @var CostAwareCacheManager|null Cost-aware cache manager for value scoring
     */
    protected ?CostAwareCacheManager $costAwareManager = null;

    /**
     * SmartCache constructor.
     *
     * @param Repository $cache
     * @param CacheManager $cacheManager
     * @param ConfigRepository $config
     * @param array $strategies
     */
    public function __construct(
        Repository $cache,
        CacheManager $cacheManager,
        ConfigRepository $config,
        array $strategies = [],
        ?CostAwareCacheManager $costAwareManager = null
    ) {
        $this->cache = $cache;
        $this->cacheManager = $cacheManager;
        $this->config = $config;
        $this->costAwareManager = $costAwareManager;

        foreach ($strategies as $strategy) {
            $this->addStrategy($strategy);
        }

        // Determine cache driver
        $store = $cache->getStore();
        $this->driver = $this->determineCacheDriver($store);

        // Initialize performance monitoring flag (no cache read needed)
        $this->enablePerformanceMonitoring = $config->get('smart-cache.monitoring.enabled', true);

        // Managed keys, dependencies, and performance metrics are lazy-loaded on first access
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
    public function get($key, $default = null): mixed
    {
        $key = $this->applyNamespace((string) $key);
        $startTime = $this->enablePerformanceMonitoring ? microtime(true) : null;

        $sentinel = static::sentinel();
        $value = $this->cache->get($key, $sentinel);

        if ($value === $sentinel) {
            $this->recordPerformanceMetric('cache_miss', $key, $startTime);
            $this->dispatchCacheMissed($key);
            return $default;
        }

        $restoredValue = $this->maybeRestoreValue($value, $key);
        $this->recordPerformanceMetric('cache_hit', $key, $startTime);
        $this->dispatchCacheHit($key, $restoredValue);

        // Track access frequency for adaptive compression
        $this->trackAccessFrequency($key);

        // Reset active tags to prevent leaking into next operation
        $this->activeTags = [];

        return $restoredValue;
    }

    /**
     * Track access frequency for adaptive compression strategy.
     *
     * @param string $key
     * @return void
     */
    protected function trackAccessFrequency(string $key): void
    {
        if (isset($this->strategies['adaptive_compression'])) {
            $strategy = $this->strategies['adaptive_compression'];
            if ($strategy instanceof \SmartCache\Strategies\AdaptiveCompressionStrategy) {
                $strategy->trackAccess($key);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function put($key, $value, $ttl = null): bool
    {
        $key = $this->applyNamespace((string) $key);
        $startTime = $this->enablePerformanceMonitoring ? microtime(true) : null;

        // Apply jitter to TTL when enabled (prevents thundering herd)
        if ($this->jitterEnabled && \is_int($ttl) && $ttl > 0) {
            $ttl = $this->applyJitter($ttl);
        }

        // Wrap null so the underlying store can distinguish it from a cache miss
        $storable = static::wrapNullValue($value);
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, $ttl);

        // Track all keys for pattern matching and invalidation
        $this->trackKey($key);

        // Handle active tags
        if (!empty($this->activeTags)) {
            $this->associateTagsWithKey($key, $this->activeTags);
        }

        $result = $this->cache->put($key, $optimizedValue, $ttl);

        if ($this->enablePerformanceMonitoring) {
            $this->recordPerformanceMetric('cache_write', $key, $startTime, [
                'original_size' => $this->calculateDataSize($value),
                'optimized_size' => $this->calculateDataSize($optimizedValue),
                'ttl' => $ttl
            ]);
        }

        // Dispatch event
        $seconds = \is_int($ttl) ? $ttl : null;
        $this->dispatchKeyWritten($key, $value, $seconds);

        return $result;
    }

    /**
     * PSR-16 alias for put().
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function has($key): bool
    {
        $key = $this->applyNamespace((string) $key);
        return $this->cache->has($key);
    }

    /**
     * PSR-16 alias for forget().
     *
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        return $this->forget($key);
    }

    /**
     * {@inheritdoc}
     */
    public function forget($key): bool
    {
        $key = $this->applyNamespace((string) $key);
        $value = $this->cache->get($key);

        // If value is chunked, clean up all chunk keys
        if (\is_array($value) && isset($value['_sc_chunked']) && $value['_sc_chunked'] === true) {
            foreach ($value['chunk_keys'] as $chunkKey) {
                $this->cache->forget($chunkKey);
            }
        }

        // Clean up SWR/stampede metadata (consistent format)
        $this->cache->forget("_sc_meta:{$key}");

        // Remove from tracked keys
        $this->untrackKey($key);

        $result = $this->cache->forget($key);

        // Dispatch event
        if ($result) {
            $this->dispatchKeyForgotten($key);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function forever($key, $value): bool
    {
        $key = $this->applyNamespace((string) $key);
        $storable = static::wrapNullValue($value);
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, null);

        // Track all keys for pattern matching and invalidation
        $this->trackKey($key);

        // Handle active tags
        if (!empty($this->activeTags)) {
            $this->associateTagsWithKey($key, $this->activeTags);
        }

        return $this->cache->forever($key, $optimizedValue);
    }

    /**
     * {@inheritdoc}
     */
    public function remember($key, $ttl, \Closure $callback): mixed
    {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            // Record access for cost-aware scoring
            if ($this->costAwareManager !== null) {
                $this->costAwareManager->recordAccess($this->applyNamespace((string) $key));
            }
            return $value;
        }

        // Measure regeneration cost for cost-aware caching
        $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
        $value = $callback();
        $this->put($key, $value, $ttl);

        // Record regeneration cost and value size
        if ($this->costAwareManager !== null && $startTime !== null) {
            $costMs = (\microtime(true) - $startTime) * 1000;
            $size = $this->calculateDataSize($value);
            $this->costAwareManager->recordCost($this->applyNamespace((string) $key), $costMs, $size);
        }

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     * This is an alias for rememberForever().
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
     * {@inheritdoc}
     */
    public function rememberForever($key, \Closure $callback): mixed
    {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            // Record access for cost-aware scoring
            if ($this->costAwareManager !== null) {
                $this->costAwareManager->recordAccess($this->applyNamespace((string) $key));
            }
            return $value;
        }

        // Measure regeneration cost for cost-aware caching
        $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
        $value = $callback();
        $this->forever($key, $value);

        // Record regeneration cost and value size
        if ($this->costAwareManager !== null && $startTime !== null) {
            $costMs = (\microtime(true) - $startTime) * 1000;
            $size = $this->calculateDataSize($value);
            $this->costAwareManager->recordCost($this->applyNamespace((string) $key), $costMs, $size);
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function store(string|null $name = null): static
    {
        if ($name === null) {
            return $this;
        }

        // Create a new SmartCache instance with the specified store
        // This preserves all optimization strategies and runtime configuration
        $instance = new static(
            $this->cacheManager->store($name),
            $this->cacheManager,
            $this->config,
            $this->strategies
        );

        // Preserve runtime configuration
        $instance->jitterEnabled = $this->jitterEnabled;
        $instance->jitterPercentage = $this->jitterPercentage;
        $instance->activeNamespace = $this->activeNamespace;
        $instance->circuitBreakerEnabled = $this->circuitBreakerEnabled;
        $instance->costAwareManager = $this->costAwareManager;

        return $instance;
    }

    /**
     * Get the underlying cache repository.
     *
     * This provides direct access to the Laravel cache repository without SmartCache optimizations.
     * Use this when you need raw access to the cache driver.
     *
     * @param string|null $name The store name (null for current store)
     * @return Repository
     */
    public function repository(string|null $name = null): Repository
    {
        if ($name === null) {
            return $this->cache;
        }

        return $this->cacheManager->store($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getStore(): Store
    {
        return $this->cache->getStore();
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param array|string $key
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
     * Store an item in the cache if the key does not exist.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function add($key, $value, $ttl = null): bool
    {
        $namespacedKey = $this->applyNamespace((string) $key);

        // Use the underlying cache's atomic add() if available
        $storable = static::wrapNullValue($value);
        if (\method_exists($this->cache, 'add')) {
            $optimizedValue = $this->maybeOptimizeValue($storable, $namespacedKey, $ttl);
            $result = $this->cache->add($namespacedKey, $optimizedValue, $ttl);

            if ($result) {
                $this->trackKey($namespacedKey);

                if (!empty($this->activeTags)) {
                    $this->associateTagsWithKey($namespacedKey, $this->activeTags);
                }
            }

            return $result;
        }

        // Fallback: non-atomic approach for stores that don't support add()
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
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
        $key = $this->applyNamespace((string) $key);
        return $this->cache->increment($key, $value);
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
        $key = $this->applyNamespace((string) $key);
        return $this->cache->decrement($key, $value);
    }

    /**
     * PSR-16: Obtains multiple cache items identified by their unique keys.
     *
     * @param iterable $keys
     * @param mixed $default
     * @return iterable
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    /**
     * PSR-16: Persists a set of key => value pairs in the cache.
     *
     * @param iterable $values
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->put($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $success = true;

        // Clean up expired keys first
        $this->cleanupExpiredManagedKeys();

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
     * Flush the entire cache store (clears ALL keys, not just managed ones).
     *
     * This delegates to the underlying cache store's flush operation,
     * which clears everything regardless of whether SmartCache manages it.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $result = $this->cache->getStore()->flush();

        // Reset internal state
        $this->managedKeys = [];
        $this->managedKeysDirty = false;
        $this->managedKeysChangeCount = 0;
        $this->dependencies = [];
        $this->performanceMetrics = [];

        return $result;
    }

    /**
     * Get raw (unrestored) value from cache.
     *
     * This is useful for statistics and diagnostics where you need to see
     * the optimization markers (e.g., _sc_compressed, _sc_chunked) without
     * them being transparently restored.
     *
     * @param string $key
     * @return mixed
     */
    public function getRaw(string $key): mixed
    {
        $key = $this->applyNamespace((string) $key);
        $sentinel = static::sentinel();
        $value = $this->cache->get($key, $sentinel);

        return $value === $sentinel ? null : $value;
    }

    /**
     * Get the value score for a cache key (cost-aware caching).
     *
     * Returns metadata about how valuable a cache key is based on regeneration cost,
     * access frequency, size, and time decay.
     *
     * @param string $key
     * @return array{cost_ms: float, access_count: int, size_bytes: int, last_accessed: int, created_at: int, score: float}|null
     */
    public function cacheValue(string $key): ?array
    {
        if ($this->costAwareManager === null) {
            return null;
        }

        return $this->costAwareManager->getKeyMetadata($this->applyNamespace((string) $key));
    }

    /**
     * Get a report of all tracked cache keys sorted by value (cost-aware caching).
     *
     * Returns an array of all tracked keys with their scores, sorted highest-first.
     * Higher scores mean more valuable keys (expensive to regenerate, frequently accessed).
     *
     * @return array
     */
    public function getCacheValueReport(): array
    {
        if ($this->costAwareManager === null) {
            return [];
        }

        return $this->costAwareManager->getValueReport();
    }

    /**
     * Get suggestions for which cache keys to evict based on lowest value.
     *
     * @param int $count Number of eviction suggestions
     * @return array
     */
    public function suggestEvictions(int $count = 10): array
    {
        if ($this->costAwareManager === null) {
            return [];
        }

        return $this->costAwareManager->suggestEvictions($count);
    }

    /**
     * Get the cost-aware cache manager instance.
     *
     * @return CostAwareCacheManager|null
     */
    public function getCostAwareManager(): ?CostAwareCacheManager
    {
        return $this->costAwareManager;
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

        // Find the best strategy for the original value
        // Each strategy evaluates against the original value, not chained results
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldApply($value, $context)) {
                try {
                    return $strategy->optimize($value, $context);
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

        return $value;
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

        // Unwrap null marker (stored by wrapNullValue in put/forever/add)
        return static::unwrapNullValue($restoredValue);
    }

    /**
     * Track a key that has been optimized.
     *
     * @param string $key
     * @return void
     */
    protected function trackKey(string $key): void
    {
        $this->ensureManagedKeysLoaded();

        if (!isset($this->managedKeys[$key])) {
            $this->managedKeys[$key] = true;
            $this->managedKeysDirty = true;
            $this->managedKeysChangeCount++;

            if ($this->managedKeysChangeCount >= $this->managedKeysPersistThreshold) {
                $this->persistManagedKeys();
            }
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
        $this->ensureManagedKeysLoaded();

        if (isset($this->managedKeys[$key])) {
            unset($this->managedKeys[$key]);
            $this->managedKeysDirty = true;
            $this->managedKeysChangeCount++;

            // Persist periodically based on threshold
            if ($this->managedKeysChangeCount >= $this->managedKeysPersistThreshold) {
                $this->persistManagedKeys();
            }
        }
    }

    /**
     * Persist managed keys to cache if dirty.
     *
     * @return void
     */
    public function persistManagedKeys(): void
    {
        if ($this->managedKeysDirty) {
            // Store as indexed array for backward compatibility with existing cache data
            $this->cache->forever('_sc_managed_keys', \array_keys($this->managedKeys));
            $this->managedKeysDirty = false;
            $this->managedKeysChangeCount = 0;
        }
    }

    /**
     * Destructor to ensure managed keys are persisted.
     */
    public function __destruct()
    {
        $this->persistManagedKeys();
    }

    /**
     * Ensure managed keys are loaded from cache (lazy loading).
     *
     * @return void
     */
    protected function ensureManagedKeysLoaded(): void
    {
        if (!$this->managedKeysLoaded) {
            $this->loadManagedKeys();
            $this->managedKeysLoaded = true;
        }
    }

    /**
     * Ensure dependencies are loaded from cache (lazy loading).
     *
     * @return void
     */
    protected function ensureDependenciesLoaded(): void
    {
        if (!$this->dependenciesLoaded) {
            $this->loadDependencies();
            $this->dependenciesLoaded = true;
        }
    }

    /**
     * Ensure performance metrics are loaded from cache (lazy loading).
     *
     * @return void
     */
    protected function ensurePerformanceMetricsLoaded(): void
    {
        if (!$this->performanceMetricsLoaded) {
            $this->loadPerformanceMetrics();
            $this->performanceMetricsLoaded = true;
        }
    }

    /**
     * Load managed keys from cache.
     *
     * @return void
     */
    protected function loadManagedKeys(): void
    {
        $keys = $this->cache->get('_sc_managed_keys', []);
        // Convert indexed array from cache to associative array for O(1) lookups
        $this->managedKeys = \array_fill_keys($keys, true);
    }

    /**
     * Get all managed keys.
     *
     * @return array
     */
    public function getManagedKeys(): array
    {
        $this->ensureManagedKeysLoaded();

        return \array_keys($this->managedKeys);
    }

    /**
     * Clean up expired keys from managed keys tracking.
     *
     * @return int Number of expired keys removed
     */
    public function cleanupExpiredManagedKeys(): int
    {
        $this->ensureManagedKeysLoaded();

        $cleaned = 0;
        $validKeys = [];

        foreach ($this->managedKeys as $key => $flag) {
            if ($this->has($key)) {
                $validKeys[$key] = true;
            } else {
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->managedKeys = $validKeys;
            $this->cache->forever('_sc_managed_keys', \array_keys($this->managedKeys));
        }

        return $cleaned;
    }

    /**
     * Check if a specific feature is available.
     *
     * @param string $feature The feature name to check
     * @return bool
     */
    public function hasFeature(string $feature): bool
    {
        $features = [
            'tags' => \method_exists($this, 'tags'),
            'flushTags' => \method_exists($this, 'flushTags'),
            'dependsOn' => \method_exists($this, 'dependsOn'),
            'invalidate' => \method_exists($this, 'invalidate'),
            'flushPatterns' => \method_exists($this, 'flushPatterns'),
            'invalidateModel' => \method_exists($this, 'invalidateModel'),
            'swr' => \method_exists($this, 'swr'),
            'stale' => \method_exists($this, 'stale'),
            'refreshAhead' => \method_exists($this, 'refreshAhead'),
            'flexible' => \method_exists($this, 'flexible'),
            'getStatistics' => \method_exists($this, 'getStatistics'),
            'healthCheck' => \method_exists($this, 'healthCheck'),
            'getPerformanceMetrics' => \method_exists($this, 'getPerformanceMetrics'),
            'analyzePerformance' => \method_exists($this, 'analyzePerformance'),
            'getAvailableCommands' => \method_exists($this, 'getAvailableCommands'),
            'executeCommand' => \method_exists($this, 'executeCommand'),
        ];

        return $features[$feature] ?? false;
    }

    /**
     * Determines the cache driver from the store instance.
     *
     * @param mixed $store
     * @return string|null
     */
    protected function determineCacheDriver($store): ?string
    {
        $class = \get_class($store);
        $parts = \explode('\\', $class);
        $storeName = \end($parts);

        return \strtolower(\preg_replace('/Store$/', '', \preg_replace('/(?<!^)[A-Z]/', '_$0', $storeName)));
    }

    /**
     * Stale-while-revalidate cache with optimization support.
     * 
     * Laravel's flexible method uses: [freshTtl, staleTtl]
     * - freshTtl: seconds data is considered fresh
     * - staleTtl: absolute seconds to serve stale data while revalidating
     *
     * @param string $key
     * @param array $durations [freshTtl, staleTtl]
     * @param \Closure $callback
     * @return mixed
     */
    public function flexible(string $key, array $durations, \Closure $callback): mixed
    {
        $namespacedKey = $this->applyNamespace((string) $key);
        $freshTtl = $durations[0] ?? 3600;  // Default 1 hour fresh
        $staleTtl = $durations[1] ?? 7200;  // Default 2 hours stale (absolute time)
        $totalTtl = $staleTtl;

        // Get cached value with timestamp using sentinel for null support
        $metaKey = "_sc_meta:{$namespacedKey}";
        $sentinel = static::sentinel();
        $cachedValue = $this->cache->get($namespacedKey, $sentinel);
        $cachedMeta = $this->cache->get($metaKey);

        if ($cachedValue !== $sentinel && $cachedMeta !== null) {
            $age = time() - ($cachedMeta['stored_at'] ?? $cachedMeta['created_at'] ?? time());

            // If data is fresh, return it
            if ($age <= $freshTtl) {
                return $this->maybeRestoreValue($cachedValue, $namespacedKey);
            }

            // If data is stale but within stale period, return stale and refresh in background
            if ($age <= $totalTtl) {
                // Return stale data immediately
                $staleValue = $this->maybeRestoreValue($cachedValue, $namespacedKey);

                // Trigger background refresh (simplified - in real implementation would be async)
                $this->refreshInBackground($namespacedKey, $durations, $callback);

                return $staleValue;
            }
        }

        // No cache or expired beyond stale period - generate fresh data
        return $this->generateAndCache($namespacedKey, $durations, $callback);
    }

    /**
     * Generate fresh data and cache it with metadata.
     */
    protected function generateAndCache(string $key, array $durations, \Closure $callback): mixed
    {
        $value = $callback();
        $freshTtl = $durations[0] ?? 3600;
        $staleTtl = $durations[1] ?? 7200;
        $totalTtl = $staleTtl;

        // Wrap null so the store can distinguish it from a cache miss
        $storable = static::wrapNullValue($value);
        // Optimize the value
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, $totalTtl);

        // Track all keys for pattern matching and invalidation
        $this->trackKey($key);

        // Store data and metadata with consistent meta key format
        $metaKey = "_sc_meta:{$key}";
        $this->cache->put($key, $optimizedValue, $totalTtl);
        $this->cache->put($metaKey, ['stored_at' => time(), 'created_at' => time(), 'fresh_ttl' => $freshTtl], $totalTtl);

        return $value;
    }

    /**
     * Refresh cache in background (simplified version).
     */
    protected function refreshInBackground(string $key, array $durations, \Closure $callback): void
    {
        // In a real implementation, this would be dispatched to a queue
        // For testing purposes, we'll do it synchronously but mark it as background refresh
        try {
            $this->generateAndCache($key, $durations, $callback);
        } catch (\Throwable $e) {
            // Background refresh failed - log but don't interrupt main flow
            if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                Log::warning("SmartCache background refresh failed for {$key}: " . $e->getMessage());
            }
        }
    }

    /**
     * Stale-While-Revalidate (SWR) caching pattern.
     * 
     * Returns cached data immediately, triggers background refresh if stale.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl TTL in seconds (default: 1 hour)
     * @param int $staleTtl Maximum stale time in seconds (default: 2 hours)
     * @return mixed
     */
    public function swr(string $key, \Closure $callback, int $ttl = 3600, int $staleTtl = 7200): mixed
    {
        return $this->flexible($key, [$ttl, $staleTtl], $callback);
    }

    /**
     * Stale cache pattern - allows serving stale data beyond TTL.
     * 
     * Serves stale data for extended period while attempting background refresh.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl Fresh TTL in seconds (default: 30 minutes)
     * @param int $staleTtl Extended stale TTL in seconds (default: 24 hours)
     * @return mixed
     */
    public function stale(string $key, \Closure $callback, int $ttl = 1800, int $staleTtl = 86400): mixed
    {
        return $this->flexible($key, [$ttl, $staleTtl], $callback);
    }

    /**
     * Refresh-Ahead caching pattern.
     * 
     * Proactively refreshes cache before expiration to avoid cache misses.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl Cache TTL in seconds (default: 1 hour)
     * @param int $refreshWindow Time before expiry to trigger refresh in seconds (default: 10 minutes)
     * @return mixed
     */
    public function refreshAhead(string $key, \Closure $callback, int $ttl = 3600, int $refreshWindow = 600): mixed
    {
        // For refresh-ahead, we use a shorter fresh period and longer stale period
        $freshTtl = max(1, $ttl - $refreshWindow); // Refresh before actual expiry
        $staleTtl = $ttl + $refreshWindow; // Allow some grace period

        return $this->flexible($key, [$freshTtl, $staleTtl], $callback);
    }

    /**
     * Queue a background cache refresh job.
     *
     * This dispatches a job to refresh the cache value in the background,
     * enabling true async SWR patterns. The callback must be serializable
     * (e.g., "Class@method" or an invokable class name).
     *
     * @param string $key
     * @param callable|string $callback Serializable callback
     * @param int|null $ttl
     * @param string|null $queue Queue name (null for default)
     * @return void
     */
    public function refreshAsync(string $key, callable|string $callback, ?int $ttl = null, ?string $queue = null): void
    {
        $job = new BackgroundCacheRefreshJob($key, $callback, $ttl, $this->activeTags);

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Get a cached value, or return stale and queue a background refresh.
     *
     * This is a true async SWR implementation that returns immediately with
     * the stale value (if available) and queues a background job to refresh.
     *
     * @param string $key
     * @param callable|string $callback Serializable callback for refresh
     * @param int $ttl Fresh TTL in seconds
     * @param int $staleTtl Maximum stale time in seconds
     * @param string|null $queue Queue name for background refresh
     * @return mixed
     */
    public function asyncSwr(string $key, callable|string $callback, int $ttl = 3600, int $staleTtl = 7200, ?string $queue = null): mixed
    {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            // Check if we should trigger a background refresh
            $namespacedKey = $this->applyNamespace((string) $key);
            $metadata = $this->cache->get("_sc_meta:{$namespacedKey}");
            if ($metadata && isset($metadata['created_at'])) {
                $age = time() - $metadata['created_at'];
                if ($age > $ttl) {
                    // Value is stale, queue background refresh
                    $this->refreshAsync($key, $callback, $staleTtl, $queue);
                }
            }
            return $value;
        }

        // No cached value, we need to compute it synchronously
        $freshValue = \is_callable($callback) ? $callback() : $this->resolveCallback($callback);
        $this->put($key, $freshValue, $staleTtl);
        $namespacedKey = $this->applyNamespace((string) $key);
        $this->cache->put("_sc_meta:{$namespacedKey}", ['created_at' => time(), 'stored_at' => time()], $staleTtl);

        return $freshValue;
    }

    /**
     * Resolve a serializable callback.
     *
     * @param string $callback
     * @return mixed
     */
    protected function resolveCallback(string $callback): mixed
    {
        if (str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $instance = app($class);
            return $instance->$method();
        }

        if (\class_exists($callback)) {
            $instance = app($callback);
            return $instance();
        }

        throw new \InvalidArgumentException("Invalid callback: {$callback}");
    }

    /**
     * {@inheritdoc}
     */
    public function tags(string|array $tags): static
    {
        $this->activeTags = \is_array($tags) ? $tags : [$tags];
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flushTags(string|array $tags): bool
    {
        $tags = \is_array($tags) ? $tags : [$tags];

        foreach ($tags as $tag) {
            $tagKeys = $this->getKeysForTag($tag);
            foreach ($tagKeys as $key) {
                // Use forget method which handles both regular and optimized cache cleanup
                // We don't care about the return value - if key doesn't exist, that's fine
                $this->forget($key);
            }
            // Clean up the tag itself
            $this->cache->forget("_sc_tag_{$tag}");
        }

        return true; // Always return true since we handle missing keys gracefully
    }

    /**
     * Set the active namespace for cache keys.
     *
     * @param string $namespace
     * @return static
     */
    public function namespace(string $namespace): static
    {
        $this->activeNamespace = $namespace;
        return $this;
    }

    /**
     * Clear the active namespace.
     *
     * @return static
     */
    public function withoutNamespace(): static
    {
        $this->activeNamespace = null;
        return $this;
    }

    /**
     * Get the current namespace.
     *
     * @return string|null
     */
    public function getNamespace(): ?string
    {
        return $this->activeNamespace;
    }

    /**
     * Apply namespace prefix to a key.
     *
     * @param string $key
     * @return string
     */
    protected function applyNamespace(string $key): string
    {
        if ($this->activeNamespace === null) {
            return $key;
        }

        return "{$this->activeNamespace}:{$key}";
    }

    /**
     * Flush all keys in a namespace.
     *
     * @param string $namespace
     * @return int Number of keys flushed
     */
    public function flushNamespace(string $namespace): int
    {
        $prefix = "{$namespace}:";
        $flushed = 0;

        // Save and clear the active namespace to avoid double-prefixing
        $savedNamespace = $this->activeNamespace;
        $this->activeNamespace = null;

        foreach ($this->getManagedKeys() as $key) {
            if (str_starts_with($key, $prefix)) {
                $this->forget($key);
                $flushed++;
            }
        }

        // Restore the namespace
        $this->activeNamespace = $savedNamespace;

        return $flushed;
    }

    /**
     * Get all keys in a namespace.
     *
     * @param string $namespace
     * @return array
     */
    public function getNamespaceKeys(string $namespace): array
    {
        $prefix = "{$namespace}:";
        $keys = [];

        foreach ($this->getManagedKeys() as $key) {
            if (str_starts_with($key, $prefix)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function dependsOn(string $key, string|array $dependencies): static
    {
        $this->ensureDependenciesLoaded();

        $dependencies = \is_array($dependencies) ? $dependencies : [$dependencies];

        if (!isset($this->dependencies[$key])) {
            $this->dependencies[$key] = [];
        }

        $this->dependencies[$key] = \array_unique([...$this->dependencies[$key], ...$dependencies]);
        $this->saveDependencies();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate(string $key): bool
    {
        return $this->invalidateWithVisited($key, []);
    }

    /**
     * Invalidate with circular dependency detection.
     */
    protected function invalidateWithVisited(string $key, array $visited): bool
    {
        $this->ensureDependenciesLoaded();

        if (\in_array($key, $visited, true)) {
            return true;
        }

        $visited[] = $key;
        $success = true;
        $dependentKeys = $this->getDependentKeys($key);

        foreach ($dependentKeys as $dependentKey) {
            $success = $this->invalidateWithVisited($dependentKey, $visited) && $success;
        }

        $this->forget($key);
        unset($this->dependencies[$key]);
        $this->saveDependencies();

        return $success;
    }

    /**
     * Associate tags with a cache key.
     *
     * @param string $key
     * @param array $tags
     * @return void
     */
    protected function associateTagsWithKey(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagKey = "_sc_tag_{$tag}";
            $taggedKeys = $this->cache->get($tagKey, []);

            if (!\in_array($key, $taggedKeys, true)) {
                $taggedKeys[] = $key;
                $this->cache->forever($tagKey, $taggedKeys);
            }
        }

        $this->activeTags = [];
    }

    protected function getKeysForTag(string $tag): array
    {
        $tagKey = "_sc_tag_{$tag}";
        return $this->cache->get($tagKey, []);
    }

    protected function getDependentKeys(string $key): array
    {
        $this->ensureDependenciesLoaded();

        $dependentKeys = [];

        foreach ($this->dependencies as $dependentKey => $deps) {
            if (\in_array($key, $deps, true)) {
                $dependentKeys[] = $dependentKey;
            }
        }

        return $dependentKeys;
    }

    /**
     * Load dependencies from cache.
     *
     * @return void
     */
    protected function loadDependencies(): void
    {
        $this->dependencies = $this->cache->get('_sc_dependencies', []);
    }

    /**
     * Save dependencies to cache.
     *
     * @return void
     */
    protected function saveDependencies(): void
    {
        $this->cache->forever('_sc_dependencies', $this->dependencies);
    }

    /**
     * Get the cache invalidation service.
     *
     * @return CacheInvalidationService
     */
    public function invalidationService(): CacheInvalidationService
    {
        if ($this->invalidationService === null) {
            $this->invalidationService = new CacheInvalidationService($this);
        }
        
        return $this->invalidationService;
    }

    /**
     * Flush cache by patterns.
     *
     * @param array $patterns
     * @return int Number of keys invalidated
     */
    public function flushPatterns(array $patterns): int
    {
        return $this->invalidationService()->flushPatterns($patterns);
    }

    /**
     * Invalidate model-related cache.
     *
     * @param string $modelClass
     * @param mixed $modelId
     * @param array $relationships
     * @return int Number of keys invalidated
     */
    public function invalidateModel(string $modelClass, mixed $modelId, array $relationships = []): int
    {
        return $this->invalidationService()->invalidateModelRelations($modelClass, $modelId, $relationships);
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return $this->invalidationService()->getCacheStatistics();
    }

    /**
     * Perform health check and cleanup.
     *
     * @return array
     */
    public function healthCheck(): array
    {
        return $this->invalidationService()->healthCheckAndCleanup();
    }

    /**
     * Get the orphan chunk cleanup service.
     *
     * @return OrphanChunkCleanupService
     */
    public function chunkCleanupService(): OrphanChunkCleanupService
    {
        if ($this->chunkCleanupService === null) {
            $this->chunkCleanupService = new OrphanChunkCleanupService($this->cache);
        }

        return $this->chunkCleanupService;
    }

    /**
     * Clean up orphan cache chunks.
     *
     * @return array Statistics about the cleanup
     */
    public function cleanupOrphanChunks(): array
    {
        return $this->chunkCleanupService()->cleanupOrphanChunks();
    }

    /**
     * Get the circuit breaker instance.
     *
     * @return CircuitBreaker
     */
    public function circuitBreaker(): CircuitBreaker
    {
        if ($this->circuitBreaker === null) {
            $this->circuitBreaker = new CircuitBreaker(
                $this->config->get('smart-cache.circuit_breaker.failure_threshold', 5),
                $this->config->get('smart-cache.circuit_breaker.recovery_timeout', 30),
                $this->config->get('smart-cache.circuit_breaker.success_threshold', 3)
            );
        }

        return $this->circuitBreaker;
    }

    /**
     * Enable the circuit breaker.
     *
     * @return static
     */
    public function withCircuitBreaker(): static
    {
        $this->circuitBreakerEnabled = true;
        return $this;
    }

    /**
     * Disable the circuit breaker.
     *
     * @return static
     */
    public function withoutCircuitBreaker(): static
    {
        $this->circuitBreakerEnabled = false;
        return $this;
    }

    /**
     * Check if the cache backend is available (circuit breaker check).
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (!$this->circuitBreakerEnabled) {
            return true;
        }

        return $this->circuitBreaker()->isAvailable();
    }

    /**
     * Get circuit breaker statistics.
     *
     * @return array
     */
    public function getCircuitBreakerStats(): array
    {
        return $this->circuitBreaker()->getStats();
    }

    /**
     * Execute a cache operation with circuit breaker protection.
     *
     * @param callable $callback
     * @param mixed $fallback
     * @return mixed
     */
    public function withFallback(callable $callback, mixed $fallback = null): mixed
    {
        if (!$this->circuitBreakerEnabled) {
            return $callback();
        }

        return $this->circuitBreaker()->executeWithFallback($callback, $fallback);
    }

    /**
     * Get the rate limiter instance.
     *
     * @return RateLimiter
     */
    public function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new RateLimiter(
                $this->cache,
                $this->config->get('smart-cache.rate_limiter.window', 60),
                $this->config->get('smart-cache.rate_limiter.max_attempts', 10)
            );
        }

        return $this->rateLimiter;
    }

    /**
     * Execute a callback with rate limiting.
     *
     * @param string $key
     * @param int $maxAttempts Maximum number of attempts allowed
     * @param int $decaySeconds Time window in seconds
     * @param callable $callback
     * @return mixed
     */
    public function throttle(
        string $key,
        int $maxAttempts,
        int $decaySeconds,
        callable $callback
    ): mixed {
        return $this->rateLimiter()->throttle(
            $key,
            $callback,
            fn () => null,
            $maxAttempts,
            $decaySeconds
        );
    }

    /**
     * Remember with stampede protection.
     *
     * Uses probabilistic early expiration to prevent cache stampede.
     *
     * @param string $key
     * @param int $ttl
     * @param \Closure $callback
     * @param float $beta Beta parameter for XFetch algorithm (default: 1.0)
     * @return mixed
     */
    public function rememberWithStampedeProtection(
        string $key,
        int $ttl,
        \Closure $callback,
        float $beta = 1.0
    ): mixed {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            $namespacedKey = $this->applyNamespace((string) $key);
            $metadata = $this->cache->get("_sc_meta:{$namespacedKey}");
            if ($metadata && isset($metadata['created_at'])) {
                if ($this->rateLimiter()->shouldRefreshProbabilistically($ttl, $metadata['created_at'], $beta)) {
                    if ($this->rateLimiter()->attempt("refresh:{$key}", 1, $ttl)) {
                        $value = $callback();
                        $this->put($key, $value, $ttl);
                        $this->cache->put("_sc_meta:{$namespacedKey}", ['created_at' => \time(), 'stored_at' => \time()], $ttl);
                    }
                }
            }
            return $value;
        }

        // No cached value, compute and store
        $value = $callback();
        $this->put($key, $value, $ttl);
        $namespacedKey = $this->applyNamespace((string) $key);
        $this->cache->put("_sc_meta:{$namespacedKey}", ['created_at' => time(), 'stored_at' => time()], $ttl);

        return $value;
    }

    /**
     * Enable TTL jitter.
     *
     * @param float $percentage Jitter percentage (0.0 to 1.0, default: 0.1 = 10%)
     * @return static
     */
    public function withJitter(float $percentage = 0.1): static
    {
        $this->jitterEnabled = true;
        $this->jitterPercentage = max(0.0, min(1.0, $percentage));
        return $this;
    }

    /**
     * Disable TTL jitter.
     *
     * @return static
     */
    public function withoutJitter(): static
    {
        $this->jitterEnabled = false;
        return $this;
    }

    /**
     * Apply jitter to a TTL value.
     *
     * @param int|null $ttl
     * @param float|null $jitterPercentage Optional override for jitter percentage
     * @return int|null
     */
    public function applyJitter(?int $ttl, ?float $jitterPercentage = null): ?int
    {
        if ($ttl === null || !$this->jitterEnabled || $ttl <= 0) {
            return $ttl;
        }

        // Use provided jitter percentage or fall back to configured value
        $percentage = $jitterPercentage ?? $this->jitterPercentage;

        // Calculate jitter range
        $jitterRange = (int) ($ttl * $percentage);

        if ($jitterRange <= 0) {
            return $ttl;
        }

        // Apply random jitter (can be positive or negative)
        $jitter = mt_rand(-$jitterRange, $jitterRange);

        // Ensure TTL doesn't go below 1 second
        return max(1, $ttl + $jitter);
    }

    /**
     * Put a value with automatic jitter applied.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param float $jitterPercentage
     * @return bool
     */
    public function putWithJitter(string $key, mixed $value, int $ttl, float $jitterPercentage = 0.1): bool
    {
        return $this->put($key, $value, $this->applyJitter($ttl, $jitterPercentage));
    }

    /**
     * Remember with automatic jitter applied.
     *
     * @param string $key
     * @param int $ttl
     * @param float $jitterPercentage
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberWithJitter(string $key, int $ttl, float $jitterPercentage, \Closure $callback): mixed
    {
        return $this->remember($key, $this->applyJitter($ttl, $jitterPercentage), $callback);
    }

    /**
     * Get available Artisan commands information.
     *
     * @return array
     */
    public function getAvailableCommands(): array
    {
        try {
            return app('smart-cache.commands', []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Execute a command programmatically (HTTP context).
     *
     * @param string $command
     * @param array $parameters
     * @return array
     */
    public function executeCommand(string $command, array $parameters = []): array
    {
        try {
            switch ($command) {
                case 'clear':
                case 'smart-cache:clear':
                    return $this->executeClearCommand($parameters);
                    
                case 'status':
                case 'smart-cache:status':
                    return $this->executeStatusCommand($parameters);
                    
                default:
                    return [
                        'success' => false,
                        'message' => "Unknown command: {$command}",
                        'available_commands' => array_keys($this->getAvailableCommands())
                    ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Command execution failed: ' . $e->getMessage(),
                'exception' => \get_class($e)
            ];
        }
    }

    protected function executeClearCommand(array $parameters = []): array
    {
        $key = $parameters['key'] ?? null;
        $force = $parameters['force'] ?? false;

        if ($key) {
            $managedKeys = $this->getManagedKeys();
            $isManaged = \in_array($key, $managedKeys, true);
            $keyExists = $this->has($key);

            if (!$isManaged && !$force) {
                return [
                    'success' => false,
                    'message' => "Key '{$key}' is not managed by SmartCache. Use force=true to clear anyway.",
                    'cleared_count' => 0
                ];
            }

            if (!$keyExists) {
                return [
                    'success' => false,
                    'message' => "Key '{$key}' does not exist.",
                    'cleared_count' => 0
                ];
            }

            $success = $isManaged ? $this->forget($key) : $this->store()->forget($key);

            return [
                'success' => $success,
                'message' => $success ? "Key '{$key}' cleared successfully." : "Failed to clear key '{$key}'.",
                'cleared_count' => $success ? 1 : 0,
                'key' => $key,
                'was_managed' => $isManaged
            ];
        }

        $keys = $this->getManagedKeys();
        $count = \count($keys);

        if ($count === 0) {
            return [
                'success' => true,
                'message' => 'No SmartCache managed keys found.',
                'cleared_count' => 0
            ];
        }

        $expiredCleaned = $this->cleanupExpiredManagedKeys();
        $keys = $this->getManagedKeys();
        $actualCount = \count($keys);

        if ($actualCount === 0) {
            return [
                'success' => true,
                'message' => "All {$count} managed keys were expired and have been cleaned up.",
                'cleared_count' => $count,
                'expired_cleaned' => $expiredCleaned,
                'total_managed_keys' => $count
            ];
        }

        $success = $this->clear();

        return [
            'success' => $success,
            'message' => $success ? "Cleared {$actualCount} SmartCache managed keys." : 'Some keys could not be cleared.',
            'cleared_count' => $success ? $actualCount : 0,
            'expired_cleaned' => $expiredCleaned,
            'total_managed_keys' => $count,
            'active_keys_cleared' => $actualCount
        ];
    }

    protected function executeStatusCommand(array $parameters = []): array
    {
        $force = $parameters['force'] ?? false;
        $managedKeys = $this->getManagedKeys();
        $count = \count($managedKeys);

        $result = [
            'success' => true,
            'cache_driver' => $this->config->get('cache.default'),
            'managed_keys_count' => $count,
            'sample_keys' => \array_slice($managedKeys, 0, \min(5, $count)),
            'configuration' => $this->config->get('smart-cache'),
            'statistics' => $this->getStatistics(),
            'health_check' => $this->healthCheck()
        ];

        if ($force) {
            $missingKeys = [];
            foreach ($managedKeys as $key) {
                if (!$this->has($key)) {
                    $missingKeys[] = $key;
                }
            }

            $result['analysis'] = [
                'managed_keys_missing_from_cache' => $missingKeys,
                'missing_keys_count' => \count($missingKeys)
            ];
        }

        return $result;
    }

    protected function recordPerformanceMetric(string $operation, string $key, ?float $startTime, array $metadata = []): void
    {
        if (!$this->enablePerformanceMonitoring || $startTime === null) {
            return;
        }

        $this->ensurePerformanceMetricsLoaded();

        $duration = \microtime(true) - $startTime;
        $timestamp = \time();

        if (!isset($this->performanceMetrics[$operation])) {
            $this->performanceMetrics[$operation] = [
                'count' => 0,
                'total_duration' => 0.0,
                'average_duration' => 0.0,
                'max_duration' => 0.0,
                'min_duration' => PHP_FLOAT_MAX
            ];
        }

        $metrics = &$this->performanceMetrics[$operation];
        $metrics['count']++;
        $metrics['total_duration'] += $duration;
        $metrics['average_duration'] = $metrics['total_duration'] / $metrics['count'];
        $metrics['max_duration'] = \max($metrics['max_duration'], $duration);
        $metrics['min_duration'] = \min($metrics['min_duration'], $duration);

        if (!isset($metrics['recent'])) {
            $metrics['recent'] = [];
        }

        $metrics['recent'][] = [
            'key' => $key,
            'duration' => $duration,
            'timestamp' => $timestamp,
            'metadata' => $metadata
        ];

        if (\count($metrics['recent']) > 100) {
            $metrics['recent'] = \array_slice($metrics['recent'], -100);
        }

        if ($metrics['count'] % 50 === 0) {
            $this->persistPerformanceMetrics();
        }
    }

    protected function calculateDataSize(mixed $data): int
    {
        if (\is_string($data)) {
            return \strlen($data);
        }

        if (\is_int($data) || \is_float($data) || \is_bool($data)) {
            return PHP_INT_SIZE;
        }

        if ($data === null) {
            return 0;
        }

        return \strlen(\serialize($data));
    }

    public function getPerformanceMetrics(): array
    {
        $this->ensurePerformanceMetricsLoaded();

        return [
            'monitoring_enabled' => $this->enablePerformanceMonitoring,
            'metrics' => $this->performanceMetrics,
            'cache_efficiency' => $this->calculateCacheEfficiency(),
            'optimization_impact' => $this->calculateOptimizationImpact()
        ];
    }

    protected function calculateCacheEfficiency(): array
    {
        $hits = $this->performanceMetrics['cache_hit']['count'] ?? 0;
        $misses = $this->performanceMetrics['cache_miss']['count'] ?? 0;
        $total = $hits + $misses;

        return [
            'hit_count' => $hits,
            'miss_count' => $misses,
            'total_requests' => $total,
            'hit_ratio' => $total > 0 ? \round(($hits / $total) * 100, 2) : 0,
            'miss_ratio' => $total > 0 ? \round(($misses / $total) * 100, 2) : 0
        ];
    }

    protected function calculateOptimizationImpact(): array
    {
        $writes = $this->performanceMetrics['cache_write']['recent'] ?? [];
        $totalOriginalSize = 0;
        $totalOptimizedSize = 0;
        $optimizationCount = 0;

        foreach ($writes as $write) {
            if (isset($write['metadata']['original_size'], $write['metadata']['optimized_size'])) {
                $originalSize = $write['metadata']['original_size'];
                $optimizedSize = $write['metadata']['optimized_size'];

                $totalOriginalSize += $originalSize;
                $totalOptimizedSize += $optimizedSize;

                if ($optimizedSize < $originalSize) {
                    $optimizationCount++;
                }
            }
        }

        $writeCount = \count($writes);

        return [
            'total_writes' => $writeCount,
            'optimizations_applied' => $optimizationCount,
            'optimization_ratio' => $writeCount > 0 ? \round(($optimizationCount / $writeCount) * 100, 2) : 0,
            'size_reduction_bytes' => \max(0, $totalOriginalSize - $totalOptimizedSize),
            'size_reduction_percentage' => $totalOriginalSize > 0 ? \round((($totalOriginalSize - $totalOptimizedSize) / $totalOriginalSize) * 100, 2) : 0
        ];
    }

    /**
     * Load performance metrics from cache.
     */
    protected function loadPerformanceMetrics(): void
    {
        $this->performanceMetrics = $this->cache->get('_sc_performance_metrics', []);
    }

    /**
     * Persist performance metrics to cache.
     */
    protected function persistPerformanceMetrics(): void
    {
        if ($this->enablePerformanceMonitoring) {
            $this->cache->put('_sc_performance_metrics', $this->performanceMetrics, 3600); // 1 hour TTL
        }
    }

    /**
     * Reset performance metrics.
     */
    public function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [];
        $this->cache->forget('_sc_performance_metrics');
    }

    /**
     * Analyze cache performance and provide recommendations.
     */
    public function analyzePerformance(): array
    {
        $metrics = $this->getPerformanceMetrics();
        $recommendations = [];
        $efficiency = $metrics['cache_efficiency'];
        $optimization = $metrics['optimization_impact'];
        
        // Get warning thresholds from config
        $hitRatioThreshold = $this->config->get('smart-cache.warnings.hit_ratio_threshold', 70);
        $optimizationThreshold = $this->config->get('smart-cache.warnings.optimization_ratio_threshold', 20);
        $slowWriteThreshold = $this->config->get('smart-cache.warnings.slow_write_threshold', 0.1);
        
        // Hit ratio recommendations
        if ($efficiency['hit_ratio'] < $hitRatioThreshold) {
            $recommendations[] = [
                'type' => 'low_hit_ratio',
                'severity' => 'warning',
                'message' => "Cache hit ratio is below {$hitRatioThreshold}%. Consider increasing TTL values or reviewing cache key strategies.",
                'current_ratio' => $efficiency['hit_ratio'],
                'threshold' => $hitRatioThreshold
            ];
        }
        
        // Optimization recommendations
        if ($optimization['optimization_ratio'] < $optimizationThreshold && $optimization['total_writes'] > 10) {
            $recommendations[] = [
                'type' => 'low_optimization',
                'severity' => 'info',
                'message' => "Few cache entries are being optimized. Consider adjusting compression/chunking thresholds.",
                'current_ratio' => $optimization['optimization_ratio'],
                'threshold' => $optimizationThreshold
            ];
        }
        
        // Performance issues
        $writes = $metrics['metrics']['cache_write'] ?? [];
        if (isset($writes['average_duration']) && $writes['average_duration'] > $slowWriteThreshold) {
            $recommendations[] = [
                'type' => 'slow_writes',
                'severity' => 'warning',
                'message' => "Cache write operations are taking longer than " . round($slowWriteThreshold * 1000) . "ms on average.",
                'average_duration' => round($writes['average_duration'] * 1000, 2) . 'ms',
                'threshold' => round($slowWriteThreshold * 1000) . 'ms'
            ];
        }
        
        return [
            'analysis_timestamp' => now()->toDateTimeString(),
            'overall_health' => \count($recommendations) === 0 ? 'good' : 'needs_attention',
            'recommendations' => $recommendations,
            'metrics_summary' => [
                'cache_efficiency' => $efficiency,
                'optimization_impact' => $optimization,
                'total_operations' => \array_sum(\array_column($metrics['metrics'], 'count'))
            ]
        ];
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
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param array $values
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->put($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Remove multiple items from the cache.
     *
     * @param iterable $keys
     * @return bool
     */
    public function deleteMultiple($keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->forget($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get a memoized cache instance.
     *
     * @param string|null $store
     * @return static
     */
    public function memo(?string $store = null): static
    {
        // Get the cache repository
        $repository = $store ? $this->cacheManager->store($store) : $this->cache;

        // Wrap it with memoization
        $memoizedRepository = new \SmartCache\Drivers\MemoizedCacheDriver($repository);

        // Create a new SmartCache instance with the memoized repository
        return new static(
            $memoizedRepository,
            $this->cacheManager,
            $this->config,
            $this->strategies
        );
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