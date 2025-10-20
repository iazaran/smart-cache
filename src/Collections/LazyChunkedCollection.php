<?php

namespace SmartCache\Collections;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * Lazy Chunked Collection
 * 
 * Loads chunks on-demand as they're accessed, reducing memory usage
 * for large datasets stored in cache.
 */
class LazyChunkedCollection implements \Iterator, \Countable, \ArrayAccess
{
    /**
     * @var Repository
     */
    protected Repository $cache;

    /**
     * @var array
     */
    protected array $chunkKeys;

    /**
     * @var int
     */
    protected int $chunkSize;

    /**
     * @var int
     */
    protected int $totalItems;

    /**
     * @var bool
     */
    protected bool $isCollection;

    /**
     * @var int
     */
    protected int $position = 0;

    /**
     * @var array|null
     */
    protected ?array $currentChunk = null;

    /**
     * @var int
     */
    protected int $currentChunkIndex = -1;

    /**
     * @var array
     */
    protected array $loadedChunks = [];

    /**
     * @var int
     */
    protected int $maxLoadedChunks = 3; // Keep max 3 chunks in memory

    /**
     * Create a new lazy chunked collection.
     *
     * @param Repository $cache
     * @param array $chunkKeys
     * @param int $chunkSize
     * @param int $totalItems
     * @param bool $isCollection
     */
    public function __construct(
        Repository $cache,
        array $chunkKeys,
        int $chunkSize,
        int $totalItems,
        bool $isCollection = false
    ) {
        $this->cache = $cache;
        $this->chunkKeys = $chunkKeys;
        $this->chunkSize = $chunkSize;
        $this->totalItems = $totalItems;
        $this->isCollection = $isCollection;
    }

    /**
     * Get the current element.
     *
     * @return mixed
     */
    public function current(): mixed
    {
        $this->ensureChunkLoaded($this->position);
        
        $indexInChunk = $this->position % $this->chunkSize;
        
        if ($this->currentChunk === null || !isset($this->currentChunk[$indexInChunk])) {
            return null;
        }
        
        return $this->currentChunk[$indexInChunk];
    }

    /**
     * Get the current key.
     *
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Move to the next element.
     *
     * @return void
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * Rewind to the first element.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Check if the current position is valid.
     *
     * @return bool
     */
    public function valid(): bool
    {
        return $this->position < $this->totalItems;
    }

    /**
     * Count the total number of items.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->totalItems;
    }

    /**
     * Check if an offset exists.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->totalItems;
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!$this->offsetExists($offset)) {
            return null;
        }
        
        $this->ensureChunkLoaded($offset);
        
        $indexInChunk = $offset % $this->chunkSize;
        
        return $this->currentChunk[$indexInChunk] ?? null;
    }

    /**
     * Set an item at a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     * @throws \RuntimeException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('LazyChunkedCollection is read-only');
    }

    /**
     * Unset an item at a given offset.
     *
     * @param mixed $offset
     * @return void
     * @throws \RuntimeException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('LazyChunkedCollection is read-only');
    }

    /**
     * Convert to array (loads all chunks).
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [];
        
        foreach ($this->chunkKeys as $chunkKey) {
            $chunk = $this->cache->get($chunkKey, []);
            $result = array_merge($result, $chunk);
        }
        
        return $result;
    }

    /**
     * Convert to Laravel Collection (loads all chunks).
     *
     * @return Collection
     */
    public function toCollection(): Collection
    {
        return new Collection($this->toArray());
    }

    /**
     * Get a slice of the collection without loading all chunks.
     *
     * @param int $offset
     * @param int|null $length
     * @return array
     */
    public function slice(int $offset, ?int $length = null): array
    {
        $result = [];
        $end = $length !== null ? $offset + $length : $this->totalItems;
        $end = min($end, $this->totalItems);
        
        for ($i = $offset; $i < $end; $i++) {
            $result[] = $this->offsetGet($i);
        }
        
        return $result;
    }

    /**
     * Apply a callback to each item without loading all chunks at once.
     *
     * @param callable $callback
     * @return void
     */
    public function each(callable $callback): void
    {
        foreach ($this as $index => $item) {
            $callback($item, $index);
        }
    }

    /**
     * Filter items without loading all chunks at once.
     *
     * @param callable $callback
     * @return array
     */
    public function filter(callable $callback): array
    {
        $result = [];
        
        foreach ($this as $index => $item) {
            if ($callback($item, $index)) {
                $result[] = $item;
            }
        }
        
        return $result;
    }

    /**
     * Map items without loading all chunks at once.
     *
     * @param callable $callback
     * @return array
     */
    public function map(callable $callback): array
    {
        $result = [];
        
        foreach ($this as $index => $item) {
            $result[] = $callback($item, $index);
        }
        
        return $result;
    }

    /**
     * Ensure the chunk containing the given position is loaded.
     *
     * @param int $position
     * @return void
     */
    protected function ensureChunkLoaded(int $position): void
    {
        $chunkIndex = (int) floor($position / $this->chunkSize);
        
        // Already loaded
        if ($chunkIndex === $this->currentChunkIndex) {
            return;
        }
        
        // Check if chunk is in loaded chunks cache
        if (isset($this->loadedChunks[$chunkIndex])) {
            $this->currentChunk = $this->loadedChunks[$chunkIndex];
            $this->currentChunkIndex = $chunkIndex;
            return;
        }
        
        // Load the chunk
        if (isset($this->chunkKeys[$chunkIndex])) {
            $this->currentChunk = $this->cache->get($this->chunkKeys[$chunkIndex], []);
            $this->currentChunkIndex = $chunkIndex;
            
            // Cache the loaded chunk
            $this->loadedChunks[$chunkIndex] = $this->currentChunk;
            
            // Limit memory usage by removing old chunks
            if (count($this->loadedChunks) > $this->maxLoadedChunks) {
                // Remove the oldest chunk (first key)
                $oldestKey = array_key_first($this->loadedChunks);
                unset($this->loadedChunks[$oldestKey]);
            }
        } else {
            $this->currentChunk = null;
            $this->currentChunkIndex = -1;
        }
    }

    /**
     * Get memory usage statistics.
     *
     * @return array
     */
    public function getMemoryStats(): array
    {
        return [
            'loaded_chunks' => count($this->loadedChunks),
            'total_chunks' => count($this->chunkKeys),
            'memory_usage' => strlen(serialize($this->loadedChunks)),
            'total_items' => $this->totalItems,
            'chunk_size' => $this->chunkSize,
        ];
    }
}

