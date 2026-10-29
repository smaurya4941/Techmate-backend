<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnquiryRequest;
use App\Services\LeadSubmissionService;
use Illuminate\Http\JsonResponse;

class EnquiryController extends Controller
{
    public function __construct(private readonly LeadSubmissionService $leads) {}

    public function store(EnquiryRequest $request): JsonResponse
    {
        [$lead, $created] = $this->leads->createEnquiry($request->validated(), $request->idempotencyKey(), $request->submittedAt());

        // A replayed Idempotency-Key returns the original reference with 200 instead of creating a second lead.
        return response()->json([
            'message' => 'Enquiry submitted successfully.',
            'data' => ['reference' => $lead->public_reference],
        ], $created ? 201 : 200);
    }
}
