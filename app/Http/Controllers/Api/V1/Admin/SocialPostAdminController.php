<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\SocialPost;
use App\Models\SocialAccount;
use App\Services\Social\SocialPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SocialPostAdminController
 *
 * REST endpoints for the Social Hub admin panel.
 *
 * Routes (all under /api/v1/admin, auth:sanctum + role middleware):
 *
 *   GET  /social-posts                    → index()          paginated post history
 *   POST /social-posts                    → store()          create manual post
 *   GET  /social-posts/{id}               → show()           single post detail
 *   POST /social-posts/from-article/{id}  → fromArticle()    share article to socials
 *   POST /social-posts/{id}/cancel        → cancel()         cancel queued/scheduled post
 *   POST /social-posts/{id}/retry         → retry()          retry failed post
 *   DELETE /social-posts/{id}             → destroy()        delete post record
 *
 *   GET  /social-posts/stats              → stats()          dashboard counts
 */
class SocialPostAdminController extends Controller
{
    public function __construct(private SocialPostService $service) {}

    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // ── GET /api/v1/admin/social-posts ────────────────────────────────────
    // Paginated list with filters: platform, status, date range, article_id
    public function index(Request $request): JsonResponse
    {
        $query = SocialPost::with(['socialAccount', 'article.translations', 'createdBy'])
            ->where('channel_id', $this->channelId())
            ->withTrashed(false);

        // Filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('social_account_id')) {
            $query->where('social_account_id', $request->social_account_id);
        }
        if ($request->filled('article_id')) {
            $query->where('article_id', $request->article_id);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Sort: newest first by default
        $query->orderByDesc('created_at');

        $perPage = min((int) $request->get('per_page', 20), 100);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => $paged->map(fn($p) => $this->postRow($p)),
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

    // ── GET /api/v1/admin/social-posts/stats ─────────────────────────────
    // Dashboard counts for the Social Hub overview widget
    public function stats(): JsonResponse
    {
        $channelId = $this->channelId();

        $counts = SocialPost::where('channel_id', $channelId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'published'  THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = 'queued'     THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'scheduled'  AND post_type = 'scheduled' AND scheduled_at > NOW() THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'publishing' THEN 1 ELSE 0 END) as publishing
            ")
            ->first();

        // Posts published in the last 7 days per platform
        $byPlatform = SocialPost::where('channel_id', $channelId)
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays(7))
            ->selectRaw('platform, COUNT(*) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform');

        return response()->json([
            'counts'      => $counts,
            'by_platform' => $byPlatform,
        ]);
    }

    // ── GET /api/v1/admin/social-posts/{id} ──────────────────────────────
    public function show(int $id): JsonResponse
    {
        $post = SocialPost::with(['socialAccount', 'article.translations', 'createdBy', 'tiktokStatus'])
            ->where('channel_id', $this->channelId())
            ->findOrFail($id);

        return response()->json(['data' => $this->postRow($post, detailed: true)]);
    }

