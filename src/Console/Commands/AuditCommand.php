<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use SmartCache\Contracts\SmartCache;

/**
 * Audit SmartCache-managed entries without mutating the cache.
 */
class AuditCommand extends Command
{
    protected $signature = 'smart-cache:audit
                            {--format=table : Output format: table or json}
                            {--driver= : Cache store to audit, defaults to cache.default}
                            {--limit=20 : Maximum number of keys to show in tables}';

    protected $description = 'Audit SmartCache managed keys, chunk health, oversized entries, and eviction suggestions.';

    public function handle(SmartCache $cache, ConfigRepository $config): int
    {
        $targetCache = $this->option('driver') ? $cache->store((string) $this->option('driver')) : $cache;
        $report = $this->buildReport($targetCache, $config, (int) $this->option('limit'), $this->option('driver') ?: $config->get('cache.default', 'default'));

        if ($this->option('format') === 'json') {
            $this->line(\json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->displayReport($report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReport(SmartCache $cache, ConfigRepository $config, int $limit, string $driver): array
    {
        $limit = \max(1, $limit);
        $managedKeys = $cache->getManagedKeys();
        $thresholds = $config->get('smart-cache.thresholds', []);
        $largeThreshold = (int) ($thresholds['compression'] ?? 51200);

        $keys = [];
        $missingKeys = [];
        $brokenChunks = [];
        $largeUnoptimized = [];
        $referencedChunks = [];
        $optimizedCount = 0;
        $totalBytes = 0;

        foreach ($managedKeys as $key) {
            if (!$cache->has($key)) {
                $missingKeys[] = $key;
                $keys[] = [
                    'key' => $key,
                    'exists' => false,
                    'strategy' => 'missing',
                    'stored_bytes' => 0,
                    'chunks' => 0,
                    'missing_chunks' => [],
                ];
                continue;
            }

            $raw = $cache->getRaw($key);
            $strategy = $this->detectStrategy($raw);
            $storedBytes = $this->storedSize($cache, $raw);
            $totalBytes += $storedBytes;
            $chunks = $this->chunkKeys($raw);
            $missingChunks = [];

            if ($strategy !== 'none') {
                $optimizedCount++;
            }

            foreach ($chunks as $chunkKey) {
                $referencedChunks[$chunkKey] = true;
                if (!$cache->repository()->has($chunkKey)) {
                    $missingChunks[] = $chunkKey;
                }
            }

            if (!empty($missingChunks)) {
                $brokenChunks[] = [
                    'key' => $key,
                    'missing_chunks' => $missingChunks,
                ];
            }

            if ($strategy === 'none' && $storedBytes >= $largeThreshold) {
                $largeUnoptimized[] = [
                    'key' => $key,
                    'stored_bytes' => $storedBytes,
                ];
            }

            $keys[] = [
                'key' => $key,
                'exists' => true,
                'strategy' => $strategy,
                'stored_bytes' => $storedBytes,
                'chunks' => \count($chunks),
                'missing_chunks' => $missingChunks,
            ];
        }

        \usort($keys, fn (array $a, array $b) => $b['stored_bytes'] <=> $a['stored_bytes']);

        $orphanChunks = $this->findOrphanChunks($cache, \array_keys($referencedChunks));
        try {
            $evictionSuggestions = \array_slice($cache->suggestEvictions($limit), 0, $limit);
        } catch (\Throwable) {
            $evictionSuggestions = [];
        }
        $recommendations = $this->buildRecommendations($missingKeys, $brokenChunks, $orphanChunks, $largeUnoptimized, $evictionSuggestions);
        $health = $this->determineHealth($missingKeys, $brokenChunks, $orphanChunks, $largeUnoptimized);

        return [
            'health' => $health,
            'driver' => $driver,
            'summary' => [
                'managed_keys' => \count($managedKeys),
                'existing_keys' => \count($managedKeys) - \count($missingKeys),
                'missing_keys' => \count($missingKeys),
                'optimized_keys' => $optimizedCount,
                'broken_chunked_keys' => \count($brokenChunks),
                'orphan_chunks' => \count($orphanChunks),
                'large_unoptimized_keys' => \count($largeUnoptimized),
                'tracked_stored_bytes' => $totalBytes,
                'tracked_stored_human' => $this->formatBytes($totalBytes),
            ],
            'keys' => \array_slice($keys, 0, $limit),
            'missing_keys' => \array_slice($missingKeys, 0, $limit),
            'broken_chunks' => \array_slice($brokenChunks, 0, $limit),
            'orphan_chunks' => \array_slice($orphanChunks, 0, $limit),
            'large_unoptimized' => \array_slice($largeUnoptimized, 0, $limit),
            'eviction_suggestions' => $evictionSuggestions,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function displayReport(array $report): void
    {
        $this->info('SmartCache Audit');
        $this->line('----------------');
        $this->line('Health: ' . \strtoupper($report['health']));
        $this->line('Driver: ' . $report['driver']);
        $this->newLine();

        $summary = $report['summary'];
        $this->line('Managed keys: ' . $summary['managed_keys']);
        $this->line('Existing keys: ' . $summary['existing_keys']);
        $this->line('Missing keys: ' . $summary['missing_keys']);
        $this->line('Optimized keys: ' . $summary['optimized_keys']);
        $this->line('Broken chunked keys: ' . $summary['broken_chunked_keys']);
        $this->line('Orphan chunks: ' . $summary['orphan_chunks']);
        $this->line('Large unoptimized keys: ' . $summary['large_unoptimized_keys']);
        $this->line('Tracked stored size: ' . $summary['tracked_stored_human']);

        if (!empty($report['keys'])) {
            $this->newLine();
            $this->line('Largest managed keys:');
            $this->table(
                ['Key', 'Strategy', 'Stored Size', 'Chunks', 'Missing Chunks'],
                \array_map(fn (array $key) => [
                    $key['key'],
                    $key['strategy'],
                    $this->formatBytes((int) $key['stored_bytes']),
                    $key['chunks'],
                    \count($key['missing_chunks']),
                ], $report['keys'])
            );
        }

        if (!empty($report['broken_chunks'])) {
            $this->newLine();
            $this->warn('Broken chunked keys:');
            foreach ($report['broken_chunks'] as $broken) {
                $this->line(' - ' . $broken['key'] . ' missing ' . \count($broken['missing_chunks']) . ' chunk(s)');
            }
        }

        if (!empty($report['recommendations'])) {
            $this->newLine();
            $this->line('Recommendations:');
            foreach ($report['recommendations'] as $recommendation) {
                $this->line(' - ' . $recommendation);
            }
        }
    }

    protected function detectStrategy(mixed $raw): string
    {
        if (\is_array($raw) && ($raw['_sc_chunked'] ?? false) === true) {
            return 'chunking';
        }

        if (\is_array($raw) && ($raw['_sc_compressed'] ?? false) === true) {
            return 'compression';
        }

        if (\is_array($raw) && ($raw['_sc_serialized'] ?? false) === true) {
            return 'serialization';
        }

        if (\is_array($raw) && ($raw['_sc_encrypted'] ?? false) === true) {
            return 'encryption';
        }

        return 'none';
    }

    /**
     * @return array<int, string>
     */
    protected function chunkKeys(mixed $raw): array
    {
        if (!\is_array($raw) || ($raw['_sc_chunked'] ?? false) !== true) {
            return [];
        }

        return \array_values($raw['chunk_keys'] ?? []);
    }

    protected function storedSize(SmartCache $cache, mixed $raw): int
    {
        $size = $this->valueSize($raw);

        foreach ($this->chunkKeys($raw) as $chunkKey) {
            $chunk = $cache->repository()->get($chunkKey);
            if ($chunk !== null) {
                $size += $this->valueSize($chunk);
            }
        }

        return $size;
    }

    protected function valueSize(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (\is_string($value)) {
            return \strlen($value);
        }

        return \strlen(\serialize($value));
    }

    /**
     * @param array<int, string> $referencedChunkKeys
     * @return array<int, string>
     */
    protected function findOrphanChunks(SmartCache $cache, array $referencedChunkKeys): array
    {
        try {
            $allKeys = $this->getAllCacheKeys($cache->getStore());
        } catch (\Throwable) {
            return [];
        }

        $referenced = \array_fill_keys($referencedChunkKeys, true);
        $orphans = [];

        foreach ($allKeys as $key) {
            if (\str_starts_with($key, '_sc_chunk_') && !isset($referenced[$key])) {
                $orphans[] = $key;
            }
        }

        return $orphans;
    }

    /**
     * @return array<int, string>
     */
    protected function getAllCacheKeys(object $store): array
    {
        $storeClass = \get_class($store);

        if (\str_contains($storeClass, 'Redis')) {
            return $store->connection()->keys('*');
        }

        if (\str_contains($storeClass, 'ArrayStore')) {
            if (\method_exists($store, 'all')) {
                return \array_keys($store->all(false));
            }

            $reflection = new \ReflectionClass($store);
            $storageProperty = $reflection->getProperty('storage');
            $storageProperty->setAccessible(true);
            $storage = $storageProperty->getValue($store);

            return \array_keys($storage ?? []);
        }

        throw new \RuntimeException('Cannot enumerate keys for this cache driver');
    }

    /**
     * @param array<int, string> $missingKeys
     * @param array<int, array<string, mixed>> $brokenChunks
     * @param array<int, string> $orphanChunks
     * @param array<int, array<string, mixed>> $largeUnoptimized
     * @param array<int, array<string, mixed>> $evictionSuggestions
     * @return array<int, string>
     */
    protected function buildRecommendations(
        array $missingKeys,
        array $brokenChunks,
        array $orphanChunks,
        array $largeUnoptimized,
        array $evictionSuggestions
    ): array {
        $recommendations = [];

        if (!empty($missingKeys)) {
            $recommendations[] = 'Run smart-cache:clear to remove missing keys from the managed-key index.';
        }

        if (!empty($brokenChunks)) {
            $recommendations[] = 'Use remember() around large chunked values so missing chunks regenerate automatically.';
        }

        if (!empty($orphanChunks)) {
            $recommendations[] = 'Run smart-cache:cleanup-chunks to remove orphaned chunk entries.';
        }

        if (!empty($largeUnoptimized)) {
            $recommendations[] = 'Review large unoptimized keys; enable compression/chunking or lower thresholds if these values are read often.';
        }

        if (!empty($evictionSuggestions)) {
            $recommendations[] = 'Review low-value eviction suggestions before increasing cache storage.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'No immediate action required.';
        }

        return $recommendations;
    }

    /**
     * @param array<int, string> $missingKeys
     * @param array<int, array<string, mixed>> $brokenChunks
     * @param array<int, string> $orphanChunks
     * @param array<int, array<string, mixed>> $largeUnoptimized
     */
    protected function determineHealth(array $missingKeys, array $brokenChunks, array $orphanChunks, array $largeUnoptimized): string
    {
        if (!empty($missingKeys) || !empty($brokenChunks)) {
            return 'critical';
        }

        if (!empty($orphanChunks) || !empty($largeUnoptimized)) {
            return 'warning';
        }

        return 'good';
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return \round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return \round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
