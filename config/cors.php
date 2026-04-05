<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | FIX: Added localhost:3000 (Next.js dev) and localhost:3001 to allowed
    | origins. Without this, all preflight OPTIONS requests from the browser
    | are rejected and you see CORS errors in the console even when the
    | 419 CSRF issue is resolved.
    |
    | For production replace these with your actual domain(s).
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // ── Local development ────────────────────────────────────────────
        'http://localhost:3000',   // Next.js dev server
        'http://localhost:3001',   // Next.js alternate port
        'http://127.0.0.1:3000',

        // ── Production ───────────────────────────────────────────────────
        'https://cninews.tv',
        'https://www.cninews.tv',
        'https://cninews.co.uk',
        'https://app.cninews.co.uk',
        'https://cni-frontendvlayout.vercel.app',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ── Required for Sanctum cookie-based auth (SPA mode) ─────────────────
    // Set to true only if your frontend and backend share the same domain.
    // For Bearer token auth (which this project uses), false is fine.
    'supports_credentials' => false,

];
