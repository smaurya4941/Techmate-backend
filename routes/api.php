<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryProductController;
use App\Http\Controllers\Api\V1\DistributorApplicationController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteRequestController;
use App\Http\Controllers\Api\V1\SettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (prefixed with /api by the framework)
|--------------------------------------------------------------------------
*/

// Phase 1 System Health Check Endpoint
Route::get('/health', HealthCheckController::class)->name('api.health');

// Authenticated user endpoint (prepared for future phases)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| Public REST API, version 1: /api/v1
| Catalogue routes are read-only. Submission routes are write-only: nothing
| here lists or returns leads, applications or quotations.
|
| Deferred: POST /service-requests (no approved service workflow yet; see docs/api-specification.md).
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/{slug}/products', [CategoryProductController::class, 'index'])->name('categories.products');
        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
        Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
        Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    });

    Route::middleware('throttle:public-submissions')->group(function () {
        Route::post('/enquiries', [EnquiryController::class, 'store'])->name('enquiries.store');
        Route::post('/quote-requests', [QuoteRequestController::class, 'store'])->name('quote-requests.store');
        Route::post('/distributor-applications', [DistributorApplicationController::class, 'store'])->name('distributor-applications.store');
    });
});
