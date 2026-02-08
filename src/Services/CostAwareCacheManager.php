<?php

namespace SmartCache\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * Cost-Aware Cache Manager
 *
 * Implements a GreedyDual-inspired algorithm that scores cache entries based on:
 * - Regeneration cost: how long the callback took to compute (measured automatically)
 * - Access frequency: how often the key is read
 * - Size: how much memory the cached value consumes
 *
 * This allows SmartCache to understand the *value* of what it's caching,
 * enabling intelligent TTL optimization and eviction decisions.
 */
class CostAwareCacheManager
{
    protected Repository $cache;

    /**
     * In-memory metadata for the current request.
     *
     * @var array<string, array{cost_ms: float, access_count: int, size_bytes: int, last_accessed: int, created_at: int}>
     */
    protected array $metadata = [];

    /**
     * Whether metadata has been loaded from cache.
     */
    protected bool $loaded = false;

    /**
     * Whether metadata has been modified and needs persisting.
     */
    protected bool $dirty = false;

    /**
     * Maximum number of keys to track metadata for.
     */
    protected int $maxTrackedKeys;

    /**
     * TTL for the metadata storage itself (seconds).
     */
    protected int $metadataTtl;

    public function __construct(Repository $cache, int $maxTrackedKeys = 1000, int $metadataTtl = 86400)
    {
        $this->cache = $cache;
        $this->maxTrackedKeys = $maxTrackedKeys;
        $this->metadataTtl = $metadataTtl;
    }

    /**
     * Record the regeneration cost of a cache key.
     */
    public function recordCost(string $key, float $costMs, int $sizeBytes): void
    {
        $this->ensureLoaded();

        $this->metadata[$key] = [
            'cost_ms' => $costMs,
            'access_count' => ($this->metadata[$key]['access_count'] ?? 0) + 1,
            'size_bytes' => $sizeBytes,
            'last_accessed' => \time(),
            'created_at' => $this->metadata[$key]['created_at'] ?? \time(),
        ];

        $this->dirty = true;
        $this->trimIfNeeded();
    }

    /**
     * Record an access (read) for a cache key.
     */
    public function recordAccess(string $key): void
    {
        $this->ensureLoaded();

        if (!isset($this->metadata[$key])) {
            return;
        }

        $this->metadata[$key]['access_count']++;
        $this->metadata[$key]['last_accessed'] = \time();
        $this->dirty = true;
    }

    /**
     * Calculate the value score for a cache key.
     *
     * Score = (regeneration_cost * ln(1 + access_count)) / size_bytes
     *
     * Higher scores mean the key is more valuable to keep cached.
     */
    public function getScore(string $key): float
    {
        $this->ensureLoaded();

        if (!isset($this->metadata[$key])) {
            return 0.0;
        }

        $meta = $this->metadata[$key];
        $cost = \max(0.001, $meta['cost_ms']);
        $frequency = \log(1 + $meta['access_count']);
        $size = \max(1, $meta['size_bytes']);

        // Apply time decay: reduce score for keys not accessed recently
        $age = \time() - $meta['last_accessed'];
        $decay = \exp(-$age / 86400); // Half-life of ~1 day

        return ($cost * $frequency * $decay) / $size;
    }

    /**
     * Get metadata for a specific cache key.
     *
     * @return array{cost_ms: float, access_count: int, size_bytes: int, last_accessed: int, created_at: int, score: float}|null
     */
    public function getKeyMetadata(string $key): ?array
    {
        $this->ensureLoaded();

        if (!isset($this->metadata[$key])) {
            return null;
        }

        return [
            ...$this->metadata[$key],
            'score' => $this->getScore($key),
        ];
    }

    /**
     * Get a report of all tracked keys sorted by value score (highest first).
     */
    public function getValueReport(): array
    {
        $this->ensureLoaded();

        $report = [];
        foreach ($this->metadata as $key => $meta) {
            $report[] = [
                'key' => $key,
                ...$meta,
                'score' => $this->getScore($key),
            ];
        }

        \usort($report, fn($a, $b) => $b['score'] <=> $a['score']);

        return $report;
    }

    /**
     * Suggest which keys should be evicted based on lowest value scores.
     *
     * @param int $count Number of keys to suggest for eviction
     * @return array List of keys with their scores, sorted lowest-first
     */
    public function suggestEvictions(int $count): array
    {
        $this->ensureLoaded();

        $scored = [];
        foreach ($this->metadata as $key => $meta) {
            $scored[$key] = $this->getScore($key);
        }

        \asort($scored);

        $suggestions = [];
        foreach (\array_slice($scored, 0, $count, true) as $key => $score) {
            $suggestions[] = [
                'key' => $key,
                'score' => $score,
                ...$this->metadata[$key],
            ];
        }

        return $suggestions;
    }

    /**
     * Remove metadata for a specific key.
     */
    public function forget(string $key): void
    {
        $this->ensureLoaded();

        if (isset($this->metadata[$key])) {
            unset($this->metadata[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Get the number of tracked keys.
     */
    public function trackedKeyCount(): int
    {
        $this->ensureLoaded();

        return \count($this->metadata);
    }

    /**
     * Persist metadata to cache (call at end of request or periodically).
     */
    public function persist(): void
    {
        if (!$this->dirty || empty($this->metadata)) {
            return;
        }

        $this->cache->put('_sc_cost_metadata', $this->metadata, $this->metadataTtl);
        $this->dirty = false;
    }

    /**
     * Load metadata from cache if not already loaded.
     */
    protected function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->metadata = $this->cache->get('_sc_cost_metadata', []);
        $this->loaded = true;
    }

    /**
     * Trim tracked keys if we exceed the maximum, removing lowest-value entries.
     */
    protected function trimIfNeeded(): void
    {
        if (\count($this->metadata) <= $this->maxTrackedKeys) {
            return;
        }

        // Score all keys and remove the lowest-value ones
        $scored = [];
        foreach ($this->metadata as $key => $meta) {
            $scored[$key] = $this->getScore($key);
        }

        \arsort($scored);

        // Keep only the top maxTrackedKeys
        $keysToKeep = \array_slice($scored, 0, $this->maxTrackedKeys, true);
        $this->metadata = \array_intersect_key($this->metadata, $keysToKeep);
    }

    /**
     * Persist metadata on shutdown if dirty.
     */
    public function __destruct()
    {
        $this->persist();
    }
}
