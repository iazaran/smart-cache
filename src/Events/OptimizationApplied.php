<?php

namespace SmartCache\Events;

class OptimizationApplied
{
    /**
     * The key that was optimized.
     *
     * @var string
     */
    public string $key;

    /**
     * The optimization strategy that was applied.
     *
     * @var string
     */
    public string $strategy;

    /**
     * The original size in bytes.
     *
     * @var int
     */
    public int $originalSize;

    /**
     * The optimized size in bytes.
     *
     * @var int
     */
    public int $optimizedSize;

    /**
     * The compression/optimization ratio.
     *
     * @var float
     */
    public float $ratio;

    /**
     * Create a new event instance.
     *
     * @param string $key
     * @param string $strategy
     * @param int $originalSize
     * @param int $optimizedSize
     */
    public function __construct(string $key, string $strategy, int $originalSize, int $optimizedSize)
    {
        $this->key = $key;
        $this->strategy = $strategy;
        $this->originalSize = $originalSize;
        $this->optimizedSize = $optimizedSize;
        $this->ratio = $optimizedSize > 0 ? $optimizedSize / $originalSize : 0;
    }
}

