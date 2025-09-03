<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use SmartCache\Contracts\SmartCache;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'smart-cache:status {--force : Include Laravel cache analysis and orphaned SmartCache keys}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display information about SmartCache usage and configuration. Use --force to include Laravel cache analysis.';

    /**
     * Execute the console command.
     */
    public function handle(SmartCache $cache, ConfigRepository $config): int
    {
        $keys = $cache->getManagedKeys();
        $count = count($keys);
        $force = $this->option('force');
        
        $this->info('SmartCache Status');
        $this->line('----------------');
        $this->line('');
        
        // Display driver information
        $this->line('Cache Driver: ' . Cache::getDefaultDriver());
        $this->line('');
        
        // Display managed keys count
        $this->line("Managed Keys: {$count}");
        
        // If there are keys, show some examples
        if ($count > 0) {
            $this->line('');
            $this->line('Examples:');
            
            $sampleKeys = array_slice($keys, 0, min(5, $count));
            
            foreach ($sampleKeys as $key) {
                $this->line(" - {$key}");
            }
            
            if ($count > 5) {
                $this->line(" - ... and " . ($count - 5) . " more");
            }
        }
        
        // Force option: Scan for orphaned SmartCache keys
        if ($force) {
            $this->line('');
            $this->line('Laravel Cache Analysis (--force):');
            $this->line('-----------------------------------');
            
            $nonManagedKeys = $this->findAllNonManagedKeys($cache);
            
            if (!empty($nonManagedKeys)) {
                $this->line('');
                $this->warn("Found " . count($nonManagedKeys) . " non-managed Laravel cache keys:");
                
                $sampleNonManaged = array_slice($nonManagedKeys, 0, min(10, count($nonManagedKeys)));
                foreach ($sampleNonManaged as $key) {
                    $this->line(" ! {$key}");
                }
                
                if (count($nonManagedKeys) > 10) {
                    $this->line(" ! ... and " . (count($nonManagedKeys) - 10) . " more");
                }
                
                $this->line('');
                $this->comment('These keys are stored in Laravel cache but not managed by SmartCache.');
                $this->comment('Consider running: php artisan smart-cache:clear --force');
            } else {
                $this->line('');
                $this->info('✓ No non-managed cache keys found.');
            }
            
            // Check if managed keys actually exist in cache
            $missingKeys = [];
            foreach ($keys as $key) {
                if (!$cache->has($key)) {
                    $missingKeys[] = $key;
                }
            }
            
            if (!empty($missingKeys)) {
                $this->line('');
                $this->warn("Found " . count($missingKeys) . " managed keys that no longer exist in cache:");
                
                $sampleMissing = array_slice($missingKeys, 0, min(5, count($missingKeys)));
                foreach ($sampleMissing as $key) {
                    $this->line(" ? {$key}");
                }
                
                if (count($missingKeys) > 5) {
                    $this->line(" ? ... and " . (count($missingKeys) - 5) . " more");
                }
                
                $this->line('');
                $this->comment('These managed keys no longer exist in the cache store.');
            } else if ($count > 0) {
                $this->line('');
                $this->info('✓ All managed keys exist in cache.');
            }
        }
        
        // Display configuration
        $configData = $config->get('smart-cache');
        $this->line('');
        $this->line('Configuration:');
        $this->line(' - Compression: ' . ($configData['strategies']['compression']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . number_format($configData['thresholds']['compression'] / 1024, 2) . ' KB');
        $this->line('   * Level: ' . $configData['strategies']['compression']['level']);
        $this->line(' - Chunking: ' . ($configData['strategies']['chunking']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . number_format($configData['thresholds']['chunking'] / 1024, 2) . ' KB');
        $this->line('   * Chunk Size: ' . $configData['strategies']['chunking']['chunk_size'] . ' items');
        
        return 0;
    }
    
    /**
     * Find all non-managed keys in the cache.
     */
    protected function findAllNonManagedKeys(SmartCache $cache): array
    {
        $repository = $cache->store();
        $store = $repository->getStore();
        $managedKeys = $cache->getManagedKeys();
        $nonManagedKeys = [];
        
        try {
            $allKeys = $this->getAllCacheKeys($store);
            
            foreach ($allKeys as $key) {
                // Check if key is not managed by SmartCache and not a SmartCache internal key
                if (!in_array($key, $managedKeys) && !$this->isSmartCacheInternalKey($key)) {
                    $nonManagedKeys[] = $key;
                }
            }
            
            return $nonManagedKeys;
        } catch (\Exception $e) {
            // If we can't scan keys, return empty array
            return [];
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
        
        // Array store (used in testing)
        if (str_contains($storeClass, 'ArrayStore')) {
            return array_keys($store->all(false)); // false to avoid unserializing values
        }
        
        // For other drivers, we can't easily get all keys
        // This is a limitation of Laravel's cache abstraction
        throw new \Exception('Cannot enumerate keys for this cache driver');
    }

    /**
     * Check if a key is a SmartCache internal key.
     */
    protected function isSmartCacheInternalKey(string $key): bool
    {
        // Look for SmartCache-specific patterns (internal keys that shouldn't be shown as "non-managed")
        return str_contains($key, '_sc_') || 
               str_contains($key, '_sc_meta') || 
               str_contains($key, '_sc_chunk_') ||
               $key === '_sc_managed_keys';
    }

} 