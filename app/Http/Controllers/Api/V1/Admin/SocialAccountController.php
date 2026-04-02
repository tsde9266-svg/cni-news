<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Services\Social\FacebookTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * SocialAccountController
 *
 * Manages connecting, listing, validating, and disconnecting social accounts.
 *
 * Routes (all under /api/v1/admin, auth:sanctum + role middleware):
 *
 *   GET    /social-accounts               → index()      list all connected accounts
 *   GET    /social-accounts/connect/facebook → connectFacebook()  step 1: get OAuth URL
 *   GET    /social-accounts/callback/facebook → callbackFacebook() step 2: exchange tokens + list pages
 *   POST   /social-accounts/facebook/save-page → saveFacebookPage() step 3: save chosen page
 *   POST   /social-accounts/{id}/validate  → validate()   test if token still works
 *   DELETE /social-accounts/{id}           → destroy()    disconnect account
 *
 * Facebook OAuth flow:
 *   1. Admin clicks "Connect Facebook" in the admin UI
 *   2. Frontend calls GET /connect/facebook → gets redirect_url
 *   3. Admin is redirected to Facebook, grants permissions
 *   4. Facebook redirects back to /callback/facebook?code=...
 *   5. We exchange code → short-lived token → long-lived token → page tokens
 *   6. We return list of pages to admin UI
 *   7. Admin picks which page to connect
 *   8. Frontend calls POST /facebook/save-page with chosen page_id
 *   9. We store the never-expiring page token encrypted
 */
