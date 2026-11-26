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
        $offset = $this->normalizeOffset($offset);

        return is_int($offset) && $offset >= 0 && $offset < $this->totalItems;
    }

    /**
     * An integer string such as '5' as the integer 5, as a PHP array reads
     * it. $collection['5'] used to read as null.
     *
     * @param mixed $offset
     * @return mixed
     */
    private function normalizeOffset(mixed $offset): mixed
    {
        if (\is_string($offset) && (string) (int) $offset === $offset) {
            return (int) $offset;
        }

        return $offset;
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        $offset = $this->normalizeOffset($offset);

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
     * A copy that holds every chunk in memory, so it stays readable after the
     * entry it was read from is deleted or replaced, which deletes the chunks.
     *
     * Loads every chunk, as materialize() does, but keeps the collection's
     * type and methods for callers that use them.
     *
     * @internal SmartCache uses it where it deletes the chunks itself.
     *
     * @return static
     * @throws \RuntimeException When a chunk is missing or not an array
     */
    public function detached(): static
    {
        $repository = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());

        foreach ($this->chunkKeys as $chunkKey) {
            $repository->forever($chunkKey, $this->fetchChunk((string) $chunkKey));
        }

        $copy = clone $this;
        $copy->cache = $repository;
        $copy->position = 0;
        $copy->currentChunk = null;
        $copy->currentChunkIndex = -1;
        $copy->loadedChunks = [];

        return $copy;
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
        $result = [];

        for ($i = 0; $i < $this->totalItems; $i++) {
            $result[$i] = $this->offsetGet($i);
        }

        return $result;
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
     * Every item is visited, whatever the callback returns. Unlike
     * Illuminate\Support\Collection::each(), returning false does not stop
     * it; 1.x keeps it that way, so a callback that returns false by chance
     * never skips items. Use foreach with break to stop early.
     *
     * each(), filter(), map(), and jsonSerialize() read items by position
     * rather than with foreach ($this), which rewound the collection: called
     * inside a foreach over the same collection, they ended that loop after
     * its first item.
     *
     * @param callable $callback
     * @return void
     */
    public function each(callable $callback): void
    {
        for ($i = 0; $i < $this->totalItems; $i++) {
            $callback($this->offsetGet($i), $i);
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

        for ($i = 0; $i < $this->totalItems; $i++) {
            $item = $this->offsetGet($i);

            if ($callback($item, $i)) {
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

        for ($i = 0; $i < $this->totalItems; $i++) {
            $result[] = $callback($this->offsetGet($i), $i);
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
