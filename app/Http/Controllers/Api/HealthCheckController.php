<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckController extends Controller
{
    /**
     * Return application health status.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        try {
            // Lightweight check to verify database connectivity without disclosing internal details
            DB::connection()->getPdo();

            return response()->json([
                'success' => true,
                'application' => config('app.name', 'Techmate API'),
                'message' => 'API is running successfully.',
                'environment' => app()->environment(),
            ], 200);
        } catch (Throwable $e) {
            // Return clean sanitized error response without leaking credentials or stack traces
            return response()->json([
                'success' => false,
                'application' => config('app.name', 'Techmate API'),
                'message' => 'Database connectivity unavailable.',
                'environment' => app()->environment(),
            ], 503);
        }
    }
}
