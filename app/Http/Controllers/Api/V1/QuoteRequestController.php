<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteRequestRequest;
use App\Services\LeadSubmissionService;
use Illuminate\Http\JsonResponse;

class QuoteRequestController extends Controller
{
    public function __construct(private readonly LeadSubmissionService $leads) {}

    public function store(QuoteRequestRequest $request): JsonResponse
    {
        $lead = $this->leads->createQuoteRequest($request->validated(), $request->lineItems());

        return response()->json([
            'message' => 'Quote request submitted successfully.',
            'data' => ['reference' => $lead->public_reference],
        ], 201);
    }
}
