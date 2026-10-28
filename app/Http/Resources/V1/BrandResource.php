<?php

namespace App\Http\Resources\V1;

use App\Models\Brand;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => MediaUrl::for($this->logo_path),
            'website_url' => $this->website_url,
            'product_count' => $this->whenCounted('products'),
        ];
    }
}
