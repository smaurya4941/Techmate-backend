<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

/** Child rows are removable by anyone who may edit the product (their files are cleaned up with them). */
class ProductImagePolicy extends CataloguePolicy
{
    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_MANAGE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_MANAGE);
    }
}
