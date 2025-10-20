<?php

namespace SmartCache\Services;

/**
 * Smart Chunk Size Calculator
 * 
 * Dynamically calculates optimal chunk sizes based on:
 * - Cache driver limitations
 * - Data characteristics (item size, total size)
 * - Performance considerations
 */
class SmartChunkSizeCalculator
{
    /**
     * Driver-specific maximum value sizes (in bytes).
     *
     * @var array
     */
    protected array $driverLimits = [
        'redis' => 512 * 1024 * 1024, // 512MB
        'memcached' => 1024 * 1024,   // 1MB
        'file' => PHP_INT_MAX,         // No practical limit
        'database' => 16 * 1024 * 1024, // 16MB (typical BLOB limit)
        'dynamodb' => 400 * 1024,      // 400KB
        'array' => PHP_INT_MAX,        // No limit
        'default' => 1024 * 1024,      // 1MB default
    ];

    /**
     * Calculate optimal chunk size for the given data and driver.
     *
     * @param array $data
     * @param string|null $driver
     * @param int $defaultChunkSize
     * @return int
     */
    public function calculateOptimalSize(array $data, ?string $driver = null, int $defaultChunkSize = 1000): int
    {
        // Get driver limit
        $maxValueSize = $this->getDriverLimit($driver);
        
        // Analyze data characteristics
        $totalItems = count($data);
        $avgItemSize = $this->calculateAverageItemSize($data);
        $totalSize = $totalItems * $avgItemSize;
        
        // If data is small, don't chunk
        if ($totalSize < 100 * 1024) { // < 100KB
            return $totalItems; // Return all items in one chunk
        }
        
        // Calculate chunk size based on driver limit
        // Use 80% of max size to leave safety margin
        $safeMaxSize = (int) ($maxValueSize * 0.8);
        $optimalChunkSize = $avgItemSize > 0 
            ? (int) floor($safeMaxSize / $avgItemSize)
            : $defaultChunkSize;
        
        // Ensure minimum chunk size
        $optimalChunkSize = max($optimalChunkSize, 100);
        
        // Adjust based on total data size
        if ($totalSize > 10 * 1024 * 1024) { // > 10MB
            // Use smaller chunks for very large datasets
            $optimalChunkSize = min($optimalChunkSize, 500);
        } elseif ($totalSize < 1024 * 1024) { // < 1MB
            // Use larger chunks for smaller datasets
            $optimalChunkSize = min($optimalChunkSize, 5000);
        }
        
        // Ensure we don't exceed total items
        $optimalChunkSize = min($optimalChunkSize, $totalItems);
        
        return $optimalChunkSize;
    }

    /**
     * Get the maximum value size for a driver.
     *
     * @param string|null $driver
     * @return int
     */
    public function getDriverLimit(?string $driver): int
    {
        if (!$driver) {
            return $this->driverLimits['default'];
        }
        
        return $this->driverLimits[$driver] ?? $this->driverLimits['default'];
    }

    /**
     * Calculate the average item size in the array.
     *
     * @param array $data
     * @param int $sampleSize
     * @return int
     */
    protected function calculateAverageItemSize(array $data, int $sampleSize = 100): int
    {
        if (empty($data)) {
            return 0;
        }
        
        $totalItems = count($data);
        $samplesToTake = min($sampleSize, $totalItems);
        
        // Sample random items
        $samples = array_rand($data, $samplesToTake);
        if (!is_array($samples)) {
            $samples = [$samples];
        }
        
        $totalSize = 0;
        foreach ($samples as $key) {
            $totalSize += strlen(serialize($data[$key]));
        }
        
        return (int) ceil($totalSize / $samplesToTake);
    }

    /**
     * Calculate the number of chunks needed.
     *
     * @param int $totalItems
     * @param int $chunkSize
     * @return int
     */
    public function calculateChunkCount(int $totalItems, int $chunkSize): int
    {
        return (int) ceil($totalItems / $chunkSize);
    }

    /**
     * Get chunking recommendations for the given data.
     *
     * @param array $data
     * @param string|null $driver
     * @return array
     */
    public function getRecommendations(array $data, ?string $driver = null): array
    {
        $totalItems = count($data);
        $avgItemSize = $this->calculateAverageItemSize($data);
        $totalSize = $totalItems * $avgItemSize;
        $optimalChunkSize = $this->calculateOptimalSize($data, $driver);
        $chunkCount = $this->calculateChunkCount($totalItems, $optimalChunkSize);
        
        return [
            'total_items' => $totalItems,
            'total_size' => $totalSize,
            'avg_item_size' => $avgItemSize,
            'optimal_chunk_size' => $optimalChunkSize,
            'chunk_count' => $chunkCount,
            'driver' => $driver ?? 'default',
            'driver_limit' => $this->getDriverLimit($driver),
            'should_chunk' => $totalSize > 100 * 1024, // Recommend chunking for > 100KB
            'estimated_chunk_size' => $optimalChunkSize * $avgItemSize,
        ];
    }

    /**
     * Validate that chunk size is safe for the driver.
     *
     * @param int $chunkSize
     * @param int $avgItemSize
     * @param string|null $driver
     * @return bool
     */
    public function isChunkSizeSafe(int $chunkSize, int $avgItemSize, ?string $driver = null): bool
    {
        $estimatedChunkBytes = $chunkSize * $avgItemSize;
        $driverLimit = $this->getDriverLimit($driver);
        
        // Use 80% of limit as safety margin
        return $estimatedChunkBytes <= ($driverLimit * 0.8);
    }

    /**
     * Set custom driver limit.
     *
     * @param string $driver
     * @param int $limit
     * @return void
     */
    public function setDriverLimit(string $driver, int $limit): void
    {
        $this->driverLimits[$driver] = $limit;
    }

    /**
     * Get all driver limits.
     *
     * @return array
     */
    public function getDriverLimits(): array
    {
        return $this->driverLimits;
    }
}

