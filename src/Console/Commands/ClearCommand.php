<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use SmartCache\Contracts\SmartCache;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'smart-cache:clear {key? : The specific cache key to clear} {--force : Force clear keys even if not managed by SmartCache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear SmartCache managed items. Optionally specify a key to clear only that item. Use --force to clear keys even if not managed by SmartCache.';

    /**
     * Execute the console command.
     */
    public function handle(SmartCache $cache): int
    {
        $specificKey = $this->argument('key');
        
        if ($specificKey) {
            return $this->clearSpecificKey($cache, $specificKey);
        }
        
        return $this->clearAllKeys($cache);
    }
    
    /**
     * Clear a specific cache key.
     */
    protected function clearSpecificKey(SmartCache $cache, string $key): int
    {
        $managedKeys = $cache->getManagedKeys();
        $isManaged = in_array($key, $managedKeys);
        $force = $this->option('force');
        
        // Check if key exists in cache (either managed or regular Laravel cache)
        $keyExists = $cache->has($key);
        
        if (!$isManaged && !$force) {
            if ($keyExists) {
                $this->error("Cache key '{$key}' exists but is not managed by SmartCache. Use --force to clear it anyway.");
            } else {
                $this->error("Cache key '{$key}' is not managed by SmartCache or does not exist.");
            }
            return 1;
        }
        
        if (!$keyExists) {
            $this->error("Cache key '{$key}' does not exist.");
            return 1;
        }
        
        if ($isManaged) {
            $this->info("Clearing SmartCache managed item with key '{$key}'...");
            $success = $cache->forget($key);
        } else {
            $this->info("Clearing cache item with key '{$key}' (not managed by SmartCache)...");
            // Use the underlying Laravel cache to clear non-managed keys
            $success = $cache->store()->forget($key);
        }
        
        if ($success) {
            $this->info("Cache key '{$key}' has been cleared successfully.");
            return 0;
        } else {
            $this->error("Failed to clear cache key '{$key}'.");
            return 1;
        }
    }
    
    /**
     * Clear all SmartCache managed keys.
     */
    protected function clearAllKeys(SmartCache $cache): int
    {
        $keys = $cache->getManagedKeys();
        $count = count($keys);
        $force = $this->option('force');
        
        if ($count === 0) {
            if ($force) {
                $this->info('No SmartCache managed items found. Checking for orphaned SmartCache keys...');
                return $this->clearOrphanedKeys($cache);
            } else {
                $this->info('No SmartCache managed items found.');
                return 0;
            }
        }
        
        $this->info("Clearing {$count} SmartCache managed items...");
        
        $success = $cache->clear();
        
        if ($success) {
            $this->info('All SmartCache managed items have been cleared successfully.');
            
            if ($force) {
                $this->info('Checking for orphaned SmartCache keys...');
                $this->clearOrphanedKeys($cache);
            }
            
            return 0;
        } else {
            $this->error('Some SmartCache items could not be cleared.');
            return 1;
        }
    }
    
    /**
     * Clear orphaned SmartCache keys (chunk keys, meta keys, etc.).
     */
    protected function clearOrphanedKeys(SmartCache $cache): int
    {
        $store = $cache->store();
        $cleared = 0;
        
        // Try to get all cache keys (this varies by cache driver)
        try {
            $allKeys = $this->getAllCacheKeys($store);
            
            foreach ($allKeys as $key) {
                // Look for SmartCache-related patterns
                if ($this->isSmartCacheRelatedKey($key)) {
                    if ($store->forget($key)) {
                        $cleared++;
                        $this->line("Cleared orphaned key: {$key}");
                    }
                }
            }
            
            if ($cleared > 0) {
                $this->info("Cleared {$cleared} orphaned SmartCache-related keys.");
            } else {
                $this->info('No orphaned SmartCache keys found.');
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->warn('Could not scan for orphaned keys with this cache driver. Only managed keys were cleared.');
            return 0;
        }
    }
    
    /**
     * Get all cache keys (driver-dependent).
     */
    protected function getAllCacheKeys($store): array
    {
        $storeClass = get_class($store);
        
        // Redis store
        if (str_contains($storeClass, 'Redis')) {
            return $store->connection()->keys('*');
        }
        
        // For other drivers, we can't easily get all keys
        // This is a limitation of Laravel's cache abstraction
        throw new \Exception('Cannot enumerate keys for this cache driver');
    }
    
    /**
     * Check if a key is SmartCache-related.
     */
    protected function isSmartCacheRelatedKey(string $key): bool
    {
        // Look for SmartCache-specific patterns
        return str_contains($key, '_sc_') || 
               str_contains($key, '_sc_meta') || 
               str_contains($key, '_sc_chunk_') ||
               $key === '_sc_managed_keys';
    }
} 