<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── CategoryAdminController ────────────────────────────────────────────────
class CategoryAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(): JsonResponse
    {
        $cats = DB::table('categories')
            ->where('channel_id', $this->channelId())
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get()
            ->map(fn($c) => [
                'id'           => $c->id,
                'slug'         => $c->slug,
                'name'         => $c->default_name,
                'description'  => $c->default_description,
                'position'     => $c->position,
                'is_featured'  => (bool) $c->is_featured,
                'is_active'    => (bool) $c->is_active,
                'parent_id'    => $c->parent_id,
                'article_count'=> DB::table('articles')
                    ->where('main_category_id', $c->id)
                    ->whereNull('deleted_at')
                    ->count(),
            ]);

        return response()->json(['data' => $cats]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:120',
            'slug'     => 'nullable|string|max:120',
            'position' => 'nullable|integer',
        ]);

        $slug = $request->slug
            ? Str::slug($request->slug)
            : Str::slug($request->name);

        abort_if(
            DB::table('categories')->where('slug', $slug)->where('channel_id', $this->channelId())->exists(),
            422, 'A category with this slug already exists.'
        );

        $id = DB::table('categories')->insertGetId([
            'channel_id'         => $this->channelId(),
            'slug'               => $slug,
            'default_name'       => $request->name,
            'default_description'=> $request->description,
            'position'           => $request->position ?? 99,
            'is_active'          => true,
            'is_featured'        => $request->boolean('is_featured'),
            'parent_id'          => $request->parent_id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('category_translations')->insertOrIgnore([
            'category_id' => $id,
            'language_id' => 1,
            'name'        => $request->name,
            'description' => $request->description,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['message' => 'Category created.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate(['name' => 'sometimes|string|max:120']);

        $update = array_filter([
            'default_name'        => $request->name,
            'default_description' => $request->description,
            'position'            => $request->position,
            'is_featured'         => $request->has('is_featured') ? (int)$request->boolean('is_featured') : null,
            'is_active'           => $request->has('is_active')   ? (int)$request->boolean('is_active')   : null,
            'updated_at'          => now(),
        ], fn($v) => $v !== null);

        DB::table('categories')->where('id', $id)->update($update);

        if ($request->filled('name')) {
            DB::table('category_translations')
                ->where('category_id', $id)->where('language_id', 1)
                ->update(['name' => $request->name, 'updated_at' => now()]);
        }

        return response()->json(['message' => 'Updated.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $count = DB::table('articles')->where('main_category_id', $id)->whereNull('deleted_at')->count();
        abort_if($count > 0, 422, "Cannot delete: {$count} articles use this category.");
        DB::table('categories')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Deleted.']);
    }
}
