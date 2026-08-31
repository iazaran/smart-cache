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
     * Cluster connections are scanned one master at a time. If a connection
     * cannot provide a complete, safe SCAN, enumeration fails rather than
     * falling back to the blocking KEYS command or returning a partial result.
     *
     * @param object $connection
     * @return array
     */
    protected function scanRedisKeys(object $connection): array
    {
        if ($this->isRedisClusterConnection($connection)) {
            return $this->scanRedisClusterKeys($connection);
        }

        return $this->scanRedisConnection($connection);
    }

    /**
     * Determine whether the connection represents a Redis cluster.
     *
     * @param object $connection
     * @return bool
     */
    protected function isRedisClusterConnection(object $connection): bool
    {
        if (\method_exists($connection, 'isCluster')) {
            return (bool) $connection->isCluster();
        }

        return \str_contains(\get_class($connection), 'Cluster');
    }

    /**
     * Scan every master exposed by Laravel's PhpRedis or Predis cluster clients.
     *
     * @param object $connection
     * @return array
     */
    protected function scanRedisClusterKeys(object $connection): array
    {
        if (!\method_exists($connection, 'client')) {
            throw new \RuntimeException('Cannot safely enumerate this Redis cluster connection');
        }

        try {
            $client = $connection->client();
            $keys = [];

            // PhpRedisClusterConnection accepts a master node in the SCAN options.
            if (\is_object($client) && \method_exists($client, '_masters')) {
                $masters = $client->_masters();

                if (!\is_array($masters) || $masters === []) {
                    throw new \RuntimeException('Redis cluster did not expose any master nodes');
                }

                if (!\method_exists($client, 'scan')) {
                    throw new \RuntimeException('PhpRedis cluster client does not support SCAN');
                }

                foreach ($masters as $master) {
                    $scanner = static function ($cursor) use ($client, $master) {
                        $batch = $client->scan($cursor, $master, '*', 1000);

                        if ($batch === false) {
                            $batch = [];
                        }

                        return (int) $cursor === 0 && $batch === []
                            ? false
                            : [$cursor, $batch];
                    };

                    foreach ($this->scanRedisUsing($scanner) as $key) {
                        $keys[$key] = true;
                    }
                }

                return \array_keys($keys);
            }

            // Predis' cluster client is iterable and each yielded node supports SCAN.
            if (\is_iterable($client)) {
                $nodesScanned = 0;

                foreach ($client as $node) {
                    if (!\is_object($node)) {
                        throw new \RuntimeException('Redis cluster exposed an invalid node');
                    }

                    foreach ($this->scanRedisConnection($node) as $key) {
                        $keys[$key] = true;
                    }

                    $nodesScanned++;
                }

                if ($nodesScanned === 0) {
                    throw new \RuntimeException('Redis cluster did not expose any nodes');
                }

                return \array_keys($keys);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot safely enumerate this Redis cluster connection', 0, $e);
        }

        throw new \RuntimeException('Cannot safely enumerate this Redis cluster connection');
    }

    /**
     * Scan one Redis connection or cluster node.
     *
     * @param object $connection
     * @param array $options
     * @return array
     */
    protected function scanRedisConnection(object $connection, array $options = []): array
    {
        if (!\method_exists($connection, 'scan') && !\method_exists($connection, '__call')) {
            throw new \RuntimeException('Redis connection does not support SCAN');
        }

        $scanOptions = \array_merge([
            'match' => '*',
            'count' => 1000,
        ], $options);

        return $this->scanRedisUsing(
            static fn ($cursor) => $connection->scan($cursor, $scanOptions)
        );
    }

    /**
     * Run and validate a complete cursor-based Redis scan.
     *
     * @param \Closure $scanner
     * @return array
     */
    protected function scanRedisUsing(\Closure $scanner): array
    {
        $keys = [];
        $cursor = 0;
        // Backstop: a driver that never returns a zero cursor must not spin forever.
        $iterations = 0;

        try {
            do {
                if (++$iterations > 100000) {
                    throw new \RuntimeException('Redis SCAN did not complete after 100000 iterations');
                }

                $result = $scanner($cursor);

                // phpredis' Laravel wrapper signals "iteration complete" with false.
                if ($result === false || $result === null) {
                    break;
                }

                if (!\is_array($result) || \count($result) !== 2 || !\is_array($result[1])) {
                    throw new \RuntimeException('Redis SCAN returned an unexpected result');
                }

                [$cursor, $batch] = $result;

                foreach ($batch as $key) {
                    // SCAN may return the same key more than once.
                    $keys[$key] = true;
                }
            } while ((int) $cursor !== 0);
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException('Redis SCAN failed', 0, $e);
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
