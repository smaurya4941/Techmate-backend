<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SiteSettings;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /** Returns only the declared, filled website settings (all public by design). */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Settings retrieved successfully.',
            'data' => (object) SiteSettings::publicValues(),
        ]);
    }
}
