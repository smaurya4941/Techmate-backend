<?php

namespace App\Policies;

use App\Models\QuotationItem;
use App\Models\User;

class QuotationItemPolicy
{
    public function viewAny(User $user): bool
    {
        return (new QuotationPolicy)->viewAny($user);
    }

    public function view(User $user, QuotationItem $item): bool
    {
        return (new QuotationPolicy)->view($user, $item->quotation);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, QuotationItem $item): bool
    {
        return false;
    }

    public function delete(User $user, QuotationItem $item): bool
    {
        return false;
    }
}
