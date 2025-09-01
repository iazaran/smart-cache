<?php

namespace SmartCache\Providers;

use Illuminate\Support\ServiceProvider;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\SmartCache;
use SmartCache\Strategies\CompressionStrategy;
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
                    $config->get('smart-cache.strategies.chunking.chunk_size', 1000)
                );
            }
            
            if ($config->get('smart-cache.strategies.compression.enabled', true)) {
                $strategies[] = new CompressionStrategy(
                    $config->get('smart-cache.thresholds.compression', 51200),
                    $config->get('smart-cache.strategies.compression.level', 6)
                );
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
    }
} 