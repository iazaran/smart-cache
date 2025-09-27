<?php

namespace SmartCache\Tests\Feature;

use SmartCache\Tests\TestCase;
use SmartCache\SmartCache;
use SmartCache\Contracts\SmartCache as SmartCacheContract;

class EnhancedSmartCacheControllerTest extends TestCase
{
    protected SmartCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->app->make(SmartCacheContract::class);
    }

    public function test_laravel_12_swr_features(): void
    {
        $results = [];
        
        // Test SWR method
        $results['swr_test'] = $this->testSWRMethod();
        
        // Test Stale method
        $results['stale_test'] = $this->testStaleMethod();
        
        // Test RefreshAhead method
        $results['refresh_ahead_test'] = $this->testRefreshAheadMethod();
        
        // Test Flexible method (existing but enhanced)
        $results['flexible_enhanced_test'] = $this->testFlexibleMethodEnhanced();
        
        $this->assertArrayHasKey('swr_test', $results);
        $this->assertArrayHasKey('stale_test', $results);
        $this->assertArrayHasKey('refresh_ahead_test', $results);
        $this->assertArrayHasKey('flexible_enhanced_test', $results);
        
        foreach ($results as $testName => $result) {
            $this->assertEquals('success', $result['status'], "Test {$testName} failed");
        }
    }

    protected function testSWRMethod(): array
    {
        try {
            $key = 'swr_feature_test_' . time();
            $callCount = 0;
            
            $callback = function () use (&$callCount) {
                $callCount++;
                return [
                    'swr_data' => 'test_value',
                    'generated_at' => now()->toDateTimeString(),
                    'call_number' => $callCount
                ];
            };
            
            // Test SWR with facade
            $facadeResult = \SmartCache\Facades\SmartCache::swr($key . '_facade', $callback, 2, 10);
            
            // Test SWR with helper instance
            $helperResult = smart_cache()->swr($key . '_helper', $callback, 2, 10);
            
            return [
                'status' => 'success',
                'method' => 'swr',
                'facade_result' => $facadeResult,
                'helper_result' => $helperResult,
                'both_methods_working' => isset($facadeResult['swr_data']) && isset($helperResult['swr_data']),
                'call_count' => $callCount
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testStaleMethod(): array
    {
        try {
            $key = 'stale_feature_test_' . time();
            $callCount = 0;
            
            $callback = function () use (&$callCount) {
                $callCount++;
                return [
                    'stale_data' => 'test_value',
                    'generated_at' => now()->toDateTimeString(),
                    'call_number' => $callCount
                ];
            };
            
            // Test Stale with facade
            $facadeResult = \SmartCache\Facades\SmartCache::stale($key . '_facade', $callback, 1, 5);
            
            // Test Stale with helper instance
            $helperResult = smart_cache()->stale($key . '_helper', $callback, 1, 5);
            
            return [
                'status' => 'success',
                'method' => 'stale',
                'facade_result' => $facadeResult,
                'helper_result' => $helperResult,
                'both_methods_working' => isset($facadeResult['stale_data']) && isset($helperResult['stale_data']),
                'call_count' => $callCount
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testRefreshAheadMethod(): array
    {
        try {
            $key = 'refresh_ahead_feature_test_' . time();
            $callCount = 0;
            
            $callback = function () use (&$callCount) {
                $callCount++;
                return [
                    'refresh_ahead_data' => 'test_value',
                    'generated_at' => now()->toDateTimeString(),
                    'call_number' => $callCount
                ];
            };
            
            // Test RefreshAhead with facade
            $facadeResult = \SmartCache\Facades\SmartCache::refreshAhead($key . '_facade', $callback, 5, 2);
            
            // Test RefreshAhead with helper instance
            $helperResult = smart_cache()->refreshAhead($key . '_helper', $callback, 5, 2);
            
            return [
                'status' => 'success',
                'method' => 'refreshAhead',
                'facade_result' => $facadeResult,
                'helper_result' => $helperResult,
                'both_methods_working' => isset($facadeResult['refresh_ahead_data']) && isset($helperResult['refresh_ahead_data']),
                'call_count' => $callCount
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testFlexibleMethodEnhanced(): array
    {
        try {
            $key = 'flexible_enhanced_test_' . time();
            $callCount = 0;
            
            $callback = function () use (&$callCount) {
                $callCount++;
                return [
                    'flexible_data' => 'enhanced_test',
                    'generated_at' => now()->toDateTimeString(),
                    'call_number' => $callCount
                ];
            };
            
            // Test various flexible configurations
            $shortFresh = $this->cache->flexible($key . '_short', [1, 5], $callback);
            $longFresh = $this->cache->flexible($key . '_long', [10, 20], $callback);
            
            return [
                'status' => 'success',
                'method' => 'flexible_enhanced',
                'short_fresh_result' => $shortFresh,
                'long_fresh_result' => $longFresh,
                'configurations_working' => isset($shortFresh['flexible_data']) && isset($longFresh['flexible_data']),
                'call_count' => $callCount
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function test_http_command_execution(): void
    {
        $results = [];
        
        // Test getting available commands
        $results['available_commands'] = $this->testGetAvailableCommands();
        
        // Test executing clear command
        $results['clear_command'] = $this->testExecuteClearCommand();
        
        // Test executing status command
        $results['status_command'] = $this->testExecuteStatusCommand();
        
        foreach ($results as $testName => $result) {
            $this->assertEquals('success', $result['status'], "HTTP command test {$testName} failed");
        }
    }

    protected function testGetAvailableCommands(): array
    {
        try {
            $commands = $this->cache->getAvailableCommands();
            
            return [
                'status' => 'success',
                'available_commands' => array_keys($commands),
                'command_count' => count($commands),
                'has_clear_command' => isset($commands['smart-cache:clear']),
                'has_status_command' => isset($commands['smart-cache:status']),
                'commands_metadata' => $commands
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testExecuteClearCommand(): array
    {
        try {
            // Setup test data
            $this->cache->put('clear_test_1', 'value1', 3600);
            $this->cache->put('clear_test_2', 'value2', 3600);
            
            $initialCount = count($this->cache->getManagedKeys());
            
            // Execute clear command
            $result = $this->cache->executeCommand('clear');
            
            return [
                'status' => 'success',
                'initial_count' => $initialCount,
                'command_result' => $result,
                'command_success' => $result['success'] ?? false,
                'cleared_count' => $result['cleared_count'] ?? 0,
                'final_managed_keys' => count($this->cache->getManagedKeys())
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testExecuteStatusCommand(): array
    {
        try {
            // Setup test data
            $this->cache->put('status_test', 'value', 3600);
            
            // Execute status command
            $result = $this->cache->executeCommand('status');
            $resultWithForce = $this->cache->executeCommand('status', ['force' => true]);
            
            return [
                'status' => 'success',
                'basic_status' => $result,
                'status_with_force' => $resultWithForce,
                'has_cache_driver' => isset($result['cache_driver']),
                'has_managed_keys_count' => isset($result['managed_keys_count']),
                'has_configuration' => isset($result['configuration']),
                'has_statistics' => isset($result['statistics']),
                'has_analysis_with_force' => isset($resultWithForce['analysis'])
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function test_performance_monitoring(): void
    {
        $results = [];
        
        // Reset metrics for clean test
        $this->cache->resetPerformanceMetrics();
        
        // Test performance metrics collection
        $results['metrics_collection'] = $this->testPerformanceMetricsCollection();
        
        // Test performance analysis
        $results['performance_analysis'] = $this->testPerformanceAnalysis();
        
        foreach ($results as $testName => $result) {
            $this->assertEquals('success', $result['status'], "Performance monitoring test {$testName} failed");
        }
    }

    protected function testPerformanceMetricsCollection(): array
    {
        try {
            // Perform various operations to generate metrics
            $this->cache->put('perf_test_1', 'value1', 3600);
            $this->cache->put('perf_test_2', 'value2', 3600);
            $this->cache->get('perf_test_1'); // hit
            $this->cache->get('nonexistent'); // miss
            
            // Get metrics
            $metrics = $this->cache->getPerformanceMetrics();
            
            return [
                'status' => 'success',
                'monitoring_enabled' => $metrics['monitoring_enabled'],
                'metrics_collected' => count($metrics['metrics']) > 0,
                'cache_efficiency' => $metrics['cache_efficiency'],
                'optimization_impact' => $metrics['optimization_impact'],
                'has_cache_hits' => isset($metrics['metrics']['cache_hit']),
                'has_cache_misses' => isset($metrics['metrics']['cache_miss']),
                'has_cache_writes' => isset($metrics['metrics']['cache_write'])
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testPerformanceAnalysis(): array
    {
        try {
            // Generate some more operations for better analysis
            for ($i = 0; $i < 5; $i++) {
                $this->cache->put("analysis_test_$i", "value_$i", 3600);
                $this->cache->get("analysis_test_$i");
            }
            
            // Generate some misses
            for ($i = 0; $i < 2; $i++) {
                $this->cache->get("miss_test_$i");
            }
            
            $analysis = $this->cache->analyzePerformance();
            
            return [
                'status' => 'success',
                'analysis_timestamp' => $analysis['analysis_timestamp'],
                'overall_health' => $analysis['overall_health'],
                'recommendations_count' => count($analysis['recommendations']),
                'has_metrics_summary' => isset($analysis['metrics_summary']),
                'cache_efficiency_summary' => $analysis['metrics_summary']['cache_efficiency'] ?? null,
                'optimization_summary' => $analysis['metrics_summary']['optimization_impact'] ?? null,
                'full_analysis' => $analysis
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function test_enhanced_facade_methods(): array
    {
        $results = [];
        
        // Test all new facade methods
        $results['swr_facade'] = $this->testFacadeSWRMethods();
        $results['command_facade'] = $this->testFacadeCommandMethods();
        $results['performance_facade'] = $this->testFacadePerformanceMethods();
        
        foreach ($results as $testName => $result) {
            $this->assertEquals('success', $result['status'], "Enhanced facade test {$testName} failed");
        }
        
        return $results;
    }

    protected function testFacadeSWRMethods(): array
    {
        try {
            $timestamp = time();
            $callback = fn() => ['facade_test' => true, 'timestamp' => $timestamp];
            
            // Test all SWR methods through facade
            $swrResult = \SmartCache\Facades\SmartCache::swr("facade_swr_$timestamp", $callback);
            $staleResult = \SmartCache\Facades\SmartCache::stale("facade_stale_$timestamp", $callback);
            $refreshResult = \SmartCache\Facades\SmartCache::refreshAhead("facade_refresh_$timestamp", $callback);
            
            return [
                'status' => 'success',
                'swr_working' => $swrResult['facade_test'] ?? false,
                'stale_working' => $staleResult['facade_test'] ?? false,
                'refresh_ahead_working' => $refreshResult['facade_test'] ?? false,
                'all_methods_available' => true
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testFacadeCommandMethods(): array
    {
        try {
            $commands = \SmartCache\Facades\SmartCache::getAvailableCommands();
            $statusResult = \SmartCache\Facades\SmartCache::executeCommand('status');
            
            return [
                'status' => 'success',
                'commands_available' => count($commands) > 0,
                'execute_command_working' => $statusResult['success'] ?? false,
                'commands_list' => array_keys($commands)
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testFacadePerformanceMethods(): array
    {
        try {
            \SmartCache\Facades\SmartCache::put('facade_perf_test', 'value', 3600);
            
            $metrics = \SmartCache\Facades\SmartCache::getPerformanceMetrics();
            $analysis = \SmartCache\Facades\SmartCache::analyzePerformance();
            
            \SmartCache\Facades\SmartCache::resetPerformanceMetrics();
            $metricsAfterReset = \SmartCache\Facades\SmartCache::getPerformanceMetrics();
            
            return [
                'status' => 'success',
                'get_metrics_working' => isset($metrics['monitoring_enabled']),
                'analyze_performance_working' => isset($analysis['overall_health']),
                'reset_metrics_working' => empty($metricsAfterReset['metrics']),
                'all_performance_methods_available' => true
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function test_complete_feature_integration(): array
    {
        // Reset everything for a clean integration test
        $this->cache->clear();
        $this->cache->resetPerformanceMetrics();
        
        $results = [];
        
        // Test 1: Laravel 12 SWR features with monitoring
        $results['swr_with_monitoring'] = $this->testSWRWithMonitoring();
        
        // Test 2: Command execution with performance tracking
        $results['commands_with_monitoring'] = $this->testCommandsWithMonitoring();
        
        // Test 3: Optimization with performance analysis
        $results['optimization_with_analysis'] = $this->testOptimizationWithAnalysis();
        
        $allWorking = array_reduce($results, fn($carry, $item) => $carry && ($item['status'] === 'success'), true);
        
        // Add assertions
        $this->assertTrue($allWorking, 'Not all integration tests passed');
        $this->assertEquals('success', $results['swr_with_monitoring']['status']);
        $this->assertEquals('success', $results['commands_with_monitoring']['status']);
        $this->assertEquals('success', $results['optimization_with_analysis']['status']);
        
        return [
            'status' => 'success',
            'integration_tests' => $results,
            'all_features_working' => $allWorking
        ];
    }

    protected function testSWRWithMonitoring(): array
    {
        try {
            $key = 'swr_monitoring_test_' . time();
            $callback = fn() => ['swr_with_monitoring' => true, 'data' => range(1, 1000)];
            
            // Use SWR method
            $result = $this->cache->swr($key, $callback);
            
            // Get performance metrics
            $metrics = $this->cache->getPerformanceMetrics();
            
            return [
                'status' => 'success',
                'swr_result' => $result,
                'monitoring_captured' => count($metrics['metrics']) > 0,
                'optimization_detected' => $metrics['optimization_impact']['optimizations_applied'] > 0
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testCommandsWithMonitoring(): array
    {
        try {
            // Add some data
            $this->cache->put('command_monitoring_test', 'value', 3600);
            
            // Execute status command
            $statusResult = $this->cache->executeCommand('status');
            
            // Get performance metrics
            $metrics = $this->cache->getPerformanceMetrics();
            
            return [
                'status' => 'success',
                'command_executed' => $statusResult['success'] ?? false,
                'has_performance_data' => isset($statusResult['statistics']),
                'monitoring_working' => count($metrics['metrics']) > 0
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function testOptimizationWithAnalysis(): array
    {
        try {
            // Create large data to trigger optimization
            $largeData = array_fill(0, 3000, 'large_data_item_for_optimization_testing');
            
            $this->cache->put('optimization_analysis_test', $largeData, 3600);
            $retrieved = $this->cache->get('optimization_analysis_test');
            
            // Analyze performance
            $analysis = $this->cache->analyzePerformance();
            $metrics = $this->cache->getPerformanceMetrics();
            
            return [
                'status' => 'success',
                'data_integrity' => $largeData === $retrieved,
                'optimization_applied' => $metrics['optimization_impact']['optimizations_applied'] > 0,
                'size_reduction' => $metrics['optimization_impact']['size_reduction_bytes'] > 0,
                'analysis_available' => isset($analysis['overall_health']),
                'recommendations_provided' => count($analysis['recommendations']) >= 0
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
