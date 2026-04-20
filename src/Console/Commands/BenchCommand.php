<?php

namespace SmartCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use SmartCache\Contracts\SmartCache;

/**
 * Benchmark SmartCache optimization against raw Laravel cache writes.
 */
class BenchCommand extends Command
{
    protected $signature = 'smart-cache:bench
                            {--profile=all : Benchmark profile: all, medium, api-json, large-array, sparse-array}
                            {--driver= : Cache store to benchmark, defaults to cache.default}
                            {--iterations=3 : Number of iterations per profile}
                            {--format=table : Output format: table or json}
                            {--output= : Write JSON report to a file path}';

    protected $description = 'Benchmark SmartCache compression and chunking against raw Laravel cache operations.';

    public function handle(SmartCache $cache, ConfigRepository $config): int
    {
        $driver = $this->option('driver') ?: $config->get('cache.default', 'default');
        $iterations = \max(1, (int) $this->option('iterations'));
        $profile = (string) $this->option('profile');
        $targetCache = $this->option('driver') ? $cache->store((string) $this->option('driver')) : $cache;

        $report = [
            'generated_at' => \date('c'),
            'environment' => [
                'php' => PHP_VERSION,
                'laravel' => \function_exists('app') && \method_exists(app(), 'version') ? app()->version() : null,
                'driver' => $driver,
                'iterations' => $iterations,
                'note' => 'Local benchmark results depend on hardware, cache driver, serialization settings, and payload shape.',
            ],
            'profiles' => [],
        ];

        foreach ($this->selectedProfiles($profile) as $profileName) {
            $report['profiles'][] = $this->runProfile($targetCache, $profileName, $iterations);
        }

        $output = $this->option('output');
        if ($output) {
            \file_put_contents((string) $output, \json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($this->option('format') === 'json') {
            $this->line(\json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->displayReport($report);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function selectedProfiles(string $profile): array
    {
        $profiles = ['medium', 'api-json', 'large-array', 'sparse-array'];

        if ($profile === 'all') {
            return $profiles;
        }

        if (!\in_array($profile, $profiles, true)) {
            throw new \InvalidArgumentException("Unknown benchmark profile [{$profile}]");
        }

        return [$profile];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runProfile(SmartCache $cache, string $profile, int $iterations): array
    {
        $payload = $this->payloadFor($profile);
        $rawWriteTimes = [];
        $rawReadTimes = [];
        $smartWriteTimes = [];
        $smartReadTimes = [];
        $rawBytes = 0;
        $smartBytes = 0;
        $strategy = 'none';
        $chunks = 0;
        $integrity = true;
        $keyShapePreserved = true;

        for ($i = 0; $i < $iterations; $i++) {
            $rawKey = "_sc_bench_raw:{$profile}:{$i}:" . \bin2hex(\random_bytes(4));
            $smartKey = "_sc_bench:{$profile}:{$i}:" . \bin2hex(\random_bytes(4));

            try {
                $start = \microtime(true);
                $cache->repository()->put($rawKey, $payload, 300);
                $rawWriteTimes[] = $this->elapsedMs($start);

                $start = \microtime(true);
                $rawValue = $cache->repository()->get($rawKey);
                $rawReadTimes[] = $this->elapsedMs($start);

                $start = \microtime(true);
                $cache->put($smartKey, $payload, 300);
                $smartWriteTimes[] = $this->elapsedMs($start);

                $rawStored = $cache->repository()->get($rawKey);
                $smartStored = $cache->getRaw($smartKey);
                $rawBytes = $this->valueSize($rawStored);
                $smartBytes = $this->storedSize($cache, $smartStored);
                $strategy = $this->detectStrategy($smartStored);
                $chunks = \count($this->chunkKeys($smartStored));

                $start = \microtime(true);
                $smartValue = $cache->get($smartKey);
                $smartReadTimes[] = $this->elapsedMs($start);

                $integrity = $integrity && $rawValue === $payload && $smartValue === $payload;
                $keyShapePreserved = $keyShapePreserved && $this->keysMatch($payload, $smartValue);
            } finally {
                $cache->repository()->forget($rawKey);
                $cache->forget($smartKey);
            }
        }

        return [
            'profile' => $profile,
            'description' => $this->profileDescription($profile),
            'goal' => $this->profileGoal($profile),
            'success_metric' => $this->profileSuccessMetric($profile),
            'strategy' => $strategy,
            'chunks' => $chunks,
            'original_bytes' => $rawBytes,
            'smartcache_bytes' => $smartBytes,
            'size_reduction_percent' => $rawBytes > 0 ? \round((($rawBytes - $smartBytes) / $rawBytes) * 100, 2) : 0.0,
            'laravel_write_ms_avg' => $this->average($rawWriteTimes),
            'laravel_read_ms_avg' => $this->average($rawReadTimes),
            'smartcache_write_ms_avg' => $this->average($smartWriteTimes),
            'smartcache_read_ms_avg' => $this->average($smartReadTimes),
            'data_integrity' => $integrity,
            'key_shape_preserved' => $keyShapePreserved,
            'goal_passed' => $this->profileGoalPassed($profile, $strategy, $rawBytes, $smartBytes, $chunks, $integrity, $keyShapePreserved),
            'result_summary' => $this->profileResultSummary($profile, $strategy, $rawBytes, $smartBytes, $chunks, $integrity, $keyShapePreserved),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function displayReport(array $report): void
    {
        $this->info('SmartCache Benchmark');
        $this->line('--------------------');
        $this->line('Driver: ' . $report['environment']['driver']);
        $this->line('Iterations: ' . $report['environment']['iterations']);
        $this->line($report['environment']['note']);
        $this->newLine();

        $this->table(
            ['Profile', 'Goal', 'Strategy', 'Raw Size', 'Smart Size', 'Saved', 'Raw Write', 'Smart Write', 'Raw Read', 'Smart Read', 'Chunks', 'Passed'],
            \array_map(fn (array $profile) => [
                $profile['profile'],
                $profile['goal'],
                $profile['strategy'],
                $this->formatBytes((int) $profile['original_bytes']),
                $this->formatBytes((int) $profile['smartcache_bytes']),
                $profile['size_reduction_percent'] . '%',
                $profile['laravel_write_ms_avg'] . 'ms',
                $profile['smartcache_write_ms_avg'] . 'ms',
                $profile['laravel_read_ms_avg'] . 'ms',
                $profile['smartcache_read_ms_avg'] . 'ms',
                $profile['chunks'],
                $profile['goal_passed'] ? 'yes' : 'no',
            ], $report['profiles'])
        );

        $this->newLine();
        $this->line('Profile result summaries:');
        foreach ($report['profiles'] as $profile) {
            $this->line(' - ' . $profile['profile'] . ': ' . $profile['result_summary']);
        }
    }

    protected function payloadFor(string $profile): mixed
    {
        return match ($profile) {
            'medium' => [
                'id' => 1,
                'name' => 'medium-payload',
                'items' => \range(1, 20),
                'meta' => ['source' => 'smart-cache:bench'],
            ],
            'api-json' => \json_encode([
                'status' => 'ok',
                'records' => \array_map(fn (int $i) => [
                    'id' => $i,
                    'title' => "Product {$i}",
                    'description' => \str_repeat('cacheable api payload ', 12),
                    'tags' => ['catalog', 'large-data', 'smart-cache'],
                ], \range(1, 900)),
            ], JSON_THROW_ON_ERROR),
            'large-array' => \array_map(fn (int $i) => [
                'id' => $i,
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'profile' => [
                    'bio' => \str_repeat('large eloquent result ', 10),
                    'roles' => ['viewer', 'buyer'],
                ],
            ], \range(1, 1400)),
            'sparse-array' => $this->sparsePayload(),
            default => throw new \InvalidArgumentException("Unknown benchmark profile [{$profile}]"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sparsePayload(): array
    {
        $payload = [];

        foreach (\range(1, 1200) as $i) {
            $payload[$i * 10] = [
                'id' => $i * 10,
                'value' => \str_repeat('sparse keyed cache value ', 8),
            ];
        }

        return $payload;
    }

    protected function profileDescription(string $profile): string
    {
        return match ($profile) {
            'medium' => 'Small mixed payload below large-data thresholds.',
            'api-json' => 'Large repetitive API-style response.',
            'large-array' => 'Large Eloquent-like list of associative records.',
            'sparse-array' => 'Large sparse numeric array used to verify key preservation.',
            default => $profile,
        };
    }

    protected function profileGoal(string $profile): string
    {
        return match ($profile) {
            'medium' => 'control',
            'api-json' => 'compression',
            'large-array' => 'driver-safety',
            'sparse-array' => 'key-preservation',
            default => 'unknown',
        };
    }

    protected function profileSuccessMetric(string $profile): string
    {
        return match ($profile) {
            'medium' => 'No optimization should be applied to small payloads.',
            'api-json' => 'Compressed storage should be smaller than raw storage.',
            'large-array' => 'Payload should be split into chunks and restored with data integrity.',
            'sparse-array' => 'Sparse numeric keys should survive chunking and restore.',
            default => 'Profile should complete successfully.',
        };
    }

    protected function profileGoalPassed(
        string $profile,
        string $strategy,
        int $rawBytes,
        int $smartBytes,
        int $chunks,
        bool $integrity,
        bool $keyShapePreserved
    ): bool {
        return match ($profile) {
            'medium' => $strategy === 'none' && $integrity,
            'api-json' => $strategy === 'compression' && $smartBytes < $rawBytes && $integrity,
            'large-array' => $strategy === 'chunking' && $chunks > 0 && $integrity && $keyShapePreserved,
            'sparse-array' => $strategy === 'chunking' && $chunks > 0 && $integrity && $keyShapePreserved,
            default => $integrity,
        };
    }

    protected function profileResultSummary(
        string $profile,
        string $strategy,
        int $rawBytes,
        int $smartBytes,
        int $chunks,
        bool $integrity,
        bool $keyShapePreserved
    ): string {
        $passed = $this->profileGoalPassed($profile, $strategy, $rawBytes, $smartBytes, $chunks, $integrity, $keyShapePreserved);

        if (!$passed) {
            return 'Goal did not pass; review strategy, thresholds, and data integrity fields.';
        }

        return match ($profile) {
            'medium' => 'Passed: SmartCache correctly skipped optimization for a small payload.',
            'api-json' => 'Passed: compression reduced stored size for a large repetitive JSON payload.',
            'large-array' => 'Passed: chunking split a large array and restored it with data integrity; byte reduction is not the goal for this profile.',
            'sparse-array' => 'Passed: chunking preserved sparse numeric keys; byte reduction is not the goal for this profile.',
            default => 'Passed.',
        };
    }

    protected function detectStrategy(mixed $raw): string
    {
        if (\is_array($raw) && ($raw['_sc_chunked'] ?? false) === true) {
            return 'chunking';
        }

        if (\is_array($raw) && ($raw['_sc_compressed'] ?? false) === true) {
            return 'compression';
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

    protected function keysMatch(mixed $expected, mixed $actual): bool
    {
        if (!\is_array($expected) || !\is_array($actual)) {
            return true;
        }

        return \array_keys($expected) === \array_keys($actual);
    }

    protected function elapsedMs(float $start): float
    {
        return \round((\microtime(true) - $start) * 1000, 4);
    }

    /**
     * @param array<int, float> $values
     */
    protected function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return \round(\array_sum($values) / \count($values), 4);
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
