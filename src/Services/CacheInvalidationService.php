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
     * Flush cache by multiple patterns with advanced matching.
     *
     * @param array $patterns
     * @return int Number of keys invalidated
     */
    public function flushPatterns(array $patterns): int
    {
        $invalidated = 0;
        $managedKeys = $this->smartCache->getManagedKeys();

        foreach ($patterns as $pattern) {
            foreach ($managedKeys as $key) {
                if ($this->matchesAdvancedPattern($key, $pattern)) {
                    $result = $this->smartCache->forget($key);
                    if ($result) {
                        $invalidated++;
                    }
                }
            }
        }

        return $invalidated;
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

        // Analyze optimization usage
        foreach ($managedKeys as $key) {
            $value = $this->smartCache->get($key);
            if (is_array($value)) {
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

        $managedKeys = $this->smartCache->getManagedKeys();
        $results['total_keys_checked'] = count($managedKeys);

        // Clean up expired managed keys first
        $results['expired_keys_cleaned'] = $this->smartCache->cleanupExpiredManagedKeys();

        // Check for orphaned chunks
        foreach ($managedKeys as $key) {
            $value = $this->smartCache->get($key);
            if (is_array($value) && isset($value['_sc_chunked'])) {
                $missingChunks = 0;
                foreach ($value['chunk_keys'] as $chunkKey) {
                    if (!$this->smartCache->has($chunkKey)) {
                        $missingChunks++;
                    }
                }
                
                if ($missingChunks > 0) {
                    // Key has missing chunks, remove it
                    $this->smartCache->forget($key);
                    $results['orphaned_chunks_cleaned']++;
                }
            }
        }

        return $results;
    }
}
