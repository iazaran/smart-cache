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
     *
     * Registers model event listeners directly on the dispatcher instead of
     * using observe() or static event methods, to avoid the recursive
     * bootIfNotBooted() call that Laravel 13+ forbids during trait booting.
     */
    protected static function bootCacheInvalidation(): void
    {
        $observer = new CacheInvalidationObserver();

        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, function ($model) use ($observer, $event) {
                $observer->{$event}($model);
            });
        }
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
        $config = $this->cacheInvalidation;
        $declaredConfig = $this->getDeclarativeCacheInvalidationConfig();

        if ($declaredConfig !== null) {
            $config = $this->mergeCacheInvalidationConfig($declaredConfig, $config);
        }

        return $this->normalizeCacheInvalidationConfig($config);
    }

    /**
     * Get cache keys to invalidate for this model instance.
     * Override this method to define dynamic cache keys.
     *
     * @return array
     */
    public function getCacheKeysToInvalidate(): array
    {
        $config = $this->getCacheInvalidationConfig();

        return $this->resolveCacheInvalidationValues($config['keys']);
    }

    /**
     * Get cache tags to flush for this model instance.
     * Override this method to define dynamic cache tags.
     *
     * @return array
     */
    public function getCacheTagsToFlush(): array
    {
        $config = $this->getCacheInvalidationConfig();

        return $this->resolveCacheInvalidationValues($config['tags']);
    }

    /**
     * Get cache key patterns to invalidate for this model instance.
     *
     * @return array
     */
    public function getCachePatternsToInvalidate(): array
    {
        $config = $this->getCacheInvalidationConfig();

        return $this->resolveCacheInvalidationValues($config['patterns']);
    }

    /**
     * Get cache dependencies to invalidate for this model instance.
     *
     * @return array
     */
    public function getCacheDependenciesToInvalidate(): array
    {
        $config = $this->getCacheInvalidationConfig();

        return $this->resolveCacheInvalidationValues($config['dependencies']);
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
            SmartCache::flushTags($tagsToFlush, 'model');
        }

        // Handle patterns (basic wildcard support)
        foreach ($this->getCachePatternsToInvalidate() as $pattern) {
            $this->invalidatePattern($pattern);
        }

        // Handle dependencies
        foreach ($this->getCacheDependenciesToInvalidate() as $dependency) {
            SmartCache::invalidate($dependency);
        }
    }

    /**
     * Flush cache tags for a model class explicitly.
     *
     * Useful after query-builder updates, mass deletes, upserts, raw SQL, or
     * quiet saves where Eloquent model events are intentionally bypassed.
     *
     * The no-argument form resolves the model's declared tags against a fresh
     * instance, so it is intended for static tags (e.g. 'products'). Tags that
     * embed instance placeholders like 'category_{category_id}' cannot be
     * resolved without a loaded model — pass those tags explicitly instead.
     *
     * @param string|array|null $tags
     * @return bool
     */
    public static function flushCacheTags(string|array|null $tags = null): bool
    {
        $tagsToFlush = $tags === null
            ? (new static())->getCacheTagsToFlush()
            : (\is_array($tags) ? $tags : [$tags]);

        $tagsToFlush = \array_values(\array_unique(\array_filter(
            $tagsToFlush,
            fn ($tag) => \is_string($tag) && $tag !== ''
        )));

        if ($tagsToFlush === []) {
            return true;
        }

        return SmartCache::flushTags($tagsToFlush, 'model_helper');
    }

    /**
     * Invalidate cache keys matching a pattern.
     *
     * @param string $pattern
     * @return void
     */
    protected function invalidatePattern(string $pattern): void
    {
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

    /**
     * Read an optional declarative invalidation method when it is compatible.
     *
     * The guard keeps this additive for existing models that may already have
     * a cacheInvalidation() method for another purpose with required arguments.
     *
     * @return array|null
     */
    protected function getDeclarativeCacheInvalidationConfig(): ?array
    {
        if (!\method_exists($this, 'cacheInvalidation')) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($this, 'cacheInvalidation');

            if ($method->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $config = $this->cacheInvalidation();

            return \is_array($config) ? $config : null;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Merge two invalidation config arrays without duplicating entries.
     *
     * @param array $base
     * @param array $override
     * @return array
     */
    protected function mergeCacheInvalidationConfig(array $base, array $override): array
    {
        $base = $this->normalizeCacheInvalidationConfig($base);
        $override = $this->normalizeCacheInvalidationConfig($override);

        foreach (['keys', 'tags', 'patterns', 'dependencies'] as $section) {
            $base[$section] = \array_values(\array_unique([
                ...$base[$section],
                ...$override[$section],
            ]));
        }

        return $base;
    }

    /**
     * Normalize invalidation config into the expected shape.
     *
     * @param array $config
     * @return array
     */
    protected function normalizeCacheInvalidationConfig(array $config): array
    {
        $normalized = [
            'keys' => [],
            'tags' => [],
            'patterns' => [],
            'dependencies' => [],
        ];

        foreach ($normalized as $section => $default) {
            $values = $config[$section] ?? [];
            $normalized[$section] = \is_array($values) ? \array_values($values) : [$values];
        }

        return $normalized;
    }

    /**
     * Resolve placeholders like {id}, {slug}, and {category_id}.
     *
     * @param array $values
     * @return array
     */
    protected function resolveCacheInvalidationValues(array $values): array
    {
        $resolvedValues = [];

        foreach ($values as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $resolvedValues[] = preg_replace_callback('/\{(\w+)\}/', function ($matches) {
                $attribute = $matches[1];
                return $this->getAttribute($attribute) ?? $matches[0];
            }, $value);
        }

        return \array_values(\array_unique($resolvedValues));
    }
}
