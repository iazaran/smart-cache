<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;

/**
 * Pre-warm the cache using registered warmers.
 */
class WarmCacheCommand extends Command
{
    protected $signature = 'smart-cache:warm 
                            {--warmer=* : Specific warmer(s) to run}
                            {--list : List available warmers}';

    protected $description = 'Pre-warm the cache using registered warmers';

    public function handle(): int
    {
        $warmers = config('smart-cache.warmers', []);

        if ($this->option('list')) {
            return $this->listWarmers($warmers);
        }

        if (empty($warmers)) {
            $this->warn('No cache warmers configured. Add warmers to config/smart-cache.php');
            $this->newLine();
            $this->line('Example configuration:');
            $this->line("'warmers' => [");
            $this->line("    'users' => App\\CacheWarmers\\UserCacheWarmer::class,");
            $this->line("    'products' => App\\CacheWarmers\\ProductCacheWarmer::class,");
            $this->line('],');
            return self::SUCCESS;
        }

        $selectedWarmers = $this->option('warmer');
        if (!empty($selectedWarmers)) {
            $warmers = \array_filter(
                $warmers,
                fn($key) => \in_array($key, $selectedWarmers, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        $this->info('Starting cache warming...');
        $this->newLine();

        $totalKeys = 0;
        $totalTime = 0.0;

        foreach ($warmers as $name => $warmerClass) {
            $this->line("Running warmer: <comment>{$name}</comment>");

            try {
                $startTime = \microtime(true);
                $warmer = app($warmerClass);

                if (!\method_exists($warmer, 'warm')) {
                    $this->error("  Warmer {$warmerClass} must have a warm() method");
                    continue;
                }

                $result = $warmer->warm();
                $elapsed = \round((\microtime(true) - $startTime) * 1000, 2);
                $totalTime += $elapsed;

                $keysWarmed = $result['keys'] ?? 0;
                $totalKeys += $keysWarmed;

                $this->info("  ✓ Warmed {$keysWarmed} keys in {$elapsed}ms");
            } catch (\Throwable $e) {
                $this->error('  ✗ Failed: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Cache warming complete!');
        $this->line("  Total keys warmed: <comment>{$totalKeys}</comment>");
        $this->line("  Total time: <comment>{$totalTime}ms</comment>");

        return self::SUCCESS;
    }

    protected function listWarmers(array $warmers): int
    {
        if (empty($warmers)) {
            $this->warn('No cache warmers configured.');
            return self::SUCCESS;
        }

        $this->info('Available cache warmers:');
        $this->newLine();

        $rows = [];
        foreach ($warmers as $name => $class) {
            $rows[] = [$name, $class];
        }

        $this->table(['Name', 'Class'], $rows);

        return self::SUCCESS;
    }
}

