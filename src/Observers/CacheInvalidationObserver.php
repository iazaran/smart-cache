<?php

namespace SmartCache\Observers;

use Illuminate\Database\Eloquent\Model;
use SmartCache\Traits\CacheInvalidation;

class CacheInvalidationObserver
{
    /**
     * Handle the model "created" event.
     *
     * @param Model $model
     * @return void
     */
    public function created(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the model "updated" event.
     *
     * @param Model $model
     * @return void
     */
    public function updated(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the model "deleted" event.
     *
     * @param Model $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the model "restored" event.
     *
     * @param Model $model
     * @return void
     */
    public function restored(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Perform cache invalidation if the model uses the CacheInvalidation trait.
     *
     * @param Model $model
     * @return void
     */
    protected function invalidateCache(Model $model): void
    {
        if (!$this->usesCacheInvalidationTrait($model)) {
            return;
        }

        if ($this->shouldDeferUntilAfterCommit($model)) {
            $model->getConnection()->afterCommit(function () use ($model): void {
                $model->performCacheInvalidation();
            });

            return;
        }

        $model->performCacheInvalidation();
    }

    /**
     * Determine if invalidation should wait for the active DB transaction.
     *
     * @param Model $model
     * @return bool
     */
    protected function shouldDeferUntilAfterCommit(Model $model): bool
    {
        if (!$this->afterCommitInvalidationEnabled()) {
            return false;
        }

        try {
            $connection = $model->getConnection();

            if (!\method_exists($connection, 'transactionLevel')
                || !\method_exists($connection, 'afterCommit')
            ) {
                return false;
            }

            return $connection->transactionLevel() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check the package config while keeping non-Laravel static analysis happy.
     *
     * @return bool
     */
    protected function afterCommitInvalidationEnabled(): bool
    {
        if (!\function_exists('config')) {
            return true;
        }

        return (bool) \config('smart-cache.model_invalidation.after_commit', true);
    }

    /**
     * Check if the model uses the CacheInvalidation trait.
     *
     * @param Model $model
     * @return bool
     */
    protected function usesCacheInvalidationTrait(Model $model): bool
    {
        return in_array(CacheInvalidation::class, class_uses_recursive($model));
    }
}
