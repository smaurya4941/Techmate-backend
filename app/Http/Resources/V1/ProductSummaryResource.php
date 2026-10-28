<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Listing representation of a product. Explicit allowlist: columns such as
 * status, sort_order, created_by and updated_by are never serialized.
 *
 * @mixin Product
 */
class ProductSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            // Quote-first catalogue: null means "price on request". Indicative only.
            'price' => $this->price,
            'currency_code' => $this->currency_code,
            'is_featured' => $this->is_featured,
            'image_url' => MediaUrl::for($this->primary_image_path),
            'published_at' => $this->published_at?->toIso8601String(),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            // A brand that is inactive or soft-deleted is hidden, not leaked.
            'brand' => $this->whenLoaded('brand', function () {
                $brand = $this->brand;

                return $brand && $brand->is_active && ! $brand->trashed()
                    ? ['id' => $brand->id, 'name' => $brand->name, 'slug' => $brand->slug, 'logo_url' => MediaUrl::for($brand->logo_path)]
                    : null;
            }),
        ];
    }
}
