<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;
use App\Support\Permissions;

class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CMS_MANAGE);
    }

    public function view(User $user, Page $page): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Page $page): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Page $page): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function restore(User $user, Page $page): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CATALOGUE_DELETE);
    }

    public function forceDelete(User $user, Page $page): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
