<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeQuotaUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * YouTubePublisher
 *
 * Uploads videos to a YouTube channel via YouTube Data API v3.
 *
 * Upload flow (resumable upload — required for all video files):
 *   Step 1: POST /upload/youtube/v3/videos?uploadType=resumable
 *           with video metadata in body → get upload URI
 *   Step 2: PUT {upload_uri} with video binary in 5MB chunks
 *   Step 3: Parse response for video ID
 *
 * Quota cost: 1,600 units per upload (daily limit: 10,000 → max 6 uploads/day free)
 * We check quota BEFORE attempting upload and throw if insufficient.
 *
 * Token management:
 *   - access_token expires in 1 hour
 *   - refresh_token lasts indefinitely (stored once at OAuth)
 *   - We auto-refresh before every API call if token is expired
 *
 * Common errors handled:
 *   - quotaExceeded (403)    → non-retryable, log and notify
 *   - uploadLimitExceeded    → non-retryable for 24 hours
 *   - invalid_grant (401)    → refresh token expired/revoked, mark inactive
 *   - forbidden (403)        → wrong scope or account issue
 *   - 5xx server errors      → retryable with backoff
 */
class YouTubePublisher
{
    private const UPLOAD_QUOTA_UNITS = 1600;
    private const CHUNK_SIZE_BYTES   = 5 * 1024 * 1024; // 5MB per chunk
    private const API_BASE           = 'https://www.googleapis.com/youtube/v3';
    private const UPLOAD_BASE        = 'https://www.googleapis.com/upload/youtube/v3';
    private const TOKEN_URL          = 'https://oauth2.googleapis.com/token';

    /**
     * Upload a video SocialPost to YouTube.
     * Returns the YouTube video ID on success.
     *
     * @throws SocialPublishException
     */
    public function publish(SocialPost $post): string
    {
        $account = $post->socialAccount;

        // Refresh token if needed before any API call
        $this->refreshTokenIfExpired($account);

        // Check we have enough quota
        $available = YoutubeQuotaUsage::availableToday($account->id);
        if ($available < self::UPLOAD_QUOTA_UNITS) {
            throw new SocialPublishException(
                "YouTube quota insufficient: {$available} units available, need " . self::UPLOAD_QUOTA_UNITS . ". Resets at midnight PT.",
                retryable: false,
                errorData: ['quota_available' => $available, 'quota_needed' => self::UPLOAD_QUOTA_UNITS]
            );
        }

        // Resolve video file path
        $videoPath = $this->resolveVideoPath($post);
        $fileSize  = filesize($videoPath);

        if ($fileSize === false || $fileSize === 0) {
            throw new SocialPublishException('Video file not found or empty.', retryable: false);
        }

        $token = $account->getAccessToken();

        // Build video metadata
        $metadata = $this->buildMetadata($post);

        // Step 1: Initiate resumable upload session
        $uploadUri = $this->initiateResumableUpload($token, $metadata, $fileSize, $account->id);

        // Step 2: Upload the video in chunks
        $videoId = $this->uploadChunks($uploadUri, $videoPath, $fileSize, $account->id);

        // Track quota consumption
        YoutubeQuotaUsage::consume($account->id, self::UPLOAD_QUOTA_UNITS, 'upload');

        // Update account last_used_at
        $account->update(['last_used_at' => now()]);

        return $videoId;
    }

    // ── Step 1: Initiate resumable upload ─────────────────────────────────

