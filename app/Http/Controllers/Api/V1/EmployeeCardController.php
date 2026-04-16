<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EmployeeCardController extends Controller
{
    public function verify(string $cardNumber): JsonResponse
    {
        // Placeholder — CNI employee card verification
        return response()->json(['data' => ['valid' => false, 'message' => 'Card not found.']], 404);
    }
}
