<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\TiktokPublishStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTokPublisher
 *
 * Posts videos to TikTok via the Content Posting API v2.
 *
 * We use PULL_FROM_URL (not FILE_UPLOAD) because:
 *   - Simpler: no chunked binary upload needed
 *   - TikTok fetches directly from your CDN
 *   - Requires domain verification (one-time setup in TikTok Developer Portal)
 *
 * Mandatory flow:
 *
 *   Step 0 — Query creator info (REQUIRED per TikTok guidelines):
 *     POST /v2/post/publish/creator_info/query/
 *     → returns available privacy_level_options, interaction settings
 *     → you MUST show these in the UI and get explicit user consent
 *
 *   Step 1 — Init upload:
 *     POST /v2/post/publish/video/init/
 *       post_info.title          (required, max 2200 chars)
 *       post_info.privacy_level  (must be from creator_info options)
 *       post_info.disable_duet   (required bool)
 *       post_info.disable_comment (required bool)
 *       post_info.disable_stitch  (required bool)
 *       source_info.source       = PULL_FROM_URL
 *       source_info.video_url    = your publicly accessible video URL
 *     → returns publish_id
 *
 *   Step 2 — Async: poll status until PUBLISH_COMPLETE or FAILED
 *     POST /v2/post/publish/status/fetch/
 *       publish_id = from step 1
 *     → stages: CREATED → PROCESSING → PUBLISH_COMPLETE | FAILED
 *     → Stored in tiktok_publish_status table, polled by PollTikTokStatusCommand
 *
 * Token management:
 *   - access_token:  expires 24 hours → auto-refreshed before each call
 *   - refresh_token: expires 365 days → re-auth required when expired
 *   - Rate limit: 6 requests per minute per access_token
 *
 * Before audit: all posts are SELF_ONLY (private).
 * After audit:  PUBLIC_TO_EVERYONE available.
 *
 * Domain verification required for PULL_FROM_URL:
 *   TikTok Developer Portal → Your App → Domain Verification
 *   Add your domain: cninews.co.uk (or whatever your server domain is)
 */
class TikTokPublisher
{
    private const API_BASE   = 'https://open.tiktokapis.com/v2';
    private const TOKEN_URL  = 'https://open.tiktokapis.com/v2/oauth/token/';

