<?php

namespace SmartCache\Contracts;

interface OptimizationStrategy
{
    /**
     * Determine if this strategy should be applied to the given cache value.
     *
     * @param mixed $value The value being cached
     * @param array $context Additional context (like cache driver, key, etc.)
     * @return bool
     */
    public function shouldApply(mixed $value, array $context = []): bool;
    
    /**
     * Apply the optimization strategy to the value.
     *
     * @param mixed $value The value to optimize
     * @param array $context Additional context
     * @return mixed The optimized value
     */
    public function optimize(mixed $value, array $context = []): mixed;
    
    /**
     * Restore the original value from the optimized value.
     *
     * @param mixed $value The optimized value
     * @param array $context Additional context
     * @return mixed The original value
     */
    public function restore(mixed $value, array $context = []): mixed;
    
    /**
     * Get a unique identifier for this strategy.
     *
     * @return string
     */
    public function getIdentifier(): string;
} 