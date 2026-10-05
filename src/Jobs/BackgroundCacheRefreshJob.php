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
     * @var callable|string
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
     * Namespace that was active when the refresh was queued.
     *
     * Defaults to null so jobs serialized by earlier releases still unserialize.
     *
     * @var string|null
     */
    protected ?string $namespace = null;

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
     * @param string|null $namespace Namespace to write the key under
     */
    public function __construct(string $key, callable|string $callback, ?int $ttl = null, array $tags = [], ?string $namespace = null)
    {
        // Closures cannot be safely serialized by Laravel's queue drivers,
        // so reject them early with a clear, actionable error rather than
        // letting the worker fail later with a cryptic serialization warning.
        if ($callback instanceof \Closure) {
            throw new \InvalidArgumentException(
                'BackgroundCacheRefreshJob does not accept Closures because they cannot be serialized for the queue. '
                . 'Pass a serializable callback instead: a "Class@method" string, an invokable class name, '
                . 'or a [Class::class, "method"] array. For inline closures, use SmartCache::swr() (synchronous in-process refresh).'
            );
        }

        $this->key = $key;
        $this->callback = $callback;
        $this->ttl = $ttl;
        $this->tags = $tags;
        $this->namespace = $namespace;
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
            if ($this->namespace === null) {
                $this->storeValue($value);
            } else {
                $this->storeValueInNamespace($value);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job if it's a transient issue
            \Illuminate\Support\Facades\Log::warning("Background cache refresh failed for key '{$this->key}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Write the refreshed value.
     *
     * @param mixed $value
     * @return void
     */
    protected function storeValue(mixed $value): void
    {
        if (!empty($this->tags)) {
            SmartCache::tags($this->tags)->put($this->key, $value, $this->ttl);
        } else {
            SmartCache::put($this->key, $value, $this->ttl);
        }
    }

    /**
     * Write the refreshed value under the namespace it was queued from.
     *
     * A queue worker has no active namespace, and a sync-queue job runs inside a
     * request that may have a different one, so the namespace is set for the
     * write and the previous one restored afterwards.
     *
     * @param mixed $value
     * @return void
     */
    protected function storeValueInNamespace(mixed $value): void
    {
        $cache = SmartCache::getFacadeRoot();
        $previous = $cache->getNamespace();
        $cache->namespace($this->namespace);

        try {
            $this->storeValue($value);
        } finally {
            if ($previous === null) {
                $cache->withoutNamespace();
            } else {
                $cache->namespace($previous);
            }
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
        if (\is_string($callback) && \class_exists($callback)) {
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
