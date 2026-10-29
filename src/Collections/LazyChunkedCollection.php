<?php

namespace SmartCache\Collections;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * Lazy Chunked Collection
 * 
 * Loads chunks on-demand as they're accessed, reducing memory usage
 * for large datasets stored in cache.
 *
 * A chunk that is missing when it is loaded (evicted, or deleted because the
 * entry was replaced after this collection was returned) throws a
 * RuntimeException. Before 1.16.0 its items silently read as null and
 * toArray() left them out, so a partial dataset looked complete.
 */
class LazyChunkedCollection implements \Iterator, \Countable, \ArrayAccess, \JsonSerializable
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
     * Class of the cached collection, such as Eloquent\Collection, for
     * materialize(); null for entries written before it was recorded.
     *
     * @var string|null
     */
    protected ?string $collectionClass = null;

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
     * @param string|null $collectionClass Class of the cached collection
     */
    public function __construct(
        Repository $cache,
        array $chunkKeys,
        int $chunkSize,
        int $totalItems,
        bool $isCollection = false,
        ?string $collectionClass = null
    ) {
        $this->cache = $cache;
        $this->chunkKeys = $chunkKeys;
        $this->chunkSize = $chunkSize;
        $this->totalItems = $totalItems;
        $this->isCollection = $isCollection;
        $this->collectionClass = $collectionClass;
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
            $chunk = $this->fetchChunk($chunkKey);

            // Chunks preserve the original keys (ChunkingStrategy has always used
            // array_chunk(..., preserve_keys: true)). array_merge would renumber the
            // integer ones, so sparse int keys came back renumbered here while the
            // eager path returned them intact.
            //
            // Keys are appended rather than overwritten when they collide, so a
            // collection assembled by hand from chunk-relative (0-based) chunks
            // still yields every item instead of keeping only the last chunk.
            foreach ($chunk as $itemKey => $itemValue) {
                // Preserve array_merge()'s established behavior for string-key
                // collisions: the later chunk replaces the earlier value.
                if (\is_string($itemKey)) {
                    $result[$itemKey] = $itemValue;
                    continue;
                }

                if (\array_key_exists($itemKey, $result)) {
                    $result[] = $itemValue;
                    continue;
                }

                $result[$itemKey] = $itemValue;
            }
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
     * Load every chunk now: a Collection when a collection was cached, an
     * array otherwise, as an eager read returns. A cached Eloquent\Collection
     * (or another Collection subclass) comes back as that class, as it does
     * from an eager read; before 1.17.0 it came back as a Support\Collection.
     *
     * @return array|Collection
     */
    public function materialize(): array|Collection
    {
        if (!$this->isCollection) {
            return $this->toArray();
        }

        $class = $this->collectionClass;

        if ($class !== null
            && $class !== Collection::class
            && \class_exists($class)
            && \is_subclass_of($class, Collection::class)
        ) {
            try {
                return new $class($this->toArray());
            } catch (\Throwable $e) {
                // Fall through to a plain collection, as an eager read does.
            }
        }

        return $this->toCollection();
    }

    /**
     * The items in iteration order, for json_encode() and JSON responses.
     *
     * Keys are positions, as when iterating; before 1.17.0 json_encode()
     * produced "{}" and a controller could not return the collection.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return \iterator_to_array($this);
    }

    /**
     * Read one chunk from the cache.
     *
     * @param string $chunkKey
     * @return array
     * @throws \RuntimeException When the chunk is missing or not an array
     */
    protected function fetchChunk(string $chunkKey): array
    {
        $chunk = $this->cache->get($chunkKey);

        if (!\is_array($chunk)) {
            throw new \RuntimeException("Missing cache chunk [{$chunkKey}] while reading a lazily loaded value");
        }

        return $chunk;
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

        // Negative arguments count from the end, as in array_slice(); they
        // used to produce out-of-range positions that read as null.
        if ($offset < 0) {
            $offset = \max(0, $this->totalItems + $offset);
        }

        if ($length === null) {
            $end = $this->totalItems;
        } elseif ($length < 0) {
            $end = $this->totalItems + $length;
        } else {
            $end = $offset + $length;
        }

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
            $chunk = $this->fetchChunk($this->chunkKeys[$chunkIndex]);

            // ChunkingStrategy chunks with preserve_keys, so chunk N is keyed by
            // the original offsets N*size … (N+1)*size-1 (or by the original string
            // keys). Access here is purely positional — key() yields the ordinal
            // position — so index the chunk by position, not by original key.
            $this->currentChunk = \array_values($chunk);
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
