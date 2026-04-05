<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisplayAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin CRUD for display_ads.
 *
 * GET    /api/v1/admin/display-ads          — list all ads
 * POST   /api/v1/admin/display-ads          — create (multipart if uploading file)
 * GET    /api/v1/admin/display-ads/{id}     — show
 * POST   /api/v1/admin/display-ads/{id}     — update (POST for multipart support)
 * DELETE /api/v1/admin/display-ads/{id}     — delete
 * POST   /api/v1/admin/display-ads/{id}/toggle — toggle is_active
 */
class DisplayAdAdminController extends Controller
{
    // ── List ────────────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $ads = DisplayAd::orderBy('display_order')->orderByDesc('created_at')->get();
        return response()->json(['data' => $ads]);
    }

    // ── Show ────────────────────────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => DisplayAd::findOrFail($id)]);
    }

    // ── Create ──────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'click_url'     => ['nullable', 'url', 'max:500'],
            'alt_text'      => ['nullable', 'string', 'max:320'],
            'placement'     => ['required', 'in:leaderboard,sidebar,in-feed,all'],
            'is_active'     => ['boolean'],
            'display_order' => ['integer', 'min:0'],
            'starts_at'     => ['nullable', 'date'],
            'ends_at'       => ['nullable', 'date', 'after_or_equal:starts_at'],
            'media_type'    => ['in:image,video'],
            'file'          => ['nullable', 'file', 'mimes:jpeg,png,gif,webp,mp4,webm,mov', 'max:51200'],
            'image_url'     => ['nullable', 'url'],
            'video_url'     => ['nullable', 'url'],
        ]);

        [$imageUrl, $videoUrl, $mediaType] = $this->resolveMedia($request);

        $ad = DisplayAd::create([
            'title'         => $request->title,
            'image_url'     => $imageUrl ?? '',
            'media_type'    => $mediaType,
            'video_url'     => $videoUrl,
            'click_url'     => $request->click_url ?? '#',
            'alt_text'      => $request->alt_text,
            'placement'     => $request->placement,
            'is_active'     => $request->boolean('is_active', true),
            'display_order' => (int) $request->input('display_order', 0),
            'starts_at'     => $request->starts_at ?: null,
            'ends_at'       => $request->ends_at   ?: null,
        ]);

        $this->bustCache();
        return response()->json(['data' => $ad], 201);
    }

    // ── Update ──────────────────────────────────────────────────────────────
    // POST (not PATCH) so multipart file uploads work
    public function update(Request $request, int $id): JsonResponse
    {
        $ad = DisplayAd::findOrFail($id);

        $request->validate([
            'title'         => ['sometimes', 'string', 'max:255'],
            'click_url'     => ['nullable', 'url', 'max:500'],
            'alt_text'      => ['nullable', 'string', 'max:320'],
            'placement'     => ['sometimes', 'in:leaderboard,sidebar,in-feed,all'],
            'is_active'     => ['boolean'],
            'display_order' => ['integer', 'min:0'],
            'starts_at'     => ['nullable', 'date'],
            'ends_at'       => ['nullable', 'date'],
            'media_type'    => ['in:image,video'],
            'file'          => ['nullable', 'file', 'mimes:jpeg,png,gif,webp,mp4,webm,mov', 'max:51200'],
            'image_url'     => ['nullable', 'url'],
            'video_url'     => ['nullable', 'url'],
        ]);

        $data = array_filter([
            'title'         => $request->title,
            'click_url'     => $request->click_url,
            'alt_text'      => $request->alt_text,
            'placement'     => $request->placement,
            'display_order' => $request->has('display_order') ? (int) $request->display_order : null,
            'starts_at'     => $request->starts_at ?: null,
            'ends_at'       => $request->ends_at   ?: null,
        ], fn($v) => $v !== null);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($request->hasFile('file') || $request->filled('image_url') || $request->filled('video_url')) {
            [$imageUrl, $videoUrl, $mediaType] = $this->resolveMedia($request);
            if ($imageUrl)   $data['image_url']  = $imageUrl;
            if ($videoUrl)   $data['video_url']  = $videoUrl;
            if ($mediaType)  $data['media_type'] = $mediaType;
        }

        $ad->update($data);
        $this->bustCache();

        return response()->json(['data' => $ad->fresh()]);
    }

    // ── Delete ──────────────────────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        DisplayAd::findOrFail($id)->delete();
        $this->bustCache();
        return response()->json(['message' => 'Deleted.']);
    }

    // ── Toggle active ────────────────────────────────────────────────────────
    public function toggle(int $id): JsonResponse
    {
        $ad = DisplayAd::findOrFail($id);
        $ad->update(['is_active' => !$ad->is_active]);
        $this->bustCache();
        return response()->json(['data' => $ad->fresh()]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function resolveMedia(Request $request): array
    {
        $mediaType = $request->input('media_type', 'image');
        $imageUrl  = $request->input('image_url');
        $videoUrl  = $request->input('video_url');

        if ($request->hasFile('file')) {
            $file      = $request->file('file');
            $mime      = $file->getMimeType();
            $isVideo   = str_starts_with($mime, 'video/');
            $slug      = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $filename  = "ads/{$slug}-" . time() . '.' . $file->getClientOriginalExtension();

            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));
            $url = Storage::disk('public')->url($filename);

            if ($isVideo) {
                $mediaType = 'video';
                $videoUrl  = $url;
            } else {
                $mediaType = 'image';
                $imageUrl  = $url;
            }
        }

        return [$imageUrl, $videoUrl, $mediaType];
    }

    private function bustCache(): void
    {
        foreach (['all', 'leaderboard', 'sidebar', 'in-feed'] as $p) {
            Cache::forget("display_ads_{$p}");
        }
    }
}
