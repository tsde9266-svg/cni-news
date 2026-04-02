<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\ArticleVersion;
use App\Models\AuditLog;
use App\Services\ArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function __construct(private ArticleService $articleService) {}

    // ── GET /api/v1/articles ───────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $langId = $this->langId($request);

        $query = Article::with([
            'translations'    => fn($q) => $q->where('language_id', $langId),
            'mainCategory',
            'author',
            'featuredImage.variants',
        ])
        ->published()
        ->forChannel($this->channelId());

        if ($request->filled('category')) {
            $catId = DB::table('categories')->where('slug', $request->category)->value('id');
            $query->where('main_category_id', $catId);
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('slug', $request->tag));
        }

        if ($request->filled('author_id')) {
            $query->where('author_user_id', $request->author_id);
        }

        if ($request->boolean('breaking')) {
            $query->where('is_breaking', true);
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $ids = ArticleTranslation::search($request->search)->keys();
            $query->whereIn('id', $ids);
        }

        $sortable = ['published_at', 'view_count', 'created_at'];
        $sort     = in_array($request->sort, $sortable) ? $request->sort : 'published_at';
        $order    = $request->order === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage  = min((int) $request->get('per_page', 15), 50);
        $articles = $query->paginate($perPage);

        return response()->json([
            'data' => ArticleResource::collection($articles),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page'    => $articles->lastPage(),
                'per_page'     => $articles->perPage(),
                'total'        => $articles->total(),
            ],
        ]);
    }

    // ── GET /api/v1/articles/{slug} ────────────────────────────────────────
    public function show(Request $request, string $slug): JsonResponse
    {
        $article = Article::with([
            'translations',
            'mainCategory',
            'categories',
            'tags',
            'author.authorProfile',
            'featuredImage.variants',
            'comments' => fn($q) => $q->where('status', 'approved')
                ->whereNull('parent_comment_id')
                ->with(['replies' => fn($r) => $r->where('status', 'approved')])
                ->latest()
                ->limit(20),
        ])
        ->where('slug', $slug)
        ->where('channel_id', $this->channelId())
        ->published()
        ->firstOrFail();

        $this->articleService->incrementViewCount($article->id);

        return response()->json([
            'data' => new ArticleResource($article, detailed: true),
        ]);
    }

    // ── POST /api/v1/articles ──────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Article::class);

        $validated = $request->validate([
            'title'                    => ['required', 'string', 'max:320'],
            'subtitle'                 => ['nullable', 'string', 'max:320'],
            'summary'                  => ['nullable', 'string', 'max:1000'],
            'body'                     => ['required', 'string', 'min:10'],
            'language_id'              => ['required', 'integer', 'exists:languages,id'],
            'type'                     => ['nullable', 'in:news,opinion,interview,analysis,bulletin,sponsored'],
            'main_category_id'         => ['required', 'integer', 'exists:categories,id'],
            'featured_image_media_id'  => ['nullable', 'integer', 'exists:media_assets,id'],
            'tag_ids'                  => ['nullable', 'array'],
            'tag_ids.*'                => ['integer', 'exists:tags,id'],
            'allow_comments'           => ['nullable', 'boolean'],
            'seo_title'                => ['nullable', 'string', 'max:160'],
            'seo_description'          => ['nullable', 'string', 'max:320'],
        ]);

        $article = DB::transaction(function () use ($validated, $request) {
            $channelId = $this->channelId();

            $article = Article::create([
                'channel_id'              => $channelId,
                'primary_language_id'     => $validated['language_id'],
                'slug'                    => $this->generateSlug($validated['title'], $channelId),
                'status'                  => 'draft',
                'type'                    => $validated['type'] ?? 'news',
                'author_user_id'          => $request->user()->id,
                'main_category_id'        => $validated['main_category_id'],
                'featured_image_media_id' => $validated['featured_image_media_id'] ?? null,
                'allow_comments'          => $validated['allow_comments'] ?? true,
            ]);

            ArticleTranslation::create([
                'article_id'      => $article->id,
                'language_id'     => $validated['language_id'],
                'title'           => $validated['title'],
                'subtitle'        => $validated['subtitle'] ?? null,
                'summary'         => $validated['summary'] ?? null,
                'body'            => $validated['body'],
                'seo_title'       => $validated['seo_title'] ?? $validated['title'],
                'seo_description' => $validated['seo_description'] ?? $validated['summary'] ?? null,
            ]);

            ArticleVersion::create([
                'article_id'       => $article->id,
                'language_id'      => $validated['language_id'],
                'version_number'   => 1,
                'title'            => $validated['title'],
                'body'             => $validated['body'],
                'saved_by_user_id' => $request->user()->id,
                'change_summary'   => 'Initial draft',
            ]);

            if (! empty($validated['tag_ids'])) {
                $article->tags()->sync($validated['tag_ids']);
            }

            return $article;
        });

        AuditLog::log('article_created', 'article', $article->id, null, [
            'title'  => $validated['title'],
            'author' => $request->user()->display_name,
        ]);

        return response()->json(['data' => new ArticleResource($article)], 201);
    }

    // ── PATCH /api/v1/articles/{id} ────────────────────────────────────────
    public function update(Request $request, Article $article): JsonResponse
    {
        // $article = Article::findOrFail($id);
        $this->authorize('update', $article);

        // Check content lock
        $lock = DB::table('content_locks')
            ->where('content_type', 'article')
            ->where('content_id', $article->id)
            ->where('expires_at', '>', now())
            ->first();

        if ($lock && $lock->locked_by_user_id !== $request->user()->id) {
            $locker = DB::table('users')->where('id', $lock->locked_by_user_id)->value('display_name');
            return response()->json([
                'errors' => ['lock' => ["This article is being edited by {$locker}. Try again shortly."]],
            ], 423);
        }

        $validated = $request->validate([
            'title'                    => ['nullable', 'string', 'max:320'],
            'subtitle'                 => ['nullable', 'string', 'max:320'],
            'summary'                  => ['nullable', 'string', 'max:1000'],
            'body'                     => ['nullable', 'string', 'min:10'],
            'language_id'              => ['nullable', 'integer', 'exists:languages,id'],
            'type'                     => ['nullable', 'in:news,opinion,interview,analysis,bulletin,sponsored'],
            'main_category_id'         => ['nullable', 'integer', 'exists:categories,id'],
            'featured_image_media_id'  => ['nullable', 'integer', 'exists:media_assets,id'],
            'tag_ids'                  => ['nullable', 'array'],
            'allow_comments'           => ['nullable', 'boolean'],
            'change_summary'           => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $article, $request) {
            // Acquire lock
            DB::table('content_locks')->updateOrInsert(
                ['content_type' => 'article', 'content_id' => $article->id],
                ['locked_by_user_id' => $request->user()->id, 'locked_at' => now(), 'expires_at' => now()->addMinutes(15)]
            );

            $article->update(array_filter([
                'type'                    => $validated['type'] ?? null,
                'main_category_id'        => $validated['main_category_id'] ?? null,
                'featured_image_media_id' => $validated['featured_image_media_id'] ?? null,
                'allow_comments'          => $validated['allow_comments'] ?? null,
            ], fn($v) => ! is_null($v)));

            $langId = $validated['language_id'] ?? $article->primary_language_id;

            if (! empty($validated['title']) || ! empty($validated['body'])) {
                $translation = ArticleTranslation::where('article_id', $article->id)
                    ->where('language_id', $langId)
                    ->first();

                if ($translation) {
                    $translation->update(array_filter([
                        'title'           => $validated['title'] ?? null,
                        'subtitle'        => $validated['subtitle'] ?? null,
                        'summary'         => $validated['summary'] ?? null,
                        'body'            => $validated['body'] ?? null,
                    ], fn($v) => ! is_null($v)));

                    $latest = ArticleVersion::where('article_id', $article->id)
                        ->where('language_id', $langId)
                        ->max('version_number') ?? 0;

                    ArticleVersion::create([
                        'article_id'       => $article->id,
                        'language_id'      => $langId,
                        'version_number'   => $latest + 1,
                        'title'            => $translation->title,
                        'body'             => $translation->body,
                        'saved_by_user_id' => $request->user()->id,
                        'change_summary'   => $validated['change_summary'] ?? null,
                    ]);
                }
            }

            if (array_key_exists('tag_ids', $validated)) {
                $article->tags()->sync($validated['tag_ids'] ?? []);
            }
        });

        AuditLog::log('article_updated', 'article', $article->id);

        return response()->json(['data' => new ArticleResource($article->fresh())]);
    }

    // ── POST /api/v1/articles/{id}/submit ─────────────────────────────────
    public function submit(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->authorize('update', $article);

        abort_unless($article->status === 'draft', 422, 'Only draft articles can be submitted.');

        $article->update(['status' => 'pending_review']);
        AuditLog::log('article_submitted_for_review', 'article', $article->id);

        return response()->json(['message' => 'Submitted for editor review.']);
    }

    // ── POST /api/v1/articles/{id}/publish ────────────────────────────────
    public function publish(Request $request, int $id): JsonResponse
    {
        $article = Article::with('author.authorProfile')->findOrFail($id);
        $this->authorize('publish', $article);

        abort_unless(
            in_array($article->status, ['draft', 'pending_review', 'scheduled']),
            422,
            'This article cannot be published in its current state.'
        );

        $request->validate(['publish_at' => ['nullable', 'date']]);

        $publishAt = $request->filled('publish_at')
            ? \Carbon\Carbon::parse($request->publish_at)
            : now();

        $status = $publishAt->isFuture() ? 'scheduled' : 'published';

        $article->update([
            'status'         => $status,
            'published_at'   => $status === 'published' ? $publishAt : null,
            'scheduled_at'   => $status === 'scheduled' ? $publishAt : null,
            'editor_user_id' => $request->user()->id,
            'word_count'     => $this->articleService->countWords($article),
        ]);

        if ($status === 'published') {
            $this->articleService->recordAuthorEarning($article);
        }

        AuditLog::log("article_{$status}", 'article', $article->id, null, [
            'published_at' => $publishAt,
            'editor'       => $request->user()->display_name,
        ]);

        return response()->json([
            'message' => $status === 'published'
                ? 'Article published.'
                : "Scheduled for {$publishAt->format('d M Y H:i')} UTC.",
            'data' => new ArticleResource($article->fresh()),
        ]);
    }

    // ── POST /api/v1/articles/{id}/unpublish ──────────────────────────────
    public function unpublish(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->authorize('publish', $article);
        $article->update(['status' => 'archived', 'published_at' => null]);
        AuditLog::log('article_unpublished', 'article', $article->id);
        return response()->json(['message' => 'Article archived.']);
    }

    // ── POST /api/v1/articles/{id}/breaking ───────────────────────────────
    public function toggleBreaking(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->authorize('setBreaking', $article);
        $article->update(['is_breaking' => ! $article->is_breaking]);
        return response()->json([
            'is_breaking' => $article->is_breaking,
            'message'     => $article->is_breaking ? 'Marked as breaking.' : 'Breaking flag removed.',
        ]);
    }

    // ── GET /api/v1/articles/{id}/versions ────────────────────────────────
    public function versions(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->authorize('update', $article);

        $versions = ArticleVersion::where('article_id', $id)
            ->with('savedBy')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn($v) => [
                'version'        => $v->version_number,
                'title'          => $v->title,
                'saved_by'       => $v->savedBy?->display_name,
                'change_summary' => $v->change_summary,
                'created_at'     => $v->created_at,
            ]);

        return response()->json(['data' => $versions]);
    }

    // ── DELETE /api/v1/articles/{id} ──────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->authorize('delete', $article);
        AuditLog::log('article_deleted', 'article', $article->id, $article->only('slug', 'status'));
        $article->delete();
        return response()->json(null, 204);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function langId(Request $request): int
    {
        $code = $request->get('lang', 'en');
        return DB::table('languages')->where('code', $code)->value('id') ?? 1;
    }

    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id');
    }

    private function generateSlug(string $title, int $channelId): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i    = 1;
        while (DB::table('articles')->where('channel_id', $channelId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
