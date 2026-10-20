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

        // Use atomic increment when supported by the store
        $store = $this->cache->getStore();
        if (\method_exists($store, 'increment')) {
            // Initialize the key if it doesn't exist
            $this->cache->add($rateLimitKey, 0, $window);
            $current = (int) $store->increment($rateLimitKey);
            return $current <= $maxAttempts;
        }

        // Fallback for stores without atomic increment
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
     *
     * XFetch refreshes when now - delta * beta * ln(rand) >= expiry, where delta
     * is how long a regeneration takes: the refresh probability is
     * exp(-remaining / (beta * delta)), negligible until the last few multiples
     * of delta before expiry. Earlier releases used the whole TTL as delta,
     * which refreshed about 37% of reads even right after a write.
     *
     * @param int $ttl
     * @param int $createdAt
     * @param float $beta Above 1 refreshes earlier, below 1 later; 0 never early
     * @param float|null $delta Seconds a regeneration takes; when unknown, one
     *                          second, or a tenth of the TTL if that is shorter
     * @return bool
     */
    public function shouldRefreshProbabilistically(int $ttl, int $createdAt, float $beta = 1.0, ?float $delta = null): bool
    {
        $now = \time();
        $expiresAt = $createdAt + $ttl;
        $remaining = $expiresAt - $now;

        if ($remaining <= 0) {
            return true;
        }

        if ($beta <= 0) {
            return false;
        }

        if ($delta === null || $delta <= 0) {
            $delta = \max(0.001, \min(1.0, $ttl / 10));
        }

        $probability = \exp(-$remaining / ($beta * $delta));
        $random = \mt_rand() / \mt_getrandmax();

        return $random < $probability;
    }
}

