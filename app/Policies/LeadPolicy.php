<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lead access is scoped on the server: Super Admin and Sales Manager see every
 * lead; a Sales Executive sees only leads assigned to them. Leads are created
 * by the public API and are never deleted.
 */
class LeadPolicy
{
    /** Restricts a lead query to what the user may see. Use on every lead-derived query. */
    public static function scope(Builder $query, User $user, string $column = 'assigned_to'): Builder
    {
        if ($user->hasPermission(Permissions::LEADS_VIEW_ALL)) {
            return $query;
        }

        if ($user->hasPermission(Permissions::LEADS_VIEW_OWN)) {
            return $query->where($column, $user->getKey());
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canSee(User $user, Lead $lead): bool
    {
        if ($user->hasPermission(Permissions::LEADS_VIEW_ALL)) {
            return true;
        }

        return $user->hasPermission(Permissions::LEADS_VIEW_OWN)
            && $lead->assigned_to !== null
            && $lead->assigned_to === $user->getKey();
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::LEADS_VIEW_ALL) || $user->hasPermission(Permissions::LEADS_VIEW_OWN);
    }

    public function view(User $user, Lead $lead): bool
    {
        return self::canSee($user, $lead);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permissions::LEADS_UPDATE) && self::canSee($user, $lead);
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permissions::LEADS_ASSIGN) && self::canSee($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
