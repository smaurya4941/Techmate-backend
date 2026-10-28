<?php

namespace App\Observers;

use App\Services\FrontendRevalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/** Registered for every model whose rows feed the public API. */
class PublicContentObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        FrontendRevalidator::notify(class_basename($model).' saved');
    }

    public function deleted(Model $model): void
    {
        FrontendRevalidator::notify(class_basename($model).' deleted');
    }

    public function restored(Model $model): void
    {
        FrontendRevalidator::notify(class_basename($model).' restored');
    }
}
