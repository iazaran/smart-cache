<?php

namespace SmartCache\Console\Concerns;

/**
 * Shared cache-key enumeration for the console commands.
 *
 * Previously duplicated verbatim in ClearCommand and StatusCommand.
 */
trait EnumeratesCacheKeys
{
    /**
     * List every key currently held by the store.
     *
     * @param object $store
     * @return array
     * @throws \Exception When the driver cannot be enumerated.
     */
    protected function getAllCacheKeys(object $store): array
    {
        $storeClass = \get_class($store);

        if (\str_contains($storeClass, 'Redis')) {
            return $this->scanRedisKeys($store->connection());
        }

        if (\str_contains($storeClass, 'ArrayStore')) {
            return $this->getArrayStoreKeys($store);
        }

        throw new \Exception('Cannot enumerate keys for this cache driver');
    }

    /**
     * Enumerate Redis keys with SCAN rather than KEYS.
     *
     * KEYS is O(N) and runs to completion on Redis' single-threaded event loop,
     * stalling every other client for the duration — on a multi-million-key
     * instance that is a production outage. SCAN walks the keyspace in small
     * batches instead and yields between them.
     *
     * The match pattern is '*', which is also SCAN's default, so a driver that
     * ignores the options array still returns the same key set. Anything
     * unexpected — no scan(), an unrecognised return shape, an exception —
     * falls back to the historical keys('*') call.
     *
     * @param object $connection
     * @return array
     */
    protected function scanRedisKeys(object $connection): array
    {
        if (!\method_exists($connection, 'scan') && !\method_exists($connection, '__call')) {
            return $connection->keys('*');
        }

        $keys = [];
        $cursor = 0;
        // Backstop: a driver that never returns a zero cursor must not spin forever.
        $iterations = 0;

        try {
            do {
                $result = $connection->scan($cursor, ['match' => '*', 'count' => 1000]);

                // phpredis' Laravel wrapper signals "iteration complete" with false.
                if ($result === false || $result === null) {
                    break;
                }

                if (!\is_array($result) || \count($result) !== 2 || !\is_array($result[1])) {
                    return $connection->keys('*');
                }

                [$cursor, $batch] = $result;

                foreach ($batch as $key) {
                    // SCAN may return the same key more than once.
                    $keys[$key] = true;
                }
            } while ((int) $cursor !== 0 && ++$iterations < 100000);
        } catch (\Throwable $e) {
            return $connection->keys('*');
        }

        return \array_keys($keys);
    }

    /**
     * @param object $store
     * @return array
     */
    protected function getArrayStoreKeys(object $store): array
    {
        if (\method_exists($store, 'all')) {
            return \array_keys($store->all(false));
        }

        try {
            $reflection = new \ReflectionClass($store);
            $storageProperty = $reflection->getProperty('storage');
            $storage = $storageProperty->getValue($store);

            return \array_keys($storage ?? []);
        } catch (\ReflectionException) {
            return [];
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function isSmartCacheInternalKey(string $key): bool
    {
        return \str_contains($key, '_sc_') ||
               \str_contains($key, '_sc_meta') ||
               \str_contains($key, '_sc_chunk_') ||
               $key === '_sc_managed_keys';
    }
}
