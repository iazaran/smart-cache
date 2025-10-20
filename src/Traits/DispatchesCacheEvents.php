<?php

namespace SmartCache\Traits;

use Illuminate\Support\Facades\Event;
use SmartCache\Events\CacheHit;
use SmartCache\Events\CacheMissed;
use SmartCache\Events\KeyWritten;
use SmartCache\Events\KeyForgotten;
use SmartCache\Events\OptimizationApplied;

trait DispatchesCacheEvents
{
    /**
     * Dispatch a cache hit event.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function dispatchCacheHit(string $key, mixed $value): void
    {
        if ($this->shouldDispatchEvent('cache_hit')) {
            Event::dispatch(new CacheHit($key, $value, $this->activeTags));
        }
    }

    /**
     * Dispatch a cache missed event.
     *
     * @param string $key
     * @return void
     */
    protected function dispatchCacheMissed(string $key): void
    {
        if ($this->shouldDispatchEvent('cache_missed')) {
            Event::dispatch(new CacheMissed($key, $this->activeTags));
        }
    }

    /**
     * Dispatch a key written event.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $seconds
     * @return void
     */
    protected function dispatchKeyWritten(string $key, mixed $value, ?int $seconds = null): void
    {
        if ($this->shouldDispatchEvent('key_written')) {
            Event::dispatch(new KeyWritten($key, $value, $seconds, $this->activeTags));
        }
    }

    /**
     * Dispatch a key forgotten event.
     *
     * @param string $key
     * @return void
     */
    protected function dispatchKeyForgotten(string $key): void
    {
        if ($this->shouldDispatchEvent('key_forgotten')) {
            Event::dispatch(new KeyForgotten($key, $this->activeTags));
        }
    }

    /**
     * Dispatch an optimization applied event.
     *
     * @param string $key
     * @param string $strategy
     * @param int $originalSize
     * @param int $optimizedSize
     * @return void
     */
    protected function dispatchOptimizationApplied(string $key, string $strategy, int $originalSize, int $optimizedSize): void
    {
        if ($this->shouldDispatchEvent('optimization_applied')) {
            Event::dispatch(new OptimizationApplied($key, $strategy, $originalSize, $optimizedSize));
        }
    }

    /**
     * Determine if an event should be dispatched.
     *
     * @param string $eventType
     * @return bool
     */
    protected function shouldDispatchEvent(string $eventType): bool
    {
        if (!$this->config->get('smart-cache.events.enabled', false)) {
            return false;
        }

        return $this->config->get("smart-cache.events.dispatch.{$eventType}", true);
    }
}

