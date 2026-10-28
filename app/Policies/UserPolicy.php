<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Staff accounts are deactivated, never deleted: leads, activities, follow-ups
 * and quotations reference them for history.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::USERS_MANAGE);
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
