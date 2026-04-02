<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // ── Social Login (existing — for user auth) ───────────────────────────
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // ── Facebook / Instagram ──────────────────────────────────────────────
    // Used for BOTH user social login AND Page publishing (same app).
    // Callback for user login:       /api/v1/auth/social/facebook/callback
    // Callback for page connection:  /api/v1/admin/social-accounts/callback/facebook
    // In Facebook App settings → Valid OAuth Redirect URIs, add BOTH.
    'facebook' => [
        'client_id'     => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URI',
            env('APP_URL') . '/api/v1/admin/social-accounts/callback/facebook'
        ),
    ],

    // ── YouTube / Google OAuth ────────────────────────────────────────────
    // Separate OAuth client for YouTube channel management.
    // Scopes: https://www.googleapis.com/auth/youtube.upload
    //         https://www.googleapis.com/auth/youtube.readonly
    // In Google Cloud Console → Authorized redirect URIs, add:
    //   {APP_URL}/api/v1/admin/social-accounts/callback/youtube
    'youtube' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect'      => env('YOUTUBE_REDIRECT_URI',
            env('APP_URL') . '/api/v1/admin/social-accounts/callback/youtube'
        ),
        'api_key'        => env('YOUTUBE_API_KEY'),
        'channel_id'     => env('YOUTUBE_CHANNEL_ID'),
        'channel_handle' => env('YOUTUBE_CHANNEL_HANDLE', '@CNINewsNetwork'),
    ],

    // ── TikTok ────────────────────────────────────────────────────────────
    // Register at: https://developers.tiktok.com
    // Add Content Posting API product to your app.
    // Redirect URI must be registered in TikTok Developer Portal.
    // Scopes needed: user.info.basic, video.publish
    'tiktok' => [
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect'      => env('TIKTOK_REDIRECT_URI',
            env('APP_URL') . '/api/v1/admin/social-accounts/callback/tiktok'
        ),
    ],

    // ── Twitter / X ───────────────────────────────────────────────────────
    // Free tier: 500 posts/month write-only. No read access.
    // OAuth 1.0a — all 4 keys required.
    // App settings: Read and Write permissions, OAuth 1.0a enabled.
    // Callback URL: {APP_URL}/api/v1/admin/social-accounts/callback/twitter
    'twitter' => [
        'client_id'     => env('TWITTER_API_KEY'),
        'client_secret' => env('TWITTER_API_SECRET'),
        'redirect'      => env('TWITTER_REDIRECT_URI',
            env('APP_URL') . '/api/v1/admin/social-accounts/callback/twitter'
        ),
    ],

];
