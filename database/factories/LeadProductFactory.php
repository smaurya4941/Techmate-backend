<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeadProduct> */
class LeadProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'product_id' => null,
            'product_name_snapshot' => 'Sample Product',
            'product_code_snapshot' => 'SAMPLE-0001',
            'quantity' => 1,
        ];
    }
}
