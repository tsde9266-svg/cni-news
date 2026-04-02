<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POST /api/v1/admin/media        — upload an image
 * GET  /api/v1/admin/media        — list media assets
 * PATCH /api/v1/admin/media/{id}  — update title/alt
 * DELETE /api/v1/admin/media/{id} — soft-delete
 */
class MediaAdminController extends Controller
{
    private function channelId(): int
    {
        return \DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    // GET /api/v1/admin/media
    public function index(Request $request): JsonResponse
    {
        $query = MediaAsset::where('channel_id', $this->channelId())
            ->where('type', 'image')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where(fn($q) =>
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('alt_text', 'like', "%{$request->search}%")
            );
        }

        $perPage = min((int) $request->get('per_page', 24), 100);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => $paged->map(fn($m) => $this->mediaRow($m)),
            'meta' => [
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
                'total'        => $paged->total(),
            ],
        ]);
    }

    // POST /api/v1/admin/media  (multipart/form-data)
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'      => ['required', 'file', 'image', 'max:10240'], // 10 MB max
            'title'     => ['nullable', 'string', 'max:255'],
            'alt_text'  => ['nullable', 'string', 'max:320'],
        ]);

        $file      = $request->file('file');
        $slug      = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename  = "uploads/{$slug}-" . time() . '.' . $file->getClientOriginalExtension();

        // Store on public disk
        Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = MediaAsset::create([
            'owner_user_id'    => $request->user()->id,
            'channel_id'       => $this->channelId(),
            'type'             => 'image',
            'storage_provider' => 'local',
            'disk'             => 'public',
            'original_url'     => Storage::disk('public')->url($filename),
            'internal_path'    => $filename,
            'title'            => $request->input('title', $file->getClientOriginalName()),
            'alt_text'         => $request->input('alt_text'),
            'mime_type'        => $file->getMimeType(),
            'size_bytes'       => $file->getSize(),
            'width'            => $width,
            'height'           => $height,
        ]);

        return response()->json(['data' => $this->mediaRow($media)], 201);
    }

    // PATCH /api/v1/admin/media/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $media = MediaAsset::findOrFail($id);

        $media->update(array_filter([
            'title'    => $request->title,
            'alt_text' => $request->alt_text,
        ], fn($v) => $v !== null));

        return response()->json(['data' => $this->mediaRow($media->fresh())]);
    }

    // DELETE /api/v1/admin/media/{id}
    public function destroy(int $id): JsonResponse
    {
        $media = MediaAsset::findOrFail($id);
        $media->delete();
        return response()->json(null, 204);
    }

    // POST /api/v1/admin/media/video  (multipart/form-data)
    public function storeVideo(Request $request): JsonResponse
    {
        $request->validate([
            'file'  => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/webm', 'max:512000'], // 500 MB
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $name = "{$slug}-" . time() . '.' . $file->getClientOriginalExtension();

        // Stream to disk — avoids loading full file into memory
        Storage::disk('public')->putFileAs('videos', $file, $name);

        $media = MediaAsset::create([
            'owner_user_id'    => $request->user()->id,
            'channel_id'       => $this->channelId(),
            'type'             => 'video',
            'storage_provider' => 'local',
            'disk'             => 'public',
            'original_url'     => Storage::disk('public')->url("videos/{$name}"),
            'internal_path'    => "videos/{$name}",
            'title'            => $request->input('title', $file->getClientOriginalName()),
            'mime_type'        => $file->getMimeType(),
            'size_bytes'       => $file->getSize(),
        ]);

        return response()->json([
            'data' => array_merge($this->mediaRow($media), [
                'internal_path' => $media->internal_path,
            ]),
        ], 201);
    }

    // ── Format a media row for the API response ───────────────────────────
    private function mediaRow(MediaAsset $m): array
    {
        // Resolve the URL — use original_url if present, else build from disk
        $url = $m->original_url;
        if (! $url && $m->internal_path) {
            $url = Storage::disk($m->disk ?? 'public')->url($m->internal_path);
        }

        return [
            'id'         => $m->id,
            'url'        => $url,
            'title'      => $m->title,
            'alt_text'   => $m->alt_text,
            'mime_type'  => $m->mime_type,
            'size_bytes' => $m->size_bytes,
            'width'      => $m->width,
            'height'     => $m->height,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
