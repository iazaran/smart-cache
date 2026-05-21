<?php

namespace SmartCache\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * Service to clean up orphan chunks when main cache keys expire.
 */
class OrphanChunkCleanupService
{
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
     * Clean up orphan chunks (chunks whose main key no longer exists).
     *
     * @return array Statistics about the cleanup
     */
    public function cleanupOrphanChunks(): array
    {
        $orphanedMainKeys = [];
        $cleanedChunks = 0;

        foreach ($this->chunkRegistry as $mainKey => $data) {
            // Check if main key still exists
            if (!$this->cache->has($mainKey)) {
                // Main key is gone, clean up its chunks
                foreach ($data['chunk_keys'] as $chunkKey) {
                    if ($this->cache->forget($chunkKey)) {
                        $cleanedChunks++;
                    }
                }
                $orphanedMainKeys[] = $mainKey;
            }
        }

        // Remove orphaned entries from registry
        foreach ($orphanedMainKeys as $mainKey) {
            unset($this->chunkRegistry[$mainKey]);
        }

        // Always persist after a cleanup pass so the registry on disk reflects
        // the current state, including any debounced mutations from earlier calls.
        if (!empty($orphanedMainKeys) || $this->pendingChanges > 0) {
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
        $this->chunkRegistry = $this->cache->get('_sc_chunk_registry', []);
    }

    /**
     * Persist the chunk registry to cache.
     *
     * @return void
     */
    protected function persistRegistry(): void
    {
        $this->cache->forever('_sc_chunk_registry', $this->chunkRegistry);
    }
}

