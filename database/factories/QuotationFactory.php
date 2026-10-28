<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    /** Empty draft (zero totals reconcile); add lines with QuotationItemFactory. */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'status' => 'draft',
            'currency_code' => 'USD',
            'issue_date' => now()->toDateString(),
            'customer_name_snapshot' => 'Sample Contact',
            'customer_email_snapshot' => 'sample@example.com',
        ];
    }
}
