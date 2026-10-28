<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Sample Brand '.fake()->unique()->numerify('####');

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
