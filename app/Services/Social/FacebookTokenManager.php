<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;

/**
 * FacebookTokenManager
 *
 * Handles the full 3-step Facebook token lifecycle:
 *
 *   Step 1: Short-lived User Token (1 hour)  — from OAuth callback
 *   Step 2: Long-lived User Token (60 days)  — exchanged server-side
 *   Step 3: Never-expiring Page Access Token — fetched via /{user_id}/accounts
 *
 * Only the Step 3 Page token is stored. Steps 1 & 2 are transient.
 *
 * The Page token NEVER expires unless:
 *   - User changes their Facebook password (code 190, subcode 460)
 *   - User revokes app permissions (code 190, subcode 463)
 *   - Account hits security checkpoint (code 190, subcode 490)
 *   - App is taken offline/deleted (code 190)
 */
class FacebookTokenManager
{
    private string $appId;
    private string $appSecret;
    private string $graphBase;

    public function __construct()
    {
        $this->appId     = config('services.facebook.client_id');
        $this->appSecret = config('services.facebook.client_secret');
        $this->graphBase = 'https://graph.facebook.com/v22.0';
    }

    /**
     * Step 2: Exchange short-lived user token → long-lived user token (60 days).
     * Must be called server-side (uses app_secret, never expose to client).
     *
     * @throws \RuntimeException
     */
    public function exchangeForLongLived(string $shortLivedToken): string
    {
        $response = Http::timeout(15)->get("{$this->graphBase}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId,
            'client_secret'     => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \RuntimeException(
                "Token exchange failed: [{$data['error']['code']}] {$data['error']['message']}"
            );
        }

        return $data['access_token'];
    }

    /**
     * Step 3: Fetch all Pages the user admins, with their never-expiring Page tokens.
     * Call this using the long-lived USER token from step 2.
     *
     * Returns array of pages, each with:
     *   id, name, access_token (never expires), category, picture_url
     *
     * @throws \RuntimeException
     */
    public function getPagesWithTokens(string $longLivedUserToken): array
    {
        // Get the app-scoped user ID first
        $me = Http::timeout(15)
            ->get("{$this->graphBase}/me", [
                'access_token' => $longLivedUserToken,
                'fields'       => 'id',
            ])->json();

        if (isset($me['error'])) {
            throw new \RuntimeException(
                "Failed to get user ID: [{$me['error']['code']}] {$me['error']['message']}"
            );
        }

        // Fetch all pages with never-expiring tokens
        $pages = Http::timeout(15)
            ->get("{$this->graphBase}/{$me['id']}/accounts", [
                'access_token' => $longLivedUserToken,
                'fields'       => 'id,name,access_token,category,picture{url}',
            ])->json();

        if (isset($pages['error'])) {
            throw new \RuntimeException(
                "Failed to fetch pages: [{$pages['error']['code']}] {$pages['error']['message']}"
            );
        }

        return collect($pages['data'] ?? [])->map(fn($p) => [
            'id'          => $p['id'],
            'name'        => $p['name'],
            'access_token'=> $p['access_token'],
            'category'    => $p['category'] ?? null,
            'picture_url' => $p['picture']['data']['url'] ?? null,
        ])->all();
    }

    /**
     * Validate a stored Page token is still working.
     * Returns ['valid' => bool, 'code' => int|null, 'subcode' => int|null, 'message' => string]
     */
    public function validatePageToken(string $pageToken): array
    {
        $response = Http::timeout(10)
            ->get("{$this->graphBase}/me", [
                'access_token' => $pageToken,
                'fields'       => 'id,name',
            ])->json();

        if (isset($response['error'])) {
            return [
                'valid'   => false,
                'code'    => $response['error']['code'] ?? null,
                'subcode' => $response['error']['error_subcode'] ?? null,
                'message' => $response['error']['message'] ?? 'Unknown error',
            ];
        }

        return ['valid' => true, 'page_id' => $response['id']];
    }

    /**
     * Map a Facebook error code + subcode to a human-readable admin message.
     */
    public static function diagnoseError(int $code, ?int $subcode): string
    {
        return match(true) {
            $code === 190 && $subcode === 460 =>
                'Facebook password was changed — please reconnect the account.',
            $code === 190 && $subcode === 463 =>
                'Facebook session expired — please reconnect the account.',
            $code === 190 && $subcode === 490 =>
                'Facebook account has a security checkpoint. Log into Facebook directly to resolve it, then reconnect.',
            $code === 190 =>
                'Facebook access token is invalid — please reconnect the account.',
            $code === 200 && $subcode === 1609008 =>
                'Missing pages_manage_posts permission — reconnect and grant all requested permissions.',
            $code === 200 && $subcode === 2424008 =>
                'Page Publishing Authorization required — complete PPA in Facebook Business Settings, then reconnect.',
            $code === 32 =>
                'Rate limit reached — too many API requests. Increase caching duration.',
            $code === 368 =>
                'Temporarily blocked by Facebook for posting too fast. Wait a few hours before retrying.',
            default =>
                "Facebook API error (code {$code}" . ($subcode ? ", subcode {$subcode}" : '') . ") — try reconnecting the account.",
        };
    }
}
