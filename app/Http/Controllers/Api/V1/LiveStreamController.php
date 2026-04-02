<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public live streams endpoint — used by the public Next.js frontend.
 * Route: GET /api/v1/live-streams
 */
class LiveStreamController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // public function index(Request $request): JsonResponse
    // {
    //     $streams = DB::table('live_streams')
    //         ->where('channel_id', $this->channelId())
    //         ->where('is_public', true)
    //         ->whereIn('status', ['scheduled', 'live'])
    //         ->orderByRaw("FIELD(status, 'live', 'scheduled')")
    //         ->orderBy('scheduled_start_at')
    //         ->get()
    //         ->map(fn($s) => [
    //             'id'                 => $s->id,
    //             'title'              => $s->title,
    //             'description'        => $s->description,
    //             'platform'           => $s->primary_platform,
    //             'platform_stream_id' => $s->platform_stream_id,
    //             'status'             => $s->status,
    //             'is_live'            => $s->status === 'live',
    //             'scheduled_start_at' => $s->scheduled_start_at ?? null,
    //             'actual_end_at' => (property_exists($s, 'actual_end_at') ? $s->actual_end_at : null),
    //         ]);

    //     return response()->json(['data' => $streams]);
    // }

    public function index(Request $request): JsonResponse
    {
        $streams = DB::table('live_streams')
            ->where('channel_id', $this->channelId())
            ->where('is_public', true)
            ->whereIn('status', ['scheduled', 'live'])
            ->orderByRaw("FIELD(status, 'live', 'scheduled')")
            ->orderBy('scheduled_start_at')
            ->get()
            ->map(function($s) {  // ← Changed to function() for proper scope
                return [
                    'id' => isset($s->id) ? $s->id : null,
                    'title' => isset($s->title) ? $s->title : '',
                    'description' => isset($s->description) ? $s->description : '',
                    'platform' => isset($s->primary_platform) ? $s->primary_platform : '',
                    'platform_stream_id' => isset($s->platform_stream_id) ? $s->platform_stream_id : '',
                    'status' => isset($s->status) ? $s->status : '',
                    'is_live' => (isset($s->status) && $s->status === 'live'),
                    'scheduled_start_at' => isset($s->scheduled_start_at) ? $s->scheduled_start_at : null,
                    'actual_start_at' => isset($s->actual_start_at) ? $s->actual_start_at : null,
                    'actual_end_at' => isset($s->actual_end_at) ? $s->actual_end_at : null,
                ];
            });

        return response()->json(['data' => $streams]);
    }

}
