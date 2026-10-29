<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable contact record, one per normalised e-mail address. A returning customer
 * keeps the same Customer but each enquiry remains its own Lead: no silent merging.
 */
class Customer extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'company_name', 'email', 'phone'];

    /**
     * Finds or creates the customer for an enquiry. Existing details are only
     * filled in where blank, never overwritten by an anonymous submission.
     */
    public static function resolve(string $name, ?string $company, ?string $email, ?string $phone): ?self
    {
        $email = $email ? mb_strtolower(trim($email)) : null;

        if ($email === null) {
            return null;
        }

        $customer = static::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'company_name' => $company, 'phone' => $phone]
        );

        if (! $customer->wasRecentlyCreated) {
            $customer->fill(array_filter([
                'company_name' => $customer->company_name ? null : $company,
                'phone' => $customer->phone ? null : $phone,
            ]))->save();
        }

        return $customer;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
