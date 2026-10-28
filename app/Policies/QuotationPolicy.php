<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 5 provides quotation oversight only. Quotation content is never edited
 * from the panel (issued snapshots stay immutable); only the status may move
 * along Quotation::TRANSITIONS, and only for roles holding quotations.update_status.
 */
class QuotationPolicy
{
    public static function scope(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(Permissions::QUOTATIONS_VIEW_ALL)) {
            return $query;
        }

        if ($user->hasPermission(Permissions::QUOTATIONS_VIEW_OWN)) {
            return $query->whereHas('lead', fn (Builder $lead) => $lead->where('assigned_to', $user->getKey()));
        }

        return $query->whereRaw('1 = 0');
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::QUOTATIONS_VIEW_ALL) || $user->hasPermission(Permissions::QUOTATIONS_VIEW_OWN);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if ($user->hasPermission(Permissions::QUOTATIONS_VIEW_ALL)) {
            return true;
        }

        return $user->hasPermission(Permissions::QUOTATIONS_VIEW_OWN)
            && $quotation->lead?->assigned_to === $user->getKey();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return false;
    }

    public function updateStatus(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission(Permissions::QUOTATIONS_UPDATE_STATUS)
            && $this->view($user, $quotation)
            && $quotation->nextStatuses() !== [];
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
