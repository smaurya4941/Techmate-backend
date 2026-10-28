<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numerify('####');

        return [
            'title' => "Sample Page {$n}",
            'slug' => "sample-page-{$n}",
            'status' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => 'published', 'published_at' => now()->subMinute()]);
    }
}
