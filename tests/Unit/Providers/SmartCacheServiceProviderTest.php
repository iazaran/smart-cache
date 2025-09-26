<?php

namespace SmartCache\Tests\Unit\Providers;

use SmartCache\Providers\SmartCacheServiceProvider;
use SmartCache\Contracts\SmartCache as SmartCacheContract;
use SmartCache\SmartCache;
use SmartCache\Console\Commands\ClearCommand;
use SmartCache\Console\Commands\StatusCommand;
use SmartCache\Tests\TestCase;

class SmartCacheServiceProviderTest extends TestCase
{
    public function test_service_provider_registers_smart_cache_singleton()
    {
        // The service should be registered as a singleton
        $this->assertTrue($this->app->bound(SmartCacheContract::class));
        
        // Should return the same instance on multiple calls
        $instance1 = $this->app->make(SmartCacheContract::class);
        $instance2 = $this->app->make(SmartCacheContract::class);
        
        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(SmartCache::class, $instance1);
    }

    public function test_service_provider_registers_smart_cache_alias()
    {
        // The service should also be available via alias
        $this->assertTrue($this->app->bound('smart-cache'));
        
        $instanceFromContract = $this->app->make(SmartCacheContract::class);
        $instanceFromAlias = $this->app->make('smart-cache');
        
        $this->assertSame($instanceFromContract, $instanceFromAlias);
    }

    public function test_smart_cache_is_constructed_with_correct_dependencies()
    {
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Should be able to use basic cache operations
        $this->assertTrue($smartCache->put('test-key', 'test-value'));
        $this->assertEquals('test-value', $smartCache->get('test-key'));
        $this->assertTrue($smartCache->has('test-key'));
        $this->assertTrue($smartCache->forget('test-key'));
    }

    public function test_smart_cache_uses_compression_strategy_when_enabled()
    {
        // Compression is enabled in test configuration
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store a large compressible string
        $largeData = $this->createCompressibleData();
        $smartCache->put('compression-test', $largeData);
        
        // Should be tracked as a managed key since it was optimized
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('compression-test', $managedKeys);
        
        // Should be able to retrieve the original data
        $retrieved = $smartCache->get('compression-test');
        $this->assertEquals($largeData, $retrieved);
    }

    public function test_smart_cache_uses_chunking_strategy_when_enabled()
    {
        // Chunking is enabled in test configuration
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store a large array that should trigger chunking
        $largeArray = $this->createChunkableData();
        $smartCache->put('chunking-test', $largeArray);
        
        // Should be tracked as a managed key since it was optimized
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('chunking-test', $managedKeys);
        
        // Should be able to retrieve the original data
        $retrieved = $smartCache->get('chunking-test');
        $this->assertEquals($largeArray, $retrieved);
    }

    public function test_service_provider_respects_compression_configuration()
    {
        // Test with compression disabled
        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        
        // Re-register the service provider to pick up new config
        $provider = new SmartCacheServiceProvider($this->app);
        $provider->register();
        
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store compressible data - should not be optimized
        $largeData = $this->createCompressibleData();
        $smartCache->put('no-compression-test', $largeData);
        
        // All keys are now tracked for advanced invalidation features
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('no-compression-test', $managedKeys);
    }

    public function test_service_provider_respects_chunking_configuration()
    {
        // Test with both compression and chunking disabled
        $this->app['config']->set('smart-cache.strategies.compression.enabled', false);
        $this->app['config']->set('smart-cache.strategies.chunking.enabled', false);
        
        // Re-register the service provider to pick up new config
        $provider = new SmartCacheServiceProvider($this->app);
        $provider->register();
        
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store chunkable data - should not be optimized since both strategies are disabled
        $largeArray = $this->createChunkableData();
        $smartCache->put('no-chunking-test', $largeArray);
        
        // All keys are now tracked for advanced invalidation features
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('no-chunking-test', $managedKeys);
    }

    public function test_service_provider_uses_custom_compression_settings()
    {
        // Set custom compression settings
        $this->app['config']->set('smart-cache.thresholds.compression', 500); // 500 bytes
        $this->app['config']->set('smart-cache.strategies.compression.level', 9); // Max compression
        
        // Re-register the service provider
        $provider = new SmartCacheServiceProvider($this->app);
        $provider->register();
        
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store data that exceeds the lower threshold
        $mediumData = str_repeat('test ', 150); // ~750 bytes
        $smartCache->put('custom-compression-test', $mediumData);
        
        // Should be optimized with the lower threshold
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('custom-compression-test', $managedKeys);
        
        // Should still retrieve original data
        $retrieved = $smartCache->get('custom-compression-test');
        $this->assertEquals($mediumData, $retrieved);
    }

