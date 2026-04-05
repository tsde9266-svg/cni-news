<?php

namespace App\Providers;

use App\Models\Article;
use App\Policies\ArticlePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ── Rate limiters ──────────────────────────────────────────────────
        // Public read endpoints (SSR + browser): 600/min per IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        // Auth endpoints: stricter to prevent brute-force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Register policies
        Gate::policy(Article::class, ArticlePolicy::class);

        // Super admin bypasses all policies
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
