<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeadActivity> */
class LeadActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => null,
            'activity_type' => 'note',
            'description' => 'Sample note',
            'occurred_at' => now(),
        ];
    }
}
