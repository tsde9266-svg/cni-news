<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FacebookPublisher
 *
 * Posts content to a Facebook Page via the Graph API v22.0.
 *
 * Supports:
 *   - Text + link post  → POST /{page_id}/feed
 *   - Image post        → Step 1: POST /{page_id}/photos (unpublished)
 *                         Step 2: POST /{page_id}/feed with attached_media
 *   - Scheduled post    → same as above with published=false + scheduled_publish_time
 *
 * Token: Never-expiring Page Access Token stored in social_accounts table.
 *
 * Rate limits:
 *   - 4,800 calls per Page per 24 hours (Page-level, generous for a news site)
 *   - Scheduling: published_at must be 10 minutes → 6 months in the future
 *
 * Error handling strategy:
 *   - Code 190 (any subcode): token invalidated → mark account inactive, no retry
 *   - Code 200: permission error → mark account inactive, no retry
 *   - Code 32: rate limit → retry after 1 hour
 *   - Code 368: throttled → retry after 3 hours
 *   - Code 2 (transient): retry with backoff
 *   - HTTP 5xx: retry with backoff
 */
class FacebookPublisher
{
    private string $graphBase = 'https://graph.facebook.com/v22.0';

    /**
     * Publish a SocialPost to Facebook.
     * Returns the platform post ID on success.
     *
     * @throws \App\Services\Social\SocialPublishException
     */
    public function publish(SocialPost $post): string
    {
        $account  = $post->socialAccount;
        $token    = $account->getAccessToken();
        $pageId   = $account->platform_account_id;

        // Determine post type and route to the right method
        $hasMedia = !empty($post->media_public_url) || !empty($post->media_asset_id);

        if ($hasMedia) {
            return $this->publishWithImage($post, $pageId, $token);
        }

        return $this->publishTextPost($post, $pageId, $token);
    }

    // ── Text / link post ──────────────────────────────────────────────────

    private function publishTextPost(SocialPost $post, string $pageId, string $token): string
    {
        $params = [
            'access_token' => $token,
            'message'      => $post->content_text,
        ];

        // Add link if present — Facebook auto-generates a preview card
        if ($post->link_url) {
            $params['link'] = $post->link_url;
        }

        // Scheduling
        if ($post->post_type === 'scheduled' && $post->scheduled_at) {
            $params['published']              = 'false';
            $params['scheduled_publish_time'] = $post->scheduled_at->timestamp;
        }

        $response = Http::timeout(30)
            ->post("{$this->graphBase}/{$pageId}/feed", $params);

        return $this->handleResponse($response, $post->social_account_id);
    }

    // ── Image post (two-step) ─────────────────────────────────────────────

    private function publishWithImage(SocialPost $post, string $pageId, string $token): string
    {
        $imageUrl = $this->resolveImageUrl($post);

        // Step 1: Upload photo as unpublished (staged attachment)
        $photoResponse = Http::timeout(60)
            ->post("{$this->graphBase}/{$pageId}/photos", [
                'access_token' => $token,
                'url'          => $imageUrl,
                'published'    => 'false', // always false — we publish via feed
            ]);

        $photoData = $photoResponse->json();

        if (isset($photoData['error'])) {
            $this->throwFromApiError($photoData['error'], $post->social_account_id);
        }

        $photoId = $photoData['id'];

        // Step 2: Create feed post with attached photo
        $params = [
            'access_token'               => $token,
            'message'                    => $post->content_text ?? '',
            'attached_media[0]'          => json_encode(['media_fbid' => $photoId]),
        ];

        if ($post->link_url) {
            $params['link'] = $post->link_url;
        }

        if ($post->post_type === 'scheduled' && $post->scheduled_at) {
            $params['published']              = 'false';
            $params['scheduled_publish_time'] = $post->scheduled_at->timestamp;
        }

        $response = Http::timeout(30)
            ->post("{$this->graphBase}/{$pageId}/feed", $params);

        return $this->handleResponse($response, $post->social_account_id);
    }

    // ── Response handling ─────────────────────────────────────────────────

    /**
     * Parse the API response and return the post ID, or throw.
     * @throws SocialPublishException
     */
    private function handleResponse(\Illuminate\Http\Client\Response $response, int $accountId): string
    {
        $data = $response->json();

        if (isset($data['error'])) {
            $this->throwFromApiError($data['error'], $accountId);
        }

        if (!isset($data['id'])) {
            throw new SocialPublishException(
                'Facebook API returned success but no post ID.',
                retryable: false
            );
        }

        return $data['id'];
    }

    /**
     * Convert a Facebook API error into a typed SocialPublishException.
     * Sets retryable=true only for transient errors.
     * @throws SocialPublishException
     */
    private function throwFromApiError(array $error, int $accountId): never
    {
        $code    = (int) ($error['code'] ?? 0);
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = $error['message'] ?? 'Unknown Facebook error';
        $type    = $error['type'] ?? '';

        Log::warning('Facebook API error', compact('code', 'subcode', 'message', 'accountId'));

        // Token/auth errors — permanent failure, mark account inactive
        if ($code === 190 || ($type === 'OAuthException' && in_array($code, [200, 10]))) {
            // Deactivate the account so admin knows to re-connect
            \App\Models\SocialAccount::find($accountId)?->update([
                'is_active'           => false,
                'deactivation_reason' => FacebookTokenManager::diagnoseError($code, $subcode),
            ]);

            throw new SocialPublishException(
                "Facebook auth error [{$code}]: {$message}",
                retryable: false,
                errorData: compact('code', 'subcode', 'message', 'type'),
                requiresReconnect: true
            );
        }

        // Rate limit / throttle — retry after a delay
        if ($code === 32 || $code === 368 || $code === 613) {
            throw new SocialPublishException(
                "Facebook rate limit [{$code}]: {$message}",
                retryable: true,
                errorData: compact('code', 'subcode', 'message'),
                retryDelayMinutes: $code === 368 ? 180 : 60
            );
        }

        // Transient / unknown server error — retry with backoff
        if ($code === 2 || $code === 1 || $code >= 500) {
            throw new SocialPublishException(
                "Facebook transient error [{$code}]: {$message}",
                retryable: true,
                errorData: compact('code', 'subcode', 'message')
            );
        }

        // Anything else — permanent failure, don't retry
        throw new SocialPublishException(
            "Facebook error [{$code}]: {$message}",
            retryable: false,
            errorData: compact('code', 'subcode', 'message', 'type')
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve the public image URL from a post.
     * Prefers media_public_url; falls back to building URL from media_asset.
     */
    private function resolveImageUrl(SocialPost $post): string
    {
        if ($post->media_public_url) {
            return $post->media_public_url;
        }

        $asset = $post->mediaAsset;
        if (!$asset) {
            throw new SocialPublishException(
                'No image URL available for this post.',
                retryable: false
            );
        }

        // Use original_url if available (Pexels CDN, S3 direct, etc.)
        if ($asset->original_url) {
            return $asset->original_url;
        }

        // Build from disk
        return Storage::disk($asset->disk ?? 'public')->url($asset->internal_path);
    }
}
