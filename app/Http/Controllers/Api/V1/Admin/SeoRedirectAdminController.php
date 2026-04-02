<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeoRedirectAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('seo_redirects')
            ->where('channel_id', $this->channelId())
            ->orderByDesc('hit_count');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('old_path', 'like', "%{$s}%")
                  ->orWhere('new_path', 'like', "%{$s}%")
            );
        }
        if ($request->has('active')) $query->where('is_active', $request->boolean('active'));

        $paged = $query->paginate(min((int) $request->get('per_page', 25), 100));

        return response()->json([
            'data' => collect($paged->items())->map(fn($r) => [
                'id'        => $r->id, 'old_path' => $r->old_path,
                'new_path'  => $r->new_path, 'http_code' => $r->http_code,
                'hit_count' => $r->hit_count, 'is_active' => (bool) $r->is_active,
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
        $request->validate(['old_path' => 'required|string|max:500', 'new_path' => 'required|string|max:500']);

        $id = DB::table('seo_redirects')->insertGetId([
            'channel_id' => $this->channelId(),
            'old_path'   => $request->old_path,
            'new_path'   => $request->new_path,
            'http_code'  => $request->get('http_code', 301),
            'is_active'  => true, 'hit_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Redirect created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        DB::table('seo_redirects')->where('id', $id)->update(array_filter([
            'old_path'   => $request->old_path,
            'new_path'   => $request->new_path,
            'http_code'  => $request->http_code,
            'is_active'  => $request->has('is_active') ? (int) $request->boolean('is_active') : null,
            'updated_at' => now(),
        ], fn($v) => $v !== null));

        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('seo_redirects')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