    // ── POST /api/v1/admin/social-posts ──────────────────────────────────
    // Create a manual post (not from an article)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_ids'              => ['required', 'array', 'min:1'],
            'account_ids.*'            => ['integer', 'exists:social_accounts,id'],
            'text'                     => ['required_without:media_url', 'nullable', 'string'],
            'link_url'                 => ['nullable', 'url', 'max:500'],
            'media_url'                => ['nullable', 'url', 'max:500'],
            'scheduled_at'             => ['nullable', 'date', 'after:now'],
            'platform_options'         => ['nullable', 'array'],
            // YouTube-specific (required if any account is YouTube)
            'platform_options.youtube.title'          => ['nullable', 'string', 'max:100'],
            'platform_options.youtube.privacy_status' => ['nullable', 'in:public,private,unlisted'],
            // TikTok-specific
            'platform_options.tiktok.privacy_level'   => ['nullable', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,SELF_ONLY'],
            'platform_options.tiktok.disable_duet'    => ['nullable', 'boolean'],
            'platform_options.tiktok.disable_comment' => ['nullable', 'boolean'],
            'platform_options.tiktok.disable_stitch'  => ['nullable', 'boolean'],
            // Instagram-specific
            'platform_options.instagram.media_type'   => ['nullable', 'in:IMAGE,REELS'],
        ]);

        try {
            $posts = $this->service->createManual($validated, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => count($posts) . ' post(s) created successfully.',
            'data'    => array_map(fn($p) => $this->postRow($p), $posts),
        ], 201);
    }

    // ── POST /api/v1/admin/social-posts/from-article/{id} ────────────────
    // Auto-generate and dispatch posts from a published article
    public function fromArticle(Request $request, int $articleId): JsonResponse
    {
        $article = Article::where('channel_id', $this->channelId())
            ->findOrFail($articleId);

        $validated = $request->validate([
            'account_ids'      => ['required', 'array', 'min:1'],
            'account_ids.*'    => ['integer', 'exists:social_accounts,id'],
            'scheduled_at'     => ['nullable', 'date', 'after:now'],
            'platform_options' => ['nullable', 'array'],
            // Per-platform text overrides
            'platform_options.facebook.text'          => ['nullable', 'string'],
            'platform_options.instagram.text'         => ['nullable', 'string'],
            'platform_options.twitter.text'           => ['nullable', 'string', 'max:280'],
            'platform_options.tiktok.title'           => ['nullable', 'string', 'max:2200'],
            'platform_options.youtube.title'          => ['nullable', 'string', 'max:100'],
            'platform_options.youtube.description'    => ['nullable', 'string', 'max:5000'],
            'platform_options.youtube.tags'           => ['nullable', 'array'],
            'platform_options.youtube.tags.*'         => ['string', 'max:100'],
            'platform_options.youtube.privacy_status' => ['nullable', 'in:public,private,unlisted'],
            'platform_options.youtube.media_url'      => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $posts = $this->service->createFromArticle(
                article:          $article,
                accountIds:       $validated['account_ids'],
                options:          $validated['platform_options'] ?? [],
                scheduledAt:      $validated['scheduled_at'] ?? null,
                createdByUserId:  $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Article shared to ' . count($posts) . ' platform(s).',
            'data'    => array_map(fn($p) => $this->postRow($p), $posts),
        ], 201);
    }

    // ── POST /api/v1/admin/social-posts/{id}/cancel ───────────────────────
    public function cancel(int $id): JsonResponse
    {
        $post = SocialPost::where('channel_id', $this->channelId())->findOrFail($id);

        try {
            $this->service->cancel($post);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Post cancelled.']);
    }

    // ── POST /api/v1/admin/social-posts/{id}/retry ────────────────────────
    public function retry(int $id): JsonResponse
    {
        $post = SocialPost::where('channel_id', $this->channelId())->findOrFail($id);

        try {
            $this->service->retry($post);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Post queued for retry.']);
    }

    // ── DELETE /api/v1/admin/social-posts/{id} ────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $post = SocialPost::where('channel_id', $this->channelId())->findOrFail($id);

        if (in_array($post->status, ['queued', 'publishing'])) {
            return response()->json([
                'error' => 'Cannot delete a post that is queued or currently publishing. Cancel it first.',
            ], 422);
        }

        $post->delete();

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function postRow(SocialPost $post, bool $detailed = false): array
    {
        $base = [
            'id'                 => $post->id,
            'platform'           => $post->platform,
            'status'             => $post->status,
            'post_type'          => $post->post_type,
            'content_text'       => $post->content_text,
            'link_url'           => $post->link_url,
            'media_public_url'   => $post->media_public_url,
            'scheduled_at'       => $post->scheduled_at?->toIso8601String(),
            'published_at'       => $post->published_at?->toIso8601String(),
            'platform_post_id'   => $post->platform_post_id,
            'platform_post_url'  => $post->platform_post_url,
            'attempt_count'      => $post->attempt_count,
            'max_attempts'       => $post->max_attempts,
            'error_message'      => $post->error_message,
            'retry_after'        => $post->retry_after?->toIso8601String(),
            'can_retry'          => $post->canRetry(),
            'created_at'         => $post->created_at?->toIso8601String(),
            // Related
            'account' => $post->socialAccount ? [
                'id'           => $post->socialAccount->id,
                'platform'     => $post->socialAccount->platform,
                'account_name' => $post->socialAccount->account_name,
                'picture_url'  => $post->socialAccount->profile_picture_url,
            ] : null,
            'article' => $post->article ? [
                'id'    => $post->article->id,
                'slug'  => $post->article->slug,
                'title' => $post->article->translations->first()?->title,
            ] : null,
            'created_by' => $post->createdBy?->display_name,
        ];

        if ($detailed) {
            $base['platform_options'] = $post->platform_options;
            $base['error_data']       = $post->error_data;
            $base['queue_job_id']     = $post->queue_job_id;
            if ($post->platform === 'tiktok' && $post->tiktokStatus) {
                $base['tiktok_status'] = [
                    'publish_id'    => $post->tiktokStatus->publish_id,
                    'status'        => $post->tiktokStatus->tiktok_status,
                    'fail_reason'   => $post->tiktokStatus->fail_reason,
                    'poll_count'    => $post->tiktokStatus->poll_count,
                    'next_poll_at'  => $post->tiktokStatus->next_poll_at?->toIso8601String(),
                ];
            }
        }

        return $base;
    }
}
