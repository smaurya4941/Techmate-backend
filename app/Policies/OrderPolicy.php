<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operations and managers see every order; an executive only orders won from their own
 * leads. Orders are created only by LeadOutcomeService and never edited or deleted here.
 */
class OrderPolicy
{
    public static function scope(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(Permissions::ORDERS_VIEW_ALL)) {
            return $query;
        }

        if ($user->hasPermission(Permissions::ORDERS_VIEW_OWN)) {
            return $query->whereHas('lead', fn (Builder $lead) => $lead->where('assigned_to', $user->getKey()));
        }

        return $query->whereRaw('1 = 0');
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ORDERS_VIEW_ALL) || $user->hasPermission(Permissions::ORDERS_VIEW_OWN);
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->hasPermission(Permissions::ORDERS_VIEW_ALL)) {
            return true;
        }

        return $user->hasPermission(Permissions::ORDERS_VIEW_OWN) && $order->lead?->assigned_to === $user->getKey();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    /** Confirm, progress, cancel and record installation. */
    public function manage(User $user, Order $order): bool
    {
        return $user->hasPermission(Permissions::ORDERS_MANAGE) && $this->view($user, $order);
    }

    public function recordPayment(User $user, Order $order): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_RECORD) && $this->view($user, $order);
    }

    /** Verification and voiding (corrections) are the same, higher, permission. */
    public function verifyPayment(User $user, Order $order): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_VERIFY) && $this->view($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
