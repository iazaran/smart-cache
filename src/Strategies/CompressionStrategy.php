<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;

class CompressionStrategy implements OptimizationStrategy
{
    /**
     * @var int
     */
    protected int $threshold;

    /**
     * @var int
     */
    protected int $level;

    /**
     * CompressionStrategy constructor.
     *
     * @param int $threshold Size in bytes that triggers compression
     * @param int $level Compression level (0-9)
     */
    public function __construct(int $threshold = 51200, int $level = 6)
    {
        $this->threshold = $threshold;
        $this->level = $level;
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

        // For strings, use strlen directly (no serialization needed)
        if (is_string($value)) {
            return strlen($value) > $this->threshold;
        }

        // For arrays, use a quick estimate: count * average-item-size
        // Only serialize if the estimate is close to the threshold
        if (is_array($value)) {
            $count = count($value);
            // Rough estimate: each item ~50 bytes on average
            $estimate = $count * 50;
            // If clearly below threshold, skip
            if ($estimate < $this->threshold / 2) {
                return false;
            }
            // If clearly above threshold, apply
            if ($estimate > $this->threshold * 2) {
                return true;
            }
        }

        // Fall back to serialize for borderline cases and objects
        return strlen(serialize($value)) > $this->threshold;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        $isString = is_string($value);
        $data = $isString ? $value : serialize($value);
        
        $compressed = gzencode($data, $this->level);
        
        return [
            '_sc_compressed' => true,
            'data' => base64_encode($compressed),
            'is_string' => $isString,
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
        return 'compression';
    }
} 