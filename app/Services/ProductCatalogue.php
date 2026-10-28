<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single implementation of the public product listing query, shared by
 * /products and /categories/{slug}/products so both apply identical rules.
 */
class ProductCatalogue
{
    /** Columns needed by the listing resource; excludes the long description. */
    private const LIST_COLUMNS = [
        'id', 'category_id', 'brand_id', 'name', 'slug', 'sku', 'short_description',
        'price', 'currency_code', 'primary_image_path', 'is_featured', 'sort_order', 'published_at',
    ];

    /**
     * @param  array{search: ?string, category: ?string, brand: ?string, featured: ?bool, sort_by: string, sort_direction: string, per_page: int}  $filters
     * @param  list<int>|null  $categoryIds  When given, restricts to these categories (overrides the slug filter).
     */
    public function paginate(array $filters, ?array $categoryIds = null): LengthAwarePaginator
    {
        $query = Product::query()
            ->select(self::LIST_COLUMNS)
            ->publiclyVisible()
            ->with([
                'category:id,name,slug',
                'brand:id,name,slug,logo_path,is_active,deleted_at',
            ]);

        if ($categoryIds !== null) {
            $query->whereIn('category_id', $categoryIds);
        } elseif ($filters['category'] !== null) {
            $query->whereIn('category_id', $this->categoryIdsForSlug($filters['category']));
        }

        if ($filters['brand'] !== null) {
            $query->whereHas('brand', fn (Builder $q) => $q
                ->withoutTrashed()->where('is_active', true)->where('slug', $filters['brand']));
        }

        if ($filters['featured'] !== null) {
            $query->where('is_featured', $filters['featured']);
        }

        if ($filters['search'] !== null) {
            $this->applySearch($query, $filters['search']);
        }

        // Allowlisted by ProductIndexRequest; the id tie-breaker keeps pages stable.
        $query->orderBy($filters['sort_by'], $filters['sort_direction'])->orderBy('id');

        return $query->paginate($filters['per_page'])->withQueryString();
    }

    /**
     * IDs of an active category plus its active direct children. Subcategory
     * products are included in the parent's listing (the tree is two levels).
     *
     * @return list<int>
     */
    public function categoryIdsFor(Category $category): array
    {
        return [
            $category->id,
            ...Category::query()->active()->where('parent_id', $category->id)->pluck('id')->all(),
        ];
    }

    /** @return list<int> Empty when the slug is unknown/inactive, which yields an empty listing. */
    private function categoryIdsForSlug(string $slug): array
    {
        $category = Category::query()->active()->where('slug', $slug)->first(['id']);

        return $category ? $this->categoryIdsFor($category) : [];
    }

    /**
     * Case-insensitive literal substring match over name, SKU, short description
     * and the brand/category names. This is LIKE matching, not relevance ranking.
     */
    private function applySearch(Builder $query, string $term): void
    {
        // "!" is the escape character so the clause behaves identically on MySQL and SQLite.
        $like = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term)).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(products.short_description) LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('brand', fn (Builder $b) => $b->withoutTrashed()->where('is_active', true)
                    ->whereRaw("LOWER(brands.name) LIKE ? ESCAPE '!'", [$like]))
                ->orWhereHas('category', fn (Builder $c) => $c->whereRaw("LOWER(categories.name) LIKE ? ESCAPE '!'", [$like]));
        });
    }
}
