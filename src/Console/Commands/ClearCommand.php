<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use SmartCache\Contracts\SmartCache;
use SmartCache\Console\Concerns\EnumeratesCacheKeys;

/**
 * Clear SmartCache managed items.
 */
class ClearCommand extends Command
{
    use EnumeratesCacheKeys;

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
        $isManaged = isset($this->managedKeySet($cache->getManagedKeys())[$key]);
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
            $success = $cache->store()->forget($key);
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

            // --force also clears non-managed keys, as it does when the index
            // is empty to begin with; this branch used to skip them.
            if ($force) {
                $this->info('Checking for non-managed cache keys...');
                return $this->clearOrphanedKeys($cache);
            }

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
        $store = $cache->getStore();
        $cleared = 0;
        $failed = 0;
        $managedKeys = $this->managedKeySet($cache->getManagedKeys());

        try {
            $allKeys = $this->getAllCacheKeys($store);
        } catch (\Exception) {
            $this->warn('Could not scan for non-managed keys with this cache driver. Only managed keys were cleared.');
            return self::SUCCESS;
        }

        foreach ($allKeys as $key) {
            $key = (string) $key;

            if (isset($managedKeys[$key]) || $this->isSmartCacheInternalKey($key)) {
                continue;
            }

            // One key that cannot be read or deleted (such as a Redis tag set
            // of Laravel's own tagged cache, which is not a string value) no
            // longer stops the keys after it from being cleared.
            try {
                if ($cache->store()->forget($key)) {
                    $cleared++;
                    $this->line("Cleared key: {$key}");
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        if ($cleared > 0) {
            $this->info("Cleared {$cleared} non-managed cache keys.");
        } else {
            $this->info('No non-managed cache keys found.');
        }

        if ($failed > 0) {
            $this->warn("{$failed} non-managed cache keys could not be cleared.");
        }

        return self::SUCCESS;
    }
}
