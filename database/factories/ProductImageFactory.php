<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductImage> */
class ProductImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'image_path' => 'products/sample/'.fake()->unique()->numerify('image-####').'.jpg',
            'alt_text' => 'Sample image',
            'sort_order' => 0,
        ];
    }
}
