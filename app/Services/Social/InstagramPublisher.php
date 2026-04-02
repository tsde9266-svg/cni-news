<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * InstagramPublisher
 *
 * Posts images and Reels to an Instagram Business/Creator account
 * via the Instagram Graph API (graph.instagram.com).
 *
 * MANDATORY two-step flow — cannot be skipped:
 *
 *   Step 1 — Create media container:
 *     POST /{ig_user_id}/media
 *       For IMAGE: image_url + caption
 *       For REELS: video_url + caption + media_type=REELS
 *     → returns container_id
 *
 *   Step 2 — Poll container status until FINISHED:
 *     GET /{container_id}?fields=status_code
 *     Values: EXPIRED | ERROR | FINISHED | IN_PROGRESS | PUBLISHED
 *     → only call media_publish once status_code = FINISHED
 *
 *   Step 3 — Publish:
 *     POST /{ig_user_id}/media_publish
 *       creation_id = container_id
 *     → returns media_id (the published Instagram post ID)
 *
 * Critical constraints (from research):
 *   - Image must be JPEG only (not PNG, WebP, etc.)
 *   - Image ratio between 4:5 and 1.91:1
 *   - Reels: MP4/H.264, max 100MB, 3 sec–15 min
 *   - Max 25 API-published posts per account per 24 hours
 *   - Media URL MUST be publicly accessible at time of API call
 *   - Account MUST be Business or Creator type (not personal)
 *   - Account MUST be linked to a Facebook Page
 *
 * Token: Same never-expiring Page Access Token as Facebook.
 * The IG user ID (numeric) is stored in platform_account_id.
 */
class InstagramPublisher
{
    private string $graphBase = 'https://graph.instagram.com/v22.0';

    // How long to wait between status polls (seconds)
    private const POLL_INTERVAL_SECONDS = 5;

    // Maximum polling attempts before giving up (~2.5 minutes total)
    private const MAX_POLL_ATTEMPTS = 30;

    /**
     * Publish a SocialPost to Instagram.
     * Returns the Instagram media_id on success.
     *
     * @throws SocialPublishException
     */
    public function publish(SocialPost $post): string
    {
        $account  = $post->socialAccount;
        $token    = $account->getAccessToken();
        $igUserId = $account->platform_account_id;

        // Route to image or reel based on post options or media type
        $mediaType = $post->getOption('media_type', 'IMAGE');

        if (strtoupper($mediaType) === 'REELS') {
            return $this->publishReel($post, $igUserId, $token);
        }

        return $this->publishImage($post, $igUserId, $token);
    }

    // ── Image post ────────────────────────────────────────────────────────

    private function publishImage(SocialPost $post, string $igUserId, string $token): string
    {
        $imageUrl = $this->resolveMediaUrl($post);

        // Step 1: Create container
        $containerId = $this->createContainer($igUserId, $token, [
            'image_url'  => $imageUrl,
            'caption'    => $post->content_text ?? '',
            'media_type' => 'IMAGE',
        ]);

        // Step 2: Poll until FINISHED
        $this->waitUntilFinished($containerId, $token);

        // Step 3: Publish
        return $this->publishContainer($igUserId, $token, $containerId);
    }

    // ── Reel post ─────────────────────────────────────────────────────────

    private function publishReel(SocialPost $post, string $igUserId, string $token): string
    {
        $videoUrl = $this->resolveMediaUrl($post);

        // Step 1: Create container
        $containerId = $this->createContainer($igUserId, $token, [
            'video_url'          => $videoUrl,
            'caption'            => $post->content_text ?? '',
            'media_type'         => 'REELS',
            'share_to_feed'      => $post->getOption('share_to_feed', true),
        ]);

        // Step 2: Poll until FINISHED (videos take longer — up to 2 minutes)
        $this->waitUntilFinished($containerId, $token, maxAttempts: self::MAX_POLL_ATTEMPTS);

        // Step 3: Publish
        return $this->publishContainer($igUserId, $token, $containerId);
    }

    // ── Step 1: Create container ──────────────────────────────────────────

    private function createContainer(string $igUserId, string $token, array $params): string
    {
        $response = Http::timeout(30)
            ->post("{$this->graphBase}/{$igUserId}/media", array_merge(
                $params,
                ['access_token' => $token]
            ));

        $data = $response->json();

        if (isset($data['error'])) {
            $this->throwFromApiError($data['error']);
        }

        if (empty($data['id'])) {
            throw new SocialPublishException(
                'Instagram container creation returned no ID.',
                retryable: true
            );
        }

        return $data['id'];
    }

    // ── Step 2: Poll status ───────────────────────────────────────────────

