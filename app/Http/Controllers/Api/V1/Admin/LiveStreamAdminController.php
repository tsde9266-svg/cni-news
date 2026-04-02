<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveStreamAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('live_streams')
            ->where('channel_id', $this->channelId())
            ->orderByDesc('scheduled_start_at');

        if ($request->filled('status')) $query->where('status', $request->status);

        $paged = $query->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($s) => [
                'id'                 => $s->id,
                'title'              => $s->title,
                'description'        => $s->description,
                'status'             => $s->status,
                'primary_platform'   => $s->primary_platform,
                'platform_stream_id' => $s->platform_stream_id,
                'scheduled_start_at' => $s->scheduled_start_at,
                'actual_start_at'    => $s->actual_start_at,
                'actual_end_at'      => $s->actual_end_at,
                'is_public'          => (bool) $s->is_public,
                'peak_viewers'       => (int) $s->peak_viewers,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page'     => $paged->perPage(),     'total'     => $paged->total(),
                'from'         => $paged->firstItem(),   'to'        => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title'            => 'required|string|max:255',
            'primary_platform' => 'required|in:youtube,facebook,custom_rtmp',
        ]);

        $id = DB::table('live_streams')->insertGetId([
            'channel_id'         => $this->channelId(),
            'title'              => $request->title,
            'description'        => $request->description,
            'primary_platform'   => $request->primary_platform,
            'platform_stream_id' => $request->platform_stream_id,
            'scheduled_start_at' => $request->scheduled_start_at,
            'status'             => 'scheduled',
            'is_public'          => $request->boolean('is_public', true),
            'peak_viewers'       => 0,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Stream created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = array_filter([
            'title'              => $request->title,
            'description'        => $request->description,
            'platform_stream_id' => $request->platform_stream_id,
            'scheduled_start_at' => $request->scheduled_start_at,
            'is_public'          => $request->has('is_public') ? (int) $request->boolean('is_public') : null,
            'updated_at'         => now(),
        ], fn($v) => $v !== null);

        DB::table('live_streams')->where('id', $id)->update($data);
        return response()->json(['message' => 'Updated.']);
    }

    public function goLive(Request $request, int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->update([
            'status'          => 'live',
            'actual_start_at' => now(),
            'updated_at'      => now(),
        ]);
        AuditLog::log('live_stream_started', 'live_stream', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Stream is now live.']);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->update([
            'status'        => 'ended',
            'actual_end_at' => now(),
            'updated_at'    => now(),
        ]);
        AuditLog::log('live_stream_ended', 'live_stream', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Stream ended.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('live_streams')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
