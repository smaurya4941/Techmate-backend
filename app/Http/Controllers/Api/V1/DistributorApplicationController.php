<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DistributorApplicationRequest;
use App\Services\DistributorApplicationService;
use Illuminate\Http\JsonResponse;

class DistributorApplicationController extends Controller
{
    public function __construct(private readonly DistributorApplicationService $applications) {}

    public function store(DistributorApplicationRequest $request): JsonResponse
    {
        $application = $this->applications->submit($request->validated());

        return response()->json([
            'message' => 'Distributor application submitted successfully.',
            'data' => ['reference' => $application->public_reference],
        ], 201);
    }
}