    /**
     * Poll the container status until it reaches FINISHED.
     * Instagram processes images quickly (~2s) but videos can take 30–120s.
     *
     * Status values:
     *   EXPIRED     — container expired (older than 24hrs), create a new one
     *   ERROR       — processing failed, fatal
     *   FINISHED    — ready to publish
     *   IN_PROGRESS — still processing, keep polling
     *   PUBLISHED   — already published (shouldn't happen here)
     *
     * @throws SocialPublishException
     */
    private function waitUntilFinished(
        string $containerId,
        string $token,
        int    $maxAttempts = 12 // 12 × 5s = 60s default for images
    ): void {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }

            $response = Http::timeout(15)
                ->get("{$this->graphBase}/{$containerId}", [
                    'fields'       => 'status_code,status',
                    'access_token' => $token,
                ])->json();

            if (isset($response['error'])) {
                $this->throwFromApiError($response['error']);
            }

            $statusCode = $response['status_code'] ?? 'IN_PROGRESS';

            match ($statusCode) {
                'FINISHED'    => null, // ready — break out of loop below
                'IN_PROGRESS' => null, // keep polling
                'ERROR'       => throw new SocialPublishException(
                    'Instagram media processing failed (ERROR status). ' .
                    'Check: JPEG format for images, H.264 MP4 for videos, correct aspect ratio.',
                    retryable: false,
                    errorData: ['status' => $response['status'] ?? 'unknown']
                ),
                'EXPIRED'     => throw new SocialPublishException(
                    'Instagram media container expired. Re-create the post.',
                    retryable: true // retryable — job will start fresh
                ),
                default       => null,
            };

            if ($statusCode === 'FINISHED') {
                return;
            }
        }

        // Timed out polling
        throw new SocialPublishException(
            "Instagram media still processing after {$maxAttempts} polls (" .
            ($maxAttempts * self::POLL_INTERVAL_SECONDS) . "s). " .
            "Will retry — large videos can take up to 2 minutes.",
            retryable: true
        );
    }

    // ── Step 3: Publish container ─────────────────────────────────────────

    private function publishContainer(string $igUserId, string $token, string $containerId): string
    {
        $response = Http::timeout(30)
            ->post("{$this->graphBase}/{$igUserId}/media_publish", [
                'creation_id'  => $containerId,
                'access_token' => $token,
            ])->json();

        if (isset($response['error'])) {
            $this->throwFromApiError($response['error']);
        }

        if (empty($response['id'])) {
            throw new SocialPublishException(
                'Instagram media_publish returned no media ID.',
                retryable: false
            );
        }

        return $response['id'];
    }

    // ── Error handling ────────────────────────────────────────────────────

    private function throwFromApiError(array $error): never
    {
        $code    = (int) ($error['code'] ?? 0);
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = $error['message'] ?? 'Unknown Instagram error';

        Log::warning('Instagram API error', compact('code', 'subcode', 'message'));

        // Token / auth errors — same codes as Facebook (same token)
        if ($code === 190 || $code === 200) {
            throw new SocialPublishException(
                "Instagram auth error [{$code}]: {$message}",
                retryable: false,
                requiresReconnect: true,
                errorData: compact('code', 'subcode', 'message')
            );
        }

        // Publishing rate limit (25 posts/day)
        if (str_contains($message, 'PUBLISHED_POSTS_LIMIT_REACHED') || $code === 9007) {
            throw new SocialPublishException(
                'Instagram 25-posts-per-day limit reached. Try again tomorrow.',
                retryable: false,
                errorData: compact('code', 'message')
            );
        }

        // Media not accessible — image/video URL unreachable
        if ($code === 9 || str_contains(strtolower($message), 'not accessible')) {
            throw new SocialPublishException(
                'Instagram cannot access your media URL. Ensure it is publicly reachable.',
                retryable: false,
                errorData: compact('code', 'message')
            );
        }

        // 5xx transient
        if ($code >= 500) {
            throw new SocialPublishException(
                "Instagram server error [{$code}]: {$message}",
                retryable: true,
                errorData: compact('code', 'message')
            );
        }

        throw new SocialPublishException(
            "Instagram error [{$code}]: {$message}",
            retryable: false,
            errorData: compact('code', 'subcode', 'message')
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function resolveMediaUrl(SocialPost $post): string
    {
        if ($post->media_public_url) {
            return $post->media_public_url;
        }

        $asset = $post->mediaAsset;
        if (!$asset) {
            throw new SocialPublishException(
                'No media URL available for Instagram post. Instagram requires an image or video.',
                retryable: false
            );
        }

        if ($asset->original_url) {
            return $asset->original_url;
        }

        return Storage::disk($asset->disk ?? 'public')->url($asset->internal_path);
    }
}
