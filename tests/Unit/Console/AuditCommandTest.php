<?php

namespace SmartCache\Tests\Unit\Console;

use SmartCache\Console\Commands\AuditCommand;
use SmartCache\Contracts\SmartCache;
use SmartCache\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AuditCommandTest extends TestCase
{
    public function test_command_has_correct_signature_and_description(): void
    {
        $command = new AuditCommand();

        $this->assertEquals('smart-cache:audit', $command->getName());
        $this->assertEquals('Audit SmartCache managed keys, chunk health, oversized entries, and eviction suggestions.', $command->getDescription());
    }

    public function test_audit_command_reports_good_health_for_empty_cache(): void
    {
        $commandTester = $this->commandTester();

        $exitCode = $commandTester->execute([]);
        $output = $commandTester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('SmartCache Audit', $output);
        $this->assertStringContainsString('Health: GOOD', $output);
        $this->assertStringContainsString('Managed keys: 0', $output);
    }

    public function test_audit_command_detects_broken_chunked_keys(): void
    {
        $cache = $this->app->make(SmartCache::class);
        $cache->put('audit-chunked-key', $this->createChunkableData(), 3600);

        $raw = $cache->getRaw('audit-chunked-key');
        $this->assertIsArray($raw);
        $this->assertArrayHasKey('chunk_keys', $raw);

        $cache->repository()->forget($raw['chunk_keys'][0]);

        $commandTester = $this->commandTester();
        $exitCode = $commandTester->execute([]);
        $output = $commandTester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Health: CRITICAL', $output);
        $this->assertStringContainsString('Broken chunked keys: 1', $output);
        $this->assertStringContainsString('audit-chunked-key', $output);
    }

    public function test_audit_command_outputs_json_report(): void
    {
        $cache = $this->app->make(SmartCache::class);
        $cache->put('audit-json-key', 'value', 3600);

        $commandTester = $this->commandTester();
        $exitCode = $commandTester->execute(['--format' => 'json']);
        $report = \json_decode($commandTester->getDisplay(), true);

        $this->assertEquals(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertArrayHasKey('health', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertGreaterThanOrEqual(1, $report['summary']['managed_keys']);
    }

    protected function commandTester(): CommandTester
    {
        $command = new AuditCommand();
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
