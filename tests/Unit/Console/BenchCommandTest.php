<?php

namespace SmartCache\Tests\Unit\Console;

use SmartCache\Console\Commands\BenchCommand;
use SmartCache\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BenchCommandTest extends TestCase
{
    public function test_command_has_correct_signature_and_description(): void
    {
        $command = new BenchCommand();

        $this->assertEquals('smart-cache:bench', $command->getName());
        $this->assertEquals('Benchmark SmartCache compression and chunking against raw Laravel cache operations.', $command->getDescription());
    }

    public function test_bench_command_runs_single_profile(): void
    {
        $commandTester = $this->commandTester();

        $exitCode = $commandTester->execute([
            '--profile' => 'api-json',
            '--iterations' => 1,
        ]);
        $output = $commandTester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('SmartCache Benchmark', $output);
        $this->assertStringContainsString('api-json', $output);
        $this->assertStringContainsString('compression', $output);
        $this->assertStringContainsString('Profile result summaries', $output);
    }

    public function test_bench_command_outputs_json_report(): void
    {
        $commandTester = $this->commandTester();

        $exitCode = $commandTester->execute([
            '--profile' => 'sparse-array',
            '--iterations' => 1,
            '--format' => 'json',
        ]);
        $report = \json_decode($commandTester->getDisplay(), true);

        $this->assertEquals(0, $exitCode);
        $this->assertIsArray($report);
        $this->assertSame('array', $report['environment']['driver']);
        $this->assertCount(1, $report['profiles']);
        $this->assertSame('sparse-array', $report['profiles'][0]['profile']);
        $this->assertSame('key-preservation', $report['profiles'][0]['goal']);
        $this->assertSame('Sparse numeric keys should survive chunking and restore.', $report['profiles'][0]['success_metric']);
        $this->assertTrue($report['profiles'][0]['goal_passed']);
        $this->assertStringContainsString('key', $report['profiles'][0]['result_summary']);
        $this->assertTrue($report['profiles'][0]['data_integrity']);
        $this->assertTrue($report['profiles'][0]['key_shape_preserved']);
    }

    public function test_bench_command_writes_json_report_to_output_file(): void
    {
        $outputPath = \sys_get_temp_dir() . '/smart-cache-bench-test-' . \bin2hex(\random_bytes(4)) . '.json';

        try {
            $commandTester = $this->commandTester();
            $exitCode = $commandTester->execute([
                '--profile' => 'medium',
                '--iterations' => 1,
                '--output' => $outputPath,
            ]);

            $this->assertEquals(0, $exitCode);
            $this->assertFileExists($outputPath);

            $report = \json_decode((string) \file_get_contents($outputPath), true);
            $this->assertSame('medium', $report['profiles'][0]['profile']);
            $this->assertSame('control', $report['profiles'][0]['goal']);
            $this->assertTrue($report['profiles'][0]['goal_passed']);
        } finally {
            if (\file_exists($outputPath)) {
                \unlink($outputPath);
            }
        }
    }

    protected function commandTester(): CommandTester
    {
        $command = new BenchCommand();
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
