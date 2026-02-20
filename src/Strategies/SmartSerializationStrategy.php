<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;

/**
 * Smart Serialization Strategy
 * 
 * Automatically selects the best serialization method based on data type:
 * - JSON for simple arrays (faster, more compact, cross-platform)
 * - igbinary for complex data (more compact than PHP serialize)
 * - PHP serialize as fallback
 */
class SmartSerializationStrategy implements OptimizationStrategy
{
    /**
     * @var string
     */
    protected string $preferredMethod;

    /**
     * @var bool
     */
    protected bool $autoDetect;

    /**
     * @var int Minimum size in bytes to apply serialization optimization
     */
    protected int $sizeThreshold;

    /**
     * SmartSerializationStrategy constructor.
     *
     * @param string $preferredMethod Preferred serialization method (auto, json, igbinary, php)
     * @param bool $autoDetect Auto-detect best method
     * @param int $sizeThreshold Minimum size in bytes to apply optimization (default: 1024)
     */
    public function __construct(string $preferredMethod = 'auto', bool $autoDetect = true, int $sizeThreshold = 1024)
    {
        $this->preferredMethod = $preferredMethod;
        $this->autoDetect = $autoDetect;
        $this->sizeThreshold = $sizeThreshold;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, array $context = []): bool
    {
        // Quick type check: primitives and short strings are always below threshold
        if (\is_int($value) || \is_float($value) || \is_bool($value) || $value === null) {
            return false;
        }

        if (\is_string($value)) {
            return \strlen($value) >= $this->sizeThreshold;
        }

        // For arrays, estimate size before serializing
        if (\is_array($value)) {
            $count = \count($value);
            $estimate = $count * 50;
            if ($estimate < $this->sizeThreshold / 2) {
                return false;
            }
            if ($estimate > $this->sizeThreshold * 2) {
                return true;
            }
        }

        // Fall back to serialize for borderline cases and objects
        return \strlen(\serialize($value)) >= $this->sizeThreshold;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(mixed $value, array $context = []): mixed
    {
        $method = $this->selectSerializationMethod($value);
        
        return [
            '_sc_serialized' => true,
            'method' => $method,
            'data' => $this->serialize($value, $method),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function restore(mixed $value, array $context = []): mixed
    {
        if (!is_array($value) || !isset($value['_sc_serialized']) || $value['_sc_serialized'] !== true) {
            return $value;
        }
        
        return $this->unserialize($value['data'], $value['method']);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'smart_serialization';
    }

    /**
     * Select the best serialization method for the given value.
     *
     * @param mixed $value
     * @return string
     */
    protected function selectSerializationMethod(mixed $value): string
    {
        // If not auto-detecting, use preferred method
        if (!$this->autoDetect) {
            return $this->getAvailableMethod($this->preferredMethod);
        }
        
        // Auto-detect best method
        
        // Try JSON first for simple data (fastest and most compact)
        if ($this->isJsonSafe($value)) {
            return 'json';
        }
        
        // Use igbinary if available (more compact than PHP serialize)
        if (function_exists('igbinary_serialize')) {
            return 'igbinary';
        }
        
        // Fallback to PHP serialize
        return 'php';
    }

    /**
     * Check if a value can be safely serialized with JSON.
     *
     * Uses a single json_encode call which natively detects all unsupported types
     * (resources, closures, non-stdClass objects with private state, etc.).
     *
     * @param mixed $value
     * @return bool
     */
    protected function isJsonSafe(mixed $value): bool
    {
        // Quick rejection for types that json_encode cannot handle
        if (\is_resource($value) || $value instanceof \Closure) {
            return false;
        }

        if (\is_object($value) && !($value instanceof \stdClass)) {
            return false;
        }

        // Single encode attempt — catches nested issues too
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== false;
    }

    /**
     * Get an available serialization method.
     *
     * @param string $method
     * @return string
     */
    protected function getAvailableMethod(string $method): string
    {
        if ($method === 'auto') {
            return 'php';
        }
        
        if ($method === 'igbinary' && !function_exists('igbinary_serialize')) {
            return 'php';
        }
        
        return $method;
    }

    /**
     * Serialize a value using the specified method.
     *
     * @param mixed $value
     * @param string $method
     * @return string
     */
    protected function serialize(mixed $value, string $method): string
    {
        return match ($method) {
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'igbinary' => base64_encode(igbinary_serialize($value)),
            'php' => base64_encode(serialize($value)),
            default => base64_encode(serialize($value)),
        };
    }

    /**
     * Unserialize a value using the specified method.
     *
     * @param string $data
     * @param string $method
     * @return mixed
     */
    protected function unserialize(string $data, string $method): mixed
    {
        return match ($method) {
            'json' => json_decode($data, true),
            'igbinary' => igbinary_unserialize(base64_decode($data)),
            'php' => unserialize(base64_decode($data)),
            default => unserialize(base64_decode($data)),
        };
    }

    /**
     * Get serialization statistics for monitoring.
     *
     * @param mixed $value
     * @return array
     */
    public function getSerializationStats(mixed $value): array
    {
        $stats = [];
        
        // Test JSON
        if ($this->isJsonSafe($value)) {
            $jsonData = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stats['json'] = [
                'size' => strlen($jsonData),
                'available' => true,
            ];
        } else {
            $stats['json'] = [
                'size' => null,
                'available' => false,
            ];
        }
        
        // Test igbinary
        if (function_exists('igbinary_serialize')) {
            $igbinaryData = igbinary_serialize($value);
            $stats['igbinary'] = [
                'size' => strlen($igbinaryData),
                'available' => true,
            ];
        } else {
            $stats['igbinary'] = [
                'size' => null,
                'available' => false,
            ];
        }
        
        // Test PHP serialize
        $phpData = serialize($value);
        $stats['php'] = [
            'size' => strlen($phpData),
            'available' => true,
        ];
        
        // Determine best method
        $bestMethod = 'php';
        $bestSize = $stats['php']['size'];
        
        if ($stats['json']['available'] && $stats['json']['size'] < $bestSize) {
            $bestMethod = 'json';
            $bestSize = $stats['json']['size'];
        }
        
        if ($stats['igbinary']['available'] && $stats['igbinary']['size'] < $bestSize) {
            $bestMethod = 'igbinary';
            $bestSize = $stats['igbinary']['size'];
        }
        
        $stats['recommended'] = $bestMethod;
        $stats['best_size'] = $bestSize;
        
        return $stats;
    }
}

