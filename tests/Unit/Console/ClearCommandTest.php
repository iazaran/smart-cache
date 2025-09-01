<?php

namespace SmartCache\Tests\Unit\Console;

use SmartCache\Console\Commands\ClearCommand;
use SmartCache\Contracts\SmartCache;
use SmartCache\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Tester\CommandTester;

class ClearCommandTest extends TestCase
{
    protected ClearCommand $command;
    protected SmartCache&MockInterface $mockSmartCache;
    protected CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockSmartCache = Mockery::mock(SmartCache::class);
        $this->command = new ClearCommand();
        
        // Set up command tester using Symfony Console Application instead
        $application = new \Symfony\Component\Console\Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function test_command_has_correct_signature_and_description()
    {
        $this->assertEquals('smart-cache:clear', $this->command->getName());
        $this->assertEquals('Clear SmartCache managed items. Optionally specify a key to clear only that item.', $this->command->getDescription());
    }

    public function test_clear_command_with_no_managed_keys()
    {
        // Setup mock to return empty array for managed keys
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn([]);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No SmartCache managed items found.', $output);
    }

    public function test_clear_command_with_managed_keys_success()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('clear')
            ->once()
            ->andReturn(true);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Clearing 3 SmartCache managed items...', $output);
        $this->assertStringContainsString('All SmartCache items have been cleared successfully.', $output);
    }

    public function test_clear_command_with_managed_keys_failure()
    {
        $managedKeys = ['key1', 'key2'];
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('clear')
            ->once()
            ->andReturn(false);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert error exit code
        $this->assertEquals(1, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Clearing 2 SmartCache managed items...', $output);
        $this->assertStringContainsString('Some SmartCache items could not be cleared.', $output);
    }

    public function test_clear_command_with_single_managed_key()
    {
        $managedKeys = ['single-key'];
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('clear')
            ->once()
            ->andReturn(true);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command
        $exitCode = $this->commandTester->execute([]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Clearing 1 SmartCache managed items...', $output);
        $this->assertStringContainsString('All SmartCache items have been cleared successfully.', $output);
    }

    public function test_clear_specific_key_success()
    {
        $managedKeys = ['key1', 'key2', 'target-key', 'key3'];
        $targetKey = 'target-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('forget')
            ->once()
            ->with($targetKey)
            ->andReturn(true);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with specific key
        $exitCode = $this->commandTester->execute(['key' => $targetKey]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Clearing SmartCache item with key 'target-key'...", $output);
        $this->assertStringContainsString("Cache key 'target-key' has been cleared successfully.", $output);
    }

    public function test_clear_specific_key_not_managed()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        $nonExistentKey = 'non-existent-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        // forget() should not be called since key is not managed

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with non-existent key
        $exitCode = $this->commandTester->execute(['key' => $nonExistentKey]);

        // Assert error exit code
        $this->assertEquals(1, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Cache key 'non-existent-key' is not managed by SmartCache or does not exist.", $output);
    }

    public function test_clear_specific_key_forget_fails()
    {
        $managedKeys = ['key1', 'key2', 'target-key'];
        $targetKey = 'target-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('forget')
            ->once()
            ->with($targetKey)
            ->andReturn(false);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with specific key
        $exitCode = $this->commandTester->execute(['key' => $targetKey]);

        // Assert error exit code
        $this->assertEquals(1, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Clearing SmartCache item with key 'target-key'...", $output);
        $this->assertStringContainsString("Failed to clear cache key 'target-key'.", $output);
    }

    public function test_clear_command_integration_with_real_smart_cache()
    {
        // Use real SmartCache instance from service container
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
        
        // Add some test data
        $smartCache->put('test-key-1', $this->createCompressibleData());
        $smartCache->put('test-key-2', $this->createLargeTestData(50));
        
        // Verify keys are managed
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertNotEmpty($managedKeys);
        
        // Create command with Laravel application set
        $command = new ClearCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        $exitCode = $commandTester->execute([]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify keys are cleared
        $this->assertEmpty($smartCache->getManagedKeys());
    }

    public function test_clear_specific_key_integration_with_real_smart_cache()
    {
        // Use real SmartCache instance from service container
        $smartCache = $this->app->make(\SmartCache\Contracts\SmartCache::class);
        
        // Add some test data
        $smartCache->put('test-key-1', $this->createCompressibleData());
        $smartCache->put('test-key-2', $this->createLargeTestData(50));
        $smartCache->put('test-key-3', 'simple data');
        
        // Verify keys are managed
        $managedKeys = $smartCache->getManagedKeys();
        $this->assertContains('test-key-1', $managedKeys);
        $this->assertContains('test-key-2', $managedKeys);
        
        // Create command with Laravel application set
        $command = new ClearCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Clear only test-key-1
        $exitCode = $commandTester->execute(['key' => 'test-key-1']);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("Clearing SmartCache item with key 'test-key-1'...", $output);
        $this->assertStringContainsString("Cache key 'test-key-1' has been cleared successfully.", $output);
        
        // Verify only test-key-1 is cleared, others remain
        $remainingKeys = $smartCache->getManagedKeys();
        $this->assertNotContains('test-key-1', $remainingKeys);
        $this->assertContains('test-key-2', $remainingKeys);
        
        // Verify the key is actually forgotten
        $this->assertFalse($smartCache->has('test-key-1'));
        $this->assertTrue($smartCache->has('test-key-2'));
        
        // Test clearing non-existent key
        $exitCode = $commandTester->execute(['key' => 'non-existent-key']);
        $this->assertEquals(1, $exitCode);
        
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("Cache key 'non-existent-key' is not managed by SmartCache or does not exist.", $output);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
