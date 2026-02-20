<?php

namespace SmartCache\Providers;

use Illuminate\Support\ServiceProvider;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\Services\CostAwareCacheManager;
use SmartCache\SmartCache;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\AdaptiveCompressionStrategy;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Strategies\EncryptionStrategy;
use SmartCache\Console\Commands\ClearCommand;
use SmartCache\Console\Commands\CleanupChunksCommand;
use SmartCache\Console\Commands\StatusCommand;
use SmartCache\Console\Commands\WarmCacheCommand;

class SmartCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/smart-cache.php', 'smart-cache'
        );

        // Register the service
        $this->app->singleton(SmartCacheContract::class, function ($app) {
            $cacheManager = $app['cache'];
            $config = $app['config'];
            
            // Create strategies based on config
            // Order matters: more specific strategies (chunking) should be tried first
            $strategies = [];

            if ($config->get('smart-cache.strategies.chunking.enabled', true)) {
                $strategies[] = new ChunkingStrategy(
                    $config->get('smart-cache.thresholds.chunking', 102400),
                    $config->get('smart-cache.strategies.chunking.chunk_size', 1000),
                    $config->get('smart-cache.strategies.chunking.lazy_loading', false),
                    $config->get('smart-cache.strategies.chunking.smart_sizing', false)
                );
            }

            if ($config->get('smart-cache.strategies.compression.enabled', true)) {
                $compressionMode = $config->get('smart-cache.strategies.compression.mode', 'fixed');

                if ($compressionMode === 'adaptive') {
                    $adaptiveStrategy = new AdaptiveCompressionStrategy(
                        $config->get('smart-cache.thresholds.compression', 51200),
                        $config->get('smart-cache.strategies.compression.level', 6),
                        $config->get('smart-cache.strategies.compression.adaptive.sample_size', 1024),
                        $config->get('smart-cache.strategies.compression.adaptive.high_compression_threshold', 0.5),
                        $config->get('smart-cache.strategies.compression.adaptive.low_compression_threshold', 0.7),
                        $config->get('smart-cache.strategies.compression.adaptive.frequency_threshold', 100)
                    );
                    $adaptiveStrategy->setCacheRepository($cacheManager->store());
                    $strategies[] = $adaptiveStrategy;
                } else {
                    $strategies[] = new CompressionStrategy(
                        $config->get('smart-cache.thresholds.compression', 51200),
                        $config->get('smart-cache.strategies.compression.level', 6)
                    );
                }
            }

            // Add encryption strategy if enabled
            if ($config->get('smart-cache.strategies.encryption.enabled', false)) {
                $strategies[] = new EncryptionStrategy(
                    $app['encrypter'],
                    [
                        'keys' => $config->get('smart-cache.strategies.encryption.keys', []),
                        'patterns' => $config->get('smart-cache.strategies.encryption.patterns', []),
                        'encrypt_all' => $config->get('smart-cache.strategies.encryption.encrypt_all', false),
                    ]
                );
            }

            // Create cost-aware manager if enabled
            $costAwareManager = null;
            if ($config->get('smart-cache.cost_aware.enabled', true)) {
                $costAwareManager = new CostAwareCacheManager(
                    $cacheManager->store(),
                    $config->get('smart-cache.cost_aware.max_tracked_keys', 1000),
                    $config->get('smart-cache.cost_aware.metadata_ttl', 86400)
                );
            }

            return new SmartCache($cacheManager->store(), $cacheManager, $config, $strategies, $costAwareManager);
        });

        // Register alias for facade
        $this->app->alias(SmartCacheContract::class, 'smart-cache');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/smart-cache.php' => $this->app->configPath('smart-cache.php'),
        ], 'smart-cache-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearCommand::class,
                StatusCommand::class,
                CleanupChunksCommand::class,
                WarmCacheCommand::class,
            ]);
        }

        // Register dashboard routes if enabled
        $this->registerDashboardRoutes();

        // Register terminating callback to persist cost-aware metadata reliably
        $this->app->terminating(function () {
            try {
                $smartCache = $this->app->make(SmartCacheContract::class);
                if (\method_exists($smartCache, 'persistCostMetadata')) {
                    $smartCache->persistCostMetadata();
                }
            } catch (\Throwable $e) {
                // Silently fail — don't break the response
            }
        });

        // Register command metadata for HTTP context
        $this->app->singleton('smart-cache.commands', fn () => [
            'smart-cache:clear' => [
                'class' => ClearCommand::class,
                'description' => 'Clear SmartCache managed items',
                'signature' => 'smart-cache:clear {key? : The specific cache key to clear} {--force : Force clear keys even if not managed by SmartCache}'
            ],
            'smart-cache:status' => [
                'class' => StatusCommand::class,
                'description' => 'Display information about SmartCache usage and configuration',
                'signature' => 'smart-cache:status {--force : Include Laravel cache analysis and orphaned SmartCache keys}'
            ],
            'smart-cache:cleanup-chunks' => [
                'class' => CleanupChunksCommand::class,
                'description' => 'Clean up orphan cache chunks whose main keys have expired',
                'signature' => 'smart-cache:cleanup-chunks'
            ],
            'smart-cache:warm' => [
                'class' => WarmCacheCommand::class,
                'description' => 'Pre-warm the cache using registered warmers',
                'signature' => 'smart-cache:warm {--warmer=* : Specific warmer(s) to run} {--list : List available warmers}'
            ]
        ]);
    }

    /**
     * Register dashboard routes if enabled.
     */
    protected function registerDashboardRoutes(): void
    {
        if (!$this->app['config']->get('smart-cache.dashboard.enabled', false)) {
            return;
        }

        $prefix = $this->app['config']->get('smart-cache.dashboard.prefix', 'smart-cache');
        $middleware = $this->app['config']->get('smart-cache.dashboard.middleware', ['web']);

        $this->app['router']
            ->prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../../routes/smart-cache.php');
    }
}