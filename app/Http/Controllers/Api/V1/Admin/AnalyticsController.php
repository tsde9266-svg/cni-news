<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'total_articles' => DB::table('articles')->whereNull('deleted_at')->count(),
                'total_users'    => DB::table('users')->whereNull('deleted_at')->count(),
                'total_events'   => DB::table('events')->whereNull('deleted_at')->count(),
                'total_views'    => DB::table('articles')->sum('view_count'),
            ],
        ]);
    }
}
