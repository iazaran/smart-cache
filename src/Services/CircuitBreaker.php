<?php

namespace SmartCache\Services;

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

    public function __construct(
        int $failureThreshold = 5,
        int $recoveryTimeout = 30,
        int $successThreshold = 3
    ) {
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
        $this->successThreshold = $successThreshold;
    }

    public function isAvailable(): bool
    {
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state === self::STATE_OPEN) {
            $elapsed = $this->openedAt !== null ? \time() - $this->openedAt : 0;
            if ($elapsed >= $this->recoveryTimeout) {
                $this->state = self::STATE_HALF_OPEN;
                $this->successCount = 0;
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
    }

    public function getState(): string
    {
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

