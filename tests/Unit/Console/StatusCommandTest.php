<?php

namespace SmartCache\Tests\Unit\Console;

use SmartCache\Console\Commands\StatusCommand;
use SmartCache\Contracts\SmartCache;
use SmartCache\Tests\TestCase;
use Symfony\Component\Console\Application;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    protected StatusCommand $command;
    protected SmartCache $mockSmartCache;
    protected ConfigRepository $mockConfig;
    protected CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockSmartCache = Mockery::mock(SmartCache::class);
        $this->mockConfig = Mockery::mock(ConfigRepository::class);
        $this->command = new StatusCommand();
        
        // Set up command tester using Symfony Console Application instead
        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function test_command_has_correct_signature_and_description()
    {
        $this->assertEquals('smart-cache:status', $this->command->getName());
        $this->assertEquals('Display information about SmartCache usage and configuration. Use --force to include Laravel cache analysis.', $this->command->getDescription());
    }

    public function test_status_command_with_no_managed_keys()
    {
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn([]);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Managed Keys: 0', $output);
        $this->assertStringContainsString('Cache Driver:', $output);
        $this->assertStringContainsString('Compression: Enabled', $output);
        $this->assertStringContainsString('Chunking: Enabled', $output);
    }

    public function test_status_command_with_managed_keys()
    {
        $managedKeys = ['key1', 'key2', 'key3', 'key4', 'key5', 'key6', 'key7'];
        
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Managed Keys: 7', $output);
        $this->assertStringContainsString('Examples:', $output);
        $this->assertStringContainsString('key1', $output);
        $this->assertStringContainsString('key2', $output);
        $this->assertStringContainsString('key3', $output);
        $this->assertStringContainsString('key4', $output);
        $this->assertStringContainsString('key5', $output);
        $this->assertStringContainsString('and 2 more', $output);
    }

    public function test_status_command_with_few_managed_keys()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Managed Keys: 3', $output);
        $this->assertStringContainsString('Examples:', $output);
        $this->assertStringContainsString('key1', $output);
        $this->assertStringContainsString('key2', $output);
        $this->assertStringContainsString('key3', $output);
        $this->assertStringNotContainsString('and', $output); // No "and X more" message
    }

    public function test_status_command_displays_configuration_correctly()
    {
        $customConfig = [
            'strategies' => [
                'compression' => [
                    'enabled' => false,
                    'level' => 9
                ],
                'chunking' => [
                    'enabled' => true,
                    'chunk_size' => 1000
                ]
            ],
            'thresholds' => [
                'compression' => 2048, // 2KB
                'chunking' => 4096     // 4KB
            ]
        ];

        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn([]);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($customConfig);

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Compression: Disabled', $output);
        $this->assertStringContainsString('Threshold: 2.00 KB', $output);
        $this->assertStringContainsString('Level: 9', $output);
        $this->assertStringContainsString('Chunking: Enabled', $output);
        $this->assertStringContainsString('Threshold: 4.00 KB', $output);
    }

    public function test_status_command_displays_cache_driver()
    {
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn([]);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Cache Driver: array', $output); // Default test driver
    }

    public function test_status_command_integration_with_real_dependencies()
    {
        // Use real dependencies from service container
        $smartCache = $this->app->make(SmartCache::class);
        
        // Add some test data
        $smartCache->put('integration-test-key', $this->createCompressibleData());
        
        // Create command with Laravel application set
        $command = new StatusCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        $exitCode = $commandTester->execute([]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output contains expected elements
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Managed Keys:', $output);
        $this->assertStringContainsString('Configuration:', $output);
        
        // Clean up
        $smartCache->forget('integration-test-key');
    }

    public function test_status_command_handles_missing_config_gracefully()
    {
        // Setup mocks with minimal config
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn([]);

        $incompleteConfig = [
            'strategies' => [
                'compression' => ['enabled' => true, 'level' => 6],
                'chunking' => ['enabled' => true, 'chunk_size' => 1000]
            ],
            'thresholds' => [
                'compression' => 1024,
                'chunking' => 2048
            ]
        ];

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($incompleteConfig);

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Should still complete successfully
        $this->assertEquals(0, $exitCode);

        // Should display available configuration
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Configuration:', $output);
        $this->assertStringContainsString('Compression: Enabled', $output);
        $this->assertStringContainsString('Chunking: Enabled', $output);
    }

    public function test_status_command_with_force_option_no_orphaned_keys()
    {
        $managedKeys = ['key1', 'key2'];
        
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->twice() // Called once for display, once for orphan detection
            ->andReturn($managedKeys);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());
        
        // Mock the store() method to return a cache repository
        $mockRepository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $mockStore = Mockery::mock(\Illuminate\Cache\ArrayStore::class);
        
        $mockRepository->shouldReceive('getStore')
            ->once()
            ->andReturn($mockStore);
            
        $this->mockSmartCache->shouldReceive('store')
            ->once()
            ->andReturn($mockRepository);
        
        // Mock has() calls to check if managed keys exist
        $this->mockSmartCache->shouldReceive('has')
            ->with('key1')
            ->once()
            ->andReturn(true);
        $this->mockSmartCache->shouldReceive('has')
            ->with('key2')
            ->once()
            ->andReturn(true);

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command with force
        $exitCode = $this->commandTester->execute(['--force' => true]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Laravel Cache Analysis (--force)', $output);
        $this->assertStringContainsString('✓ No non-managed cache keys found.', $output);
        $this->assertStringContainsString('✓ All managed keys exist in cache.', $output);
    }

    public function test_status_command_with_force_option_finds_missing_managed_keys()
    {
        $managedKeys = ['existing-key', 'missing-key1', 'missing-key2'];
        
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->twice()
            ->andReturn($managedKeys);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());
        
        // Mock the store() method to return a cache repository
        $mockRepository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $mockStore = Mockery::mock(\Illuminate\Cache\ArrayStore::class);
        
        $mockRepository->shouldReceive('getStore')
            ->once()
            ->andReturn($mockStore);
            
        $this->mockSmartCache->shouldReceive('store')
            ->once()
            ->andReturn($mockRepository);
        
        // Mock has() calls - one key exists, two are missing
        $this->mockSmartCache->shouldReceive('has')
            ->with('existing-key')
            ->once()
            ->andReturn(true);
        $this->mockSmartCache->shouldReceive('has')
            ->with('missing-key1')
            ->once()
            ->andReturn(false);
        $this->mockSmartCache->shouldReceive('has')
            ->with('missing-key2')
            ->once()
            ->andReturn(false);

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command with force
        $exitCode = $this->commandTester->execute(['--force' => true]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Laravel Cache Analysis (--force)', $output);
        $this->assertStringContainsString('Found 2 managed keys that no longer exist in cache:', $output);
        $this->assertStringContainsString('missing-key1', $output);
        $this->assertStringContainsString('missing-key2', $output);
        $this->assertStringContainsString('These managed keys no longer exist in the cache store.', $output);
    }

    public function test_status_command_without_force_option_skips_analysis()
    {
        $managedKeys = ['key1', 'key2'];
        
        // Setup mocks
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once() // Only called once for display, not for analysis
            ->andReturn($managedKeys);

        $this->mockConfig->shouldReceive('get')
            ->with('smart-cache')
            ->once()
            ->andReturn($this->getDefaultSmartCacheConfig());

        // store() should NOT be called without force
        $this->mockSmartCache->shouldNotReceive('store');
        $this->mockSmartCache->shouldNotReceive('has');

        // Inject mocks
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);
        $this->app->instance(ConfigRepository::class, $this->mockConfig);

        // Execute the command without force
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert analysis section is NOT present
        $output = $this->commandTester->getDisplay();
        $this->assertStringNotContainsString('Laravel Cache Analysis', $output);
        $this->assertStringNotContainsString('orphaned', $output);
    }

    public function test_status_command_integration_with_force_option()
    {
        // Use real dependencies from service container
        $smartCache = $this->app->make(SmartCache::class);
        
        // Add some test data
        $smartCache->put('integration-test-key', $this->createCompressibleData());
        
        // Create command with Laravel application set
        $command = new StatusCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        $exitCode = $commandTester->execute(['--force' => true]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output contains force analysis
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Laravel Cache Analysis (--force)', $output);
        
        // Clean up
        $smartCache->forget('integration-test-key');
    }

    public function test_status_command_with_force_shows_all_non_managed_keys()
    {
        // Use real SmartCache and Laravel Cache instances from service container
        $smartCache = $this->app->make(SmartCache::class);
        $laravelCache = $this->app['cache'];
        
        // Add SmartCache managed data (both must be large enough to trigger optimization)
        $smartCache->put('smart-cache-key-1', $this->createCompressibleData());
        $smartCache->put('smart-cache-key-2', $this->createLargeTestData(100));
        
        // Add Laravel native cache data (not managed by SmartCache)
        $laravelCache->put('laravel-key-1', 'laravel data 1');
        $laravelCache->put('laravel-key-2', ['laravel' => 'data 2']);
        $laravelCache->put('laravel-key-3', 'laravel data 3');
        
        // Verify setup - SmartCache keys should be managed
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('smart-cache-key-1', $managedKeys);
        $this->assertContains('smart-cache-key-2', $managedKeys);
        $this->assertNotContains('laravel-key-1', $managedKeys);
        $this->assertNotContains('laravel-key-2', $managedKeys);
        $this->assertNotContains('laravel-key-3', $managedKeys);
        
        // Create command with Laravel application set
        $command = new StatusCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Execute command with --force flag
        $exitCode = $commandTester->execute(['--force' => true]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output shows non-managed keys
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Managed Keys: 2', $output);
        $this->assertStringContainsString('Laravel Cache Analysis (--force)', $output);
        $this->assertStringContainsString('Found 3 non-managed Laravel cache keys:', $output);
        $this->assertStringContainsString('laravel-key-1', $output);
        $this->assertStringContainsString('laravel-key-2', $output);
        $this->assertStringContainsString('laravel-key-3', $output);
        $this->assertStringContainsString('These keys are stored in Laravel cache but not managed by SmartCache.', $output);
        $this->assertStringContainsString('Consider running: php artisan smart-cache:clear --force', $output);
        $this->assertStringContainsString('✓ All managed keys exist in cache.', $output);
        
        // Clean up
        $smartCache->forget('smart-cache-key-1');
        $smartCache->forget('smart-cache-key-2');
        $laravelCache->forget('laravel-key-1');
        $laravelCache->forget('laravel-key-2');
        $laravelCache->forget('laravel-key-3');
    }
    
    public function test_status_command_without_force_only_shows_managed_keys()
    {
        // Use real SmartCache and Laravel Cache instances from service container
        $smartCache = $this->app->make(SmartCache::class);
        $laravelCache = $this->app['cache'];
        
        // Add SmartCache managed data
        $smartCache->put('smart-cache-key-1', $this->createCompressibleData());
        
        // Add Laravel native cache data (not managed by SmartCache)
        $laravelCache->put('laravel-key-1', 'laravel data 1');
        
        // Create command with Laravel application set
        $command = new StatusCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Execute command WITHOUT --force flag
        $exitCode = $commandTester->execute([]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output shows managed keys but not Laravel cache analysis
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Managed Keys: 1', $output);
        $this->assertStringContainsString('smart-cache-key-1', $output);
        $this->assertStringNotContainsString('Laravel Cache Analysis (--force)', $output);
        $this->assertStringNotContainsString('laravel-key-1', $output);
        
        // Clean up
        $smartCache->forget('smart-cache-key-1');
        $laravelCache->forget('laravel-key-1');
    }

    public function test_status_command_with_force_shows_no_non_managed_keys_when_none_exist()
    {
        // Use real SmartCache instance from service container
        $smartCache = $this->app->make(SmartCache::class);
        
        // Add only SmartCache managed data
        $smartCache->put('smart-cache-key-1', $this->createCompressibleData());
        
        // Create command with Laravel application set
        $command = new StatusCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Execute command with --force flag
        $exitCode = $commandTester->execute(['--force' => true]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output shows no non-managed keys
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SmartCache Status', $output);
        $this->assertStringContainsString('Managed Keys: 1', $output);
        $this->assertStringContainsString('Laravel Cache Analysis (--force)', $output);
        $this->assertStringContainsString('✓ No non-managed cache keys found.', $output);
        $this->assertStringContainsString('✓ All managed keys exist in cache.', $output);
        
        // Clean up
        $smartCache->forget('smart-cache-key-1');
    }

    /**
     * Get default smart cache configuration for testing.
     */
    protected function getDefaultSmartCacheConfig(): array
    {
        return [
            'strategies' => [
                'compression' => [
                    'enabled' => true,
                    'level' => 6
                ],
                'chunking' => [
                    'enabled' => true,
                    'chunk_size' => 1000
                ]
            ],
            'thresholds' => [
                'compression' => 1024,
                'chunking' => 2048
            ]
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
