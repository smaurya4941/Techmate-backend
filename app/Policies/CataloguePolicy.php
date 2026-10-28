<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for catalogue records (products, categories, brands and their
 * child rows). Catalogue managers create, edit, unpublish and archive;
 * only Super Admin may soft-delete or restore. Nobody may force-delete, so
 * historical lead and quotation references can never be orphaned.
 */
abstract class CataloguePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_MANAGE);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function restore(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