class SocialAccountController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // ── GET /api/v1/admin/social-accounts ─────────────────────────────────
    // List all connected social accounts with status
    public function index(): JsonResponse
    {
        $accounts = SocialAccount::where('channel_id', $this->channelId())
            ->orderBy('platform')
            ->orderBy('account_name')
            ->get()
            ->map(fn($a) => $this->accountRow($a));

        return response()->json(['data' => $accounts]);
    }

    // ── GET /api/v1/admin/social-accounts/connect/facebook ────────────────
    // Step 1: Generate Facebook OAuth redirect URL.
    // Scopes needed:
    //   pages_show_list      → see which pages user manages
    //   pages_manage_posts   → post to page feed
    //   pages_read_engagement → read page posts (inbound feed)
    //   public_profile       → basic user identity
    public function connectFacebook(Request $request): JsonResponse
    {
        $redirectUrl = Socialite::driver('facebook')
            ->scopes([
                'pages_show_list',
                'pages_manage_posts',
                'pages_read_engagement',
                'public_profile',
            ])
            ->with(['auth_type' => 'rerequest'])
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    // ── GET /api/v1/admin/social-accounts/callback/facebook ───────────────
    // Step 2: Handle Facebook OAuth callback.
    // Exchanges code → short-lived token → long-lived token → page list.
    // Returns list of pages for admin to choose from.
    public function callbackFacebook(Request $request): JsonResponse|RedirectResponse
    {
        // DEBUG — remove after fixing
        Log::info('FB callback received', [
            'all_params' => $request->all(),
            'url'        => $request->fullUrl(),
            'has_code'   => $request->has('code'),
            'has_error'  => $request->has('error'),
        ]);

        // User denied permissions
        if ($request->get('error')) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $msg = urlencode('Facebook denied: ' . $request->get('error_description'));
            return redirect("{$frontendUrl}/admin/social?fb_error={$msg}");
        }

        if (!$request->has('code')) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $msg = urlencode('No authorization code received from Facebook. Ensure ' . config('services.facebook.redirect') . ' is listed in your Facebook App\'s Valid OAuth Redirect URIs.');
            return redirect("{$frontendUrl}/admin/social?fb_error={$msg}");
        }

        try {
            // Get the short-lived user token via Socialite
            $socialUser = Socialite::driver('facebook')->stateless()->user();
            $shortLivedToken = $socialUser->token;

            $manager = new FacebookTokenManager();

            // Step 2: Exchange short-lived → long-lived user token (server-side)
            $longLivedToken = $manager->exchangeForLongLived($shortLivedToken);

            // Step 3: Get all pages with never-expiring page tokens
            $pages = $manager->getPagesWithTokens($longLivedToken);

            if (empty($pages)) {
                return response()->json([
                    'error' => 'No Facebook Pages found. You must be an admin of at least one Facebook Page.',
                ], 422);
            }

            // Redirect back to the frontend admin page with pages data
            // We encode pages as a base64 JSON blob in the URL so the frontend
            // can display the page picker without a separate API call.
            // The access tokens in this payload are the real page tokens —
            // they only travel over HTTPS and are never logged.
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $encoded = base64_encode(json_encode($pages));
            return redirect("{$frontendUrl}/admin/social?fb_pages={$encoded}");

        } catch (\Exception $e) {
            Log::error('Facebook OAuth callback failed', [
                'error' => $e->getMessage(),
                'user'  => $request->user()?->id,
            ]);

            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $msg = urlencode('Facebook authentication failed. ' . ($e->getMessage()));
            return redirect("{$frontendUrl}/admin/social?fb_error={$msg}");
        }
    }

    // ── POST /api/v1/admin/social-accounts/facebook/save-page ─────────────
    // Step 3: Admin has chosen which page to connect. Save it.
    public function saveFacebookPage(Request $request): JsonResponse
    {
        $request->validate([
            'page_id'     => ['required', 'string'],
            'page_name'   => ['required', 'string'],
            'access_token'=> ['required', 'string'],
            'picture_url' => ['nullable', 'string'],
            'category'    => ['nullable', 'string'],
        ]);

        // Validate the page token works before saving
        $manager = new FacebookTokenManager();
        $validation = $manager->validatePageToken($request->access_token);

        if (!$validation['valid']) {
            $reason = FacebookTokenManager::diagnoseError(
                $validation['code'] ?? 190,
                $validation['subcode'] ?? null
            );
            return response()->json(['error' => $reason], 422);
        }

        // Check if this page is already connected
        $existing = SocialAccount::where('channel_id', $this->channelId())
            ->where('platform', 'facebook')
            ->where('platform_account_id', $request->page_id)
            ->first();

        if ($existing) {
            // Update the existing connection (re-connect / token refresh)
            $existing->setAccessToken($request->access_token);
            $existing->update([
                'account_name'       => $request->page_name,
                'platform_username'  => $request->page_name,
                'profile_picture_url'=> $request->picture_url,
                'is_active'          => true,
                'deactivation_reason'=> null,
                'last_refreshed_at'  => now(),
                'platform_meta'      => array_merge(
                    $existing->platform_meta ?? [],
                    ['category' => $request->category]
                ),
            ]);
            $existing->save();

            AuditLog::log('social_account_reconnected', 'social_account', $existing->id, null, [
                'platform'     => 'facebook',
                'account_name' => $request->page_name,
            ]);
            return response()->json([
                'message' => "Facebook Page \"{$request->page_name}\" reconnected successfully.",
                'data'    => $this->accountRow($existing->fresh()),
            ]);
        }

        // Create new connection
        $account = new SocialAccount([
            'channel_id'           => $this->channelId(),
            'connected_by_user_id' => $request->user()->id,
            'platform'             => 'facebook',
            'account_name'         => $request->page_name,
            'platform_account_id'  => $request->page_id,
            'platform_username'    => $request->page_name,
            'profile_picture_url'  => $request->picture_url,
            'token_expires_at'     => null, // page tokens never expire
            'is_active'            => true,
            'last_refreshed_at'    => now(),
            'platform_meta'        => [
                'category' => $request->category,
            ],
        ]);

        $account->setAccessToken($request->access_token);
        $account->save();

        AuditLog::log('social_account_connected', 'social_account', $account->id, null, [
            'platform'     => 'facebook',
            'account_name' => $request->page_name,
            'connected_by' => $request->user()->display_name,
        ]);
        return response()->json([
            'message' => "Facebook Page \"{$request->page_name}\" connected successfully.",
            'data'    => $this->accountRow($account),
        ], 201);
    }

    // ── POST /api/v1/admin/social-accounts/{id}/validate ──────────────────
    // Test if the stored token for an account is still working.
    // Run this on a schedule (e.g. daily) and when posts fail with 190 errors.
    public function checkToken(int $id): JsonResponse
    {
        $account = SocialAccount::where('channel_id', $this->channelId())
            ->findOrFail($id);

        $result = match($account->platform) {
            'facebook', 'instagram' => $this->validateFacebook($account),
            default => ['valid' => true, 'message' => 'Validation not implemented for this platform yet.'],
        };

        if (!$result['valid']) {
            // Mark account as inactive so the admin knows action is needed
            $reason = FacebookTokenManager::diagnoseError(
                $result['code'] ?? 190,
                $result['subcode'] ?? null
            );
            $account->update([
                'is_active'           => false,
                'deactivation_reason' => $reason,
            ]);

            AuditLog::log('social_account_token_invalid', 'social_account', $account->id, null, [
                'platform'    => $account->platform,
                'error_code'  => $result['code'],
                'reason'      => $reason,
            ]);
        } else {
            // Token is fine — make sure account is marked active
            $account->update([
                'is_active'           => true,
                'deactivation_reason' => null,
                'last_used_at'        => now(),
            ]);
        }

        return response()->json([
            'valid'   => $result['valid'],
            'message' => $result['valid']
                ? 'Token is valid and working.'
                : ($result['message'] ?? 'Token is invalid.'),
        ]);
    }

    // ── DELETE /api/v1/admin/social-accounts/{id} ─────────────────────────
    // Disconnect a social account. Removes the stored token.
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = SocialAccount::where('channel_id', $this->channelId())
            ->findOrFail($id);

        AuditLog::log('social_account_disconnected', 'social_account', $account->id, [
            'platform'     => $account->platform,
            'account_name' => $account->account_name,
        ], null);

        $account->delete();

        return response()->json(['message' => 'Social account disconnected.']);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function validateFacebook(SocialAccount $account): array
    {
        try {
            $manager = new FacebookTokenManager();
            return $manager->validatePageToken($account->getAccessToken());
        } catch (\Exception $e) {
            return [
                'valid'   => false,
                'code'    => 0,
                'subcode' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function accountRow(SocialAccount $account): array
    {
        return [
            'id'                   => $account->id,
            'platform'             => $account->platform,
            'account_name'         => $account->account_name,
            'platform_username'    => $account->platform_username,
            'platform_account_id'  => $account->platform_account_id,
            'profile_picture_url'  => $account->profile_picture_url,
            'is_active'            => $account->is_active,
            'deactivation_reason'  => $account->deactivation_reason,
            'token_expires_at'     => $account->token_expires_at?->toIso8601String(),
            'token_expires_soon'   => $account->isTokenExpired(),
            'last_used_at'         => $account->last_used_at?->toIso8601String(),
            'last_refreshed_at'    => $account->last_refreshed_at?->toIso8601String(),
            'connected_at'         => $account->created_at?->toIso8601String(),
            // Platform-specific display info (no tokens)
            'meta' => match($account->platform) {
                'facebook'  => ['category' => $account->getMeta('category')],
                'youtube'   => ['uploads_playlist_id' => $account->getMeta('uploads_playlist_id')],
                'tiktok'    => ['verified_domain' => $account->getMeta('verified_domain')],
                default     => [],
            },
        ];
    }
    // ═══════════════════════════════════════════════════════════════════════
    // YOUTUBE OAUTH
    // ═══════════════════════════════════════════════════════════════════════

    // ── GET /api/v1/admin/social-accounts/connect/youtube ─────────────────
    // Step 1: Generate Google OAuth URL with YouTube scopes.
    // Google requires access_type=offline to get a refresh_token.
    // prompt=consent forces re-consent so we always get a refresh_token
    // (without this, Google only sends refresh_token on first auth).
    public function connectYouTube(Request $request): JsonResponse
    {
        $clientId    = config('services.youtube.client_id');
        $redirectUri = config('services.youtube.redirect');

        if (!$clientId) {
            return response()->json([
                'error' => 'YOUTUBE_CLIENT_ID not set in .env. Create a Google OAuth client first.',
            ], 422);
        }

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/youtube',
                'https://www.googleapis.com/auth/userinfo.profile',
            ]),
            'access_type'   => 'offline',
            'prompt'        => 'consent', // always get refresh_token
        ]);

        return response()->json([
            'redirect_url' => "https://accounts.google.com/o/oauth2/v2/auth?{$params}",
        ]);
    }

    // ── GET /api/v1/admin/social-accounts/callback/youtube ────────────────
    // Step 2: Exchange code → access_token + refresh_token.
    // Store both — access_token expires in 1 hour, refresh_token lasts forever.
    public function callbackYouTube(Request $request): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        if ($request->get('error')) {
            $msg = urlencode('YouTube authorization denied: ' . $request->get('error'));
            return redirect("{$frontendUrl}/admin/social?yt_error={$msg}");
        }

        $code = $request->get('code');
        if (!$code) {
            return redirect("{$frontendUrl}/admin/social?yt_error=" . urlencode('No authorization code from Google.'));
        }

        try {
            // Exchange code for tokens
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'code'          => $code,
                    'client_id'     => config('services.youtube.client_id'),
                    'client_secret' => config('services.youtube.client_secret'),
                    'redirect_uri'  => config('services.youtube.redirect'),
                    'grant_type'    => 'authorization_code',
                ]);

            $tokens = $response->json();

            if (isset($tokens['error'])) {
                throw new \RuntimeException("Token exchange failed: {$tokens['error_description']} ({$tokens['error']})");
            }

            $accessToken  = $tokens['access_token'];
            $refreshToken = $tokens['refresh_token'] ?? null;
            $expiresIn    = $tokens['expires_in'] ?? 3600;

            if (!$refreshToken) {
                throw new \RuntimeException(
                    'Google did not return a refresh token. ' .
                    'Revoke app access at https://myaccount.google.com/permissions then try again.'
                );
            }

            // Fetch channel info
            $channelResponse = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['Authorization' => "Bearer {$accessToken}"])
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,contentDetails',
                    'mine' => 'true',
                ])->json();

            if (empty($channelResponse['items'])) {
                throw new \RuntimeException('No YouTube channel found for this Google account.');
            }

            $channel          = $channelResponse['items'][0];
            $channelId        = $channel['id'];
            $channelName      = $channel['snippet']['title'];
            $channelHandle    = $channel['snippet']['customUrl'] ?? null;
            $thumbnailUrl     = $channel['snippet']['thumbnails']['default']['url'] ?? null;
            $uploadsPlaylistId = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null;

            // Encode channel data for frontend
            $data = base64_encode(json_encode([
                'channel_id'          => $channelId,
                'channel_name'        => $channelName,
                'channel_handle'      => $channelHandle,
                'thumbnail_url'       => $thumbnailUrl,
                'uploads_playlist_id' => $uploadsPlaylistId,
                'access_token'        => $accessToken,
                'refresh_token'       => $refreshToken,
                'expires_in'          => $expiresIn,
            ]));

            return redirect("{$frontendUrl}/admin/social?yt_channel={$data}");

        } catch (\Exception $e) {
            Log::error('YouTube OAuth callback failed', ['error' => $e->getMessage()]);
            $msg = urlencode('YouTube connection failed: ' . $e->getMessage());
            return redirect("{$frontendUrl}/admin/social?yt_error={$msg}");
        }
    }

    // ── POST /api/v1/admin/social-accounts/youtube/save-channel ──────────
    // Step 3: Save the YouTube channel with tokens.
    public function saveYouTubeChannel(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id'          => ['required', 'string'],
            'channel_name'        => ['required', 'string'],
            'access_token'        => ['required', 'string'],
            'refresh_token'       => ['required', 'string'],
            'expires_in'          => ['required', 'integer'],
            'channel_handle'      => ['nullable', 'string'],
            'thumbnail_url'       => ['nullable', 'string'],
            'uploads_playlist_id' => ['nullable', 'string'],
        ]);

        $channelId = $request->channel_id;

        // Check if already connected
        $existing = SocialAccount::where('channel_id', $this->channelId())
            ->where('platform', 'youtube')
            ->where('platform_account_id', $channelId)
            ->first();

        if ($existing) {
            $existing->setAccessToken($request->access_token);
            $existing->setRefreshToken($request->refresh_token);
            $existing->update([
                'account_name'        => $request->channel_name,
                'platform_username'   => $request->channel_handle,
                'profile_picture_url' => $request->thumbnail_url,
                'token_expires_at'    => now()->addSeconds($request->expires_in - 60),
                'is_active'           => true,
                'deactivation_reason' => null,
                'last_refreshed_at'   => now(),
                'platform_meta'       => [
                    'uploads_playlist_id' => $request->uploads_playlist_id,
                    'channel_handle'      => $request->channel_handle,
                ],
            ]);
            $existing->save();

            AuditLog::log('social_account_reconnected', 'social_account', $existing->id, null, [
                'platform' => 'youtube', 'channel' => $request->channel_name,
            ]);

            return response()->json([
                'message' => "YouTube channel \"{$request->channel_name}\" reconnected.",
                'data'    => $this->accountRow($existing->fresh()),
            ]);
        }

        $account = new SocialAccount([
            'channel_id'           => $this->channelId(),
            'connected_by_user_id' => $request->user()?->id,
            'platform'             => 'youtube',
            'account_name'         => $request->channel_name,
            'platform_account_id'  => $channelId,
            'platform_username'    => $request->channel_handle,
            'profile_picture_url'  => $request->thumbnail_url,
            'token_expires_at'     => now()->addSeconds($request->expires_in - 60),
            'is_active'            => true,
            'last_refreshed_at'    => now(),
            'platform_meta'        => [
                'uploads_playlist_id' => $request->uploads_playlist_id,
                'channel_handle'      => $request->channel_handle,
            ],
        ]);

        $account->setAccessToken($request->access_token);
        $account->setRefreshToken($request->refresh_token);
        $account->save();

        AuditLog::log('social_account_connected', 'social_account', $account->id, null, [
            'platform' => 'youtube', 'channel' => $request->channel_name,
        ]);

        return response()->json([
            'message' => "YouTube channel \"{$request->channel_name}\" connected successfully.",
            'data'    => $this->accountRow($account),
        ], 201);
    }

}

    