<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSpecification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductSpecification> */
class ProductSpecificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'specification_group' => 'General',
            'specification_key' => 'sample_'.fake()->unique()->numerify('####'),
            'specification_label' => 'Sample attribute',
            'value_type' => 'string',
            'value_string' => 'sample value',
            'sort_order' => 0,
        ];
    }

    public function number(string $value, ?string $unit = null): static
    {
        return $this->state([
            'value_type' => 'number', 'value_string' => null, 'value_number' => $value, 'unit' => $unit,
        ]);
    }

    public function boolean(bool $value): static
    {
        return $this->state([
            'value_type' => 'boolean', 'value_string' => null, 'value_boolean' => $value,
        ]);
    }
}
