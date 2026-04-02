<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\AssignImagesToArticles::class,
        \App\Console\Commands\RetryFailedSocialPostsCommand::class,
        \App\Console\Commands\PollTikTokStatusCommand::class,
        \App\Console\Commands\SocialIngestCommand::class,
    ];

    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
    {
        // ── Social queue processor ────────────────────────────────────────
        // Dispatches scheduled and retryable social posts every minute.
        $schedule->command('social:process-queue')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // ── TikTok async status poller ────────────────────────────────────
        // Polls pending TikTok uploads for PUBLISH_COMPLETE / FAILED status.
        $schedule->command('social:poll-tiktok')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // ── Social feed ingest ────────────────────────────────────────────
        // Pulls latest posts from YouTube, Facebook, Instagram into feed.
        // Every 30 minutes — generous cache, avoids hitting rate limits.
        $schedule->command('social:ingest')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
    }
}
