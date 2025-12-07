<?php

namespace SmartCache\Contracts;

/**
 * Cache Warmer Interface
 * 
 * Implement this interface to create cache warmers that can be
 * executed via the smart-cache:warm command.
 */
interface CacheWarmer
{
    /**
     * Warm the cache.
     *
     * @return array{keys: int, errors?: int, message?: string}
     */
    public function warm(): array;

    /**
     * Get the warmer name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the warmer description.
     *
     * @return string
     */
    public function getDescription(): string;
}

