<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MembershipController extends Controller
{
    public function plans(Request $request): JsonResponse
    {
        $plans = DB::table('membership_plans')
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Membership subscription coming soon.'], 422);
    }

    public function cancel(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Membership cancellation coming soon.'], 422);
    }

    public function applyPromo(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Promo code feature coming soon.'], 422);
    }

    public function validatePromo(Request $request): JsonResponse
    {
        return response()->json(['valid' => false, 'message' => 'Invalid promo code.'], 422);
    }

    public function myMembership(Request $request): JsonResponse
    {
        return response()->json(['data' => null]);
    }
}