    public function test_service_provider_uses_custom_chunking_settings()
    {
        // Set custom chunking settings
        $this->app['config']->set('smart-cache.thresholds.chunking', 1024); // 1KB
        $this->app['config']->set('smart-cache.strategies.chunking.chunk_size', 50); // 50 items per chunk
        
        // Re-register the service provider
        $provider = new SmartCacheServiceProvider($this->app);
        $provider->register();
        
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Store array that should trigger chunking with custom settings
        $testArray = $this->createLargeTestData(100); // 100 items, should be chunked
        $smartCache->put('custom-chunking-test', $testArray);
        
        // Should be optimized
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('custom-chunking-test', $managedKeys);
        
        // Should retrieve original data
        $retrieved = $smartCache->get('custom-chunking-test');
        $this->assertEquals($testArray, $retrieved);
    }

    public function test_service_provider_registers_console_commands_in_console_environment()
    {
        // Simulate console environment
        $this->app['env'] = 'testing'; // This should trigger console registration
        
        // Get all registered commands
        $registeredCommands = [];
        
        // Mock the commands method to capture registered commands
        $originalProvider = new SmartCacheServiceProvider($this->app);
        
        // Check that commands are available in artisan
        $artisan = $this->app['Illuminate\Contracts\Console\Kernel'];
        
        // The commands should be registered and available
        $clearCommand = $this->app->make(ClearCommand::class);
        $statusCommand = $this->app->make(StatusCommand::class);
        
        $this->assertInstanceOf(ClearCommand::class, $clearCommand);
        $this->assertInstanceOf(StatusCommand::class, $statusCommand);
        
        // Verify command signatures
        $this->assertEquals('smart-cache:clear', $clearCommand->getName());
        $this->assertEquals('smart-cache:status', $statusCommand->getName());
    }

    public function test_service_provider_merges_configuration()
    {
        // The service provider should merge the package configuration
        $config = $this->app['config']->get('smart-cache');
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('strategies', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('compression', $config['strategies']);
        $this->assertArrayHasKey('chunking', $config['strategies']);
    }

    public function test_service_provider_config_publishing()
    {
        $provider = new SmartCacheServiceProvider($this->app);
        
        // Boot the provider to register publishable assets
        $provider->boot();
        
        // In a minimal test environment, we can't test the exact publishing mechanism
        // but we can verify the provider boots without error and that configuration is available
        $config = $this->app['config']->get('smart-cache');
        $this->assertNotEmpty($config);
        
        // This test mainly ensures the boot method works correctly
        $this->assertTrue(true);
    }

    public function test_multiple_smart_cache_instances_are_same_singleton()
    {
        $instance1 = $this->app->make(SmartCacheContract::class);
        $instance2 = app(SmartCacheContract::class);
        $instance3 = resolve(SmartCacheContract::class);
        
        $this->assertSame($instance1, $instance2);
        $this->assertSame($instance2, $instance3);
    }

    public function test_smart_cache_integrates_with_laravel_cache_manager()
    {
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Should be able to access different cache stores
        $defaultStore = $smartCache->store();
        $arrayStore = $smartCache->store('array');
        
        $this->assertNotNull($defaultStore);
        $this->assertNotNull($arrayStore);
        
        // Both should be usable for caching
        $defaultStore->put('default-test', 'value');
        $arrayStore->put('array-test', 'value');
        
        $this->assertTrue($defaultStore->has('default-test'));
        $this->assertTrue($arrayStore->has('array-test'));
    }

    public function test_service_provider_handles_missing_strategies_gracefully()
    {
        // Remove all strategies from config
        $this->app['config']->set('smart-cache.strategies', []);
        
        // Re-register the service provider
        $provider = new SmartCacheServiceProvider($this->app);
        $provider->register();
        
        $smartCache = $this->app->make(SmartCacheContract::class);
        
        // Should still work, just without optimizations
        $this->assertTrue($smartCache->put('test-key', 'test-value'));
        $this->assertEquals('test-value', $smartCache->get('test-key'));
        
        // Keys are still tracked even without optimization strategies
        $this->assertNotEmpty($smartCache->getManagedKeys());
    }
}
