<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ApiCollection;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;

class CategoryController extends Controller
{
    /** Active root categories, each with its active direct children (two levels, bounded). */
    public function index(): ApiCollection
    {
        $publishedProducts = fn (Builder $q) => $q->where('status', 'published')
            ->where(fn (Builder $p) => $p->whereNull('published_at')->orWhere('published_at', '<=', now()));

        $categories = Category::query()
            ->active()
            ->whereNull('parent_id')
            ->withCount(['products' => $publishedProducts])
            ->with(['children' => fn ($q) => $q->active()
                ->withCount(['products' => $publishedProducts])
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->get();

        return new ApiCollection($categories, CategoryResource::class, 'Categories retrieved successfully.');
    }
}
