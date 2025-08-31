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
        $this->assertEquals('Display information about SmartCache usage and configuration', $this->command->getDescription());
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
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
        
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
