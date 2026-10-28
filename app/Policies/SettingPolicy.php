<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::SETTINGS_MANAGE);
    }

    public function view(User $user, Setting $setting): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Setting $setting): bool
    {
        return false;
    }
}
