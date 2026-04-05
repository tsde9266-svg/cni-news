<?php
// ─────────────────────────────────────────────────────────────────────────────
// FILE: routes/api.php  (REPLACE existing file)
//
// IMPORTANT: In Laravel 11 the routes/api.php file is not auto-loaded.
// Run: php artisan install:api
// ─────────────────────────────────────────────────────────────────────────────

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\AuthorProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Health check ──────────────────────────────────────────────────────
    Route::get('/health', function () {
        try { $db = \DB::connection()->getPdo() ? 'ok' : 'error'; } catch (\Exception) { $db = 'error'; }
        // Use whichever cache driver is configured — does NOT assume Redis
        try { \Cache::put('_health', 1, 5); $cache = \Cache::get('_health') === 1 ? 'ok' : 'error'; }
        catch (\Exception) { $cache = 'error'; }
        return response()->json(['status' => 'ok', 'db' => $db, 'cache' => $cache]);
    });

    // ── Auth (public) ──────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',                  [AuthController::class, 'register']);
        Route::post('/login',                     [AuthController::class, 'login']);
        Route::get('/social/{provider}/redirect', [AuthController::class, 'socialRedirect']);
        Route::get('/social/{provider}/callback', [AuthController::class, 'socialCallback']);
    });

    // ── Public read ────────────────────────────────────────────────────────
    Route::get('/articles',              [ArticleController::class, 'index']);
    Route::get('/articles/{slug}',       [ArticleController::class, 'show']);

    // ── Advertising (public — no auth required) ────────────────────────────
    Route::get('/ad-packages',                [\App\Http\Controllers\Api\V1\AdPackageController::class, 'index']);
    Route::get('/ad-packages/{slug}',         [\App\Http\Controllers\Api\V1\AdPackageController::class, 'show']);
    Route::post('/ad-bookings',               [\App\Http\Controllers\Api\V1\AdBookingController::class, 'store']);
    Route::get('/ad-bookings/{reference}',    [\App\Http\Controllers\Api\V1\AdBookingController::class, 'show']);
    Route::get('/social-feed',           [\App\Http\Controllers\Api\V1\SocialFeedController::class, 'index']);
    Route::get('/languages',             function () {
        $languages = \Illuminate\Support\Facades\DB::table('languages')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'direction'])
            ->map(fn($l) => [
                'id'     => $l->id,
                'code'   => $l->code,
                'name'   => $l->name,
                'is_rtl' => $l->direction === 'rtl',
            ]);
        return response()->json(['data' => $languages]);
    });
    Route::get('/categories',            [CategoryController::class, 'index']);
    Route::get('/categories/{slug}',     [CategoryController::class, 'show']);
    Route::get('/search',                [SearchController::class, 'index']);
    Route::get('/live-streams',          [\App\Http\Controllers\Api\V1\LiveStreamController::class, 'index']);

    // ── OAuth callbacks — must be public (browser redirect, no Bearer token) ──
    Route::get('/admin/social-accounts/callback/facebook', [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'callbackFacebook']);
    Route::get('/admin/social-accounts/callback/youtube',  [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'callbackYouTube']);
    Route::get('/events',                [\App\Http\Controllers\Api\V1\EventController::class, 'index']);
    Route::get('/authors/{displayName}', [AuthorProfileController::class, 'show']);
    Route::get('/verify-card/{cardNumber}', [\App\Http\Controllers\Api\V1\EmployeeCardController::class, 'verify']);

    // ── Authenticated ──────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me',            [AuthController::class, 'me']);
        Route::post('/auth/logout',  [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);

        // Articles (write)
        Route::post('/articles',                                [ArticleController::class, 'store']);
        Route::patch('/articles/{id}',                          [ArticleController::class, 'update']);
        // Route::patch('/articles/{id}',                          [ArticleController::class, 'update']);
        Route::delete('/articles/{id}',                         [ArticleController::class, 'destroy']);
        Route::post('/articles/{id}/submit',                    [ArticleController::class, 'submit']);
        Route::post('/articles/{id}/publish',                   [ArticleController::class, 'publish']);
        Route::post('/articles/{id}/unpublish',                 [ArticleController::class, 'unpublish']);
        Route::post('/articles/{id}/breaking',                  [ArticleController::class, 'toggleBreaking']);
        Route::get('/articles/{id}/versions',                   [ArticleController::class, 'versions']);
        Route::post('/articles/{id}/restore-version/{version}', [ArticleController::class, 'restoreVersion']);

        // Author
        Route::get('/my/author-profile',   [AuthorProfileController::class, 'myProfile']);
        Route::patch('/my/author-profile', [AuthorProfileController::class, 'updateMyProfile']);
        Route::get('/my/earnings',         [AuthorProfileController::class, 'myEarnings']);
        Route::get('/my/articles',         [AuthorProfileController::class, 'myArticles']);

        // Memberships
        Route::get('/memberships/plans',        [\App\Http\Controllers\Api\V1\MembershipController::class, 'plans']);
        Route::post('/memberships/subscribe',   [\App\Http\Controllers\Api\V1\MembershipController::class, 'subscribe']);
        Route::post('/memberships/cancel',      [\App\Http\Controllers\Api\V1\MembershipController::class, 'cancel']);
        Route::post('/memberships/apply-promo', [\App\Http\Controllers\Api\V1\MembershipController::class, 'applyPromo']);

        // Comments
        Route::post('/articles/{id}/comments', [\App\Http\Controllers\Api\V1\CommentController::class, 'store']);
        Route::delete('/comments/{id}',        [\App\Http\Controllers\Api\V1\CommentController::class, 'destroy']);

        // ── Admin namespace ────────────────────────────────────────────────
        Route::prefix('admin')
            ->middleware('role:admin,editor,journalist,moderator,super_admin')
            ->group(function () {

            // ── Dashboard ────────────────────────────────────────────────
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Admin\AdminDashboardController::class, 'index']);

            // ── Articles ─────────────────────────────────────────────────
            Route::get('/articles',                      [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'index']);
            Route::get('/articles/pending',              [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'pending']);
            Route::patch('/articles/{id}/set-rate',      [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'setArticleRate']);

            // POST /api/v1/admin/articles/import-rss
            Route::post('/articles/import-rss',    [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'importRss']);

            // POST /api/v1/admin/articles/bulk
            Route::post('/articles/bulk',          [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'bulk']);

            // GET /api/v1/admin/articles/{id}
            Route::get('/articles/{id}',           [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'show']);

            // POST /api/v1/admin/articles/{id}/publish
            Route::post('/articles/{id}/publish',  [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'publish']);

            // POST /api/v1/admin/articles/{id}/unpublish
            Route::post('/articles/{id}/unpublish',[\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'unpublish']);

            // POST /api/v1/admin/articles/{id}/approve
            Route::post('/articles/{id}/approve',  [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'approve']);

            // POST /api/v1/admin/articles/{id}/reject
            Route::post('/articles/{id}/reject',   [\App\Http\Controllers\Api\V1\Admin\ArticleAdminController::class, 'reject']);


            // ── Users ────────────────────────────────────────────────────
            Route::get('/users',                    [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'index']);
            Route::get('/users/{id}',               [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'show']);
            Route::patch('/users/{id}',             [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'update']);
            Route::post('/users/{id}/suspend',      [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'suspend']);
            Route::post('/users/{id}/activate',     [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'activate']);
            Route::post('/users/{id}/assign-role',  [\App\Http\Controllers\Api\V1\Admin\UserAdminController::class, 'assignRole']);

            // ── Authors ──────────────────────────────────────────────────
            Route::get('/authors',                              [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'index']);
            Route::patch('/authors/{id}/toggle-monetise',       [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'toggleMonetise']);
            Route::patch('/authors/{id}/set-rate',              [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'setRate']);
            Route::post('/authors/{id}/set-self-publish',       [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'setSelfPublish']);
            Route::get('/author-earnings',                      [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'earnings']);
            Route::post('/author-earnings/{id}/approve',        [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'approveEarning']);
            Route::post('/author-payouts',                      [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'createPayout']);
            Route::get('/contributor-applications',             [\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'applications']);
            Route::post('/contributor-applications/{id}/review',[\App\Http\Controllers\Api\V1\Admin\AuthorController::class, 'reviewApplication']);

            // ── Categories ───────────────────────────────────────────────
            Route::get('/categories',       [\App\Http\Controllers\Api\V1\Admin\CategoryAdminController::class, 'index']);
            Route::post('/categories',      [\App\Http\Controllers\Api\V1\Admin\CategoryAdminController::class, 'store']);
            Route::patch('/categories/{id}',[\App\Http\Controllers\Api\V1\Admin\CategoryAdminController::class, 'update']);
            Route::delete('/categories/{id}',[\App\Http\Controllers\Api\V1\Admin\CategoryAdminController::class, 'destroy']);

            // ── Tags ─────────────────────────────────────────────────────
            Route::get('/tags',        [\App\Http\Controllers\Api\V1\Admin\TagAdminController::class, 'index']);
            Route::post('/tags',       [\App\Http\Controllers\Api\V1\Admin\TagAdminController::class, 'store']);
            Route::patch('/tags/{id}', [\App\Http\Controllers\Api\V1\Admin\TagAdminController::class, 'update']);
            Route::delete('/tags/{id}',[\App\Http\Controllers\Api\V1\Admin\TagAdminController::class, 'destroy']);

            // ── Membership plans ─────────────────────────────────────────
            Route::get('/membership-plans',          [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'index']);
            Route::post('/membership-plans',         [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'store']);
            Route::patch('/membership-plans/{id}',   [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'update']);
            Route::delete('/membership-plans/{id}',  [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'destroy']);

            // ── Memberships list ─────────────────────────────────────────
            Route::get('/memberships',               [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'members']);
            Route::post('/memberships/{id}/cancel',  [\App\Http\Controllers\Api\V1\Admin\MembershipAdminController::class, 'cancelMembership']);

            // ── Promo codes ──────────────────────────────────────────────
            Route::get('/promo-codes',                [\App\Http\Controllers\Api\V1\Admin\PromoCodeController::class, 'index']);
            Route::post('/promo-codes',               [\App\Http\Controllers\Api\V1\Admin\PromoCodeController::class, 'store']);
            Route::patch('/promo-codes/{id}',         [\App\Http\Controllers\Api\V1\Admin\PromoCodeController::class, 'update']);
            Route::delete('/promo-codes/{id}',        [\App\Http\Controllers\Api\V1\Admin\PromoCodeController::class, 'destroy']);
            Route::post('/promo-codes/{id}/deactivate',[\App\Http\Controllers\Api\V1\Admin\PromoCodeController::class, 'deactivate']);

            // ── Comments ─────────────────────────────────────────────────
            Route::get('/comments',              [\App\Http\Controllers\Api\V1\Admin\CommentAdminController::class, 'index']);
            Route::post('/comments/{id}/approve',[\App\Http\Controllers\Api\V1\Admin\CommentAdminController::class, 'approve']);
            Route::post('/comments/{id}/reject', [\App\Http\Controllers\Api\V1\Admin\CommentAdminController::class, 'reject']);
            Route::post('/comments/bulk-action', [\App\Http\Controllers\Api\V1\Admin\CommentAdminController::class, 'bulkAction']);

            // ── Media ────────────────────────────────────────────────────
            Route::get('/media',           [\App\Http\Controllers\Api\V1\Admin\MediaAdminController::class, 'index']);
            Route::post('/media',          [\App\Http\Controllers\Api\V1\Admin\MediaAdminController::class, 'store']);
            Route::post('/media/video',    [\App\Http\Controllers\Api\V1\Admin\MediaAdminController::class, 'storeVideo']);
            Route::patch('/media/{id}',    [\App\Http\Controllers\Api\V1\Admin\MediaAdminController::class, 'update']);
            Route::delete('/media/{id}',   [\App\Http\Controllers\Api\V1\Admin\MediaAdminController::class, 'destroy']);

            // ── Live streams ─────────────────────────────────────────────
            Route::get('/live-streams',              [\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'index']);
            Route::post('/live-streams',             [\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'store']);
            Route::patch('/live-streams/{id}',       [\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'update']);
            Route::post('/live-streams/{id}/go-live',[\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'goLive']);
            Route::post('/live-streams/{id}/end',    [\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'end']);
            Route::delete('/live-streams/{id}',      [\App\Http\Controllers\Api\V1\Admin\LiveStreamAdminController::class, 'destroy']);

            // ── Events ───────────────────────────────────────────────────
            Route::get('/events',         [\App\Http\Controllers\Api\V1\Admin\EventAdminController::class, 'index']);
            Route::post('/events',        [\App\Http\Controllers\Api\V1\Admin\EventAdminController::class, 'store']);
            Route::patch('/events/{id}',  [\App\Http\Controllers\Api\V1\Admin\EventAdminController::class, 'update']);
            Route::delete('/events/{id}', [\App\Http\Controllers\Api\V1\Admin\EventAdminController::class, 'destroy']);

            // ── SEO Redirects ────────────────────────────────────────────
            Route::get('/seo-redirects',          [\App\Http\Controllers\Api\V1\Admin\SeoRedirectAdminController::class, 'index']);
            Route::post('/seo-redirects',         [\App\Http\Controllers\Api\V1\Admin\SeoRedirectAdminController::class, 'store']);
            Route::patch('/seo-redirects/{id}',   [\App\Http\Controllers\Api\V1\Admin\SeoRedirectAdminController::class, 'update']);
            Route::delete('/seo-redirects/{id}',  [\App\Http\Controllers\Api\V1\Admin\SeoRedirectAdminController::class, 'destroy']);

            // ── Settings ─────────────────────────────────────────────────
            Route::get('/settings',    [\App\Http\Controllers\Api\V1\Admin\SettingsAdminController::class, 'index']);
            Route::patch('/settings',  [\App\Http\Controllers\Api\V1\Admin\SettingsAdminController::class, 'update']);

            // ── Ad Bookings ───────────────────────────────────────────────
            Route::get('/ad-bookings',               [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'index']);
            Route::get('/ad-bookings/{id}',           [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'show']);
            Route::post('/ad-bookings/{id}/confirm',  [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'confirm']);
            Route::post('/ad-bookings/{id}/reject',   [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'reject']);
            Route::post('/ad-bookings/{id}/activate', [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'activate']);
            Route::get('/ad-packages',                [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'packages']);
            Route::post('/ad-packages',               [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'storePackage']);
            Route::patch('/ad-packages/{id}',         [\App\Http\Controllers\Api\V1\Admin\AdBookingAdminController::class, 'updatePackage']);

            // ── Social Posts ──────────────────────────────────────────────
            Route::get('/social-posts/stats',                  [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'stats']);
            Route::get('/social-posts',                        [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'index']);
            Route::post('/social-posts',                       [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'store']);
            Route::get('/social-posts/{id}',                   [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'show']);
            Route::post('/social-posts/from-article/{id}',     [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'fromArticle']);
            Route::post('/social-posts/{id}/cancel',           [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'cancel']);
            Route::post('/social-posts/{id}/retry',            [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'retry']);
            Route::delete('/social-posts/{id}',                [\App\Http\Controllers\Api\V1\Admin\SocialPostAdminController::class, 'destroy']);

            // ── Social Feed Refresh ───────────────────────────────────────
            // Force-run the ingest + clear backend cache. Use from admin when
            // newly connected accounts don't appear yet (no need to wait 30 min).
            Route::post('/social-feed/refresh', function () {
                \Illuminate\Support\Facades\Artisan::call('social:ingest');
                return response()->json(['message' => 'Social feed refreshed.']);
            });

            // ── Social Accounts ───────────────────────────────────────────
            Route::get('/social-accounts',                     [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'index']);
            Route::get('/social-accounts/connect/facebook',    [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'connectFacebook']);
            Route::get('/social-accounts/connect/youtube',     [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'connectYouTube']);
            Route::post('/social-accounts/youtube/save-channel', [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'saveYouTubeChannel']);
            Route::post('/social-accounts/facebook/save-page', [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'saveFacebookPage']);
            Route::post('/social-accounts/{id}/check-token', [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'checkToken']);
            Route::delete('/social-accounts/{id}',             [\App\Http\Controllers\Api\V1\Admin\SocialAccountController::class, 'destroy']);

            // ── Analytics ────────────────────────────────────────────────
            Route::get('/analytics/overview', [\App\Http\Controllers\Api\V1\Admin\AnalyticsController::class, 'overview']);
        });
    });
});
