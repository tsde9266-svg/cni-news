<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TwitterPublisher
 *
 * Posts text tweets to Twitter/X via API v2 using OAuth 1.0a.
 *
 * Free tier constraints (as of 2025):
 *   - 500 posts per month (write only)
 *   - No read access on free tier
 *   - No media upload on free tier (Basic plan required at $100/mo)
 *   - Rate limit: 1 request per 15 minutes per user on free tier
 *
 * Auth: OAuth 1.0a (all 4 credentials required):
 *   - API Key        (stored as access_token in social_accounts)
 *   - API Secret     (stored as oauth_token_secret)
 *   - Access Token   (stored in platform_meta.access_token)
 *   - Access Secret  (stored in platform_meta.access_secret)
 *
 * Twitter/X OAuth 1.0a signature is complex — we build it manually
 * rather than pulling in a full OAuth library dependency.
 *
 * Character limit: 280 chars. URLs always count as 23 chars regardless
 * of actual length (t.co shortening). We enforce this in SocialPostService.
 */
class TwitterPublisher
{
    private const API_BASE = 'https://api.twitter.com/2';

    /**
     * Post a tweet.
     * Returns the tweet ID on success.
     *
     * @throws SocialPublishException
     */
    public function publish(SocialPost $post): string
    {
        $account = $post->socialAccount;

        // Get all 4 OAuth 1.0a credentials
        $apiKey       = config('services.twitter.client_id');
        $apiSecret    = config('services.twitter.client_secret');
        $accessToken  = $account->getMeta('access_token');
        $accessSecret = $account->getMeta('access_secret');

        if (!$apiKey || !$apiSecret || !$accessToken || !$accessSecret) {
            throw new SocialPublishException(
                'Twitter OAuth credentials incomplete. Ensure API Key, API Secret, Access Token and Access Secret are all configured.',
                retryable: false,
                requiresReconnect: true
            );
        }

        $text = $post->content_text ?? '';

        if (empty(trim($text))) {
            throw new SocialPublishException(
                'Twitter post text is empty.',
                retryable: false
            );
        }

        // Build the tweet payload
        $payload = ['text' => $text];

        // Add reply settings if specified
        if ($replySettings = $post->getOption('reply_settings')) {
            $payload['reply_settings'] = $replySettings; // 'mentionedUsers' | 'subscribers' | 'everyone'
        }

        // Make the authenticated request
        $response = $this->makeOAuth1Request(
            method:       'POST',
            url:          self::API_BASE . '/tweets',
            apiKey:       $apiKey,
            apiSecret:    $apiSecret,
            accessToken:  $accessToken,
            accessSecret: $accessSecret,
            body:         $payload
        );

        $data = $response->json();

        if ($response->failed() || isset($data['errors'])) {
            $this->throwFromApiError($data, $response->status(), $account->id);
        }

        $tweetId = $data['data']['id'] ?? null;

        if (!$tweetId) {
            throw new SocialPublishException(
                'Twitter API returned success but no tweet ID.',
                retryable: false
            );
        }

        return $tweetId;
    }

    // ── OAuth 1.0a request builder ─────────────────────────────────────────

    /**
     * Make an OAuth 1.0a signed HTTP request.
     * Builds the Authorization header with HMAC-SHA1 signature.
     */
    private function makeOAuth1Request(
        string $method,
        string $url,
        string $apiKey,
        string $apiSecret,
        string $accessToken,
        string $accessSecret,
        array  $body = []
    ): \Illuminate\Http\Client\Response {
        $nonce     = bin2hex(random_bytes(16));
        $timestamp = time();

        // OAuth parameters (must be sorted alphabetically for signature base)
        $oauthParams = [
            'oauth_consumer_key'     => $apiKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => $timestamp,
            'oauth_token'            => $accessToken,
            'oauth_version'          => '1.0',
        ];

        // Build signature base string
        // For JSON body requests, only OAuth params go in the signature (not body)
        $signatureBase = $this->buildSignatureBase($method, $url, $oauthParams);
        $signingKey    = rawurlencode($apiSecret) . '&' . rawurlencode($accessSecret);
        $signature     = base64_encode(hash_hmac('sha1', $signatureBase, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;

        // Build Authorization header
        $authHeader = 'OAuth ' . implode(', ', array_map(
            fn($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
            array_keys($oauthParams),
            array_values($oauthParams)
        ));

        return Http::timeout(15)
            ->withHeaders([
                'Authorization' => $authHeader,
                'Content-Type'  => 'application/json',
            ])
            ->send($method, $url, ['json' => $body]);
    }

    private function buildSignatureBase(string $method, string $url, array $params): string
    {
        ksort($params);
        $paramString = implode('&', array_map(
            fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($params),
            array_values($params)
        ));

        return strtoupper($method) . '&'
            . rawurlencode($url) . '&'
            . rawurlencode($paramString);
    }

    // ── Error handling ─────────────────────────────────────────────────────

    private function throwFromApiError(array $data, int $httpStatus, int $accountId): never
    {
        $errors  = $data['errors'] ?? [];
        $first   = $errors[0] ?? [];
        $message = $first['message'] ?? ($data['detail'] ?? 'Unknown Twitter error');
        $code    = $first['code']    ?? $httpStatus;

        Log::warning('Twitter API error', compact('code', 'message', 'accountId', 'httpStatus'));

        // 401 — auth failed (wrong keys or revoked)
        if ($httpStatus === 401 || $code === 32 || $code === 89) {
            \App\Models\SocialAccount::find($accountId)?->update([
                'is_active'           => false,
                'deactivation_reason' => 'Twitter credentials are invalid or revoked. Please reconnect the account.',
            ]);
            throw new SocialPublishException(
                "Twitter auth failed: {$message}",
                retryable: false,
                requiresReconnect: true,
                errorData: compact('code', 'message', 'httpStatus')
            );
        }

        // 403 — permissions (app not in Read+Write mode, or duplicate tweet)
        if ($httpStatus === 403 || $code === 187) {
            $reason = $code === 187
                ? 'Duplicate tweet — this exact text was already posted recently.'
                : 'Twitter permission error. Ensure your app has Read and Write permissions in the Developer Portal.';
            throw new SocialPublishException(
                $reason,
                retryable: false,
                errorData: compact('code', 'message', 'httpStatus')
            );
        }

        // 429 — rate limit (500/month or per-minute limit)
        if ($httpStatus === 429) {
            throw new SocialPublishException(
                'Twitter rate limit reached. Free tier allows 500 posts per month.',
                retryable: true,
                errorData: compact('code', 'message', 'httpStatus'),
                retryDelayMinutes: 60
            );
        }

        // 5xx — Twitter server error, retryable
        if ($httpStatus >= 500) {
            throw new SocialPublishException(
                "Twitter server error (HTTP {$httpStatus}): {$message}",
                retryable: true,
                errorData: compact('code', 'message', 'httpStatus')
            );
        }

        throw new SocialPublishException(
            "Twitter error [{$code}]: {$message}",
            retryable: false,
            errorData: compact('code', 'message', 'httpStatus')
        );
    }
}
