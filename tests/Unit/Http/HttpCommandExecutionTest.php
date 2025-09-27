<?php

namespace SmartCache\Tests\Unit;

use SmartCache\Tests\TestCase;
use SmartCache\SmartCache;
use SmartCache\Contracts\SmartCache as SmartCacheContract;

class HttpCommandExecutionTest extends TestCase
{
    protected SmartCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->app->make(SmartCacheContract::class);
    }

    public function test_get_available_commands_returns_command_metadata(): void
    {
        $commands = $this->cache->getAvailableCommands();
        
        $this->assertIsArray($commands);
        $this->assertArrayHasKey('smart-cache:clear', $commands);
        $this->assertArrayHasKey('smart-cache:status', $commands);
        
        // Check clear command metadata
        $clearCommand = $commands['smart-cache:clear'];
        $this->assertArrayHasKey('class', $clearCommand);
        $this->assertArrayHasKey('description', $clearCommand);
        $this->assertArrayHasKey('signature', $clearCommand);
        $this->assertEquals('SmartCache\Console\Commands\ClearCommand', $clearCommand['class']);
        
        // Check status command metadata
        $statusCommand = $commands['smart-cache:status'];
        $this->assertArrayHasKey('class', $statusCommand);
        $this->assertArrayHasKey('description', $statusCommand);
        $this->assertArrayHasKey('signature', $statusCommand);
        $this->assertEquals('SmartCache\Console\Commands\StatusCommand', $statusCommand['class']);
    }

    public function test_execute_clear_command_without_parameters(): void
    {
        // Setup: Add some test data
        $this->cache->put('test_key_1', 'value1', 3600);
        $this->cache->put('test_key_2', 'value2', 3600);
        
        $initialCount = count($this->cache->getManagedKeys());
        $this->assertGreaterThan(0, $initialCount);
        
        // Execute clear command
        $result = $this->cache->executeCommand('clear');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('cleared_count', $result);
        $this->assertEquals($initialCount, $result['cleared_count']);
        
        // Verify keys were cleared
        $this->assertEquals(0, count($this->cache->getManagedKeys()));
    }

    public function test_execute_clear_command_with_specific_key(): void
    {
        // Setup: Add test data
        $testKey = 'specific_key_test_' . time();
        $this->cache->put($testKey, 'test_value', 3600);
        
        $this->assertTrue($this->cache->has($testKey));
        
        // Execute clear command for specific key
        $result = $this->cache->executeCommand('clear', ['key' => $testKey]);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['cleared_count']);
        $this->assertEquals($testKey, $result['key']);
        $this->assertTrue($result['was_managed']);
        
        // Verify key was cleared
        $this->assertFalse($this->cache->has($testKey));
    }

    public function test_execute_clear_command_with_non_existent_key(): void
    {
        $nonExistentKey = 'non_existent_key_' . time();
        
        $result = $this->cache->executeCommand('clear', ['key' => $nonExistentKey]);
        
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not managed by SmartCache', $result['message']);
        $this->assertEquals(0, $result['cleared_count']);
    }

    public function test_execute_clear_command_with_non_managed_key_without_force(): void
    {
        // Add a key directly to Laravel cache (not managed by SmartCache)
        $nonManagedKey = 'non_managed_key_' . time();
        $this->app['cache']->put($nonManagedKey, 'value', 3600);
        
        $result = $this->cache->executeCommand('clear', ['key' => $nonManagedKey]);
        
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not managed by SmartCache', $result['message']);
        $this->assertEquals(0, $result['cleared_count']);
    }

    public function test_execute_clear_command_with_non_managed_key_with_force(): void
    {
        // Add a key directly to Laravel cache (not managed by SmartCache)
        $nonManagedKey = 'non_managed_key_force_' . time();
        $this->app['cache']->put($nonManagedKey, 'value', 3600);
        
        $result = $this->cache->executeCommand('clear', [
            'key' => $nonManagedKey,
            'force' => true
        ]);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['cleared_count']);
        $this->assertEquals($nonManagedKey, $result['key']);
        $this->assertFalse($result['was_managed']);
    }

    public function test_execute_status_command(): void
    {
        // Setup: Add some test data
        $this->cache->put('status_test_key_1', 'value1', 3600);
        $this->cache->put('status_test_key_2', 'value2', 3600);
        
        $result = $this->cache->executeCommand('status');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache_driver', $result);
        $this->assertArrayHasKey('managed_keys_count', $result);
        $this->assertArrayHasKey('sample_keys', $result);
        $this->assertArrayHasKey('configuration', $result);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('health_check', $result);
        
        $this->assertGreaterThanOrEqual(2, $result['managed_keys_count']);
        $this->assertIsArray($result['sample_keys']);
        $this->assertIsArray($result['configuration']);
    }

    public function test_execute_status_command_with_force(): void
    {
        // Setup: Add test data and create a scenario with missing keys
        $this->cache->put('status_force_test', 'value', 3600);
        
        $result = $this->cache->executeCommand('status', ['force' => true]);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('analysis', $result);
        $this->assertArrayHasKey('managed_keys_missing_from_cache', $result['analysis']);
        $this->assertArrayHasKey('missing_keys_count', $result['analysis']);
        
        $this->assertIsArray($result['analysis']['managed_keys_missing_from_cache']);
        $this->assertIsInt($result['analysis']['missing_keys_count']);
    }

    public function test_execute_unknown_command(): void
    {
        $result = $this->cache->executeCommand('unknown-command');
        
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown command', $result['message']);
        $this->assertArrayHasKey('available_commands', $result);
        $this->assertIsArray($result['available_commands']);
    }

    public function test_execute_command_handles_exceptions(): void
    {
        // Mock a scenario that would cause an exception
        $result = $this->cache->executeCommand('clear', ['key' => null]);
        
        $this->assertIsArray($result);
        // Should handle gracefully even with invalid parameters
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_facade_execute_command(): void
    {
        \SmartCache\Facades\SmartCache::put('facade_command_test', 'value', 3600);
        
        $result = \SmartCache\Facades\SmartCache::executeCommand('status');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['managed_keys_count']);
    }

    public function test_facade_get_available_commands(): void
    {
        $commands = \SmartCache\Facades\SmartCache::getAvailableCommands();
        
        $this->assertIsArray($commands);
        $this->assertArrayHasKey('smart-cache:clear', $commands);
        $this->assertArrayHasKey('smart-cache:status', $commands);
    }

    public function test_command_shortcuts(): void
    {
        // Test that both long and short command names work
        $this->cache->put('shortcut_test', 'value', 3600);
        
        $resultLong = $this->cache->executeCommand('smart-cache:status');
        $resultShort = $this->cache->executeCommand('status');
        
        $this->assertTrue($resultLong['success']);
        $this->assertTrue($resultShort['success']);
        $this->assertEquals($resultLong['managed_keys_count'], $resultShort['managed_keys_count']);
        
        // Test clear shortcuts
        $clearLong = $this->cache->executeCommand('smart-cache:clear');
        
        // Reset data for second test
        $this->cache->put('shortcut_test_2', 'value', 3600);
        $clearShort = $this->cache->executeCommand('clear');
        
        $this->assertTrue($clearLong['success']);
        $this->assertTrue($clearShort['success']);
    }
}
