<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use SmartCache\Contracts\SmartCache;

/**
 * Display SmartCache status and configuration.
 */
class StatusCommand extends Command
{
    protected $signature = 'smart-cache:status {--force : Include Laravel cache analysis and orphaned SmartCache keys}';
    protected $description = 'Display information about SmartCache usage and configuration.';

    public function handle(SmartCache $cache, ConfigRepository $config): int
    {
        $keys = $cache->getManagedKeys();
        $count = \count($keys);
        $force = $this->option('force');

        $this->info('SmartCache Status');
        $this->line('----------------');
        $this->newLine();
        $this->line('Cache Driver: ' . Cache::getDefaultDriver());
        $this->newLine();
        $this->line("Managed Keys: {$count}");

        if ($count > 0) {
            $this->newLine();
            $this->line('Examples:');
            $sampleKeys = \array_slice($keys, 0, \min(5, $count));
            foreach ($sampleKeys as $key) {
                $this->line(" - {$key}");
            }
            if ($count > 5) {
                $this->line(' - ... and ' . ($count - 5) . ' more');
            }
        }

        if ($force) {
            $this->displayForceAnalysis($cache, $keys, $count);
        }

        $this->displayConfiguration($config);

        return self::SUCCESS;
    }

    protected function displayForceAnalysis(SmartCache $cache, array $keys, int $count): void
    {
        $this->newLine();
        $this->line('Laravel Cache Analysis (--force):');
        $this->line('-----------------------------------');

        $nonManagedKeys = $this->findAllNonManagedKeys($cache);

        if (!empty($nonManagedKeys)) {
            $nonManagedCount = \count($nonManagedKeys);
            $this->newLine();
            $this->warn("Found {$nonManagedCount} non-managed Laravel cache keys:");

            $sampleNonManaged = \array_slice($nonManagedKeys, 0, \min(10, $nonManagedCount));
            foreach ($sampleNonManaged as $key) {
                $this->line(" ! {$key}");
            }

            if ($nonManagedCount > 10) {
                $this->line(' ! ... and ' . ($nonManagedCount - 10) . ' more');
            }

            $this->newLine();
            $this->comment('These keys are stored in Laravel cache but not managed by SmartCache.');
            $this->comment('Consider running: php artisan smart-cache:clear --force');
        } else {
            $this->newLine();
            $this->info('✓ No non-managed cache keys found.');
        }

        $missingKeys = [];
        foreach ($keys as $key) {
            if (!$cache->has($key)) {
                $missingKeys[] = $key;
            }
        }

        if (!empty($missingKeys)) {
            $missingCount = \count($missingKeys);
            $this->newLine();
            $this->warn("Found {$missingCount} managed keys that no longer exist in cache:");

            $sampleMissing = \array_slice($missingKeys, 0, \min(5, $missingCount));
            foreach ($sampleMissing as $key) {
                $this->line(" ? {$key}");
            }

            if ($missingCount > 5) {
                $this->line(' ? ... and ' . ($missingCount - 5) . ' more');
            }

            $this->newLine();
            $this->comment('These managed keys no longer exist in the cache store.');
        } elseif ($count > 0) {
            $this->newLine();
            $this->info('✓ All managed keys exist in cache.');
        }
    }

    protected function displayConfiguration(ConfigRepository $config): void
    {
        $configData = $config->get('smart-cache');
        $this->newLine();
        $this->line('Configuration:');
        $this->line(' - Compression: ' . ($configData['strategies']['compression']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . \number_format($configData['thresholds']['compression'] / 1024, 2) . ' KB');
        $this->line('   * Level: ' . $configData['strategies']['compression']['level']);
        $this->line(' - Chunking: ' . ($configData['strategies']['chunking']['enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('   * Threshold: ' . \number_format($configData['thresholds']['chunking'] / 1024, 2) . ' KB');
        $this->line('   * Chunk Size: ' . $configData['strategies']['chunking']['chunk_size'] . ' items');
    }

    protected function findAllNonManagedKeys(SmartCache $cache): array
    {
        $store = $cache->getStore();
        $managedKeys = $cache->getManagedKeys();
        $nonManagedKeys = [];

        try {
            $allKeys = $this->getAllCacheKeys($store);

            foreach ($allKeys as $key) {
                if (!\in_array($key, $managedKeys, true) && !$this->isSmartCacheInternalKey($key)) {
                    $nonManagedKeys[] = $key;
                }
            }

            return $nonManagedKeys;
        } catch (\Exception) {
            return [];
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