    /**
     * Initiate a TikTok video post.
     * Returns the publish_id — the final post_id comes later via polling.
     *
     * IMPORTANT: This method returns a publish_id, NOT a final post_id.
     * The SocialPublishJob stores this in tiktok_publish_status for polling.
     * The actual TikTok post_id is only available after moderation completes.
     *
     * @throws SocialPublishException
     */
    public function publish(SocialPost $post): string
    {
        $account = $post->socialAccount;

        // Refresh token if needed (TikTok tokens expire every 24 hours)
        $this->refreshTokenIfExpired($account);

        $token   = $account->getAccessToken();
        $videoUrl = $this->resolveVideoUrl($post);

        // Build post_info from platform_options (set in admin UI)
        $postInfo = $this->buildPostInfo($post);

        // Init the upload
        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json; charset=UTF-8',
            ])
            ->post(self::API_BASE . '/post/publish/video/init/', [
                'post_info'   => $postInfo,
                'source_info' => [
                    'source'    => 'PULL_FROM_URL',
                    'video_url' => $videoUrl,
                ],
            ]);

        $data = $response->json();

        if ($data['error']['code'] !== 'ok') {
            $this->throwFromApiError($data['error'], $account->id);
        }

        $publishId = $data['data']['publish_id'];

        // Create the status tracking row for polling
        TiktokPublishStatus::create([
            'social_post_id' => $post->id,
            'publish_id'     => $publishId,
            'tiktok_status'  => 'CREATED',
            'poll_count'     => 0,
            'next_poll_at'   => now()->addSeconds(30), // first poll after 30 seconds
            'abandon_after'  => now()->addMinutes(15), // TikTok's max processing time
        ]);

        // Update account last_used_at
        $account->update(['last_used_at' => now()]);

        Log::info("TikTok upload initiated", [
            'post_id'    => $post->id,
            'publish_id' => $publishId,
        ]);

        // Return publish_id — job uses this as the temporary "post ID"
        // It will be updated to the real post_id once polling completes
        return $publishId;
    }

    /**
     * Query creator info before showing the post UI.
     * TikTok requires your UI to reflect these values — you cannot
     * hardcode privacy levels or interaction settings.
     *
     * Returns:
     *   privacy_level_options: ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY']
     *   comment_disabled, duet_disabled, stitch_disabled (bool)
     *   max_video_post_duration_sec (int)
     *
     * @throws SocialPublishException
     */
    public function queryCreatorInfo(SocialAccount $account): array
    {
        $this->refreshTokenIfExpired($account);
        $token = $account->getAccessToken();

        $response = Http::timeout(15)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json; charset=UTF-8',
            ])
            ->post(self::API_BASE . '/post/publish/creator_info/query/');

        $data = $response->json();

        if ($data['error']['code'] !== 'ok') {
            $this->throwFromApiError($data['error'], $account->id);
        }

        return $data['data'];
    }

    /**
     * Poll the status of an in-progress TikTok upload.
     * Called by PollTikTokStatusCommand every minute.
     *
     * Returns the current status string:
     *   CREATED, PROCESSING, PUBLISH_COMPLETE, FAILED
     */
    public function pollStatus(TiktokPublishStatus $statusRow): string
    {
        $account = $statusRow->socialPost->socialAccount;
        $this->refreshTokenIfExpired($account);
        $token = $account->getAccessToken();

        $response = Http::timeout(15)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json; charset=UTF-8',
            ])
            ->post(self::API_BASE . '/post/publish/status/fetch/', [
                'publish_id' => $statusRow->publish_id,
            ]);

        $data = $response->json();

        if ($data['error']['code'] !== 'ok') {
            $this->throwFromApiError($data['error'], $account->id);
        }

        return $data['data']['status'] ?? 'PROCESSING';
    }

    // ── Token refresh ─────────────────────────────────────────────────────

    public function refreshTokenIfExpired(SocialAccount $account): void
    {
        if (!$account->isTokenExpired()) {
            return;
        }

        // Check if refresh token itself has expired (365 days)
        if ($account->isRefreshTokenExpired()) {
            $account->update([
                'is_active'           => false,
                'deactivation_reason' => 'TikTok refresh token has expired (365-day limit). Please reconnect the TikTok account.',
            ]);
            throw new SocialPublishException(
                'TikTok refresh token expired. Re-authorization required.',
                retryable: false,
                requiresReconnect: true
            );
        }

        $refreshToken = $account->getRefreshToken();

        $response = Http::timeout(15)->asForm()->post(self::TOKEN_URL, [
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $data = $response->json();

        if (isset($data['error'])) {
            throw new SocialPublishException(
                'TikTok token refresh failed: ' . ($data['description'] ?? $data['error'] ?? 'unknown error'),
                retryable: true
            );
        }

        // Save new tokens — TikTok re-issues both tokens on refresh
        $account->setAccessToken($data['access_token']);
        $account->setRefreshToken($data['refresh_token']);

        $account->update([
            'token_expires_at'         => now()->addSeconds($data['expires_in'] - 60),
            'refresh_token_expires_at' => now()->addSeconds($data['refresh_expires_in'] - 60),
            'last_refreshed_at'        => now(),
        ]);
        $account->save();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buildPostInfo(SocialPost $post): array
    {
        $options = $post->platform_options ?? [];

        return [
            'title'                    => $options['title'] ?? substr($post->content_text ?? 'CNI News Video', 0, 2200),
            'privacy_level'            => $options['privacy_level'] ?? 'SELF_ONLY', // safe default
            'disable_duet'             => $options['disable_duet']    ?? false,
            'disable_comment'          => $options['disable_comment'] ?? false,
            'disable_stitch'           => $options['disable_stitch']  ?? false,
            'video_cover_timestamp_ms' => $options['cover_timestamp_ms'] ?? 0,
        ];
    }

    private function resolveVideoUrl(SocialPost $post): string
    {
        if ($post->media_public_url) {
            return $post->media_public_url;
        }

        $asset = $post->mediaAsset;
        if ($asset && $asset->original_url) {
            return $asset->original_url;
        }

        throw new SocialPublishException(
            'No public video URL for TikTok. Set media_public_url to a URL on your verified domain.',
            retryable: false
        );
    }

    private function throwFromApiError(array $error, int $accountId): never
    {
        $code    = $error['code'] ?? 'unknown';
        $message = $error['message'] ?? 'Unknown TikTok error';
        $logId   = $error['log_id'] ?? null;

        Log::warning('TikTok API error', compact('code', 'message', 'accountId', 'logId'));

        // Auth errors
        if (in_array($code, ['access_token_invalid', 'access_token_expired'])) {
            throw new SocialPublishException(
                "TikTok auth error: {$message}",
                retryable: false,
                requiresReconnect: true,
                errorData: compact('code', 'message')
            );
        }

        // Spam / too many posts
        if (str_contains($code, 'spam') || str_contains($code, 'too_many')) {
            throw new SocialPublishException(
                "TikTok rate limit: {$message}",
                retryable: true,
                errorData: compact('code', 'message'),
                retryDelayMinutes: 60
            );
        }

        // Video pull failed — bad URL or domain not verified
        if (str_contains($code, 'video_pull_failed') || str_contains($code, 'url')) {
            throw new SocialPublishException(
                "TikTok could not fetch video: {$message}. Ensure the URL is on your verified domain.",
                retryable: false,
                errorData: compact('code', 'message')
            );
        }

        // Invalid privacy level — need to re-query creator_info
        if (str_contains($code, 'privacy_level')) {
            throw new SocialPublishException(
                "TikTok invalid privacy level: {$message}. Query creator_info to get valid options.",
                retryable: false,
                errorData: compact('code', 'message')
            );
        }

        throw new SocialPublishException(
            "TikTok error [{$code}]: {$message}",
            retryable: false,
            errorData: compact('code', 'message', 'logId')
        );
    }
}
