<?php

namespace SmartCache\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SmartCache\Facades\SmartCache;

/**
 * Cache Statistics Dashboard Controller
 * 
 * Provides endpoints for viewing cache statistics and health information.
 */
class StatisticsController extends Controller
{
    /**
     * Refuse to serve when the dashboard is switched off.
     *
     * The routes are registered from the service provider, so a `route:cache`
     * taken while the dashboard was enabled bakes them into the compiled route
     * file. Disabling `dashboard.enabled` afterwards would otherwise leave every
     * endpoint live until the routes were re-cached.
     *
     * @return void
     */
    protected function ensureDashboardEnabled(): void
    {
        if (!config('smart-cache.dashboard.enabled', false)) {
            abort(404);
        }
    }

    /**
     * Get cache statistics.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $this->ensureDashboardEnabled();

        $cache = SmartCache::getFacadeRoot();
        
        return response()->json([
            'success' => true,
            'data' => [
                'statistics' => $cache->getStatistics(),
                'performance' => $cache->getPerformanceMetrics(),
                'managed_keys_count' => \count($cache->getManagedKeys()),
                'circuit_breaker' => $cache->getCircuitBreakerStats(),
            ],
        ]);
    }

    /**
     * Get health check information.
     *
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        $this->ensureDashboardEnabled();

        $cache = SmartCache::getFacadeRoot();
        
        return response()->json([
            'success' => true,
            'data' => $cache->healthCheck(),
        ]);
    }

    /**
     * Get managed keys.
     *
     * @return JsonResponse
     */
    public function keys(): JsonResponse
    {
        $this->ensureDashboardEnabled();

        $cache = SmartCache::getFacadeRoot();
        
        return response()->json([
            'success' => true,
            'data' => [
                'keys' => $cache->getManagedKeys(),
                'count' => \count($cache->getManagedKeys()),
            ],
        ]);
    }

    /**
     * Get available commands.
     *
     * @return JsonResponse
     */
    public function commands(): JsonResponse
    {
        $this->ensureDashboardEnabled();

        $cache = SmartCache::getFacadeRoot();
        
        return response()->json([
            'success' => true,
            'data' => $cache->getAvailableCommands(),
        ]);
    }

    /**
     * Get dashboard HTML view.
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard(): \Illuminate\Http\Response
    {
        $this->ensureDashboardEnabled();

        $cache = SmartCache::getFacadeRoot();
        
        $data = [
            'statistics' => $cache->getStatistics(),
            'performance' => $cache->getPerformanceMetrics(),
            'managed_keys' => $cache->getManagedKeys(),
            'circuit_breaker' => $cache->getCircuitBreakerStats(),
            'health' => $cache->healthCheck(),
        ];

        $html = $this->renderDashboard($data);
        
        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Render the dashboard HTML.
     */
    protected function renderDashboard(array $data): string
    {
        $keysCount = \count($data['managed_keys']);
        $perf = $data['performance'];

        // getPerformanceMetrics() reports this as cache_efficiency.hit_ratio.
        // Reading a non-existent top-level 'hit_rate' meant the card always
        // rendered "N/A". The old key is still honoured for any caller passing
        // a hand-built payload.
        $hitRateValue = $perf['cache_efficiency']['hit_ratio'] ?? $perf['hit_rate'] ?? null;
        $hitRate = \is_numeric($hitRateValue) ? \number_format((float) $hitRateValue, 2) . '%' : 'N/A';

        // The circuit-breaker state can originate from the cache when
        // `circuit_breaker.shared` is enabled, so it is not trusted input here.
        // Escape it before interpolating into the markup and the CSS class.
        $rawState = $data['circuit_breaker']['state'] ?? 'unknown';
        $rawState = \is_string($rawState) ? $rawState : 'unknown';

        $cbClass = \preg_replace('/[^a-z_]/', '', \strtolower($rawState)) ?: 'unknown';
        $cbState = \htmlspecialchars($rawState, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCache Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #666; font-size: 14px; text-transform: uppercase; margin-bottom: 10px; }
        .card .value { font-size: 32px; font-weight: bold; color: #333; }
        .card .label { color: #999; font-size: 12px; }
        .status-open { color: #e74c3c; }
        .status-closed { color: #27ae60; }
        .status-half_open { color: #f39c12; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; }
        th { color: #666; font-weight: 500; }
        .refresh { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .refresh:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 SmartCache Dashboard</h1>
        <button class="refresh" onclick="location.reload()">Refresh</button>
        <div class="grid" style="margin-top: 20px;">
            <div class="card">
                <h2>Managed Keys</h2>
                <div class="value">{$keysCount}</div>
                <div class="label">Total cached items</div>
            </div>
            <div class="card">
                <h2>Hit Rate</h2>
                <div class="value">{$hitRate}</div>
                <div class="label">Cache efficiency</div>
            </div>
            <div class="card">
                <h2>Circuit Breaker</h2>
                <div class="value status-{$cbClass}">{$cbState}</div>
                <div class="label">Backend status</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}

