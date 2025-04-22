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
    protected $signature = 'smart-cache:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all SmartCache managed items';

    /**
     * Execute the console command.
     */
    public function handle(SmartCache $cache): int
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