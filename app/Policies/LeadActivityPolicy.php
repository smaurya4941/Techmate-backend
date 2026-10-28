<?php

namespace App\Policies;

use App\Models\LeadActivity;
use App\Models\User;
use App\Support\Permissions;

/** Activities are append-only: visible with their lead, creatable by staff who may update it, never edited or deleted. */
class LeadActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return (new LeadPolicy)->viewAny($user);
    }

    public function view(User $user, LeadActivity $activity): bool
    {
        return LeadPolicy::canSee($user, $activity->lead);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::LEADS_UPDATE);
    }

    public function update(User $user, LeadActivity $activity): bool
    {
        return false;
    }

    public function delete(User $user, LeadActivity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
