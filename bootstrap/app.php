<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        // ── FIX 1: Register routes/api.php with the 'api' middleware group ──
        // This is what was missing — without this line, every POST to
        // /api/v1/auth/login hits the web middleware group which runs
        // VerifyCsrfToken and returns 419 CSRF token mismatch.
        api:      __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ── FIX 2: Register the 'role' middleware alias ────────────────────
        // The admin routes use ->middleware('role:admin,editor,...')
        // Without this alias Laravel throws "Class role not found"
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // ── FIX 3: Exclude API routes from CSRF verification ──────────────
        // Belt-and-suspenders: even with api: routing above, this ensures
        // the CSRF middleware never runs on /api/* paths
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // ── FIX 4: Sanctum stateful domains for same-origin SPA ───────────
        // Required if your Next.js frontend and Laravel backend are on the
        // same domain (e.g. both on localhost). If using a separate domain
        // with Bearer token auth, this is not needed but doesn't hurt.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
