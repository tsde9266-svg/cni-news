<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tags')
            ->where('channel_id', $this->channelId())
            ->select('tags.*', DB::raw(
                '(SELECT COUNT(*) FROM article_tag_map WHERE article_tag_map.tag_id = tags.id) as article_count'
            ));

        if ($request->filled('search')) {
            $query->where('default_name', 'like', '%' . $request->search . '%');
        }

        $query->orderByDesc('article_count');
        $perPage = min((int) $request->get('per_page', 50), 200);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paged->items())->map(fn($t) => [
                'id'            => $t->id,
                'slug'          => $t->slug,
                'name'          => $t->default_name,
                'article_count' => (int) $t->article_count,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
                'total'        => $paged->total(),
                'from'         => $paged->firstItem(),
                'to'           => $paged->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:80']);
        $slug = Str::slug($request->name);

        abort_if(
            DB::table('tags')->where('slug', $slug)->where('channel_id', $this->channelId())->exists(),
            422, 'A tag with this slug already exists.'
        );

        $id = DB::table('tags')->insertGetId([
            'channel_id'   => $this->channelId(),
            'slug'         => $slug,
            'default_name' => $request->name,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Tag created.', 'data' => ['id' => $id, 'slug' => $slug, 'name' => $request->name]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:80']);
        DB::table('tags')->where('id', $id)->update([
            'default_name' => $request->name,
            'slug'         => Str::slug($request->name),
            'updated_at'   => now(),
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('article_tag_map')->where('tag_id', $id)->delete();
        DB::table('tags')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
