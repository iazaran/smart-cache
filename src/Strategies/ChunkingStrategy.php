<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;
use SmartCache\Collections\LazyChunkedCollection;
use SmartCache\Services\SmartChunkSizeCalculator;
use SmartCache\Services\OrphanChunkCleanupService;

class ChunkingStrategy implements OptimizationStrategy
{
    /**
     * @var int
     */
    protected int $threshold;

    /**
     * @var int
     */
    protected int $chunkSize;

    /**
     * @var bool
     */
    protected bool $lazyLoading;

    /**
     * @var bool
     */
    protected bool $smartSizing;

    /**
     * @var SmartChunkSizeCalculator|null
     */
    protected ?SmartChunkSizeCalculator $sizeCalculator = null;

    /**
     * @var OrphanChunkCleanupService|null
     */
    protected ?OrphanChunkCleanupService $cleanupService = null;

    /**
     * ChunkingStrategy constructor.
     *
     * @param int $threshold Size threshold for chunking (in bytes)
     * @param int $chunkSize Maximum items per chunk
     * @param bool $lazyLoading Enable lazy loading
     * @param bool $smartSizing Enable smart chunk sizing
     */
    public function __construct(
        int $threshold = 102400,
        int $chunkSize = 1000,
        bool $lazyLoading = false,
        bool $smartSizing = false
    ) {
        $this->threshold = $threshold;
        $this->chunkSize = $chunkSize;
        $this->lazyLoading = $lazyLoading;
        $this->smartSizing = $smartSizing;

        if ($smartSizing) {
            $this->sizeCalculator = new SmartChunkSizeCalculator();
        }
    }

    /**
     * Set the cleanup service for tracking chunks.
     *
     * @param OrphanChunkCleanupService $cleanupService
     * @return void
     */
    public function setCleanupService(OrphanChunkCleanupService $cleanupService): void
    {
        $this->cleanupService = $cleanupService;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, array $context = []): bool
    {
        // Check if driver supports chunking
        if (isset($context['driver']) &&
            isset($context['config']['drivers'][$context['driver']]['chunking']) &&
            $context['config']['drivers'][$context['driver']]['chunking'] === false) {
            return false;
        }

        // Only chunk arrays and array-like objects
        if (!\is_array($value) && !($value instanceof \Traversable)) {
            return false;
        }

        // For Laravel collections, get the underlying array
        if (\class_exists('\Illuminate\Support\Collection') && $value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        // Quick count check first - must have enough items to chunk
        if (!\is_array($value) || \count($value) <= $this->chunkSize) {
            return false;
        }

        // Estimate size without full serialization: count * avg item size
        $count = \count($value);
        $estimate = $count * 50; // rough estimate per item

        // If clearly below threshold, skip serialization
        if ($estimate < $this->threshold / 2) {
            return false;
        }

        // If clearly above threshold, apply
        if ($estimate > $this->threshold * 2) {
            return true;
        }

        // For borderline cases, serialize to get exact size
        return \strlen(\serialize($value)) > $this->threshold;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        // Convert to array if it's a collection
        if (\class_exists('\Illuminate\Support\Collection') && $value instanceof \Illuminate\Support\Collection) {
            $isCollection = true;
            $value = $value->all();
        } else {
            $isCollection = false;
        }

        // Get cache instance from context
        $cache = $context['cache'] ?? null;
        $prefix = $context['key'] ?? uniqid('chunk_');
        $ttl = $context['ttl'] ?? null;
        $driver = $context['driver'] ?? null;

        // Calculate optimal chunk size if smart sizing is enabled
        $chunkSize = $this->chunkSize;
        if ($this->smartSizing && $this->sizeCalculator) {
            $chunkSize = $this->sizeCalculator->calculateOptimalSize($value, $driver, $this->chunkSize);
        }

        $chunks = array_chunk($value, $chunkSize, true);
        $chunkKeys = [];

        // Store each chunk separately
        foreach ($chunks as $index => $chunk) {
            $chunkKey = "_sc_chunk_{$prefix}_{$index}";
            $chunkKeys[] = $chunkKey;

            if ($cache) {
                $cache->put($chunkKey, $chunk, $ttl);
            }
        }

        // Register chunks for orphan cleanup tracking
        if ($this->cleanupService !== null) {
            $this->cleanupService->registerChunks($prefix, $chunkKeys);
        }

        return [
            '_sc_chunked' => true,
            'chunk_keys' => $chunkKeys,
            'total_items' => count($value),
            'is_collection' => $isCollection,
            'original_key' => $prefix,
            'driver' => $driver,
            'lazy_loading' => $this->lazyLoading,
            'chunk_size' => $chunkSize, // Use actual chunk size (may be different if smart sizing is enabled)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function restore(mixed $value, array $context = []): mixed
    {
        if (!is_array($value) || !isset($value['_sc_chunked']) || $value['_sc_chunked'] !== true) {
            return $value;
        }

        $cache = $context['cache'] ?? null;
        if (!$cache) {
            throw new \RuntimeException('Cache repository is required to restore chunked data');
        }

        // Check if lazy loading is enabled
        $lazyLoading = $value['lazy_loading'] ?? $this->lazyLoading;

        // Get the chunk size that was used (may be different if smart sizing was enabled)
        $chunkSize = $value['chunk_size'] ?? $this->chunkSize;

        if ($lazyLoading) {
            // Return lazy collection
            return new LazyChunkedCollection(
                $cache,
                $value['chunk_keys'],
                $chunkSize,
                $value['total_items'],
                $value['is_collection'] ?? false
            );
        }

        $result = [];

        // Retrieve and merge all chunks (eager loading)
        foreach ($value['chunk_keys'] as $chunkKey) {
            $chunk = $cache->get($chunkKey);
            if ($chunk === null) {
                // If any chunk is missing, return null indicating cache miss
                return null;
            }

            $result = array_merge($result, $chunk);
        }

        // Convert back to collection if needed
        if ($value['is_collection'] && \class_exists('\Illuminate\Support\Collection')) {
            return new \Illuminate\Support\Collection($result);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'chunking';
    }
} 