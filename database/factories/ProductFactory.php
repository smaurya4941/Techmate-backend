<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** Sample rows only: no real products, prices or specifications are invented. */
    public function definition(): array
    {
        $n = fake()->unique()->numerify('######');

        return [
            'category_id' => Category::factory(),
            'brand_id' => null,
            'name' => "Sample Product {$n}",
            'slug' => "sample-product-{$n}",
            'sku' => "SAMPLE-{$n}",
            'status' => Product::STATUS_DRAFT,
            'is_featured' => false,
            'sort_order' => 0,
            'price' => null,
            'currency_code' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Product::STATUS_PUBLISHED, 'published_at' => now()->subMinute()]);
    }

    public function archived(): static
    {
        return $this->state(['status' => Product::STATUS_ARCHIVED]);
    }

    public function priced(string $price, string $currency): static
    {
        return $this->state(['price' => $price, 'currency_code' => $currency]);
    }
}
