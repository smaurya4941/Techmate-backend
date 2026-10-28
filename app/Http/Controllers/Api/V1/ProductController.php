<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\V1\ApiCollection;
use App\Http\Resources\V1\ProductDetailResource;
use App\Http\Resources\V1\ProductSummaryResource;
use App\Models\Product;
use App\Services\ProductCatalogue;

class ProductController extends Controller
{
    public function __construct(private readonly ProductCatalogue $catalogue) {}

    public function index(ProductIndexRequest $request): ApiCollection
    {
        $products = $this->catalogue->paginate($request->filters());

        return new ApiCollection($products, ProductSummaryResource::class, 'Products retrieved successfully.');
    }

    public function show(string $slug): ProductDetailResource
    {
        // Same visibility rule as the listing; anything else is a plain 404.
        $product = Product::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->with([
                'category:id,name,slug',
                'brand:id,name,slug,logo_path,is_active,deleted_at',
                'images',
                'specifications',
                'publicDocuments',
            ])
            ->firstOrFail();

        return ProductDetailResource::make($product)
            ->additional(['message' => 'Product retrieved successfully.']);
    }
}
