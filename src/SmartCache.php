<?php

namespace SmartCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Factory as CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\EventFake;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Drivers\MemoizedCacheDriver;
use SmartCache\Events\OptimizationApplied;
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
     * How often rememberWithLock() re-checks the cache while another process
     * holds the regeneration lock.
     */
    private const REGENERATION_LOCK_POLL_MICROSECONDS = 100000;

    /**
     * How often a metadata lock is retried while another process holds it. A
     * metadata update takes a few round trips, so Laravel's default of 250 ms
     * would mostly be spent waiting on a lock that is already free.
     */
    private const METADATA_LOCK_RETRY_MILLISECONDS = 25;

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
     * Uses a unique two-key marker to avoid collision with user data.
     */
    protected static function wrapNullValue(mixed $value): mixed
    {
        return $value === null ? ['__smartcache_null__' => true, '__sc_v' => 2] : $value;
    }

    /**
     * Unwrap a null marker back to an actual null value.
     * Supports both legacy (_sc_null) and current (__smartcache_null__) markers.
     */
    protected static function unwrapNullValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        // Current marker format (v2)
        if (\array_key_exists('__smartcache_null__', $value) && $value['__smartcache_null__'] === true
            && \array_key_exists('__sc_v', $value) && \count($value) === 2) {
            return null;
        }

        // Legacy marker format (v1) — backwards compatibility
        if (\array_key_exists('_sc_null', $value) && $value['_sc_null'] === true && \count($value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Turn a cache key into the string the store uses. Backed enums use their
     * value and pure enums their name, as Laravel's enum_value() does (which
     * Laravel 8-10 do not have).
     */
    protected static function normalizeKey(mixed $key): string
    {
        if ($key instanceof \BackedEnum) {
            return (string) $key->value;
        }

        if ($key instanceof \UnitEnum) {
            return $key->name;
        }

        return (string) $key;
    }

    /**
     * The chunk keys a stored chunked wrapper points at; [] for anything else.
     *
     * @return array<int, string>
     */
    protected static function chunkKeysOf(mixed $raw): array
    {
        if (!\is_array($raw)
            || ($raw['_sc_chunked'] ?? false) !== true
            || !isset($raw['chunk_keys'])
            || !\is_array($raw['chunk_keys'])
        ) {
            return [];
        }

        $keys = [];
        foreach ($raw['chunk_keys'] as $chunkKey) {
            if (\is_string($chunkKey) || \is_int($chunkKey)) {
                $keys[] = (string) $chunkKey;
            }
        }

        return $keys;
    }

    /**
     * @var Repository
     */
    protected Repository $cache;

    /**
     * The repository below a native Cache::memo() layer, set by memo() when
     * smart-cache.memo.native is on. SmartCache's bookkeeping and existence
     * checks read it, so they never see a value remembered earlier in the
     * request.
     */
    private ?Repository $storeRepository = null;

    /**
     * Name of the cache store this instance uses, when store() or memo()
     * selected one by name; null for the default store.
     */
    private ?string $storeName = null;

    /**
     * Strategies that only decode existing entries. The service provider adds
     * one for every strategy that is disabled, so entries it wrote before it
     * was disabled still restore instead of coming back as internal wrappers.
     *
     * @var OptimizationStrategy[]
     */
    protected array $restoreStrategies = [];

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
     * Scope of the active tags, maintained by tags().
     *
     * - tags:  the list tags() set. If $activeTags no longer equals it, for
     *          example because a subclass assigned a different list to
     *          $activeTags directly, the tags apply to the next write, as they
     *          did before 1.15.0.
     * - fresh: no tagged lookup has happened since tags(); the next write is
     *          tagged whatever its key.
     * - keys:  keys looked up under these tags. After a lookup, the tags only
     *          apply to a write of one of these keys, so a tagged miss still
     *          tags the remember()-style write that follows it, but never an
     *          unrelated write.
     * - flushable: nothing has used the tags since tags() set them, so a
     *          flush() now means tags(...)->flush(): flush only these tags.
     *          Unlike fresh, this is maintained whether or not tags are scoped.
     *
     * @var array{tags: array, fresh: bool, keys: array<string, true>, flushable: bool}|null
     */
    private ?array $activeTagsScope = null;

    /**
     * Whether active tags are scoped to the keys looked up under them
     * (smart-cache.tags.scoped, opt-in). When false, tags behave as in 1.14.
     */
    private bool $scopedTags = false;

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
     * Keys tracked and untracked since the index was last loaded or persisted.
     * persistManagedKeys() applies them to the stored index under a lock, so
     * keys another process tracked meanwhile are not lost.
     *
     * @var array<string, true>
     */
    private array $managedKeysAdded = [];

    /**
     * @var array<string, true>
     */
    private array $managedKeysRemoved = [];

    /**
     * While true, untrackKey() does not persist the index every few changes;
     * clearManaged() persists it once at the end instead.
     */
    private bool $batchingManagedKeys = false;

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
     * @var bool Whether the next put() receives an explicitly jittered TTL
     */
    private bool $skipNextConfiguredJitter = false;

    /**
     * @var CostAwareCacheManager|null Cost-aware cache manager for value scoring
     */
    protected ?CostAwareCacheManager $costAwareManager = null;

    /**
     * @var bool Whether write deduplication (Cache DNA) is enabled
     */
    protected bool $deduplicationEnabled = false;

    /**
     * @var bool Whether self-healing cache is enabled
     */
    protected bool $selfHealingEnabled = false;

    /**
     * SmartCache constructor.
     *
     * @param Repository $cache
     * @param CacheManager $cacheManager
     * @param ConfigRepository $config
     * @param array $strategies
     * @param CostAwareCacheManager|null $costAwareManager
     * @param array $restoreStrategies Decode-only strategies for disabled optimizations
     */
    public function __construct(
        Repository $cache,
        CacheManager $cacheManager,
        ConfigRepository $config,
        array $strategies = [],
        ?CostAwareCacheManager $costAwareManager = null,
        array $restoreStrategies = []
    ) {
        $this->cache = $cache;
        $this->cacheManager = $cacheManager;
        $this->config = $config;
        $this->costAwareManager = $costAwareManager;

        foreach ($strategies as $strategy) {
            $this->addStrategy($strategy);
        }

        foreach ($restoreStrategies as $strategy) {
            if ($strategy instanceof OptimizationStrategy) {
                $this->restoreStrategies[] = $strategy;
            }
        }

        // Determine cache driver
        $store = $cache->getStore();
        $this->driver = $this->determineCacheDriver($store);

        // Initialize performance monitoring flag (no cache read needed)
        $this->enablePerformanceMonitoring = $config->get('smart-cache.monitoring.enabled', true);

        // Initialize write deduplication (Cache DNA)
        $this->deduplicationEnabled = (bool) $config->get('smart-cache.deduplication.enabled', true);

        // Initialize self-healing cache
        $this->selfHealingEnabled = (bool) $config->get('smart-cache.self_healing.enabled', true);

        // Opt-in tag scoping; off keeps the 1.14 tag behaviour for existing apps
        $this->scopedTags = (bool) $config->get('smart-cache.tags.scoped', false);

        // Initialize circuit breaker from config
        $this->circuitBreakerEnabled = (bool) $config->get('smart-cache.circuit_breaker.enabled', false);

        // Initialize TTL jitter from config
        $this->jitterEnabled = (bool) $config->get('smart-cache.jitter.enabled', false);
        $this->jitterPercentage = (float) $config->get('smart-cache.jitter.percentage', 0.1);

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
     * Get all registered optimization strategies.
     *
     * @return array<string, OptimizationStrategy>
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = null): mixed
    {
        // Laravel's Repository treats an array of keys as many().
        if (\is_array($key)) {
            return $this->many($key);
        }

        $key = $this->applyNamespace(static::normalizeKey($key));
        $startTime = $this->enablePerformanceMonitoring ? microtime(true) : null;

        $sentinel = static::sentinel();
        $value = $this->cache->get($key, $sentinel);

        if ($value === $sentinel) {
            $this->recordPerformanceMetric('cache_miss', $key, $startTime);
            $this->dispatchCacheMissed($key);
            $this->bindActiveTags($key);
            return $this->resolveDefault($default);
        }

        $restoredValue = $this->maybeRestoreValue($value, $key);
        if ($restoredValue === $sentinel) {
            $this->recordPerformanceMetric('cache_miss', $key, $startTime);
            $this->dispatchCacheMissed($key);
            $this->bindActiveTags($key);
            return $this->resolveDefault($default);
        }

        $this->recordPerformanceMetric('cache_hit', $key, $startTime);
        $this->dispatchCacheHit($key, $restoredValue);

        // Track access frequency for adaptive compression
        $this->trackAccessFrequency($key);

        // Reset active tags to prevent leaking into next operation
        $this->clearActiveTags();

        return $restoredValue;
    }

    /**
     * Resolve a default value the way Laravel's Repository does: a Closure
     * default is called on a miss instead of being returned as-is.
     *
     * Cache calls inside the Closure must not take or clear the caller's tags,
     * which belong to the key being looked up.
     */
    private function resolveDefault(mixed $default): mixed
    {
        if (!$default instanceof \Closure) {
            return $default;
        }

        $tags = $this->activeTags;
        $scope = $this->activeTagsScope;
        $this->clearActiveTags();

        try {
            return $default();
        } finally {
            $this->activeTags = $tags;
            $this->activeTagsScope = $scope;
        }
    }

    /**
     * Determine if an item doesn't exist in the cache.
     *
     * @param \UnitEnum|string $key
     * @return bool
     */
    public function missing($key): bool
    {
        return !$this->has($key);
    }

    /**
     * Retrieve a string item from the cache, like Laravel's Repository::string().
     *
     * @param \UnitEnum|string $key
     * @param mixed $default
     * @return string
     * @throws \InvalidArgumentException When the cached value is not a string
     */
    public function string($key, $default = null): string
    {
        $value = $this->get($key, $default);

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(
                \sprintf('Cache value for key [%s] must be a string, %s given.', static::normalizeKey($key), \gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an integer item from the cache, like Laravel's Repository::integer().
     *
     * @param \UnitEnum|string $key
     * @param mixed $default
     * @return int
     * @throws \InvalidArgumentException When the cached value is not an integer
     */
    public function integer($key, $default = null): int
    {
        $value = $this->get($key, $default);

        if (\is_int($value)) {
            return $value;
        }

        if (\filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(
            \sprintf('Cache value for key [%s] must be an integer, %s given.', static::normalizeKey($key), \gettype($value))
        );
    }

    /**
     * Retrieve a float item from the cache, like Laravel's Repository::float().
     *
     * @param \UnitEnum|string $key
     * @param mixed $default
     * @return float
     * @throws \InvalidArgumentException When the cached value is not a float
     */
    public function float($key, $default = null): float
    {
        $value = $this->get($key, $default);

        if (\is_float($value)) {
            return $value;
        }

        if (\filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return (float) $value;
        }

        throw new \InvalidArgumentException(
            \sprintf('Cache value for key [%s] must be a float, %s given.', static::normalizeKey($key), \gettype($value))
        );
    }

    /**
     * Retrieve a boolean item from the cache, like Laravel's Repository::boolean().
     *
     * @param \UnitEnum|string $key
     * @param mixed $default
     * @return bool
     * @throws \InvalidArgumentException When the cached value is not a boolean
     */
    public function boolean($key, $default = null): bool
    {
        $value = $this->get($key, $default);

        if (!\is_bool($value)) {
            throw new \InvalidArgumentException(
                \sprintf('Cache value for key [%s] must be a boolean, %s given.', static::normalizeKey($key), \gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an array item from the cache, like Laravel's Repository::array().
     *
     * @param \UnitEnum|string $key
     * @param mixed $default
     * @return array
     * @throws \InvalidArgumentException When the cached value is not an array
     */
    public function array($key, $default = null): array
    {
        $value = $this->get($key, $default);

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(
                \sprintf('Cache value for key [%s] must be an array, %s given.', static::normalizeKey($key), \gettype($value))
            );
        }

        return $value;
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
        // Laravel's Repository treats put(array, $ttl) as putMany().
        if (\is_array($key)) {
            return $this->putMany($key, $value);
        }

        $applyConfiguredJitter = !$this->skipNextConfiguredJitter;
        $this->skipNextConfiguredJitter = false;

        return $this->putValue($key, $value, $ttl, $applyConfiguredJitter);
    }

    /**
     * Store a value, optionally applying the globally configured TTL jitter.
     *
     * The explicit *WithJitter helpers pass an already-jittered TTL here so the
     * global setting cannot jitter it a second time.
     *
     * @param mixed $key
     * @param mixed $value
     * @param mixed $ttl
     * @param bool $applyConfiguredJitter
     * @return bool
     */
    private function putValue($key, $value, $ttl, bool $applyConfiguredJitter): bool
    {
        $key = $this->applyNamespace(static::normalizeKey($key));
        $startTime = $this->enablePerformanceMonitoring ? microtime(true) : null;

        // Apply jitter to TTL when enabled (prevents thundering herd)
        if ($applyConfiguredJitter && $this->jitterEnabled && \is_int($ttl) && $ttl > 0) {
            $ttl = $this->applyJitter($ttl);
        }

        // Take this write's tags before optimizing: an OptimizationApplied
        // listener that uses the cache must not take or clear them.
        $tags = $this->takeActiveTags($key);

        // Laravel treats a TTL of zero, a negative TTL, or a past date as a
        // delete. Writing chunks for a value that is deleted straight away would
        // only leave them behind, so delete the entry the way forget() does.
        $seconds = static::ttlSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->forgetNamespacedKey($key);
        }

        // Wrap null so the underlying store can distinguish it from a cache miss
        $storable = static::wrapNullValue($value);
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, $ttl);

        // Track metadata before writing the value. A tag/managed-key entry
        // pointing at a missing value is harmless; a value missing from the
        // metadata indexes can survive invalidation indefinitely.
        $this->trackKey($key);

        if ($tags !== []) {
            $this->associateTagsWithKey($key, $tags);
        }

        // Cache DNA — skip the write when the content is identical AND the entry
        // already on the store is guaranteed to outlive the window the caller is
        // asking for. Skipping a write whose TTL would have pushed the expiry out
        // makes the entry vanish earlier than the caller asked for, so the expiry
        // is part of the dedup decision, not just the content hash.
        //
        // A chunked value is never skipped: its chunks have just been written
        // under new keys, so the stored wrapper must be replaced to point at them.
        $newHash = null;
        $requestedExpiry = false;
        if ($this->deduplicationEnabled) {
            $newHash = $this->contentHash($optimizedValue);
            $requestedExpiry = $this->resolveDedupExpiry($ttl);
            $stored = $this->storeRepository()->get("_sc_dna:{$key}");

            if (static::chunkKeysOf($optimizedValue) === []
                && $this->canSkipDuplicateWrite($stored, $newHash, $requestedExpiry)
                && $this->storeRepository()->has($key)
            ) {
                // Content unchanged — record a deduplicated write metric and return early
                if ($this->enablePerformanceMonitoring) {
                    $this->recordPerformanceMetric('cache_write_dedup', $key, $startTime);
                }
                return true;
            }
        }

        $result = $this->storeOptimized(
            $key,
            $value,
            $optimizedValue,
            $ttl === null,
            fn (): bool => (bool) $this->cache->put($key, $optimizedValue, $ttl)
        );

        // Persist content hash for future deduplication
        if ($this->deduplicationEnabled && $result) {
            $hashTtl = \is_int($ttl) && $ttl > 0 ? $ttl : 86400;
            // 'e' stays false when the TTL shape could not be resolved, which keeps
            // canSkipDuplicateWrite() from ever skipping against this record.
            $this->storeRepository()->put("_sc_dna:{$key}", [
                'h' => $newHash,
                'e' => $requestedExpiry,
            ], $hashTtl);
        }

        if ($this->enablePerformanceMonitoring) {
            $this->recordPerformanceMetric('cache_write', $key, $startTime, [
                'original_size' => $this->calculateDataSize($value),
                'optimized_size' => $this->calculateDataSize($optimizedValue),
                'ttl' => $ttl
            ]);
        }

        $this->dispatchWrite($key, $value, \is_int($ttl) ? $ttl : null, $tags);

        return $result;
    }

    /**
     * Write an optimized value and keep chunk keys from leaking.
     *
     * Each chunked write stores its chunks under new keys (see ChunkingStrategy),
     * so a reader never mixes chunks of two versions. That leaves the chunks of
     * the entry being replaced to delete, which happens only once the new entry
     * is stored, so a concurrent reader of the old entry never loses a chunk to a
     * write that then fails. If the store rejects the new entry, its own chunks
     * are deleted instead.
     *
     * The replaced entry is only read when the new value is chunked, or when an
     * array or collection is stored without expiry: a chunked value replaced by
     * a smaller one under a TTL leaves chunks that expire on their own TTL, but
     * nothing else would ever remove chunks stored forever.
     *
     * @param string $key Namespaced key
     * @param mixed $value The value as given by the caller
     * @param mixed $optimizedValue The value about to be stored
     * @param bool $forever Whether the entry is stored without expiry
     * @param \Closure(): bool $write Performs the store write
     * @return bool
     */
    private function storeOptimized(string $key, mixed $value, mixed $optimizedValue, bool $forever, \Closure $write): bool
    {
        $newChunks = static::chunkKeysOf($optimizedValue);
        $replacedChunks = [];

        if ($newChunks !== [] || ($forever && (\is_array($value) || $value instanceof \Traversable))) {
            $replacedChunks = static::chunkKeysOf($this->storeRepository()->get($key));
        }

        $result = $write();

        if (!$result) {
            $this->forgetChunkKeys($newChunks);

            return false;
        }

        $this->forgetChunkKeys(\array_diff($replacedChunks, $newChunks));

        if ($forever && $newChunks !== []) {
            $this->registerForeverChunks($key, $newChunks);
        } elseif ($replacedChunks !== []) {
            $this->unregisterChunks($key);
        }

        return true;
    }

    /**
     * Delete chunk keys, best-effort: a failure leaves a chunk that expires with
     * its TTL or that cleanupOrphanChunks() removes, never a broken entry.
     *
     * @param array<int, string> $chunkKeys
     * @return void
     */
    private function forgetChunkKeys(array $chunkKeys): void
    {
        foreach ($chunkKeys as $chunkKey) {
            try {
                $this->cache->forget($chunkKey);
            } catch (\Throwable $e) {
                // Best-effort cleanup.
            }
        }
    }

    /**
     * Dispatch KeyWritten with the tags the write used. They have already been
     * taken from $activeTags by then, so they are handed to the event through
     * $writeEventTags.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $seconds
     * @param array $tags
     * @return void
     */
    private function dispatchWrite(string $key, mixed $value, ?int $seconds, array $tags): void
    {
        $this->writeEventTags = $tags;

        try {
            $this->dispatchKeyWritten($key, $value, $seconds);
        } finally {
            $this->writeEventTags = null;
        }
    }

    /**
     * A TTL in seconds, the way Laravel's Repository reads it; null means the
     * entry is stored forever, and also covers TTL shapes it cannot read, which
     * are passed to the store unchanged.
     *
     * @param mixed $ttl
     * @return int|null
     */
    protected static function ttlSeconds(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (\is_int($ttl)) {
            return $ttl;
        }

        if (\is_float($ttl) || (\is_string($ttl) && \is_numeric($ttl))) {
            return (int) $ttl;
        }

        try {
            if ($ttl instanceof \DateInterval) {
                $ttl = Carbon::now()->add($ttl);
            }

            if ($ttl instanceof \DateTimeInterface) {
                return $ttl->getTimestamp() - Carbon::now()->getTimestamp();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
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
        $key = $this->applyNamespace(static::normalizeKey($key));
        $this->bindActiveTags($key);
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
        $key = $this->applyNamespace(static::normalizeKey($key));
        $this->bindActiveTags($key);

        return $this->forgetNamespacedKey($key);
    }

    /**
     * Remove an already namespaced key with its chunks and metadata.
     *
     * The entry is read from the store itself, not through memo(): chunk keys
     * remembered from an earlier version would leave the current ones behind.
     * It is deleted before its chunks, so a concurrent reader sees a miss
     * rather than an entry with missing chunks.
     *
     * @param string $key
     * @return bool
     */
    private function forgetNamespacedKey(string $key): bool
    {
        $chunkKeys = static::chunkKeysOf($this->storeRepository()->get($key));

        // Clean up SWR/stampede metadata (consistent format)
        $this->storeRepository()->forget("_sc_meta:{$key}");

        // Clean up Cache DNA hash
        if ($this->deduplicationEnabled) {
            $this->storeRepository()->forget("_sc_dna:{$key}");
        }

        // Remove from tracked keys
        $this->untrackKey($key);

        $result = (bool) $this->cache->forget($key);

        if ($chunkKeys !== []) {
            $this->forgetChunkKeys($chunkKeys);
            $this->unregisterChunks($key);
        }

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
        $key = $this->applyNamespace(static::normalizeKey($key));
        // Taken before optimizing, as in put()
        $tags = $this->takeActiveTags($key);
        $storable = static::wrapNullValue($value);
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, null);

        // Track all keys for pattern matching and invalidation
        $this->trackKey($key);

        // Handle active tags
        if ($tags !== []) {
            $this->associateTagsWithKey($key, $tags);
        }

        // forever() does not maintain a DNA record; a stale one left over from an
        // earlier put() would let the next put() skip a needed write.
        $this->invalidateDnaRecord($key);

        $result = $this->storeOptimized(
            $key,
            $value,
            $optimizedValue,
            true,
            fn (): bool => (bool) $this->cache->forever($key, $optimizedValue)
        );

        if ($result) {
            $this->dispatchWrite($key, $value, null, $tags);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function remember($key, $ttl, \Closure $callback): mixed
    {
        return $this->rememberWithWarmth($key, $ttl, $callback)[0];
    }

    /**
     * Get an item from the cache, or run the Closure and store its result, and
     * report whether the value came from the cache (Laravel 13's
     * Repository::rememberWithWarmth()).
     *
     * The TTL may be a Closure that receives the new value, as in Laravel 13.
     *
     * @param \UnitEnum|string $key
     * @param \Closure|\DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return array{0: mixed, 1: bool} The value, and true when it was cached
     */
    public function rememberWithWarmth($key, $ttl, \Closure $callback): array
    {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            // Record access for cost-aware scoring
            if ($this->costAwareManager !== null) {
                $this->costAwareManager->recordAccess($this->applyNamespace(static::normalizeKey($key)));
            }
            return [$value, true];
        }

        // Measure regeneration cost for cost-aware caching
        $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
        $value = $this->callKeepingActiveTags($callback);
        $this->put($key, $value, $ttl instanceof \Closure ? $ttl($value) : $ttl);

        // Record regeneration cost and value size
        if ($this->costAwareManager !== null && $startTime !== null) {
            $costMs = (\microtime(true) - $startTime) * 1000;
            $size = $this->calculateDataSize($value);
            $this->costAwareManager->recordCost($this->applyNamespace(static::normalizeKey($key)), $costMs, $size);
        }

        return [$value, false];
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
                $this->costAwareManager->recordAccess($this->applyNamespace(static::normalizeKey($key)));
            }
            return $value;
        }

        // Measure regeneration cost for cost-aware caching
        $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
        $value = $this->callKeepingActiveTags($callback);
        $this->forever($key, $value);

        // Record regeneration cost and value size
        if ($this->costAwareManager !== null && $startTime !== null) {
            $costMs = (\microtime(true) - $startTime) * 1000;
            $size = $this->calculateDataSize($value);
            $this->costAwareManager->recordCost($this->applyNamespace(static::normalizeKey($key)), $costMs, $size);
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
            $this->strategies,
            null,
            $this->restoreStrategies
        );

        // Preserve runtime configuration
        $instance->storeName = $name;
        $instance->jitterEnabled = $this->jitterEnabled;
        $instance->jitterPercentage = $this->jitterPercentage;
        $instance->activeNamespace = $this->activeNamespace;
        $instance->circuitBreakerEnabled = $this->circuitBreakerEnabled;
        $instance->costAwareManager = $this->costAwareManager;
        $this->moveActiveTagsTo($instance);

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
     * Set the expiration of a cached item without changing its value.
     *
     * Falls back to get-then-put on Laravel versions where the underlying
     * store does not support touch() natively.
     *
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $ttl
     * @return bool
     */
    public function touch($key, $ttl): bool
    {
        $namespacedKey = $this->applyNamespace(static::normalizeKey($key));
        $this->bindActiveTags($namespacedKey);

        // Read the entry from the store, not memo(): the chunks to extend are
        // the ones the stored entry points at.
        $sentinel = static::sentinel();
        $rawValue = $this->storeRepository()->get($namespacedKey, $sentinel);

        if ($rawValue === $sentinel) {
            return false;
        }

        // A TTL of zero or less deletes the entry, as in Laravel 13's touch().
        $seconds = static::ttlSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->forgetNamespacedKey($namespacedKey);
        }

        // Delegate to the underlying cache's touch() if available (Laravel 13+)
        if (\method_exists($this->cache, 'touch')) {
            $success = $this->cache->touch($namespacedKey, $ttl);
        } else {
            // Fallback for older Laravel versions: read the raw value and re-store it
            $success = $this->cache->put($namespacedKey, $rawValue, $ttl);
        }

        if (!$success) {
            return false;
        }

        return $this->touchRelatedRawKeys($rawValue, $namespacedKey, $ttl);
    }

    /**
     * Extend metadata and chunk keys that must live as long as the main value.
     */
    protected function touchRelatedRawKeys(mixed $rawValue, string $namespacedKey, mixed $ttl): bool
    {
        $success = $this->touchOptionalRawKey("_sc_meta:{$namespacedKey}", $ttl);

        // The DNA record stores the absolute expiry the value was written for, and
        // touch() has just moved that expiry. Re-TTLing the record would leave a
        // stale expiry behind, so drop it instead: the next put() then writes
        // through rather than skipping against outdated bookkeeping.
        $this->invalidateDnaRecord($namespacedKey);

        if (!\is_array($rawValue) || !isset($rawValue['_sc_chunked']) || $rawValue['_sc_chunked'] !== true) {
            return $success;
        }

        if (!isset($rawValue['chunk_keys']) || !\is_array($rawValue['chunk_keys'])) {
            return false;
        }

        foreach ($rawValue['chunk_keys'] as $chunkKey) {
            $success = $this->touchRequiredRawKey((string) $chunkKey, $ttl) && $success;
        }

        return $success;
    }

    /**
     * Touch a related key only when it exists.
     */
    protected function touchOptionalRawKey(string $key, mixed $ttl): bool
    {
        if (!$this->storeRepository()->has($key)) {
            return true;
        }

        return $this->touchRequiredRawKey($key, $ttl);
    }

    /**
     * Touch a related key that is required for restoring the main cache value.
     */
    protected function touchRequiredRawKey(string $key, mixed $ttl): bool
    {
        if (\method_exists($this->cache, 'touch')) {
            return (bool) $this->cache->touch($key, $ttl);
        }

        $sentinel = static::sentinel();
        $value = $this->cache->get($key, $sentinel);

        if ($value === $sentinel) {
            return false;
        }

        return (bool) $this->cache->put($key, $value, $ttl);
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
        $key = static::normalizeKey($key);
        $namespacedKey = $this->applyNamespace($key);
        $tags = $this->takeActiveTags($namespacedKey);

        // Laravel's add() refuses a TTL of zero or less.
        $seconds = static::ttlSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return false;
        }

        // Chunking writes its chunks before the atomic add() runs. They use new
        // keys on every write, so they can no longer overwrite the live entry's
        // chunks, but checking first saves writing them for nothing. Only arrays
        // and collections can be chunked, so scalar flags and idempotency
        // markers keep their single round trip. existsInStore() reads past
        // memo(), whose remembered hit may be stale.
        if ((\is_array($value) || $value instanceof \Traversable) && $this->existsInStore($key)) {
            return false;
        }

        // Laravel's memoized store has no add() of its own, so a Repository on
        // top of it falls back to a non-atomic get-then-put. Add to the store
        // itself instead; SmartCache's own memo layer is atomic already.
        $repository = $this->storeRepository !== null ? $this->storeRepository : $this->cache;

        // Use the underlying cache's atomic add() if available
        $storable = static::wrapNullValue($value);
        if (\method_exists($repository, 'add')) {
            $optimizedValue = $this->maybeOptimizeValue($storable, $namespacedKey, $ttl);
            $result = $this->storeOptimized(
                $namespacedKey,
                $value,
                $optimizedValue,
                $ttl === null,
                fn (): bool => (bool) $repository->add($namespacedKey, $optimizedValue, $ttl)
            );

            if ($result) {
                $this->trackKey($namespacedKey);
                $this->invalidateDnaRecord($namespacedKey);

                if (!empty($tags)) {
                    $this->associateTagsWithKey($namespacedKey, $tags);
                }

                $this->dispatchWrite($namespacedKey, $value, \is_int($ttl) ? $ttl : null, $tags);
            }

            return $result;
        }

        // Fallback: non-atomic approach for stores that don't support add()
        if ($this->has($key)) {
            return false;
        }

        // Hand the tags to put(), which applies them to this key.
        $this->activeTags = $tags;

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
        $key = $this->applyNamespace(static::normalizeKey($key));
        $this->bindActiveTags($key);
        $result = $this->cache->increment($key, $value);

        if ($result !== false) {
            $this->invalidateDnaRecord($key);
        }

        return $result;
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
        $key = $this->applyNamespace(static::normalizeKey($key));
        $this->bindActiveTags($key);
        $result = $this->cache->decrement($key, $value);

        if ($result !== false) {
            $this->invalidateDnaRecord($key);
        }

        return $result;
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
            $results[static::normalizeKey($key)] = $this->get($key, $default);
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
        return $this->putEach($values, $ttl);
    }

    /**
     * Clear cache entries tracked by SmartCache.
     *
     * SmartCache 1.x preserves its historical managed-only clear behavior.
     * Prefer clearManaged() in application code so the scope is explicit, or
     * use flush() when the entire underlying cache store must be cleared.
     */
    public function clear(): bool
    {
        return $this->clearManaged();
    }

    /**
     * Clear cache entries tracked by SmartCache without flushing unrelated keys.
     *
     * This method is intentionally implemented on the concrete class rather than
     * the public contract so existing third-party contract implementations remain
     * backward compatible.
     *
     * @return bool
     */
    public function clearManaged(): bool
    {
        $this->markActiveTagsUsed();
        $success = true;

        // Start from the stored index, not a copy loaded earlier, so keys other
        // processes tracked since then are cleared too.
        $this->persistManagedKeys();
        $this->loadManagedKeys();
        $this->managedKeysLoaded = true;

        // Clean up expired keys first
        $this->cleanupExpiredManagedKeys();

        // Get all tracked keys
        $keys = $this->getManagedKeys();

        $this->batchingManagedKeys = true;

        try {
            foreach ($keys as $key) {
                // Managed keys are stored fully qualified, so the active namespace
                // must not be applied a second time here.
                $success = $this->forgetStoredKey((string) $key) && $success;
            }
        } finally {
            $this->batchingManagedKeys = false;
        }

        // Remove the cleared keys from the index. A key another process tracked
        // while this ran stays listed, because its value was not cleared.
        $this->persistManagedKeys();

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
        // tags(...)->flush() clears only the entries under those tags, as
        // Laravel's Cache::tags(...)->flush() does, instead of the whole store.
        $tags = $this->flushableTags();
        if ($tags !== null) {
            $this->clearActiveTags();

            return $this->flushTags($tags);
        }

        $result = $this->cache->getStore()->flush();

        // SmartCache's memo layer sits above getStore(), so it would still
        // serve values the flush just removed.
        for ($layer = $this->cache; $layer instanceof MemoizedCacheDriver; $layer = $layer->getRepository()) {
            $layer->clearMemoization();
        }

        // Reset internal state
        $this->managedKeys = [];
        $this->managedKeysAdded = [];
        $this->managedKeysRemoved = [];
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
        $this->markActiveTagsUsed();
        $key = $this->applyNamespace($key);
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
     * Persist cost-aware metadata to cache.
     * Called by terminating callback to ensure data is saved reliably.
     *
     * @return void
     */
    public function persistCostMetadata(): void
    {
        if ($this->costAwareManager !== null) {
            $this->costAwareManager->persist();
        }
    }

    /**
     * Compute a fast content hash for write deduplication (Cache DNA).
     *
     * Uses `xxh128` (PHP 8.1+, guaranteed by the package's PHP requirement) which is
     * significantly faster than `md5` on typical payloads while producing the same
     * 32-character hex output. This is a non-cryptographic dedup check; collision
     * resistance is sufficient for distinguishing payload identity, and timing
     * comparisons here are not security-relevant because the hash is never an
     * authentication token.
     *
     * @param mixed $value
     * @return string
     */
    protected function contentHash(mixed $value): string
    {
        return \hash('xxh128', \serialize($value));
    }

    /**
     * Drop the deduplication record for a key.
     *
     * Any write path that changes a value or its expiry without going through
     * put() must call this. A stale record makes the next put() compare against
     * content or an expiry that is no longer in the store — which would skip a
     * write that is genuinely needed, serving the older value or letting the
     * entry expire early.
     *
     * @param string $namespacedKey
     * @return void
     */
    private function invalidateDnaRecord(string $namespacedKey): void
    {
        if ($this->deduplicationEnabled) {
            $this->storeRepository()->forget("_sc_dna:{$namespacedKey}");
        }
    }

    /**
     * Resolve a TTL into the absolute timestamp the caller expects the entry to
     * survive until.
     *
     * @param mixed $ttl
     * @return int|null|false Timestamp, null for "forever", or false when the
     *                        TTL shape cannot be resolved (never dedup then).
     */
    private function resolveDedupExpiry(mixed $ttl): int|null|false
    {
        if ($ttl === null) {
            return null;
        }

        if (\is_int($ttl) || \is_float($ttl)) {
            return $this->currentTimestamp() + (int) $ttl;
        }

        if ($ttl instanceof \DateInterval) {
            try {
                return Carbon::now()->add($ttl)->getTimestamp();
            } catch (\Throwable $e) {
                return false;
            }
        }

        if ($ttl instanceof \DateTimeInterface) {
            return $ttl->getTimestamp();
        }

        if (\is_numeric($ttl)) {
            return $this->currentTimestamp() + (int) $ttl;
        }

        return false;
    }

    /**
     * Current time, taken from the framework clock so it matches the expiry maths
     * the underlying store performs (and so Carbon::setTestNow() drives it).
     */
    private function currentTimestamp(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * Decide whether a write can be skipped because an identical value is already
     * stored with an expiry that covers the newly requested window.
     *
     * Legacy v1 records (a bare hash string) carry no expiry, so they always fall
     * through to a real write — that both refreshes the TTL and upgrades the
     * record to the current format.
     *
     * @param mixed $stored The persisted `_sc_dna:` record.
     * @param string $newHash Content hash of the value about to be written.
     * @param int|null|false $requestedExpiry Result of resolveDedupExpiry().
     * @return bool
     */
    private function canSkipDuplicateWrite(mixed $stored, string $newHash, int|null|false $requestedExpiry): bool
    {
        if ($requestedExpiry === false) {
            return false;
        }

        // Laravel treats a TTL of zero, a negative TTL, or a past date as a delete.
        // Any live entry trivially "covers" a past expiry, so without this guard
        // deduplication would swallow the delete and leave the value in place.
        if (\is_int($requestedExpiry) && $requestedExpiry <= $this->currentTimestamp()) {
            return false;
        }

        if (!\is_array($stored)
            || !\array_key_exists('h', $stored)
            || $stored['h'] !== $newHash
            || !\array_key_exists('e', $stored)) {
            return false;
        }

        $storedExpiry = $stored['e'];

        // Stored forever — outlives anything the caller can ask for.
        if ($storedExpiry === null) {
            return true;
        }

        // Caller wants it forever but the stored entry expires, or the stored
        // expiry is unusable: write through.
        if ($requestedExpiry === null || !\is_int($storedExpiry)) {
            return false;
        }

        return $storedExpiry >= $requestedExpiry;
    }

    /**
     * Cache a value only when the condition is met.
     *
     * If the condition callable returns false the value is still returned
     * but is NOT stored in cache, keeping stale or invalid data out of the
     * store.  Useful for business-rule filtering (e.g. don't cache empty
     * API responses or error payloads).
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback   Value generator
     * @param callable $condition  Receives the generated value; return true to cache
     * @return mixed The generated value (cached or not)
     */
    public function rememberIf(string $key, mixed $ttl, \Closure $callback, callable $condition): mixed
    {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            if ($this->costAwareManager !== null) {
                $this->costAwareManager->recordAccess($this->applyNamespace($key));
            }
            return $value;
        }

        $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
        $value = $this->callKeepingActiveTags($callback);

        if ($condition($value)) {
            $this->put($key, $value, $ttl instanceof \Closure ? $ttl($value) : $ttl);

            if ($this->costAwareManager !== null && $startTime !== null) {
                $costMs = (\microtime(true) - $startTime) * 1000;
                $size = $this->calculateDataSize($value);
                $this->costAwareManager->recordCost($this->applyNamespace($key), $costMs, $size);
            }
        }

        return $value;
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
            'namespace' => $this->activeNamespace,
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
                    $optimized = $strategy->optimize($value, $context);
                } catch (\Throwable $e) {
                    if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                        Log::warning("SmartCache optimization failed for {$key}: " . $e->getMessage());
                    }

                    if ($this->config->get('smart-cache.fallback.enabled', true)) {
                        continue;
                    }

                    throw $e;
                }

                // Sizes cost a serialize() each, so only measure them for a
                // listener, or for Event::fake() in an application's tests.
                if ($this->shouldDispatchEvent('optimization_applied')
                    && $this->optimizationAppliedIsObserved()
                ) {
                    $this->dispatchOptimizationApplied(
                        $key,
                        $strategy->getIdentifier(),
                        $this->calculateDataSize($value),
                        $this->calculateDataSize($optimized)
                    );
                }

                return $optimized;
            }
        }

        return $value;
    }

    /**
     * Whether anything will receive an OptimizationApplied event: a listener,
     * or Event::fake() in an application's tests.
     *
     * An application test that mocks the Event facade does not expect a
     * hasListeners() call; that counts as nobody listening.
     *
     * @return bool
     */
    private function optimizationAppliedIsObserved(): bool
    {
        if (Event::getFacadeRoot() instanceof EventFake) {
            return true;
        }

        try {
            // Event::spy() returns null rather than a bool.
            return (bool) Event::hasListeners(OptimizationApplied::class);
        } catch (\BadMethodCallException $e) {
            // Mockery records unexpected calls even when caught.
            if (\method_exists($e, 'dismiss')) {
                $e->dismiss();
            }

            return false;
        }
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
        return $this->restoreValue($value, $key, true);
    }

    /**
     * @param mixed $value
     * @param string $key
     * @param bool $mayReread Whether a chunked entry that fails to restore may be read again
     * @return mixed
     */
    private function restoreValue(mixed $value, string $key, bool $mayReread): mixed
    {
        $context = [
            'key' => $key,
            'driver' => $this->driver,
            'cache' => $this->cache,
            'config' => $this->config->get('smart-cache'),
        ];

        $restoredValue = $value;

        // Try each strategy to restore; decoders for disabled strategies last
        foreach ([...\array_values($this->strategies), ...$this->restoreStrategies] as $strategy) {
            try {
                $newValue = $strategy->restore($restoredValue, $context);
                if ($newValue !== $restoredValue) {
                    $restoredValue = $newValue;
                    // If a strategy was applied, we're done (assuming only one is applied at a time)
                    break;
                }
            } catch (\Throwable $e) {
                // A chunked entry whose chunks are gone may just have been replaced:
                // a writer deletes the old chunks once its new entry is stored. Read
                // the entry again and restore that rather than evicting it.
                if ($mayReread && static::chunkKeysOf($value) !== []) {
                    $sentinel = static::sentinel();
                    $current = $this->storeRepository()->get($key, $sentinel);

                    if ($current === $sentinel) {
                        return $sentinel;
                    }

                    if (static::chunkKeysOf($current) !== static::chunkKeysOf($value)) {
                        $this->forgetMemoized($key);

                        return $this->restoreValue($current, $key, false);
                    }
                }

                if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                    Log::warning("SmartCache restoration failed for {$key}: " . $e->getMessage());
                }

                // Self-Healing: evict the corrupted entry so the next remember()
                // call transparently regenerates the data instead of serving garbage.
                if ($this->selfHealingEnabled) {
                    try {
                        // If the wrapper points at chunk keys, drop them too so we do not
                        // leak orphan chunks into the underlying store.
                        $chunkKeys = static::chunkKeysOf($value);
                        if ($chunkKeys !== []) {
                            $this->forgetChunkKeys($chunkKeys);
                            $this->unregisterChunks($key);
                        }

                        $this->cache->forget($key);
                        $this->storeRepository()->forget("_sc_meta:{$key}");
                        $this->storeRepository()->forget("_sc_dna:{$key}");
                        $this->untrackKey($key);
                        Log::info("SmartCache self-healing: evicted corrupted key [{$key}]");
                    } catch (\Throwable $evictError) {
                        // Best-effort eviction
                    }
                    return static::sentinel();
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
            $this->managedKeysAdded[$key] = true;
            unset($this->managedKeysRemoved[$key]);
            $this->managedKeysDirty = true;
            $this->managedKeysChangeCount++;

            // Optional cap: drop oldest entries when the index grows past the configured limit.
            // Default (null/0) preserves the historical unlimited behaviour.
            $maxTracked = (int) $this->config->get('smart-cache.managed_keys.max_tracked', 0);
            if ($maxTracked > 0 && \count($this->managedKeys) > $maxTracked) {
                $overflow = \count($this->managedKeys) - $maxTracked;
                $this->managedKeys = \array_slice($this->managedKeys, $overflow, null, true);
            }

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
            unset($this->managedKeys[$key], $this->managedKeysAdded[$key]);
            $this->managedKeysRemoved[$key] = true;
            $this->managedKeysDirty = true;
            $this->managedKeysChangeCount++;

            // Persist periodically based on threshold
            if (!$this->batchingManagedKeys
                && $this->managedKeysChangeCount >= $this->managedKeysPersistThreshold
            ) {
                $this->persistManagedKeys();
            }
        }
    }

    /**
     * Persist managed keys to cache if dirty.
     *
     * The index is shared by every process, so the stored copy is re-read under
     * a metadata lock and only this instance's changes are applied to it. Writing
     * the in-memory copy back as a whole would drop keys other processes tracked
     * since it was loaded, and those keys would then escape clear(),
     * flushPatterns(), and model invalidation.
     *
     * @return void
     */
    public function persistManagedKeys(): void
    {
        if (!$this->managedKeysDirty) {
            return;
        }

        $added = $this->managedKeysAdded;
        $removed = $this->managedKeysRemoved;

        $this->withMetadataLock('managed_keys', function () use ($added, $removed): void {
            $merged = $this->readStoredManagedKeys();

            foreach ($added as $key => $flag) {
                $merged[$key] = true;
            }

            foreach ($removed as $key => $flag) {
                unset($merged[$key]);
            }

            $maxTracked = (int) $this->config->get('smart-cache.managed_keys.max_tracked', 0);
            if ($maxTracked > 0 && \count($merged) > $maxTracked) {
                $merged = \array_slice($merged, \count($merged) - $maxTracked, null, true);
            }

            if ($merged === []) {
                $this->storeRepository()->forget('_sc_managed_keys');
            } else {
                // Store as indexed array for backward compatibility with existing cache data
                $this->storeRepository()->forever('_sc_managed_keys', \array_keys($merged));
            }

            $this->managedKeys = $merged;
        });

        $this->managedKeysAdded = [];
        $this->managedKeysRemoved = [];
        $this->managedKeysDirty = false;
        $this->managedKeysChangeCount = 0;
    }

    /**
     * The stored managed-key index as a key => true map.
     *
     * @return array<string, true>
     */
    private function readStoredManagedKeys(): array
    {
        $keys = $this->storeRepository()->get('_sc_managed_keys', []);

        if (!\is_array($keys)) {
            return [];
        }

        $map = [];
        foreach ($keys as $key) {
            if (\is_string($key) || \is_int($key)) {
                $map[$key] = true;
            }
        }

        return $map;
    }

    /**
     * Destructor to ensure managed keys are persisted.
     */
    public function __destruct()
    {
        // Runs during shutdown, when the store connection may already be gone.
        // An exception escaping a destructor is fatal and cannot be caught by
        // the application, so persistence here is strictly best-effort.
        try {
            $this->persistManagedKeys();
        } catch (\Throwable $e) {
            // Best-effort persist on shutdown.
        }

        if ($this->chunkCleanupService !== null) {
            try {
                $this->chunkCleanupService->flush();
            } catch (\Throwable $e) {
                // Best-effort flush on shutdown.
            }
        }
    }

    /**
     * Reset per-request, in-memory state so the singleton can be safely reused
     * across requests in long-running workers (Laravel Octane, Swoole, RoadRunner).
     *
     * Persists any pending managed-keys mutations to the underlying cache before
     * clearing the local buffers, then drops active tags, namespace, lazy-load
     * flags, and accumulated performance metrics.  This does **not** clear
     * cached values themselves — only the per-request state held in memory.
     *
     * Recommended Octane wiring:
     *
     * ```php
     * use Laravel\Octane\Events\RequestReceived;
     * use SmartCache\Contracts\SmartCache;
     *
     * Event::listen(RequestReceived::class, function ($event) {
     *     $event->sandbox->make(SmartCache::class)->reset();
     * });
     * ```
     *
     * @return void
     */
    public function reset(): void
    {
        // Flush any dirty in-memory state to the store before discarding it.
        try {
            $this->persistManagedKeys();
            if ($this->performanceMetricsLoaded && !empty($this->performanceMetrics)) {
                $this->persistPerformanceMetrics();
            }
            if ($this->chunkCleanupService !== null) {
                $this->chunkCleanupService->flush();
            }
        } catch (\Throwable $e) {
            // Best-effort persist; never let a reset() call break the request lifecycle.
        }

        $this->clearActiveTags();
        $this->activeNamespace = null;
        $this->forgetLoadedState();
    }

    /**
     * Persist pending bookkeeping and drop what was loaded from the store, so
     * the next job in a long-running queue worker starts from the current
     * managed-key index, dependency map, and metrics instead of copies loaded
     * by an earlier job. Pending tags are dropped too.
     *
     * Unlike reset(), the active namespace and runtime settings are kept: a
     * worker may set them once, when it boots. The service provider calls this
     * before each job a queue worker runs.
     *
     * @return void
     */
    public function refreshState(): void
    {
        try {
            $this->persistManagedKeys();
            if ($this->performanceMetricsLoaded && !empty($this->performanceMetrics)) {
                $this->persistPerformanceMetrics();
            }
            if ($this->chunkCleanupService !== null) {
                $this->chunkCleanupService->flush();
            }
        } catch (\Throwable $e) {
            // Best-effort persist; never let this break the worker loop.
        }

        $this->clearActiveTags();
        $this->forgetLoadedState();
    }

    /**
     * @return void
     */
    private function forgetLoadedState(): void
    {
        $this->dependencies = [];
        $this->managedKeys = [];
        $this->managedKeysAdded = [];
        $this->managedKeysRemoved = [];
        $this->performanceMetrics = [];

        $this->managedKeysLoaded = false;
        $this->dependenciesLoaded = false;
        $this->performanceMetricsLoaded = false;
        $this->managedKeysDirty = false;
        $this->managedKeysChangeCount = 0;
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
        // Converted from the stored indexed array to a map for O(1) lookups
        $this->managedKeys = $this->readStoredManagedKeys();
        $this->managedKeysAdded = [];
        $this->managedKeysRemoved = [];
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

        foreach (\array_keys($this->managedKeys) as $key) {
            // Managed keys are already fully qualified. Going through has() would
            // re-apply the active namespace and mark every live key as expired,
            // silently destroying the index. The store is asked directly, as a
            // memo() layer may still remember a value that has since expired.
            if (!$this->storeRepository()->has($key)) {
                unset($this->managedKeys[$key], $this->managedKeysAdded[$key]);
                $this->managedKeysRemoved[$key] = true;
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->managedKeysDirty = true;
            $this->persistManagedKeys();
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
     * Returns Laravel's driver name (RedisStore => redis, DynamoDbStore =>
     * dynamodb), which is how the smart-cache.drivers config is keyed. Earlier
     * releases produced "redis_", so that config never applied.
     *
     * @param mixed $store
     * @return string|null
     */
    protected function determineCacheDriver($store): ?string
    {
        $class = \get_class($store);
        $parts = \explode('\\', $class);
        $storeName = \end($parts);

        return \strtolower((string) \preg_replace('/Store$/', '', $storeName));
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
        $namespacedKey = $this->applyNamespace($key);
        // With scoped tags, active tags tag this key whenever it is regenerated
        // and are used up here so they cannot tag the next unrelated write.
        // Otherwise flexible() leaves them alone, as in 1.14.
        $this->markActiveTagsUsed();
        $tags = $this->scopedTags ? $this->takeActiveTags($namespacedKey) : [];
        [$freshTtl, $staleTtl] = static::flexibleDurations($durations);
        $totalTtl = $staleTtl;

        // Get cached value with timestamp using sentinel for null support
        $metaKey = "_sc_meta:{$namespacedKey}";
        $sentinel = static::sentinel();
        $cachedValue = $this->cache->get($namespacedKey, $sentinel);
        $cachedMeta = $this->storeRepository()->get($metaKey);

        if ($cachedValue !== $sentinel && $cachedMeta !== null) {
            $age = time() - ($cachedMeta['stored_at'] ?? $cachedMeta['created_at'] ?? time());

            // If data is fresh, return it
            if ($age <= $freshTtl) {
                $restoredValue = $this->maybeRestoreValue($cachedValue, $namespacedKey);

                if ($restoredValue !== $sentinel) {
                    return $restoredValue;
                }
            }

            // If data is stale but within stale period, refresh synchronously and return stale
            if ($age <= $totalTtl) {
                // Capture the stale value before refreshing it
                $staleValue = $this->maybeRestoreValue($cachedValue, $namespacedKey);

                if ($staleValue === $sentinel) {
                    $this->tagRegeneratedKey($namespacedKey, $tags);
                    return $this->generateAndCache($namespacedKey, $durations, $callback);
                }

                // A lazily loaded value reads its chunks later, and the refresh
                // below deletes them once the new value is stored. Load it now.
                if ($staleValue instanceof LazyChunkedCollection) {
                    $staleValue = $staleValue->materialize();
                }

                // Historical 1.x behavior: refresh in-process before returning stale
                $this->tagRegeneratedKey($namespacedKey, $tags);
                $this->refreshInBackground($namespacedKey, $durations, $callback);

                return $staleValue;
            }
        }

        // No cache or expired beyond stale period - generate fresh data
        $this->tagRegeneratedKey($namespacedKey, $tags);
        return $this->generateAndCache($namespacedKey, $durations, $callback);
    }

    /**
     * Tag a key that flexible() is about to (re)generate. Like put(), the tag
     * index is written before the value.
     *
     * @param string $namespacedKey
     * @param array $tags
     * @return void
     */
    private function tagRegeneratedKey(string $namespacedKey, array $tags): void
    {
        if ($tags !== []) {
            $this->associateTagsWithKey($namespacedKey, $tags);
        }
    }

    /**
     * Generate fresh data and cache it with metadata.
     */
    protected function generateAndCache(string $key, array $durations, \Closure $callback): mixed
    {
        $value = $callback();
        [$freshTtl, $staleTtl] = static::flexibleDurations($durations);
        $totalTtl = $staleTtl;

        // Wrap null so the store can distinguish it from a cache miss
        $storable = static::wrapNullValue($value);
        // Optimize the value
        $optimizedValue = $this->maybeOptimizeValue($storable, $key, $totalTtl);

        // Track all keys for pattern matching and invalidation
        $this->trackKey($key);

        // Store data and metadata with consistent meta key format
        $metaKey = "_sc_meta:{$key}";
        // This writes straight to the store, bypassing put()'s DNA bookkeeping.
        $this->invalidateDnaRecord($key);
        $stored = $this->storeOptimized(
            $key,
            $value,
            $optimizedValue,
            false,
            fn (): bool => (bool) $this->cache->put($key, $optimizedValue, $totalTtl)
        );
        $this->storeRepository()->put($metaKey, ['stored_at' => time(), 'created_at' => time(), 'fresh_ttl' => $freshTtl], $totalTtl);

        if ($stored) {
            $this->dispatchWrite($key, $value, $totalTtl, []);
        }

        return $value;
    }

    /**
     * flexible()'s [fresh, stale] durations in seconds. Like Laravel, each may
     * be an int, a DateInterval, or a DateTimeInterface.
     *
     * @param array $durations
     * @return array{0: int, 1: int}
     */
    protected static function flexibleDurations(array $durations): array
    {
        $fresh = static::ttlSeconds($durations[0] ?? 3600) ?? 3600;  // Default 1 hour fresh
        $stale = static::ttlSeconds($durations[1] ?? 7200) ?? 7200;  // Default 2 hours stale (absolute time)

        return [$fresh, $stale];
    }

    /**
     * Refresh cache synchronously while retaining stale-on-error behavior.
     *
     * When `smart-cache.swr.single_flight` is enabled and the underlying
     * cache store implements `LockProvider`, only one process performs
     * the synchronous in-process refresh for a given key at a time.  Other
     * concurrent callers continue to serve the stale value without piling
     * on the upstream source.  Falls back to the historical behaviour when
     * locks are unavailable.
     */
    protected function refreshInBackground(string $key, array $durations, \Closure $callback): void
    {
        $singleFlight = (bool) $this->config->get('smart-cache.swr.single_flight', false);
        $lock = null;

        if ($singleFlight) {
            $store = $this->cache->getStore();
            if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
                $lockTtl = (int) $this->config->get('smart-cache.swr.lock_ttl', 30);
                try {
                    $lock = $store->lock("_sc_swr_refresh:{$key}", max(1, $lockTtl));
                    if (!$lock->get()) {
                        // Another process is already refreshing this key — keep serving stale.
                        return;
                    }
                } catch (\Throwable $lockError) {
                    // Lock acquisition failed — fall through to the legacy behaviour.
                    $lock = null;
                }
            }
        }

        try {
            $this->generateAndCache($key, $durations, $callback);
        } catch (\Throwable $e) {
            // Background refresh failed - log but don't interrupt main flow
            if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                Log::warning("SmartCache background refresh failed for {$key}: " . $e->getMessage());
            }
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable $releaseError) {
                    // Best-effort release; lock will expire on its TTL.
                }
            }
        }
    }

    /**
     * Stale-While-Revalidate (SWR) caching pattern.
     *
     * Returns fresh data immediately, or refreshes synchronously before returning stale data.
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
     * Serves stale data for an extended period while attempting a synchronous refresh.
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
        // The queue worker has no active namespace, so the job carries it along.
        $this->markActiveTagsUsed();
        $tags = $this->scopedTags ? $this->takeActiveTags($this->applyNamespace($key)) : $this->activeTags;
        $job = new BackgroundCacheRefreshJob($key, $callback, $ttl, $tags, $this->activeNamespace);

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
            $metadata = $this->storeRepository()->get("_sc_meta:{$namespacedKey}");
            if ($metadata && isset($metadata['created_at'])) {
                $age = time() - $metadata['created_at'];
                if ($age > $ttl) {
                    // Restart the freshness window first so the requests that arrive
                    // before the job finishes keep serving stale instead of each
                    // queueing another refresh job.
                    $this->storeRepository()->put("_sc_meta:{$namespacedKey}", ['created_at' => time(), 'stored_at' => time()], $staleTtl);

                    // Value is stale, queue background refresh
                    try {
                        $this->refreshAsync($key, $callback, $staleTtl, $queue);
                    } catch (\Throwable $e) {
                        // Nothing was queued: put the stale timestamp back so the
                        // next request retries instead of waiting out $ttl.
                        $this->storeRepository()->put("_sc_meta:{$namespacedKey}", $metadata, $staleTtl);

                        throw $e;
                    }
                }
            }
            return $value;
        }

        // No cached value, we need to compute it synchronously
        $freshValue = $this->callKeepingActiveTags(
            fn () => \is_callable($callback) ? $callback() : $this->resolveCallback($callback)
        );
        $this->put($key, $freshValue, $staleTtl);
        $namespacedKey = $this->applyNamespace((string) $key);
        $this->storeRepository()->put("_sc_meta:{$namespacedKey}", ['created_at' => time(), 'stored_at' => time()], $staleTtl);

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
        // Like Laravel, tags('a', 'b') is the same as tags(['a', 'b']).
        $tags = \is_array($tags) ? $tags : \func_get_args();
        $tags = \array_map(static fn ($tag) => static::normalizeKey($tag), \array_values($tags));

        // Calling tags() again with the same list keeps the keys already looked
        // up, so "look up several keys, then write the misses" stays tagged.
        $keys = $this->activeTagsScope !== null && $this->activeTagsScope['tags'] === $tags
            ? $this->activeTagsScope['keys']
            : [];

        $this->activeTags = $tags;
        $this->activeTagsScope = ['tags' => $tags, 'fresh' => true, 'keys' => $keys, 'flushable' => true];

        return $this;
    }

    /**
     * SmartCache supports tags on every store: it keeps its own tag index
     * instead of relying on the store's tag support, which Laravel's file,
     * database, and DynamoDB stores lack.
     *
     * @return bool
     */
    public function supportsTags(): bool
    {
        return true;
    }

    /**
     * Tags a flush() right now would apply to: the list tags() just set, if no
     * cache operation has used it since. Null means flush() clears the store.
     *
     * @return array|null
     */
    private function flushableTags(): ?array
    {
        $scope = $this->activeTagsScope;

        if ($scope === null
            || !($scope['flushable'] ?? false)
            || $this->activeTags === []
            || $scope['tags'] !== $this->activeTags
        ) {
            return null;
        }

        return $this->activeTags;
    }

    /**
     * Record that a cache operation has run since tags(), so a later flush()
     * clears the whole store again rather than only these tags. The tags
     * themselves are untouched.
     *
     * @return void
     */
    private function markActiveTagsUsed(): void
    {
        if ($this->activeTagsScope !== null) {
            $this->activeTagsScope['flushable'] = false;
        }
    }

    /**
     * Record a tagged lookup of this key, so the active tags still apply to a
     * later write of it (the remember() pattern), but not to writes of other keys.
     *
     * @param string $namespacedKey
     * @return void
     */
    private function bindActiveTags(string $namespacedKey): void
    {
        $this->markActiveTagsUsed();

        if (!$this->scopedTags
            || $this->activeTags === []
            || $this->activeTagsScope === null
            || $this->activeTagsScope['tags'] !== $this->activeTags
        ) {
            return;
        }

        $this->activeTagsScope['fresh'] = false;
        $this->activeTagsScope['keys'][$namespacedKey] = true;
    }

    /**
     * Use up the active tags for a write of this key.
     *
     * @param string $namespacedKey
     * @return array Tags to associate with the key; empty when none apply.
     */
    private function takeActiveTags(string $namespacedKey): array
    {
        $tags = $this->activeTags;
        $scope = $this->activeTagsScope;
        $this->clearActiveTags();

        // Without scoping, and for tags that tags() did not set, the next write
        // takes the tags whatever its key, as in 1.14.
        if (!$this->scopedTags || $scope === null || $scope['tags'] !== $tags) {
            return $tags;
        }

        return $scope['fresh'] || isset($scope['keys'][$namespacedKey]) ? $tags : [];
    }

    /**
     * @return void
     */
    private function clearActiveTags(): void
    {
        $this->activeTags = [];
        $this->activeTagsScope = null;
    }

    /**
     * Run a value callback so that cache calls inside it cannot use up the
     * active tags, which belong to the key the value is written to (scoped
     * tags only; otherwise tags behave as in 1.14).
     *
     * @param \Closure $callback
     * @return mixed
     */
    private function callKeepingActiveTags(\Closure $callback): mixed
    {
        if (!$this->scopedTags) {
            return $callback();
        }

        $tags = $this->activeTags;
        $scope = $this->activeTagsScope;

        try {
            return $callback();
        } finally {
            $this->activeTags = $tags;
            $this->activeTagsScope = $scope;
        }
    }

    /**
     * Hand the active tags to an instance created by store() or memo(), which
     * is where the caller's next write goes (scoped tags only).
     *
     * @param SmartCache $instance
     * @return void
     */
    private function moveActiveTagsTo(SmartCache $instance): void
    {
        // Only tags set right before store()/memo() belong to the new instance.
        // After a lookup they belong to that lookup's key, for example inside a
        // tagged remember() callback.
        if (!$this->scopedTags || !($this->activeTagsScope['fresh'] ?? false)) {
            // The tags stay here for the next write, as in 1.14, but a later
            // flush() on this instance is no longer tags(...)->flush().
            $this->markActiveTagsUsed();

            return;
        }

        $instance->activeTags = $this->activeTags;
        $instance->activeTagsScope = $this->activeTagsScope;
        $this->clearActiveTags();
    }

    /**
     * {@inheritdoc}
     */
    public function flushTags(string|array $tags, string $source = 'manual'): bool
    {
        $this->markActiveTagsUsed();
        $tags = \is_array($tags) ? $tags : [$tags];

        foreach ($tags as $tag) {
            $tag = static::normalizeKey($tag);

            // Read the raw index rather than getKeysForTag(): pruning and
            // re-persisting the index is wasted work when the tag entry is about
            // to be deleted below.
            $tagKeys = $this->readTagKeys($tag);
            $flushed = 0;

            foreach ($tagKeys as $key) {
                // Use forget method which handles both regular and optimized cache cleanup.
                // We don't care about the return value - if the key doesn't exist, that's fine.
                if ($this->storeRepository()->has($key)) {
                    $flushed++;
                }
                $this->forgetStoredKey((string) $key);
            }

            // Remove the flushed keys from the tag rather than deleting the whole
            // entry, so a key another process tagged meanwhile stays tagged.
            $this->mutateTagKeys($tag, static fn (array $keys): array => \array_values(\array_diff($keys, $tagKeys)));
            $this->dispatchTagFlushed($tag, $flushed, $source);
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
        $this->markActiveTagsUsed();
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
        $this->markActiveTagsUsed();
        $dependencies = \is_array($dependencies) ? $dependencies : [$dependencies];

        $this->mutateDependencies(function (array $map) use ($key, $dependencies): array {
            $existing = $map[$key] ?? [];
            $map[$key] = \array_values(\array_unique([...$existing, ...$dependencies]));

            return $map;
        });

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate(string $key): bool
    {
        $this->loadDependencies();
        $this->dependenciesLoaded = true;

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
        $this->mutateDependencies(function (array $map) use ($key): array {
            unset($map[$key]);

            return $map;
        });

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
            $tag = (string) $tag;

            if ($tag === '') {
                continue;
            }

            $this->mutateTagKeys($tag, function (array $taggedKeys) use ($key): array {
                if (!\in_array($key, $taggedKeys, true)) {
                    $taggedKeys[] = $key;
                }

                return $taggedKeys;
            });
        }

        $this->clearActiveTags();
    }

    /**
     * Get the live keys associated with a tag, pruning stale references.
     *
     * Reads the tag index, drops entries whose values no longer exist, and
     * rewrites the index when anything was pruned so long-lived tags do not
     * accumulate dead references indefinitely.
     *
     * @param string $tag
     * @return array
     */
    protected function getKeysForTag(string $tag): array
    {
        $keys = $this->readTagKeys($tag);
        $liveKeys = [];

        foreach ($keys as $key) {
            if ($this->storeRepository()->has($key)) {
                $liveKeys[] = $key;
            }
        }

        if (\count($liveKeys) !== \count($keys)) {
            $this->persistTagKeys($tag, $liveKeys);
        }

        return $liveKeys;
    }

    protected function readTagKeys(string $tag): array
    {
        $tagKey = "_sc_tag_{$tag}";
        $keys = $this->storeRepository()->get($tagKey, []);

        return \is_array($keys) ? \array_values(\array_unique($keys)) : [];
    }

    protected function persistTagKeys(string $tag, array $keys): void
    {
        $tagKey = "_sc_tag_{$tag}";
        $keys = \array_values(\array_unique($keys));

        if ($keys === []) {
            $this->storeRepository()->forget($tagKey);
            return;
        }

        $this->storeRepository()->forever($tagKey, $keys);
    }

    protected function mutateTagKeys(string $tag, callable $callback): void
    {
        $this->withMetadataLock("tag:{$tag}", function () use ($tag, $callback): void {
            $keys = $this->readTagKeys($tag);
            $this->persistTagKeys($tag, $callback($keys));
        });
    }

    protected function withMetadataLock(string $scope, callable $callback): mixed
    {
        if (!$this->config->get('smart-cache.metadata_lock.enabled', true)) {
            return $callback();
        }

        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            return $callback();
        }

        $ttl = \max(1, (int) $this->config->get('smart-cache.metadata_lock.ttl', 5));
        $wait = \max(0, (int) $this->config->get('smart-cache.metadata_lock.wait', 1));
        $lockName = '_sc_meta_lock:' . \sha1($scope);

        try {
            $lock = $store->lock($lockName, $ttl);

            if (\method_exists($lock, 'betweenBlockedAttemptsSleepFor')) {
                $lock->betweenBlockedAttemptsSleepFor(self::METADATA_LOCK_RETRY_MILLISECONDS);
            }

            if ($wait > 0 && \method_exists($lock, 'block')) {
                return $lock->block($wait, $callback);
            }

            $ran = false;
            $result = $lock->get(function () use ($callback, &$ran): mixed {
                $ran = true;
                return $callback();
            });

            if ($ran) {
                return $result;
            }
        } catch (\Throwable $e) {
            // Best-effort locking: unsupported or unavailable locks should not
            // make cache writes fail.
        }

        return $callback();
    }

    protected function forgetStoredKey(string $key): bool
    {
        $savedNamespace = $this->activeNamespace;
        $this->activeNamespace = null;

        try {
            return $this->forget($key);
        } finally {
            $this->activeNamespace = $savedNamespace;
        }
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
        $dependencies = $this->storeRepository()->get('_sc_dependencies', []);
        $this->dependencies = \is_array($dependencies) ? $dependencies : [];
    }

    /**
     * Save dependencies to cache.
     *
     * @return void
     */
    protected function saveDependencies(): void
    {
        $this->mutateDependencies(fn (array $map): array => $map);
    }

    /**
     * Apply a mutation to the dependency map under a metadata lock.
     *
     * The stored map is re-read inside the lock before the callback runs so
     * concurrent dependency changes from other processes are not lost. The
     * in-memory copy is kept in sync with what is persisted.
     *
     * @param callable $callback Receives the current map, returns the new map.
     * @return void
     */
    protected function mutateDependencies(callable $callback): void
    {
        $this->withMetadataLock('dependencies', function () use ($callback): void {
            $stored = $this->storeRepository()->get('_sc_dependencies', []);
            $stored = \is_array($stored) ? $stored : [];

            $updated = $callback($stored);
            $this->dependencies = $updated;
            $this->dependenciesLoaded = true;

            if ($updated === []) {
                $this->storeRepository()->forget('_sc_dependencies');
                return;
            }

            $this->storeRepository()->forever('_sc_dependencies', $updated);
        });
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
        $this->markActiveTagsUsed();
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
        $this->markActiveTagsUsed();
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
            $persistEvery = (int) $this->config->get('smart-cache.chunk_registry.persist_every', 1);
            $this->chunkCleanupService = new OrphanChunkCleanupService($this->storeRepository(), $persistEvery);
        }

        return $this->chunkCleanupService;
    }

    /**
     * Clean up orphan cache chunks.
     *
     * The registry lists the chunks of values stored without expiry, the only
     * chunks that would otherwise stay in the store forever once their entry is
     * gone (evicted, deleted outside SmartCache, or replaced by a concurrent
     * write). Chunks of values with a TTL expire with them.
     *
     * @return array Statistics about the cleanup
     */
    public function cleanupOrphanChunks(): array
    {
        return $this->withMetadataLock(
            'chunk_registry',
            fn (): array => $this->chunkCleanupService()->cleanupOrphanChunks()
        );
    }

    /**
     * Record the chunks of a value stored without expiry in the orphan-chunk
     * registry, replacing any earlier entry for the key.
     *
     * @param string $key
     * @param array<int, string> $chunkKeys
     * @return void
     */
    private function registerForeverChunks(string $key, array $chunkKeys): void
    {
        $this->mutateChunkRegistry(static function (array $registry) use ($key, $chunkKeys): array {
            $registry[$key] = ['chunk_keys' => $chunkKeys, 'registered_at' => \time()];

            return $registry;
        });
    }

    /**
     * Drop a key from the orphan-chunk registry once its chunks are deleted.
     *
     * @param string $key
     * @return void
     */
    private function unregisterChunks(string $key): void
    {
        // Most chunked values carry a TTL and were never registered, so look
        // before taking the lock.
        $registry = $this->storeRepository()->get(OrphanChunkCleanupService::REGISTRY_KEY);
        if (!\is_array($registry) || !\array_key_exists($key, $registry)) {
            return;
        }

        $this->mutateChunkRegistry(static function (array $registry) use ($key): array {
            unset($registry[$key]);

            return $registry;
        });
    }

    /**
     * Apply a change to the stored orphan-chunk registry under a metadata lock,
     * re-reading it first so changes from other processes are kept.
     *
     * @param callable(array): array $callback
     * @return void
     */
    private function mutateChunkRegistry(callable $callback): void
    {
        try {
            $this->withMetadataLock('chunk_registry', function () use ($callback): void {
                $stored = $this->storeRepository()->get(OrphanChunkCleanupService::REGISTRY_KEY, []);
                $registry = $callback(\is_array($stored) ? $stored : []);

                if ($registry === []) {
                    $this->storeRepository()->forget(OrphanChunkCleanupService::REGISTRY_KEY);
                } else {
                    $this->storeRepository()->forever(OrphanChunkCleanupService::REGISTRY_KEY, $registry);
                }
            });
        } catch (\Throwable $e) {
            // The registry is a safety net for cleanupOrphanChunks(); failing to
            // update it must not fail the write that triggered it.
            if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                Log::warning('SmartCache could not update the chunk registry: ' . $e->getMessage());
            }
        }
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

            // Opt-in cross-process state sharing through the cache.
            if ($this->config->get('smart-cache.circuit_breaker.shared', false)) {
                $sharedKey = '_sc_circuit_breaker:' . ($this->driver ?? 'default');
                $sharedTtl = (int) $this->config->get('smart-cache.circuit_breaker.shared_ttl', 300);
                $this->circuitBreaker->enableSharedState($this->storeRepository(), $sharedKey, $sharedTtl);
            }
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
                $this->storeRepository(),
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
        $namespacedKey = $this->applyNamespace($key);

        if ($value !== $sentinel) {
            $metadata = $this->storeRepository()->get("_sc_meta:{$namespacedKey}");
            if (\is_array($metadata) && isset($metadata['created_at'])) {
                // How long the last regeneration took; entries written before
                // 1.16 did not record it.
                $delta = isset($metadata['delta']) && \is_numeric($metadata['delta']) ? (float) $metadata['delta'] : null;

                if ($this->rateLimiter()->shouldRefreshProbabilistically($ttl, (int) $metadata['created_at'], $beta, $delta)) {
                    // One early refresh per key at a time. The window only has to
                    // cover the regeneration: a TTL-long window spent on one early
                    // refresh would block the next one, right before expiry.
                    $window = \max(1, \min($ttl, (int) \ceil(($delta ?? 1.0) * 2)));

                    if ($this->rateLimiter()->attempt("refresh:{$namespacedKey}", 1, $window)) {
                        $value = $this->regenerateForStampedeProtection($key, $namespacedKey, $ttl, $callback);
                    }
                }
            }
            return $value;
        }

        // No cached value, compute and store
        return $this->regenerateForStampedeProtection($key, $namespacedKey, $ttl, $callback);
    }

    /**
     * Run the callback, store its value, and record when it was stored and how
     * long it took, which the next early-refresh decision uses.
     *
     * @param string $key
     * @param string $namespacedKey
     * @param int $ttl
     * @param \Closure $callback
     * @return mixed
     */
    private function regenerateForStampedeProtection(string $key, string $namespacedKey, int $ttl, \Closure $callback): mixed
    {
        $startedAt = \microtime(true);
        $value = $this->callKeepingActiveTags($callback);
        $delta = \microtime(true) - $startedAt;

        $this->put($key, $value, $ttl);
        $this->storeRepository()->put(
            "_sc_meta:{$namespacedKey}",
            ['created_at' => \time(), 'stored_at' => \time(), 'delta' => \round($delta, 6)],
            $ttl
        );

        return $value;
    }

    /**
     * Remember a value, letting only one process regenerate it on a cache miss.
     *
     * On a miss the callback runs under an atomic lock, so a cold or freshly
     * invalidated key is rebuilt once instead of by every concurrent request.
     * Processes that arrive while the lock is held wait up to $waitSeconds and
     * then read the value the lock holder stored. Cache hits never touch the lock.
     *
     * When the store does not support locks, the lock backend fails, or the wait
     * times out, the callback runs without the lock, so this method never fails
     * where remember() would succeed.
     *
     * @param string $key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl
     * @param \Closure $callback
     * @param int $lockSeconds Maximum seconds the lock is held; keep it above the regeneration time
     * @param int $waitSeconds Seconds a waiting process blocks before regenerating without the lock
     * @return mixed
     */
    public function rememberWithLock(
        string $key,
        mixed $ttl,
        \Closure $callback,
        int $lockSeconds = 10,
        int $waitSeconds = 10
    ): mixed {
        $sentinel = static::sentinel();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            if ($this->costAwareManager !== null) {
                $this->costAwareManager->recordAccess($this->applyNamespace($key));
            }
            return $value;
        }

        $valueAppeared = false;
        $lock = $this->acquireRegenerationLock($key, $lockSeconds, $waitSeconds, $valueAppeared);

        try {
            // Another process may have stored the value while we waited, or just
            // before we took the lock. has() keeps this re-check out of the
            // hit/miss metrics.
            if ($valueAppeared || $this->existsInStore($key)) {
                // Laravel's memo layer still remembers the miss from above.
                $value = $this->storeRepository !== null
                    ? $this->getFromStore($key, $sentinel)
                    : $this->get($key, $sentinel);

                if ($value !== $sentinel) {
                    return $value;
                }
            }

            $startTime = $this->costAwareManager !== null ? \microtime(true) : null;
            $value = $this->callKeepingActiveTags($callback);
            $this->put($key, $value, $ttl instanceof \Closure ? $ttl($value) : $ttl);

            if ($this->costAwareManager !== null && $startTime !== null) {
                $costMs = (\microtime(true) - $startTime) * 1000;
                $size = $this->calculateDataSize($value);
                $this->costAwareManager->recordCost($this->applyNamespace($key), $costMs, $size);
            }

            return $value;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable $releaseError) {
                    // Best-effort release; the lock expires on its own TTL.
                }
            }
        }
    }

    /**
     * Acquire the lock that serialises regeneration of a missing key.
     *
     * While another process holds the lock, this polls the cache and stops
     * waiting as soon as the value appears, so waiters do not queue for the lock
     * just to read it.
     *
     * Returns null when the value appeared, the store has no lock support, the
     * lock backend fails (for example a database store without the cache_locks
     * table), or the wait times out. The caller then re-checks the cache and
     * regenerates without the lock only if the value is still missing.
     *
     * @param string $key
     * @param int $lockSeconds
     * @param int $waitSeconds
     * @param bool $valueAppeared Set to true when waiting ended because the value was stored
     * @return Lock|null
     */
    private function acquireRegenerationLock(string $key, int $lockSeconds, int $waitSeconds, bool &$valueAppeared): ?Lock
    {
        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            return null;
        }

        $namespacedKey = $this->applyNamespace($key);

        try {
            // Hashed so a long key cannot push the lock name past a store's key-length limit.
            $lock = $store->lock('_sc_remember_lock:' . \sha1($namespacedKey), \max(1, $lockSeconds));
            $deadline = \microtime(true) + \max(0, $waitSeconds);

            while (!$lock->get()) {
                if ($this->existsInStore($key)) {
                    $valueAppeared = true;
                    return null;
                }

                if (\microtime(true) >= $deadline) {
                    return null;
                }

                \usleep(self::REGENERATION_LOCK_POLL_MICROSECONDS);
            }

            return $lock;
        } catch (\Throwable $e) {
            if ($this->config->get('smart-cache.fallback.log_errors', true)) {
                Log::warning("SmartCache could not lock regeneration of {$namespacedKey}: " . $e->getMessage());
            }

            return null;
        }
    }

    /**
     * Whether the key exists in the store right now.
     *
     * memo() remembers hits and misses for the rest of the request, which could
     * hide a change another process made meanwhile, so that memory is dropped
     * first, in every layer when memo() instances are nested.
     *
     * @param string $key
     * @return bool
     */
    private function existsInStore(string $key): bool
    {
        $namespacedKey = $this->applyNamespace($key);

        $this->forgetMemoized($namespacedKey);

        return $this->storeRepository()->has($namespacedKey);
    }

    /**
     * Drop a key from every SmartCache memo layer, so the next read goes to the
     * store. Laravel's memoized store offers no way to do this.
     *
     * @param string $namespacedKey
     * @return void
     */
    private function forgetMemoized(string $namespacedKey): void
    {
        for ($layer = $this->cache; $layer instanceof MemoizedCacheDriver; $layer = $layer->getRepository()) {
            $layer->forgetMemoizedKey($namespacedKey);
        }
    }

    /**
     * The repository below any memo() layer.
     *
     * SmartCache's own bookkeeping (managed keys, tag and dependency indexes,
     * deduplication records, SWR metadata, metrics) and its existence checks
     * read and write here. Through memo() they would see what was read earlier
     * in the request: a deduplication record remembered from before another
     * process wrote the key makes put() skip a write it needs, and a tag index
     * remembered under a metadata lock drops other processes' entries.
     *
     * @return Repository
     */
    protected function storeRepository(): Repository
    {
        if ($this->storeRepository !== null) {
            return $this->storeRepository;
        }

        $repository = $this->cache;

        while ($repository instanceof MemoizedCacheDriver) {
            $repository = $repository->getRepository();
        }

        return $repository;
    }

    /**
     * get() reading the store itself rather than a memo() layer.
     *
     * Laravel's memoized store cannot forget a single key, so after another
     * process stores a value this instance has already missed, the store is the
     * only place to read it from.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getFromStore(string $key, mixed $default): mixed
    {
        $memoized = $this->cache;
        $this->cache = $this->storeRepository();

        try {
            return $this->get($key, $default);
        } finally {
            $this->cache = $memoized;
        }
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
        $this->skipNextConfiguredJitter = true;

        try {
            return $this->put($key, $value, $this->jitterTtl($ttl, $jitterPercentage));
        } finally {
            $this->skipNextConfiguredJitter = false;
        }
    }

    /**
     * Compute a jittered TTL for the explicit *WithJitter helpers.
     *
     * Calling these methods is itself the opt-in, so the global
     * `smart-cache.jitter.enabled` flag (off by default) must not suppress it.
     * applyJitter() keeps its flag-gated behaviour for existing callers.
     *
     * @param int $ttl
     * @param float $jitterPercentage
     * @return int
     */
    private function jitterTtl(int $ttl, float $jitterPercentage): int
    {
        if ($ttl <= 0) {
            return $ttl;
        }

        $percentage = \max(0.0, \min(1.0, $jitterPercentage));
        $jitterRange = (int) ($ttl * $percentage);

        if ($jitterRange <= 0) {
            return $ttl;
        }

        return \max(1, $ttl + \mt_rand(-$jitterRange, $jitterRange));
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
        $callbackWithPreparedTtl = function () use ($callback): mixed {
            $value = $callback();
            // Set this only after the user callback returns, so cache writes made
            // inside that callback retain their normal global jitter behavior.
            $this->skipNextConfiguredJitter = true;

            return $value;
        };

        try {
            return $this->remember(
                $key,
                $this->jitterTtl($ttl, $jitterPercentage),
                $callbackWithPreparedTtl
            );
        } finally {
            $this->skipNextConfiguredJitter = false;
        }
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
                // Managed keys are already namespaced; read the store directly.
                if (!$this->storeRepository()->has($key)) {
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

        $recentLimit = \max(1, (int) $this->config->get('smart-cache.monitoring.recent_entries_limit', 100));
        if (\count($metrics['recent']) > $recentLimit) {
            $metrics['recent'] = \array_slice($metrics['recent'], -$recentLimit);
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
        $metrics = $this->storeRepository()->get('_sc_performance_metrics', []);
        // A foreign or corrupted entry under this key must not fatal the request.
        $this->performanceMetrics = \is_array($metrics) ? $metrics : [];
    }

    /**
     * Persist performance metrics to cache.
     */
    protected function persistPerformanceMetrics(): void
    {
        if ($this->enablePerformanceMonitoring) {
            $ttl = (int) $this->config->get('smart-cache.monitoring.metrics_ttl', 3600);
            $this->storeRepository()->put('_sc_performance_metrics', $this->performanceMetrics, $ttl);
        }
    }

    /**
     * Reset performance metrics.
     */
    public function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [];
        $this->storeRepository()->forget('_sc_performance_metrics');
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

        // Like Laravel's Repository, accept a list of keys or ['key' => default].
        foreach ($keys as $key => $default) {
            if (\is_string($key)) {
                $results[$key] = $this->get($key, $default);
            } else {
                $results[static::normalizeKey($default)] = $this->get($default);
            }
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
        return $this->putEach($values, $ttl);
    }

    /**
     * put() every value, applying the active tags to each key, as Laravel's
     * tagged putMany() does. Before 1.16 the first put() used the tags up, so
     * tags('t')->putMany([...]) tagged only the first key and flushTags('t')
     * left the others in place.
     *
     * @param iterable $values
     * @param mixed $ttl
     * @return bool
     */
    private function putEach(iterable $values, mixed $ttl): bool
    {
        $tags = $this->activeTags;
        $scope = $this->activeTagsScope;
        $success = true;

        try {
            foreach ($values as $key => $value) {
                $this->activeTags = $tags;
                $this->activeTagsScope = $scope;

                if (!$this->put($key, $value, $ttl)) {
                    $success = false;
                }
            }
        } finally {
            $this->clearActiveTags();
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
     * By default every call wraps the store in a new SmartCache memo layer.
     * With smart-cache.memo.native enabled (Laravel 12.9+), the instance is
     * built on Laravel's Cache::memo() instead: one memo layer per store, shared
     * with Cache::memo() and with every other memo() call, which Laravel clears
     * between requests and queued jobs.
     *
     * @param string|null $store
     * @return static
     */
    public function memo(?string $store = null): static
    {
        $native = $this->nativeMemoRepositories($store);

        if ($native !== null) {
            [$memoizedRepository, $storeRepository] = $native;
        } else {
            // Get the cache repository
            $repository = $store ? $this->cacheManager->store($store) : $this->cache;

            // Wrap it with memoization
            $memoizedRepository = new \SmartCache\Drivers\MemoizedCacheDriver($repository);
            $storeRepository = null;
        }

        // Create a new SmartCache instance with the memoized repository
        $instance = new static(
            $memoizedRepository,
            $this->cacheManager,
            $this->config,
            $this->strategies,
            null,
            $this->restoreStrategies
        );

        if ($storeRepository !== null) {
            $instance->storeRepository = $storeRepository;
            // getStore() is Laravel's MemoizedStore here; the drivers.* settings
            // and chunk size limits belong to the store below it.
            $instance->driver = $instance->determineCacheDriver($storeRepository->getStore());
        }

        // Preserve runtime configuration, as store() does. Dropping the namespace
        // would make a tenant-scoped memo() read and write global keys.
        $instance->storeName = $store ?? $this->storeName;
        $instance->jitterEnabled = $this->jitterEnabled;
        $instance->jitterPercentage = $this->jitterPercentage;
        $instance->activeNamespace = $this->activeNamespace;
        $instance->circuitBreakerEnabled = $this->circuitBreakerEnabled;
        $instance->costAwareManager = $this->costAwareManager;
        $this->moveActiveTagsTo($instance);

        return $instance;
    }

    /**
     * The repositories for a memo() built on Laravel's Cache::memo(): the
     * memoized one, and the plain one below it. Null when native memo is off,
     * when the framework has no Cache::memo() (before Laravel 12.9), or when
     * the store this instance uses has no name the cache manager knows.
     *
     * @param string|null $store
     * @return array{0: Repository, 1: Repository}|null
     */
    private function nativeMemoRepositories(?string $store): ?array
    {
        if (!$this->config->get('smart-cache.memo.native', false)
            || !\method_exists($this->cacheManager, 'memo')
        ) {
            return null;
        }

        if ($store === null) {
            // memo() on a native memo instance uses the same memo layer.
            if ($this->storeRepository !== null) {
                return [$this->cache, $this->storeRepository];
            }

            $store = $this->storeName;

            // A repository handed to the constructor directly, or a SmartCache
            // memo layer: there is no store name to ask Cache::memo() for.
            if ($store === null && $this->cache !== $this->cacheManager->store()) {
                return null;
            }
        }

        return [$this->cacheManager->memo($store), $this->cacheManager->store($store)];
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
        $this->markActiveTagsUsed();

        return $this->cache->{$method}(...$arguments);
    }
}
