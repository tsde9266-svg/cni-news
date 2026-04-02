<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public events endpoint — used by the public Next.js frontend.
 * Route: GET /api/v1/events
 */
class EventController extends Controller
{
    private function channelId(): int
    {
        return DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('events')
            ->where('channel_id', $this->channelId())
            ->where('status', 'published')
            ->where('is_public', true)
            ->whereNull('deleted_at')
            ->orderBy('starts_at');

        if ($request->filled('city'))    $query->where('city', $request->city);
        if ($request->filled('country')) $query->where('country', $request->country);

        $perPage = min((int) $request->get('per_page', 12), 50);
        $paged   = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paged->items())->map(fn($e) => [
                'id'            => $e->id,
                'title'         => $e->title,
                'description'   => $e->description,
                'location_name' => $e->location_name,
                'city'          => $e->city,
                'country'       => $e->country,
                'starts_at'     => $e->starts_at,
                'ends_at'       => $e->ends_at,
                'ticket_price'  => (float) $e->ticket_price,
                'is_free'       => $e->ticket_price == 0,
                'max_capacity'  => $e->max_capacity,
            ]),
            'meta' => [
                'current_page' => $paged->currentPage(), 'last_page' => $paged->lastPage(),
                'per_page'     => $paged->perPage(),     'total'     => $paged->total(),
            ],
        ]);
    }
}
