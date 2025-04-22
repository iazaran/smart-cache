<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;

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
     * ChunkingStrategy constructor.
     *
     * @param int $threshold Size threshold for chunking (in bytes)
     * @param int $chunkSize Maximum items per chunk
     */
    public function __construct(int $threshold = 102400, int $chunkSize = 1000)
    {
        $this->threshold = $threshold;
        $this->chunkSize = $chunkSize;
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
        if (!is_array($value) && !($value instanceof \Traversable)) {
            return false;
        }

        // For Laravel collections, get the underlying array
        if (class_exists('\Illuminate\Support\Collection') && $value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        $serialized = serialize($value);
        
        // Check if size exceeds threshold and the array is large enough to benefit from chunking
        return strlen($serialized) > $this->threshold && (is_array($value) && count($value) > $this->chunkSize);
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        // Convert to array if it's a collection
        if (class_exists('\Illuminate\Support\Collection') && $value instanceof \Illuminate\Support\Collection) {
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

        $chunks = array_chunk($value, $this->chunkSize, true);
        $chunkKeys = [];

        // Store each chunk separately
        foreach ($chunks as $index => $chunk) {
            $chunkKey = "_sc_chunk_{$prefix}_{$index}";
            $chunkKeys[] = $chunkKey;
            
            if ($cache) {
                $cache->put($chunkKey, $chunk, $ttl);
            }
        }

        return [
            '_sc_chunked' => true,
            'chunk_keys' => $chunkKeys,
            'total_items' => count($value),
            'is_collection' => $isCollection,
            'original_key' => $prefix,
            'driver' => $driver,
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

        $result = [];
        
        // Retrieve and merge all chunks
        foreach ($value['chunk_keys'] as $chunkKey) {
            $chunk = $cache->get($chunkKey);
            if ($chunk === null) {
                // If any chunk is missing, return null indicating cache miss
                return null;
            }
            
            $result = array_merge($result, $chunk);
        }

        // Convert back to collection if needed
        if ($value['is_collection'] && class_exists('\Illuminate\Support\Collection')) {
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