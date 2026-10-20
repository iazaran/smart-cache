<?php

namespace SmartCache\Services;

use SmartCache\Contracts\SmartCache;
use Illuminate\Support\Facades\Cache;

class CacheInvalidationService
{
    protected SmartCache $smartCache;

    public function __construct(SmartCache $smartCache)
    {
        $this->smartCache = $smartCache;
    }

    /**
     * Run a callback with the SmartCache namespace suspended.
     *
     * getManagedKeys() returns fully qualified keys, but forget()/has()/getRaw()
     * apply the active namespace to whatever they are given. Feeding one to the
     * other double-prefixes the key, so every lookup in this service misses and
     * invalidation silently becomes a no-op for namespaced callers.
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    private function withoutNamespace(callable $callback): mixed
    {
        // getNamespace() is deliberately not on the SmartCache contract, so a
        // third-party implementation may not expose it. Fall back to the historical
        // behaviour rather than fataling on it.
        if (!\is_callable([$this->smartCache, 'getNamespace'])) {
            return $callback();
        }

        $saved = $this->smartCache->getNamespace();

        if (!\is_string($saved)) {
            return $callback();
        }

        $this->smartCache->withoutNamespace();

        try {
            return $callback();
        } finally {
            $this->smartCache->namespace($saved);
        }
    }

    /**
     * Flush cache by multiple patterns with advanced matching.
     *
     * @param array $patterns
     * @return int Number of keys invalidated
     */
    public function flushPatterns(array $patterns): int
    {
        $namespace = $this->activeNamespace();

        return $this->withoutNamespace(function () use ($patterns, $namespace): int {
            $invalidated = 0;
            $managedKeys = $this->smartCache->getManagedKeys();

            foreach ($patterns as $pattern) {
                foreach ($managedKeys as $key) {
                    $key = (string) $key;

                    if ($this->matchesAdvancedPattern($key, $pattern)
                        || $this->matchesWithinNamespace($key, (string) $pattern, $namespace)
                    ) {
                        $result = $this->smartCache->forget($key);
                        if ($result) {
                            $invalidated++;
                        }
                    }
                }
            }

            return $invalidated;
        });
    }

    /**
     * The caller's namespace, or null when there is none or the SmartCache
     * implementation does not expose it.
     *
     * @return string|null
     */
    private function activeNamespace(): ?string
    {
        if (!\is_callable([$this->smartCache, 'getNamespace'])) {
            return null;
        }

        $namespace = $this->smartCache->getNamespace();

        return \is_string($namespace) && $namespace !== '' ? $namespace : null;
    }

    /**
     * Whether a fully qualified key in the caller's namespace matches the
     * pattern once the namespace prefix is removed.
     *
     * Patterns are matched against fully qualified managed keys, so before 1.16
     * namespace('t1')->flushPatterns(['user_*']) removed the global user_*
     * keys but left t1:user_* stale. Both are removed now.
     *
     * @param string $key
     * @param string $pattern
     * @param string|null $namespace
     * @return bool
     */
    private function matchesWithinNamespace(string $key, string $pattern, ?string $namespace): bool
    {
        if ($namespace === null || !\str_starts_with($key, $namespace . ':')) {
            return false;
        }

        return $this->matchesAdvancedPattern(\substr($key, \strlen($namespace) + 1), $pattern);
    }

    /**
     * Invalidate cache based on model relationships.
     *
     * @param string $modelClass
     * @param mixed $modelId
     * @param array $relationships
     * @return int Number of keys invalidated
     */
    public function invalidateModelRelations(string $modelClass, mixed $modelId, array $relationships = []): int
    {
        $invalidated = 0;
        $basePatterns = [
            $modelClass . '_' . $modelId . '_*',
            $modelClass . '_*_' . $modelId,
            strtolower(class_basename($modelClass)) . '_' . $modelId . '_*',
        ];

        // Add relationship-based patterns
        foreach ($relationships as $relation) {
            $basePatterns[] = $relation . '_*_' . $modelClass . '_' . $modelId;
            $basePatterns[] = $modelClass . '_' . $modelId . '_' . $relation . '_*';
        }

        $invalidated += $this->flushPatterns($basePatterns);

        return $invalidated;
    }

    /**
     * Set up cache warming for frequently accessed keys.
     *
     * @param array $warmingRules
     * @return void
     */
    public function setupCacheWarming(array $warmingRules): void
    {
        foreach ($warmingRules as $rule) {
            if (isset($rule['key'], $rule['callback'], $rule['ttl'])) {
                // Warm the cache if it doesn't exist or is about to expire
                if (!$this->smartCache->has($rule['key'])) {
                    $value = $rule['callback']();
                    $this->smartCache->put($rule['key'], $value, $rule['ttl']);
                }
            }
        }
    }

