<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialFeedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SocialFeedController
 *
 * Public endpoint — no auth required.
 * Returns social_feed_items for the homepage social widget.
 * Cached for 30 minutes (matches the social:ingest schedule).
 *
 * GET /api/v1/social-feed
 */
class SocialFeedController extends Controller
{
    public function index(): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;

        $items = Cache::remember("social_feed_{$channelId}", 1800, function () use ($channelId) {
            return SocialFeedItem::where('channel_id', $channelId)
                ->where('is_visible', true)
                ->orderByDesc('posted_at')
                ->limit(12)
                ->get()
                ->map(fn($item) => [
                    'id'            => $item->id,
                    'platform'      => $item->platform,
                    'content_type'  => $item->content_type,
                    'title'         => $item->title,
                    'caption'       => $item->caption
                        ? mb_substr($item->caption, 0, 120) . (mb_strlen($item->caption) > 120 ? '...' : '')
                        : null,
                    'thumbnail_url' => $item->thumbnail_url,
                    'media_url'     => $item->media_url,
                    'permalink'     => $item->permalink,
                    'posted_at'     => $item->posted_at?->toIso8601String(),
                    'views_count'   => $item->views_count,
                    'likes_count'   => $item->likes_count,
                ]);
        });

        return response()->json(['data' => $items]);
    }
}
