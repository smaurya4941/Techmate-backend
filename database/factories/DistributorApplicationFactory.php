<?php

namespace Database\Factories;

use App\Models\DistributorApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DistributorApplication> */
class DistributorApplicationFactory extends Factory
{
    /** Uses reserved example.com addresses; never real applicant data. */
    public function definition(): array
    {
        return [
            'contact_name' => 'Sample Applicant',
            'company_name' => 'Sample Dealer Pvt Ltd',
            'email' => fake()->unique()->userName().'@example.com',
            'phone' => '+10000000000',
            'business_type' => 'dealer',
            'regions_served' => ['Sample Region'],
        ];
    }
}
