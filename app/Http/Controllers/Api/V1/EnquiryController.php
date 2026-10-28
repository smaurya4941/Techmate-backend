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
        $lead = $this->leads->createEnquiry($request->validated());

        return response()->json([
            'message' => 'Enquiry submitted successfully.',
            'data' => ['reference' => $lead->public_reference],
        ], 201);
    }
}
