<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SeoRedirectMiddleware
 *
 * Checks every incoming request path against the seo_redirects table.
 * This handles WordPress URL migration — any old WP URL can be mapped
 * to the new CNI URL by adding a row to the seo_redirects table.
 *
 * Register in bootstrap/app.php:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->web(append: [
 *           \App\Http\Middleware\SeoRedirectMiddleware::class,
 *       ]);
 *   })
 *
 * Add redirects via the admin or directly:
 *   INSERT INTO seo_redirects (old_path, new_path, http_code)
 *   VALUES ('/old-wp-slug/', '/en/pakistan/new-slug', 301);
 */
class SeoRedirectMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = '/' . ltrim($request->getPathInfo(), '/');

        // Skip API and admin routes — only check web paths
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/admin')) {
            return $next($request);
        }

        $redirect = DB::table('seo_redirects')
            ->where('old_path', $path)
            ->where('is_active', true)
            ->first();

        if ($redirect) {
            // Increment hit counter (non-blocking)
            DB::table('seo_redirects')
                ->where('id', $redirect->id)
                ->increment('hit_count');

            return redirect($redirect->new_path, $redirect->http_code);
        }

        return $next($request);
    }
}
