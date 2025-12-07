<?php

namespace SmartCache\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * Rate Limiter for Cache Operations
 *
 * Prevents cache stampede by limiting concurrent cache regeneration
 * for the same key using a probabilistic approach.
 */
class RateLimiter
{
    protected Repository $cache;
    protected int $window;
    protected int $maxAttempts;

    public function __construct(Repository $cache, int $window = 60, int $maxAttempts = 10)
    {
        $this->cache = $cache;
        $this->window = $window;
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * Attempt to acquire a rate limit slot.
     */
    public function attempt(string $key, ?int $maxAttempts = null, ?int $window = null): bool
    {
        $maxAttempts = $maxAttempts ?? $this->maxAttempts;
        $window = $window ?? $this->window;

        $rateLimitKey = "_sc_rate:{$key}";
        $current = (int) $this->cache->get($rateLimitKey, 0);

        if ($current >= $maxAttempts) {
            return false;
        }

        $this->cache->put($rateLimitKey, $current + 1, $window);
        return true;
    }

    /**
     * Get remaining attempts for a key.
     */
    public function remaining(string $key, ?int $maxAttempts = null): int
    {
        $maxAttempts = $maxAttempts ?? $this->maxAttempts;
        $rateLimitKey = "_sc_rate:{$key}";
        $current = (int) $this->cache->get($rateLimitKey, 0);

        return \max(0, $maxAttempts - $current);
    }

    /**
     * Reset the rate limit for a key.
     */
    public function reset(string $key): void
    {
        $this->cache->forget("_sc_rate:{$key}");
    }

    /**
     * Execute a callback with rate limiting.
     */
    public function throttle(
        string $key,
        callable $callback,
        ?callable $onLimited = null,
        ?int $maxAttempts = null,
        ?int $window = null
    ): mixed {
        if (!$this->attempt($key, $maxAttempts, $window)) {
            return $onLimited ? $onLimited() : null;
        }

        return $callback();
    }

    /**
     * Probabilistic early expiration to prevent stampede (XFetch algorithm).
     */
    public function shouldRefreshProbabilistically(int $ttl, int $createdAt, float $beta = 1.0): bool
    {
        $now = \time();
        $expiresAt = $createdAt + $ttl;
        $remaining = $expiresAt - $now;

        if ($remaining <= 0) {
            return true;
        }

        $probability = \exp(-$remaining / ($beta * $ttl));
        $random = \mt_rand() / \mt_getrandmax();

        return $random < $probability;
    }
}

