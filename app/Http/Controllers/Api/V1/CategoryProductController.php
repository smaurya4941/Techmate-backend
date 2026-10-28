<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\V1\ApiCollection;
use App\Http\Resources\V1\ProductSummaryResource;
use App\Models\Category;
use App\Services\ProductCatalogue;

class CategoryProductController extends Controller
{
    public function __construct(private readonly ProductCatalogue $catalogue) {}

    /**
     * Products of an active category and its active direct subcategories.
     * The `category` query filter is ignored here; the path decides the scope.
     */
    public function index(ProductIndexRequest $request, string $slug): ApiCollection
    {
        $category = Category::query()->active()->where('slug', $slug)->firstOrFail();

        $products = $this->catalogue->paginate($request->filters(), $this->catalogue->categoryIdsFor($category));

        return new ApiCollection($products, ProductSummaryResource::class, 'Products retrieved successfully.');
    }
}
