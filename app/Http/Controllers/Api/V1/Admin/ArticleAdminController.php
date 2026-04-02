<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin article endpoints.
 * All routes are under /api/v1/admin/* (auth:sanctum + role middleware applied in routes).
 */
class ArticleAdminController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/articles
    public function index(Request $request): JsonResponse
    {
        $query = Article::with([
            'translations' => fn($q) => $q->where('language_id', 1)->select('article_id','language_id','title','summary'),
            'author:id,display_name',
            'mainCategory:id,default_name,slug',
        ])
        ->where('channel_id', $this->channelId())
        ->withCount('comments');

        // Filters
        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('type'))     $query->where('type', $request->type);
        if ($request->filled('author_id'))$query->where('author_user_id', $request->author_id);
        if ($request->filled('category')) {
            $catId = DB::table('categories')->where('slug', $request->category)->value('id');
            if ($catId) $query->where('main_category_id', $catId);
        }
        if ($request->boolean('breaking')) $query->where('is_breaking', true);
        if ($request->boolean('featured')) $query->where('is_featured', true);

        if ($request->filled('search')) {
            $query->whereHas('translations', fn($q) =>
                $q->where('title', 'like', '%' . $request->search . '%')
            );
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortable = ['created_at', 'updated_at', 'published_at', 'view_count', 'word_count'];
        $sort  = in_array($request->sort, $sortable) ? $request->sort : 'created_at';
        $order = $request->order === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage  = min((int) $request->get('per_page', 20), 100);
        $articles = $query->paginate($perPage);

        return response()->json([
            'data' => $articles->map(fn($a) => $this->articleRow($a)),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page'    => $articles->lastPage(),
                'per_page'     => $articles->perPage(),
                'total'        => $articles->total(),
                'from'         => $articles->firstItem(),
                'to'           => $articles->lastItem(),
            ],
        ]);
    }

    // GET /api/v1/admin/articles/pending
    public function pending(): JsonResponse
    {
        $articles = Article::with([
            'translations' => fn($q) => $q->where('language_id', 1)->select('article_id','language_id','title'),
            'author:id,display_name',
        ])
        ->where('channel_id', $this->channelId())
        ->where('status', 'pending_review')
        ->orderByDesc('created_at')
        ->limit(20)
        ->get();

        return response()->json(['data' => $articles->map(fn($a) => $this->articleRow($a))]);
    }

    // GET /api/v1/admin/articles/{id}
    public function show(int $id): JsonResponse
    {
        $article = Article::with([
            'translations',
            'mainCategory:id,default_name,slug',
            'tags:id,slug,default_name',
            'author:id,display_name',
            'featuredImage',
        ])
        ->where('channel_id', $this->channelId())
        ->findOrFail($id);

        // Build full detailed payload
        $translations = $article->translations->map(fn($t) => [
            'language_id'     => $t->language_id,
            'title'           => $t->title,
            'subtitle'        => $t->subtitle,
            'summary'         => $t->summary,
            'body'            => $t->body,
            'seo_title'       => $t->seo_title,
            'seo_description' => $t->seo_description,
        ]);

        return response()->json(['data' => [
            ...$this->articleRow($article),
            'translations'    => $translations,
            'tags'            => $article->tags->map(fn($t) => ['id' => $t->id, 'name' => $t->default_name, 'slug' => $t->slug]),
            'featured_image'  => $article->featuredImage ? [
                'id'  => $article->featuredImage->id,
                'url' => $article->featuredImage->original_url,
                'alt' => $article->featuredImage->alt_text,
            ] : null,
        ]]);
    }

    // PATCH /api/v1/admin/articles/{id}/set-rate
    public function setArticleRate(Request $request, int $id): JsonResponse
    {
        $request->validate(['rate_amount' => 'required|numeric|min:0']);
        DB::table('author_earnings')
            ->where('article_id', $id)
            ->update(['amount' => $request->rate_amount]);
        return response()->json(['message' => 'Rate updated.']);
    }

    // ── Bulk actions ──────────────────────────────────────────────────────
    // POST /api/v1/admin/articles/bulk
    public function bulk(Request $request): JsonResponse
    {
        $request->validate([
            'ids'    => 'required|array',
            'ids.*'  => 'integer',
            'action' => 'required|in:publish,unpublish,delete,archive,set_breaking,unset_breaking',
        ]);

        $ids  = $request->ids;
        $user = $request->user();

        match ($request->action) {
            'publish'       => Article::whereIn('id', $ids)->update(['status' => 'published', 'published_at' => now()]),
            'unpublish'     => Article::whereIn('id', $ids)->update(['status' => 'draft']),
            'archive'       => Article::whereIn('id', $ids)->update(['status' => 'archived']),
            'delete'        => Article::whereIn('id', $ids)->delete(),
            'set_breaking'  => Article::whereIn('id', $ids)->update(['is_breaking' => true]),
            'unset_breaking'=> Article::whereIn('id', $ids)->update(['is_breaking' => false]),
        };

        AuditLog::log('article_bulk_' . $request->action, 'article', null, null, [
            'ids'   => $ids,
            'actor' => $user->display_name,
        ]);

        return response()->json(['message' => 'Bulk action applied.', 'affected' => count($ids)]);
    }

    // ── Status transitions (also available via public routes but repeated here for clarity) ──
    // POST /api/v1/admin/articles/{id}/publish
    public function publish(Request $request, int $id): JsonResponse
    {
        $article = Article::where('channel_id', $this->channelId())->findOrFail($id);
        $article->update(['status' => 'published', 'published_at' => $article->published_at ?? now()]);
        AuditLog::log('article_published', 'article', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Published.', 'data' => $this->articleRow($article->fresh())]);
    }

    // POST /api/v1/admin/articles/{id}/unpublish
    public function unpublish(Request $request, int $id): JsonResponse
    {
        $article = Article::where('channel_id', $this->channelId())->findOrFail($id);
        $article->update(['status' => 'draft']);
        AuditLog::log('article_unpublished', 'article', $id, null, ['actor' => $request->user()->display_name]);
        return response()->json(['message' => 'Unpublished.', 'data' => $this->articleRow($article->fresh())]);
    }

    // POST /api/v1/admin/articles/{id}/approve
    public function approve(Request $request, int $id): JsonResponse
    {
        $article = Article::where('channel_id', $this->channelId())->findOrFail($id);
        $article->update(['status' => 'published', 'published_at' => now(), 'editor_user_id' => $request->user()->id]);
        AuditLog::log('article_approved', 'article', $id, null, ['editor' => $request->user()->display_name]);
        return response()->json(['message' => 'Approved and published.']);
    }

    // POST /api/v1/admin/articles/{id}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $article = Article::where('channel_id', $this->channelId())->findOrFail($id);
        $article->update(['status' => 'draft']);
        AuditLog::log('article_rejected', 'article', $id, null, [
            'editor' => $request->user()->display_name,
            'reason' => $request->reason,
        ]);
        return response()->json(['message' => 'Returned to draft.']);
    }

    // ── Private helper ─────────────────────────────────────────────────────
    private function articleRow(Article $a): array
    {
        return [
            'id'            => $a->id,
            'slug'          => $a->slug,
            'status'        => $a->status,
            'type'          => $a->type,
            'is_breaking'   => (bool) $a->is_breaking,
            'is_featured'   => (bool) $a->is_featured,
            'allow_comments'=> (bool) $a->allow_comments,
            'view_count'    => (int) $a->view_count,
            'word_count'    => (int) $a->word_count,
            'comments_count'=> (int) ($a->comments_count ?? 0),
            'published_at'  => $a->published_at?->toISOString(),
            'scheduled_at'  => $a->scheduled_at?->toISOString(),
            'created_at'    => $a->created_at->toISOString(),
            'updated_at'    => $a->updated_at->toISOString(),
            // Translation fields (flattened from first/English translation)
            'title'         => $a->translations->first()?->title ?? '—',
            'summary'       => $a->translations->first()?->summary,
            'author_id'     => $a->author_user_id,
            'author_name'   => $a->author?->display_name,
            'category_id'   => $a->main_category_id,
            'category_name' => $a->mainCategory?->default_name,
        ];
    }
}
