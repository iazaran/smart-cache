<?php

namespace SmartCache\Tests\Feature;

use Illuminate\Support\Facades\Route;
use SmartCache\Http\Controllers\StatisticsController;
use SmartCache\Services\CircuitBreaker;
use SmartCache\Tests\TestCase;

/**
 * Covers the dashboard HTTP surface, which previously had no test coverage.
 */
class DashboardSecurityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('smart-cache.dashboard', [
            'enabled' => true,
            'prefix' => 'smart-cache',
            'middleware' => [],
        ]);
    }

    public function test_dashboard_endpoints_respond_when_enabled(): void
    {
        $this->get('/smart-cache/')->assertOk();
        $this->getJson('/smart-cache/statistics')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/smart-cache/health')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/smart-cache/keys')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/smart-cache/commands')->assertOk()->assertJsonPath('success', true);
    }

    public function test_endpoints_404_when_the_dashboard_is_disabled_but_routes_are_still_registered(): void
    {
        // Reproduces a `route:cache` taken while the dashboard was enabled: the
        // routes stay in the compiled file after the config is switched off, so
        // the controller itself has to refuse.
        config(['smart-cache.dashboard.enabled' => false]);

        $this->get('/smart-cache/')->assertNotFound();
        $this->getJson('/smart-cache/statistics')->assertNotFound();
        $this->getJson('/smart-cache/health')->assertNotFound();
        $this->getJson('/smart-cache/keys')->assertNotFound();
        $this->getJson('/smart-cache/commands')->assertNotFound();
    }

    public function test_dashboard_escapes_the_circuit_breaker_state(): void
    {
        $controller = new StatisticsController();

        $render = new \ReflectionMethod($controller, 'renderDashboard');
        $render->setAccessible(true);

        $html = $render->invoke($controller, [
            'managed_keys' => [],
            'performance' => [],
            'circuit_breaker' => ['state' => '"><script>alert(1)</script>'],
            'statistics' => [],
            'health' => [],
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // The CSS class is built from a restricted character set, so a hostile
        // value cannot break out of the attribute either.
        $this->assertStringContainsString('class="value status-scriptalertscript"', $html);
    }

    public function test_dashboard_tolerates_a_non_string_circuit_breaker_state(): void
    {
        $controller = new StatisticsController();

        $render = new \ReflectionMethod($controller, 'renderDashboard');
        $render->setAccessible(true);

        $html = $render->invoke($controller, [
            'managed_keys' => [],
            'performance' => [],
            'circuit_breaker' => ['state' => ['unexpected' => 'array']],
            'statistics' => [],
            'health' => [],
        ]);

        $this->assertStringContainsString('unknown', $html);
    }

    public function test_circuit_breaker_ignores_an_unrecognised_shared_state(): void
    {
        $cache = $this->app['cache']->store('array');

        $breaker = new CircuitBreaker(5, 30, 3);
        $breaker->enableSharedState($cache, '_sc_circuit_breaker:test', 300);

        $cache->put('_sc_circuit_breaker:test', [
            'state' => '"><script>alert(1)</script>',
            'failure_count' => 0,
            'success_count' => 0,
        ], 300);

        // A poisoned shared entry must not become the breaker's state.
        $this->assertContains($breaker->getStats()['state'], ['closed', 'open', 'half_open']);
    }

    public function test_circuit_breaker_still_accepts_a_legitimate_shared_state(): void
    {
        $cache = $this->app['cache']->store('array');

        $breaker = new CircuitBreaker(5, 30, 3);
        $breaker->enableSharedState($cache, '_sc_circuit_breaker:test2', 300);

        $cache->put('_sc_circuit_breaker:test2', [
            'state' => 'open',
            'failure_count' => 7,
            'success_count' => 0,
            'opened_at' => time(),
        ], 300);

        $this->assertSame('open', $breaker->getStats()['state']);
    }
}
