<?php

namespace SmartCache\Providers;

use Illuminate\Support\ServiceProvider;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\SmartCache;
use SmartCache\Strategies\CompressionStrategy;
use SmartCache\Strategies\AdaptiveCompressionStrategy;
use SmartCache\Strategies\ChunkingStrategy;
use SmartCache\Console\Commands\ClearCommand;
use SmartCache\Console\Commands\StatusCommand;

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
                    $strategies[] = new AdaptiveCompressionStrategy(
                        $config->get('smart-cache.thresholds.compression', 51200),
                        $config->get('smart-cache.strategies.compression.level', 6),
                        $config->get('smart-cache.strategies.compression.adaptive.sample_size', 1024),
                        $config->get('smart-cache.strategies.compression.adaptive.high_compression_threshold', 0.5),
                        $config->get('smart-cache.strategies.compression.adaptive.low_compression_threshold', 0.7),
                        $config->get('smart-cache.strategies.compression.adaptive.frequency_threshold', 100)
                    );
                } else {
                    $strategies[] = new CompressionStrategy(
                        $config->get('smart-cache.thresholds.compression', 51200),
                        $config->get('smart-cache.strategies.compression.level', 6)
                    );
                }
            }
            
            return new SmartCache($cacheManager->store(), $cacheManager, $config, $strategies);
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
            ]);
        }

        // Register command metadata for HTTP context
        $this->app->singleton('smart-cache.commands', function ($app) {
            return [
                'smart-cache:clear' => [
                    'class' => ClearCommand::class,
                    'description' => 'Clear SmartCache managed items',
                    'signature' => 'smart-cache:clear {key? : The specific cache key to clear} {--force : Force clear keys even if not managed by SmartCache}'
                ],
                'smart-cache:status' => [
                    'class' => StatusCommand::class,
                    'description' => 'Display information about SmartCache usage and configuration',
                    'signature' => 'smart-cache:status {--force : Include Laravel cache analysis and orphaned SmartCache keys}'
                ]
            ];
        });
    }
} 