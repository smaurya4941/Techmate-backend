<?php

namespace App\Models\Concerns;

use App\Support\MediaCleanup;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Removes public-disk files that a record stops referencing: when the path
 * column is replaced or cleared, and when the row is permanently removed.
 * Soft-deleted (archived) rows keep their files so they can be restored.
 */
trait CleansUpFiles
{
    /** @return list<string> Columns holding public-disk paths. */
    abstract protected static function publicFileColumns(): array;

    protected static function bootCleansUpFiles(): void
    {
        static::updated(function ($model) {
            foreach (static::publicFileColumns() as $column) {
                if ($model->wasChanged($column)) {
                    MediaCleanup::deletePublic($model->getOriginal($column));
                }
            }
        });

        static::deleted(function ($model) {
            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

            if ($softDeletes && ! $model->isForceDeleting()) {
                return;
            }

            foreach (static::publicFileColumns() as $column) {
                MediaCleanup::deletePublic($model->getAttribute($column));
            }
        });
    }
}
