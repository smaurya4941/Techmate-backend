<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\GoogleReviewsException;
use App\Http\Controllers\Controller;
use App\Services\GoogleReviewsService;
use Illuminate\Http\JsonResponse;

class GoogleReviewsController extends Controller
{
    public function index(GoogleReviewsService $reviews): JsonResponse
    {
        try {
            return response()
                ->json($reviews->get())
                ->header('Cache-Control', 'public, max-age=300');
        } catch (GoogleReviewsException $e) {
            return response()
                ->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
