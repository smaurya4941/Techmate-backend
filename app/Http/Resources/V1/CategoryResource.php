<?php

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public category. The tree is bounded to two levels: a root exposes its
 * (already filtered) children, and children never nest further.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => MediaUrl::for($this->image_path),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent
                ? ['name' => $this->parent->name, 'slug' => $this->parent->slug]
                : null),
            'product_count' => $this->whenCounted('products'),
            'children' => $this->whenLoaded('children', fn () => CategoryResource::collection($this->children)),
        ];
    }
}
