<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductDocument> */
class ProductDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'title' => 'Sample datasheet',
            'document_type' => 'datasheet',
            'file_path' => 'documents/sample/'.fake()->unique()->numerify('doc-####').'.pdf',
            'mime_type' => 'application/pdf',
            'sort_order' => 0,
            'is_public' => false,
        ];
    }

    public function public(): static
    {
        return $this->state(['is_public' => true]);
    }
}
