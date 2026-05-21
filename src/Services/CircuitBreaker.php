<?php

namespace SmartCache\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * Circuit Breaker for Cache Operations
 *
 * Implements the circuit breaker pattern to prevent cascading failures
 * when the cache backend is unavailable or slow.
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    protected string $state = self::STATE_CLOSED;
    protected int $failureCount = 0;
    protected int $failureThreshold;
    protected int $recoveryTimeout;
    protected ?int $openedAt = null;
    protected int $successCount = 0;
    protected int $successThreshold;
    protected array $failureHistory = [];
    protected int $maxHistorySize = 100;

    /**
     * Optional cache repository used to share breaker state across workers.
     * When set, every state mutation is mirrored to the cache and every
     * state read first hydrates from the cache.  Stays null for per-instance
     * (historical) behaviour.
     *
     * @var Repository|null
     */
    protected ?Repository $sharedStateCache = null;

    /**
     * Cache key used when sharing state.
     *
     * @var string|null
     */
    protected ?string $sharedStateKey = null;

    /**
     * TTL (seconds) for the shared state entry.  Best-effort eventual
     * consistency — breakers self-heal when the key expires.
     *
     * @var int
     */
    protected int $sharedStateTtl = 300;

    public function __construct(
        int $failureThreshold = 5,
        int $recoveryTimeout = 30,
        int $successThreshold = 3
    ) {
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
        $this->successThreshold = $successThreshold;
    }

    /**
     * Enable cross-process state sharing through the given cache repository.
     *
     * After this call every state mutation persists to `$key` and every state
     * read first hydrates from it, so multiple workers/processes see a single
     * circuit breaker.  Opt-in; default behaviour stays per-instance.
     *
     * @param Repository $cache
     * @param string $key
     * @param int $ttl Best-effort TTL (seconds) for the persisted state
     * @return $this
     */
    public function enableSharedState(Repository $cache, string $key, int $ttl = 300): self
    {
        $this->sharedStateCache = $cache;
        $this->sharedStateKey = $key;
        $this->sharedStateTtl = max(1, $ttl);
        $this->hydrateSharedState();
        return $this;
    }

    /**
     * Refresh local state from the shared cache entry, if shared state is
     * enabled.  Silently no-ops on read errors so a broken cache never breaks
     * the application path.
     */
    protected function hydrateSharedState(): void
    {
        if ($this->sharedStateCache === null || $this->sharedStateKey === null) {
            return;
        }

        try {
            $payload = $this->sharedStateCache->get($this->sharedStateKey);
        } catch (\Throwable $e) {
            return;
        }

        if (!\is_array($payload)) {
            return;
        }

        $this->state = $payload['state'] ?? $this->state;
        $this->failureCount = (int) ($payload['failure_count'] ?? $this->failureCount);
        $this->successCount = (int) ($payload['success_count'] ?? $this->successCount);
        $this->openedAt = isset($payload['opened_at']) ? (int) $payload['opened_at'] : $this->openedAt;
    }

    /**
     * Mirror the current local state to the shared cache entry.  Silently
     * absorbs write errors so a broken cache never breaks the application path.
     */
    protected function persistSharedState(): void
    {
        if ($this->sharedStateCache === null || $this->sharedStateKey === null) {
            return;
        }

        try {
            $this->sharedStateCache->put($this->sharedStateKey, [
                'state' => $this->state,
                'failure_count' => $this->failureCount,
                'success_count' => $this->successCount,
                'opened_at' => $this->openedAt,
            ], $this->sharedStateTtl);
        } catch (\Throwable $e) {
            // Best-effort persistence.
        }
    }

    public function isAvailable(): bool
    {
        $this->hydrateSharedState();

        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state === self::STATE_OPEN) {
            $elapsed = $this->openedAt !== null ? \time() - $this->openedAt : 0;
            if ($elapsed >= $this->recoveryTimeout) {
                $this->state = self::STATE_HALF_OPEN;
                $this->successCount = 0;
                $this->persistSharedState();
                return true;
            }
            return false;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->successCount++;
            if ($this->successCount >= $this->successThreshold) {
                $this->close();
            }
        } else {
            $this->failureCount = 0;
        }
        $this->persistSharedState();
    }

    public function recordFailure(?\Throwable $exception = null): void
    {
        $this->failureCount++;

        $this->failureHistory[] = [
            'timestamp' => \time(),
            'exception' => $exception ? \get_class($exception) : null,
            'message' => $exception?->getMessage(),
        ];

        if (\count($this->failureHistory) > $this->maxHistorySize) {
            $this->failureHistory = \array_slice($this->failureHistory, -$this->maxHistorySize);
        }

        if ($this->state === self::STATE_HALF_OPEN || $this->failureCount >= $this->failureThreshold) {
            $this->open();
        }
        $this->persistSharedState();
    }

    protected function open(): void
    {
        $this->state = self::STATE_OPEN;
        $this->openedAt = \time();
    }

    protected function close(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->openedAt = null;
    }

    public function reset(): void
    {
        $this->close();
        $this->failureHistory = [];
        $this->persistSharedState();
    }

    public function getState(): string
    {
        $this->hydrateSharedState();

        if ($this->state === self::STATE_OPEN && $this->openedAt !== null) {
            $elapsed = \time() - $this->openedAt;
            if ($elapsed >= $this->recoveryTimeout) {
                $this->state = self::STATE_HALF_OPEN;
            }
        }
        return $this->state;
    }

    public function getStats(): array
    {
        $timeUntilRecovery = null;
        if ($this->openedAt !== null) {
            $elapsed = \time() - $this->openedAt;
            $timeUntilRecovery = \max(0, $this->recoveryTimeout - $elapsed);
        }

        return [
            'state' => $this->getState(),
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'recovery_timeout' => $this->recoveryTimeout,
            'opened_at' => $this->openedAt,
            'time_until_recovery' => $timeUntilRecovery,
            'recent_failures' => \array_slice($this->failureHistory, -10),
        ];
    }

    public function execute(callable $callback, mixed $fallback = null): mixed
    {
        if (!$this->isAvailable()) {
            return $fallback;
        }

        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($e);
            throw $e;
        }
    }

    public function executeWithFallback(callable $callback, mixed $fallback = null): mixed
    {
        if (!$this->isAvailable()) {
            return $fallback;
        }

        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($e);
            return $fallback;
        }
    }
}

