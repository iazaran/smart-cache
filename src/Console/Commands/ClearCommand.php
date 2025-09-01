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
    protected $signature = 'smart-cache:clear {key? : The specific cache key to clear}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear SmartCache managed items. Optionally specify a key to clear only that item.';

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
        
        if (!in_array($key, $managedKeys)) {
            $this->error("Cache key '{$key}' is not managed by SmartCache or does not exist.");
            return 1;
        }
        
        $this->info("Clearing SmartCache item with key '{$key}'...");
        
        $success = $cache->forget($key);
        
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
        
        if ($count === 0) {
            $this->info('No SmartCache managed items found.');
            return 0;
        }
        
        $this->info("Clearing {$count} SmartCache managed items...");
        
        $success = $cache->clear();
        
        if ($success) {
            $this->info('All SmartCache items have been cleared successfully.');
            return 0;
        } else {
            $this->error('Some SmartCache items could not be cleared.');
            return 1;
        }
    }
} 