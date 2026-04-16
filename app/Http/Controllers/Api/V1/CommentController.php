<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $articleId = $request->get('article_id');
        if (! $articleId) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $perPage  = min((int) $request->get('per_page', 20), 50);
        $comments = DB::table('comments')
            ->where('article_id', $articleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $comments->items(),
            'meta' => ['current_page' => $comments->currentPage(), 'last_page' => $comments->lastPage(), 'total' => $comments->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['article_id' => 'required|integer', 'body' => 'required|string|max:2000']);

        $user = $request->user();
        DB::table('comments')->insert([
            'article_id' => $request->article_id,
            'user_id'    => $user->id,
            'body'       => $request->body,
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Comment submitted and awaiting moderation.'], 201);
    }
}
