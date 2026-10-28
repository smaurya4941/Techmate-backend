<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;
use App\Support\Permissions;

class FollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return (new LeadPolicy)->viewAny($user);
    }

    public function view(User $user, FollowUp $followUp): bool
    {
        return LeadPolicy::canSee($user, $followUp->lead);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::LEADS_UPDATE);
    }

    /** Completing or cancelling: anyone who may update the lead it belongs to. */
    public function update(User $user, FollowUp $followUp): bool
    {
        return $user->hasPermission(Permissions::LEADS_UPDATE) && LeadPolicy::canSee($user, $followUp->lead);
    }

    /** Follow-ups are cancelled, not deleted, to preserve sales history. */
    public function delete(User $user, FollowUp $followUp): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
