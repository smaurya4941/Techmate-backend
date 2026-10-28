<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CategoryPolicy extends CataloguePolicy
{
    /** A category that still has products or sub-categories cannot be deleted; deactivate it instead. */
    public function delete(User $user, Model $record): bool
    {
        /** @var Category $record */
        return parent::delete($user, $record)
            && ! $record->products()->withTrashed()->exists()
            && ! $record->children()->withTrashed()->exists();
    }
}
