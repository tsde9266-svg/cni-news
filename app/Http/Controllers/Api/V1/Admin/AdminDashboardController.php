<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/dashboard
 *
 * Returns all data the Next.js dashboard needs in a single request:
 *  - stats overview (counts, today's views)
 *  - recent articles (last 10)
 *  - pending review queue
 *  - top articles by views (this week)
 */
class AdminDashboardController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/dashboard
    public function index(): JsonResponse
    {
        $channelId = $this->channelId();

        // ── Counts ──────────────────────────────────────────────────────────
        $totalArticles   = Article::where('channel_id', $channelId)->count();
        $pendingArticles = Article::where('channel_id', $channelId)->where('status', 'pending_review')->count();
        $publishedToday  = Article::where('channel_id', $channelId)
            ->where('status', 'published')
            ->whereDate('published_at', today())
            ->count();
        $scheduledCount  = Article::where('channel_id', $channelId)->where('status', 'scheduled')->count();

        $totalUsers    = DB::table('users')->where('channel_id', $channelId)->whereNull('deleted_at')->count();
        $activeMembers = DB::table('memberships')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->where('users.channel_id', $channelId)
            ->where('memberships.status', 'active')
            ->count();

        $pendingComments = DB::table('comments')
            ->join('articles', 'comments.article_id', '=', 'articles.id')
            ->where('articles.channel_id', $channelId)
            ->where('comments.status', 'pending')
            ->whereNull('comments.deleted_at')
            ->count();

        $viewsToday = (int) Article::where('channel_id', $channelId)
            ->whereDate('updated_at', today())
            ->sum('view_count');

        $viewsThisWeek = (int) Article::where('channel_id', $channelId)
            ->where('published_at', '>=', now()->startOfWeek())
            ->sum('view_count');

        $liveStreams = DB::table('live_streams')
            ->where('channel_id', $channelId)
            ->where('status', 'live')
            ->count();

        // ── Revenue this month ───────────────────────────────────────────────
        $revenueThisMonth = (float) DB::table('payments')
            ->join('memberships', 'payments.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->where('users.channel_id', $channelId)
            ->where('payments.status', 'succeeded')
            ->whereYear('payments.paid_at', now()->year)
            ->whereMonth('payments.paid_at', now()->month)
            ->sum('payments.amount_paid');

        // ── Recent articles (last 10 across all statuses) ────────────────────
        $recentArticles = Article::with([
            'translations' => fn($q) => $q->where('language_id', 1)->select('article_id','title'),
            'author:id,display_name',
            'mainCategory:id,default_name,slug',
        ])
        ->where('channel_id', $channelId)
        ->orderByDesc('updated_at')
        ->limit(10)
        ->get()
        ->map(fn($a) => [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'title'        => $a->translations->first()?->title ?? '—',
            'status'       => $a->status,
            'type'         => $a->type,
            'is_breaking'  => $a->is_breaking,
            'is_featured'  => $a->is_featured,
            'view_count'   => $a->view_count,
            'published_at' => $a->published_at?->toISOString(),
            'updated_at'   => $a->updated_at->toISOString(),
            'author_name'  => $a->author?->display_name,
            'category_name'=> $a->mainCategory?->default_name,
        ]);

        // ── Pending review queue ─────────────────────────────────────────────
        $pendingQueue = Article::with([
            'translations' => fn($q) => $q->where('language_id', 1)->select('article_id','title'),
            'author:id,display_name',
        ])
        ->where('channel_id', $channelId)
        ->where('status', 'pending_review')
        ->orderByDesc('created_at')
        ->limit(8)
        ->get()
        ->map(fn($a) => [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'title'        => $a->translations->first()?->title ?? '—',
            'author_name'  => $a->author?->display_name,
            'word_count'   => $a->word_count,
            'created_at'   => $a->created_at->toISOString(),
        ]);

        // ── Top articles this week ────────────────────────────────────────────
        $topArticles = Article::with([
            'translations' => fn($q) => $q->where('language_id', 1)->select('article_id','title'),
        ])
        ->where('channel_id', $channelId)
        ->where('status', 'published')
        ->where('published_at', '>=', now()->subDays(7))
        ->orderByDesc('view_count')
        ->limit(5)
        ->get()
        ->map(fn($a) => [
            'id'         => $a->id,
            'slug'       => $a->slug,
            'title'      => $a->translations->first()?->title ?? '—',
            'view_count' => $a->view_count,
            'is_breaking'=> $a->is_breaking,
        ]);

        // ── Activity by day (last 7 days published count) ────────────────────
        $activityByDay = Article::where('channel_id', $channelId)
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(published_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        // Fill in missing days with 0
        $activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $activity[] = [
                'date'  => $d,
                'label' => now()->subDays($i)->format('D'),
                'count' => (int) ($activityByDay[$d] ?? 0),
            ];
        }

        return response()->json([
            'stats' => [
                'articles_total'      => $totalArticles,
                'articles_pending'    => $pendingArticles,
                'articles_today'      => $publishedToday,
                'articles_scheduled'  => $scheduledCount,
                'users_total'         => $totalUsers,
                'members_active'      => $activeMembers,
                'comments_pending'    => $pendingComments,
                'views_today'         => $viewsToday,
                'views_this_week'     => $viewsThisWeek,
                'revenue_this_month'  => round($revenueThisMonth, 2),
                'live_streams_active' => $liveStreams,
            ],
            'recent_articles'  => $recentArticles,
            'pending_queue'    => $pendingQueue,
            'top_articles'     => $topArticles,
            'activity_by_day'  => $activity,
        ]);
    }
}
