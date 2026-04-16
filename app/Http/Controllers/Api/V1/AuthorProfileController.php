<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorProfileController extends Controller
{
    public function show(Request $request, string $displayName): JsonResponse
    {
        $user = DB::table('users')
            ->where('display_name', $displayName)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Author not found.'], 404);
        }

        $profile = DB::table('author_profiles')
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'data' => [
                'id'           => $user->id,
                'display_name' => $user->display_name ?? $user->name,
                'pen_name'     => $profile?->pen_name,
                'byline'       => $profile?->byline,
                'bio'          => $profile?->bio,
                'avatar_url'   => null,
                'twitter_url'  => $profile?->twitter_url,
                'facebook_url' => $profile?->facebook_url,
                'instagram_url'=> $profile?->instagram_url,
                'youtube_url'  => $profile?->youtube_url,
                'linkedin_url' => $profile?->linkedin_url,
                'website_url'  => $profile?->website_url,
            ],
        ]);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = DB::table('author_profiles')->where('user_id', $user->id)->first();

        return response()->json([
            'data' => [
                'id'           => $user->id,
                'display_name' => $user->display_name ?? $user->name,
                'pen_name'     => $profile?->pen_name,
                'byline'       => $profile?->byline,
                'bio'          => $profile?->bio,
                'twitter_url'  => $profile?->twitter_url,
                'facebook_url' => $profile?->facebook_url,
                'instagram_url'=> $profile?->instagram_url,
                'youtube_url'  => $profile?->youtube_url,
                'linkedin_url' => $profile?->linkedin_url,
                'website_url'  => $profile?->website_url,
            ],
        ]);
    }

    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->only([
            'pen_name', 'byline', 'bio',
            'twitter_url', 'facebook_url', 'instagram_url',
            'youtube_url', 'linkedin_url', 'website_url',
        ]);

        $exists = DB::table('author_profiles')->where('user_id', $user->id)->exists();

        if ($exists) {
            DB::table('author_profiles')
                ->where('user_id', $user->id)
                ->update(array_merge($data, ['updated_at' => now()]));
        } else {
            DB::table('author_profiles')->insert(array_merge($data, [
                'user_id'          => $user->id,
                'can_self_publish' => false,
                'is_monetised'     => false,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]));
        }

        return $this->myProfile($request);
    }

    public function myEarnings(Request $request): JsonResponse
    {
        return response()->json(['data' => ['total' => 0, 'pending' => 0, 'paid' => 0]]);
    }

    public function myArticles(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = min((int) $request->get('per_page', 12), 50);

        $paged = DB::table('articles')
            ->where('author_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderByDesc('published_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $paged->items(),
            'meta' => [
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
                'total'        => $paged->total(),
            ],
        ]);
    }
}
