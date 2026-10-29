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
     * Registrations and removals made through this instance since the last
     * persist. persistRegistry() applies them to the stored registry instead
     * of writing this instance's copy as a whole, which would drop what other
     * processes registered since it was loaded.
     *
     * @var array<string, array>
     */
    private array $pendingRegistrations = [];

    /**
     * @var array<string, true>
     */
    private array $pendingRemovals = [];

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
        $this->pendingRegistrations[$mainKey] = $this->chunkRegistry[$mainKey];
        unset($this->pendingRemovals[$mainKey]);
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

        unset($this->chunkRegistry[$mainKey], $this->pendingRegistrations[$mainKey]);
        $this->pendingRemovals[$mainKey] = true;
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
     * The pass starts from the stored registry, since SmartCache and other
     * processes register chunks there, and also checks the entries this
     * instance holds; at worst an entry is checked that is already clean. At
     * the end only the pass's own changes are applied to the registry as it is
     * stored then. Writing back the copy read at the start would drop chunks
     * registered while the pass ran, or since an earlier pass in the same
     * process, and those chunks would then never be removed.
     *
     * @return array Statistics about the cleanup
     */
    public function cleanupOrphanChunks(): array
    {
        $registry = $this->readStoredRegistry();

        // Chunk lists are merged rather than replaced: only chunks the current
        // entry does not point at are ever deleted, so checking more is safe.
        foreach ($this->chunkRegistry as $mainKey => $data) {
            $registry[$mainKey] = [
                'chunk_keys' => \array_values(\array_unique([
                    ...$this->stringList($registry[$mainKey]['chunk_keys'] ?? []),
                    ...$this->stringList(\is_array($data) ? ($data['chunk_keys'] ?? []) : []),
                ])),
                'registered_at' => $registry[$mainKey]['registered_at'] ?? (\is_array($data) ? ($data['registered_at'] ?? null) : null),
            ];
        }

        $orphanedMainKeys = 0;
        $cleanedChunks = 0;
        /** @var array<string, array{removed: array<int, string>, referenced: array<int, string>}> $changes */
        $changes = [];

        foreach ($registry as $mainKey => $data) {
            $mainKey = (string) $mainKey;
            $registered = $this->stringList(\is_array($data) ? ($data['chunk_keys'] ?? []) : []);
            $referenced = $this->referencedChunkKeys($this->cache->get($mainKey));
            $removed = \array_values(\array_diff($registered, $referenced));

            foreach ($removed as $chunkKey) {
                if ($this->cache->forget($chunkKey)) {
                    $cleanedChunks++;
                }
            }

            if ($referenced === []) {
                // Main key is gone, or no longer chunked: nothing left to track
                $orphanedMainKeys++;
            }

            if ($removed !== [] || $referenced !== $registered) {
                $changes[$mainKey] = ['removed' => $removed, 'referenced' => $referenced];
            }
        }

        // Always persist after a cleanup pass so the registry on disk reflects
        // the current state, including any debounced mutations from earlier calls.
        if ($changes !== [] || $this->pendingChanges > 0) {
            $this->writeRegistry($changes);
            $this->pendingChanges = 0;
        } else {
            $this->chunkRegistry = $registry;
        }

        return [
            'orphaned_main_keys' => $orphanedMainKeys,
            'cleaned_chunks' => $cleanedChunks,
            'remaining_tracked_keys' => count($this->chunkRegistry),
        ];
    }

    /**
     * The stored registry, or [] when it is missing or not an array.
     *
     * @return array
     */
    private function readStoredRegistry(): array
    {
        $registry = $this->cache->get(self::REGISTRY_KEY, []);

        return \is_array($registry) ? $registry : [];
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
        $this->writeRegistry([]);
    }

    /**
     * Apply this instance's pending changes, and a cleanup pass's changes, to
     * the registry as it is stored now.
     *
     * @param array<string, array{removed: array<int, string>, referenced: array<int, string>}> $cleanup
     * @return void
     */
    private function writeRegistry(array $cleanup): void
    {
        $registry = $this->readStoredRegistry();

        foreach ($this->pendingRemovals as $mainKey => $flag) {
            unset($registry[$mainKey]);
        }

        foreach ($this->pendingRegistrations as $mainKey => $data) {
            $registry[$mainKey] = $data;
        }

        // A cleanup pass removes the chunks it deleted and tracks the chunks the
        // current entry points at. Chunks registered while it ran stay listed.
        foreach ($cleanup as $mainKey => $change) {
            $chunks = \array_values(\array_unique([
                ...\array_diff($this->stringList($registry[$mainKey]['chunk_keys'] ?? []), $change['removed']),
                ...$change['referenced'],
            ]));

            if ($chunks === []) {
                unset($registry[$mainKey]);
            } else {
                $registry[$mainKey] = [
                    'chunk_keys' => $chunks,
                    'registered_at' => $registry[$mainKey]['registered_at'] ?? time(),
                ];
            }
        }

        $this->pendingRegistrations = [];
        $this->pendingRemovals = [];
        $this->chunkRegistry = $registry;

        if ($registry === []) {
            $this->cache->forget(self::REGISTRY_KEY);

            return;
        }

        $this->cache->forever(self::REGISTRY_KEY, $registry);
    }
}

