<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use SmartCache\Contracts\SmartCache;

/**
 * Clear SmartCache managed items.
 */
class ClearCommand extends Command
{
    protected $signature = 'smart-cache:clear {key? : The specific cache key to clear} {--force : Force clear keys even if not managed by SmartCache}';
    protected $description = 'Clear SmartCache managed items. Optionally specify a key to clear only that item.';

    public function handle(SmartCache $cache): int
    {
        $specificKey = $this->argument('key');

        if ($specificKey) {
            return $this->clearSpecificKey($cache, $specificKey);
        }

        return $this->clearAllKeys($cache);
    }

    protected function clearSpecificKey(SmartCache $cache, string $key): int
    {
        $managedKeys = $cache->getManagedKeys();
        $isManaged = \in_array($key, $managedKeys, true);
        $force = $this->option('force');
        $keyExists = $cache->has($key);

        if (!$isManaged && !$force) {
            if ($keyExists) {
                $this->error("Cache key '{$key}' exists but is not managed by SmartCache. Use --force to clear it anyway.");
            } else {
                $this->error("Cache key '{$key}' is not managed by SmartCache or does not exist.");
            }
            return self::FAILURE;
        }

        if (!$keyExists) {
            $this->error("Cache key '{$key}' does not exist.");
            return self::FAILURE;
        }

        if ($isManaged) {
            $this->info("Clearing SmartCache managed item with key '{$key}'...");
            $success = $cache->forget($key);
        } else {
            $this->info("Clearing cache item with key '{$key}' (not managed by SmartCache)...");
            $success = $cache->repository()->forget($key);
        }

        if ($success) {
            $this->info("Cache key '{$key}' has been cleared successfully.");
            return self::SUCCESS;
        }

        $this->error("Failed to clear cache key '{$key}'.");
        return self::FAILURE;
    }

    protected function clearAllKeys(SmartCache $cache): int
    {
        $keys = $cache->getManagedKeys();
        $count = \count($keys);
        $force = $this->option('force');

        if ($count === 0) {
            if ($force) {
                $this->info('No SmartCache managed items found. Checking for non-managed cache keys...');
                return $this->clearOrphanedKeys($cache);
            }
            $this->info('No SmartCache managed items found.');
            return self::SUCCESS;
        }

        $this->info("Clearing {$count} SmartCache managed items...");

        $expiredCleaned = $cache->cleanupExpiredManagedKeys();
        if ($expiredCleaned > 0) {
            $this->info("Cleaned up {$expiredCleaned} expired keys from tracking list.");
        }

        $keys = $cache->getManagedKeys();
        $actualCount = \count($keys);

        if ($actualCount === 0) {
            $this->info('All managed keys were expired and have been cleaned up.');
            return self::SUCCESS;
        }

        $this->info("Clearing {$actualCount} active SmartCache managed items...");

        $success = $cache->clear();

        if ($success) {
            $this->info('All SmartCache managed items have been cleared successfully.');
            if ($force) {
                $this->info('Checking for non-managed cache keys...');
                $this->clearOrphanedKeys($cache);
            }
            return self::SUCCESS;
        }

        $this->error('Some SmartCache items could not be cleared.');
        $this->comment('This may be due to cache driver limitations or permission issues.');
        return self::FAILURE;
    }

    protected function clearOrphanedKeys(SmartCache $cache): int
    {
        $repository = $cache->repository();
        $store = $repository->getStore();
        $cleared = 0;
        $managedKeys = $cache->getManagedKeys();

        try {
            $allKeys = $this->getAllCacheKeys($store);

            foreach ($allKeys as $key) {
                if (!\in_array($key, $managedKeys, true) && !$this->isSmartCacheInternalKey($key)) {
                    if ($repository->forget($key)) {
                        $cleared++;
                        $this->line("Cleared key: {$key}");
                    }
                }
            }

            if ($cleared > 0) {
                $this->info("Cleared {$cleared} non-managed cache keys.");
            } else {
                $this->info('No non-managed cache keys found.');
            }

            return self::SUCCESS;
        } catch (\Exception) {
            $this->warn('Could not scan for non-managed keys with this cache driver. Only managed keys were cleared.');
            return self::SUCCESS;
        }
    }

    protected function getAllCacheKeys(object $store): array
    {
        $storeClass = \get_class($store);

        if (\str_contains($storeClass, 'Redis')) {
            return $store->connection()->keys('*');
        }

        if (\str_contains($storeClass, 'ArrayStore')) {
            return $this->getArrayStoreKeys($store);
        }

        throw new \Exception('Cannot enumerate keys for this cache driver');
    }

    protected function getArrayStoreKeys(object $store): array
    {
        if (\method_exists($store, 'all')) {
            return \array_keys($store->all(false));
        }

        try {
            $reflection = new \ReflectionClass($store);
            $storageProperty = $reflection->getProperty('storage');
            $storageProperty->setAccessible(true);
            $storage = $storageProperty->getValue($store);

            return \array_keys($storage ?? []);
        } catch (\ReflectionException) {
            return [];
        }
    }

    protected function isSmartCacheInternalKey(string $key): bool
    {
        return \str_contains($key, '_sc_') ||
               \str_contains($key, '_sc_meta') ||
               \str_contains($key, '_sc_chunk_') ||
               $key === '_sc_managed_keys';
    }
}