    private function initiateResumableUpload(
        string $token,
        array  $metadata,
        int    $fileSize,
        int    $accountId
    ): string {
        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization'           => "Bearer {$token}",
                'Content-Type'            => 'application/json',
                'X-Upload-Content-Type'   => 'video/*',
                'X-Upload-Content-Length' => $fileSize,
            ])
            ->post(self::UPLOAD_BASE . '/videos?uploadType=resumable&part=snippet,status', $metadata);

        if ($response->status() === 401) {
            throw new SocialPublishException(
                'YouTube token invalid during upload initiation.',
                retryable: false,
                requiresReconnect: true
            );
        }

        if ($response->failed()) {
            $error = $response->json();
            $this->throwFromApiError($error, $accountId);
        }

        // The upload URI is in the Location header
        $uploadUri = $response->header('Location');
        if (!$uploadUri) {
            throw new SocialPublishException(
                'YouTube did not return an upload URI.',
                retryable: true
            );
        }

        return $uploadUri;
    }

    // ── Step 2: Upload video chunks ───────────────────────────────────────

    private function uploadChunks(
        string $uploadUri,
        string $videoPath,
        int    $fileSize,
        int    $accountId
    ): string {
        $handle = fopen($videoPath, 'rb');
        if (!$handle) {
            throw new SocialPublishException('Cannot open video file for reading.', retryable: false);
        }

        $offset    = 0;
        $videoId   = null;

        try {
            while (!feof($handle)) {
                $chunk     = fread($handle, self::CHUNK_SIZE_BYTES);
                $chunkSize = strlen($chunk);
                $rangeEnd  = $offset + $chunkSize - 1;

                $response = Http::timeout(120) // video uploads can be slow
                    ->withHeaders([
                        'Content-Range' => "bytes {$offset}-{$rangeEnd}/{$fileSize}",
                        'Content-Type'  => 'video/*',
                    ])
                    ->withBody($chunk, 'video/*')
                    ->put($uploadUri);

                $status = $response->status();

                // 308 Resume Incomplete — chunk accepted, continue
                if ($status === 308) {
                    $offset += $chunkSize;
                    continue;
                }

                // 200 or 201 — upload complete
                if ($status === 200 || $status === 201) {
                    $data = $response->json();
                    $videoId = $data['id'] ?? null;
                    break;
                }

                // 5xx — server error, retryable
                if ($status >= 500) {
                    throw new SocialPublishException(
                        "YouTube upload server error (HTTP {$status}).",
                        retryable: true
                    );
                }

                // Anything else — parse error
                $error = $response->json();
                $this->throwFromApiError($error ?? ['error' => ['code' => $status, 'message' => 'Unknown']], $accountId);
            }
        } finally {
            fclose($handle);
        }

        if (!$videoId) {
            throw new SocialPublishException('YouTube upload completed but no video ID returned.', retryable: false);
        }

        return $videoId;
    }

    // ── Token refresh ─────────────────────────────────────────────────────

    /**
     * If the access token is expired (or expires within 5 minutes),
     * use the refresh token to get a new one and update the DB.
     *
     * @throws SocialPublishException if refresh fails (token revoked)
     */
    public function refreshTokenIfExpired(SocialAccount $account): void
    {
        if (!$account->isTokenExpired()) {
            return;
        }

        $refreshToken = $account->getRefreshToken();
        if (!$refreshToken) {
            throw new SocialPublishException(
                'YouTube refresh token is missing — please reconnect the YouTube account.',
                retryable: false,
                requiresReconnect: true
            );
        }

        $response = Http::timeout(15)->asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        $data = $response->json();

        if (isset($data['error'])) {
            // invalid_grant = refresh token revoked (user disconnected app in Google)
            if ($data['error'] === 'invalid_grant') {
                $account->update([
                    'is_active'           => false,
                    'deactivation_reason' => 'YouTube refresh token has been revoked. Please reconnect the YouTube account.',
                ]);
                throw new SocialPublishException(
                    'YouTube refresh token revoked.',
                    retryable: false,
                    requiresReconnect: true
                );
            }

            throw new SocialPublishException(
                'YouTube token refresh failed: ' . ($data['error_description'] ?? $data['error'] ?? 'unknown error'),
                retryable: true
            );
        }

        // Save new access token (refresh_token is NOT re-issued unless user re-consents)
        $account->setAccessToken($data['access_token']);
        $account->update([
            'token_expires_at'  => now()->addSeconds($data['expires_in'] - 60),
            'last_refreshed_at' => now(),
        ]);
        $account->save();
    }

    // ── Metadata builder ──────────────────────────────────────────────────

    private function buildMetadata(SocialPost $post): array
    {
        $options = $post->platform_options ?? [];

        return [
            'snippet' => [
                'title'               => $options['title'] ?? ($post->content_text ? substr($post->content_text, 0, 100) : 'CNI News Video'),
                'description'         => $options['description'] ?? ($post->content_text ?? ''),
                'tags'                => $options['tags'] ?? ['CNI News', 'Pakistan', 'News'],
                'categoryId'          => $options['category_id'] ?? '25', // 25 = News & Politics
                'defaultLanguage'     => $options['default_language'] ?? 'en',
                'defaultAudioLanguage'=> $options['audio_language'] ?? 'en',
            ],
            'status' => [
                'privacyStatus'           => $options['privacy_status'] ?? 'public',
                'selfDeclaredMadeForKids' => false,
                'madeForKids'             => false,
                // Scheduled publish: ISO 8601 datetime
                'publishAt' => ($post->post_type === 'scheduled' && $post->scheduled_at)
                    ? $post->scheduled_at->toRfc3339String()
                    : null,
            ],
        ];
    }

    // ── Error handling ────────────────────────────────────────────────────

    private function throwFromApiError(array $response, int $accountId): never
    {
        $error   = $response['error'] ?? $response;
        $errors  = is_array($error) ? $error : [];
        $message = $errors['message'] ?? ($errors[0]['message'] ?? 'Unknown YouTube error');
        $reason  = $errors['errors'][0]['reason'] ?? ($errors[0]['reason'] ?? '');
        $code    = (int) ($errors['code'] ?? 0);

        Log::warning('YouTube API error', compact('code', 'message', 'reason', 'accountId'));

        // Quota exceeded — don't retry today
        if ($reason === 'quotaExceeded' || $reason === 'rateLimitExceeded') {
            throw new SocialPublishException(
                "YouTube quota exceeded. Resets at midnight Pacific Time.",
                retryable: false,
                errorData: compact('code', 'message', 'reason')
            );
        }

        // Upload count limit
        if ($reason === 'uploadLimitExceeded') {
            throw new SocialPublishException(
                'YouTube daily upload limit reached. Try again tomorrow.',
                retryable: false,
                errorData: compact('code', 'message', 'reason')
            );
        }

        // Auth failures
        if ($code === 401 || $reason === 'authError') {
            $accountModel = \App\Models\SocialAccount::find($accountId);
            $accountModel?->update([
                'is_active'           => false,
                'deactivation_reason' => 'YouTube authorization failed. Please reconnect the account.',
            ]);
            throw new SocialPublishException(
                'YouTube authorization failed.',
                retryable: false,
                requiresReconnect: true,
                errorData: compact('code', 'message', 'reason')
            );
        }

        // Forbidden — bad scope or account issue
        if ($code === 403) {
            throw new SocialPublishException(
                "YouTube forbidden: {$message}",
                retryable: false,
                errorData: compact('code', 'message', 'reason')
            );
        }

        // 5xx — retryable
        if ($code >= 500) {
            throw new SocialPublishException(
                "YouTube server error: {$message}",
                retryable: true,
                errorData: compact('code', 'message', 'reason')
            );
        }

        throw new SocialPublishException(
            "YouTube error [{$code}]: {$message}",
            retryable: false,
            errorData: compact('code', 'message', 'reason')
        );
    }

    // ── Path resolution ───────────────────────────────────────────────────

    private function resolveVideoPath(SocialPost $post): string
    {
        // If media_public_url is a local storage path, resolve it
        if ($post->media_public_url && !str_starts_with($post->media_public_url, 'http')) {
            return Storage::disk('public')->path($post->media_public_url);
        }

        // If we have a media_asset with an internal_path
        $asset = $post->mediaAsset;
        if ($asset && $asset->internal_path) {
            return Storage::disk($asset->disk ?? 'public')->path($asset->internal_path);
        }

        // Can't upload from a remote URL without downloading first
        if ($post->media_public_url && str_starts_with($post->media_public_url, 'http')) {
            // If it's a local public storage URL, resolve to disk path directly
            $publicBase = Storage::disk('public')->url('');
            if (str_starts_with($post->media_public_url, $publicBase)) {
                $relative  = ltrim(str_replace($publicBase, '', $post->media_public_url), '/');
                $localPath = Storage::disk('public')->path($relative);
                if (file_exists($localPath)) {
                    return $localPath;
                }
            }
            return $this->downloadToTemp($post->media_public_url);
        }

        throw new SocialPublishException(
            'No video file available for YouTube upload. Provide media_public_url or media_asset_id.',
            retryable: false
        );
    }

    /**
     * Download a remote video to a temp file for upload.
     * Returns the local temp path.
     */
    private function downloadToTemp(string $url): string
    {
        $tempPath = sys_get_temp_dir() . '/yt_upload_' . uniqid() . '.mp4';

        $response = Http::timeout(300)->sink($tempPath)->get($url);

        if (!$response->successful()) {
            throw new SocialPublishException(
                "Failed to download video from URL for YouTube upload.",
                retryable: true
            );
        }

        return $tempPath;
    }
}
