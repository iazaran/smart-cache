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
     * Create a new orphan chunk cleanup service.
     *
     * @param Repository $cache
     */
    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
        $this->loadChunkRegistry();
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
        $this->persistRegistry();
    }

    /**
     * Unregister chunks for a main key.
     *
     * @param string $mainKey
     * @return void
     */
    public function unregisterChunks(string $mainKey): void
    {
        unset($this->chunkRegistry[$mainKey]);
        $this->persistRegistry();
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

        if (!empty($orphanedMainKeys)) {
            $this->persistRegistry();
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

