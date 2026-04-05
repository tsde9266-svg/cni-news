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

// ── Social feed ingest (pull from Facebook / YouTube / Instagram) ───────────
Schedule::command('social:ingest')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

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
