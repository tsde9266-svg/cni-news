<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\SocialPost;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\SocialPublishException;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\TwitterPublisher;
use App\Services\Social\YouTubePublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SocialPublishJob
 *
 * Queued job that publishes a single SocialPost to its target platform.
 * Dispatched by SocialPostService (Task 5) or the scheduler (for scheduled posts).
 *
 * Lifecycle:
 *   1. Job is dispatched → post.status = 'queued'
 *   2. Job runs          → post.status = 'publishing', attempt_count++
 *   3a. Success          → post.status = 'published', platform_post_id saved
 *   3b. Retryable fail   → post.status = 'failed', retry_after set, job re-queued
 *   3c. Permanent fail   → post.status = 'failed', no more retries
 *
 * Queue configuration:
 *   - Queue name: 'social'  (separate from default to not block other jobs)
 *   - Timeout: 300 seconds  (5 minutes, covers slow video uploads)
 *   - Max tries: 1          (we manage retries ourselves via retry_after)
 *
 * Why we manage retries manually instead of Laravel's built-in tries:
 *   - Different retry delays per platform and error type
 *   - Need to persist retry state across server restarts
 *   - Need to differentiate "retryable" from "permanent" failures
 *   - The retry_after column lets the scheduler query exactly when to re-queue
 */
class SocialPublishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Tell Laravel not to auto-retry — we manage it ourselves
    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        private readonly int $socialPostId
    ) {
        $this->onQueue('social');
    }

    public function handle(): void
    {
        $post = SocialPost::with(['socialAccount', 'mediaAsset'])
            ->find($this->socialPostId);

        // Post was deleted or cancelled while in queue
        if (!$post || $post->status === 'cancelled') {
            Log::info("SocialPublishJob: post {$this->socialPostId} not found or cancelled, skipping.");
            return;
        }

        // Already published (duplicate dispatch guard)
        if ($post->status === 'published') {
            Log::info("SocialPublishJob: post {$this->socialPostId} already published, skipping.");
            return;
        }

        // Account was disconnected or deactivated
        if (!$post->socialAccount || !$post->socialAccount->is_active) {
            $post->markFailed(
                'Social account is inactive or disconnected. Please reconnect it in the Social Hub.',
                ['reason' => 'account_inactive']
            );
            return;
        }

        // Mark as publishing (increments attempt_count)
        $post->markPublishing();

        try {
            $platformPostId = $this->dispatchToPublisher($post);

            // Build the post URL for each platform
            $postUrl = $this->buildPostUrl($post->platform, $platformPostId, $post->socialAccount->platform_account_id);

            $post->markPublished($platformPostId, $postUrl);

            // Update account last_used_at
            $post->socialAccount->update(['last_used_at' => now()]);

            AuditLog::log('social_post_published', 'social_post', $post->id, null, [
                'platform'         => $post->platform,
                'platform_post_id' => $platformPostId,
                'article_id'       => $post->article_id,
            ]);

            Log::info("SocialPublishJob: post {$post->id} published to {$post->platform} as {$platformPostId}");

        } catch (SocialPublishException $e) {

            Log::warning("SocialPublishJob: post {$post->id} failed", [
                'platform'   => $post->platform,
                'message'    => $e->getMessage(),
                'retryable'  => $e->retryable,
                'error_data' => $e->errorData,
            ]);

            $post->markFailed($e->getMessage(), $e->errorData);

            // If retryable and not exhausted, re-queue after the backoff delay
            if ($e->retryable && $post->canRetry()) {
                $delayMinutes = $e->retryDelayMinutes > 0
                    ? $e->retryDelayMinutes
                    : (int) pow(2, $post->attempt_count); // 2, 4, 8 minutes

                // Update retry_after so the scheduler knows when to re-queue
                $post->update(['retry_after' => now()->addMinutes($delayMinutes)]);

                Log::info("SocialPublishJob: post {$post->id} will retry in {$delayMinutes} minutes.");
            }

            // If account needs reconnecting, log it prominently
            if ($e->requiresReconnect) {
                Log::error("SocialPublishJob: social account {$post->social_account_id} requires reconnection.", [
                    'platform' => $post->platform,
                    'post_id'  => $post->id,
                ]);
            }

        } catch (\Throwable $e) {
            // Unexpected exception (network timeout, DB issue, etc.) — always retryable
            Log::error("SocialPublishJob: unexpected error on post {$post->id}", [
                'platform' => $post->platform,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            $post->markFailed(
                "Unexpected error: {$e->getMessage()}",
                ['exception' => get_class($e)]
            );

            // Re-queue with backoff if attempts remain
            if ($post->canRetry()) {
                $delayMinutes = (int) pow(2, $post->attempt_count);
                $post->update(['retry_after' => now()->addMinutes($delayMinutes)]);
            }
        }
    }

    // ── Route to the correct publisher ────────────────────────────────────

    private function dispatchToPublisher(SocialPost $post): string
    {
        return match ($post->platform) {
            'facebook'  => (new FacebookPublisher())->publish($post),
            'instagram' => $this->publishInstagram($post),
            'youtube'   => (new YouTubePublisher())->publish($post),
            'tiktok'    => $this->publishTikTok($post),
            'twitter'   => $this->publishTwitter($post),
            default     => throw new SocialPublishException(
                "No publisher implemented for platform: {$post->platform}",
                retryable: false
            ),
        };
    }

    // ── Stub dispatchers for platforms built in later tasks ───────────────
    // These will be replaced in Tasks 4 (TikTok) and 7 (Twitter).
    // They exist here so the job compiles and routes correctly now.

    private function publishInstagram(SocialPost $post): string
    {
        return (new InstagramPublisher())->publish($post);
    }

    private function publishTikTok(SocialPost $post): string
    {
        // Returns publish_id — final post_id comes via PollTikTokStatusCommand
        return (new TikTokPublisher())->publish($post);
    }

    private function publishTwitter(SocialPost $post): string
    {
        return (new TwitterPublisher())->publish($post);
    }

    // ── Build post URL ────────────────────────────────────────────────────

    private function buildPostUrl(string $platform, string $postId, string $accountId): ?string
    {
        return match ($platform) {
            'facebook'  => "https://www.facebook.com/{$postId}",
            'instagram' => "https://www.instagram.com/p/{$postId}/",
            'youtube'   => "https://www.youtube.com/watch?v={$postId}",
            'tiktok'    => null, // TikTok post_id not available until after moderation
            'twitter'   => "https://x.com/i/web/status/{$postId}",
            default     => null,
        };
    }
}
