<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuoteRequestRequest extends PublicSubmissionRequest
{
    public const SOURCES = ['website', 'request_quote_page', 'product_page', 'home_page'];

    public const BUSINESS_TYPES = ['restaurant', 'bakery', 'cloud-kitchen', 'catering', 'dealer', 'other'];

    /** @var Collection<string, Product>|null Eligible products keyed by slug, resolved during validation. */
    private ?Collection $resolvedProducts = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxItems = (int) config('techmate.quote_request.max_items');
        $maxQuantity = (int) config('techmate.quote_request.max_quantity');
        $slug = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

        return [
            ...$this->guardRules(),
            ...$this->contactRules(phoneRequired: true),
            'message' => ['nullable', 'string', 'max:3000'],
            'business_type' => ['nullable', Rule::in(self::BUSINESS_TYPES)],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'items' => ['required', 'array', 'min:1', 'max:'.$maxItems],
            // An item is either a catalogue product (slug) or a described custom requirement.
            'items.*' => ['array'],
            'items.*.product' => ['nullable', 'string', 'max:220', $slug, 'distinct', 'required_without:items.*.name'],
            'items.*.name' => ['nullable', 'string', 'min:2', 'max:200', 'required_without:items.*.product'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$maxQuantity],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            // Prices are never client-supplied; reject rather than silently ignore.
            'items.*.price' => ['prohibited'],
            'items.*.unit_price' => ['prohibited'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $slugs = collect($this->input('items', []))->pluck('product')->filter()->unique()->values();

            $this->resolvedProducts = $slugs->isEmpty()
                ? collect()
                : Product::query()->publiclyVisible()->whereIn('slug', $slugs)
                    ->get(['id', 'name', 'slug', 'sku'])->keyBy('slug');

            foreach ($this->input('items', []) as $index => $item) {
                if (! empty($item['product']) && ! $this->resolvedProducts->has($item['product'])) {
                    $validator->errors()->add("items.{$index}.product", 'The selected product is not available for quotation.');
                }
            }
        }];
    }

    /**
     * Normalized line items ready for persistence. Product lines take their
     * name/code from the catalogue, never from the client.
     *
     * @return list<array{product_id: ?int, name: string, code: ?string, quantity: int, notes: ?string}>
     */
    public function lineItems(): array
    {
        return collect($this->validated('items'))->map(function (array $item) {
            $product = empty($item['product']) ? null : $this->resolvedProducts?->get($item['product']);

            return [
                'product_id' => $product?->id,
                'name' => $product ? $product->name : trim($item['name']),
                'code' => $product?->sku,
                'quantity' => (int) $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ];
        })->all();
    }
}
