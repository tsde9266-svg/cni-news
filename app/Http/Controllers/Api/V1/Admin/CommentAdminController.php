<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/comments
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('comments')
            ->join('articles', 'comments.article_id', '=', 'articles.id')
            ->leftJoin('article_translations', function ($j) {
                $j->on('article_translations.article_id', '=', 'articles.id')
                  ->where('article_translations.language_id', 1);
            })
            ->leftJoin('users', 'comments.user_id', '=', 'users.id')
            ->where('articles.channel_id', $this->channelId())
            ->whereNull('comments.deleted_at')
            ->select([
                'comments.id',
                'comments.article_id',
                'comments.user_id',
                'comments.guest_name',
                'comments.guest_email',
                'comments.content',
                'comments.status',
                'comments.spam_score',
                'comments.created_at',
                'article_translations.title as article_title',
                'articles.slug as article_slug',
                'users.display_name as user_display_name',
                'users.email as user_email',
            ]);

        if ($request->filled('status'))  $query->where('comments.status', $request->status);
        if ($request->filled('search'))  {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('comments.content', 'like', "%{$s}%")
                  ->orWhere('comments.guest_name', 'like', "%{$s}%")
            );
        }
        if ($request->filled('article_id')) $query->where('comments.article_id', $request->article_id);

        $query->orderByDesc('comments.created_at');

        $perPage  = min((int) $request->get('per_page', 25), 100);
        $paged    = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paged->items())->map(fn($c) => [
                'id'               => $c->id,
                'article_id'       => $c->article_id,
                'article_title'    => $c->article_title ?? '—',
                'article_slug'     => $c->article_slug,
                'user_id'          => $c->user_id,
                'author_name'      => $c->user_display_name ?? $c->guest_name ?? 'Guest',
                'author_email'     => $c->user_email ?? $c->guest_email,
                'content'          => $c->content,
                'status'           => $c->status,
                'spam_score'       => $c->spam_score,
                'created_at'       => $c->created_at,
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

    // POST /api/v1/admin/comments/{id}/approve
    public function approve(Request $request, int $id): JsonResponse
    {
        DB::table('comments')->where('id', $id)->update(['status' => 'approved', 'updated_at' => now()]);
        AuditLog::log('comment_approved', 'comment', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Approved.']);
    }

    // POST /api/v1/admin/comments/{id}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        DB::table('comments')->where('id', $id)->update(['status' => 'rejected', 'updated_at' => now()]);
        AuditLog::log('comment_rejected', 'comment', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Rejected.']);
    }

    // DELETE /api/v1/admin/comments/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::table('comments')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Deleted.']);
    }

    // POST /api/v1/admin/comments/bulk-action
    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'ids'    => 'required|array',
            'action' => 'required|in:approve,reject,delete',
        ]);

        match ($request->action) {
            'approve' => DB::table('comments')->whereIn('id', $request->ids)->update(['status' => 'approved', 'updated_at' => now()]),
            'reject'  => DB::table('comments')->whereIn('id', $request->ids)->update(['status' => 'rejected', 'updated_at' => now()]),
            'delete'  => DB::table('comments')->whereIn('id', $request->ids)->update(['deleted_at' => now()]),
        };

        return response()->json(['message' => 'Done.', 'affected' => count($request->ids)]);
    }
}
