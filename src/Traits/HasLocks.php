<?php

namespace SmartCache\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

trait HasLocks
{
    /**
     * Get a lock instance.
     *
     * @param string $name
     * @param int $seconds
     * @param string|null $owner
     * @return Lock
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        $store = $this->cache->getStore();
        
        if (!$store instanceof LockProvider) {
            throw new \RuntimeException(
                "Cache store [" . \get_class($store) . "] does not support atomic locks. " .
                "Please use a cache driver that implements LockProvider (redis, memcached, dynamodb, database, file, array)."
            );
        }
        
        return $store->lock($name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param string $name
     * @param string $owner
     * @return Lock
     */
    public function restoreLock(string $name, string $owner): Lock
    {
        $store = $this->cache->getStore();
        
        if (!$store instanceof LockProvider) {
            throw new \RuntimeException(
                "Cache store [" . \get_class($store) . "] does not support atomic locks."
            );
        }
        
        return $store->restoreLock($name, $owner);
    }
}

