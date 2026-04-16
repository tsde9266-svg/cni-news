<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        // Page-view / event tracking endpoint — accepts and discards for now.
        return response()->json(['ok' => true]);
    }

    public function pageview(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
