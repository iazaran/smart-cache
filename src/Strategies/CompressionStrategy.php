<?php

namespace SmartCache\Strategies;

use SmartCache\Contracts\OptimizationStrategy;

class CompressionStrategy implements OptimizationStrategy
{
    /**
     * Arrays with at most this many top-level items are measured exactly.
     */
    private const EXACT_SIZE_MAX_ITEMS = 50;

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
        // Guard: zlib extension is required for gzencode/gzdecode
        if (!function_exists('gzencode')) {
            return false;
        }

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

        try {
            // Arrays with few top-level items, such as ['data' => $rows, 'meta' => $meta]
            // or a model's toArray(), are measured exactly: one large value can sit
            // anywhere among them. Longer arrays are estimated from the serialized size
            // of a few sample entries.
            if (is_array($value) && count($value) > self::EXACT_SIZE_MAX_ITEMS) {
                $count = count($value);
                $sampleSize = min(5, $count);
                $sampleBytes = 0;
                $sampled = 0;

                // Each entry serializes as its key followed by its value; long keys
                // with small values would otherwise be badly underestimated.
                foreach ($value as $itemKey => $item) {
                    if ($sampled >= $sampleSize) {
                        break;
                    }
                    $sampleBytes += strlen(serialize($itemKey)) + strlen(serialize($item));
                    $sampled++;
                }

                $estimate = (int) ceil($sampleBytes / $sampleSize) * $count;
                // If clearly below threshold, skip
                if ($estimate < $this->threshold / 2) {
                    return false;
                }
                // If clearly above threshold, apply
                if ($estimate > $this->threshold * 2) {
                    return true;
                }
            }

            // Serialize small arrays, borderline cases, and objects
            return strlen(serialize($value)) > $this->threshold;
        } catch (\Throwable $e) {
            // Values that cannot be serialized cannot be compressed either.
            return false;
        }
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

        if (!isset($value['data']) || !is_string($value['data'])) {
            throw new \RuntimeException('SmartCache compressed payload is missing the "data" field.');
        }

        $decoded = base64_decode($value['data'], true);
        if ($decoded === false) {
            throw new \RuntimeException('SmartCache compressed payload contains invalid base64 data.');
        }

        $decompressed = @gzdecode($decoded);
        if ($decompressed === false) {
            throw new \RuntimeException('SmartCache compressed payload failed gzdecode (corrupted gzip stream).');
        }

        if (!empty($value['is_string'])) {
            return $decompressed;
        }

        // set_error_handler() pushes onto a stack; passing the previous handler back
        // to it pushes a second frame instead of popping ours. Left unbalanced, the
        // stack grows on every restore() and the application's own
        // restore_error_handler() pops the wrong frame — leaving this
        // error-swallowing closure active and silently discarding app warnings.
        \set_error_handler(static function (): bool {
            return true;
        });
        try {
            $restored = \unserialize($decompressed);
        } finally {
            \restore_error_handler();
        }

        if ($restored === false && $decompressed !== \serialize(false)) {
            throw new \RuntimeException('SmartCache compressed payload failed unserialize (corrupted serialized data).');
        }

        return $restored;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'compression';
    }
} 