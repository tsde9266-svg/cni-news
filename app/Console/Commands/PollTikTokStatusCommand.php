<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\TiktokPublishStatus;
use App\Services\Social\SocialPublishException;
use App\Services\Social\TikTokPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PollTikTokStatusCommand
 *
 * Runs every minute via the scheduler.
 * Polls pending TikTok uploads for their completion status.
 *
 * TikTok's async pipeline stages:
 *   CREATED          → init accepted, video not yet on TikTok servers
 *   PROCESSING       → TikTok is transcoding / moderating
 *   PUBLISH_COMPLETE → live on TikTok (post_id now available)
 *   FAILED           → processing failed (fail_reason available)
 *
 * Polling strategy (exponential backoff):
 *   Poll 1:  30 seconds after init
 *   Poll 2:  1 minute after poll 1
 *   Poll 3:  2 minutes after poll 2
 *   Poll 4+: 4 minutes (capped)
 *   Abandon: 15 minutes after init (TikTok's documented max processing time)
 */
class PollTikTokStatusCommand extends Command
{
    protected $signature   = 'social:poll-tiktok';
    protected $description = 'Poll TikTok for pending video publish status updates.';

    public function handle(): void
    {
        $publisher = new TikTokPublisher();

        // Find all status rows that are due for a poll and not yet abandoned
        $pending = TiktokPublishStatus::whereIn('tiktok_status', ['CREATED', 'PROCESSING'])
            ->where(fn($q) =>
                $q->whereNull('next_poll_at')
                  ->orWhere('next_poll_at', '<=', now())
            )
            ->where(fn($q) =>
                $q->whereNull('abandon_after')
                  ->orWhere('abandon_after', '>', now())
            )
            ->with(['socialPost.socialAccount'])
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $this->line("social:poll-tiktok: checking {$pending->count()} pending upload(s)...");

        foreach ($pending as $statusRow) {
            $post = $statusRow->socialPost;

            if (!$post || !$post->socialAccount) {
                $statusRow->update(['tiktok_status' => 'FAILED', 'fail_reason' => 'Social post or account deleted']);
                continue;
            }

            try {
                $status = $publisher->pollStatus($statusRow);

                $statusRow->update(['tiktok_status' => $status]);

                match ($status) {
                    'PUBLISH_COMPLETE' => $this->handleComplete($statusRow, $post),
                    'FAILED'           => $this->handleFailed($statusRow, $post),
                    default            => $statusRow->scheduleNextPoll(), // still processing
                };

                $this->line("  #{$post->id} ({$statusRow->publish_id}): {$status}");

            } catch (SocialPublishException $e) {
                Log::warning("TikTok poll error for post {$post->id}", ['error' => $e->getMessage()]);

                // If retryable, schedule next poll; otherwise abandon
                if ($e->retryable) {
                    $statusRow->scheduleNextPoll();
                } else {
                    $this->handleFailed($statusRow, $post, $e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("Unexpected error polling TikTok status for post {$post->id}", [
                    'error' => $e->getMessage(),
                ]);
                $statusRow->scheduleNextPoll();
            }
        }

        // Clean up abandoned rows older than 15 minutes
        TiktokPublishStatus::where('abandon_after', '<', now())
            ->whereIn('tiktok_status', ['CREATED', 'PROCESSING'])
            ->each(function ($row) {
                $row->update(['tiktok_status' => 'FAILED', 'fail_reason' => 'Abandoned: exceeded 15-minute timeout']);
                $row->socialPost?->markFailed(
                    'TikTok publish timed out after 15 minutes.',
                    ['reason' => 'timeout']
                );
                $this->line("  ✗ Abandoned post #{$row->social_post_id} (timeout)");
            });
    }

    private function handleComplete(TiktokPublishStatus $statusRow, SocialPost $post): void
    {
        // TikTok does not return the public post_id until after moderation.
        // We use the publish_id as the platform identifier for now.
        // When moderation completes (can take minutes to hours for public posts),
        // the post_id becomes available via the status endpoint.
        $postUrl = $statusRow->tiktok_post_id
            ? "https://www.tiktok.com/@{$post->socialAccount->platform_username}/video/{$statusRow->tiktok_post_id}"
            : null;

        $post->markPublished(
            $statusRow->tiktok_post_id ?? $statusRow->publish_id,
            $postUrl
        );

        Log::info("TikTok post published", [
            'post_id'    => $post->id,
            'publish_id' => $statusRow->publish_id,
            'tiktok_id'  => $statusRow->tiktok_post_id,
        ]);
    }

    private function handleFailed(
        TiktokPublishStatus $statusRow,
        SocialPost $post,
        ?string $overrideMessage = null
    ): void {
        $reason = $overrideMessage
            ?? $statusRow->fail_reason
            ?? 'TikTok video processing failed.';

        $post->markFailed($reason, [
            'publish_id'  => $statusRow->publish_id,
            'fail_reason' => $statusRow->fail_reason,
        ]);

        Log::warning("TikTok post failed", [
            'post_id'    => $post->id,
            'publish_id' => $statusRow->publish_id,
            'reason'     => $reason,
        ]);
    }
}
