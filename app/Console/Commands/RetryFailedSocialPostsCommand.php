<?php

namespace App\Console\Commands;

use App\Jobs\SocialPublishJob;
use App\Models\SocialPost;
use Illuminate\Console\Command;

/**
 * RetryFailedSocialPostsCommand
 *
 * Runs every minute via the scheduler.
 * Finds social_posts that are:
 *   - status = 'failed'
 *   - attempt_count < max_attempts
 *   - retry_after <= now()
 *
 * Also handles scheduled posts whose scheduled_at has arrived:
 *   - status = 'queued'
 *   - scheduled_at <= now()
 *
 * This command is the heartbeat of the social hub scheduler.
 * It replaces complex Laravel job chaining with a simple DB poll.
 */
class RetryFailedSocialPostsCommand extends Command
{
    protected $signature   = 'social:process-queue';
    protected $description = 'Dispatch pending and retryable social posts to the publish queue.';

    public function handle(): void
    {
        // 1. Re-queue retryable failed posts whose backoff has elapsed
        $retryable = SocialPost::retryable()->get();

        foreach ($retryable as $post) {
            $post->update(['status' => 'queued', 'retry_after' => null]);
            SocialPublishJob::dispatch($post->id);
            $this->line("  ↺ Re-queued post #{$post->id} ({$post->platform})");
        }

        // 2. Queue immediate posts that were staged as 'queued' but not yet dispatched
        // (covers server restarts where the job was lost from the in-memory queue)
        $pending = SocialPost::due()
            ->whereNull('queue_job_id')
            ->limit(50)
            ->get();

        foreach ($pending as $post) {
            SocialPublishJob::dispatch($post->id);
            $this->line("  → Dispatched post #{$post->id} ({$post->platform})");
        }

        $total = $retryable->count() + $pending->count();
        if ($total > 0) {
            $this->info("social:process-queue: dispatched {$total} post(s).");
        }
    }
}
