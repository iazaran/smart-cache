<?php

namespace SmartCache\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * Service to clean up orphan chunks when main cache keys expire.
 *
 * Since 1.16.0 SmartCache registers the chunks of every chunked value stored
 * without expiry (forever(), rememberForever(), put() with a null TTL); those
 * are the chunks nothing else would remove once their entry is gone. Chunks of
 * values with a TTL expire with them and are not registered.
 */
class OrphanChunkCleanupService
{
    /**
     * Cache key of the registry: main key => ['chunk_keys' => [...], 'registered_at' => int].
     */
    public const REGISTRY_KEY = '_sc_chunk_registry';

    /**
     * @var Repository
     */
    protected Repository $cache;

    /**
     * @var array
     */
    protected array $chunkRegistry = [];

    /**
     * Number of registry mutations between persists.  Default `1` matches the
     * historical behaviour (persist on every change).  Higher values batch
     * writes on extremely chunk-heavy workloads.
     *
     * @var int
     */
    protected int $persistEvery = 1;

    /**
     * Counter for un-persisted mutations.
     *
     * @var int
     */
    protected int $pendingChanges = 0;

    /**
     * Create a new orphan chunk cleanup service.
     *
     * @param Repository $cache
     * @param int $persistEvery Persist the registry every N mutations (>=1).
     */
    public function __construct(Repository $cache, int $persistEvery = 1)
    {
        $this->cache = $cache;
        $this->persistEvery = max(1, $persistEvery);
        $this->loadChunkRegistry();
    }

    /**
     * Flush any buffered registry changes to the underlying cache.  Called by
     * the SmartCache shutdown hook so debounced writes are not lost between
     * requests.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->pendingChanges > 0) {
            $this->persistRegistry();
            $this->pendingChanges = 0;
        }
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            // Best-effort flush on shutdown.
        }
    }

    /**
     * Register chunks for a main key.
     *
     * @param string $mainKey
     * @param array $chunkKeys
     * @return void
     */
    public function registerChunks(string $mainKey, array $chunkKeys): void
    {
        $this->chunkRegistry[$mainKey] = [
            'chunk_keys' => $chunkKeys,
            'registered_at' => time(),
        ];
        $this->recordChange();
    }

    /**
     * Unregister chunks for a main key.
     *
     * @param string $mainKey
     * @return void
     */
    public function unregisterChunks(string $mainKey): void
    {
        if (!isset($this->chunkRegistry[$mainKey])) {
            return;
        }

        unset($this->chunkRegistry[$mainKey]);
        $this->recordChange();
    }

    /**
     * Record a registry mutation, flushing to cache once the debounce
     * threshold is reached.
     */
    protected function recordChange(): void
    {
        $this->pendingChanges++;
        if ($this->pendingChanges >= $this->persistEvery) {
            $this->persistRegistry();
            $this->pendingChanges = 0;
        }
    }

    /**
     * Clean up orphan chunks: registered chunks that the main key's current
     * entry no longer points at, because the entry is gone or was replaced.
     *
     * The stored registry is re-read first, since other processes register
     * chunks too. Entries this instance holds in memory are kept as well; at
     * worst an entry is checked that is already clean.
     *
     * @return array Statistics about the cleanup
     */
    public function cleanupOrphanChunks(): array
    {
        $stored = $this->cache->get(self::REGISTRY_KEY, []);
        $this->chunkRegistry = \array_replace(\is_array($stored) ? $stored : [], $this->chunkRegistry);

        $orphanedMainKeys = [];
        $cleanedChunks = 0;
        $changed = false;

        foreach ($this->chunkRegistry as $mainKey => $data) {
            $registered = $this->stringList($data['chunk_keys'] ?? []);
            $referenced = $this->referencedChunkKeys($this->cache->get((string) $mainKey));

            foreach (\array_diff($registered, $referenced) as $chunkKey) {
                if ($this->cache->forget($chunkKey)) {
                    $cleanedChunks++;
                }
            }

            if ($referenced === []) {
                // Main key is gone, or no longer chunked: nothing left to track
                $orphanedMainKeys[] = $mainKey;
                unset($this->chunkRegistry[$mainKey]);
                $changed = true;
            } elseif ($referenced !== $registered) {
                // Replaced by another chunked value; track its chunks instead
                $this->chunkRegistry[$mainKey] = [
                    'chunk_keys' => $referenced,
                    'registered_at' => $data['registered_at'] ?? time(),
                ];
                $changed = true;
            }
        }

        // Always persist after a cleanup pass so the registry on disk reflects
        // the current state, including any debounced mutations from earlier calls.
        if ($changed || $this->pendingChanges > 0) {
            $this->persistRegistry();
            $this->pendingChanges = 0;
        }

        return [
            'orphaned_main_keys' => count($orphanedMainKeys),
            'cleaned_chunks' => $cleanedChunks,
            'remaining_tracked_keys' => count($this->chunkRegistry),
        ];
    }

    /**
     * The chunk keys a stored entry points at; [] when it is missing or not chunked.
     *
     * @param mixed $raw
     * @return array<int, string>
     */
    protected function referencedChunkKeys(mixed $raw): array
    {
        if (!\is_array($raw) || ($raw['_sc_chunked'] ?? false) !== true) {
            return [];
        }

        return $this->stringList($raw['chunk_keys'] ?? []);
    }

    /**
     * @param mixed $keys
     * @return array<int, string>
     */
    private function stringList(mixed $keys): array
    {
        if (!\is_array($keys)) {
            return [];
        }

        $list = [];
        foreach ($keys as $key) {
            if (\is_string($key) || \is_int($key)) {
                $list[] = (string) $key;
            }
        }

        return $list;
    }

    /**
     * Get the chunk registry.
     *
     * @return array
     */
    public function getChunkRegistry(): array
    {
        return $this->chunkRegistry;
    }

    /**
     * Load the chunk registry from cache.
     *
     * @return void
     */
    protected function loadChunkRegistry(): void
    {
        $registry = $this->cache->get(self::REGISTRY_KEY, []);
        // A foreign or corrupted entry under this key must not fatal the request.
        $this->chunkRegistry = \is_array($registry) ? $registry : [];
    }

    /**
     * Persist the chunk registry to cache.
     *
     * @return void
     */
    protected function persistRegistry(): void
    {
        if ($this->chunkRegistry === []) {
            $this->cache->forget(self::REGISTRY_KEY);

            return;
        }

        $this->cache->forever(self::REGISTRY_KEY, $this->chunkRegistry);
    }
}

