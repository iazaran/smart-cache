<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use SmartCache\Facades\SmartCache;

/**
 * Clean up orphan cache chunks whose main keys have expired.
 */
class CleanupChunksCommand extends Command
{
    protected $signature = 'smart-cache:cleanup-chunks';
    protected $description = 'Clean up orphan cache chunks whose main keys have expired';

    public function handle(): int
    {
        $this->info('Cleaning up orphan cache chunks...');

        try {
            $stats = SmartCache::cleanupOrphanChunks();

            $this->info('Orphan cleanup complete:');
            $this->line("  - Orphaned main keys found: {$stats['orphaned_main_keys']}");
            $this->line("  - Chunks cleaned: {$stats['cleaned_chunks']}");
            $this->line("  - Remaining tracked keys: {$stats['remaining_tracked_keys']}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to cleanup chunks: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

