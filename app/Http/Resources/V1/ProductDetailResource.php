<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use App\Models\ProductSpecification;
use App\Support\MediaUrl;
use Illuminate\Http\Request;

/** @mixin Product */
class ProductDetailResource extends ProductSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'url' => MediaUrl::for($image->image_path),
                'alt_text' => $image->alt_text,
                'caption' => $image->caption,
            ])->values()),
            'specifications' => $this->whenLoaded('specifications', fn () => $this->groupedSpecifications()),
            // Only documents flagged is_public are ever loaded (see the controller).
            'documents' => $this->whenLoaded('publicDocuments', fn () => $this->publicDocuments->map(fn ($doc) => [
                'title' => $doc->title,
                'type' => $doc->document_type,
                'url' => MediaUrl::for($doc->file_path),
                'mime_type' => $doc->mime_type,
                'file_size_bytes' => $doc->file_size_bytes,
                'language_code' => $doc->language_code,
            ])->values()),
        ];
    }

    /**
     * Specifications grouped by their group label, preserving sort order.
     * Ungrouped specs fall under "General".
     *
     * @return list<array{group: string, items: list<array<string, mixed>>}>
     */
    private function groupedSpecifications(): array
    {
        return $this->specifications
            ->groupBy(fn (ProductSpecification $spec) => $spec->specification_group ?: 'General')
            ->map(fn ($specs, $group) => [
                'group' => $group,
                'items' => $specs->map(fn (ProductSpecification $spec) => [
                    'key' => $spec->specification_key,
                    'label' => $spec->specification_label,
                    'type' => $spec->value_type,
                    'value' => $spec->value_type === ProductSpecification::TYPE_NUMBER
                        ? (float) $spec->value_number
                        : $spec->value(),
                    'unit' => $spec->unit,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
