<?php

namespace SmartCache\Traits;

use SmartCache\Facades\SmartCache;
use SmartCache\Observers\CacheInvalidationObserver;

trait CacheInvalidation
{
    /**
     * Cache invalidation configuration for this model.
     *
     * @var array
     */
    protected array $cacheInvalidation = [
        'keys' => [],
        'tags' => [],
        'patterns' => [],
        'dependencies' => [],
    ];

    /**
     * Boot the cache invalidation trait.
     */
    protected static function bootCacheInvalidation(): void
    {
        static::observe(CacheInvalidationObserver::class);
    }

    /**
     * Define cache keys to invalidate when this model changes.
     *
     * @param array $keys
     * @return static
     */
    public function invalidatesKeys(array $keys): static
    {
        $this->cacheInvalidation['keys'] = array_merge($this->cacheInvalidation['keys'], $keys);
        return $this;
    }

    /**
     * Define cache tags to flush when this model changes.
     *
     * @param array $tags
     * @return static
     */
    public function invalidatesTags(array $tags): static
    {
        $this->cacheInvalidation['tags'] = array_merge($this->cacheInvalidation['tags'], $tags);
        return $this;
    }

    /**
     * Define cache key patterns to invalidate when this model changes.
     *
     * @param array $patterns
     * @return static
     */
    public function invalidatesPatterns(array $patterns): static
    {
        $this->cacheInvalidation['patterns'] = array_merge($this->cacheInvalidation['patterns'], $patterns);
        return $this;
    }

    /**
     * Define cache dependencies when this model changes.
     *
     * @param array $dependencies
     * @return static
     */
    public function invalidatesDependencies(array $dependencies): static
    {
        $this->cacheInvalidation['dependencies'] = array_merge($this->cacheInvalidation['dependencies'], $dependencies);
        return $this;
    }

    /**
     * Get cache invalidation configuration.
     *
     * @return array
     */
    public function getCacheInvalidationConfig(): array
    {
        return $this->cacheInvalidation;
    }

    /**
     * Get cache keys to invalidate for this model instance.
     * Override this method to define dynamic cache keys.
     *
     * @return array
     */
    public function getCacheKeysToInvalidate(): array
    {
        $keys = $this->cacheInvalidation['keys'];
        
        // Add dynamic keys based on model attributes
        $dynamicKeys = [];
        foreach ($keys as $key) {
            // Replace placeholders like {id}, {slug}, etc.
            $dynamicKey = preg_replace_callback('/\{(\w+)\}/', function ($matches) {
                $attribute = $matches[1];
                return $this->getAttribute($attribute) ?? $matches[0];
            }, $key);
            $dynamicKeys[] = $dynamicKey;
        }
        
        return array_merge($keys, $dynamicKeys);
    }

    /**
     * Get cache tags to flush for this model instance.
     * Override this method to define dynamic cache tags.
     *
     * @return array
     */
    public function getCacheTagsToFlush(): array
    {
        $tags = $this->cacheInvalidation['tags'];
        
        // Add dynamic tags based on model attributes
        $dynamicTags = [];
        foreach ($tags as $tag) {
            // Replace placeholders like {id}, {category_id}, etc.
            $dynamicTag = preg_replace_callback('/\{(\w+)\}/', function ($matches) {
                $attribute = $matches[1];
                return $this->getAttribute($attribute) ?? $matches[0];
            }, $tag);
            $dynamicTags[] = $dynamicTag;
        }
        
        return array_merge($tags, $dynamicTags);
    }

    /**
     * Perform cache invalidation.
     *
     * @return void
     */
    public function performCacheInvalidation(): void
    {
        // Invalidate specific keys
        foreach ($this->getCacheKeysToInvalidate() as $key) {
            SmartCache::forget($key);
        }

        // Flush tags
        $tagsToFlush = $this->getCacheTagsToFlush();
        if (!empty($tagsToFlush)) {
            SmartCache::flushTags($tagsToFlush);
        }

        // Handle patterns (basic wildcard support)
        foreach ($this->cacheInvalidation['patterns'] as $pattern) {
            $this->invalidatePattern($pattern);
        }

        // Handle dependencies
        foreach ($this->cacheInvalidation['dependencies'] as $dependency) {
            SmartCache::invalidate($dependency);
        }
    }

    /**
     * Invalidate cache keys matching a pattern.
     *
     * @param string $pattern
     * @return void
     */
    protected function invalidatePattern(string $pattern): void
    {
        // Replace model placeholders
        $pattern = preg_replace_callback('/\{(\w+)\}/', function ($matches) {
            $attribute = $matches[1];
            return $this->getAttribute($attribute) ?? $matches[0];
        }, $pattern);

        // This is a simplified pattern matching
        // In a real implementation, you might want to use the cache store's
        // native pattern matching capabilities (like Redis KEYS command)
        $managedKeys = SmartCache::getManagedKeys();
        
        foreach ($managedKeys as $key) {
            if ($this->matchesPattern($key, $pattern)) {
                SmartCache::forget($key);
            }
        }
    }

    /**
     * Check if a key matches a pattern with basic wildcard support.
     *
     * @param string $key
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $key, string $pattern): bool
    {
        // Convert simple wildcard pattern to regex
        $regexPattern = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
        return (bool) preg_match("/^{$regexPattern}$/", $key);
    }
}
