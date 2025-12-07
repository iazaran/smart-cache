<?php

namespace SmartCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SmartCache\Facades\SmartCache;

/**
 * Background Cache Refresh Job
 * 
 * Refreshes cache values in the background using Laravel's queue system.
 * This enables true async SWR (Stale-While-Revalidate) patterns.
 */
class BackgroundCacheRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var string
     */
    protected string $key;

    /**
     * @var \Closure|string
     */
    protected $callback;

    /**
     * @var int|null
     */
    protected ?int $ttl;

    /**
     * @var array
     */
    protected array $tags;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     *
     * @param string $key
     * @param callable|string $callback Serializable callback (class@method or invokable class)
     * @param int|null $ttl
     * @param array $tags
     */
    public function __construct(string $key, callable|string $callback, ?int $ttl = null, array $tags = [])
    {
        $this->key = $key;
        $this->callback = $callback;
        $this->ttl = $ttl;
        $this->tags = $tags;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            // Resolve the callback
            $value = $this->resolveCallback();

            // Store the refreshed value
            if (!empty($this->tags)) {
                SmartCache::tags($this->tags)->put($this->key, $value, $this->ttl);
            } else {
                SmartCache::put($this->key, $value, $this->ttl);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job if it's a transient issue
            \Illuminate\Support\Facades\Log::warning("Background cache refresh failed for key '{$this->key}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Resolve and execute the callback.
     *
     * @return mixed
     */
    protected function resolveCallback(): mixed
    {
        $callback = $this->callback;

        // If it's a string like "Class@method", resolve it
        if (\is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $instance = app($class);
            return $instance->$method();
        }

        // If it's an invokable class name
        if (\is_string($callback) && class_exists($callback)) {
            $instance = app($callback);
            return $instance();
        }

        // If it's a callable array [Class, method]
        if (\is_array($callback) && \count($callback) === 2) {
            [$class, $method] = $callback;
            if (\is_string($class)) {
                $instance = app($class);
                return $instance->$method();
            }
            return $callback();
        }

        // If it's already a closure (shouldn't happen in queue, but handle it)
        if (\is_callable($callback)) {
            return $callback();
        }

        throw new \InvalidArgumentException('Invalid callback provided for background cache refresh');
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return ['smart-cache', 'cache-refresh', "key:{$this->key}"];
    }
}

