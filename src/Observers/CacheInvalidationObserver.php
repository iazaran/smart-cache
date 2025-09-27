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
        if ($this->usesCacheInvalidationTrait($model)) {
            $model->performCacheInvalidation();
        }
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
