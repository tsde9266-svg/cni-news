<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DisplayAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/display-ads?placement=leaderboard|sidebar|in-feed|all
 *
 * Returns active ads for the requested placement.
 * placement=all (or omitted) returns every active ad.
 * Ads with placement='all' always appear in every query.
 */
class DisplayAdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $placement = $request->get('placement', 'all');
        $cacheKey  = "display_ads_{$placement}";

        $ads = Cache::remember($cacheKey, 300, function () use ($placement) {
            return DisplayAd::live()
                ->where(function ($q) use ($placement) {
                    $q->where('placement', $placement)
                      ->orWhere('placement', 'all');
                })
                ->orderBy('display_order')
                ->get(['id', 'title', 'image_url', 'media_type', 'video_url', 'click_url', 'alt_text', 'placement']);
        });

        return response()->json(['data' => $ads]);
    }
}
