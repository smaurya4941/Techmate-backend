<?php

namespace Database\Factories;

use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowUp> */
class FollowUpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'title' => 'Sample follow-up',
            'due_at' => now()->addDay(),
            'status' => 'pending',
        ];
    }
}
