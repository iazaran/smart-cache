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

    public function test_clear_command_with_no_managed_keys_and_force()
    {
        // Setup mock to return empty array for managed keys
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->twice() // Called once for display, once in clearOrphanedKeys
            ->andReturn([]);

        // Mock getStore to return a store that will simulate an unsupported driver
        $mockStore = Mockery::mock(\stdClass::class); // Use stdClass to simulate unsupported driver

        $this->mockSmartCache->shouldReceive('getStore')
            ->once()
            ->andReturn($mockStore);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with force
        $exitCode = $this->commandTester->execute(['--force' => true]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No SmartCache managed items found. Checking for non-managed cache keys...', $output);
        $this->assertStringContainsString('Could not scan for non-managed keys with this cache driver. Only managed keys were cleared.', $output);
    }

    public function test_clear_command_with_managed_keys_success()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->twice() // Once for initial count, once after cleanup
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('cleanupExpiredManagedKeys')
            ->once()
            ->andReturn(0);
        
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
        $this->assertStringContainsString('All SmartCache managed items have been cleared successfully.', $output);
    }

    public function test_clear_command_with_managed_keys_failure()
    {
        $managedKeys = ['key1', 'key2'];
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->twice() // Once for initial count, once after cleanup
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('cleanupExpiredManagedKeys')
            ->once()
            ->andReturn(0);
        
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
            ->twice() // Once for initial count, once after cleanup
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('cleanupExpiredManagedKeys')
            ->once()
            ->andReturn(0);
        
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
        $this->assertStringContainsString('All SmartCache managed items have been cleared successfully.', $output);
    }

    public function test_clear_specific_key_success()
    {
        $managedKeys = ['key1', 'key2', 'target-key', 'key3'];
        $targetKey = 'target-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('has')
            ->once()
            ->with($targetKey)
            ->andReturn(true);
        
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
        $this->assertStringContainsString("Clearing SmartCache managed item with key 'target-key'...", $output);
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
            
        $this->mockSmartCache->shouldReceive('has')
            ->once()
            ->with($nonExistentKey)
            ->andReturn(false);
        
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

    public function test_clear_specific_key_not_managed_but_exists_in_cache_without_force()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        $existingKey = 'existing-but-not-managed-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
            
        $this->mockSmartCache->shouldReceive('has')
            ->once()
            ->with($existingKey)
            ->andReturn(true);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with existing but non-managed key
        $exitCode = $this->commandTester->execute(['key' => $existingKey]);

        // Assert error exit code
        $this->assertEquals(1, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Cache key 'existing-but-not-managed-key' exists but is not managed by SmartCache. Use --force to clear it anyway.", $output);
    }

    public function test_clear_specific_key_not_managed_but_exists_with_force()
    {
        $managedKeys = ['key1', 'key2', 'key3'];
        $existingKey = 'existing-but-not-managed-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
            
        $this->mockSmartCache->shouldReceive('has')
            ->once()
            ->with($existingKey)
            ->andReturn(true);

        // Mock the store() method to return a SmartCache instance for non-managed key clearing
        $mockStoreInstance = Mockery::mock(SmartCache::class);
        $mockStoreInstance->shouldReceive('forget')
            ->once()
            ->with($existingKey)
            ->andReturn(true);

        $this->mockSmartCache->shouldReceive('store')
            ->once()
            ->andReturn($mockStoreInstance);

        // Inject the mock into the command
        $this->command->setLaravel($this->app);
        $this->app->instance(SmartCache::class, $this->mockSmartCache);

        // Execute the command with force option
        $exitCode = $this->commandTester->execute(['key' => $existingKey, '--force' => true]);

        // Assert success exit code
        $this->assertEquals(0, $exitCode);

        // Assert correct output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Clearing cache item with key 'existing-but-not-managed-key' (not managed by SmartCache)...", $output);
        $this->assertStringContainsString("Cache key 'existing-but-not-managed-key' has been cleared successfully.", $output);
    }

    public function test_clear_specific_key_forget_fails()
    {
        $managedKeys = ['key1', 'key2', 'target-key'];
        $targetKey = 'target-key';
        
        // Setup mock expectations
        $this->mockSmartCache->shouldReceive('getManagedKeys')
            ->once()
            ->andReturn($managedKeys);
        
        $this->mockSmartCache->shouldReceive('has')
            ->once()
            ->with($targetKey)
            ->andReturn(true);
        
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
        $this->assertStringContainsString("Clearing SmartCache managed item with key 'target-key'...", $output);
        $this->assertStringContainsString("Failed to clear cache key 'target-key'.", $output);
    }

    public function test_clear_command_integration_with_real_smart_cache()
    {
        // Use real SmartCache instance from service container
        $smartCache = $this->app->make(SmartCache::class);
        
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
        $smartCache = $this->app->make(SmartCache::class);
        
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
        $this->assertStringContainsString("Clearing SmartCache managed item with key 'test-key-1'...", $output);
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

    public function test_clear_command_with_force_clears_all_non_managed_keys()
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
        
        // All keys should exist
        $this->assertTrue($smartCache->has('smart-cache-key-1'));
        $this->assertTrue($smartCache->has('smart-cache-key-2'));
        $this->assertTrue($laravelCache->has('laravel-key-1'));
        $this->assertTrue($laravelCache->has('laravel-key-2'));
        $this->assertTrue($laravelCache->has('laravel-key-3'));
        
        // Create command with Laravel application set
        $command = new ClearCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Clear all with --force flag
        $exitCode = $commandTester->execute(['--force' => true]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output contains expected messages
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Clearing 2 SmartCache managed items...', $output);
        $this->assertStringContainsString('All SmartCache managed items have been cleared successfully.', $output);
        $this->assertStringContainsString('Checking for non-managed cache keys...', $output);
        $this->assertStringContainsString('Cleared 3 non-managed cache keys.', $output);
        
        // Verify all keys have been cleared
        $this->assertFalse($smartCache->has('smart-cache-key-1'));
        $this->assertFalse($smartCache->has('smart-cache-key-2'));
        $this->assertFalse($laravelCache->has('laravel-key-1'));
        $this->assertFalse($laravelCache->has('laravel-key-2'));
        $this->assertFalse($laravelCache->has('laravel-key-3'));
        
        // Verify managed keys list is cleared
        $remainingManagedKeys = $smartCache->getManagedKeys();
        $this->assertEmpty($remainingManagedKeys);
    }
    
    public function test_clear_command_without_force_only_clears_managed_keys()
    {
        // Use real SmartCache and Laravel Cache instances from service container
        $smartCache = $this->app->make(SmartCache::class);
        $laravelCache = $this->app['cache'];
        
        // Add SmartCache managed data
        $smartCache->put('smart-cache-key-1', $this->createCompressibleData());
        
        // Add Laravel native cache data (not managed by SmartCache)
        $laravelCache->put('laravel-key-1', 'laravel data 1');
        
        // Verify setup
        $this->assertTrue($smartCache->has('smart-cache-key-1'));
        $this->assertTrue($laravelCache->has('laravel-key-1'));
        
        // Create command with Laravel application set
        $command = new ClearCommand();
        $command->setLaravel($this->app);
        
        $commandTester = new CommandTester($command);
        
        // Clear all WITHOUT --force flag
        $exitCode = $commandTester->execute([]);
        
        // Assert success
        $this->assertEquals(0, $exitCode);
        
        // Verify output
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Clearing 1 SmartCache managed items...', $output);
        $this->assertStringContainsString('All SmartCache managed items have been cleared successfully.', $output);
        $this->assertStringNotContainsString('Checking for non-managed cache keys...', $output);
        
        // Verify only SmartCache managed keys are cleared, Laravel keys remain
        $this->assertFalse($smartCache->has('smart-cache-key-1'));
        $this->assertTrue($laravelCache->has('laravel-key-1')); // Should remain
        
        // Clean up
        $laravelCache->forget('laravel-key-1');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
