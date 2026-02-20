<?php

namespace SmartCache\Tests;

use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SmartCache\Providers\SmartCacheServiceProvider;

/**
 * Base TestCase for SmartCache package tests
 * 
 * This class provides a minimal Laravel environment using Orchestra Testbench
 * which allows testing Laravel packages without a full installation.
 */
abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Additional setup can go here
        $this->loadPackageConfiguration();
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SmartCacheServiceProvider::class,
        ];
    }

    /**
     * Define aliases.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'SmartCache' => \SmartCache\Facades\SmartCache::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Setup the application environment
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);
        
        // Setup cache configuration
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        
        $app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => __DIR__ . '/storage/framework/cache',
        ]);

        // Setup SmartCache configuration
        $app['config']->set('smart-cache', [
            'strategies' => [
                'compression' => [
                    'enabled' => true,
                    'level' => 6,
                ],
                'chunking' => [
                    'enabled' => true,
                    'chunk_size' => 1000,
                ],
            ],
            'thresholds' => [
                'compression' => 1024, // 1KB for testing (lower than default)
                'chunking' => 2048,    // 2KB for testing (lower than default)
            ],
            'fallback' => [
                'enabled' => true,
                'log_errors' => false, // Disable logging in tests
            ],
            'cost_aware' => [
                'enabled' => true,
                'max_tracked_keys' => 100,
                'metadata_ttl' => 3600,
            ],
            'deduplication' => [
                'enabled' => false,
            ],
            'self_healing' => [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Load package configuration for testing.
     */
    protected function loadPackageConfiguration(): void
    {
        // This method can be overridden in individual test classes
        // to provide specific configuration for different test scenarios
    }

    /**
     * Get a cache manager instance for testing.
     */
    protected function getCacheManager(): CacheManager
    {
        return $this->app['cache'];
    }

    /**
     * Get a specific cache store for testing.
     */
    protected function getCacheStore(string|null $store = null)
    {
        return $this->getCacheManager()->store($store);
    }

    /**
     * Create a large test dataset for optimization testing.
     */
    protected function createLargeTestData(int $size = 2000): array
    {
        $data = [];
        for ($i = 0; $i < $size; $i++) {
            $data[] = [
                'id' => $i,
                'name' => 'Test Item ' . $i,
                'description' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 10),
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'tags' => ['tag1', 'tag2', 'tag3'],
                    'nested' => [
                        'level1' => [
                            'level2' => [
                                'data' => str_repeat('nested data ', 20)
                            ]
                        ]
                    ]
                ]
            ];
        }
        
        return $data;
    }

    /**
     * Create test data that should trigger compression.
     */
    protected function createCompressibleData(): string
    {
        // Create repetitive string that compresses well
        return str_repeat('This is test data that should compress well. ', 100);
    }

    /**
     * Create test data that should trigger chunking.
     */
    protected function createChunkableData(): array
    {
        return $this->createLargeTestData(1200); // Creates much larger data to ensure chunking threshold is met
    }

    /**
     * Assert that a value is optimized (not the original value).
     */
    protected function assertValueIsOptimized($original, $cached): void
    {
        $this->assertNotEquals($original, $cached, 'Value should be optimized and different from original');
    }

    /**
     * Assert that a value has compression metadata.
     */
    protected function assertValueIsCompressed($value): void
    {
        $this->assertIsArray($value, 'Compressed value should be an array');
        $this->assertArrayHasKey('_sc_compressed', $value, 'Compressed value should have compression marker');
        $this->assertTrue($value['_sc_compressed'], 'Compression marker should be true');
        $this->assertArrayHasKey('data', $value, 'Compressed value should have data key');
    }

    /**
     * Assert that a value has chunking metadata.
     */
    protected function assertValueIsChunked($value): void
    {
        $this->assertIsArray($value, 'Chunked value should be an array');
        $this->assertArrayHasKey('_sc_chunked', $value, 'Chunked value should have chunking marker');
        $this->assertTrue($value['_sc_chunked'], 'Chunking marker should be true');
        $this->assertArrayHasKey('chunk_keys', $value, 'Chunked value should have chunk_keys');
        $this->assertArrayHasKey('total_items', $value, 'Chunked value should have total_items');
    }
}
