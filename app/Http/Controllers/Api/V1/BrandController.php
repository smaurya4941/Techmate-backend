<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ApiCollection;
use App\Http\Resources\V1\BrandResource;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): ApiCollection
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('techmate.catalogue.max_per_page')],
        ]);

        $brands = Brand::query()
            ->active()
            ->withCount(['products' => fn (Builder $q) => $q->where('status', 'published')
                ->where(fn (Builder $p) => $p->whereNull('published_at')->orWhere('published_at', '<=', now()))])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 24))
            ->withQueryString();

        return new ApiCollection($brands, BrandResource::class, 'Brands retrieved successfully.');
    }
}
