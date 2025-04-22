<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use SmartCache\Contracts\SmartCache;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'smart-cache:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display information about SmartCache usage and configuration';

    /**
     * Execute the console command.
     */
    public function handle(SmartCache $cache): int
    {
        $keys = $cache->getManagedKeys();
        $count = count($keys);
        
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
        
        // Display configuration
        $config = config('smart-cache');
        $this->line('');
        $this->line('Configuration:');
        $this->line(' - Compression: ' . ($config['strategies']['compression']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . number_format($config['thresholds']['compression'] / 1024, 2) . ' KB');
        $this->line('   * Level: ' . $config['strategies']['compression']['level']);
        $this->line(' - Chunking: ' . ($config['strategies']['chunking']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . number_format($config['thresholds']['chunking'] / 1024, 2) . ' KB');
        $this->line('   * Chunk Size: ' . $config['strategies']['chunking']['chunk_size'] . ' items');
        
        return 0;
    }
} 