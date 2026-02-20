<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;
use Illuminate\Contracts\Cache\Repository;

/**
 * Adaptive Compression Strategy
 * 
 * Automatically selects the best compression level based on:
 * - Data compressibility (sample-based analysis)
 * - Access frequency (hot data gets faster compression)
 * - Data size (larger data may benefit from higher compression)
 */
class AdaptiveCompressionStrategy implements OptimizationStrategy
{
    /**
     * @var int
     */
    protected int $threshold;

    /**
     * @var int
     */
    protected int $defaultLevel;

    /**
     * @var int
     */
    protected int $sampleSize;

    /**
     * @var float
     */
    protected float $highCompressionThreshold;

    /**
     * @var float
     */
    protected float $lowCompressionThreshold;

    /**
     * @var int
     */
    protected int $frequencyThreshold;

    /**
     * In-memory access frequency counters for the current request.
     *
     * @var array<string, int>
     */
    protected array $accessFrequency = [];

    /**
     * Whether frequency data has been loaded from cache.
     */
    protected bool $frequencyLoaded = false;

    /**
     * Whether frequency data has been modified and needs persisting.
     */
    protected bool $frequencyDirty = false;

    /**
     * Optional cache repository for persisting frequency data.
     */
    protected ?Repository $cache = null;

    /**
     * AdaptiveCompressionStrategy constructor.
     *
     * @param int $threshold Size in bytes that triggers compression
     * @param int $defaultLevel Default compression level (0-9)
     * @param int $sampleSize Bytes to sample for compressibility test
     * @param float $highCompressionThreshold Ratio below which to use level 9
     * @param float $lowCompressionThreshold Ratio above which to use level 3
     * @param int $frequencyThreshold Access count above which to prioritize speed
     */
    public function __construct(
        int $threshold = 51200,
        int $defaultLevel = 6,
        int $sampleSize = 1024,
        float $highCompressionThreshold = 0.5,
        float $lowCompressionThreshold = 0.7,
        int $frequencyThreshold = 100
    ) {
        $this->threshold = $threshold;
        $this->defaultLevel = $defaultLevel;
        $this->sampleSize = $sampleSize;
        $this->highCompressionThreshold = $highCompressionThreshold;
        $this->lowCompressionThreshold = $lowCompressionThreshold;
        $this->frequencyThreshold = $frequencyThreshold;
    }

