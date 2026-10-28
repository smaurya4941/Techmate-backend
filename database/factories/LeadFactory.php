<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    /** Uses reserved example.com addresses; never real customer data. */
    public function definition(): array
    {
        return [
            'lead_type' => 'general',
            'customer_name' => 'Sample Contact',
            'company_name' => 'Sample Company',
            'email' => fake()->unique()->userName().'@example.com',
            'phone' => '+10000000000',
            'status' => 'new',
            'priority' => 'normal',
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
