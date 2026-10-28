<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates and normalizes catalogue listing query parameters. */
class ProductIndexRequest extends FormRequest
{
    public const SORTABLE = ['sort_order', 'name', 'published_at'];

    public function authorize(): bool
    {
        return true; // Public, read-only.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('techmate.catalogue.max_per_page')],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'featured' => ['sometimes', 'nullable', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            'sort_by' => ['sometimes', Rule::in(self::SORTABLE)],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Normalized filters with defaults applied.
     *
     * @return array{search: ?string, category: ?string, brand: ?string, featured: ?bool, sort_by: string, sort_direction: string, per_page: int}
     */
    public function filters(): array
    {
        $search = trim((string) $this->validated('search', ''));
        $featured = $this->validated('featured');

        return [
            'search' => $search === '' ? null : $search,
            'category' => $this->validated('category') ?: null,
            'brand' => $this->validated('brand') ?: null,
            'featured' => $featured === null ? null : filter_var($featured, FILTER_VALIDATE_BOOLEAN),
            'sort_by' => $this->validated('sort_by', 'sort_order'),
            'sort_direction' => $this->validated('sort_direction', 'asc'),
            'per_page' => (int) $this->validated('per_page', config('techmate.catalogue.default_per_page')),
        ];
    }
}