    /**
     * Create cache hierarchies for organized invalidation.
     *
     * @param string $parentKey
     * @param array $childKeys
     * @return void
     */
    public function createCacheHierarchy(string $parentKey, array $childKeys): void
    {
        foreach ($childKeys as $childKey) {
            $this->smartCache->dependsOn($childKey, $parentKey);
        }
    }

    /**
     * Advanced pattern matching with regex and wildcard support.
     *
     * @param string $key
     * @param string $pattern
     * @return bool
     */
    protected function matchesAdvancedPattern(string $key, string $pattern): bool
    {
        try {
            // Handle regex patterns (starting with /)
            if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                $result = preg_match($pattern, $key);
                return $result === 1;
            }

            // Handle glob patterns (* and ?)
            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                // Convert glob pattern to regex pattern
                $regexPattern = preg_quote($pattern, '/');
                $regexPattern = str_replace(['\*', '\?'], ['.*', '.'], $regexPattern);
                $result = preg_match("/^{$regexPattern}$/", $key);
                return $result === 1;
            }

            // Exact match
            return $key === $pattern;
        } catch (\Exception $e) {
            // If pattern matching fails, return false to prevent errors
            return false;
        }
    }

    /**
     * Get cache statistics and analytics.
     *
     * @return array
     */
    public function getCacheStatistics(): array
    {
        $managedKeys = $this->smartCache->getManagedKeys();
        $stats = [
            'managed_keys_count' => count($managedKeys),
            'tag_usage' => [],
            'dependency_chains' => [],
            'optimization_stats' => [
                'compressed' => 0,
                'chunked' => 0,
                'unoptimized' => 0,
            ],
        ];

        // Analyze optimization usage (use getRaw to see optimization markers)
        foreach ($managedKeys as $key) {
            $value = $this->withoutNamespace(fn () => $this->smartCache->getRaw($key));
            if (\is_array($value)) {
                if (isset($value['_sc_compressed'])) {
                    $stats['optimization_stats']['compressed']++;
                } elseif (isset($value['_sc_chunked'])) {
                    $stats['optimization_stats']['chunked']++;
                } else {
                    $stats['optimization_stats']['unoptimized']++;
                }
            } else {
                $stats['optimization_stats']['unoptimized']++;
            }
        }

        return $stats;
    }

    /**
     * Perform cache health check and cleanup.
     *
     * @return array
     */
    public function healthCheckAndCleanup(): array
    {
        $results = [
            'orphaned_chunks_cleaned' => 0,
            'broken_dependencies_fixed' => 0,
            'invalid_tags_removed' => 0,
            'expired_keys_cleaned' => 0,
            'total_keys_checked' => 0,
        ];

        $results['total_keys_checked'] = count($this->smartCache->getManagedKeys());

        // Clean up expired managed keys first
        $results['expired_keys_cleaned'] = $this->smartCache->cleanupExpiredManagedKeys();

        return $this->withoutNamespace(function () use ($results): array {
            // Re-read after the expiry sweep so the scan below does not walk keys
            // that were just dropped from the index.
            $managedKeys = $this->smartCache->getManagedKeys();

            // Check for orphaned chunks (use getRaw to see optimization markers)
            foreach ($managedKeys as $key) {
                $value = $this->smartCache->getRaw($key);

                if (!\is_array($value) || !isset($value['_sc_chunked'])) {
                    continue;
                }

                // A truncated or legacy wrapper may carry the marker without the
                // chunk list; treat it as broken rather than iterating null.
                if (!isset($value['chunk_keys']) || !\is_array($value['chunk_keys'])) {
                    $this->smartCache->forget($key);
                    $results['orphaned_chunks_cleaned']++;
                    continue;
                }

                $missingChunks = 0;
                foreach ($value['chunk_keys'] as $chunkKey) {
                    if (!$this->smartCache->has($chunkKey)) {
                        $missingChunks++;
                    }
                }

                // A writer deletes the replaced entry's chunks once its new entry
                // is stored; only an entry that is still the one inspected is
                // broken.
                if ($missingChunks > 0 && $this->smartCache->getRaw($key) === $value) {
                    // Key has missing chunks, remove it
                    $this->smartCache->forget($key);
                    $results['orphaned_chunks_cleaned']++;
                }
            }

            return $results;
        });
    }
}
