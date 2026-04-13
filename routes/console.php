<?php

use Illuminate\Support\Facades\Schedule;

// ── Import RSS news feeds every 30 minutes ────────────────────────────────
Schedule::command('cni:import-rss')->everyThirtyMinutes();

// ── Flush cached article view counts to DB every 5 minutes ────────────────
Schedule::command('cni:flush-view-counts')->everyFiveMinutes();

// ── Publish scheduled articles ─────────────────────────────────────────────
Schedule::call(function () {
    \App\Models\Article::where('status', 'scheduled')
        ->where('scheduled_at', '<=', now())
        ->update([
            'status'       => 'published',
            'published_at' => now(),
        ]);
})->everyMinute()->name('publish-scheduled-articles');

// ── Expire membership plans ────────────────────────────────────────────────
Schedule::call(function () {
    \App\Models\Membership::where('status', 'active')
        ->whereNotNull('end_date')
        ->where('end_date', '<', now()->toDateString())
        ->where('auto_renew', false)
        ->update(['status' => 'expired']);
})->daily()->name('expire-memberships');

// ── Expire promo codes ─────────────────────────────────────────────────────
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('promo_codes')
        ->where('is_active', true)
        ->where('valid_until', '<', now()->toDateString())
        ->update(['is_active' => false]);
})->daily()->name('expire-promo-codes');

// ── Social feed ingest (pull from Facebook / Instagram — YouTube uses RSS on frontend) ──
Schedule::command('social:ingest')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// ── Prune social feed items — keep only latest 30 per platform ──────────────
Schedule::call(function () {
    $platforms = \Illuminate\Support\Facades\DB::table('social_feed_items')
        ->select('platform')
        ->distinct()
        ->pluck('platform');

    foreach ($platforms as $platform) {
        $keepIds = \Illuminate\Support\Facades\DB::table('social_feed_items')
            ->where('platform', $platform)
            ->orderByDesc('posted_at')
            ->limit(30)
            ->pluck('id');

        if ($keepIds->isNotEmpty()) {
            \Illuminate\Support\Facades\DB::table('social_feed_items')
                ->where('platform', $platform)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }
})->daily()->name('prune-social-feed-items');

// ── Prune RSS bot articles older than 7 days that were never published ────────
Schedule::call(function () {
    $botUserId = \Illuminate\Support\Facades\DB::table('users')
        ->where('email', 'rss-bot@cninews.tv')
        ->value('id');

    if (!$botUserId) return;

    $old = \Illuminate\Support\Facades\DB::table('articles')
        ->where('author_user_id', $botUserId)
        ->whereIn('status', ['draft', 'pending_review'])
        ->where('created_at', '<', now()->subDays(7))
        ->pluck('id');

    if ($old->isNotEmpty()) {
        \Illuminate\Support\Facades\DB::table('article_translations')->whereIn('article_id', $old)->delete();
        \Illuminate\Support\Facades\DB::table('article_tag_map')->whereIn('article_id', $old)->delete();
        \Illuminate\Support\Facades\DB::table('articles')->whereIn('id', $old)->delete();
    }
})->daily()->name('prune-rss-articles');

// ── Social post queue processor (publish scheduled posts to platforms) ───────
Schedule::command('social:process-queue')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// ── TikTok async status poller ────────────────────────────────────────────────
Schedule::command('social:poll-tiktok')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
