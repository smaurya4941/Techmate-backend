<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotation access follows lead visibility: managers and admins see everything, an
 * executive only quotations on leads assigned to them. Preparing and sending needs
 * quotations.manage; approving needs quotations.approve. Issued quotations are never
 * edited in place (QuotationService enforces draft-only edits), and nothing is deleted.
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

    /** Preparing a quotation for a lead the user can see (Filament's resource-level create check passes no lead). */
    public function create(User $user, ?Lead $lead = null): bool
    {
        if (! $user->hasPermission(Permissions::QUOTATIONS_MANAGE)) {
            return false;
        }

        return $lead === null || LeadPolicy::canSee($user, $lead);
    }

    /** Edit a draft, submit, send, revise, record the customer's response, cancel. */
    public function manage(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission(Permissions::QUOTATIONS_MANAGE) && $this->view($user, $quotation);
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->manage($user, $quotation) && $quotation->isEditable();
    }

    public function approve(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission(Permissions::QUOTATIONS_APPROVE) && $this->view($user, $quotation);
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