    /**
     * Set the cache repository for persisting frequency data.
     *
     * @param Repository $cache
     * @return static
     */
    public function setCacheRepository(Repository $cache): static
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, array $context = []): bool
    {
        // Check if driver supports compression
        if (isset($context['driver']) && 
            isset($context['config']['drivers'][$context['driver']]['compression']) && 
            $context['config']['drivers'][$context['driver']]['compression'] === false) {
            return false;
        }

        // Only compress strings and serializable objects/arrays
        if (!is_string($value) && !is_array($value) && !is_object($value)) {
            return false;
        }

        // Convert to string to measure size
        $serialized = is_string($value) ? $value : serialize($value);
        
        return strlen($serialized) > $this->threshold;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        $isString = is_string($value);
        $data = $isString ? $value : serialize($value);
        
        // Select optimal compression level
        $level = $this->selectCompressionLevel($data, $context);
        
        $compressed = gzencode($data, $level);
        
        return [
            '_sc_compressed' => true,
            '_sc_adaptive' => true,
            'data' => base64_encode($compressed),
            'is_string' => $isString,
            'level' => $level,
            'original_size' => strlen($data),
            'compressed_size' => strlen($compressed),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function restore(mixed $value, array $context = []): mixed
    {
        if (!is_array($value) || !isset($value['_sc_compressed']) || $value['_sc_compressed'] !== true) {
            return $value;
        }
        
        $decompressed = gzdecode(base64_decode($value['data']));
        
        if ($value['is_string']) {
            return $decompressed;
        }
        
        return unserialize($decompressed);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'adaptive_compression';
    }

    /**
     * Select the optimal compression level based on data characteristics.
     *
     * @param string $data
     * @param array $context
     * @return int
     */
    protected function selectCompressionLevel(string $data, array $context): int
    {
        $dataSize = strlen($data);
        
        // For very small data, use fast compression
        if ($dataSize < $this->threshold * 2) {
            return 3;
        }
        
        // Test compressibility with a sample
        $sampleSize = min($this->sampleSize, $dataSize);
        $sample = substr($data, 0, $sampleSize);
        $testCompressed = gzcompress($sample, $this->defaultLevel);
        $compressionRatio = strlen($testCompressed) / strlen($sample);
        
        // Get access frequency from context
        $accessFrequency = $this->getAccessFrequency($context['key'] ?? null);
        
        // Determine level based on compressibility and access frequency
        $level = $this->defaultLevel;
        
        // If data compresses very well, use higher compression
        if ($compressionRatio < $this->highCompressionThreshold) {
            $level = 9;
        }
        // If data doesn't compress well, use lower compression
        elseif ($compressionRatio > $this->lowCompressionThreshold) {
            $level = 3;
        }
        // Medium compressibility, use default
        else {
            $level = $this->defaultLevel;
        }
        
        // For frequently accessed data, prioritize speed over compression ratio
        if ($accessFrequency > $this->frequencyThreshold) {
            $level = min($level, 3);
        }
        
        // For very large data, consider using higher compression to save space
        if ($dataSize > 1024 * 1024 * 10 && $level < 9) { // > 10MB
            $level = min($level + 2, 9);
        }
        
        return $level;
    }

    /**
     * Get the access frequency for a key.
     *
     * @param string|null $key
     * @return int
     */
    protected function getAccessFrequency(?string $key): int
    {
        if (!$key) {
            return 0;
        }

        $this->ensureFrequencyLoaded();

        return $this->accessFrequency[$key] ?? 0;
    }

    /**
     * Track access frequency for a key.
     *
     * @param string $key
     * @return void
     */
    public function trackAccess(string $key): void
    {
        $this->ensureFrequencyLoaded();

        $this->accessFrequency[$key] = ($this->accessFrequency[$key] ?? 0) + 1;
        $this->frequencyDirty = true;

        // Trim if tracking too many keys (keep top 500 by frequency)
        if (\count($this->accessFrequency) > 500) {
            \arsort($this->accessFrequency);
            $this->accessFrequency = \array_slice($this->accessFrequency, 0, 400, true);
        }
    }

    /**
     * Load persisted frequency data from cache.
     */
    protected function ensureFrequencyLoaded(): void
    {
        if ($this->frequencyLoaded) {
            return;
        }

        $this->frequencyLoaded = true;

        if ($this->cache === null) {
            return;
        }

        try {
            $persisted = $this->cache->get('_sc_adaptive_freq', []);
            if (\is_array($persisted)) {
                $this->accessFrequency = $persisted;
            }
        } catch (\Exception $e) {
            // Silently fail — in-memory tracking still works
        }
    }

    /**
     * Persist frequency data to cache.
     */
    public function persistFrequency(): void
    {
        if (!$this->frequencyDirty || $this->cache === null) {
            return;
        }

        try {
            $this->cache->put('_sc_adaptive_freq', $this->accessFrequency, 86400);
            $this->frequencyDirty = false;
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Persist frequency data on shutdown.
     */
    public function __destruct()
    {
        $this->persistFrequency();
    }

    /**
     * Get compression statistics for monitoring.
     *
     * @param mixed $optimizedValue
     * @return array
     */
    public function getCompressionStats(mixed $optimizedValue): array
    {
        if (!is_array($optimizedValue) || !isset($optimizedValue['_sc_adaptive'])) {
            return [];
        }
        
        return [
            'level' => $optimizedValue['level'] ?? null,
            'original_size' => $optimizedValue['original_size'] ?? 0,
            'compressed_size' => $optimizedValue['compressed_size'] ?? 0,
            'ratio' => $optimizedValue['compressed_size'] > 0 
                ? $optimizedValue['compressed_size'] / $optimizedValue['original_size'] 
                : 0,
            'savings_bytes' => ($optimizedValue['original_size'] ?? 0) - ($optimizedValue['compressed_size'] ?? 0),
            'savings_percent' => $optimizedValue['original_size'] > 0
                ? (1 - ($optimizedValue['compressed_size'] / $optimizedValue['original_size'])) * 100
                : 0,
        ];
    }
}

