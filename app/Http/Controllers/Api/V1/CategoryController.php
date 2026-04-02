<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    // ── GET /api/v1/categories ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $langId    = DB::table('languages')->where('code', $request->get('lang', 'en'))->value('id') ?? 1;

        $categories = Category::with(['translations' => fn($q) => $q->where('language_id', $langId)])
            ->where('channel_id', $channelId)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('position')
            ->get()
            ->map(fn($cat) => [
                'id'          => $cat->id,
                'slug'        => $cat->slug,
                'name'        => $cat->translations->first()?->name ?? $cat->default_name,
                'description' => $cat->translations->first()?->description ?? $cat->default_description,
                'is_featured' => $cat->is_featured,
                'position'    => $cat->position,
            ]);

        return response()->json(['data' => $categories]);
    }

    // ── GET /api/v1/categories/{slug} ──────────────────────────────────────
    public function show(Request $request, string $slug): JsonResponse
    {
        $channelId = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $langId    = DB::table('languages')->where('code', $request->get('lang', 'en'))->value('id') ?? 1;

        $category = Category::with([
            'translations'  => fn($q) => $q->where('language_id', $langId),
            'children.translations' => fn($q) => $q->where('language_id', $langId),
        ])
        ->where('slug', $slug)
        ->where('channel_id', $channelId)
        ->where('is_active', true)
        ->firstOrFail();

        return response()->json([
            'data' => [
                'id'          => $category->id,
                'slug'        => $category->slug,
                'name'        => $category->translations->first()?->name ?? $category->default_name,
                'description' => $category->translations->first()?->description ?? $category->default_description,
                'children'    => $category->children->map(fn($c) => [
                    'id'   => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->translations->first()?->name ?? $c->default_name,
                ]),
            ],
        ]);
    }
}
